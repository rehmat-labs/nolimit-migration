<?php
/**
 * Resumable jobs.
 *
 * A job is a list of steps. Each HTTP request (browser poll, cron tick or WP-CLI loop) runs steps for
 * a few seconds and saves the state to a JSON file. State lives on disk, not in the database, because
 * an import replaces the database while the job is running.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename

class NLWM_Job {

	public static function path( $id, $ext = 'json' ) {
		if ( ! preg_match( '/^[a-z0-9]{16}$/', $id ) ) {
			throw new NLWM_Exception( 'Invalid job.' );
		}
		return NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . $id . '.' . $ext;
	}

	public static function work_dir( array $job ) {
		$dir = NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . $job['id'];
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @param string $type   export | import
	 * @param array  $opts   job options
	 */
	public static function create( $type, array $opts ) {
		NLWM_Util::ensure_dirs();
		$running = self::running();
		if ( $running ) {
			throw new NLWM_Exception( 'Another backup or restore is already running. Wait for it to finish or cancel it first.' );
		}
		$job = array(
			'id'       => NLWM_Util::random( 16 ),
			'token'    => wp_generate_password( 40, false, false ),
			'type'     => $type,
			'status'   => 'running',
			'steps'    => array_values( array_filter( array_keys( self::handlers( $type ) ), function ( $k ) {
				return 0 !== strpos( $k, '__' );
			} ) ),
			'step'     => 0,
			'data'     => array(),
			'opts'     => $opts,
			'progress' => 0,
			'sub'      => 0,
			'message'  => 'Starting…',
			'log'      => array(),
			'created'  => time(),
			'updated'  => time(),
			'result'   => array(),
		);
		self::log( $job, sprintf( '%s started (%s %s, PHP %s).', ucfirst( $type ), NLWM_Util::brand()['name'], NLWM_VERSION, PHP_VERSION ) );
		self::save( $job );
		return $job;
	}

	public static function load( $id ) {
		$file = self::path( $id );
		if ( ! file_exists( $file ) ) {
			throw new NLWM_Exception( 'This job no longer exists.' );
		}
		$job = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $job ) ) {
			throw new NLWM_Exception( 'Job state is unreadable.' );
		}
		return $job;
	}

	/** Load a job and check its secret token (used by requests that may arrive without a login). */
	public static function load_verified( $id, $token ) {
		$job = self::load( $id );
		if ( ! is_string( $token ) || ! hash_equals( $job['token'], $token ) ) {
			throw new NLWM_Exception( 'Invalid job token.' );
		}
		return $job;
	}

	public static function save( array &$job ) {
		$job['updated'] = time();
		$file           = self::path( $job['id'] );
		$tmp            = $file . '.tmp';
		if ( false === file_put_contents( $tmp, wp_json_encode( $job ) ) || ! @rename( $tmp, $file ) ) {
			// Windows cannot rename over an existing file on some PHP builds.
			wp_delete_file( $file );
			if ( ! @rename( $tmp, $file ) ) {
				throw new NLWM_Exception( 'Could not save job state. Is the disk full?' );
			}
		}
	}

	public static function log( array &$job, $msg ) {
		$line          = gmdate( 'H:i:s' ) . '  ' . $msg;
		$job['log'][]  = $line;
		if ( count( $job['log'] ) > 300 ) {
			$job['log'] = array_slice( $job['log'], -300 );
		}
		@file_put_contents( self::path( $job['id'], 'log' ), gmdate( 'Y-m-d ' ) . $line . "\n", FILE_APPEND );
	}

	public static function message( array &$job, $msg, $sub = null ) {
		$job['message'] = $msg;
		if ( null !== $sub ) {
			$job['sub'] = max( 0, min( 1, (float) $sub ) );
		}
	}

	/** A job that is running and was touched recently. */
	public static function running() {
		$files = glob( NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . '*.json' );
		foreach ( (array) $files as $file ) {
			$job = json_decode( (string) @file_get_contents( $file ), true );
			// Scheduled jobs may wait for the next cron tick, so they count as alive for longer.
			$alive = ( isset( $job['opts']['origin'] ) && 'scheduled' === $job['opts']['origin'] ) ? HOUR_IN_SECONDS : 180;
			if ( is_array( $job ) && 'running' === $job['status'] && time() - (int) $job['updated'] < $alive ) {
				return $job;
			}
		}
		return null;
	}

	public static function handlers( $type ) {
		switch ( $type ) {
			case 'export':
				return NLWM_Export::steps();
			case 'import':
				return NLWM_Import::steps();
			case 'fetch':
				return NLWM_Remote::steps();
		}
		throw new NLWM_Exception( 'Unknown job type.' );
	}

	/**
	 * Run steps until the time budget is used.
	 *
	 * @return array the job after this slice
	 */
	public static function run( array $job, $budget = NLWM_STEP_TIME ) {
		if ( 'running' !== $job['status'] ) {
			return $job;
		}
		$lock = @fopen( self::path( $job['id'], 'lock' ), 'c' );
		if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock );
			$job['busy'] = true; // another request is working on it right now
			return $job;
		}

		NLWM_Util::raise_limits();
		// Every step makes at least one unit of progress before it checks the deadline.
		$deadline = microtime( true ) + max( 0, (float) $budget );
		$handlers = self::handlers( $job['type'] );

		try {
			// Re-read under the lock: the state may have advanced while we waited.
			$job = self::load( $job['id'] );
			while ( 'running' === $job['status'] && $job['step'] < count( $job['steps'] ) ) {
				if ( self::cancel_requested( $job ) ) {
					$job = self::fail( $job, 'Cancelled by user.' );
					$job['status'] = 'cancelled';
					self::save( $job );
					break;
				}
				$name = $job['steps'][ $job['step'] ];
				if ( ! isset( $job['data'][ $name ] ) ) {
					$job['data'][ $name ] = array();
				}
				$done = call_user_func_array( $handlers[ $name ][0], array( &$job, $deadline ) );
				if ( $done ) {
					$job['step']++;
					$job['sub'] = 0;
				}
				$job['progress'] = self::progress( $job, $handlers );
				self::save( $job );
				if ( ! $done || microtime( true ) > $deadline ) {
					break;
				}
			}
			if ( 'running' === $job['status'] && $job['step'] >= count( $job['steps'] ) ) {
				$job['status']   = 'done';
				$job['progress'] = 100;
				self::log( $job, 'Finished.' );
				self::save( $job );
				do_action( 'nlwm_job_done', $job );
			}
		} catch ( Throwable $e ) {
			$job = self::fail( $job, $e->getMessage() );
		}

		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		return $job;
	}

	public static function fail( array $job, $message ) {
		$job['status']  = 'error';
		$job['message'] = $message;
		self::log( $job, 'ERROR: ' . $message );
		$handlers = self::handlers( $job['type'] );
		if ( isset( $handlers['__cleanup'] ) ) {
			try {
				call_user_func_array( $handlers['__cleanup'][0], array( &$job, false ) );
			} catch ( Throwable $e ) {
				self::log( $job, 'Cleanup: ' . $e->getMessage() );
			}
		}
		self::save( $job );
		do_action( 'nlwm_job_failed', $job );
		return $job;
	}

	/**
	 * Ask a job to stop. Only a flag is written here: the request that owns the job lock notices it
	 * between units of work and cleans up itself, so cleanup never races a running step.
	 */
	public static function cancel( array $job ) {
		if ( 'running' !== $job['status'] ) {
			return $job;
		}
		if ( ! self::cancellable( $job ) ) {
			$job['message'] = 'The database has already been switched; the restore must finish.';
			return $job;
		}
		@file_put_contents( self::path( $job['id'], 'cancel' ), '1' );
		// If nothing is working on the job right now, run one (empty) slice to apply the cancel.
		return self::run( $job, 0 );
	}

	protected static function cancellable( array $job ) {
		if ( 'import' !== $job['type'] ) {
			return true;
		}
		$swap = array_search( 'swap', $job['steps'], true );
		return false === $swap || $job['step'] < $swap;
	}

	protected static function cancel_requested( array $job ) {
		return file_exists( self::path( $job['id'], 'cancel' ) ) && self::cancellable( $job );
	}

	protected static function progress( array $job, array $handlers ) {
		$total = 0;
		$done  = 0;
		foreach ( $job['steps'] as $i => $name ) {
			$w      = isset( $handlers[ $name ][1] ) ? $handlers[ $name ][1] : 1;
			$total += $w;
			if ( $i < $job['step'] ) {
				$done += $w;
			} elseif ( $i === $job['step'] ) {
				$done += $w * (float) $job['sub'];
			}
		}
		return $total ? min( 99, (int) floor( 100 * $done / $total ) ) : 0;
	}

	/** What the browser needs to draw progress. */
	public static function public_view( array $job ) {
		return array(
			'id'       => $job['id'],
			'type'     => $job['type'],
			'status'   => $job['status'],
			'progress' => $job['progress'],
			'message'  => $job['message'],
			'step'     => isset( $job['steps'][ $job['step'] ] ) ? $job['steps'][ $job['step'] ] : 'done',
			'log'      => array_slice( $job['log'], -40 ),
			'result'   => $job['result'],
			'busy'     => ! empty( $job['busy'] ),
		);
	}

	/** Delete job files older than two days. */
	public static function gc() {
		foreach ( (array) glob( NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . '*.json' ) as $file ) {
			if ( filemtime( $file ) < time() - 2 * DAY_IN_SECONDS ) {
				$id = basename( $file, '.json' );
				NLWM_Util::rrmdir( NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . $id );
				foreach ( array( 'json', 'log', 'lock', 'cancel' ) as $ext ) {
					wp_delete_file( NLWM_Util::jobs_dir() . DIRECTORY_SEPARATOR . $id . '.' . $ext );
				}
			}
		}
	}
}
