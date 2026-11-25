<?php
/**
 * WP-CLI: back up, restore, list and fetch from the command line (no time limits, ideal for cron).
 *
 *     wp nolimit backup [--exclude=no_media,no_revisions] [--label=<text>]
 *     wp nolimit restore <file> [--yes] [--email-replace]
 *     wp nolimit list
 *     wp nolimit fetch <url>
 *     wp nolimit link <file> [--hours=24]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NLWM_CLI {

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude=<list>]
	 * : Comma separated: no_media, no_themes, no_inactive_themes, no_plugins, no_inactive_plugins,
	 *   no_muplugins, no_cache, no_database, no_spam, no_revisions, no_transients, no_wc_logs
	 *
	 * [--label=<text>]
	 * : Label shown in the backups list.
	 *
	 * [--scheduled]
	 * : Count it as a scheduled backup (retention applies).
	 *
	 * [--upload]
	 * : Also upload to every connected cloud storage (NoLimit Pro: Google Drive, S3 …).
	 */
	public function backup( $args, $assoc ) {
		$opts = array();
		if ( ! empty( $assoc['exclude'] ) ) {
			foreach ( explode( ',', $assoc['exclude'] ) as $k ) {
				$opts[ sanitize_key( trim( $k ) ) ] = 1;
			}
		}
		$opts['label']  = isset( $assoc['label'] ) ? $assoc['label'] : '';
		$opts['origin'] = isset( $assoc['scheduled'] ) ? 'scheduled' : 'manual';
		$opts['storages'] = isset( $assoc['upload'] ) ? array_keys( NLWM_Storages::connected() ) : array();
		$job            = $this->run( 'export', $opts );
		foreach ( isset( $job['result']['warnings'] ) ? $job['result']['warnings'] : array() as $w ) {
			WP_CLI::warning( $w );
		}
		WP_CLI::success( sprintf( 'Backup created: %s (%s)%s', $job['result']['name'], $job['result']['size_human'], ! empty( $job['result']['uploaded'] ) ? ', uploaded to ' . implode( ', ', $job['result']['uploaded'] ) : '' ) );
	}

	/**
	 * Restore a backup from the backups folder (or any path, which is copied in first).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name or path (.nolimit).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--email-replace]
	 * : Also change e-mail domains to the new domain.
	 */
	public function restore( $args, $assoc ) {
		$file = $args[0];
		$name = basename( $file );
		if ( ! NLWM_Util::valid_backup_name( $name ) ) {
			WP_CLI::error( 'The file must end in .' . NLWM_EXT . ' and contain only letters, numbers, dots, dashes and underscores.' );
		}
		NLWM_Util::ensure_dirs();
		$target = NLWM_Util::backup_path( $name );
		if ( realpath( $file ) && realpath( $file ) !== realpath( $target ) ) {
			WP_CLI::log( 'Copying into the backups folder…' );
			if ( ! copy( $file, $target ) ) {
				WP_CLI::error( 'Could not copy the file.' );
			}
		}
		if ( ! file_exists( $target ) ) {
			WP_CLI::error( 'Backup not found: ' . $file );
		}
		$info = NLWM_Import::inspect( $target );
		WP_CLI::log( sprintf( 'Backup from %s (WordPress %s), %d entries.', $info['manifest']['home_url'], $info['manifest']['wp_version'], $info['entries'] ) );
		WP_CLI::confirm( 'This replaces the whole site at ' . home_url() . '. Continue?', $assoc );
		$this->run( 'import', array(
			'backup'        => $name,
			'email_replace' => isset( $assoc['email-replace'] ),
		) );
		WP_CLI::success( 'Site restored. Log in with a user from the backup.' );
	}

	/**
	 * List backups.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( NLWM_Backups::all() as $b ) {
			$rows[] = array(
				'name'    => $b['name'],
				'label'   => $b['label'],
				'type'    => $b['type'],
				'size'    => NLWM_Util::format_bytes( $b['size'] ),
				'created' => wp_date( 'Y-m-d H:i', $b['created'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'label', 'type', 'size', 'created' ) );
	}

	/**
	 * Download a backup from a URL or transfer link into the backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Direct download link or transfer link.
	 */
	public function fetch( $args, $assoc ) {
		$job = $this->run( 'fetch', array( 'url' => $args[0] ) );
		WP_CLI::success( 'Saved as ' . $job['result']['name'] . '. Restore it with: wp nolimit restore ' . $job['result']['name'] );
	}

	/**
	 * Print a signed transfer link for a backup.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file name.
	 *
	 * [--hours=<hours>]
	 * : How long the link works. Default 24.
	 */
	public function link( $args, $assoc ) {
		$name = basename( $args[0] );
		if ( ! file_exists( NLWM_Util::backup_path( $name ) ) ) {
			WP_CLI::error( 'Backup not found.' );
		}
		WP_CLI::line( NLWM_Remote::download_url( $name, true, isset( $assoc['hours'] ) ? (int) $assoc['hours'] : 24 ) );
	}

	protected function run( $type, array $opts ) {
		try {
			$job = NLWM_Job::create( $type, $opts );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$last = '';
		while ( 'running' === $job['status'] ) {
			$job = NLWM_Job::run( $job, 60 );
			$msg = sprintf( '[%3d%%] %s', $job['progress'], $job['message'] );
			if ( $msg !== $last ) {
				WP_CLI::log( $msg );
				$last = $msg;
			}
		}
		if ( 'done' !== $job['status'] ) {
			WP_CLI::error( $job['message'] );
		}
		return $job;
	}
}
