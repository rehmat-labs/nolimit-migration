<?php
/**
 * NoLimit archive format, version 2 (.nolimit).
 *
 *   file    := "NLWMARCH" version:u8 flags:u8 reserved[6] [salt[16] verifier[32]]  entry*  "NLWMEND\0"
 *              flags bit 0 = encrypted (then salt + verifier follow the header)
 *   entry   := "NE" method:u8 reserved:u8 path_len:u16 mtime:u32 orig_size:u64 stored_size:u64 path  data
 *   data    := raw bytes                           (method 0, stored)
 *            | chunk*                              (method 1, deflate)
 *   chunk   := stored_len:u32 raw_len:u32 bytes    (stored_len == raw_len means the chunk is raw)
 *            | stored_len:u32 raw_len:u32 iv[12] tag[16] AES-256-GCM( deflated:u8 data )   (method bit 2)
 *
 * Encryption: key = PBKDF2-SHA256(password, salt, 200000 rounds); verifier = HMAC-SHA256(key, "nolimit").
 * The manifest (nolimit.json) stays readable so a backup can be identified without the password.
 * This plugin only READS encrypted archives (restore works for everyone who has the password). Writing them
 * is done by an add-on that hooks a "sealer" into the 'nlwm_archive_sealer' filter (see sealer() below).
 *
 * All integers are big-endian. Every file is compressed in independent 1 MB chunks, so writing and
 * reading can pause after any chunk and resume in the next HTTP request. Headers are 26 bytes + path,
 * instead of 4377 bytes per file in the .wpress layout.
 *
 * Archives in the .wpress layout (All-in-One WP Migration) can be read, never written.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename

class NLWM_Archive {

	const MAGIC     = 'NLWMARCH';
	const END       = "NLWMEND\0";
	const VERSION   = 2;
	const FILE_HEAD = 16;
	const ENTRY_FIX = 26;
	const CHUNK     = 1048576;
	const STORE     = 0;
	const DEFLATE   = 1;
	const ENC       = 2;
	const ENC_HEAD  = 48;
	const KDF_ROUNDS = 200000;

	/* .wpress layout */
	const WP_HEADER = 4377;

	/** Already-compressed formats: deflate would only cost CPU. */
	const NO_COMPRESS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'nolimit', 'wpress', 'jar', 'apk' );

	protected $path;
	protected $fh;
	protected $format; // 'nolimit' | 'wpress'
	protected $encrypted = false;
	protected $salt;
	protected $verifier;
	protected $key;
	/** @var object|null Writes encrypted chunks: header() returns salt+verifier, seal( $plain ) returns iv.tag.ciphertext. */
	protected $sealer;

	/**
	 * @param string   $path
	 * @param string   $mode         'read' or 'write'
	 * @param int|null $truncate_to  write mode: cut the file to this size first (drops half-written data)
	 */
	public function __construct( $path, $mode = 'read', $truncate_to = null, $password = null ) {
		$this->path = $path;
		if ( 'write' === $mode ) {
			$this->fh = @fopen( $path, 'c+b' );
			if ( ! $this->fh ) {
				throw new NLWM_Exception( esc_html( sprintf( 'Cannot open archive for writing: %s', $path ) ) );
			}
			$this->format = 'nolimit';
			// Check the password BEFORE touching the file, so a wrong password can never damage it.
			if ( $this->size() > 0 && ( null === $truncate_to || $truncate_to > 0 ) ) {
				fseek( $this->fh, 0 );
				$this->read_file_head( (string) fread( $this->fh, self::FILE_HEAD + self::ENC_HEAD ) );
				if ( $this->encrypted ) {
					$this->unlock( (string) $password );
					$this->sealer = self::sealer( (string) $password, $this->salt );
				}
			}
			if ( null !== $truncate_to ) {
				ftruncate( $this->fh, $truncate_to );
			}
			if ( 0 === $this->size() ) {
				$enc = null !== $password && '' !== $password;
				$this->sealer = $enc ? self::sealer( (string) $password ) : null;
				$this->write( self::MAGIC . chr( self::VERSION ) . chr( $enc ? 1 : 0 ) . str_repeat( "\0", 6 ) );
				if ( $enc ) {
					$head = (string) $this->sealer->header();
					if ( self::ENC_HEAD !== strlen( $head ) ) {
						throw new NLWM_Exception( 'The encryption add-on returned a bad archive header.' );
					}
					$this->write( $head );
					$this->encrypted = true;
					$this->salt      = substr( $head, 0, 16 );
					$this->verifier  = substr( $head, 16, 32 );
				}
			}
			fseek( $this->fh, 0, SEEK_END );
			return;
		}

		$this->fh = @fopen( $path, 'rb' );
		if ( ! $this->fh ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Cannot open archive: %s', $path ) ) );
		}
		$head = (string) fread( $this->fh, self::FILE_HEAD + self::ENC_HEAD );
		if ( 0 === strpos( $head, self::MAGIC ) ) {
			$this->format = 'nolimit';
			$this->read_file_head( $head );
			if ( $this->encrypted && null !== $password && '' !== $password ) {
				$this->unlock( $password );
			}
		} else {
			$this->format = 'wpress';
		}
	}

	protected function read_file_head( $head ) {
		if ( strlen( $head ) < self::FILE_HEAD || 0 !== strpos( $head, self::MAGIC ) ) {
			throw new NLWM_Exception( 'This is not a NoLimit backup file.' );
		}
		if ( ord( $head[8] ) > self::VERSION ) {
			throw new NLWM_Exception( 'This backup was made by a newer version of NoLimit Migration. Update the plugin first.' );
		}
		if ( ord( $head[9] ) & 1 ) {
			if ( strlen( $head ) < self::FILE_HEAD + self::ENC_HEAD ) {
				throw new NLWM_Exception( 'Archive is truncated.' );
			}
			$this->encrypted = true;
			$this->salt      = substr( $head, self::FILE_HEAD, 16 );
			$this->verifier  = substr( $head, self::FILE_HEAD + 16, 32 );
		}
	}

	/**
	 * The object that encrypts new chunks, supplied by an add-on through the 'nlwm_archive_sealer' filter:
	 * apply_filters( 'nlwm_archive_sealer', null, $password, $salt ) where $salt is null for a new archive
	 * and the archive's salt when an interrupted encrypted backup continues.
	 */
	protected static function sealer( $password, $salt = null ) {
		$sealer = apply_filters( 'nlwm_archive_sealer', null, (string) $password, $salt );
		if ( ! is_object( $sealer ) || ! method_exists( $sealer, 'seal' ) || ! method_exists( $sealer, 'header' ) ) {
			throw new NLWM_Exception( 'Password-protected backups are made with the NoLimit Migration Pro add-on. Leave the password empty to make a normal backup.' );
		}
		return $sealer;
	}

	/** True when an add-on can make password-protected backups right now. */
	public static function can_seal() {
		return (bool) apply_filters( 'nlwm_can_seal', has_filter( 'nlwm_archive_sealer' ) );
	}

	protected static function require_crypto() {
		if ( ! function_exists( 'openssl_decrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			throw new NLWM_Exception( 'This server\'s PHP has no OpenSSL AES-256-GCM support, which encrypted backups need.' );
		}
	}

	protected static function derive( $password, $salt ) {
		return hash_pbkdf2( 'sha256', (string) $password, $salt, self::KDF_ROUNDS, 32, true );
	}

	public function is_encrypted() {
		return $this->encrypted;
	}

	/** Check the password of an encrypted archive and keep the key for reading. */
	public function unlock( $password ) {
		if ( ! $this->encrypted ) {
			return;
		}
		if ( '' === (string) $password ) {
			throw new NLWM_Exception( 'This backup is encrypted. Enter its password.' );
		}
		self::require_crypto();
		$key = self::derive( $password, $this->salt );
		if ( ! hash_equals( $this->verifier, hash_hmac( 'sha256', 'nolimit', $key, true ) ) ) {
			throw new NLWM_Exception( 'Wrong password for this encrypted backup.' );
		}
		$this->key = $key;
	}

	public function format() {
		return $this->format;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
	}

	public function size() {
		fflush( $this->fh );
		$stat = fstat( $this->fh );
		return (int) $stat['size'];
	}

	/** Position of the first entry. */
	public function first() {
		if ( 'nolimit' !== $this->format ) {
			return 0;
		}
		return self::FILE_HEAD + ( $this->encrypted ? self::ENC_HEAD : 0 );
	}

	/* ================================================================ writing */

	public static function should_compress( $rel ) {
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		return ! in_array( $ext, self::NO_COMPRESS, true ) && function_exists( 'gzdeflate' );
	}

	protected function write_header( $rel, $method, $mtime, $orig ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		fseek( $this->fh, 0, SEEK_END );
		$pos = ftell( $this->fh );
		$this->write( 'NE' . pack( 'CCnNJJ', $method, 0, strlen( $rel ), $mtime, $orig, 0 ) . $rel );
		return $pos;
	}

	/** Write the final sizes into an entry header (they are only known after the data is written). */
	protected function patch_sizes( $header_pos, $orig, $stored ) {
		fseek( $this->fh, $header_pos + 10 );
		$this->write( pack( 'JJ', $orig, $stored ) );
		fseek( $this->fh, 0, SEEK_END );
	}

	/**
	 * Append (or continue appending) one file.
	 *
	 * @param string $src      absolute source path
	 * @param string $rel      path inside the archive, forward slashes
	 * @param array  $state    resume state, by reference: header_pos, offset (source bytes read), stored, method
	 * @param float  $deadline stop after this microtime(true)
	 * @return bool true when the file is completely written
	 */
	public function add_file( $src, $rel, array &$state, $deadline ) {
		$in = @fopen( $src, 'rb' );
		if ( ! $in ) {
			$state = array(); // vanished or unreadable since it was listed: skip it
			return true;
		}
		if ( strlen( $rel ) > 65535 ) {
			fclose( $in );
			$state = array();
			return true;
		}

		if ( ! isset( $state['header_pos'] ) ) {
			$stat  = fstat( $in );
			$state = array(
				'method' => ( self::should_compress( $rel ) ? self::DEFLATE : self::STORE ) | ( $this->sealer ? self::ENC : 0 ),
				'offset' => 0,
				'stored' => 0,
			);
			$state['header_pos'] = $this->write_header( $rel, $state['method'], (int) $stat['mtime'], (int) $stat['size'] );
		}

		$data_pos = $state['header_pos'] + self::ENTRY_FIX + strlen( ltrim( str_replace( '\\', '/', $rel ), '/' ) );
		fseek( $in, $state['offset'] );
		fseek( $this->fh, $data_pos + $state['stored'] );

		$done = false;
		while ( true ) {
			$buf = fread( $in, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				$done = true;
				break;
			}
			if ( $state['method'] & self::ENC ) {
				$z    = ( $state['method'] & self::DEFLATE ) ? gzdeflate( $buf, 6 ) : false;
				$flag = ( false !== $z && strlen( $z ) < strlen( $buf ) ) ? 1 : 0;
				$payload = $this->sealer->seal( chr( $flag ) . ( $flag ? $z : $buf ) );
				if ( ! is_string( $payload ) || strlen( $payload ) < 29 ) {
					throw new NLWM_Exception( 'Encryption failed.' );
				}
				$out     = pack( 'NN', strlen( $payload ), strlen( $buf ) ) . $payload;
			} elseif ( self::DEFLATE === $state['method'] ) {
				$z = gzdeflate( $buf, 6 );
				if ( false === $z || strlen( $z ) >= strlen( $buf ) ) {
					$z = $buf; // incompressible chunk: keep raw
				}
				$out = pack( 'NN', strlen( $z ), strlen( $buf ) ) . $z;
			} else {
				$out = $buf;
			}
			$this->write( $out );
			$state['offset'] += strlen( $buf );
			$state['stored'] += strlen( $out );
			if ( strlen( $buf ) < self::CHUNK && feof( $in ) ) {
				$done = true;
				break;
			}
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fclose( $in );

		if ( $done ) {
			$this->patch_sizes( $state['header_pos'], $state['offset'], $state['stored'] );
			$state = array();
		}
		return $done;
	}

	/** Add a small in-memory file in one go (stored, so it can be read without inflating). */
	public function add_string( $rel, $content ) {
		$pos = $this->write_header( $rel, self::STORE, time(), strlen( $content ) );
		$this->write( $content );
		$this->patch_sizes( $pos, strlen( $content ), strlen( $content ) );
	}

	public function finish() {
		fseek( $this->fh, 0, SEEK_END );
		$this->write( self::END );
		fflush( $this->fh );
	}

	protected function write( $data ) {
		$len = strlen( $data );
		$out = @fwrite( $this->fh, $data );
		if ( $out !== $len ) {
			throw new NLWM_Exception( 'Could not write to the archive. The disk may be full.' );
		}
	}

	/* ================================================================ reading */

	/** True when the archive was written to the end (has its end marker). */
	public function is_complete() {
		$size = $this->size();
		if ( 'nolimit' === $this->format ) {
			if ( $size < self::FILE_HEAD + strlen( self::END ) ) {
				return false;
			}
			fseek( $this->fh, $size - strlen( self::END ) );
			return fread( $this->fh, strlen( self::END ) ) === self::END;
		}
		if ( $size < self::WP_HEADER ) {
			return false;
		}
		fseek( $this->fh, $size - self::WP_HEADER );
		$block = (string) fread( $this->fh, self::WP_HEADER );
		if ( $block === str_repeat( "\0", self::WP_HEADER ) ) {
			return true; // older .wpress
		}
		// Newer .wpress (7.x): empty name, size field = archive size before this block, CRC32 at the end.
		if ( ! self::is_wpress_end( $block ) ) {
			return false;
		}
		$recorded = rtrim( substr( $block, 255, 14 ), "\0" );
		return ctype_digit( $recorded ) && (int) $recorded === $size - self::WP_HEADER;
	}

	/** End block of a .wpress archive: the file-name field is empty. */
	protected static function is_wpress_end( $block ) {
		return substr( $block, 0, 255 ) === str_repeat( "\0", 255 );
	}

	/**
	 * Entry at $pos, or null at the end of the archive.
	 * Keys: rel, name, size (original), stored, method, mtime, data_pos, next.
	 */
	public function entry_at( $pos ) {
		if ( fseek( $this->fh, $pos ) === -1 ) {
			throw new NLWM_Exception( 'Archive is damaged (cannot seek).' );
		}
		return 'nolimit' === $this->format ? $this->entry_nolimit( $pos ) : $this->entry_wpress( $pos );
	}

	protected function entry_nolimit( $pos ) {
		$fix = fread( $this->fh, self::ENTRY_FIX );
		if ( false === $fix || strlen( $fix ) < 8 ) {
			throw new NLWM_Exception( 'Archive is truncated. Upload or download it again.' );
		}
		if ( 0 === strpos( $fix, self::END ) ) {
			return null;
		}
		if ( 'NE' !== substr( $fix, 0, 2 ) || strlen( $fix ) < self::ENTRY_FIX ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Archive is damaged near byte %d.', $pos ) ) );
		}
		$h   = unpack( 'Cmethod/Creserved/nlen/Nmtime/Jorig/Jstored', substr( $fix, 2 ) );
		$rel = $h['len'] ? fread( $this->fh, $h['len'] ) : '';
		if ( strlen( $rel ) !== $h['len'] || '' === $rel || $h['method'] > ( self::DEFLATE | self::ENC ) ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Archive is damaged near byte %d.', $pos ) ) );
		}
		$data = $pos + self::ENTRY_FIX + $h['len'];
		return array(
			'rel'      => $rel,
			'name'     => basename( $rel ),
			'size'     => $h['orig'],
			'stored'   => $h['stored'],
			'method'   => $h['method'],
			'mtime'    => $h['mtime'],
			'data_pos' => $data,
			'next'     => $data + $h['stored'],
		);
	}

	protected function entry_wpress( $pos ) {
		$raw = fread( $this->fh, self::WP_HEADER );
		if ( false === $raw || strlen( $raw ) < self::WP_HEADER ) {
			throw new NLWM_Exception( 'Archive is truncated. Upload or download it again.' );
		}
		if ( self::is_wpress_end( $raw ) ) {
			return null;
		}
		$h    = unpack( 'a255name/a14size/a12mtime/a4096path', $raw );
		// Each field ends at its first NUL. Newer .wpress files keep a CRC32 after the NULs of the path field.
		$cut  = function ( $v ) {
			$p = strpos( $v, "\0" );
			return false === $p ? $v : substr( $v, 0, $p );
		};
		$name = $cut( substr( $raw, 0, 255 ) );
		$path = str_replace( '\\', '/', $cut( substr( $raw, 281 ) ) );
		$size = trim( $cut( substr( $raw, 255, 14 ) ) );
		$h['mtime'] = $cut( substr( $raw, 269, 12 ) );
		if ( '' === $name || ! ctype_digit( $size ) ) {
			throw new NLWM_Exception( esc_html( sprintf( 'This is not a valid backup file (damaged near byte %d).', $pos ) ) );
		}
		$rel = ( '' === $path || '.' === $path ) ? $name : trim( $path, '/' ) . '/' . $name;
		return array(
			'rel'      => $rel,
			'name'     => $name,
			'size'     => (int) $size,
			'stored'   => (int) $size,
			'method'   => self::STORE,
			'mtime'    => (int) rtrim( $h['mtime'], "\0" ),
			'data_pos' => $pos + self::WP_HEADER,
			'next'     => $pos + self::WP_HEADER + (int) $size,
		);
	}

	/** Read a small entry fully into memory. */
	public function read_entry( array $entry, $max = 52428800 ) {
		if ( $entry['size'] > $max ) {
			throw new NLWM_Exception( esc_html( sprintf( '%s is too large to read in memory.', $entry['rel'] ) ) );
		}
		$out   = '';
		$state = array();
		fseek( $this->fh, $entry['data_pos'] );
		if ( self::STORE === (int) $entry['method'] ) {
			return $entry['stored'] ? (string) fread( $this->fh, $entry['stored'] ) : '';
		}
		$in = 0;
		while ( $in < $entry['stored'] ) {
			list( $chunk, $len ) = $this->read_chunk( $entry, $in );
			$out .= $chunk;
			$in  += $len;
		}
		return $out;
	}

	/** Read one deflate chunk at data offset $in. Returns [raw bytes, bytes consumed]. */
	protected function read_chunk( array $entry, $in ) {
		fseek( $this->fh, $entry['data_pos'] + $in );
		$head = fread( $this->fh, 8 );
		if ( strlen( $head ) < 8 ) {
			throw new NLWM_Exception( esc_html( 'Archive ended unexpectedly in ' . $entry['rel'] ) );
		}
		$h = unpack( 'Nstored/Nraw', $head );
		if ( $h['stored'] > 2 * self::CHUNK + 64 || $h['raw'] > self::CHUNK || $in + 8 + $h['stored'] > $entry['stored'] ) {
			throw new NLWM_Exception( esc_html( 'Archive is damaged in ' . $entry['rel'] ) );
		}
		$data = $h['stored'] ? fread( $this->fh, $h['stored'] ) : '';
		if ( strlen( $data ) !== $h['stored'] ) {
			throw new NLWM_Exception( esc_html( 'Archive ended unexpectedly in ' . $entry['rel'] ) );
		}
		if ( $entry['method'] & self::ENC ) {
			if ( ! $this->key ) {
				throw new NLWM_Exception( 'This backup is encrypted. Enter its password.' );
			}
			$plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ) );
			if ( false === $plain || '' === $plain ) {
				throw new NLWM_Exception( esc_html( 'Wrong password, or the backup is damaged, in ' . $entry['rel'] ) );
			}
			$data = substr( $plain, 1 );
			if ( '1' === (string) ord( $plain[0] ) ) {
				$data = @gzinflate( $data );
			}
			if ( false === $data || strlen( $data ) !== $h['raw'] ) {
				throw new NLWM_Exception( esc_html( 'Archive is damaged (bad data) in ' . $entry['rel'] ) );
			}
		} elseif ( $h['stored'] !== $h['raw'] ) {
			$data = @gzinflate( $data );
			if ( false === $data || strlen( $data ) !== $h['raw'] ) {
				throw new NLWM_Exception( esc_html( 'Archive is damaged (bad compressed data) in ' . $entry['rel'] ) );
			}
		}
		return array( $data, 8 + $h['stored'] );
	}

	/**
	 * Copy an entry to $dest, resuming where $state says.
	 *
	 * @param array $state by reference: in (archive bytes consumed), out (bytes written to $dest)
	 * @return bool true when complete
	 */
	public function extract_entry( array $entry, $dest, array &$state, $deadline ) {
		$state += array( 'in' => 0, 'out' => 0 );
		$dir    = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Cannot create folder %s.', $dir ) ) );
		}
		$out = @fopen( $dest, 0 === $state['out'] ? 'wb' : 'cb' );
		if ( ! $out ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Cannot write %s. Check file permissions.', $dest ) ) );
		}
		ftruncate( $out, $state['out'] );
		fseek( $out, $state['out'] );

		$done = false;
		try {
			while ( true ) {
				if ( $state['in'] >= $entry['stored'] ) {
					$done = true;
					break;
				}
				if ( self::STORE === (int) $entry['method'] ) {
					fseek( $this->fh, $entry['data_pos'] + $state['in'] );
					$buf = fread( $this->fh, (int) min( self::CHUNK, $entry['stored'] - $state['in'] ) );
					if ( false === $buf || '' === $buf ) {
						throw new NLWM_Exception( esc_html( 'Archive ended unexpectedly while extracting ' . $entry['rel'] ) );
					}
					$used = strlen( $buf );
				} else {
					list( $buf, $used ) = $this->read_chunk( $entry, $state['in'] );
				}
				if ( '' !== $buf && fwrite( $out, $buf ) !== strlen( $buf ) ) {
					throw new NLWM_Exception( esc_html( 'Could not write ' . $dest . '. The disk may be full.' ) );
				}
				$state['in']  += $used;
				$state['out'] += strlen( $buf );
				if ( $state['in'] < $entry['stored'] && microtime( true ) > $deadline ) {
					break;
				}
			}
		} finally {
			fclose( $out );
		}
		if ( $done && $entry['mtime'] > 0 ) {
			@touch( $dest, $entry['mtime'] );
		}
		return $done;
	}
}
