<?php
/**
 * Backup files on disk plus a small index (labels and details) kept in the nlwm_backups_index option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NLWM_Backups {

	const OPTION = 'nlwm_backups_index';

	public static function index() {
		$data = get_option( self::OPTION, null );
		if ( null === $data ) {
			$data = self::import_old_index();
		}
		return is_array( $data ) ? $data : array();
	}

	protected static function write_index( array $data ) {
		update_option( self::OPTION, $data, false );
	}

	/** Early builds kept the index in a file in the backup folder: move it to the option once and delete the file. */
	protected static function import_old_index() {
		$data = array();
		$file = NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . 'backups-index.php';
		if ( file_exists( $file ) ) {
			$raw  = (string) file_get_contents( $file );
			$json = json_decode( (string) substr( $raw, (int) strpos( $raw, '{' ) ), true );
			$data = is_array( $json ) ? $json : array();
			wp_delete_file( $file );
		}
		update_option( self::OPTION, $data, false );
		return $data;
	}

	public static function set_meta( $name, array $meta ) {
		$data          = self::index();
		$data[ $name ] = array_merge( isset( $data[ $name ] ) ? $data[ $name ] : array(), $meta );
		self::write_index( $data );
	}

	/** All finished backups, newest first. */
	public static function all() {
		$dir   = NLWM_Util::backups_dir();
		$index = self::index();
		$out   = array();
		foreach ( (array) @scandir( $dir ) as $name ) {
			if ( ! NLWM_Util::valid_backup_name( $name ) ) {
				continue;
			}
			$path  = $dir . DIRECTORY_SEPARATOR . $name;
			$meta  = isset( $index[ $name ] ) ? $index[ $name ] : array();
			$out[] = array_merge(
				array(
					'name'    => $name,
					'label'   => '',
					'type'    => 'wpress' === pathinfo( $name, PATHINFO_EXTENSION ) ? 'upload' : 'manual',
					'created' => filemtime( $path ),
				),
				$meta,
				array(
					'name' => $name,
					'size' => filesize( $path ),
				)
			);
		}
		usort( $out, function ( $a, $b ) {
			return $b['created'] - $a['created'];
		} );
		return $out;
	}

	public static function delete( $name ) {
		$path = NLWM_Util::backup_path( $name );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
			clearstatcache( true, $path );
			if ( file_exists( $path ) ) {
				throw new NLWM_Exception( 'Could not delete the backup file.' );
			}
		}
		$data = self::index();
		unset( $data[ $name ] );
		self::write_index( $data );
	}

	/**
	 * Keep only the newest $keep backups of the given type.
	 */
	public static function apply_retention( $keep, $type = 'scheduled' ) {
		$keep = (int) $keep;
		if ( $keep < 1 ) {
			return array();
		}
		$deleted = array();
		$list    = array_values( array_filter( self::all(), function ( $b ) use ( $type ) {
			return $b['type'] === $type && empty( $b['locked'] );
		} ) );
		foreach ( array_slice( $list, $keep ) as $b ) {
			self::delete( $b['name'] );
			$deleted[] = $b['name'];
		}
		return $deleted;
	}

	/** Remove upload/download leftovers older than a day. */
	public static function gc_parts() {
		foreach ( (array) glob( NLWM_Util::backups_dir() . DIRECTORY_SEPARATOR . '*.part' ) as $p ) {
			if ( filemtime( $p ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $p );
			}
		}
	}
}
