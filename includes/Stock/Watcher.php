<?php
/**
 * Low-stock / out-of-stock alerts and emails, plus anomaly checks on manual changes.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Stock;

use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Insights\Anomalies;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reacts to every pool stock change.
 */
class Watcher {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'dev3ss_stock_changed', array( __CLASS__, 'on_change' ), 10, 5 );
	}

	/**
	 * Raises/clears alerts when stock crosses the low or zero line.
	 *
	 * @param int    $pool_id Pool id.
	 * @param float  $delta   Change.
	 * @param float  $after   Stock after.
	 * @param string $reason  Reason.
	 * @param array  $ctx     Context.
	 */
	public static function on_change( $pool_id, $delta, $after, $reason, $ctx ) {
		$pool = Pools::get( $pool_id );
		if ( ! $pool ) {
			return;
		}
		$before    = (float) $after - (float) $delta;
		$threshold = (float) $pool['low_threshold'];
		$unit      = '' !== $pool['unit'] ? ' ' . $pool['unit'] : '';
		if ( $after <= 0 && $before > 0 ) {
			Alerts::resolve( $pool_id, 'low_stock' );
			/* translators: 1: pool name */
			$msg = sprintf( __( '%1$s is out of stock.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'] );
			Alerts::add( $pool_id, 'out_of_stock', 'danger', $msg, array( 'stock' => $after ) );
			self::email( $pool, 'out_of_stock', $after );
		} elseif ( $after > 0 && $after <= $threshold && $before > $threshold ) {
			/* translators: 1: pool name, 2: stock, 3: unit */
			$msg = sprintf( __( '%1$s is low: %2$s%3$s left.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], wc_format_decimal( $after, 4, true ), $unit );
			Alerts::add( $pool_id, 'low_stock', 'warning', $msg, array( 'stock' => $after ) );
			self::email( $pool, 'low_stock', $after );
		}
		if ( $after > 0 ) {
			Alerts::resolve( $pool_id, 'out_of_stock' );
		}
		if ( $after > $threshold ) {
			Alerts::resolve( $pool_id, 'low_stock' );
		}
		if ( empty( $ctx['skip_anomaly'] ) && Settings::get( 'anomaly_detection', true ) && in_array( $reason, array( 'manual', 'import' ), true ) ) {
			Anomalies::check_change( $pool, (float) $delta, (float) $after, $reason, (array) $ctx );
		}
	}

	/**
	 * Sends a stock email to the alert recipients.
	 *
	 * @param array  $pool  Pool.
	 * @param string $type  low_stock|out_of_stock.
	 * @param float  $stock Stock.
	 * @return bool
	 */
	public static function email( array $pool, $type, $stock ) {
		if ( ! Settings::get( 'alerts_email', true ) ) {
			return false;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = 'out_of_stock' === $type
			/* translators: 1: site name, 2: pool name */
			? sprintf( __( '[%1$s] Out of stock: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $site, $pool['name'] )
			/* translators: 1: site name, 2: pool name */
			: sprintf( __( '[%1$s] Low stock: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $site, $pool['name'] );
		$lines = array(
			/* translators: 1: pool name, 2: stock, 3: unit */
			sprintf( __( 'The shared stock pool "%1$s" now has %2$s %3$s.', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], wc_format_decimal( $stock, 4, true ), $pool['unit'] ),
			/* translators: %s: threshold */
			sprintf( __( 'Low-stock threshold: %s', 'dev3-ai-shared-stock-for-woocommerce' ), wc_format_decimal( $pool['low_threshold'], 4, true ) ),
			'',
			admin_url( 'admin.php?page=' . DEV3SS_SLUG . '#/pools/' . $pool['id'] ),
		);
		/**
		 * Filters the stock alert email.
		 *
		 * @param array  $mail { to, subject, body }.
		 * @param array  $pool Pool.
		 * @param string $type Type.
		 */
		$mail = apply_filters(
			'dev3ss_alert_email',
			array(
				'to'      => Settings::recipient(),
				'subject' => $subject,
				'body'    => implode( "\n", $lines ),
			),
			$pool,
			$type
		);
		return $mail ? (bool) wp_mail( $mail['to'], $mail['subject'], $mail['body'] ) : false;
	}
}
