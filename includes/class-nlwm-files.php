<?php
/**
 * Lists the wp-content files that go into a backup, applying the user's exclusions.
 * Scanning is resumable (a directory stack lives in the job state) so huge sites never time out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename

class NLWM_Files {

	/** Folders never copied: our own data, other backup plugins' archives, temp and cache junk. */
	public static function always_excluded() {
		$plugin_dir = basename( NLWM_DIR );
		return array(
			'nlwm-backups',
			'plugins/' . $plugin_dir,
			'ai1wm-backups',
			'updraft',
			'backups-dup-lite',
			'backups-dup-pro',
			'wpvividbackups',
			'backup-guard',
			'backups',
			'upgrade',
			'upgrade-temp-backup',
			'wflogs',
			'debug.log',
			'uploads/backwpup',
		);
	}

	public static function cache_dirs() {
		return array( 'cache', 'et-cache', 'litespeed', 'w3tc-config', 'wp-rocket-config', 'endurance-page-cache', 'uploads/cache', 'uploads/wpo-cache', 'uploads/elementor/css' );
	}

	/**
	 * Build the exclusion rules from export options.
	 */
	public static function rules( array $opts ) {
		$ex = self::always_excluded();

		$uploads = self::uploads_rel();
		if ( ! empty( $opts['no_media'] ) && $uploads ) {
			$ex[] = $uploads;
		}
		if ( ! empty( $opts['no_cache'] ) ) {
			$ex = array_merge( $ex, self::cache_dirs() );
		}
		if ( ! empty( $opts['no_muplugins'] ) ) {
			$ex[] = 'mu-plugins';
		}
		if ( ! empty( $opts['no_themes'] ) ) {
			$ex[] = 'themes';
		} elseif ( ! empty( $opts['no_inactive_themes'] ) ) {
			$keep = array_unique( array( get_template(), get_stylesheet() ) );
			foreach ( (array) @scandir( get_theme_root() ) as $dir ) {
				if ( '.' !== $dir[0] && is_dir( get_theme_root() . '/' . $dir ) && ! in_array( $dir, $keep, true ) ) {
					$ex[] = 'themes/' . $dir;
				}
			}
		}
		if ( ! empty( $opts['no_plugins'] ) ) {
			$ex[] = 'plugins';
		} elseif ( ! empty( $opts['no_inactive_plugins'] ) ) {
			$active = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
				$active[] = strpos( $p, '/' ) ? dirname( $p ) : $p;
			}
			foreach ( (array) @scandir( WP_PLUGIN_DIR ) as $entry ) {
				if ( '.' === $entry[0] || 'index.php' === $entry ) {
					continue;
				}
				if ( ! in_array( $entry, $active, true ) ) {
					$ex[] = 'plugins/' . $entry;
				}
			}
		}

		$patterns = array( NLWM_Util::backups_pattern(), 'backup-migration-*', 'ai1wm-backups*' );
		if ( ! empty( $opts['exclude_paths'] ) ) {
			foreach ( preg_split( '/[\r\n,]+/', (string) $opts['exclude_paths'] ) as $line ) {
				$line = trim( str_replace( '\\', '/', $line ), "/ \t" );
				if ( '' === $line ) {
					continue;
				}
				if ( strpbrk( $line, '*?[' ) ) {
					$patterns[] = $line;
				} else {
					$ex[] = $line;
				}
			}
		}

		return array(
			'paths'    => array_values( array_unique( $ex ) ),
			'patterns' => $patterns,
			'max_size' => ! empty( $opts['max_file_mb'] ) ? (int) $opts['max_file_mb'] * MB_IN_BYTES : 0,
		);
	}

	/** uploads folder relative to wp-content, or '' if it lives elsewhere. */
	public static function uploads_rel() {
		$up      = wp_upload_dir( null, false );
		$content = NLWM_Util::norm( WP_CONTENT_DIR );
		$base    = NLWM_Util::norm( $up['basedir'] );
		if ( 0 === strpos( $base . '/', $content . '/' ) ) {
			return ltrim( substr( $base, strlen( $content ) ), '/' );
		}
		return '';
	}

	public static function is_excluded( $rel, array $rules, $is_dir = false ) {
		foreach ( $rules['paths'] as $p ) {
			if ( $rel === $p || 0 === strpos( $rel, $p . '/' ) ) {
				return true;
			}
		}
		foreach ( $rules['patterns'] as $pat ) {
			if ( fnmatch( $pat, $rel, FNM_PATHNAME ) || fnmatch( $pat, basename( $rel ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Continue scanning. Appends "relative/path\tsize\n" lines to $list_file.
	 *
	 * @return bool true when the scan is finished.
	 */
	public static function scan( $list_file, array &$state, array $rules, $deadline ) {
		$root = NLWM_Util::norm( WP_CONTENT_DIR );
		$state += array( 'stack' => array( '' ), 'files' => 0, 'bytes' => 0, 'skipped' => 0 );

		$state += array( 'list_bytes' => 0 );
		$fh     = fopen( $list_file, 'c+b' );
		if ( ! $fh ) {
			throw new NLWM_Exception( 'Cannot write the file list.' );
		}
		ftruncate( $fh, $state['list_bytes'] );
		fseek( $fh, 0, SEEK_END );
		while ( $state['stack'] ) {
			$rel_dir = array_pop( $state['stack'] );
			$abs_dir = '' === $rel_dir ? $root : $root . '/' . $rel_dir;
			$items   = @scandir( $abs_dir );
			if ( false === $items ) {
				continue;
			}
			$buf = '';
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$rel = '' === $rel_dir ? $item : $rel_dir . '/' . $item;
				$abs = $abs_dir . '/' . $item;
				if ( false !== strpos( $rel, "\t" ) || false !== strpos( $rel, "\n" ) ) {
					continue;
				}
				if ( is_dir( $abs ) ) {
					if ( ! self::is_excluded( $rel, $rules, true ) && substr_count( $rel, '/' ) < 40 ) {
						$state['stack'][] = $rel;
					}
					continue;
				}
				if ( self::is_excluded( $rel, $rules ) ) {
					continue;
				}
				$size = @filesize( $abs );
				if ( false === $size ) {
					continue;
				}
				if ( $rules['max_size'] && $size > $rules['max_size'] ) {
					$state['skipped']++;
					continue;
				}
				$buf .= $rel . "\t" . $size . "\n";
				$state['files']++;
				$state['bytes'] += $size;
			}
			if ( '' !== $buf && fwrite( $fh, $buf ) !== strlen( $buf ) ) {
				fclose( $fh );
				throw new NLWM_Exception( 'Could not write the file list. The disk may be full.' );
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fflush( $fh );
		$state['list_bytes'] = ftell( $fh );
		fclose( $fh );
		return empty( $state['stack'] );
	}
}
