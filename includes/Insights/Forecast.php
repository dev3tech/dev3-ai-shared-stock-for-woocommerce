<?php
/**
 * Statistical stockout forecast + reorder suggestion per pool.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Insights;

use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Data\Purchase_Orders;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Statistics first: a blended daily rate (EWMA + recent + long-run mean), trend, weekday profile,
 * safety stock (z = 1.65, about 95% service level), reorder point, reorder date and quantity.
 * DEV3 AI only explains the numbers (Ai_Features::explain_forecast); this class never calls AI.
 */
class Forecast {

	const Z       = 1.65;
	const JOB     = 'dev3ss_forecast';
	const VERSION = 1;

	/**
	 * Computes a forecast from a daily demand series.
	 *
	 * @param array $series Y-m-d => units used, oldest first.
	 * @param float $stock  Current stock.
	 * @param array $opts   { lead_time, safety_days, cover_days, today (Y-m-d), history_days, incoming }.
	 * @return array
	 */
	public static function compute( array $series, $stock, array $opts = array() ) {
		$lead     = max( 0, (int) ( $opts['lead_time'] ?? 7 ) );
		$safety_d = max( 0, (int) ( $opts['safety_days'] ?? 7 ) );
		$cover    = max( 1, (int) ( $opts['cover_days'] ?? 30 ) );
		$today    = (string) ( $opts['today'] ?? gmdate( 'Y-m-d' ) );
		$incoming = max( 0, (float) ( $opts['incoming'] ?? 0 ) );
		$stock    = (float) $stock;
		if ( isset( $opts['history_days'] ) && (int) $opts['history_days'] > 0 && (int) $opts['history_days'] < count( $series ) ) {
			$series = array_slice( $series, -1 * (int) $opts['history_days'], null, true );
		}
		$values = array_values( array_map( 'floatval', $series ) );
		$h      = count( $values );
		$total  = array_sum( $values );
		$mean   = $h ? $total / $h : 0.0;

		$r           = max( 1, min( 14, (int) floor( $h / 3 ) ) );
		$recent      = $h ? array_slice( $values, -1 * $r ) : array();
		$previous    = $h > $r ? array_slice( $values, -2 * $r, $r ) : array();
		$recent_mean = $recent ? array_sum( $recent ) / count( $recent ) : 0.0;
		$prev_mean   = $previous ? array_sum( $previous ) / count( $previous ) : 0.0;
		if ( $prev_mean > 0 ) {
			$trend_pct = ( $recent_mean - $prev_mean ) / $prev_mean * 100;
		} else {
			$trend_pct = $recent_mean > 0 ? 100.0 : 0.0;
		}

		$ewma = $h ? $values[0] : 0.0;
		foreach ( $values as $v ) {
			$ewma = 0.2 * $v + 0.8 * $ewma;
		}
		$rate = max( 0.0, 0.5 * $ewma + 0.3 * $recent_mean + 0.2 * $mean );

		$var = 0.0;
		foreach ( $values as $v ) {
			$var += ( $v - $mean ) * ( $v - $mean );
		}
		$sd = $h > 1 ? sqrt( $var / ( $h - 1 ) ) : 0.0;
		$cv = $mean > 0 ? $sd / $mean : 0.0;

		$slope = self::slope( $values );

		$weekday = array_fill( 0, 7, 0.0 );
		$wcount  = array_fill( 0, 7, 0 );
		foreach ( $series as $date => $v ) {
			$w              = (int) gmdate( 'w', strtotime( $date . ' 12:00:00 UTC' ) );
			$weekday[ $w ] += (float) $v;
			++$wcount[ $w ];
		}
		foreach ( $weekday as $w => $sum ) {
			$weekday[ $w ] = $wcount[ $w ] ? round( $sum / $wcount[ $w ], 2 ) : 0.0;
		}
		$busiest     = array_search( max( $weekday ), $weekday, true );
		$busiest_pct = $mean > 0 ? ( $weekday[ $busiest ] / $mean - 1 ) * 100 : 0.0;

		$safety        = max( self::Z * $sd * sqrt( max( 1, $lead ) ), $rate * $safety_d );
		$reorder_point = $rate * $lead + $safety;
		$effective     = $stock + $incoming;
		$days_left     = null;
		$stockout_date = null;
		$reorder_in    = null;
		$reorder_date  = null;
		$qty           = 0;
		if ( $stock <= 0 ) {
			$days_left     = 0.0;
			$stockout_date = $today;
		} elseif ( $rate > 0 ) {
			$days_left     = $stock / $rate;
			$stockout_date = self::add_days( $today, (int) floor( $days_left ) );
		}
		if ( $rate > 0 ) {
			$reorder_in   = ( $effective - $reorder_point ) / $rate;
			$reorder_date = self::add_days( $today, max( 0, (int) floor( $reorder_in ) ) );
			$qty          = max( 0, (int) ceil( $rate * ( $lead + $cover ) + $safety - $effective ) );
		}
		if ( $stock <= 0 ) {
			$urgency = 'out';
		} elseif ( $rate <= 0 ) {
			$urgency = 'idle';
		} elseif ( $reorder_in <= 0 ) {
			$urgency = 'now';
		} elseif ( $reorder_in <= 7 ) {
			$urgency = 'soon';
		} else {
			$urgency = 'ok';
		}
		if ( $stock <= 0 && $qty <= 0 ) {
			$qty = max( 1, (int) ceil( $rate * ( $lead + $cover ) + $safety ) );
		}
		$active_days = count(
			array_filter(
				$values,
				static function ( $v ) {
					return $v > 0;
				}
			)
		);
		if ( $h >= 28 && $active_days >= 7 && $cv <= 1.5 ) {
			$confidence = 'high';
		} elseif ( $h >= 14 && $active_days >= 3 ) {
			$confidence = 'medium';
		} else {
			$confidence = 'low';
		}

		return array(
			'version'        => self::VERSION,
			'today'          => $today,
			'history_days'   => $h,
			'active_days'    => $active_days,
			'total_used'     => round( $total, 2 ),
			'mean_per_day'   => round( $mean, 3 ),
			'recent_per_day' => round( $recent_mean, 3 ),
			'recent_days'    => $r,
			'rate_per_day'   => round( $rate, 3 ),
			'trend_pct'      => round( $trend_pct, 1 ),
			'slope_per_week' => round( $slope * 7, 3 ),
			'std_dev'        => round( $sd, 3 ),
			'weekday'        => $weekday,
			'busiest_day'    => (int) $busiest,
			'busiest_pct'    => round( $busiest_pct, 1 ),
			'stock'          => round( $stock, 4 ),
			'incoming'       => round( $incoming, 4 ),
			'days_left'      => null === $days_left ? null : round( $days_left, 1 ),
			'stockout_date'  => $stockout_date,
			'lead_time'      => $lead,
			'cover_days'     => $cover,
			'safety_stock'   => round( $safety, 2 ),
			'reorder_point'  => round( $reorder_point, 2 ),
			'reorder_date'   => $reorder_date,
			'reorder_in'     => null === $reorder_in ? null : round( $reorder_in, 1 ),
			'reorder_qty'    => $qty,
			'urgency'        => $urgency,
			'confidence'     => $confidence,
			'series'         => array_slice( $series, -30, null, true ),
		);
	}

