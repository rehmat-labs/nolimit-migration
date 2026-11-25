<?php
/**
 * Remote storage destinations.
 *
 * Every destination (Google Drive, S3-compatible … — all in NoLimit Migration Pro) implements
 * NLWM_Storage and is registered through the `nlwm_storages` filter. The export pipeline uploads to
 * every destination the job asks for, and the Backups screen lists, restores and deletes remote copies
 * through the same interface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class NLWM_Storage {

	/** Short machine id, e.g. "gdrive", "s3". */
	abstract public function id();

	/** Human name, e.g. "Google Drive". */
	abstract public function label();

	/** Ready to use (credentials present and valid as far as we know). */
	abstract public function connected();

	/** Who/where it is connected to, for display ("you@gmail.com", "bucket/path"). */
	public function account() {
		return '';
	}

	/** Settings screen for this destination. */
	public function settings_url() {
		return admin_url( 'admin.php?page=' . NLWM_SLUG . '&tab=drive' );
	}

	/**
	 * Upload a local backup, resumable across requests.
	 *
	 * @param string $path     local file
	 * @param array  $props    small metadata: type, site, label
	 * @param array  $state    by reference, owned by the implementation; set $state['id'] when done
	 * @param float  $deadline microtime(true) to stop at
	 * @return bool true when the upload is complete
	 */
	abstract public function upload( $path, array $props, array &$state, $deadline );

	/** Backups stored there, newest first: [ id, name, size, created (unix), type, label, view ] */
	abstract public function files();

	abstract public function delete( $id );

	/** Direct URL for downloading one backup with Range requests. */
	abstract public function download_url( $id );

	/** Request headers for that download (called before every chunk, so tokens can be refreshed). */
	public function download_headers( $id ) {
		return array();
	}

	/** Original file name and size of one backup: [ name, size ]. */
	abstract public function file_info( $id );

	/** Keep only the newest $keep scheduled backups. Returns deleted names. */
	public function apply_retention( $keep ) {
		$keep = (int) $keep;
		if ( $keep < 1 ) {
			return array();
		}
		$scheduled = array_values( array_filter( $this->files(), function ( $f ) {
			return 'scheduled' === $f['type'];
		} ) );
		$deleted = array();
		foreach ( array_slice( $scheduled, $keep ) as $f ) {
			$this->delete( $f['id'] );
			$deleted[] = $f['name'];
		}
		return $deleted;
	}
}

class NLWM_Storages {

	/** @return NLWM_Storage[] id => storage */
	public static function all() {
		static $list = null;
		if ( null === $list ) {
			$list = array();
			foreach ( (array) apply_filters( 'nlwm_storages', array() ) as $s ) {
				if ( $s instanceof NLWM_Storage ) {
					$list[ $s->id() ] = $s;
				}
			}
		}
		return $list;
	}

	/** @return NLWM_Storage[] */
	public static function connected() {
		return array_filter( self::all(), function ( NLWM_Storage $s ) {
			return $s->connected();
		} );
	}

	/** @return NLWM_Storage */
	public static function get( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			throw new NLWM_Exception( esc_html( 'Unknown storage: ' . $id ) );
		}
		return $all[ $id ];
	}

	/** Storage ids a new backup should go to, from settings. */
	public static function default_targets() {
		$s = NLWM_Util::settings();
		if ( 'local' === $s['storage'] ) {
			return array();
		}
		return array_keys( self::connected() );
	}
}
