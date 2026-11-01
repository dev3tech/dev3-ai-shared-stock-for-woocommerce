<?php
/**
 * Anomaly detection: sudden stock drops and likely data-entry errors.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Insights;

use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rules (statistics, no AI). DEV3 AI can explain an alert and propose a fix (shown as a draft).
 */
class Anomalies {

	/**
	 * Standard deviations above normal that count as "sudden".
	 *
	 * @return float
	 */
	public static function k() {
		$map = array(
			'low'    => 4.0,
			'medium' => 3.0,
			'high'   => 2.5,
		);
		$s   = (string) Settings::get( 'anomaly_sensitivity', 'medium' );
		return $map[ $s ] ?? 3.0;
	}

	/**
	 * Classifies a manual / imported change. Pure function (unit-tested).
	 *
	 * @param float $before     Stock before.
	 * @param float $after      Stock after.
	 * @param float $weekly_use Typical units used per week.
	 * @param bool  $backorders Pool allows backorders.
	 * @return array|null { rule, suggested } or null.
	 */
	public static function classify_change( $before, $after, $weekly_use = 0.0, $backorders = false ) {
		$before = (float) $before;
		$after  = (float) $after;
		$delta  = $after - $before;
		if ( $after < 0 && ! $backorders ) {
			return array(
				'rule'      => 'negative',
				'suggested' => 0,
			);
		}
		if ( $before >= 5 && $after > 0 ) {
			foreach ( array( 10, 100 ) as $f ) {
				if ( abs( $after - $before * $f ) < 0.5 ) {
					return array(
						'rule'      => 'extra_zero',
						'suggested' => $before,
					);
				}
			}
			if ( $before >= 50 && abs( $after * 10 - $before ) < 0.5 ) {
				return array(
					'rule'      => 'missing_zero',
					'suggested' => $before,
				);
			}
		}
		if ( $before >= 10 && $after >= 10 && floor( $before ) === $before && floor( $after ) === $after && $before !== $after ) {
			$a = str_split( (string) (int) $before );
			$b = str_split( (string) (int) $after );
			sort( $a );
			sort( $b );
			if ( $a === $b ) {
				return array(
					'rule'      => 'digit_swap',
					'suggested' => $before,
				);
			}
		}
		$big = max( 10, 0.5 * $before );
		if ( $delta < 0 && abs( $delta ) >= $big && abs( $delta ) > 3 * max( 1, $weekly_use ) ) {
			return array(
				'rule'      => 'large_removal',
				'suggested' => null,
			);
		}
		if ( $delta > 0 && $before > 0 && $delta > 20 * max( 1, $weekly_use ) && $delta >= 200 ) {
			return array(
				'rule'      => 'large_addition',
				'suggested' => null,
			);
		}
		return null;
	}