	/**
	 * Least-squares slope (units per day per day).
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	public static function slope( array $values ) {
		$n = count( $values );
		if ( $n < 2 ) {
			return 0.0;
		}
		$mx  = ( $n - 1 ) / 2;
		$my  = array_sum( $values ) / $n;
		$num = 0.0;
		$den = 0.0;
		foreach ( $values as $i => $v ) {
			$num += ( $i - $mx ) * ( $v - $my );
			$den += ( $i - $mx ) * ( $i - $mx );
		}
		return $den > 0 ? $num / $den : 0.0;
	}

	/**
	 * Adds days to a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @param int    $days Days.
	 * @return string
	 */
	public static function add_days( $date, $days ) {
		return gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00 UTC' ) + (int) $days * DAY_IN_SECONDS );
	}

	/**
	 * Plain-words explanation of a forecast (no AI needed).
	 *
	 * @param array $f    Forecast.
	 * @param array $pool Pool.
	 * @return string
	 */
	public static function explain( array $f, array $pool ) {
		$unit  = '' !== $pool['unit'] ? $pool['unit'] : __( 'units', 'dev3-ai-shared-stock-for-woocommerce' );
		$fmt   = static function ( $n ) {
			return $n >= 10 ? number_format_i18n( $n ) : wc_format_decimal( $n, 1, true );
		};
		$date  = static function ( $d ) {
			return $d ? date_i18n( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) : '';
		};
		$parts = array();
		if ( $f['rate_per_day'] <= 0 ) {
			/* translators: 1: days of history, 2: stock, 3: unit */
			$parts[] = sprintf( __( 'No sales in the last %1$d days, so there is no stockout in sight. %2$s %3$s are in stock.', 'dev3-ai-shared-stock-for-woocommerce' ), $f['history_days'], $fmt( $f['stock'] ), $unit );
			return implode( ' ', $parts );
		}
		/* translators: 1: units per day, 2: unit, 3: days of history */
		$parts[] = sprintf( __( 'This pool uses about %1$s %2$s a day (based on the last %3$d days).', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( $f['rate_per_day'] ), $unit, $f['history_days'] );
		if ( abs( $f['trend_pct'] ) >= 10 && $f['history_days'] >= 14 ) {
			$parts[] = $f['trend_pct'] > 0
				/* translators: 1: percent, 2: days */
				? sprintf( __( 'Demand is rising: the last %2$d days were %1$s%% busier than the %2$d days before.', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( abs( $f['trend_pct'] ) ), $f['recent_days'] )
				/* translators: 1: percent, 2: days */
				: sprintf( __( 'Demand is slowing: the last %2$d days were %1$s%% quieter than the %2$d days before.', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( abs( $f['trend_pct'] ) ), $f['recent_days'] );
		} else {
			$parts[] = __( 'Demand is steady.', 'dev3-ai-shared-stock-for-woocommerce' );
		}
		if ( $f['busiest_pct'] >= 25 && $f['history_days'] >= 28 ) {
			global $wp_locale;
			$day = $wp_locale ? $wp_locale->get_weekday( $f['busiest_day'] ) : (string) $f['busiest_day'];
			/* translators: 1: weekday, 2: percent */
			$parts[] = sprintf( __( '%1$s is the busiest day (%2$s%% above average).', 'dev3-ai-shared-stock-for-woocommerce' ), $day, $fmt( $f['busiest_pct'] ) );
		}
		if ( $f['stock'] <= 0 ) {
			$parts[] = __( 'It is out of stock now.', 'dev3-ai-shared-stock-for-woocommerce' );
		} else {
			/* translators: 1: stock, 2: unit, 3: days, 4: date */
			$parts[] = sprintf( __( 'At this pace the %1$s %2$s in stock last about %3$s days, until around %4$s.', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( $f['stock'] ), $unit, $fmt( $f['days_left'] ), $date( $f['stockout_date'] ) );
		}
		if ( $f['incoming'] > 0 ) {
			/* translators: 1: quantity, 2: unit */
			$parts[] = sprintf( __( '%1$s %2$s are already on order.', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( $f['incoming'] ), $unit );
		}
		if ( 'now' === $f['urgency'] || 'out' === $f['urgency'] ) {
			/* translators: 1: lead time days, 2: quantity, 3: unit, 4: cover days */
			$parts[] = sprintf( __( 'With a %1$d-day supplier lead time, reorder now: about %2$s %3$s covers the next %4$d days plus a safety buffer.', 'dev3-ai-shared-stock-for-woocommerce' ), $f['lead_time'], $fmt( $f['reorder_qty'] ), $unit, $f['lead_time'] + $f['cover_days'] );
		} else {
			/* translators: 1: lead time days, 2: date, 3: quantity, 4: unit, 5: cover days */
			$parts[] = sprintf( __( 'With a %1$d-day supplier lead time, reorder by %2$s: about %3$s %4$s covers the next %5$d days plus a safety buffer.', 'dev3-ai-shared-stock-for-woocommerce' ), $f['lead_time'], $date( $f['reorder_date'] ), $fmt( $f['reorder_qty'] ), $unit, $f['lead_time'] + $f['cover_days'] );
		}
		if ( 'low' === $f['confidence'] ) {
			$parts[] = __( 'There is little sales history yet, so treat this as a rough guide.', 'dev3-ai-shared-stock-for-woocommerce' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Forecast for one pool from its log.
	 *
	 * @param array  $pool  Pool.
	 * @param string $today Y-m-d (tests).
	 * @return array
	 */
	public static function for_pool( array $pool, $today = '' ) {
		$today            = $today ? $today : gmdate( 'Y-m-d' );
		$window           = (int) Settings::get( 'forecast_window', 90 );
		$series           = Log::daily_demand( $pool['id'], $window, $today );
		$first            = Log::first_date( $pool['id'] );
		$history          = $first ? (int) floor( ( strtotime( $today . ' 12:00:00 UTC' ) - strtotime( $first . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1 : 0;
		$incoming         = Purchase_Orders::incoming();
		$f                = self::compute(
			$series,
			$pool['stock'],
			array(
				'lead_time'    => $pool['lead_time_days'],
				'safety_days'  => (int) Settings::get( 'safety_days', 7 ),
				'cover_days'   => (int) Settings::get( 'cover_days', 30 ),
				'today'        => $today,
				'history_days' => max( 1, min( $window, $history ) ),
				'incoming'     => isset( $incoming[ $pool['id'] ] ) ? $incoming[ $pool['id'] ]['qty'] : 0,
			)
		);
		$f['pool_id']     = $pool['id'];
		$f['explanation'] = self::explain( $f, $pool );
		$f['computed_at'] = Pools::now();
		$f['hash']        = self::hash( $f );
		/**
		 * Filters a pool forecast.
		 *
		 * @param array $f    Forecast.
		 * @param array $pool Pool.
		 */
		return (array) apply_filters( 'dev3ss_forecast', $f, $pool );
	}

	/**
	 * Key-figures hash (an AI explanation stays valid while it matches).
	 *
	 * @param array $f Forecast.
	 * @return string
	 */
	public static function hash( array $f ) {
		return md5( wp_json_encode( array( round( $f['rate_per_day'], 1 ), round( $f['trend_pct'] ), (int) $f['days_left'], $f['reorder_qty'], $f['stock'], $f['today'] ) ) );
	}

	/**
	 * Recomputes and stores a pool forecast (keeps a still-valid AI explanation).
	 *
	 * @param int $pool_id Pool id.
	 * @return array|null
	 */
	public static function refresh( $pool_id ) {
		$pool = Pools::get( $pool_id, true );
		if ( ! $pool ) {
			return null;
		}
		$f   = self::for_pool( $pool );
		$old = $pool['forecast'];
		if ( $old && ! empty( $old['ai'] ) && ( $old['ai']['hash'] ?? '' ) === $f['hash'] ) {
			$f['ai'] = $old['ai'];
		}
		Pools::save_forecast( $pool_id, $f );
		self::risk_alert( $pool, $f );
		return $f;
	}

	/**
	 * Raises a forecast alert when the pool will run out before a reorder could arrive.
	 *
	 * @param array $pool Pool.
	 * @param array $f    Forecast.
	 */
	public static function risk_alert( array $pool, array $f ) {
		if ( null === $f['days_left'] || $f['stock'] <= 0 || $f['incoming'] > 0 ) {
			return;
		}
		if ( $f['days_left'] <= max( 1, $f['lead_time'] ) ) {
			Alerts::add(
				$pool['id'],
				'forecast',
				'warning',
				/* translators: 1: pool name, 2: days, 3: lead time */
				sprintf( __( '%1$s will run out in about %2$s days, before a new delivery can arrive (lead time %3$d days).', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], number_format_i18n( max( 1, round( $f['days_left'] ) ) ), $f['lead_time'] ),
				array(
					'days_left'   => $f['days_left'],
					'reorder_qty' => $f['reorder_qty'],
				),
				'forecast:' . $pool['id'] . ':' . $f['today']
			);
		}
	}

	/**
	 * Job handler: forecasts a chunk of pools (and optionally asks DEV3 AI to explain the at-risk ones).
	 *
	 * @param array $items Pool ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$out     = array();
		$explain = ! empty( $job['args']['explain'] ) && Settings::ai_feature( 'ai_forecast_explain' );
		foreach ( $items as $pool_id ) {
			$f = self::refresh( (int) $pool_id );
			if ( ! $f ) {
				$out[ $pool_id ] = __( 'Pool not found', 'dev3-ai-shared-stock-for-woocommerce' );
				continue;
			}
			if ( $explain && in_array( $f['urgency'], array( 'out', 'now', 'soon' ), true ) && empty( $f['ai'] ) ) {
				$ai = Ai_Features::explain_forecast( (int) $pool_id, 'cron' );
				if ( is_wp_error( $ai ) ) {
					$out[ $pool_id ] = $ai;
					continue;
				}
			}
			$out[ $pool_id ] = true;
		}
		return $out;
	}

	/**
	 * Daily cron: refreshes every active pool (statistics only, no AI).
	 *
	 * @return int Pools refreshed.
	 */
	public static function refresh_all() {
		$n = 0;
		foreach ( Pools::all( array( 'status' => 'active' ) ) as $pool ) {
			if ( self::refresh( $pool['id'] ) ) {
				++$n;
			}
		}
		return $n;
	}
}
