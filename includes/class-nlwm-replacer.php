<?php
/**
 * Search-and-replace that is safe for serialized PHP, JSON-escaped and URL-encoded values.
 *
 * Serialized strings are rewritten by walking the serialized grammar with the original byte lengths,
 * so string lengths are always recomputed exactly and nothing is ever unserialize()d (no object
 * injection, no "incomplete class" problems). Nested and double-serialized values are handled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NLWM_Replacer {

	/** @var array old => new, ready for strtr() */
	protected $pairs = array();

	/** @var string[] SQL-escaped search strings for a fast pre-check */
	protected $needles = array();

	public function __construct( array $pairs ) {
		foreach ( $pairs as $old => $new ) {
			$old = (string) $old;
			if ( '' !== $old && $old !== (string) $new ) {
				$this->pairs[ $old ] = (string) $new;
			}
		}
		foreach ( array_keys( $this->pairs ) as $old ) {
			$this->needles[] = self::sql_escape( $old );
		}
	}

	public function is_empty() {
		return empty( $this->pairs );
	}

	public function pairs() {
		return $this->pairs;
	}

	/**
	 * Build the full replacement table for a URL change, including the variants that WordPress,
	 * page builders and JSON storage use for the same URL.
	 *
	 * @param array $url_pairs  old URL => new URL (no trailing slash)
	 * @param array $path_pairs old filesystem path => new path
	 * @param array $extra      user supplied old => new
	 */
	public static function build_pairs( array $url_pairs, array $path_pairs = array(), array $extra = array() ) {
		$out = array();
		$add = function ( $old, $new ) use ( &$out ) {
			if ( '' === $old || $old === $new || isset( $out[ $old ] ) ) {
				return;
			}
			$out[ $old ] = $new;
		};

		foreach ( $extra as $old => $new ) {
			$add( (string) $old, (string) $new );
		}

		foreach ( $url_pairs as $old => $new ) {
			$old = untrailingslashit( $old );
			$new = untrailingslashit( $new );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$host_part = preg_replace( '#^https?://#i', '', $old );
			$variants  = array( 'http://' . $host_part, 'https://' . $host_part );
			foreach ( $variants as $v ) {
				$add( $v, $new );
				$add( str_replace( '/', '\\/', $v ), str_replace( '/', '\\/', $new ) );          // JSON
				$add( rawurlencode( $v ), rawurlencode( $new ) );                                // url-encoded
				$add( str_replace( '/', '\\\\/', $v ), str_replace( '/', '\\\\/', $new ) );    // double-escaped JSON
			}
			// Protocol-relative links: //old.com/... -> //new.com/...
			$new_host_part = preg_replace( '#^https?://#i', '', $new );
			$add( '//' . $host_part, '//' . $new_host_part );
			$add( '\\/\\/' . str_replace( '/', '\\/', $host_part ), '\\/\\/' . str_replace( '/', '\\/', $new_host_part ) );
		}

		foreach ( $path_pairs as $old => $new ) {
			$old = untrailingslashit( str_replace( '\\', '/', $old ) );
			$new = untrailingslashit( str_replace( '\\', '/', $new ) );
			if ( strlen( $old ) < 2 || $old === $new ) {
				continue;
			}
			// With a trailing slash a short path like "/home" cannot match inside "/homepage".
			$add( $old . '/', $new . '/' );
			$add( str_replace( '/', '\\/', $old . '/' ), str_replace( '/', '\\/', $new . '/' ) );
			// The bare path too, but only when it is specific enough (3+ folders deep).
			if ( substr_count( trim( $old, '/' ), '/' ) >= 2 ) {
				$add( $old, $new );
				$add( str_replace( '/', '\\/', $old ), str_replace( '/', '\\/', $new ) );
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------- values */

	/** Replace inside a plain PHP value (unescaped). */
	public function value( $str ) {
		if ( '' === $str || ! $this->contains_any( $str ) ) {
			return $str;
		}
		if ( self::looks_serialized( $str ) ) {
			$pos = 0;
			try {
				$out = $this->walk( $str, $pos );
				if ( strlen( $str ) === $pos ) {
					return $out;
				}
			} catch ( Exception $e ) {
				// Not valid serialized data after all: fall through to a plain replace.
			}
		}
		return strtr( $str, $this->pairs );
	}

	protected function contains_any( $str ) {
		foreach ( $this->pairs as $old => $new ) {
			if ( false !== strpos( $str, (string) $old ) ) {
				return true;
			}
		}
		return false;
	}

	public static function looks_serialized( $str ) {
		$str = trim( $str );
		if ( strlen( $str ) < 4 || ':' !== $str[1] ) {
			return 'N;' === $str;
		}
		$last = substr( $str, -1 );
		return strpos( 'asOCibdE', $str[0] ) !== false && ( ';' === $last || '}' === $last );
	}

	/**
	 * Walk one serialized value starting at $pos, returning the rewritten value.
	 * Throws on anything that is not well-formed.
	 */
	protected function walk( $s, &$pos, $replace = true ) {
		$type = isset( $s[ $pos ] ) ? $s[ $pos ] : '';
		switch ( $type ) {
			case 'N':
				$this->expect( $s, $pos, 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end || ':' !== $s[ $pos + 1 ] ) {
					throw new Exception( 'bad scalar' );
				}
				$out = substr( $s, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;

			case 's':
				$len = $this->read_len( $s, $pos, 's:' );
				$this->expect( $s, $pos, '"' );
				if ( $pos + $len + 2 > strlen( $s ) ) {
					throw new Exception( 'short string' );
				}
				$str  = substr( $s, $pos, $len );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				if ( $replace ) {
					$str = $this->value( $str ); // handles strings that are themselves serialized
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';

			case 'E': // PHP 8.1 enum: E:len:"Class:Case";
				$start = $pos;
				$len   = $this->read_len( $s, $pos, 'E:' );
				$this->expect( $s, $pos, '"' );
				$pos += $len;
				$this->expect( $s, $pos, '";' );
				return substr( $s, $start, $pos - $start );

			case 'a':
				$count = $this->read_len( $s, $pos, 'a:' );
				$this->expect( $s, $pos, '{' );
				$out = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= $this->walk( $s, $pos, false ); // keys stay as they are
					$out .= $this->walk( $s, $pos, true );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'O':
				$start   = $pos;
				$cls_len = $this->read_len( $s, $pos, 'O:' );
				$this->expect( $s, $pos, '"' );
				$pos += $cls_len;
				$this->expect( $s, $pos, '":' );
				$head  = substr( $s, $start, $pos - $start );
				$count = $this->read_len( $s, $pos, '' );
				$this->expect( $s, $pos, '{' );
				$out = $head . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= $this->walk( $s, $pos, false );
					$out .= $this->walk( $s, $pos, true );
				}
				$this->expect( $s, $pos, '}' );
				return $out . '}';

			case 'C': // custom serializer: payload is opaque, copy verbatim
				$start   = $pos;
				$cls_len = $this->read_len( $s, $pos, 'C:' );
				$this->expect( $s, $pos, '"' );
				$pos += $cls_len;
				$this->expect( $s, $pos, '":' );
				$data_len = $this->read_len( $s, $pos, '' );
				$this->expect( $s, $pos, '{' );
				$pos += $data_len;
				$this->expect( $s, $pos, '}' );
				return substr( $s, $start, $pos - $start );
		}
		throw new Exception( 'unknown token' );
	}

	protected function expect( $s, &$pos, $token ) {
		if ( substr( $s, $pos, strlen( $token ) ) !== $token ) {
			throw new Exception( esc_html( 'expected ' . $token ) );
		}
		$pos += strlen( $token );
	}

	/** Reads "<prefix><digits>:" and returns the number. */
	protected function read_len( $s, &$pos, $prefix ) {
		if ( '' !== $prefix ) {
			$this->expect( $s, $pos, $prefix );
		}
		$colon = strpos( $s, ':', $pos );
		if ( false === $colon ) {
			throw new Exception( 'no length' );
		}
		$num = substr( $s, $pos, $colon - $pos );
		if ( '' === $num || ! ctype_digit( $num ) ) {
			throw new Exception( 'bad length' );
		}
		$pos = $colon + 1;
		return (int) $num;
	}

	/* -------------------------------------------------------------- SQL */

	public static function sql_escape( $str ) {
		return strtr( $str, array(
			'\\'   => '\\\\',
			"\0"   => '\\0',
			"\n"   => '\\n',
			"\r"   => '\\r',
			"'"    => "\\'",
			'"'    => '\\"',
			"\x1a" => '\\Z',
		) );
	}

	public static function sql_unescape( $str ) {
		if ( false === strpos( $str, '\\' ) ) {
			return $str;
		}
		return preg_replace_callback( '/\\\\(.)/s', function ( $m ) {
			switch ( $m[1] ) {
				case '0':
					return "\0";
				case 'n':
					return "\n";
				case 'r':
					return "\r";
				case 'Z':
					return "\x1a";
				case 't':
					return "\t";
				case 'b':
					return "\x08";
				default:
					return $m[1];
			}
		}, $str );
	}

	/**
	 * Replace inside every quoted string literal of one SQL statement.
	 * Statements that cannot contain a search string are returned untouched (fast path).
	 */
	public function sql( $statement ) {
		if ( empty( $this->pairs ) ) {
			return $statement;
		}
		$hit = false;
		foreach ( $this->needles as $needle ) {
			if ( false !== strpos( $statement, $needle ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return $statement;
		}
		$self   = $this;
		$result = preg_replace_callback(
			"/'((?:[^'\\\\]++|\\\\.|'')*+)'/s",
			function ( $m ) use ( $self ) {
				$raw = $m[1];
				$hit = false;
				foreach ( $self->needles as $needle ) {
					if ( false !== strpos( $raw, $needle ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					return $m[0];
				}
				$plain = NLWM_Replacer::sql_unescape( str_replace( "''", "\\'", $raw ) );
				return "'" . NLWM_Replacer::sql_escape( $self->value( $plain ) ) . "'";
			},
			$statement
		);
		return null === $result ? $statement : $result;
	}
}