	/**
	 * Human message for a rule.
	 *
	 * @param string $rule   Rule.
	 * @param array  $pool   Pool.
	 * @param float  $before Before.
	 * @param float  $after  After.
	 * @return string
	 */
	public static function message( $rule, array $pool, $before, $after ) {
		$b = wc_format_decimal( $before, 4, true );
		$a = wc_format_decimal( $after, 4, true );
		switch ( $rule ) {
			case 'negative':
				/* translators: 1: pool, 2: stock */
				return sprintf( __( '%1$s went below zero (%2$s) but does not allow backorders.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $a );
			case 'extra_zero':
				/* translators: 1: pool, 2: before, 3: after */
				return sprintf( __( '%1$s was changed by hand from %2$s to %3$s. That looks like an extra zero.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $b, $a );
			case 'missing_zero':
				/* translators: 1: pool, 2: before, 3: after */
				return sprintf( __( '%1$s was changed by hand from %2$s to %3$s. That looks like a missing zero.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $b, $a );
			case 'digit_swap':
				/* translators: 1: pool, 2: before, 3: after */
				return sprintf( __( '%1$s was changed by hand from %2$s to %3$s. The digits look swapped.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $b, $a );
			case 'large_removal':
				/* translators: 1: pool, 2: before, 3: after */
				return sprintf( __( 'A large manual removal on %1$s: %2$s to %3$s, far more than a normal week of sales.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $b, $a );
			case 'large_addition':
				/* translators: 1: pool, 2: before, 3: after */
				return sprintf( __( 'An unusually large addition on %1$s: %2$s to %3$s, many months of sales at once.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $b, $a );
			default:
				/* translators: 1: pool, 2: amount */
				return sprintf( __( 'Sudden stock drop on %1$s: %2$s used in one day, far above normal.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], $a );
		}
	}

	/**
	 * Checks a manual / imported change and raises an alert.
	 *
	 * @param array  $pool   Pool.
	 * @param float  $delta  Change.
	 * @param float  $after  After.
	 * @param string $reason Reason.
	 * @param array  $ctx    Context (log_id).
	 * @return int Alert id or 0.
	 */
	public static function check_change( array $pool, $delta, $after, $reason, array $ctx = array() ) {
		$before = $after - $delta;
		$series = Log::daily_demand( $pool['id'], 28 );
		$weekly = array_sum( $series ) / 4;
		$hit    = self::classify_change( $before, $after, $weekly, $pool['backorders'] );
		if ( ! $hit ) {
			return 0;
		}
		$severity = in_array( $hit['rule'], array( 'negative', 'extra_zero', 'missing_zero' ), true ) ? 'danger' : 'warning';
		return Alerts::add(
			$pool['id'],
			'anomaly',
			$severity,
			self::message( $hit['rule'], $pool, $before, $after ),
			array(
				'rule'      => $hit['rule'],
				'reason'    => $reason,
				'before'    => round( $before, 4 ),
				'after'     => round( $after, 4 ),
				'delta'     => round( $delta, 4 ),
				'log_id'    => (int) ( $ctx['log_id'] ?? 0 ),
				'suggested' => $hit['suggested'],
				'weekly'    => round( $weekly, 2 ),
			),
			'anomaly:' . $pool['id'] . ':' . (int) ( $ctx['log_id'] ?? 0 )
		);
	}

	/**
	 * Sudden-drop test on a daily series (last day vs the days before). Pure function.
	 *
	 * @param float[] $values Daily usage, oldest first.
	 * @param float   $k      Standard deviations.
	 * @return array|null { used, mean, sd }
	 */
	public static function sudden_drop( array $values, $k = 3.0 ) {
		$values = array_values( array_map( 'floatval', $values ) );
		if ( count( $values ) < 8 ) {
			return null;
		}
		$last = array_pop( $values );
		$mean = array_sum( $values ) / count( $values );
		$var  = 0.0;
		foreach ( $values as $v ) {
			$var += ( $v - $mean ) * ( $v - $mean );
		}
		$sd = sqrt( $var / max( 1, count( $values ) - 1 ) );
		if ( $last >= 5 && $last > $mean + $k * max( $sd, 1 ) && $last > 3 * max( $mean, 0.5 ) ) {
			return array(
				'used' => $last,
				'mean' => round( $mean, 2 ),
				'sd'   => round( $sd, 2 ),
			);
		}
		return null;
	}

	/**
	 * Scans every pool for a sudden drop today and yesterday.
	 *
	 * @param string $today Y-m-d.
	 * @return int Alerts raised.
	 */
	public static function scan( $today = '' ) {
		if ( ! Settings::get( 'anomaly_detection', true ) ) {
			return 0;
		}
		$today = $today ? $today : gmdate( 'Y-m-d' );
		$n     = 0;
		foreach ( Pools::all( array( 'status' => 'active' ) ) as $pool ) {
			$series = Log::daily_demand( $pool['id'], 61, $today );
			foreach ( array( 0, 1 ) as $back ) {
				$values = array_slice( array_values( $series ), 0, count( $series ) - $back );
				$dates  = array_keys( $series );
				$day    = $dates[ count( $dates ) - 1 - $back ];
				$hit    = self::sudden_drop( $values, self::k() );
				if ( ! $hit ) {
					continue;
				}
				$id = Alerts::add(
					$pool['id'],
					'anomaly',
					'warning',
					self::message( 'sudden_drop', $pool, 0, $hit['used'] ),
					array(
						'rule'      => 'sudden_drop',
						'day'       => $day,
						'used'      => $hit['used'],
						'mean'      => $hit['mean'],
						'sd'        => $hit['sd'],
						'suggested' => null,
					),
					'drop:' . $pool['id'] . ':' . $day
				);
				$n += $id ? 1 : 0;
				break;
			}
		}
		return $n;
	}
}
