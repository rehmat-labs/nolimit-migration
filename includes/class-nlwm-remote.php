<?php
/**
 * Downloads and site-to-site transfer.
 *
 * - Backups are served through admin-ajax (never directly from the protected folder) with HTTP Range
 *   support, so large downloads resume.
 * - "Transfer links" are signed, expiring URLs for one backup. Paste one into "Import from URL" on the
 *   destination site and it pulls the file server-to-server in resumable chunks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename
// Backups and restores run in short resumable steps; raising the time and memory limit for one step is part of making large sites work on shared hosting.
// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged

class NLWM_Remote {

	const CHUNK = 52428800; // 50 MB per download request

	public static function init() {
		add_action( 'wp_ajax_nlwm_download', array( __CLASS__, 'serve' ) );
		add_action( 'wp_ajax_nopriv_nlwm_download', array( __CLASS__, 'serve' ) );
	}

	/**
	 * @param bool $signed true for a transfer link that works without a login.
	 */
	public static function download_url( $name, $signed = true, $hours = 24 ) {
		$args = array( 'action' => 'nlwm_download', 'file' => $name );
		if ( $signed ) {
			$args['exp'] = time() + (int) $hours * HOUR_IN_SECONDS;
			$args['sig'] = self::sign( $name, $args['exp'] );
		} else {
			$args['_wpnonce'] = wp_create_nonce( 'nlwm_download_' . $name );
		}
		return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	}

	protected static function sign( $name, $exp ) {
		return hash_hmac( 'sha256', $name . '|' . $exp, NLWM_Util::secret() );
	}

	public static function serve() {
		// Not sanitize_file_name(): it can alter valid names and break the signature.
		// valid_backup_name() below is the strict check.
		$name = isset( $_GET['file'] ) ? (string) wp_unslash( $_GET['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by the signature and valid_backup_name() below.
		$ok   = false;
		if ( isset( $_GET['sig'], $_GET['exp'] ) ) {
			$exp = (int) $_GET['exp'];
			$ok  = $exp > time() && hash_equals( self::sign( $name, $exp ), (string) wp_unslash( $_GET['sig'] ) );
		} elseif ( current_user_can( 'export' ) && isset( $_GET['_wpnonce'] ) ) {
			$ok = (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'nlwm_download_' . $name );
		}
		if ( ! $ok || ! NLWM_Util::valid_backup_name( $name ) ) {
			status_header( 403 );
			exit( 'This download link is invalid or has expired.' );
		}
		$path = NLWM_Util::backup_path( $name );
		if ( ! is_file( $path ) ) {
			status_header( 404 );
			exit( 'Backup not found.' );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		@set_time_limit( 0 );
		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;

		header( 'Accept-Ranges: bytes' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: no-store' );

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		} else {
			status_header( 200 );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		$fh = fopen( $path, 'rb' );
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$buf = fread( $fh, min( 1048576, $left ) );
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
			$left -= strlen( $buf );
		}
		fclose( $fh );
		exit;
	}

	/* ================================================================ import from URL */

	public static function steps() {
		return array(
			'download'  => array( array( __CLASS__, 'download_step' ), 100 ),
			'__cleanup' => array( array( __CLASS__, 'cleanup' ) ),
		);
	}

	/**
	 * Pull the file in Range-request chunks. Each chunk is streamed to a temporary file by the WordPress
	 * HTTP API and appended, so a dropped connection costs at most one chunk.
	 */
	public static function download_step( array &$job, $deadline ) {
		$state = &$job['data']['download'];
		if ( empty( $state['dest'] ) && ! empty( $job['opts']['storage'] ) ) {
			$storage = NLWM_Storages::get( $job['opts']['storage'] );
			list( $orig, $size ) = $storage->file_info( $job['opts']['remote_id'] );
			$name = NLWM_Util::valid_backup_name( $orig ) && ! file_exists( NLWM_Util::backup_path( $orig ) )
				? $orig
				: sprintf( 'cloud-%s-%s.%s', gmdate( 'Ymd-His' ), NLWM_Util::random( 6 ), preg_match( '/\.wpress$/', $orig ) ? 'wpress' : NLWM_EXT );
			$state = array(
				'url'     => $storage->download_url( $job['opts']['remote_id'] ),
				'name'    => $name,
				'dest'    => NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . $name . '.part',
				'offset'  => 0,
				'total'   => $size,
				'ranges'  => true,
				'storage' => $storage->id(),
			);
			NLWM_Util::ensure_dirs();
			wp_delete_file( $state['dest'] );
			NLWM_Job::log( $job, sprintf( 'Downloading %s from %s.', $orig, $storage->label() ) );
		}
		if ( empty( $state['dest'] ) ) {
			$url = trim( $job['opts']['url'] );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				throw new NLWM_Exception( 'Enter a valid http(s) URL.' );
			}
			// Block internal addresses (127.0.0.1, 10.x, 169.254.x …) unless a site owner allows them.
			if ( ! apply_filters( 'nlwm_allow_local_urls', false ) && ! wp_http_validate_url( $url ) ) {
				throw new NLWM_Exception( 'This link points to a private or local address, which is blocked for safety. (Developers: allow it with the nlwm_allow_local_urls filter.)' );
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$ext  = preg_match( '/\.wpress$/i', $path ) ? 'wpress' : NLWM_EXT;
			$host = preg_replace( '/[^a-z0-9.-]+/i', '-', (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$name = sprintf( 'url-%s-%s-%s.%s', $host, gmdate( 'Ymd-His' ), NLWM_Util::random( 6 ), $ext );
			$state = array(
				'url'    => $url,
				'name'   => $name,
				'dest'   => NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . $name . '.part',
				'offset' => 0,
				'total'  => null,
				'ranges' => true,
			);
			NLWM_Util::ensure_dirs();
			wp_delete_file( $state['dest'] );
			NLWM_Job::log( $job, 'Downloading ' . preg_replace( '/([?&](sig|_wpnonce)=)[^&]+/', '$1…', $url ) );
		}

		// A crashed request may have appended bytes after the last saved offset: cut them off.
		clearstatcache( true, $state['dest'] );
		if ( file_exists( $state['dest'] ) && filesize( $state['dest'] ) > $state['offset'] ) {
			$fh = fopen( $state['dest'], 'r+b' );
			ftruncate( $fh, $state['offset'] );
			fclose( $fh );
		}

		while ( null === $state['total'] || $state['offset'] < $state['total'] ) {
			$tmp     = $state['dest'] . '.chunk';
			$headers = array();
			if ( ! empty( $state['storage'] ) ) {
				$headers = NLWM_Storages::get( $state['storage'] )->download_headers( $job['opts']['remote_id'] );
			}
			if ( $state['ranges'] ) {
				$headers['Range'] = sprintf( 'bytes=%d-%d', $state['offset'], $state['offset'] + self::CHUNK - 1 );
			}
			// Cloud storages may hand out short-lived signed URLs: ask for a fresh one per chunk.
			$fetch_url = ! empty( $state['storage'] ) ? NLWM_Storages::get( $state['storage'] )->download_url( $job['opts']['remote_id'] ) : $state['url'];
			$response  = wp_remote_get( $fetch_url, array(
				'timeout'     => $state['ranges'] ? 300 : 3600,
				'stream'      => true,
				'filename'    => $tmp,
				'headers'     => $headers,
				'redirection' => 10,
				'sslverify'   => apply_filters( 'nlwm_download_sslverify', true ),
				'reject_unsafe_urls' => ! apply_filters( 'nlwm_allow_local_urls', false ),
				'user-agent'  => 'NoLimitWPMigration/' . NLWM_VERSION,
			) );
			if ( is_wp_error( $response ) ) {
				wp_delete_file( $tmp );
				$state['retries'] = isset( $state['retries'] ) ? $state['retries'] + 1 : 1;
				if ( $state['retries'] > 5 ) {
					throw new NLWM_Exception( esc_html( 'Download failed: ' . $response->get_error_message() ) );
				}
				NLWM_Job::log( $job, 'Network error, retrying: ' . $response->get_error_message() );
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( 206 === $code ) {
				$range = (string) wp_remote_retrieve_header( $response, 'content-range' );
				if ( preg_match( '#/(\d+)$#', $range, $m ) ) {
					$state['total'] = (int) $m[1];
				} elseif ( filesize( $tmp ) < self::CHUNK ) {
					// Total size unknown ("bytes 0-99/*"): a short chunk means we reached the end.
					$state['total'] = $state['offset'] + filesize( $tmp );
				}
			} elseif ( 200 === $code && 0 === $state['offset'] ) {
				// Server ignores Range: the whole file arrived in this one response.
				$state['ranges'] = false;
				$state['total']  = filesize( $tmp );
			} elseif ( 416 === $code && null !== $state['total'] ) {
				wp_delete_file( $tmp );
				break;
			} else {
				wp_delete_file( $tmp );
				throw new NLWM_Exception( esc_html( sprintf( 'The server answered HTTP %d. Check that the link is public and still valid.', $code ) ) );
			}
			if ( 0 === $state['offset'] && false !== stripos( $type, 'text/html' ) ) {
				$head = (string) file_get_contents( $tmp, false, null, 0, 512 );
				if ( false !== stripos( $head, '<html' ) || false !== stripos( $head, '<!doctype' ) ) {
					wp_delete_file( $tmp );
					throw new NLWM_Exception( 'The link returned a web page instead of a backup file. Use a direct download link (or a share link set to "anyone with the link").' );
				}
			}

			$state['offset'] += self::append( $tmp, $state['dest'] );
			wp_delete_file( $tmp );
			$state['retries'] = 0;
			NLWM_Job::save( $job ); // offset is durable before the next chunk starts

			$sub = $state['total'] ? $state['offset'] / $state['total'] : 0;
			NLWM_Job::message( $job, sprintf( 'Downloading… %s of %s', NLWM_Util::format_bytes( $state['offset'] ), $state['total'] ? NLWM_Util::format_bytes( $state['total'] ) : '?' ), $sub );
			if ( ! $state['ranges'] || microtime( true ) > $deadline ) {
				break;
			}
		}

		if ( null !== $state['total'] && $state['offset'] >= $state['total'] ) {
			$final = NLWM_Util::backup_path( $state['name'] );
			if ( ! @rename( $state['dest'], $final ) ) {
				throw new NLWM_Exception( 'Could not save the downloaded file.' );
			}
			// Make sure it really is a backup before we list it.
			try {
				$info = NLWM_Import::inspect( $final );
			} catch ( Exception $e ) {
				wp_delete_file( $final );
				throw $e;
			}
			NLWM_Backups::set_meta( $state['name'], array(
				'type'     => ! empty( $state['storage'] ) ? 'cloud' : 'url',
				'created'  => time(),
				'label'    => ! empty( $state['storage'] ) ? 'Downloaded from ' . NLWM_Storages::get( $state['storage'] )->label() : 'From ' . (string) wp_parse_url( $state['url'], PHP_URL_HOST ),
				'site_url' => isset( $info['manifest']['home_url'] ) ? $info['manifest']['home_url'] : '',
			) );
			$job['result'] = array( 'name' => $state['name'], 'size_human' => NLWM_Util::format_bytes( $state['offset'] ) );
			NLWM_Job::log( $job, sprintf( 'Downloaded %s (%s).', $state['name'], NLWM_Util::format_bytes( $state['offset'] ) ) );
			return true;
		}
		return false;
	}

	protected static function append( $src, $dest ) {
		$in  = fopen( $src, 'rb' );
		$out = fopen( $dest, 'ab' );
		if ( ! $in || ! $out ) {
			throw new NLWM_Exception( 'Could not write the downloaded file.' );
		}
		$bytes = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( false === $bytes ) {
			throw new NLWM_Exception( 'Could not write the downloaded file. The disk may be full.' );
		}
		return $bytes;
	}

	public static function cleanup( array &$job, $ok ) {
		if ( ! empty( $job['data']['download']['dest'] ) ) {
			wp_delete_file( $job['data']['download']['dest'] );
			wp_delete_file( $job['data']['download']['dest'] . '.chunk' );
		}
	}
}
