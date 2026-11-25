<?php
/**
 * Database dump and restore.
 *
 * Dump: one statement per line, table prefix replaced by a placeholder, explicit column lists
 * (generated columns left out), rows streamed unbuffered and paged by primary key (keyset pagination
 * when possible), binary columns written as hex.
 *
 * Restore: only CREATE/DROP/INSERT statements aimed at temporary tables (nlwmtmp_*) are executed.
 * Only after the whole dump went in are the live tables swapped out in one atomic RENAME TABLE, so a
 * failed or cancelled import never leaves the live database half-overwritten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Backups can be many gigabytes: they are streamed in small resumable chunks with fopen/fread/fwrite and finished with an atomic rename. WP_Filesystem has no streaming API and may need FTP credentials, which a background job cannot ask for. Only files inside the plugin's own backup folder are touched.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.WP.AlternativeFunctions.rename_rename
// All queries go through $wpdb. Values are passed with $wpdb->prepare(). Table and column names cannot be placeholders
// (no %i before WordPress 6.2): they are read from SHOW TABLES / SHOW COLUMNS of this site's own prefix and wrapped
// in backticks. Restoring a backup by design runs the CREATE/INSERT statements stored in the backup file, rewritten so
// they only touch the plugin's temporary nlwmtmp_* tables (see statement_kind()). Backups and restores must read the
// live tables, so results are not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class NLWM_Database {

	const TMP_PREFIX = 'nlwmtmp_';
	const OLD_PREFIX = 'nlwmold_';
	const ROWS       = 1000; // rows per page at most
	const PAGE_BYTES = 8000000; // aim for about 8 MB per page, so tables with big rows use smaller pages
	const MAX_STMT   = 1000000; // bytes per multi-row INSERT

	/** Tables whose rows must all arrive, or the restore stops before the switch. */
	const CORE = array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links' );

	/** @var wpdb */
	protected $db;
	protected $prefix;
	protected $session_ready = false;

	public function __construct() {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			throw new NLWM_Exception( 'NoLimit Migration needs the WordPress database connection.' );
		}
		$this->db     = $wpdb;
		$this->prefix = $wpdb->base_prefix;
	}

	/** Session settings for dump/restore. Not applied for read-only use (admin screen). */
	protected function session() {
		if ( ! $this->session_ready ) {
			$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
			$this->query( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
			$this->query( 'SET NAMES utf8mb4' );
			$this->session_ready = true;
		}
	}

	public function prefix() {
		return $this->prefix;
	}

	/**
	 * Run a statement through $wpdb. Returns false on error (see error()).
	 *
	 * wpdb's check for invalid text is switched off for this one query: it would re-read and strip every non-ASCII
	 * byte of multi-megabyte INSERTs from the backup (slow, and it damages binary and emoji data).
	 */
	public function query( $sql ) {
		$this->db->check_current_query = false;
		$suppress                      = $this->db->suppress_errors( true );
		$r                             = $this->db->query( $sql );
		$this->db->suppress_errors( $suppress );
		if ( ! empty( $this->db->queries ) ) {
			$this->db->queries = array(); // SAVEQUERIES would keep every multi-megabyte INSERT in memory
		}
		return false !== $r;
	}

	/** Rows of a SELECT/SHOW as numeric (default) or associative arrays. */
	public function rows( $sql, $assoc = false ) {
		$suppress = $this->db->suppress_errors( true );
		$rows     = $this->db->get_results( $sql, $assoc ? ARRAY_A : ARRAY_N );
		$this->db->suppress_errors( $suppress );
		$this->db->flush(); // don't keep a second copy of a big page in wpdb
		return is_array( $rows ) ? $rows : array();
	}

	public function one( $sql ) {
		$suppress = $this->db->suppress_errors( true );
		$v        = $this->db->get_var( $sql );
		$this->db->suppress_errors( $suppress );
		return $v;
	}

	public function error() {
		return (string) $this->db->last_error;
	}

	/** $wpdb->prepare(), for callers that build queries with this class. */
	public function prepare( $sql, ...$args ) {
		return $this->db->prepare( $sql, ...$args );
	}

	public function version() {
		return (string) $this->one( 'SELECT VERSION()' );
	}

	/** All base tables whose name starts with $prefix. */
	public function tables_like( $prefix ) {
		$tables = array();
		foreach ( $this->rows( $this->db->prepare( 'SHOW FULL TABLES LIKE %s', $this->db->esc_like( $prefix ) . '%' ) ) as $row ) {
			if ( isset( $row[1] ) && 'BASE TABLE' === strtoupper( $row[1] ) ) {
				$tables[] = $row[0];
			}
		}
		return $tables;
	}

	/**
	 * Tables of this WordPress install. When another install shares the database with a longer prefix
	 * that starts with ours (wp_ and wp_shop_), its tables are left out.
	 */
	public function tables( $prefix = null ) {
		$prefix = null === $prefix ? $this->prefix : $prefix;
		$all    = $this->tables_like( $prefix );
		$others = array();
		foreach ( $all as $t ) {
			if ( preg_match( '/^(.+)options$/', $t, $m ) && $m[1] !== $prefix && 0 === strpos( $m[1], $prefix ) && in_array( $m[1] . 'posts', $all, true ) ) {
				$others[] = $m[1];
			}
		}
		if ( ! $others ) {
			return $all;
		}
		return array_values( array_filter( $all, function ( $t ) use ( $others ) {
			foreach ( $others as $o ) {
				if ( 0 === strpos( $t, $o ) ) {
					return false;
				}
			}
			return true;
		} ) );
	}

	/** Tables with their sizes, for the export screen. */
	public function table_stats() {
		$out  = array();
		$mine = array_flip( $this->tables() );
		foreach ( $this->rows( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $this->db->esc_like( $this->prefix ) . '%' ), true ) as $row ) {
			if ( ! empty( $row['Engine'] ) && isset( $mine[ $row['Name'] ] ) ) {
				$out[ $row['Name'] ] = (int) $row['Data_length'] + (int) $row['Index_length'];
			}
		}
		return $out;
	}

	/**
	 * Rough size of the data a dump will contain, in bytes (from table statistics, so it is instant).
	 * Indexes are not dumped, so only the data length counts. Excluded tables are left out.
	 */
	public function data_size( array $exclude = array() ) {
		$tables = array_flip( array_diff( $this->tables(), $exclude ) );
		$bytes  = 0;
		foreach ( $this->rows( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $this->db->esc_like( $this->prefix ) . '%' ), true ) as $t ) {
			if ( isset( $t['Name'], $tables[ $t['Name'] ] ) ) {
				$bytes += (float) $t['Data_length'];
			}
		}
		return $bytes;
	}

	/* ================================================================ DUMP */

	/**
	 * Continue dumping into $file.
	 *
	 * @param array $state  resume state (by reference)
	 * @param array $opts   exclude_tables, no_spam, no_revisions, no_transients, no_wc_logs
	 * @return bool true when every table is done
	 */
	public function dump( $file, array &$state, array $opts, $deadline ) {
		$this->session();
		$state += array( 'tables' => null, 'table_index' => 0, 'last' => null, 'offset' => 0, 'rows' => 0, 'bytes' => 0 );

		if ( null === $state['tables'] ) {
			$exclude         = isset( $opts['exclude_tables'] ) ? (array) $opts['exclude_tables'] : array();
			$state['tables'] = array_values( array_diff( $this->tables(), $exclude ) );
		}

		$fh = fopen( $file, 'c+b' );
		if ( ! $fh ) {
			throw new NLWM_Exception( 'Cannot write the database dump file.' );
		}
		// Drop anything a crashed request wrote after the last saved state.
		ftruncate( $fh, $state['bytes'] );
		fseek( $fh, 0, SEEK_END );
		if ( 0 === $state['bytes'] ) {
			$this->put( $fh, "-- NoLimit Migration database dump\n-- Prefix placeholder: " . NLWM_PREFIX_PLACEHOLDER . "\n" );
		}

		while ( $state['table_index'] < count( $state['tables'] ) ) {
			$table = $state['tables'][ $state['table_index'] ];
			$alias = NLWM_PREFIX_PLACEHOLDER . substr( $table, strlen( $this->prefix ) );
			$info  = $this->table_info( $table );

			if ( null === $state['last'] && 0 === $state['offset'] ) {
				$create = $this->create_statement( $table, $alias );
				if ( null === $create || ! $info['cols'] ) {
					$state['table_index']++;
					continue;
				}
				$this->put( $fh, "\nDROP TABLE IF EXISTS `{$alias}`;\n{$create};\n" );
				$state['last'] = false; // structure written
			}

			$where  = $this->where_for( $table, $opts );
			$select = '`' . implode( '`,`', $info['cols'] ) . '`';
			$limit  = $info['page'];

			if ( $info['int_pk'] ) {
				// Keyset pagination on a single integer primary key (fast at any table size).
				$pk = '`' . $info['pk'][0] . '`';
				if ( false !== $state['last'] && null !== $state['last'] ) {
					$sql = $this->db->prepare( "SELECT {$select} FROM `{$table}` WHERE {$where} AND {$pk} > %s ORDER BY {$pk} LIMIT %d", (string) $state['last'], $limit );
				} else {
					$sql = $this->db->prepare( "SELECT {$select} FROM `{$table}` WHERE {$where} ORDER BY {$pk} LIMIT %d", $limit );
				}
			} else {
				$order = $info['pk'] ? ' ORDER BY `' . implode( '`,`', $info['pk'] ) . '`' : '';
				$sql   = $this->db->prepare( "SELECT {$select} FROM `{$table}` WHERE {$where}{$order} LIMIT %d, %d", (int) $state['offset'], $limit );
			}

			// One page at a time; tables with big rows (page-builder data) get smaller pages (see table_info()).
			$this->db->last_error = '';
			$page                 = $this->rows( $sql );
			if ( ! $page && '' !== $this->error() ) {
				fclose( $fh );
				throw new NLWM_Exception( esc_html( sprintf( 'Could not read table %s: %s', $table, $this->error() ) ) );
			}

			$count  = 0;
			$values = array();
			$bytes  = 0;
			$head   = "INSERT INTO `{$alias}` ({$select}) VALUES ";
			foreach ( $page as $row ) {
				$count++;
				$items = array();
				foreach ( $row as $i => $v ) {
					if ( null === $v ) {
						$items[] = 'NULL';
					} elseif ( $info['hex'][ $i ] ) {
						$items[] = '' === $v ? "''" : '0x' . bin2hex( $v );
					} else {
						$items[] = "'" . NLWM_Replacer::sql_escape( $v ) . "'";
					}
				}
				$tuple    = '(' . implode( ',', $items ) . ')';
				$values[] = $tuple;
				$bytes   += strlen( $tuple ) + 1;
				if ( $bytes >= self::MAX_STMT ) {
					$this->put( $fh, $head . implode( ',', $values ) . ";\n" );
					$values = array();
					$bytes  = 0;
				}
				if ( $info['int_pk'] ) {
					$state['last'] = $row[ $info['pk_index'] ];
				}
			}
			unset( $page );
			if ( $values ) {
				$this->put( $fh, $head . implode( ',', $values ) . ";\n" );
			}
			$state['rows'] += $count;

			if ( $count < $limit ) {
				$state['table_index']++;
				$state['last']   = null;
				$state['offset'] = 0;
			} elseif ( ! $info['int_pk'] ) {
				$state['offset'] += $count;
			}

			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		fflush( $fh );
		$state['bytes'] = ftell( $fh );
		fclose( $fh );
		return $state['table_index'] >= count( $state['tables'] );
	}

	protected function put( $fh, $data ) {
		if ( fwrite( $fh, $data ) !== strlen( $data ) ) {
			throw new NLWM_Exception( 'Could not write the database dump. The disk may be full.' );
		}
	}

	protected function create_statement( $table, $alias ) {
		$rows = $this->rows( "SHOW CREATE TABLE `{$table}`" );
		$row  = $rows ? $rows[0] : null;
		if ( empty( $row[1] ) ) {
			return null;
		}
		$sql = $row[1];
		$sql = preg_replace( '/^CREATE TABLE `' . preg_quote( $table, '/' ) . '`/', 'CREATE TABLE `' . $alias . '`', $sql );
		$sql = preg_replace_callback(
			'/REFERENCES `' . preg_quote( $this->prefix, '/' ) . '([^`]+)`/',
			function ( $m ) {
				return 'REFERENCES `' . NLWM_PREFIX_PLACEHOLDER . $m[1] . '`';
			},
			$sql
		);
		// Constraint names must be unique per database; let MySQL generate new ones.
		$sql = preg_replace( '/CONSTRAINT `[^`]+` FOREIGN KEY/', 'FOREIGN KEY', $sql );
		return $sql;
	}

	protected function table_info( $table ) {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		$pk    = array();
		$hex   = array();
		$cols  = array();
		$types = array();
		foreach ( $this->rows( "SHOW COLUMNS FROM `{$table}`", true ) as $c ) {
			// Generated columns are computed by the server and must not be inserted.
			if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) $c['Extra'] ) ) {
				continue;
			}
			$type    = strtolower( $c['Type'] );
			$cols[]  = $c['Field'];
			$types[] = $type;
			$hex[]   = (bool) preg_match( '/^(tinyblob|blob|mediumblob|longblob|binary|varbinary|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)/', $type );
			if ( 'PRI' === $c['Key'] ) {
				$pk[] = $c['Field'];
			}
		}
		// Rows per page: about PAGE_BYTES of data per page, between 20 and ROWS rows.
		$status = $this->rows( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $this->db->esc_like( $table ) ), true );
		$avg    = $status ? (int) $status[0]['Avg_row_length'] : 0;
		$page   = $avg > 0 ? (int) max( 20, min( self::ROWS, floor( self::PAGE_BYTES / $avg ) ) ) : self::ROWS;

		$pk_index = 1 === count( $pk ) ? array_search( $pk[0], $cols, true ) : false;
		$int_pk   = false !== $pk_index && preg_match( '/^(tiny|small|medium|big)?int\b/', $types[ $pk_index ] );
		return $cache[ $table ] = array(
			'cols'     => $cols,
			'pk'       => $pk,
			'int_pk'   => (bool) $int_pk,
			'pk_index' => $int_pk ? $pk_index : null,
			'hex'      => $hex,
			'page'     => $page,
		);
	}

	protected function where_for( $table, array $opts ) {
		$p     = $this->prefix;
		$where = array( '1=1' );
		if ( ! empty( $opts['no_spam'] ) && $table === $p . 'comments' ) {
			$where[] = "`comment_approved` NOT IN ('spam','trash')";
		}
		if ( ! empty( $opts['no_revisions'] ) && $table === $p . 'posts' ) {
			$where[] = "`post_type` <> 'revision'";
		}
		if ( $table === $p . 'options' ) {
			$names   = NLWM_Util::private_options();
			$where[] = $this->db->prepare( '`option_name` NOT IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')', $names );
		}
		if ( ! empty( $opts['no_transients'] ) && $table === $p . 'options' ) {
			$where[] = $this->db->prepare( '`option_name` NOT LIKE %s AND `option_name` NOT LIKE %s', $this->db->esc_like( '_transient_' ) . '%', $this->db->esc_like( '_site_transient_' ) . '%' );
		}
		if ( ! empty( $opts['no_wc_logs'] ) ) {
			// WooCommerce: customer sessions and the history of finished background tasks grow without limit.
			// The tables themselves are kept (empty), and scheduled tasks that have not run yet are kept too.
			if ( $table === $p . 'woocommerce_sessions' || $table === $p . 'actionscheduler_logs' ) {
				$where[] = '1=0';
			} elseif ( $table === $p . 'actionscheduler_actions' ) {
				$where[] = "`status` NOT IN ('complete','failed','canceled')";
			}
		}
		return implode( ' AND ', $where );
	}

	/* ================================================================ RESTORE */

	public function drop_with_prefix( $prefix ) {
		foreach ( $this->tables_like( $prefix ) as $t ) {
			$this->query( "DROP TABLE IF EXISTS `{$t}`" );
		}
	}

	/**
	 * Execute statements from a dump into temporary tables.
	 *
	 * @param string        $file          SQL file
	 * @param array         $state         offset, statements, errors (by reference)
	 * @param string[]      $placeholders  placeholders used in this dump
	 * @param string        $final_prefix  live table prefix (used for data keys of .wpress dumps)
	 * @param NLWM_Replacer $replacer
	 * @return bool true when the whole file has been executed
	 */
	public function restore( $file, array &$state, array $placeholders, $final_prefix, NLWM_Replacer $replacer, $deadline ) {
		$this->session();
		$state += array( 'offset' => 0, 'statements' => 0, 'errors' => array(), 'error_count' => 0, 'skipped' => array() );

		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new NLWM_Exception( 'Cannot open the database file from the backup.' );
		}
		fseek( $fh, $state['offset'] );

		$table_map = array();
		$data_map  = array();
		foreach ( $placeholders as $ph ) {
			$table_map[ '`' . $ph ] = '`' . self::TMP_PREFIX;
			$data_map[ $ph ]        = $final_prefix;
		}

		$this->query( 'SET autocommit = 0' );
		$sql  = '';
		$done = true;
		try {
			while ( ( $line = fgets( $fh ) ) !== false ) {
				if ( '' === $sql ) {
					$trim = ltrim( $line );
					if ( '' === $trim || 0 === strpos( $trim, '--' ) || 0 === strpos( $trim, '#' ) ) {
						$state['offset'] = ftell( $fh );
						continue; // comment or blank line between statements
					}
				}
				$sql .= $line;
				if ( ';' !== substr( rtrim( $line ), -1 ) ) {
					continue;
				}
				$stmt = trim( $sql );
				$sql  = '';

				$stmt = strtr( $stmt, $table_map );
				$kind = $this->statement_kind( $stmt );
				if ( null === $kind ) {
					// Anything not aimed at a temporary table (SET, LOCK, non-WordPress tables, …) is skipped.
					$head = substr( preg_replace( '/\s+/', ' ', $stmt ), 0, 80 );
					if ( ! preg_match( '/^(SET |START TRANSACTION|COMMIT|\/\*|LOCK TABLES|UNLOCK TABLES)/i', $head ) && count( $state['skipped'] ) < 20 ) {
						$state['skipped'][] = $head;
					}
				} else {
					$stmt = strtr( $stmt, $data_map );
					if ( 'INSERT' === $kind ) {
						$stmt = $replacer->sql( $stmt );
						$this->exec_insert( $stmt, $state );
					} else {
						if ( 'CREATE' === $kind ) {
							$this->query( 'COMMIT' );
						}
						$this->exec_ddl( $stmt, $state );
					}
					$state['statements']++;
				}
				$state['offset'] = ftell( $fh );
				if ( microtime( true ) > $deadline ) {
					$done = false;
					break;
				}
			}
			$this->query( 'COMMIT' );
		} finally {
			$this->query( 'SET autocommit = 1' );
			fclose( $fh );
		}
		return $done;
	}

	/** INSERT / CREATE / DROP on a temporary table, or null for anything else. */
	protected function statement_kind( $stmt ) {
		$t = preg_quote( self::TMP_PREFIX, '/' );
		if ( preg_match( '/^INSERT\s+(IGNORE\s+)?INTO\s+`' . $t . '/i', $stmt ) ) {
			return 'INSERT';
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`' . $t . '/i', $stmt ) ) {
			return 'CREATE';
		}
		if ( preg_match( '/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?`' . $t . '[^`]*`\s*;$/i', $stmt ) ) {
			return 'DROP';
		}
		return null;
	}

	/** Gone away, lost connection, packet too big, too many connections, lock wait timeout. */
	protected function connection_lost( $error ) {
		return (bool) preg_match( '/gone away|lost connection|bigger than .max_allowed_packet|too many connections|lock wait timeout/i', $error );
	}

	protected function exec_insert( $stmt, array &$state ) {
		if ( $this->query( $stmt ) ) {
			return;
		}
		$error = $this->error();
		if ( $this->connection_lost( $error ) ) {
			throw new NLWM_Exception( esc_html( sprintf(
				'The database server dropped the import (%s). Usually one row is bigger than max_allowed_packet (%s on this server). Ask your host to raise max_allowed_packet, then restore again. Your live site has not been changed.',
				$error,
				size_format( (int) $this->one( 'SELECT @@max_allowed_packet' ) )
			) ) );
		}
		$table = preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`' . preg_quote( self::TMP_PREFIX, '/' ) . '([^`]+)`/i', $stmt, $m ) ? $m[1] : '';
		$state['error_count']++;
		if ( count( $state['errors'] ) < 20 ) {
			$state['errors'][] = $error . ' — ' . substr( $stmt, 0, 140 );
		}
		if ( in_array( $table, self::CORE, true ) ) {
			throw new NLWM_Exception( esc_html( sprintf( 'Could not import data into the %s table: %s. Your live site has not been changed.', $table, $error ) ) );
		}
	}

	protected function exec_ddl( $stmt, array &$state ) {
		if ( $this->query( $stmt ) ) {
			return;
		}
		$first = $this->error();
		$retry = null;
		if ( preg_match( '/already exists/i', $first ) && preg_match( '/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $stmt, $m ) ) {
			// Left over from a request that crashed after creating it: start this table again.
			$this->query( "DROP TABLE IF EXISTS `{$m[1]}`" );
			$retry = $stmt;
		} elseif ( preg_match( '/unknown (collation|character set|storage engine|table engine)|specified key was too long|invalid default value|error in your SQL syntax|got error \d+ from storage engine/i', $first ) ) {
			// Dump from a newer/different server: unknown collation, charset or engine, key too long,
			// invalid default. Apply every compatibility rewrite at once.
			$retry = $this->compat( $stmt );
		}
		if ( null !== $retry && $this->query( $retry ) ) {
			return;
		}
		$error = $this->error();
		if ( $this->connection_lost( $error ) ) {
			throw new NLWM_Exception( esc_html( 'The database server dropped the connection: ' . $error ) );
		}
		if ( 0 === stripos( $stmt, 'CREATE' ) ) {
			throw new NLWM_Exception( esc_html( 'Could not create a table: ' . $error . '. Your live site has not been changed.' ) );
		}
		$state['error_count']++;
		$state['errors'][] = $error . ' — ' . substr( $stmt, 0, 140 );
	}

	/** Rewrite a CREATE TABLE so older MySQL/MariaDB versions accept it. */
	protected function compat( $stmt ) {
		// Collations/charsets that only exist on newer servers.
		$stmt = preg_replace( '/utf8mb4_0900_\w+|utf8mb4_uca1400_\w+/i', 'utf8mb4_unicode_520_ci', $stmt );
		$stmt = preg_replace( '/utf8mb3_(\w+)/i', 'utf8_$1', $stmt );
		$stmt = preg_replace( '/\butf8mb3\b/i', 'utf8', $stmt );
		if ( ! $this->collation_ok( 'utf8mb4_unicode_520_ci' ) ) {
			$stmt = str_replace( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', $stmt );
		}
		if ( ! $this->collation_ok( 'utf8mb4_unicode_ci' ) ) {
			$stmt = preg_replace( '/utf8mb4_\w+/', 'utf8_general_ci', $stmt );
			$stmt = str_replace( 'utf8mb4', 'utf8', $stmt );
		}
		// Engines that are not available here.
		$stmt = preg_replace( '/ENGINE=(?!InnoDB\b|MyISAM\b)\w+/i', 'ENGINE=InnoDB', $stmt );
		// Index prefixes too long for old utf8mb4 setups: shorten only inside KEY definitions.
		$stmt = preg_replace_callback( '/^(\s*(?:PRIMARY |UNIQUE |FULLTEXT )?KEY\b.*)$/mi', function ( $m ) {
			return preg_replace( '/(`[^`]+`)\((\d{3,})\)/', '$1(191)', $m[1] );
		}, $stmt );
		// Zero dates are rejected by strict servers.
		$stmt = str_replace( "DEFAULT '0000-00-00 00:00:00'", "DEFAULT '1970-01-01 00:00:01'", $stmt );
		return $stmt;
	}

	protected function collation_ok( $collation ) {
		static $cache = array();
		if ( ! isset( $cache[ $collation ] ) ) {
			$cache[ $collation ] = null !== $this->one( $this->db->prepare( 'SHOW COLLATION LIKE %s', $collation ) );
		}
		return $cache[ $collation ];
	}

	/** Temporary tables that would exceed MySQL's 64-character name limit once renamed. */
	public function check_names( $final_prefix ) {
		foreach ( $this->tables_like( self::TMP_PREFIX ) as $t ) {
			if ( strlen( $final_prefix . substr( $t, strlen( self::TMP_PREFIX ) ) ) > 64 ) {
				throw new NLWM_Exception( esc_html( sprintf( 'Table name %s is too long for this site\'s table prefix.', $t ) ) );
			}
		}
	}

	public function has_tmp_tables() {
		return (bool) $this->tables_like( self::TMP_PREFIX );
	}

	/**
	 * Swap the imported temporary tables in place of the live ones in one atomic RENAME.
	 * Live tables that the backup does not contain are dropped too (they belong to the old site).
	 *
	 * @return string[] live table names after the swap
	 */
	public function swap( $final_prefix ) {
		$tmp = $this->tables_like( self::TMP_PREFIX );
		if ( ! $tmp ) {
			throw new NLWM_Exception( 'The backup did not contain any database tables.' );
		}
		$this->drop_with_prefix( self::OLD_PREFIX );

		$renames = array();
		foreach ( $this->tables( $final_prefix ) as $t ) {
			$renames[] = "`{$t}` TO `" . self::OLD_PREFIX . substr( $t, strlen( $final_prefix ) ) . '`';
		}
		$result = array();
		foreach ( $tmp as $t ) {
			$name      = $final_prefix . substr( $t, strlen( self::TMP_PREFIX ) );
			$renames[] = "`{$t}` TO `{$name}`";
			$result[]  = $name;
		}
		// Never wait forever for a metadata lock held by another request: that would freeze the site.
		$this->query( 'SET SESSION lock_wait_timeout = 30' );
		if ( ! $this->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			throw new NLWM_Exception( esc_html( 'Could not switch to the imported tables: ' . $this->error() . '. Your live site has not been changed; try again when the site is quiet.' ) );
		}
		$this->drop_with_prefix( self::OLD_PREFIX );
		return $result;
	}
}
