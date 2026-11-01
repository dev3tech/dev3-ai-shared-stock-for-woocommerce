<?php
/**
 * REST permissions, CRUD, cart guard, and every DEV3 AI feature with Gemini mocked.
 *
 * @package Dev3\AiSharedStock
 */

require_once __DIR__ . '/Dev3ss_Test_Case.php';

use Dev3\AiSharedStock\Core3\Core\Settings_Store;
use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Data\Purchase_Orders;
use Dev3\AiSharedStock\Stock\Cart_Guard;

class RestTest extends Dev3ss_Test_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_permissions() {
		$this->assertSame( 401, $this->rest( 'GET', 'pools' )->get_status(), 'logged out' );
		$this->login( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', 'pools' )->get_status() );
		$this->login( 'shop_manager' );
		$this->assertSame( 200, $this->rest( 'GET', 'pools' )->get_status(), 'shop managers manage stock' );
		$this->assertSame( 403, $this->rest( 'GET', 'pools', array(), false )->get_status(), 'nonce required' );
		$this->assertSame( 403, $this->rest( 'GET', 'settings' )->get_status(), 'API settings stay admin-only' );
	}

	public function test_pool_crud_links_adjust_and_history() {
		$this->login();
		$res = $this->rest(
			'POST',
			'pools',
			array(
				'name'           => 'Cola cans',
				'sku'            => 'CAN',
				'stock'          => 120,
				'unit'           => 'cans',
				'supplier_email' => 'orders@fizz.example',
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( 400, $this->rest( 'POST', 'pools', array( 'name' => 'Dup', 'sku' => 'CAN' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'pools', array( 'name' => '' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', 'pools', array( 'name' => 'X', 'supplier_email' => 'nope' ) )->get_status() );

		$six = $this->simple( 'Six', 'SIX', 5 );
		$res = $this->rest( 'POST', "pools/{$id}/links", array( 'product_id' => $six->get_id(), 'qty' => 6 ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertCount( 1, $res->get_data()['links'] );
		$this->assertSame( 20, $res->get_data()['products'][0]['stock'] );
		$this->assertSame( 400, $this->rest( 'POST', "pools/{$id}/links", array( 'product_id' => $six->get_id(), 'qty' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', "pools/{$id}/links", array( 'qty' => 1 ) )->get_status() );

		$res = $this->rest( 'POST', "pools/{$id}/adjust", array( 'mode' => 'remove', 'qty' => 12, 'note' => 'Damaged' ) );
		$this->assertSame( 108, $res->get_data()['stock'] );
		$this->assertSame( 18, wc_get_product( $six->get_id() )->get_stock_quantity() );
		$this->assertSame( 50, $this->rest( 'POST', "pools/{$id}/adjust", array( 'mode' => 'set', 'qty' => 50 ) )->get_data()['stock'] );
		$this->assertSame( 400, $this->rest( 'POST', "pools/{$id}/adjust", array( 'mode' => 'add', 'qty' => -1 ) )->get_status() );

		$hist = $this->rest( 'GET', 'history', array( 'pool_id' => $id ) )->get_data();
		$this->assertSame( 3, $hist['total'] );
		$this->assertSame( 'Damaged', $hist['rows'][1]['note'] );

		$upd = $this->rest( 'PUT', "pools/{$id}", array( 'name' => 'Cans', 'stock' => 9999 ) )->get_data();
		$this->assertSame( 'Cans', $upd['name'] );
		$this->assertSame( 50, $upd['stock'], 'stock cannot be overwritten via PUT' );

		$link_id = $upd['links'][0]['id'];
		$this->assertSame( 200, $this->rest( 'DELETE', "links/{$link_id}" )->get_status() );
		$this->assertSame( 200, $this->rest( 'DELETE', "pools/{$id}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "pools/{$id}" )->get_status() );
		$this->assertNotEmpty( Log::query( array( 'pool_id' => $id ) )['rows'], 'history is kept' );
	}

	public function test_dashboard_forecasts_export_import_routes() {
		$this->login();
		$id = $this->pool( 'Mugs', 40 );
		$d  = $this->rest( 'GET', 'dashboard' )->get_data();
		$this->assertSame( 1, $d['kpis']['active'] );
		$this->assertSame( 'Mugs', $d['at_risk'][0]['name'] );
		$f = $this->rest( 'GET', 'forecasts' )->get_data();
		$this->assertSame( 'idle', $f['pools'][0]['forecast']['urgency'] );
		$job = $this->rest( 'POST', 'forecasts/run' );
		$this->assertSame( 201, $job->get_status() );
		$csv = $this->rest( 'GET', 'export', array( 'type' => 'pools' ) )->get_data();
		$this->assertStringContainsString( 'Mugs', $csv['csv'] );
		$prev = $this->rest( 'POST', 'import', array( 'type' => 'pools', 'csv' => "name,stock\nPlates,9\n", 'dry_run' => true ) )->get_data();
		$this->assertSame( 1, $prev['created'] );
		$this->assertCount( 1, Pools::all() );
		unset( $id );
	}

	public function test_po_template_flow_send_and_receive() {
		$this->login();
		$id    = $this->pool( 'Cans', 10, array( 'supplier_email' => 'orders@fizz.example', 'supplier_name' => 'Fizz' ) );
		$draft = $this->rest( 'POST', "pools/{$id}/po-draft", array( 'qty' => 100, 'ai' => false ) )->get_data();
		$this->assertSame( 'template', $draft['source'] );
		$this->assertStringContainsString( 'Hello Fizz', $draft['body'] );
		$this->assertCount( 0, $this->requests, 'no AI call' );
		$this->assertCount( 0, Purchase_Orders::all(), 'drafts are not saved' );
		$po = $this->rest( 'POST', 'purchase-orders', array( 'pool_id' => $id, 'qty' => 100, 'subject' => $draft['subject'], 'body' => $draft['body'] ) )->get_data();
		$this->assertSame( 'draft', $po['status'] );
		reset_phpmailer_instance();
		$sent = $this->rest( 'POST', "purchase-orders/{$po['id']}/send" )->get_data();
		$this->assertSame( 'sent', $sent['status'] ?? wp_json_encode( $sent ) );
		$this->assertSame( 'orders@fizz.example', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		$this->assertSame( 100.0, (float) Purchase_Orders::incoming()[ $id ]['qty'] );
		$this->rest( 'POST', "purchase-orders/{$po['id']}/receive", array( 'qty' => 96 ) );
		$this->assertSame( 106.0, $this->stock( $id ) );
		$this->assertSame( 400, $this->rest( 'POST', "purchase-orders/{$po['id']}/receive" )->get_status(), 'closed' );
	}

	public function test_ai_features_need_a_key_and_respect_toggles() {
		$this->login();
		$id  = $this->pool( 'Cans', 10 );
		$res = $this->rest( 'POST', "pools/{$id}/explain" );
		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertCount( 0, $this->requests );
		$this->with_key();
		Settings_Store::update( array( 'ai_forecast_explain' => false ) );
		Settings_Store::flush();
		$this->assertSame( 'dev3ss_feature_off', $this->rest( 'POST', "pools/{$id}/explain" )->get_data()['code'] );
		Settings_Store::update( array( 'ai_ask' => false ) );
		Settings_Store::flush();
		$ask = $this->rest( 'POST', 'ask', array( 'question' => 'What should I reorder?' ) )->get_data();
		$this->assertSame( 'rules', $ask['source'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_ai_forecast_explanation_is_stored_with_the_forecast() {
		$this->login();
		$this->with_key();
		$id                = $this->pool( 'Cans', 30 );
		$this->ai_payloads = array(
			array(
				'summary' => 'You sell about 4 cans a day; reorder this week.',
				'risk'    => 'high',
				'tips'    => array( 'Order 120 cans' ),
			),
		);
		$f = $this->rest( 'POST', "pools/{$id}/explain" )->get_data();
		$this->assertSame( 'high', $f['ai']['risk'] );
		$this->assertStringContainsString( 'Cans', $this->last_prompt() );
		$this->assertStringContainsString( 'rate_per_day', $this->last_prompt() );
		$this->assertSame( 'high', Pools::get( $id, true )['forecast']['ai']['risk'] );
		$this->rest( 'POST', "pools/{$id}/explain" );
		$this->assertCount( 1, $this->requests, 'a valid explanation is reused' );
	}

	public function test_ai_po_draft_is_a_draft_only() {
		$this->login();
		$this->with_key();
		$id                = $this->pool( 'Cans', 10, array( 'sku' => 'CAN' ) );
		$this->ai_payloads = array(
			array(
				'subject' => 'Order: 120 cans',
				'body'    => 'Dear Fizz, please send 120 cans.',
			),
		);
		$d = $this->rest( 'POST', "pools/{$id}/po-draft", array( 'qty' => 120 ) )->get_data();
		$this->assertSame( 'ai', $d['source'] );
		$this->assertSame( 'Order: 120 cans', $d['subject'] );
		$this->assertStringContainsString( 'Quantity to order: 120', $this->last_prompt() );
		$this->assertCount( 0, Purchase_Orders::all() );
	}

	public function test_ai_setup_validates_proposals_and_apply_creates_them() {
		$this->login();
		$this->with_key();
		$a                 = $this->simple( 'Soap bar', 'S1', 2, array( 'stock' => 50 ) );
		$b                 = $this->simple( 'Soap 3-pack', 'S3', 5, array( 'stock' => 10 ) );
		$this->ai_payloads = array(
			array(
				'pools' => array(
					array(
						'name'   => 'Soap bars',
						'unit'   => 'bars',
						'reason' => 'Same bar in two pack sizes',
						'stock'  => 80,
						'links'  => array(
							array( 'product_id' => $a->get_id(), 'qty' => 1 ),
							array( 'product_id' => $b->get_id(), 'qty' => 3 ),
							array( 'product_id' => 987654, 'qty' => 1 ),
						),
					),
				),
			),
		);
		$res = $this->rest( 'POST', 'setup/suggest', array( 'ai' => true ) )->get_data();
		$this->assertSame( 'Soap bars', $res['proposals'][0]['name'] );
		$this->assertSame( 'ai', $res['proposals'][0]['source'] );
		$this->assertCount( 2, $res['proposals'][0]['links'], 'unknown product id dropped' );
		$this->assertStringContainsString( 'Soap 3-pack', $this->last_prompt() );
		$this->assertCount( 0, Pools::all(), 'nothing created before apply' );
		$apply = $this->rest( 'POST', 'setup/apply', array( 'proposals' => array( $res['proposals'][0] ) ) );
		$this->assertSame( 201, $apply->get_status() );
		$this->assertCount( 1, Pools::all() );
		$this->assertSame( 26, wc_get_product( $b->get_id() )->get_stock_quantity() );
		$rules = $this->rest( 'POST', 'setup/suggest', array( 'ai' => false ) )->get_data();
		$this->assertIsArray( $rules['proposals'] );
	}

	public function test_ai_anomaly_review_and_accepted_fix() {
		$this->login();
		$this->with_key();
		$id = $this->pool( 'Mugs', 12 );
		Pools::set_stock( $id, 120, 'manual' );
		$alert             = Alerts::query( 'open', 5, 'anomaly' )[0];
		$this->ai_payloads = array(
			array(
				'likely_cause'    => 'Typo: an extra zero',
				'explanation'     => 'The count jumped from 12 to 120 with no delivery.',
				'suggested_stock' => 12,
				'confidence'      => 'high',
			),
		);
		$res = $this->rest( 'POST', "alerts/{$alert['id']}/explain" )->get_data();
		$this->assertSame( 'Typo: an extra zero', $res['detail']['ai']['likely_cause'] );
		$this->assertSame( 120.0, $this->stock( $id ), 'not applied until accepted' );
		$fix = $this->rest( 'POST', "alerts/{$alert['id']}/fix", array( 'stock' => 12 ) )->get_data();
		$this->assertSame( 'dismissed', $fix['status'] );
		$this->assertSame( 12.0, $this->stock( $id ) );
		$this->assertCount( 0, Alerts::query( 'open', 5, 'anomaly' ), 'the correction is not flagged again' );
	}

	public function test_ai_ask_uses_snapshot_and_filters_pool_ids() {
		$this->login();
		$this->with_key();
		$id                = $this->pool( 'Cans', 30 );
		$this->ai_payloads = array(
			array(
				'answer'    => 'Cans run out first.',
				'pool_ids'  => array( $id, 99999 ),
				'follow_up' => array( 'When should I reorder?' ),
			),
		);
		$res = $this->rest(
			'POST',
			'ask',
			array(
				'question' => 'What runs out before Black Friday?',
				'history'  => array( array( 'role' => 'user', 'text' => 'hi' ) ),
			)
		)->get_data();
		$this->assertSame( 'ai', $res['source'] );
		$this->assertSame( array( $id ), $res['pool_ids'] );
		$this->assertStringContainsString( 'Black Friday', $this->last_prompt() );
		$this->assertStringContainsString( '"name":"Cans"', $this->last_prompt() );
		$this->assertSame( 400, $this->rest( 'POST', 'ask', array( 'question' => '  ' ) )->get_status() );
	}

	public function test_ask_falls_back_to_rules_when_gemini_fails() {
		$this->login();
		$this->with_key();
		$this->pool( 'Cans', 30 );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				return false !== strpos( $url, 'generativelanguage' ) ? new WP_Error( 'http_request_failed', 'down' ) : $pre;
			},
			5,
			3
		);
		$res = $this->rest( 'POST', 'ask', array( 'question' => 'What should I reorder?' ) )->get_data();
		$this->assertSame( 'rules', $res['source'] );
		$this->assertArrayHasKey( 'notice', $res );
	}

	public function test_cart_guard_sums_demand_across_products() {
		$pool = $this->pool( 'Cans', 10 );
		$can  = $this->simple( 'Can', 'C1', 1 );
		$six  = $this->simple( 'Six', 'C6', 5 );
		$this->link( $pool, $can->get_id(), 1 );
		$this->link( $pool, $six->get_id(), 6 );
		$items = array(
			'a' => array( 'product_id' => $six->get_id(), 'variation_id' => 0, 'quantity' => 1 ),
			'b' => array( 'product_id' => $can->get_id(), 'variation_id' => 0, 'quantity' => 4 ),
		);
		$this->assertSame( array( $pool => 10.0 ), Cart_Guard::demand( $items ) );
		$this->assertSame( array(), Cart_Guard::shortfalls( Cart_Guard::demand( $items ) ) );
		$items['b']['quantity'] = 5;
		$short                  = Cart_Guard::shortfalls( Cart_Guard::demand( $items ) );
		$this->assertEqualsWithDelta( 11.0, $short[ $pool ]['need'], 0.001 );
		Pools::update( $pool, array( 'backorders' => true ) );
		$this->assertSame( array(), Cart_Guard::shortfalls( Cart_Guard::demand( $items ) ) );
		$this->assertStringContainsString( 'only 3 more', Cart_Guard::message_for( 'Can', 3 ) );
	}

	public function test_add_to_cart_validation_with_live_cart() {
		if ( ! function_exists( 'WC' ) ) {
			$this->markTestSkipped( 'WooCommerce missing' );
		}
		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		$pool = $this->pool( 'Cans', 10 );
		$can  = $this->simple( 'Can', 'C1', 1 );
		$six  = $this->simple( 'Six', 'C6', 5 );
		$this->link( $pool, $can->get_id(), 1 );
		$this->link( $pool, $six->get_id(), 6 );
		WC()->cart->add_to_cart( $six->get_id(), 1 );
		$guard = new Cart_Guard();
		$this->assertTrue( $guard->validate_add( true, $can->get_id(), 4 ) );
		wc_clear_notices();
		$this->assertFalse( $guard->validate_add( true, $can->get_id(), 5 ) );
		$this->assertStringContainsString( 'only 4 more', wc_get_notices( 'error' )[0]['notice'] );
		wc_clear_notices();
		WC()->cart->empty_cart();
	}
}
