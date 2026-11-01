<?php
/**
 * DEV3 AI features: forecast explanations, PO email drafts, pool setup, anomaly review, inventory Q&A.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Insights;

use Dev3\AiSharedStock\Core3\Api\Api_Error;
use Dev3\AiSharedStock\Core3\Api\Prompts;
use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every feature has a non-AI path; AI output that would change data is returned as a draft.
 */
class Ai_Features {

	/**
	 * Registers the prompt templates (filterable via dev3ss_prompt_<id>).
	 */
	public static function register_prompts() {
		$str = static function ( $max ) {
			return array(
				'type'      => 'STRING',
				'maxLength' => $max,
			);
		};
		Prompts::register(
			'forecast_explain',
			array(
				'version'  => '1.0',
				'task'     => 'forecast_explain',
				'system'   => 'You are an inventory analyst for an online shop. Explain a stock forecast to a busy shop owner in plain, friendly words. Use only the numbers given; never invent sales figures. No markdown.',
				'template' => "Pool: {{name}} (unit: {{unit}}). Today: {{today}}.\nForecast statistics (JSON): {{stats}}\nRule-based summary: {{summary}}\n\nWrite: summary = 2-4 short sentences on the trend, when stock runs out and what to do. risk = low|medium|high. tips = up to 3 short practical tips (reorder timing, buffer, promotion).",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary' => $str( 700 ),
						'risk'    => array(
							'type' => 'STRING',
							'enum' => array( 'low', 'medium', 'high' ),
						),
						'tips'    => array(
							'type'     => 'ARRAY',
							'items'    => $str( 160 ),
							'maxItems' => 3,
						),
					),
					'required'   => array( 'summary', 'risk' ),
				),
				'profile'  => 'fast',
				'cache'    => 12 * HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			'po_email',
			array(
				'version'  => '1.0',
				'task'     => 'po_email',
				'system'   => 'You write clear, polite purchase-order emails from a shop to its supplier. Plain text only, no markdown, no placeholders in brackets. Keep it under 180 words.',
				'template' => "Shop: {{company}}\nSupplier: {{supplier}}\nItem: {{name}} (SKU {{sku}}), unit: {{unit}}\nQuantity to order: {{qty}}\nNeeded by: {{date}}\nUnit cost on file: {{cost}}\nSignature:\n{{signature}}\nContext: {{context}}\n\nWrite the email subject and body. Ask the supplier to confirm the quantity, price and delivery date.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'subject' => $str( 150 ),
						'body'    => $str( 2500 ),
					),
					'required'   => array( 'subject', 'body' ),
				),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'setup_pools',
			array(
				'version'         => '1.0',
				'task'            => 'setup_pools',
				'system'          => 'You are a WooCommerce inventory consultant. Propose shared stock pools: groups of products or variations that consume the same physical stock (pack sizes of one item, bundles made from the same parts, variations cut from one material). Use only product ids, attribute keys and values present in the data. qty = units of the pool one sale uses (a 6-pack uses 6). Only propose pools that clearly make sense; never put unrelated products together.',
				'template'        => "Existing pools (do not repeat): {{existing}}\nCatalogue (JSON; variations have their own ids): {{catalogue}}\n\nReturn up to 12 pools. For an attribute rule use product_id 0 (store-wide) or the parent id, plus attr_key and attr_value. stock = a sensible opening stock in pool units from the current product stock.",
				'schema'          => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'pools' => array(
							'type'     => 'ARRAY',
							'maxItems' => 12,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'name'   => $str( 120 ),
									'unit'   => $str( 30 ),
									'reason' => $str( 300 ),
									'stock'  => array( 'type' => 'NUMBER' ),
									'links'  => array(
										'type'     => 'ARRAY',
										'maxItems' => 40,
										'items'    => array(
											'type'       => 'OBJECT',
											'properties' => array(
												'product_id' => array( 'type' => 'INTEGER' ),
												'qty'      => array( 'type' => 'NUMBER' ),
												'attr_key' => $str( 100 ),
												'attr_value' => $str( 190 ),
											),
											'required'   => array( 'product_id', 'qty' ),
										),
									),
								),
								'required'   => array( 'name', 'links' ),
							),
						),
					),
					'required'   => array( 'pools' ),
				),
				'profile'         => 'heavy',
				'thinking_budget' => 2048,
				'timeout'         => 150,
				'max_tokens'      => 8192,
			)
		);
		Prompts::register(
			'anomaly_explain',
			array(
				'version'  => '1.0',
				'task'     => 'anomaly_explain',
				'system'   => 'You review stock anomalies for a shop owner. Say what most likely happened (a typo, a stock count, a bulk order, theft or damage, a sync error) and what to check. Plain words, no markdown. Only suggest a corrected stock level when the data clearly points to a typo; otherwise suggested_stock is -1.',
				'template' => "Pool: {{name}} (unit {{unit}}), current stock {{stock}}.\nAlert: {{message}}\nDetails (JSON): {{detail}}\nRecent stock log, newest first (JSON): {{log}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'likely_cause'    => $str( 160 ),
						'explanation'     => $str( 600 ),
						'suggested_stock' => array( 'type' => 'NUMBER' ),
						'confidence'      => array(
							'type' => 'STRING',
							'enum' => array( 'low', 'medium', 'high' ),
						),
					),
					'required'   => array( 'likely_cause', 'explanation', 'suggested_stock', 'confidence' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			'ask_inventory',
			array(
				'version'  => '1.0',
				'task'     => 'ask_inventory',
				'system'   => 'You answer questions about a shop\'s inventory using only the JSON data given (pools with stock, daily usage, forecast stockout and reorder dates, incoming purchase orders) and the event dates. Be concise and specific: name pools, dates and quantities. If the data cannot answer, say so. Plain text, no markdown tables.',
				'template' => "Today: {{today}}\nUpcoming retail events: {{events}}\nPools (JSON, sorted by days left): {{pools}}\nEarlier in this chat: {{history}}\n\nQuestion: {{question}}\n\nReturn answer, pool_ids (ids of pools you mention) and up to 3 short follow_up questions.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'answer'    => $str( 1500 ),
						'pool_ids'  => array(
							'type'     => 'ARRAY',
							'items'    => array( 'type' => 'INTEGER' ),
							'maxItems' => 20,
						),
						'follow_up' => array(
							'type'     => 'ARRAY',
							'items'    => $str( 120 ),
							'maxItems' => 3,
						),
					),
					'required'   => array( 'answer' ),
				),
				'profile'  => 'fast',
				'cache'    => 10 * MINUTE_IN_SECONDS,
			)
		);
	}

	/**
	 * Error when a feature toggle is off.
	 *
	 * @param string $feature Toggle key.
	 * @return \WP_Error|null
	 */
	private static function feature_off( $feature ) {
		if ( Settings::ai_feature( $feature ) ) {
			return null;
		}
		return Api_Error::make( 'dev3ss_feature_off', __( 'This DEV3 AI feature is turned off in Settings.', 'dev3-ai-shared-stock-for-woocommerce' ), __( 'Turn it on under Settings > DEV3 AI features, or use the non-AI option.', 'dev3-ai-shared-stock-for-woocommerce' ), 400 );
	}

	/**
	 * Explains a pool forecast and stores the explanation with the forecast.
	 *
	 * @param int    $pool_id Pool id.
	 * @param string $context admin|cron.
	 * @return array|\WP_Error Forecast with `ai`.
	 */
	public static function explain_forecast( $pool_id, $context = 'admin' ) {
		$off = self::feature_off( 'ai_forecast_explain' );
		if ( $off ) {
			return $off;
		}
		$pool = Pools::get( $pool_id, true );
		if ( ! $pool ) {
			return Pools::missing();
		}
		$f = $pool['forecast'] && ( $pool['forecast']['today'] ?? '' ) === gmdate( 'Y-m-d' ) ? $pool['forecast'] : Forecast::refresh( $pool_id );
		if ( ! empty( $f['ai'] ) && ( $f['ai']['hash'] ?? '' ) === $f['hash'] ) {
			return $f;
		}
		$stats = $f;
		unset( $stats['explanation'], $stats['ai'], $stats['hash'], $stats['computed_at'], $stats['pool_id'] );
		$result = Prompts::run(
			'forecast_explain',
			array(
				'name'    => $pool['name'],
				'unit'    => $pool['unit'] ? $pool['unit'] : 'units',
				'today'   => $f['today'],
				'stats'   => $stats,
				'summary' => $f['explanation'],
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data    = (array) ( $result['data'] ?? array() );
		$f['ai'] = array(
			'summary' => sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ),
			'risk'    => in_array( $data['risk'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $data['risk'] : 'medium',
			'tips'    => array_slice( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $data['tips'] ?? array() ) ) ), 0, 3 ),
			'hash'    => $f['hash'],
			'model'   => (string) $result['model'],
			'tokens'  => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'  => ! empty( $result['cached'] ),
			'at'      => Pools::now(),
		);
		Pools::save_forecast( $pool_id, $f );
		return $f;
	}

	/**
	 * Template purchase-order email (no AI).
	 *
	 * @param array  $pool Pool.
	 * @param float  $qty  Quantity.
	 * @param string $date Needed by (Y-m-d).
	 * @return array { subject, body }
	 */
	public static function template_po( array $pool, $qty, $date ) {
		$company   = (string) Settings::get( 'po_company', '' );
		$company   = '' !== $company ? $company : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$signature = (string) Settings::get( 'po_signature', '' );
		$greeting  = '' !== $pool['supplier_name']
			/* translators: %s: supplier name */
			? sprintf( __( 'Hello %s,', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['supplier_name'] )
			: __( 'Hello,', 'dev3-ai-shared-stock-for-woocommerce' );
		$item  = $pool['name'] . ( '' !== $pool['sku'] ? ' (SKU ' . $pool['sku'] . ')' : '' );
		$lines = array(
			$greeting,
			'',
			/* translators: 1: company */
			sprintf( __( 'We would like to place the following order for %1$s:', 'dev3-ai-shared-stock-for-woocommerce' ), $company ),
			'',
			/* translators: 1: item, 2: quantity, 3: unit */
			sprintf( __( '- %1$s: %2$s %3$s', 'dev3-ai-shared-stock-for-woocommerce' ), $item, wc_format_decimal( $qty, 4, true ), $pool['unit'] ),
		);
		if ( $date ) {
			/* translators: %s: date */
			$lines[] = sprintf( __( '- Needed by: %s', 'dev3-ai-shared-stock-for-woocommerce' ), date_i18n( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ) );
		}
		if ( $pool['unit_cost'] > 0 ) {
			/* translators: %s: unit cost */
			$lines[] = sprintf( __( '- Unit price on file: %s', 'dev3-ai-shared-stock-for-woocommerce' ), wp_strip_all_tags( wc_price( $pool['unit_cost'] ) ) );
		}
		$lines[] = '';
		$lines[] = __( 'Please confirm the quantity, price and delivery date.', 'dev3-ai-shared-stock-for-woocommerce' );
		$lines[] = '';
		$lines[] = __( 'Thank you,', 'dev3-ai-shared-stock-for-woocommerce' );
		$lines[] = '' !== $signature ? $signature : $company;
		return array(
			/* translators: 1: company, 2: item */
			'subject' => sprintf( __( 'Purchase order from %1$s: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $company, $pool['name'] ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Drafts a purchase-order email (AI or template). Nothing is saved.
	 *
	 * @param int        $pool_id Pool id.
	 * @param float|null $qty     Quantity (null = forecast suggestion).
	 * @param string     $date    Needed by (blank = forecast reorder date + lead time).
	 * @param bool       $use_ai  Use DEV3 AI.
	 * @return array|\WP_Error Draft.
	 */
	public static function po_draft( $pool_id, $qty = null, $date = '', $use_ai = true ) {
		$pool = Pools::get( $pool_id, true );
		if ( ! $pool ) {
			return Pools::missing();
		}
		$f = $pool['forecast'] ? $pool['forecast'] : Forecast::refresh( $pool_id );
		if ( null === $qty || $qty <= 0 ) {
			$qty = max( 1, (int) ( $f['reorder_qty'] ?? 0 ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			$date = Forecast::add_days( gmdate( 'Y-m-d' ), max( 1, (int) $pool['lead_time_days'] ) );
		}
		$base = array(
			'pool_id'        => $pool['id'],
			'qty'            => $qty,
			'expected_date'  => $date,
			'supplier_name'  => $pool['supplier_name'],
			'supplier_email' => $pool['supplier_email'],
			'unit_cost'      => $pool['unit_cost'],
		);
		if ( ! $use_ai ) {
			return $base + self::template_po( $pool, $qty, $date ) + array( 'source' => 'template' );
		}
		$off = self::feature_off( 'ai_po_emails' );
		if ( $off ) {
			return $off;
		}
		$company = (string) Settings::get( 'po_company', '' );
		$result  = Prompts::run(
			'po_email',
			array(
				'company'   => '' !== $company ? $company : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'supplier'  => '' !== $pool['supplier_name'] ? $pool['supplier_name'] : 'our supplier',
				'name'      => $pool['name'],
				'sku'       => '' !== $pool['sku'] ? $pool['sku'] : 'n/a',
				'unit'      => '' !== $pool['unit'] ? $pool['unit'] : 'units',
				'qty'       => $qty,
				'date'      => $date,
				'cost'      => $pool['unit_cost'] > 0 ? wp_strip_all_tags( wc_price( $pool['unit_cost'] ) ) : 'not set',
				'signature' => (string) Settings::get( 'po_signature', '' ),
				'context'   => $f ? sprintf( 'Selling about %s per day; current stock %s.', $f['rate_per_day'], $pool['stock'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $base + array(
			'subject' => mb_substr( sanitize_text_field( (string) ( $result['data']['subject'] ?? '' ) ), 0, 255 ),
			'body'    => mb_substr( sanitize_textarea_field( (string) ( $result['data']['body'] ?? '' ) ), 0, 6000 ),
			'source'  => 'ai',
			'model'   => (string) $result['model'],
			'tokens'  => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'  => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Proposes pools (rules always; DEV3 AI on request).
	 *
	 * @param bool $use_ai Use DEV3 AI.
	 * @return array|\WP_Error { proposals, ai_error?, model?, tokens? }
	 */
	public static function setup_suggest( $use_ai ) {
		$snapshot = Setup_Advisor::snapshot();
		$rules    = Setup_Advisor::rule_proposals( $snapshot );
		if ( ! $use_ai ) {
			return array(
				'proposals' => $rules,
				'products'  => count( $snapshot ),
			);
		}
		$off = self::feature_off( 'ai_setup' );
		if ( $off ) {
			return $off;
		}
		if ( ! $snapshot ) {
			return array(
				'proposals' => array(),
				'products'  => 0,
			);
		}
		$existing = array_map(
			static function ( $p ) {
				return $p['name'];
			},
			Pools::all()
		);
		$result   = Prompts::run(
			'setup_pools',
			array(
				'existing'  => $existing,
				'catalogue' => $snapshot,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ai = array();
		foreach ( (array) ( $result['data']['pools'] ?? array() ) as $p ) {
			$p['source'] = 'ai';
			$ai[]        = $p;
		}
		$ai = Setup_Advisor::validate( $ai, $snapshot );
		// Rule proposals the AI did not already cover (by name) are kept after the AI ones.
		$names = array_map( 'strtolower', wp_list_pluck( $ai, 'name' ) );
		foreach ( $rules as $r ) {
			if ( ! in_array( strtolower( $r['name'] ), $names, true ) ) {
				$ai[] = $r;
			}
		}
		return array(
			'proposals' => array_slice( $ai, 0, 30 ),
			'products'  => count( $snapshot ),
			'model'     => (string) $result['model'],
			'tokens'    => (int) ( $result['usage']['total'] ?? 0 ),
		);
	}

	/**
	 * Explains an anomaly alert and proposes a correction (draft, not applied).
	 *
	 * @param int $alert_id Alert id.
	 * @return array|\WP_Error Alert with detail.ai.
	 */
	public static function explain_anomaly( $alert_id ) {
		$off = self::feature_off( 'ai_anomalies' );
		if ( $off ) {
			return $off;
		}
		$alert = Alerts::get( $alert_id );
		if ( ! $alert ) {
			return new \WP_Error( 'dev3ss_alert_missing', __( 'That alert no longer exists.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$pool = Pools::get( $alert['pool_id'], true );
		if ( ! $pool ) {
			return Pools::missing();
		}
		$log    = array_map(
			static function ( $r ) {
				return array(
					'at'     => $r['created_at'],
					'change' => $r['delta'],
					'after'  => $r['stock_after'],
					'reason' => $r['reason'],
					'note'   => $r['note'],
				);
			},
			\Dev3\AiSharedStock\Data\Log::query(
				array(
					'pool_id'  => $pool['id'],
					'per_page' => 15,
				)
			)['rows']
		);
		$detail = $alert['detail'];
		unset( $detail['ai'] );
		$result = Prompts::run(
			'anomaly_explain',
			array(
				'name'    => $pool['name'],
				'unit'    => $pool['unit'],
				'stock'   => $pool['stock'],
				'message' => $alert['message'],
				'detail'  => $detail,
				'log'     => $log,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data      = (array) ( $result['data'] ?? array() );
		$suggested = isset( $data['suggested_stock'] ) && is_numeric( $data['suggested_stock'] ) && (float) $data['suggested_stock'] >= 0 ? round( (float) $data['suggested_stock'], 4 ) : null;
		Alerts::update_detail(
			$alert_id,
			array(
				'ai' => array(
					'likely_cause'    => sanitize_text_field( (string) ( $data['likely_cause'] ?? '' ) ),
					'explanation'     => sanitize_textarea_field( (string) ( $data['explanation'] ?? '' ) ),
					'suggested_stock' => $suggested,
					'confidence'      => in_array( $data['confidence'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $data['confidence'] : 'low',
					'model'           => (string) $result['model'],
					'tokens'          => (int) ( $result['usage']['total'] ?? 0 ),
				),
			)
		);
		return Alerts::get( $alert_id );
	}

	/**
	 * Answers an inventory question (DEV3 AI, falling back to rules).
	 *
	 * @param string $question Question.
	 * @param array  $history  Earlier turns [{ role, text }].
	 * @param bool   $use_ai   Use DEV3 AI.
	 * @return array { answer, pool_ids, follow_up, source, notice? }
	 */
	public static function ask( $question, array $history = array(), $use_ai = true ) {
		$today    = gmdate( 'Y-m-d' );
		$snapshot = Ask::snapshot();
		$rules    = static function ( $notice = '' ) use ( $question, $snapshot, $today ) {
			$a              = Ask::answer( $question, $snapshot, $today );
			$a['follow_up'] = array(
				__( 'What will run out before Black Friday?', 'dev3-ai-shared-stock-for-woocommerce' ),
				__( 'What should I reorder this week?', 'dev3-ai-shared-stock-for-woocommerce' ),
				__( 'Which pools sell fastest?', 'dev3-ai-shared-stock-for-woocommerce' ),
			);
			if ( $notice ) {
				$a['notice'] = $notice;
			}
			return $a;
		};
		if ( ! $use_ai || ! Settings::ai_feature( 'ai_ask' ) || '' === \Dev3\AiSharedStock\Core3\Core\Settings_Store::api_key() ) {
			return self::with_names( $rules(), $snapshot );
		}
		$turns = array();
		foreach ( array_slice( $history, -6 ) as $turn ) {
			$turns[] = array(
				'role' => 'assistant' === ( $turn['role'] ?? '' ) ? 'assistant' : 'user',
				'text' => mb_substr( sanitize_textarea_field( (string) ( $turn['text'] ?? '' ) ), 0, 600 ),
			);
		}
		$events = array();
		foreach ( Ask::events( $today ) as $e ) {
			$events[ $e['label'] ] = $e['date'];
		}
		$result = Prompts::run(
			'ask_inventory',
			array(
				'today'    => $today,
				'events'   => $events,
				'pools'    => $snapshot,
				'history'  => $turns,
				'question' => $question,
			)
		);
		if ( is_wp_error( $result ) ) {
			return self::with_names( $rules( $result->get_error_message() ), $snapshot );
		}
		$valid = wp_list_pluck( $snapshot, 'id' );
		$data  = (array) ( $result['data'] ?? array() );
		return self::with_names(
			array(
				'answer'    => sanitize_textarea_field( (string) ( $data['answer'] ?? '' ) ),
				'pool_ids'  => array_values( array_intersect( array_map( 'intval', (array) ( $data['pool_ids'] ?? array() ) ), $valid ) ),
				'follow_up' => array_slice( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $data['follow_up'] ?? array() ) ) ), 0, 3 ),
				'source'    => 'ai',
				'model'     => (string) $result['model'],
				'tokens'    => (int) ( $result['usage']['total'] ?? 0 ),
				'cached'    => ! empty( $result['cached'] ),
			),
			$snapshot
		);
	}

	/**
	 * Adds { id, name } for each referenced pool (for links in the chat).
	 *
	 * @param array   $answer   Answer.
	 * @param array[] $snapshot Snapshot.
	 * @return array
	 */
	private static function with_names( array $answer, array $snapshot ) {
		$names           = wp_list_pluck( $snapshot, 'name', 'id' );
		$answer['pools'] = array();
		foreach ( (array) ( $answer['pool_ids'] ?? array() ) as $id ) {
			if ( isset( $names[ $id ] ) ) {
				$answer['pools'][] = array(
					'id'   => (int) $id,
					'name' => $names[ $id ],
				);
			}
		}
		return $answer;
	}
}
