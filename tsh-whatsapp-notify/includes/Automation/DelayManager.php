<?php
/**
 * Delay / wait node handling.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DelayManager
 *
 * Converts delay node configuration into an absolute timestamp.
 */
class DelayManager {

	/**
	 * Calculate the Unix timestamp at which execution should resume.
	 *
	 * @param array $config {
	 *   @type string $delay_type    'minutes'|'hours'|'days'|'specific_datetime'|'business_hours'
	 *   @type int    $delay_value   For minutes/hours/days
	 *   @type string $specific_datetime  MySQL datetime for specific_datetime type
	 *   @type string $business_start  HH:MM for business_hours type
	 *   @type string $business_end    HH:MM for business_hours type
	 * }
	 * @param int $from_timestamp  Timestamp to count from. Default: now.
	 * @return int Unix timestamp to resume at.
	 */
	public function calculate_resume_at( array $config, int $from_timestamp = 0 ): int {
		$now  = $from_timestamp ?: (int) current_time( 'timestamp' );
		$type = $config['delay_type'] ?? 'minutes';
		$val  = max( 0, (int) ( $config['delay_value'] ?? 0 ) );

		switch ( $type ) {
			case 'minutes':
				return $now + ( $val * MINUTE_IN_SECONDS );

			case 'hours':
				return $now + ( $val * HOUR_IN_SECONDS );

			case 'days':
				return $now + ( $val * DAY_IN_SECONDS );

			case 'specific_datetime':
				$dt = $config['specific_datetime'] ?? '';
				$ts = $dt ? (int) strtotime( $dt ) : 0;
				return $ts > $now ? $ts : $now;

			case 'business_hours':
				return $this->next_business_hour(
					$now,
					$config['business_start'] ?? '09:00',
					$config['business_end']   ?? '17:00'
				);

			default:
				return $now;
		}
	}

	/**
	 * Return the number of seconds to wait from now.
	 */
	public function calculate_delay_seconds( array $config, int $from_timestamp = 0 ): int {
		$now       = $from_timestamp ?: (int) current_time( 'timestamp' );
		$resume_at = $this->calculate_resume_at( $config, $now );

		return max( 0, $resume_at - $now );
	}

	/**
	 * Convert delay seconds to a human-readable label.
	 */
	public static function format_delay( array $config ): string {
		$type = $config['delay_type'] ?? 'minutes';
		$val  = (int) ( $config['delay_value'] ?? 0 );

		switch ( $type ) {
			case 'minutes':
				return sprintf( _n( '%d minute', '%d minutes', $val, 'tsh-whatsapp-notify' ), $val );
			case 'hours':
				return sprintf( _n( '%d hour', '%d hours', $val, 'tsh-whatsapp-notify' ), $val );
			case 'days':
				return sprintf( _n( '%d day', '%d days', $val, 'tsh-whatsapp-notify' ), $val );
			case 'specific_datetime':
				return $config['specific_datetime'] ?? 'Specific date/time';
			case 'business_hours':
				return 'Next business hours (' . ( $config['business_start'] ?? '09:00' ) . '–' . ( $config['business_end'] ?? '17:00' ) . ')';
			default:
				return 'Delay';
		}
	}

	// -------------------------------------------------------------------------
	// Business hours calculation
	// -------------------------------------------------------------------------

	/**
	 * Find the next timestamp that falls within business hours.
	 *
	 * @param int    $from
	 * @param string $start_time  HH:MM
	 * @param string $end_time    HH:MM
	 * @return int
	 */
	private function next_business_hour( int $from, string $start_time, string $end_time ): int {
		$start_parts = explode( ':', $start_time );
		$end_parts   = explode( ':', $end_time );

		$start_h = (int) ( $start_parts[0] ?? 9 );
		$start_m = (int) ( $start_parts[1] ?? 0 );
		$end_h   = (int) ( $end_parts[0] ?? 17 );
		$end_m   = (int) ( $end_parts[1] ?? 0 );

		// Try today first, then up to 7 days ahead.
		for ( $i = 0; $i < 7; $i++ ) {
			$day_start = mktime( $start_h, $start_m, 0, (int) gmdate( 'n', $from + $i * DAY_IN_SECONDS ), (int) gmdate( 'j', $from + $i * DAY_IN_SECONDS ), (int) gmdate( 'Y', $from + $i * DAY_IN_SECONDS ) );
			$day_end   = mktime( $end_h, $end_m, 0, (int) gmdate( 'n', $from + $i * DAY_IN_SECONDS ), (int) gmdate( 'j', $from + $i * DAY_IN_SECONDS ), (int) gmdate( 'Y', $from + $i * DAY_IN_SECONDS ) );

			// Skip weekends.
			$dow = (int) gmdate( 'w', $day_start );
			if ( 0 === $dow || 6 === $dow ) {
				continue;
			}

			if ( $from <= $day_start ) {
				return $day_start;
			}

			if ( $from >= $day_start && $from < $day_end ) {
				return $from;
			}
		}

		return $from;
	}
}
