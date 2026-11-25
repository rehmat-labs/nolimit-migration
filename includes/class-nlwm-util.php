<?php
/**
 * Small helpers shared by every part of the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups and restores run in short resumable steps; raising the time and memory limit for one step is part of making large sites work on shared hosting.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged

class NLWM_Exception extends Exception {}

class NLWM_Util {

	public static function activate() {
		self::ensure_dirs();
		if ( ! get_option( 'nlwm_secret' ) ) {
			update_option( 'nlwm_secret', wp_generate_password( 48, false, false ), false );
		}
	}

	/** Folder that holds finished backups. */
	public static function backups_dir() {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		if ( defined( 'NLWM_BACKUPS_DIR' ) ) {
			return $dir = NLWM_BACKUPS_DIR;
		}
		// Found by pattern, not stored in an option: an import replaces the options table.
		$found = glob( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'nlwm-backups-*', GLOB_ONLYDIR );
		if ( $found ) {
			sort( $found );
			return $dir = $found[0];
		}
		return $dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'nlwm-backups-' . self::random( 20 );
	}

	/** Folder-name pattern of the backups folder, relative to wp-content. */
	public static function backups_pattern() {
		return defined( 'NLWM_BACKUPS_DIR' ) ? basename( NLWM_BACKUPS_DIR ) : 'nlwm-backups-*';
	}

	/** Folder that holds running/finished job state and scratch files. */
	public static function jobs_dir() {
		return self::backups_dir() . DIRECTORY_SEPARATOR . '.jobs';
	}

	/**
	 * Create the backups folder and lock it down so archives can never be fetched over HTTP.
	 */
	public static function ensure_dirs() {
		foreach ( array( self::backups_dir(), self::jobs_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new NLWM_Exception( esc_html( sprintf( 'Could not create folder %s. Check file permissions.', $dir ) ) );
			}
			$files = array(
				'index.php'  => "<?php\n// Silence is golden.\n",
				'.htaccess'  => "# NoLimit Migration: block direct access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
				'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n",
			);
			foreach ( $files as $name => $body ) {
				$path = $dir . DIRECTORY_SEPARATOR . $name;
				if ( ! file_exists( $path ) ) {
					@file_put_contents( $path, $body );
				}
			}
		}
	}

	public static function settings() {
		$defaults = array(
			'schedule'        => 'off',
			'schedule_time'   => '03:00',
			'retention'       => 5,
			'notify_email'    => '',
			'notify_on'       => 'failure',
			'exclude'         => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- a plugin setting, not a query argument.
			'exclude_paths'   => '',
			'max_file_mb'     => 0,
			'storage'         => 'local', // local | both | drive
			'drive_retention' => 10,
		);
		$saved = get_option( 'nlwm_settings', array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		if ( 'drive' === $s['storage'] ) {
			$s['storage'] = 'remote'; // earlier development name
		}
		return $s;
	}

	/**
	 * Name, logo and tagline shown in the admin. NoLimit Pro (Agency) can white-label them.
	 */
	public static function brand() {
		return apply_filters( 'nlwm_brand', array(
			'name'        => 'NoLimit Migration',
			'menu'        => 'NoLimit Migration',
			'tagline'     => 'Backup, restore & move WordPress. No size limit.',
			'logo'        => NLWM_URL . 'assets/img/nolimit-mark.svg',
			'icon'        => '',
			'white_label' => false,
		) );
	}

	/**
	 * Options that hold this site's own secrets and connections (Pro license, S3 keys, Drive tokens,
	 * link-signing secret). They are never put into a backup, and a restore keeps the destination's own.
	 */
	public static function private_options() {
		return array_values( array_unique( (array) apply_filters( 'nlwm_private_options', array(
			'nlwm_secret',
			'nlwm_backups_index', // describes the backup files on this server
			'nlwm_gdrive',
			'nlmp_gdrive',
			'nlmp_license',
			'nlmp_s3',
			'nlmp_settings',
		) ) ) );
	}

	/** Link to the product website with campaign tags. */
	public static function site_url( $path = '', $where = 'plugin' ) {
		$base = defined( 'NLWM_SITE' ) ? NLWM_SITE : 'https://nolimitmigration.com';
		return add_query_arg( array( 'utm_source' => 'plugin', 'utm_medium' => $where ), trailingslashit( $base ) . ltrim( $path, '/' ) );
	}

	public static function secret() {
		$secret = get_option( 'nlwm_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 48, false, false );
			update_option( 'nlwm_secret', $secret, false );
		}
		return $secret . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
	}

	public static function random( $length = 12 ) {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}

	public static function format_bytes( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $i > 1 ? 2 : 0 ) . ' ' . $units[ $i ];
	}

	/** Forward-slash path, no trailing slash. */
	public static function norm( $path ) {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	/** Is this a backup file name we created or accept? Blocks path tricks. */
	public static function valid_backup_name( $name ) {
		return is_string( $name )
			&& $name === basename( $name )
			&& preg_match( '/^[A-Za-z0-9._-]+\.(' . NLWM_EXT . '|wpress)$/', $name )
			&& strpos( $name, '..' ) === false;
	}

	public static function backup_path( $name ) {
		if ( ! self::valid_backup_name( $name ) ) {
			throw new NLWM_Exception( 'Invalid backup file name.' );
		}
		return self::backups_dir() . DIRECTORY_SEPARATOR . $name;
	}

	/** Name for a new backup: domain-YYYYMMDD-HHMMSS-random.nolimit */
	public static function new_backup_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$base = sanitize_file_name( $host . ( $path ? '-' . str_replace( '/', '-', $path ) : '' ) );
		$base = preg_replace( '/[^A-Za-z0-9.-]+/', '-', $base );
		return sprintf( '%s-%s-%s.%s', $base, gmdate( 'Ymd-His' ), self::random( 6 ), NLWM_EXT );
	}

	public static function raise_limits() {
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		$current = ini_get( 'memory_limit' );
		if ( '-1' !== $current && wp_convert_hr_to_bytes( $current ) < 512 * MB_IN_BYTES ) {
			@ini_set( 'memory_limit', '512M' );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/** Largest request body we can safely send to this server, in bytes. */
	public static function max_chunk_bytes() {
		$limits = array_filter( array(
			wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ),
			wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ),
		) );
		$limit = $limits ? min( $limits ) : 2 * MB_IN_BYTES;
		$chunk = (int) floor( $limit * 0.8 );
		return max( 256 * KB_IN_BYTES, min( $chunk, 20 * MB_IN_BYTES ) );
	}

	public static function free_space( $dir ) {
		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $dir );
			if ( false !== $free ) {
				return (float) $free;
			}
		}
		return null;
	}

	/**
	 * Recursively delete a folder (used for job scratch folders only).
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- removes our own temporary folder; WP_Filesystem may need FTP credentials in a background job.
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
	}
}
