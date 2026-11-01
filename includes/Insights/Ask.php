<?php
/**
 * Ask-your-inventory: the data snapshot, dates of retail events and a rule-based answerer.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Insights;

use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Data\Purchase_Orders;

defined( 'ABSPATH' ) || exit;

/**
 * DEV3 AI answers free questions from snapshot(); without AI, answer() handles the common ones
 * ("what runs out before Black Friday?", "what should I reorder?", "best sellers").
 */
class Ask {

	/**
	 * Compact inventory snapshot with forecasts.
	 *
	 * @param int $limit Max pools.
	 * @return array[]
	 */
	public static function snapshot( $limit = 150 ) {
		$incoming = Purchase_Orders::incoming();
		$out      = array();
		foreach ( Pools::all( array( 'status' => 'active' ) ) as $pool ) {
			$f = $pool['forecast'];
			if ( ! $f || ( $f['today'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
				$f = Forecast::refresh( $pool['id'] );
			}
			$out[] = array(
				'id'            => $pool['id'],
				'name'          => $pool['name'],
				'sku'           => $pool['sku'],
				'unit'          => $pool['unit'],
				'stock'         => $pool['stock'],
				'low_threshold' => $pool['low_threshold'],
				'per_day'       => $f ? $f['rate_per_day'] : 0,
				'trend_pct'     => $f ? $f['trend_pct'] : 0,
				'days_left'     => $f ? $f['days_left'] : null,
				'stockout_date' => $f ? $f['stockout_date'] : null,
				'reorder_date'  => $f ? $f['reorder_date'] : null,
				'reorder_qty'   => $f ? $f['reorder_qty'] : 0,
				'urgency'       => $f ? $f['urgency'] : 'idle',
				'lead_time'     => $pool['lead_time_days'],
				'incoming'      => isset( $incoming[ $pool['id'] ] ) ? $incoming[ $pool['id'] ]['qty'] : 0,
				'supplier'      => $pool['supplier_name'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$x = null === $a['days_left'] ? PHP_INT_MAX : $a['days_left'];
				$y = null === $b['days_left'] ? PHP_INT_MAX : $b['days_left'];
				return $x <=> $y;
			}
		);
		return array_slice( $out, 0, (int) $limit );
	}

	/**
	 * Next dates of common retail events on or after today.
	 *
	 * @param string $today Y-m-d.
	 * @return array key => { label, date }
	 */
	public static function events( $today ) {
		$year = (int) substr( $today, 0, 4 );
		$out  = array();
		foreach ( array( $year, $year + 1 ) as $y ) {
			$thanks = strtotime( "fourth thursday of november {$y} 12:00 UTC" );
			$list   = array(
				'black_friday'   => array( __( 'Black Friday', 'dev3-ai-shared-stock-for-woocommerce' ), gmdate( 'Y-m-d', $thanks + DAY_IN_SECONDS ) ),
				'cyber_monday'   => array( __( 'Cyber Monday', 'dev3-ai-shared-stock-for-woocommerce' ), gmdate( 'Y-m-d', $thanks + 4 * DAY_IN_SECONDS ) ),
				'christmas'      => array( __( 'Christmas', 'dev3-ai-shared-stock-for-woocommerce' ), "{$y}-12-25" ),
				'new_year'       => array( __( 'New Year', 'dev3-ai-shared-stock-for-woocommerce' ), ( $y + 1 ) . '-01-01' ),
				'valentines'     => array( __( 'Valentine\'s Day', 'dev3-ai-shared-stock-for-woocommerce' ), "{$y}-02-14" ),
				'mothers_day'    => array( __( 'Mother\'s Day (US)', 'dev3-ai-shared-stock-for-woocommerce' ), gmdate( 'Y-m-d', strtotime( "second sunday of may {$y} 12:00 UTC" ) ) ),
				'back_to_school' => array( __( 'Back to school', 'dev3-ai-shared-stock-for-woocommerce' ), "{$y}-08-15" ),
			);
			foreach ( $list as $key => $pair ) {
				if ( ! isset( $out[ $key ] ) && $pair[1] >= $today ) {
					$out[ $key ] = array(
						'label' => $pair[0],
						'date'  => $pair[1],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Finds the date a question is about.
	 *
	 * @param string $q     Question.
	 * @param string $today Y-m-d.
	 * @return array|null { date, label }
	 */
	public static function target_date( $q, $today ) {
		$q      = strtolower( $q );
		$events = self::events( $today );
		$words  = array(
			'black_friday'   => '/black\s*friday|bfcm/',
			'cyber_monday'   => '/cyber\s*monday/',
			'christmas'      => '/christmas|xmas|holiday season|holidays/',
			'new_year'       => '/new\s*year/',
			'valentines'     => '/valentine/',
			'mothers_day'    => '/mother\'?s\s*day/',
			'back_to_school' => '/back\s*to\s*school/',
		);
		foreach ( $words as $key => $re ) {
			if ( preg_match( $re, $q ) && isset( $events[ $key ] ) ) {
				return $events[ $key ];
			}
		}
		if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $q, $m ) ) {
			return array(
				'date'  => $m[1],
				'label' => $m[1],
			);
		}
		if ( preg_match( '/(?:in|within|next)\s+(\d{1,3})\s*(day|week|month)s?/', $q, $m ) ) {
			$mult = array(
				'day'   => 1,
				'week'  => 7,
				'month' => 30,
			);
			$days = (int) $m[1] * $mult[ $m[2] ];
			return array(
				'date'  => Forecast::add_days( $today, $days ),
				/* translators: %d: days */
				'label' => sprintf( _n( 'the next %d day', 'the next %d days', $days, 'dev3-ai-shared-stock-for-woocommerce' ), $days ),
			);
		}
		$rel = array(
			'/next\s+week|this\s+week|7\s*days/'    => array( 7, __( 'the next 7 days', 'dev3-ai-shared-stock-for-woocommerce' ) ),
			'/next\s+month|this\s+month|30\s*days/' => array( 30, __( 'the next 30 days', 'dev3-ai-shared-stock-for-woocommerce' ) ),
			'/tomorrow/'                            => array( 1, __( 'tomorrow', 'dev3-ai-shared-stock-for-woocommerce' ) ),
			'/weekend/'                             => array( 6 - (int) gmdate( 'w', strtotime( $today ) ) + 1, __( 'the weekend', 'dev3-ai-shared-stock-for-woocommerce' ) ),
		);
		foreach ( $rel as $re => $pair ) {
			if ( preg_match( $re, $q ) ) {
				return array(
					'date'  => Forecast::add_days( $today, $pair[0] ),
					'label' => $pair[1],
				);
			}
		}
		return null;
	}

	/**
	 * Rule-based answer.
	 *
	 * @param string  $question Question.
	 * @param array[] $snapshot Snapshot.
	 * @param string  $today    Y-m-d.
	 * @return array { answer, pool_ids, source }
	 */
	public static function answer( $question, array $snapshot, $today = '' ) {
		$today = $today ? $today : gmdate( 'Y-m-d' );
		$q     = strtolower( (string) $question );
		$fmt   = static function ( $d ) {
			return date_i18n( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) );
		};
		$when  = static function ( $p ) use ( $fmt ) {
			if ( ! $p['reorder_date'] || in_array( $p['urgency'], array( 'now', 'out' ), true ) ) {
				return __( 'now', 'dev3-ai-shared-stock-for-woocommerce' );
			}
			/* translators: %s: date */
			return sprintf( __( 'by %s', 'dev3-ai-shared-stock-for-woocommerce' ), $fmt( $p['reorder_date'] ) );
		};
		$name  = static function ( $p ) {
			return $p['name'];
		};
		$ids   = static function ( $items ) {
			return array_map(
				static function ( $p ) {
					return $p['id'];
				},
				$items
			);
		};
		if ( ! $snapshot ) {
			return array(
				'answer'   => __( 'You have no active stock pools yet. Create one, or use "Set up pools for me" to get started.', 'dev3-ai-shared-stock-for-woocommerce' ),
				'pool_ids' => array(),
				'source'   => 'rules',
			);
		}
		$target = self::target_date( $q, $today );
		if ( $target && ( preg_match( '/run\s*out|stock\s*out|sell\s*out|out of stock|last|enough|short/', $q ) || ! preg_match( '/reorder|best|top|slow/', $q ) ) ) {
			$hits = array_values(
				array_filter(
					$snapshot,
					static function ( $p ) use ( $target ) {
						return null !== $p['stockout_date'] && $p['stockout_date'] <= $target['date'];
					}
				)
			);
			if ( ! $hits ) {
				return array(
					/* translators: 1: event label, 2: date */
					'answer'   => sprintf( __( 'Good news: at the current pace nothing runs out before %1$s (%2$s).', 'dev3-ai-shared-stock-for-woocommerce' ), $target['label'], $fmt( $target['date'] ) ),
					'pool_ids' => array(),
					'source'   => 'rules',
				);
			}
			$lines = array();
			foreach ( array_slice( $hits, 0, 10 ) as $p ) {
				$lines[] = $p['stock'] <= 0
					/* translators: %s: pool name */
					? sprintf( __( '%s (already out of stock)', 'dev3-ai-shared-stock-for-woocommerce' ), $p['name'] )
					/* translators: 1: pool, 2: date, 3: qty, 4: unit, 5: reorder date */
					: sprintf( __( '%1$s, around %2$s (reorder about %3$s %4$s %5$s)', 'dev3-ai-shared-stock-for-woocommerce' ), $p['name'], $fmt( $p['stockout_date'] ), number_format_i18n( $p['reorder_qty'] ), $p['unit'], $when( $p ) );
			}
			return array(
				/* translators: 1: count, 2: event, 3: date, 4: list */
				'answer'   => sprintf( _n( '%1$d pool will run out before %2$s (%3$s): %4$s', '%1$d pools will run out before %2$s (%3$s): %4$s', count( $hits ), 'dev3-ai-shared-stock-for-woocommerce' ), count( $hits ), $target['label'], $fmt( $target['date'] ), "\n• " . implode( "\n• ", $lines ) ),
				'pool_ids' => $ids( array_slice( $hits, 0, 10 ) ),
				'source'   => 'rules',
			);
		}
		if ( preg_match( '/reorder|order more|restock|re-stock|buy|purchase/', $q ) ) {
			$hits = array_values(
				array_filter(
					$snapshot,
					static function ( $p ) {
						return in_array( $p['urgency'], array( 'out', 'now', 'soon' ), true );
					}
				)
			);
			if ( ! $hits ) {
				return array(
					'answer'   => __( 'Nothing needs reordering in the next week.', 'dev3-ai-shared-stock-for-woocommerce' ),
					'pool_ids' => array(),
					'source'   => 'rules',
				);
			}
			$lines = array();
			foreach ( array_slice( $hits, 0, 10 ) as $p ) {
				/* translators: 1: pool, 2: qty, 3: unit, 4: date */
				$lines[] = sprintf( __( '%1$s: about %2$s %3$s %4$s', 'dev3-ai-shared-stock-for-woocommerce' ), $p['name'], number_format_i18n( $p['reorder_qty'] ), $p['unit'], $when( $p ) );
			}
			return array(
				/* translators: %s: list */
				'answer'   => sprintf( __( 'Reorder these soon: %s', 'dev3-ai-shared-stock-for-woocommerce' ), "\n• " . implode( "\n• ", $lines ) ),
				'pool_ids' => $ids( array_slice( $hits, 0, 10 ) ),
				'source'   => 'rules',
			);
		}
		if ( preg_match( '/best|fastest|top|most|popular|selling/', $q ) && ! preg_match( '/slow|least|not selling/', $q ) ) {
			$list = $snapshot;
			usort(
				$list,
				static function ( $a, $b ) {
					return $b['per_day'] <=> $a['per_day'];
				}
			);
			$list  = array_slice( array_filter( $list, static fn( $p ) => $p['per_day'] > 0 ), 0, 5 );
			$lines = array();
			foreach ( $list as $p ) {
				/* translators: 1: pool, 2: per day, 3: unit */
				$lines[] = sprintf( __( '%1$s (%2$s %3$s a day)', 'dev3-ai-shared-stock-for-woocommerce' ), $p['name'], $p['per_day'], $p['unit'] );
			}
			return array(
				'answer'   => $lines
					/* translators: %s: list */
					? sprintf( __( 'Your fastest-moving pools: %s.', 'dev3-ai-shared-stock-for-woocommerce' ), implode( ', ', $lines ) )
					: __( 'There are no sales in the forecast window yet.', 'dev3-ai-shared-stock-for-woocommerce' ),
				'pool_ids' => $ids( $list ),
				'source'   => 'rules',
			);
		}
		if ( preg_match( '/slow|least|not selling|dead|idle|overstock/', $q ) ) {
			$list = array_values( array_filter( $snapshot, static fn( $p ) => $p['per_day'] <= 0 && $p['stock'] > 0 ) );
			return array(
				'answer'   => $list
					/* translators: %s: list */
					? sprintf( __( 'No sales lately for: %s. Consider a promotion or a smaller reorder.', 'dev3-ai-shared-stock-for-woocommerce' ), implode( ', ', array_map( $name, array_slice( $list, 0, 10 ) ) ) )
					: __( 'Every pool with stock has sold something recently.', 'dev3-ai-shared-stock-for-woocommerce' ),
				'pool_ids' => $ids( array_slice( $list, 0, 10 ) ),
				'source'   => 'rules',
			);
		}
		if ( preg_match( '/low|running out|out of stock|empty/', $q ) ) {
			$list = array_values( array_filter( $snapshot, static fn( $p ) => $p['stock'] <= $p['low_threshold'] ) );
			return array(
				'answer'   => $list
					/* translators: %s: list */
					? sprintf( __( 'At or below the low-stock threshold: %s.', 'dev3-ai-shared-stock-for-woocommerce' ), implode( ', ', array_map( static fn( $p ) => $p['name'] . ' (' . $p['stock'] . ' ' . $p['unit'] . ')', array_slice( $list, 0, 10 ) ) ) )
					: __( 'No pool is below its low-stock threshold.', 'dev3-ai-shared-stock-for-woocommerce' ),
				'pool_ids' => $ids( array_slice( $list, 0, 10 ) ),
				'source'   => 'rules',
			);
		}
		foreach ( $snapshot as $p ) {
			if ( '' !== $p['name'] && false !== strpos( $q, strtolower( $p['name'] ) ) ) {
				$pool = Pools::get( $p['id'] );
				return array(
					'answer'   => $pool && $pool['forecast'] ? Forecast::explain( $pool['forecast'], $pool ) : $p['name'],
					'pool_ids' => array( $p['id'] ),
					'source'   => 'rules',
				);
			}
		}
		$now  = count( array_filter( $snapshot, static fn( $p ) => in_array( $p['urgency'], array( 'out', 'now' ), true ) ) );
		$soon = count( array_filter( $snapshot, static fn( $p ) => 'soon' === $p['urgency'] ) );
		return array(
			/* translators: 1: pools, 2: reorder now, 3: soon, 4: first pool */
			'answer'   => sprintf( __( 'You have %1$d active pools. %2$d need a reorder now and %3$d within a week. The first to run out is %4$s. Try asking "what runs out before Black Friday?" or "what should I reorder?".', 'dev3-ai-shared-stock-for-woocommerce' ), count( $snapshot ), $now, $soon, $snapshot[0]['name'] ),
			'pool_ids' => array( $snapshot[0]['id'] ),
			'source'   => 'rules',
		);
	}
}
