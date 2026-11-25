<?php
/**
 * Automatic backups on a schedule, with retention and e-mail reports.
 * A scheduled backup runs in slices: each cron tick works for a while, then books the next tick.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NLWM_Schedule {

	const HOOK     = 'nlwm_scheduled_backup';
	const CONT     = 'nlwm_continue_job';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'start' ) );
		add_action( self::CONT, array( __CLASS__, 'tick' ) );
		add_action( 'nlwm_job_done', array( __CLASS__, 'notify' ) );
		add_action( 'nlwm_job_failed', array( __CLASS__, 'notify' ) );
		add_action( 'nlwm_daily_gc', array( __CLASS__, 'gc' ) );
		if ( ! wp_next_scheduled( 'nlwm_daily_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nlwm_daily_gc' );
		}
	}

	public static function schedules( $s ) {
		if ( ! isset( $s['weekly'] ) ) {
			$s['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' );
		}
		$s['nlwm_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Once a month' );
		return $s;
	}

	public static function frequencies() {
		return apply_filters( 'nlwm_schedule_frequencies', array(
			'off'          => 'Off',
			'twicedaily'   => 'Twice a day',
			'daily'        => 'Daily',
			'weekly'       => 'Weekly',
			'nlwm_monthly' => 'Monthly',
		) );
	}

	/** Re-book the cron event after settings change. */
	public static function reschedule( array $settings ) {
		self::unschedule_main();
		if ( 'off' === $settings['schedule'] || ! isset( self::frequencies()[ $settings['schedule'] ] ) ) {
			return;
		}
		list( $h, $m ) = array_map( 'intval', explode( ':', $settings['schedule_time'] . ':0' ) );
		$tz    = wp_timezone();
		$first = new DateTime( 'today', $tz );
		$first->setTime( $h, $m );
		if ( $first->getTimestamp() <= time() ) {
			$first->modify( '+1 day' );
		}
		wp_schedule_event( $first->getTimestamp(), $settings['schedule'], self::HOOK );
	}

	protected static function unschedule_main() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	public static function unschedule() {
		self::unschedule_main();
		wp_clear_scheduled_hook( self::CONT );
		wp_clear_scheduled_hook( 'nlwm_daily_gc' );
	}

	public static function next_run() {
		return wp_next_scheduled( self::HOOK );
	}

	public static function start() {
		$s    = NLWM_Util::settings();
		$opts = array_fill_keys( (array) $s['exclude'], 1 );
		$opts['exclude_paths'] = $s['exclude_paths'];
		$opts['max_file_mb']   = (int) $s['max_file_mb'];
		$opts['origin']        = 'scheduled';
		$opts['storages']      = NLWM_Storages::default_targets();
		$opts['label']         = 'Scheduled backup';
		$opts = apply_filters( 'nlwm_scheduled_backup_opts', $opts );
		try {
			$job = NLWM_Job::create( 'export', $opts );
		} catch ( Exception $e ) {
			return; // something else is running; the next scheduled run will try again
		}
		self::tick( $job['id'] );
	}

	public static function tick( $id ) {
		try {
			$job = NLWM_Job::load( $id );
		} catch ( Exception $e ) {
			return;
		}
		// Cron requests have no visitor waiting, so keep working as long as PHP allows.
		$limit = (int) ini_get( 'max_execution_time' );
		$total = (int) apply_filters( 'nlwm_cron_budget', $limit > 0 ? max( 20, $limit - 10 ) : 600 );
		$start = time();
		do {
			$job = NLWM_Job::run( $job, min( 30, $total ) );
		} while ( 'running' === $job['status'] && empty( $job['busy'] ) && time() - $start < $total );
		if ( 'running' === $job['status'] ) {
			wp_schedule_single_event( time() + 5, self::CONT, array( $id ) );
			spawn_cron();
		}
	}

	public static function notify( $job ) {
		if ( 'export' !== $job['type'] || empty( $job['opts']['origin'] ) || 'scheduled' !== $job['opts']['origin'] ) {
			return;
		}
		$s = NLWM_Util::settings();
		if ( empty( $s['notify_email'] ) || ! is_email( $s['notify_email'] ) ) {
			return;
		}
		$ok = 'done' === $job['status'];
		if ( $ok && 'failure' === $s['notify_on'] ) {
			return;
		}
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = $ok ? sprintf( '[%s] Scheduled backup complete', $site ) : sprintf( '[%s] Scheduled backup FAILED', $site );
		$body    = $ok
			? sprintf(
				"Your scheduled backup finished.\n\nFile: %s\nSize: %s\nCloud copy: %s\n%s\nManage backups: %s\n",
				$job['result']['name'],
				$job['result']['size_human'],
				! empty( $job['result']['uploaded'] ) ? implode( ', ', $job['result']['uploaded'] ) : ( empty( $job['opts']['storages'] ) ? 'not used' : 'FAILED, kept on the server' ),
				empty( $job['result']['warnings'] ) ? '' : implode( "\n", $job['result']['warnings'] ) . "\n",
				admin_url( 'admin.php?page=' . NLWM_SLUG . '&tab=backups' )
			)
			: sprintf( "Your scheduled backup failed.\n\nError: %s\n\nLog:\n%s\n", $job['message'], implode( "\n", array_slice( $job['log'], -20 ) ) );
		wp_mail( $s['notify_email'], $subject, $body . "\n— " . NLWM_Util::brand()['name'] );
	}

	public static function gc() {
		NLWM_Job::gc();
		NLWM_Backups::gc_parts();
	}
}
