<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock;

use Dev3\AiSharedStock\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3ss_settings_defaults / dev3ss_settings_sanitize.
 */
class Settings {

	/**
	 * Boolean keys.
	 */
	const FLAGS = array(
		'inventory_enabled',
		'mirror_stock',
		'cart_guard',
		'alerts_email',
		'anomaly_detection',
		'ai_forecast_explain',
		'ai_po_emails',
		'ai_setup',
		'ai_anomalies',
		'ai_ask',
	);

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'inventory_enabled'   => true,
				'mirror_stock'        => true,
				'cart_guard'          => true,
				'bundle_mode'         => 'both',
				'default_threshold'   => 5,
				'default_lead_time'   => 7,
				'alerts_email'        => true,
				'alert_recipient'     => '',
				'anomaly_detection'   => true,
				'anomaly_sensitivity' => 'medium',
				'forecast_window'     => 90,
				'safety_days'         => 7,
				'cover_days'          => 30,
				'ai_forecast_explain' => true,
				'ai_po_emails'        => true,
				'ai_setup'            => true,
				'ai_anomalies'        => true,
				'ai_ask'              => true,
				'po_company'          => '',
				'po_signature'        => '',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['bundle_mode'] ) && in_array( $input['bundle_mode'], array( 'both', 'children', 'container' ), true ) ) {
			$out['bundle_mode'] = $input['bundle_mode'];
		}
		if ( isset( $input['anomaly_sensitivity'] ) && in_array( $input['anomaly_sensitivity'], array( 'low', 'medium', 'high' ), true ) ) {
			$out['anomaly_sensitivity'] = $input['anomaly_sensitivity'];
		}
		$ints = array(
			'default_threshold' => array( 0, 100000 ),
			'default_lead_time' => array( 0, 365 ),
			'forecast_window'   => array( 14, 365 ),
			'safety_days'       => array( 0, 90 ),
			'cover_days'        => array( 1, 365 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['alert_recipient'] ) ) {
			$emails                 = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $input['alert_recipient'] ) ) ) );
			$out['alert_recipient'] = implode( ', ', array_slice( $emails, 0, 5 ) );
		}
		if ( isset( $input['po_company'] ) ) {
			$out['po_company'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['po_company'] ) ), 0, 120 );
		}
		if ( isset( $input['po_signature'] ) ) {
			$out['po_signature'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['po_signature'] ) ), 0, 500 );
		}
		return $out;
	}

	/**
	 * Reads a plugin setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Whether an AI feature toggle is on (and AI itself is on).
	 *
	 * @param string $feature Toggle key (ai_setup, ai_ask...).
	 * @return bool
	 */
	public static function ai_feature( $feature ) {
		return (bool) self::get( $feature, true ) && (bool) self::get( 'ai_enabled', true );
	}

	/**
	 * Alert email recipients.
	 *
	 * @return string
	 */
	public static function recipient() {
		$to = (string) self::get( 'alert_recipient', '' );
		return '' !== $to ? $to : (string) get_option( 'admin_email' );
	}
}
