<?php
/**
 * Export (backup) pipeline.
 *
 * Options (all optional):
 *   no_media, no_themes, no_inactive_themes, no_plugins, no_inactive_plugins, no_muplugins, no_cache,
 *   no_database, no_spam, no_revisions, no_transients, exclude_tables[], exclude_paths, max_file_mb,
 *   replace[] (array of [old,new] applied when the backup is imported), label, origin (manual|scheduled|pre-import)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename

class NLWM_Export {

	const MANIFEST = 'nolimit.json';
	const DATABASE = 'database.sql';

	/** name => [callable, weight] */
	public static function steps() {
		return array(
			'init'      => array( array( __CLASS__, 'init' ), 1 ),
			'scan'      => array( array( __CLASS__, 'scan' ), 4 ),
			'database'  => array( array( __CLASS__, 'database' ), 20 ),
			'archive'   => array( array( __CLASS__, 'archive' ), 74 ),
			'finalize'  => array( array( __CLASS__, 'finalize' ), 1 ),
			'upload'    => array( array( __CLASS__, 'upload' ), 15 ),
			'__cleanup' => array( array( __CLASS__, 'cleanup' ) ),
		);
	}

	public static function init( array &$job, $deadline ) {
		$work = NLWM_Job::work_dir( $job );
		$name = NLWM_Util::new_backup_name();
		$job['data']['init'] = array(
			'name'    => $name,
			'archive' => NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . $name . '.part',
			'list'    => $work . DIRECTORY_SEPARATOR . 'files.list',
			'sql'     => $work . DIRECTORY_SEPARATOR . self::DATABASE,
		);
		wp_delete_file( $job['data']['init']['archive'] );
		wp_delete_file( $job['data']['init']['list'] );
		wp_delete_file( $job['data']['init']['sql'] );

		$free = NLWM_Util::free_space( NLWM_Util::backups_dir() );
		if ( null !== $free && $free < 50 * MB_IN_BYTES ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Only %s of disk space is free. Free up space and try again.', NLWM_Util::format_bytes( $free ) ) ) );
		}
		NLWM_Job::log( $job, 'Backup file: ' . $name );
		NLWM_Job::message( $job, 'Preparing…' );
		return true;
	}

	public static function scan( array &$job, $deadline ) {
		$init  = $job['data']['init'];
		$state = &$job['data']['scan'];
		$rules = NLWM_Files::rules( $job['opts'] );
		$done  = NLWM_Files::scan( $init['list'], $state, $rules, $deadline );
		NLWM_Job::message( $job, sprintf( 'Listing files… %s files (%s)', number_format_i18n( $state['files'] ), NLWM_Util::format_bytes( $state['bytes'] ) ), $done ? 1 : 0.5 );
		if ( $done ) {
			NLWM_Job::log( $job, sprintf( 'Found %d files, %s.%s', $state['files'], NLWM_Util::format_bytes( $state['bytes'] ), $state['skipped'] ? ' Skipped ' . $state['skipped'] . ' files over the size limit.' : '' ) );
			$free = NLWM_Util::free_space( NLWM_Util::backups_dir() );
			if ( null !== $free && $free < $state['bytes'] * 1.05 ) {
				throw new NLWM_Exception( esc_html( sprintf( 'Not enough disk space: the backup needs about %s, but only %s is free.', NLWM_Util::format_bytes( $state['bytes'] ), NLWM_Util::format_bytes( $free ) ) ) );
			}
		}
		return $done;
	}

	public static function database( array &$job, $deadline ) {
		if ( ! empty( $job['opts']['no_database'] ) ) {
			return true;
		}
		$init  = $job['data']['init'];
		$state = &$job['data']['database'];
		$db    = new NLWM_Database();
		$done  = $db->dump( $init['sql'], $state, $job['opts'], $deadline );
		$total = max( 1, count( (array) $state['tables'] ) );
		$table = isset( $state['tables'][ $state['table_index'] ] ) ? $state['tables'][ $state['table_index'] ] : '';
		NLWM_Job::message( $job, sprintf( 'Exporting database… table %d of %d %s', min( $state['table_index'] + 1, $total ), $total, $table ), $state['table_index'] / $total );
		if ( $done ) {
			clearstatcache();
			NLWM_Job::log( $job, sprintf( 'Database: %d tables, %s rows, %s.', $total, number_format_i18n( $state['rows'] ), NLWM_Util::format_bytes( filesize( $init['sql'] ) ) ) );
		}
		return $done;
	}

	public static function manifest( array $job ) {
		global $wp_version, $wpdb;
		$up  = wp_upload_dir( null, false );
		$opt = $job['opts'];
		try {
			$db         = new NLWM_Database();
			$db_version = $db->version();
		} catch ( Exception $e ) {
			$db_version = '';
		}

		$replace = array();
		if ( ! empty( $opt['replace'] ) && is_array( $opt['replace'] ) ) {
			foreach ( $opt['replace'] as $pair ) {
				if ( isset( $pair[0], $pair[1] ) && '' !== $pair[0] ) {
					$replace[] = array( (string) $pair[0], (string) $pair[1] );
				}
			}
		}

		return array(
			'format'      => 'nolimit-migration',
			'format_ver'  => 1,
			'plugin'      => NLWM_VERSION,
			'created'     => time(),
			'site_url'    => site_url(),
			'home_url'    => home_url(),
			'site_name'   => get_bloginfo( 'name' ),
			'wp_version'  => $wp_version,
			'php_version' => PHP_VERSION,
			'db_version'  => $db_version,
			'prefix'      => $wpdb->base_prefix,
			'abspath'     => untrailingslashit( ABSPATH ),
			'content_dir' => untrailingslashit( WP_CONTENT_DIR ),
			'upload_dir'  => untrailingslashit( $up['basedir'] ),
			'upload_url'  => untrailingslashit( $up['baseurl'] ),
			'multisite'   => is_multisite(),
			'plugins'     => array_values( (array) get_option( 'active_plugins', array() ) ),
			'template'    => get_template(),
			'stylesheet'  => get_stylesheet(),
			'has_db'      => empty( $opt['no_database'] ),
			'files'       => isset( $job['data']['scan']['files'] ) ? $job['data']['scan']['files'] : 0,
			'files_bytes' => isset( $job['data']['scan']['bytes'] ) ? $job['data']['scan']['bytes'] : 0,
			'options'     => array_intersect_key( $opt, array_flip( array( 'no_media', 'no_themes', 'no_inactive_themes', 'no_plugins', 'no_inactive_plugins', 'no_muplugins', 'no_cache', 'no_database', 'no_spam', 'no_revisions', 'no_transients' ) ) ),
			'replace'     => $replace,
			'label'       => isset( $opt['label'] ) ? (string) $opt['label'] : '',
			'encrypted'   => ! empty( $opt['encrypt'] ),
		);
	}

	public static function archive( array &$job, $deadline ) {
		$init  = $job['data']['init'];
		$state = &$job['data']['archive'];
		$state += array( 'size' => 0, 'phase' => 'meta', 'list_pos' => 0, 'cur' => null, 'file_state' => array(), 'bytes' => 0 );

		$archive = new NLWM_Archive( $init['archive'], 'write', $state['size'], ! empty( $job['opts']['encrypt'] ) ? $job['opts']['password'] : null );
		try {
			if ( 'meta' === $state['phase'] ) {
				$archive->add_string( self::MANIFEST, wp_json_encode( self::manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$state['phase'] = empty( $job['opts']['no_database'] ) ? 'db' : 'files';
				$state['size']  = $archive->size();
			}

			if ( 'db' === $state['phase'] ) {
				$done          = $archive->add_file( $init['sql'], self::DATABASE, $state['file_state'], $deadline );
				$state['size'] = $archive->size();
				NLWM_Job::message( $job, 'Adding database to the archive…', 0.02 );
				if ( ! $done ) {
					$archive->close();
					return false;
				}
				$state['phase'] = 'files';
			}

			$total = max( 1, (int) $job['data']['scan']['bytes'] );
			$list  = fopen( $init['list'], 'rb' );
			if ( ! $list ) {
				throw new NLWM_Exception( 'File list is missing.' );
			}
			fseek( $list, $state['list_pos'] );
			$root = untrailingslashit( WP_CONTENT_DIR );
			$done = false;
			while ( true ) {
				if ( null === $state['cur'] ) {
					$line = fgets( $list );
					if ( false === $line ) {
						$done = true;
						break;
					}
					$state['list_pos'] = ftell( $list );
					$parts             = explode( "\t", rtrim( $line, "\n" ) );
					$state['cur']      = $parts[0];
					$state['cur_size'] = isset( $parts[1] ) ? (int) $parts[1] : 0;
				}
				$before   = isset( $state['file_state']['offset'] ) ? $state['file_state']['offset'] : 0;
				$finished = $archive->add_file( $root . '/' . $state['cur'], $state['cur'], $state['file_state'], $deadline );
				if ( $finished ) {
					$state['bytes'] += max( 0, $state['cur_size'] - $before );
					$state['cur']    = null;
				} else {
					$state['bytes'] += $state['file_state']['offset'] - $before;
				}
				$state['size'] = $archive->size();
				if ( microtime( true ) > $deadline ) {
					break;
				}
			}
			fclose( $list );

			if ( $done ) {
				$archive->finish();
				$state['size'] = $archive->size();
			}
			$archive->close();
			NLWM_Job::message( $job, sprintf( 'Archiving files… %s of %s', NLWM_Util::format_bytes( $state['bytes'] ), NLWM_Util::format_bytes( $total ) ), $state['bytes'] / $total );
			return $done;
		} catch ( Exception $e ) {
			$archive->close();
			throw $e;
		}
	}

	public static function finalize( array &$job, $deadline ) {
		$init  = $job['data']['init'];
		$final = NLWM_Util::backup_path( $init['name'] );
		if ( ! @rename( $init['archive'], $final ) ) {
			throw new NLWM_Exception( 'Could not finish the backup file.' );
		}
		$size   = filesize( $final );
		$origin = isset( $job['opts']['origin'] ) ? $job['opts']['origin'] : 'manual';
		$job['opts']['password'] = ''; // never keep the password in the job file longer than needed
		NLWM_Backups::set_meta( $init['name'], array(
			'label'      => isset( $job['opts']['label'] ) ? sanitize_text_field( $job['opts']['label'] ) : '',
			'type'       => $origin,
			'created'    => time(),
			'site_url'   => home_url(),
			'files'      => $job['data']['scan']['files'],
			'has_db'     => empty( $job['opts']['no_database'] ),
			'wp_version' => get_bloginfo( 'version' ),
			'encrypted'  => ! empty( $job['opts']['encrypt'] ),
		) );
		NLWM_Util::rrmdir( NLWM_Job::work_dir( $job ) );

		if ( 'scheduled' === $origin ) {
			$removed = NLWM_Backups::apply_retention( NLWM_Util::settings()['retention'], 'scheduled' );
			if ( $removed ) {
				NLWM_Job::log( $job, 'Retention: removed ' . implode( ', ', $removed ) );
			}
		}

		$job['result'] = array(
			'name' => $init['name'],
			'size' => $size,
			'size_human' => NLWM_Util::format_bytes( $size ),
			'download'   => NLWM_Remote::download_url( $init['name'], false ),
		);
		NLWM_Job::log( $job, sprintf( 'Backup ready: %s (%s).', $init['name'], NLWM_Util::format_bytes( $size ) ) );
		NLWM_Job::message( $job, 'Backup complete.' );
		return true;
	}

	/**
	 * Copy the finished backup to every remote storage this job asked for (Google Drive, S3 …).
	 * A failed upload never loses the backup: the local file is kept and the problem is reported.
	 */
	public static function upload( array &$job, $deadline ) {
		$targets = isset( $job['opts']['storages'] ) ? array_values( (array) $job['opts']['storages'] ) : array();
		if ( ! $targets ) {
			return true;
		}
		$name   = $job['data']['init']['name'];
		$path   = NLWM_Util::backup_path( $name );
		$origin = isset( $job['opts']['origin'] ) ? $job['opts']['origin'] : 'manual';
		$all    = &$job['data']['upload'];
		$all   += array( 'index' => 0, 'state' => array(), 'ok' => array() );

		while ( $all['index'] < count( $targets ) ) {
			$id = $targets[ $all['index'] ];
			try {
				$storage = NLWM_Storages::get( $id );
				$done    = $storage->upload( $path, array(
					'nlwm'  => '1',
					'type'  => $origin,
					'site'  => home_url(),
					'label' => substr( isset( $job['opts']['label'] ) ? (string) $job['opts']['label'] : '', 0, 100 ),
				), $all['state'], $deadline );
			} catch ( Exception $e ) {
				$label = isset( $storage ) ? $storage->label() : $id;
				NLWM_Job::log( $job, sprintf( 'Upload to %s failed: %s The backup is kept on this server.', $label, $e->getMessage() ) );
				$job['result']['warnings'][] = sprintf( 'Upload to %s failed: %s The backup is kept on this server.', $label, $e->getMessage() );
				$all['index']++;
				$all['state'] = array();
				continue;
			}
			$st   = $all['state'];
			$size = isset( $st['size'] ) ? $st['size'] : filesize( $path );
			$off  = isset( $st['offset'] ) ? $st['offset'] : 0;
			NLWM_Job::message( $job, sprintf( 'Uploading to %s… %s of %s', $storage->label(), NLWM_Util::format_bytes( $off ), NLWM_Util::format_bytes( $size ) ), ( $all['index'] + $off / max( 1, $size ) ) / count( $targets ) );
			if ( ! $done ) {
				return false;
			}
			$all['ok'][ $id ] = isset( $st['id'] ) ? $st['id'] : '';
			NLWM_Job::log( $job, 'Uploaded to ' . $storage->label() . '.' );
			if ( 'scheduled' === $origin ) {
				try {
					$removed = $storage->apply_retention( NLWM_Util::settings()['drive_retention'] );
					if ( $removed ) {
						NLWM_Job::log( $job, $storage->label() . ' retention: removed ' . implode( ', ', $removed ) );
					}
				} catch ( Exception $e ) {
					NLWM_Job::log( $job, $storage->label() . ' retention skipped: ' . $e->getMessage() );
				}
			}
			$all['index']++;
			$all['state'] = array();
			if ( microtime( true ) > $deadline && $all['index'] < count( $targets ) ) {
				return false;
			}
		}

		if ( $all['ok'] ) {
			NLWM_Backups::set_meta( $name, array( 'remote' => $all['ok'] ) );
			$job['result']['uploaded'] = array_map( function ( $id ) {
				return NLWM_Storages::get( $id )->label();
			}, array_keys( $all['ok'] ) );
			// Backwards compatible flag used by e-mails and the UI.
			$job['result']['drive'] = true;
		}
		$s = NLWM_Util::settings();
		if ( in_array( $s['storage'], array( 'remote', 'drive' ), true ) && 'pre-import' !== $origin && count( $all['ok'] ) === count( $targets ) ) {
			NLWM_Backups::delete( $name );
			$job['result']['download'] = '';
			$job['result']['local']    = false;
			NLWM_Job::log( $job, 'Local copy removed (settings: keep backups in the cloud only).' );
		}
		NLWM_Job::message( $job, 'Backup complete.' );
		return true;
	}

	public static function cleanup( array &$job, $ok ) {
		if ( ! empty( $job['data']['init']['archive'] ) ) {
			wp_delete_file( $job['data']['init']['archive'] );
		}
		NLWM_Util::rrmdir( NLWM_Job::work_dir( $job ) );
	}
}
