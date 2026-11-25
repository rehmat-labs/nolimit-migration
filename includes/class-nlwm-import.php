<?php
/**
 * Import (restore) pipeline for .nolimit and .wpress archives.
 *
 * Job options: backup (file name in the backups folder), email_replace (bool), skip_files (bool),
 * skip_db (bool).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class NLWM_Import {

	/** Root-level archive entries that are metadata, never copied into wp-content. */
	protected static $meta_files = array( 'nolimit.json', 'database.sql', 'package.json', 'multisite.json', 'blogs.json', 'settings.json' );

	/** Drop-ins tied to the old server that commonly break a fresh host. */
	protected static $skip_dropins = array( 'db.php', 'object-cache.php', 'advanced-cache.php', 'db-error.php' );

	public static function steps() {
		return array(
			'prepare'    => array( array( __CLASS__, 'prepare' ), 1 ),
			// Database first, into temporary tables: if it fails, neither files nor tables were touched.
			'extract_db' => array( array( __CLASS__, 'extract_db' ), 4 ),
			'database'   => array( array( __CLASS__, 'database' ), 50 ),
			'files'      => array( array( __CLASS__, 'files' ), 41 ),
			'swap'      => array( array( __CLASS__, 'swap' ), 2 ),
			'finish'    => array( array( __CLASS__, 'finish' ), 1 ),
			'__cleanup' => array( array( __CLASS__, 'cleanup' ) ),
		);
	}

	/* ================================================================ inspect */

	/**
	 * Read an archive's details without changing anything. Used to show the confirm screen.
	 */
	public static function inspect( $path ) {
		$archive = new NLWM_Archive( $path );
		try {
			if ( ! $archive->is_complete() ) {
				throw new NLWM_Exception( 'This backup is incomplete or damaged (the end marker is missing). Upload or download it again.' );
			}
			$info = array(
				'format'    => null,
				'manifest'  => array(),
				'db_entry'  => null,
				'entries'   => 0,
				'bytes'     => 0,
				'plugins'   => 0,
				'themes'    => 0,
				'uploads'   => 0,
				'size'      => $archive->size(),
				'container' => $archive->format(),
				'encrypted' => $archive->is_encrypted(),
			);
			$pos = $archive->first();
			while ( $e = $archive->entry_at( $pos ) ) {
				$info['entries']++;
				$info['bytes'] += $e['size'];
				if ( 'nolimit.json' === $e['rel'] && 'nolimit' === $info['container'] ) {
					$info['format']   = 'nolimit';
					$info['manifest'] = json_decode( $archive->read_entry( $e ), true );
				} elseif ( 'package.json' === $e['rel'] && 'wpress' === $info['container'] ) {
					$info['format']   = 'wpress';
					$info['manifest'] = self::from_wpress_package( json_decode( $archive->read_entry( $e ), true ) );
				} elseif ( 'database.sql' === $e['rel'] ) {
					$info['db_entry'] = $e;
				} elseif ( 0 === strpos( $e['rel'], 'plugins/' ) ) {
					$info['plugins']++;
				} elseif ( 0 === strpos( $e['rel'], 'themes/' ) ) {
					$info['themes']++;
				} elseif ( 0 === strpos( $e['rel'], 'uploads/' ) ) {
					$info['uploads']++;
				}
				$pos = $e['next'];
			}
			$archive->close();
		} catch ( Exception $ex ) {
			$archive->close();
			throw $ex;
		}

		if ( null === $info['format'] || ! is_array( $info['manifest'] ) ) {
			throw new NLWM_Exception( 'This file is not a backup this plugin can restore.' );
		}
		if ( 'wpress' === $info['format'] && ! empty( $info['manifest']['encrypted'] ) ) {
			throw new NLWM_Exception( 'This backup is password-protected by the plugin that made it. Export it again from that plugin without a password.' );
		}
		if ( 'wpress' === $info['format'] && ! empty( $info['manifest']['compressed'] ) ) {
			throw new NLWM_Exception( 'This backup is compressed in a format that is not supported yet. Export it again from the source site without compression.' );
		}
		if ( ! empty( $info['manifest']['multisite'] ) || is_multisite() ) {
			throw new NLWM_Exception( 'Multisite networks are not supported yet. Backups can only be restored on single sites.' );
		}
		return $info;
	}

	/** Map a .wpress package.json onto our manifest keys. */
	protected static function from_wpress_package( $p ) {
		if ( ! is_array( $p ) ) {
			return null;
		}
		$content = isset( $p['WordPress']['Content'] ) ? untrailingslashit( $p['WordPress']['Content'] ) : '';
		$replace = array();
		if ( ! empty( $p['Replace']['OldValues'] ) ) {
			foreach ( $p['Replace']['OldValues'] as $i => $old ) {
				if ( isset( $p['Replace']['NewValues'][ $i ] ) ) {
					$replace[] = array( $old, $p['Replace']['NewValues'][ $i ] );
				}
			}
		}
		return array(
			'format'      => 'wpress',
			'site_url'    => isset( $p['SiteURL'] ) ? $p['SiteURL'] : '',
			'home_url'    => isset( $p['HomeURL'] ) ? $p['HomeURL'] : '',
			'internal_site_url' => isset( $p['InternalSiteURL'] ) ? $p['InternalSiteURL'] : '',
			'internal_home_url' => isset( $p['InternalHomeURL'] ) ? $p['InternalHomeURL'] : '',
			'wp_version'  => isset( $p['WordPress']['Version'] ) ? $p['WordPress']['Version'] : '',
			'php_version' => isset( $p['PHP']['Version'] ) ? $p['PHP']['Version'] : '',
			'plugin'      => isset( $p['Plugin']['Version'] ) ? 'AIOWPM ' . $p['Plugin']['Version'] : '',
			'content_dir' => $content,
			// Newer .wpress files keep paths under "WordPress"; older ones at the top level.
			'abspath'     => ! empty( $p['WordPress']['Absolute'] ) ? untrailingslashit( $p['WordPress']['Absolute'] ) : ( $content ? dirname( $content ) : '' ),
			'upload_dir'  => ! empty( $p['WordPress']['Uploads'] ) ? untrailingslashit( $p['WordPress']['Uploads'] ) : ( ! empty( $p['Uploads'] ) ? $p['Uploads'] : ( $content ? $content . '/uploads' : '' ) ),
			'upload_url'  => ! empty( $p['WordPress']['UploadsURL'] ) ? untrailingslashit( $p['WordPress']['UploadsURL'] ) : ( ! empty( $p['UploadsURL'] ) ? $p['UploadsURL'] : '' ),
			'plugins'     => isset( $p['Plugins'] ) ? $p['Plugins'] : array(),
			'template'    => isset( $p['Template'] ) ? $p['Template'] : '',
			'stylesheet'  => isset( $p['Stylesheet'] ) ? $p['Stylesheet'] : '',
			'has_db'      => empty( $p['NoDatabase'] ),
			'replace'     => $replace,
			'encrypted'   => ! empty( $p['Encrypted'] ),
			'compressed'  => ! empty( $p['Compression']['Enabled'] ),
			'multisite'   => false,
			'prefix'      => null,
		);
	}

	/* ================================================================ steps */

	public static function prepare( array &$job, $deadline ) {
		global $wpdb;
		$path = NLWM_Util::backup_path( $job['opts']['backup'] );
		$info = self::inspect( $path );
		if ( $info['encrypted'] ) {
			$check = new NLWM_Archive( $path, 'read', null, isset( $job['opts']['password'] ) ? $job['opts']['password'] : '' );
			$check->close(); // throws on a missing or wrong password
			NLWM_Job::log( $job, 'Encrypted backup: password accepted.' );
		}
		$m    = $info['manifest'];
		$up   = wp_upload_dir( null, false );

		NLWM_Job::log( $job, sprintf( 'Restoring %s (%s, %s entries) from %s.', $job['opts']['backup'], NLWM_Util::format_bytes( $info['size'] ), number_format_i18n( $info['entries'] ), $m['home_url'] ) );

		// Old -> new URLs (longest/most specific first is handled by strtr).
		$urls = array();
		foreach ( array(
			array( 'home_url', home_url() ),
			array( 'site_url', site_url() ),
			array( 'internal_home_url', home_url() ),
			array( 'internal_site_url', site_url() ),
			array( 'upload_url', $up['baseurl'] ),
		) as $pair ) {
			if ( ! empty( $m[ $pair[0] ] ) ) {
				$urls[ $m[ $pair[0] ] ] = $pair[1];
			}
		}
		$paths = array();
		foreach ( array(
			array( 'upload_dir', $up['basedir'] ),
			array( 'content_dir', WP_CONTENT_DIR ),
			array( 'abspath', ABSPATH ),
		) as $pair ) {
			if ( ! empty( $m[ $pair[0] ] ) ) {
				$paths[ $m[ $pair[0] ] ] = $pair[1];
			}
		}
		$extra = array();
		if ( ! empty( $m['replace'] ) ) {
			foreach ( $m['replace'] as $pair ) {
				$extra[ $pair[0] ] = $pair[1];
			}
		}
		if ( ! empty( $job['opts']['email_replace'] ) && ! empty( $m['home_url'] ) ) {
			$old_host = preg_replace( '/^www\./', '', (string) wp_parse_url( $m['home_url'], PHP_URL_HOST ) );
			$new_host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( $old_host && $new_host && $old_host !== $new_host ) {
				$extra[ '@' . $old_host ] = '@' . $new_host;
			}
		}

		$pairs = NLWM_Replacer::build_pairs( $urls, $paths, $extra );

		$job['data']['prepare'] = array(
			'path'        => $path,
			'format'      => $info['format'],
			'has_db'      => ! empty( $info['db_entry'] ) && empty( $job['opts']['skip_db'] ),
			'db_entry'    => $info['db_entry'],
			'archive_size'=> $info['size'],
			'manifest'    => $m,
			'pairs'       => $pairs,
			'sql'         => NLWM_Job::work_dir( $job ) . DIRECTORY_SEPARATOR . 'database.sql',
			'current'     => array(
				'siteurl' => get_option( 'siteurl' ),
				'home'    => get_option( 'home' ),
				'prefix'  => $wpdb->base_prefix,
			),
		);
		NLWM_Job::log( $job, sprintf( 'URL: %s → %s. %d replacement rules.', $m['home_url'], home_url(), count( $pairs ) ) );
		if ( ! empty( $m['wp_version'] ) && version_compare( $m['wp_version'], get_bloginfo( 'version' ), '>' ) ) {
			NLWM_Job::log( $job, sprintf( 'Note: the backup comes from WordPress %s, newer than this site (%s).', $m['wp_version'], get_bloginfo( 'version' ) ) );
		}

		if ( $job['data']['prepare']['has_db'] ) {
			$db = new NLWM_Database();
			$db->drop_with_prefix( NLWM_Database::TMP_PREFIX );
		}
		NLWM_Admin::maintenance( true );
		NLWM_Job::message( $job, 'Backup checked. Restoring files…' );
		return true;
	}

	protected static function safe_rel( $rel ) {
		$rel = str_replace( '\\', '/', $rel );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || '/' === $rel[0] || preg_match( '#(^|/)\.\.(/|$)#', $rel ) || preg_match( '#^[A-Za-z]:#', $rel ) ) {
			return null;
		}
		return $rel;
	}

	protected static function skip_entry( $rel ) {
		if ( in_array( $rel, self::$skip_dropins, true ) ) {
			return true;
		}
		$top = strtok( $rel, '/' );
		if ( 0 === strpos( $top, 'nlwm-backups' ) || fnmatch( NLWM_Util::backups_pattern(), $top ) || fnmatch( 'backup-migration-*', $top ) ) {
			return true;
		}
		foreach ( array_merge( NLWM_Files::always_excluded(), array( 'plugins/' . basename( NLWM_DIR ) ) ) as $p ) {
			if ( $rel === $p || 0 === strpos( $rel, $p . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function extract_db( array &$job, $deadline ) {
		$prep = $job['data']['prepare'];
		if ( ! $prep['has_db'] ) {
			return true;
		}
		$state   = &$job['data']['extract_db'];
		$archive = new NLWM_Archive( $prep['path'], 'read', null, isset( $job['opts']['password'] ) ? $job['opts']['password'] : null );
		try {
			$done = $archive->extract_entry( $prep['db_entry'], $prep['sql'], $state, $deadline );
		} finally {
			$archive->close();
		}
		NLWM_Job::message( $job, 'Unpacking the database…', $state['in'] / max( 1, $prep['db_entry']['stored'] ) );
		return $done;
	}

	public static function files( array &$job, $deadline ) {
		$prep  = $job['data']['prepare'];
		$state = &$job['data']['files'];
		$state += array( 'pos' => null, 'es' => array(), 'count' => 0, 'skipped' => 0 );

		$archive = new NLWM_Archive( $prep['path'], 'read', null, isset( $job['opts']['password'] ) ? $job['opts']['password'] : null );
		if ( null === $state['pos'] ) {
			$state['pos'] = $archive->first();
		}
		$root    = untrailingslashit( WP_CONTENT_DIR );
		$done    = false;
		try {
			while ( true ) {
				$e = $archive->entry_at( $state['pos'] );
				if ( null === $e ) {
					$done = true;
					break;
				}
				$rel = self::safe_rel( $e['rel'] );

				if ( null === $rel || in_array( $e['rel'], self::$meta_files, true ) || self::skip_entry( $rel ) || ! empty( $job['opts']['skip_files'] ) ) {
					$state['skipped']++;
				} else {
					if ( ! $archive->extract_entry( $e, $root . '/' . $rel, $state['es'], $deadline ) ) {
						break;
					}
					$state['count']++;
				}
				$state['pos'] = $e['next'];
				$state['es']  = array();
				if ( microtime( true ) > $deadline ) {
					break;
				}
			}
		} catch ( Exception $ex ) {
			$archive->close();
			throw $ex;
		}
		$archive->close();

		NLWM_Job::message( $job, sprintf( 'Restoring files… %s files', number_format_i18n( $state['count'] ) ), $state['pos'] / max( 1, $prep['archive_size'] ) );
		if ( $done ) {
			NLWM_Job::log( $job, sprintf( 'Restored %d files (%d skipped).', $state['count'], $state['skipped'] ) );
		}
		return $done;
	}

	public static function database( array &$job, $deadline ) {
		$prep = $job['data']['prepare'];
		if ( ! $prep['has_db'] ) {
			return true;
		}
		$state        = &$job['data']['database'];
		$db           = new NLWM_Database();
		$placeholders = 'wpress' === $prep['format'] ? array( NLWM_WPRESS_PLACEHOLDER ) : array( NLWM_PREFIX_PLACEHOLDER );
		$replacer     = new NLWM_Replacer( $prep['pairs'] );
		$done         = $db->restore( $prep['sql'], $state, $placeholders, $prep['current']['prefix'], $replacer, $deadline );

		$size = max( 1, filesize( $prep['sql'] ) );
		NLWM_Job::message( $job, sprintf( 'Restoring database… %d%%', floor( 100 * $state['offset'] / $size ) ), $state['offset'] / $size );
		if ( $done ) {
			NLWM_Job::log( $job, sprintf( 'Database: %s statements executed into temporary tables.', number_format_i18n( $state['statements'] ) ) );
			if ( $state['error_count'] ) {
				NLWM_Job::log( $job, sprintf( 'Warning: %d statements failed. First errors:', $state['error_count'] ) );
				foreach ( $state['errors'] as $err ) {
					NLWM_Job::log( $job, '  ' . $err );
				}
			}
		}
		return $done;
	}

	public static function swap( array &$job, $deadline ) {
		$prep = $job['data']['prepare'];
		if ( ! $prep['has_db'] ) {
			return true;
		}
		global $wpdb;
		$db    = new NLWM_Database();
		$p     = $prep['current']['prefix'];
		$state = &$job['data']['swap'];

		// This site's own secrets/connections (license, cloud keys) survive the restore.
		$private = array();
		if ( empty( $state['renamed'] ) ) {
			$names   = NLWM_Util::private_options();
			$private = $db->rows( $db->prepare( "SELECT option_name, option_value, autoload FROM `{$p}options` WHERE option_name IN (" . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ), true );
		}

		// Idempotent: if a previous request renamed the tables and then died, only redo the fix-ups.
		if ( empty( $state['renamed'] ) || $db->has_tmp_tables() ) {
			$db->check_names( $p );
			$state['renamed'] = true;
			NLWM_Job::save( $job ); // record intent before the point of no return
			$tables = $db->swap( $p );
			NLWM_Job::log( $job, sprintf( 'Switched the site to the %d imported tables.', count( $tables ) ) );
		}

		$o  = "`{$p}options`";
		$um = "`{$p}usermeta`";

		foreach ( $private as $row ) {
			$db->query( $db->prepare( "DELETE FROM {$o} WHERE option_name = %s", $row['option_name'] ) );
			$db->query( $db->prepare( "INSERT INTO {$o} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $row['option_name'], $row['option_value'], $row['autoload'] ) );
		}
		if ( $private ) {
			NLWM_Job::log( $job, sprintf( 'Kept this site\'s own connections and keys (%d settings).', count( $private ) ) );
		}

		// Our own dumps keep the source prefix inside option/meta keys: rename them.
		$old = isset( $prep['manifest']['prefix'] ) ? $prep['manifest']['prefix'] : null;
		if ( $old && $old !== $p ) {
			$len = strlen( $old ) + 1;
			$db->query( $db->prepare( "UPDATE {$o} SET option_name = %s WHERE option_name = %s", $p . 'user_roles', $old . 'user_roles' ) );
			$db->query( $db->prepare( "UPDATE {$um} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $p, $len, $wpdb->esc_like( $old ) . '%' ) );
			NLWM_Job::log( $job, sprintf( 'Table prefix changed: %s → %s.', $old, $p ) );
		}

		// Keep this site's address and keep this plugin active so the restore can finish.
		$db->query( $db->prepare( "UPDATE {$o} SET option_value = %s WHERE option_name = 'siteurl'", $prep['current']['siteurl'] ) );
		$db->query( $db->prepare( "UPDATE {$o} SET option_value = %s WHERE option_name = 'home'", $prep['current']['home'] ) );

		$m       = $prep['manifest'];
		$raw     = $db->one( "SELECT option_value FROM {$o} WHERE option_name = 'active_plugins'" );
		$plugins = is_string( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : array();
		$plugins = is_array( $plugins ) ? array_values( $plugins ) : array();

		if ( 'wpress' === $prep['format'] ) {
			// .wpress dumps leave the theme and active plugins empty; the real values are in package.json.
			$plugins = array();
			foreach ( (array) $m['plugins'] as $pl ) {
				if ( is_string( $pl ) && file_exists( WP_PLUGIN_DIR . '/' . $pl ) ) {
					$plugins[] = $pl;
				}
			}
			$raw = null === $raw ? null : '';
			foreach ( array( 'template', 'stylesheet' ) as $key ) {
				$theme = isset( $m[ $key ] ) ? (string) $m[ $key ] : '';
				if ( '' !== $theme && is_dir( get_theme_root() . '/' . $theme ) ) {
					$db->query( $db->prepare( "UPDATE {$o} SET option_value = %s WHERE option_name = %s", $theme, $key ) );
				}
			}
			NLWM_Job::log( $job, sprintf( 'Theme %s and %d plugins activated from the .wpress package.', $m['stylesheet'], count( $plugins ) ) );
		}

		// Never leave the site on a theme that is not installed (WordPress would show "Stylesheet is missing").
		$fallback = null;
		foreach ( array( 'template', 'stylesheet' ) as $key ) {
			$theme = (string) $db->one( $db->prepare( "SELECT option_value FROM {$o} WHERE option_name = %s", $key ) );
			if ( '' !== $theme && is_dir( get_theme_root() . '/' . $theme ) ) {
				continue;
			}
			if ( null === $fallback ) {
				$fallback = defined( 'WP_DEFAULT_THEME' ) && is_dir( get_theme_root() . '/' . WP_DEFAULT_THEME ) ? WP_DEFAULT_THEME : '';
				if ( '' === $fallback ) {
					foreach ( (array) glob( get_theme_root() . '/*/style.css' ) as $css ) {
						$fallback = basename( dirname( $css ) );
						break;
					}
				}
			}
			if ( $fallback ) {
				$db->query( $db->prepare( "UPDATE {$o} SET option_value = %s WHERE option_name IN ('template','stylesheet')", $fallback ) );
				NLWM_Job::log( $job, sprintf( 'The theme "%s" is not installed here; switched to %s.', $theme, $fallback ) );
				break;
			}
		}
		// Keep exactly one copy of this plugin active (another copy under a different folder name
		// would fatal with "cannot redeclare class").
		$fixed = array_values( array_filter( $plugins, function ( $pl ) {
			return NLWM_BASENAME !== $pl && basename( NLWM_FILE ) !== basename( (string) $pl );
		} ) );
		$fixed[] = NLWM_BASENAME;
		if ( $fixed !== $plugins ) {
			$value = serialize( $fixed );
			if ( null === $raw ) {
				$db->query( $db->prepare( "INSERT INTO {$o} (option_name, option_value, autoload) VALUES ('active_plugins', %s, 'yes')", $value ) );
			} else {
				$db->query( $db->prepare( "UPDATE {$o} SET option_value = %s WHERE option_name = 'active_plugins'", $value ) );
			}
		}

		// Force WordPress to rebuild permalinks and drop stale caches stored in options.
		$db->query( $db->prepare( "DELETE FROM {$o} WHERE option_name IN ('rewrite_rules') OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );

		NLWM_Job::message( $job, 'Database switched. Finishing…' );
		return true;
	}

	public static function finish( array &$job, $deadline ) {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		NLWM_Admin::maintenance( false );
		NLWM_Util::rrmdir( NLWM_Job::work_dir( $job ) );
		$job['opts']['password'] = '';

		// Downloaded from Drive only for this restore, and the owner keeps backups on Drive only.
		if ( ! empty( $job['opts']['delete_after'] ) ) {
			wp_delete_file( NLWM_Util::backup_path( $job['opts']['backup'] ) );
		}

		$warn = array();
		if ( ! empty( $job['data']['database']['error_count'] ) ) {
			$warn[] = sprintf( '%d database statements failed; see the log.', $job['data']['database']['error_count'] );
		}
		$job['result'] = array(
			'login'    => wp_login_url(),
			'home'     => home_url(),
			'warnings' => $warn,
			'db'       => $job['data']['prepare']['has_db'],
		);
		NLWM_Job::message( $job, 'Restore complete.' );
		return true;
	}

	public static function cleanup( array &$job, $ok ) {
		if ( ! empty( $job['data']['prepare']['has_db'] ) && empty( $job['data']['swap']['renamed'] ) ) {
			try {
				$db = new NLWM_Database();
				$db->drop_with_prefix( NLWM_Database::TMP_PREFIX );
				NLWM_Job::log( $job, 'Temporary tables removed. Your live database was not changed.' );
				if ( ! empty( $job['data']['files']['count'] ) ) {
					NLWM_Job::log( $job, sprintf( 'Note: %d files from the backup had already been copied into wp-content.', $job['data']['files']['count'] ) );
				}
			} catch ( Exception $e ) {
				NLWM_Job::log( $job, 'Could not remove temporary tables: ' . $e->getMessage() );
			}
		}
		NLWM_Admin::maintenance( false );
		NLWM_Util::rrmdir( NLWM_Job::work_dir( $job ) );
		$job['opts']['password'] = '';
	}
}
