<?php
/**
 * Forecast statistics, plain-words explanations, anomalies, setup rules, Ask rules, CSV.
 *
 * @package Dev3\AiSharedStock
 */

require_once __DIR__ . '/Dev3ss_Test_Case.php';

use Dev3\AiSharedStock\Csv\Csv;
use Dev3\AiSharedStock\Data\Alerts;
use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Insights\Anomalies;
use Dev3\AiSharedStock\Insights\Ask;
use Dev3\AiSharedStock\Insights\Forecast;
use Dev3\AiSharedStock\Insights\Setup_Advisor;

class InsightsTest extends Dev3ss_Test_Case {

	/**
	 * Series of N days ending today with a value callback.
	 */
	private function series( $days, callable $fn, $today = '2026-10-25' ) {
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d         = gmdate( 'Y-m-d', strtotime( $today . ' 12:00 UTC' ) - $i * DAY_IN_SECONDS );
			$out[ $d ] = (float) $fn( $days - 1 - $i );
		}
		return $out;
	}

	public function test_forecast_steady_demand() {
		$f = Forecast::compute(
			$this->series( 60, static fn() => 4 ),
			100,
			array(
				'lead_time'   => 7,
				'safety_days' => 3,
				'cover_days'  => 30,
				'today'       => '2026-10-25',
			)
		);
		$this->assertEqualsWithDelta( 4.0, $f['rate_per_day'], 0.01 );
		$this->assertEqualsWithDelta( 25.0, $f['days_left'], 0.1 );
		$this->assertSame( '2026-11-19', $f['stockout_date'] );
		$this->assertEqualsWithDelta( 12.0, $f['safety_stock'], 0.01, 'sd 0, so rate x safety days' );
		$this->assertEqualsWithDelta( 40.0, $f['reorder_point'], 0.01 );
		$this->assertSame( '2026-11-09', $f['reorder_date'] );
		$this->assertSame( 60, $f['reorder_qty'], '4 x (7+30) + 12 - 100' );
		$this->assertSame( 'ok', $f['urgency'] );
		$this->assertSame( 'high', $f['confidence'] );
		$this->assertEqualsWithDelta( 0.0, $f['trend_pct'], 0.01 );
	}

	public function test_forecast_rising_trend_and_reorder_now() {
		$f = Forecast::compute( $this->series( 42, static fn( $i ) => $i < 28 ? 2 : 6 ), 30, array( 'lead_time' => 10, 'today' => '2026-10-25' ) );
		$this->assertGreaterThan( 100, $f['trend_pct'] );
		$this->assertGreaterThan( 4, $f['rate_per_day'] );
		$this->assertSame( 'now', $f['urgency'] );
		$this->assertGreaterThan( 0, $f['reorder_qty'] );
	}

	public function test_forecast_idle_out_and_incoming() {
		$idle = Forecast::compute( $this->series( 30, static fn() => 0 ), 50, array( 'today' => '2026-10-25' ) );
		$this->assertSame( 'idle', $idle['urgency'] );
		$this->assertNull( $idle['days_left'] );
		$out = Forecast::compute( $this->series( 30, static fn() => 2 ), 0, array( 'today' => '2026-10-25' ) );
		$this->assertSame( 'out', $out['urgency'] );
		$this->assertSame( 0.0, $out['days_left'] );
		$this->assertGreaterThan( 0, $out['reorder_qty'] );
		$with  = Forecast::compute( $this->series( 30, static fn() => 2 ), 20, array( 'today' => '2026-10-25', 'incoming' => 100 ) );
		$without = Forecast::compute( $this->series( 30, static fn() => 2 ), 20, array( 'today' => '2026-10-25' ) );
		$this->assertLessThan( $without['reorder_qty'], $with['reorder_qty'], 'open POs reduce the suggested quantity' );
	}

	public function test_forecast_weekday_profile_and_history_limit() {
		$f = Forecast::compute(
			$this->series(
				56,
				static function ( $i ) {
					return 0 === ( $i % 7 ) ? 10 : 2;
				}
			),
			200,
			array( 'today' => '2026-10-25' )
		);
		$this->assertGreaterThan( 100, $f['busiest_pct'] );
		$short = Forecast::compute( $this->series( 90, static fn() => 1 ), 10, array( 'history_days' => 5 ) );
		$this->assertSame( 5, $short['history_days'] );
		$this->assertSame( 'low', $short['confidence'] );
	}

	public function test_forecast_explains_in_plain_words() {
		$pool = Pools::get( $this->pool( 'Cola cans', 100, array( 'unit' => 'cans' ) ) );
		$f    = Forecast::compute( $this->series( 60, static fn() => 4 ), 100, array( 'lead_time' => 7, 'today' => '2026-10-25' ) );
		$text = Forecast::explain( $f, $pool );
		$this->assertStringContainsString( 'about 4 cans a day', $text );
		$this->assertStringContainsString( 'last about 25 days', $text );
		$this->assertStringContainsString( 'reorder by', $text );
		$this->assertStringContainsString( 'Demand is steady', $text );
		$idle = Forecast::explain( Forecast::compute( array( '2026-10-25' => 0 ), 5, array() ), $pool );
		$this->assertStringContainsString( 'No sales', $idle );
	}

	public function test_forecast_for_pool_reads_the_log_and_raises_risk_alert() {
		$id = $this->pool( 'Mugs', 30, array( 'lead_time_days' => 10 ) );
		for ( $i = 1; $i <= 20; $i++ ) {
			Log::add( $id, -5, 0, 'order', array( 'created_at' => gmdate( 'Y-m-d 10:00:00', time() - $i * DAY_IN_SECONDS ) ) );
		}
		Log::add( $id, 5, 0, 'cancel', array( 'created_at' => gmdate( 'Y-m-d 11:00:00', time() - DAY_IN_SECONDS ) ) );
		$f = Forecast::refresh( $id );
		$this->assertGreaterThan( 3, $f['rate_per_day'] );
		$this->assertLessThan( 10, $f['days_left'] );
		$this->assertNotEmpty( Alerts::query( 'open', 10, 'forecast' ) );
		$this->assertSame( $f['hash'], Pools::get( $id, true )['forecast']['hash'] );
	}

	public function test_anomaly_rules() {
		$this->assertSame( 'extra_zero', Anomalies::classify_change( 12, 120 )['rule'] );
		$this->assertSame( 12.0, (float) Anomalies::classify_change( 12, 120 )['suggested'] );
		$this->assertSame( 'missing_zero', Anomalies::classify_change( 150, 15 )['rule'] );
		$this->assertSame( 'digit_swap', Anomalies::classify_change( 120, 210 )['rule'] );
		$this->assertSame( 'negative', Anomalies::classify_change( 5, -3 )['rule'] );
		$this->assertNull( Anomalies::classify_change( 5, -3, 0, true ) );
		$this->assertSame( 'large_removal', Anomalies::classify_change( 100, 20, 5 )['rule'] );
		$this->assertNull( Anomalies::classify_change( 100, 90, 10 ), 'normal correction' );
		$this->assertNull( Anomalies::classify_change( 40, 52, 10 ), 'normal delivery' );
	}

	public function test_sudden_drop_detection() {
		$this->assertNotNull( Anomalies::sudden_drop( array( 2, 3, 2, 3, 2, 3, 2, 3, 2, 40 ) ) );
		$this->assertNull( Anomalies::sudden_drop( array( 2, 3, 2, 3, 2, 3, 2, 3, 2, 4 ) ) );
		$this->assertNull( Anomalies::sudden_drop( array( 0, 0, 3 ) ), 'too little history' );
	}

	public function test_manual_typo_raises_one_alert_with_suggestion() {
		$id = $this->pool( 'Mugs', 12 );
		Pools::set_stock( $id, 120, 'manual' );
		$alerts = Alerts::query( 'open', 10, 'anomaly' );
		$this->assertCount( 1, $alerts );
		$this->assertSame( 'extra_zero', $alerts[0]['detail']['rule'] );
		$this->assertEquals( 12, $alerts[0]['detail']['suggested'] );
		Pools::adjust( $id, -1, 'order' );
		$this->assertCount( 1, Alerts::query( 'open', 10, 'anomaly' ), 'orders are not checked as typos' );
	}

	public function test_scan_finds_sudden_drop_once() {
		$id = $this->pool( 'Cups', 500 );
		for ( $i = 1; $i <= 30; $i++ ) {
			Log::add( $id, -2, 0, 'order', array( 'created_at' => gmdate( 'Y-m-d 10:00:00', time() - $i * DAY_IN_SECONDS ) ) );
		}
		Log::add( $id, -60, 0, 'order', array( 'created_at' => gmdate( 'Y-m-d 00:00:01' ) ) );
		$this->assertSame( 1, Anomalies::scan() );
		$this->assertSame( 0, Anomalies::scan(), 'same day is not raised twice' );
	}

	public function test_low_and_out_of_stock_alerts_and_email() {
		reset_phpmailer_instance();
		$id = $this->pool( 'Caps', 10, array( 'low_threshold' => 5 ) );
		Pools::adjust( $id, -6, 'order' );
		$this->assertCount( 1, Alerts::query( 'open', 10, 'low_stock' ) );
		$this->assertStringContainsString( 'Low stock: Caps', tests_retrieve_phpmailer_instance()->get_sent()->subject );
		Pools::adjust( $id, -4, 'order' );
		$this->assertCount( 0, Alerts::query( 'open', 10, 'low_stock' ) );
		$this->assertCount( 1, Alerts::query( 'open', 10, 'out_of_stock' ) );
		Pools::adjust( $id, 20, 'restock' );
		$this->assertCount( 0, Alerts::query( 'open', 10 ) );
	}

	public function test_pack_size_parsing_and_rule_proposals() {
		$this->assertSame( 6, Setup_Advisor::pack_size( 'Cola 6-pack' )['size'] );
		$this->assertSame( 'Cola', Setup_Advisor::pack_size( 'Cola 6-pack' )['base'] );
		$this->assertSame( 24, Setup_Advisor::pack_size( 'Cola (Case of 24)' )['size'] );
		$this->assertSame( 12, Setup_Advisor::pack_size( 'Soap bar x12' )['size'] );
		$this->assertSame( 0, Setup_Advisor::pack_size( 'Plain mug' )['size'] );
		$this->simple( 'Cola', 'C1', 1, array( 'stock' => 100 ) );
		$this->simple( 'Cola 6-pack', 'C6', 5, array( 'stock' => 10 ) );
		$this->simple( 'Mug', 'M', 8 );
		$props = Setup_Advisor::rule_proposals( Setup_Advisor::snapshot() );
		$this->assertCount( 1, $props );
		$this->assertSame( 'Cola', $props[0]['name'] );
		$this->assertSame( array( 1, 6 ), array_map( 'intval', wp_list_pluck( $props[0]['links'], 'qty' ) ) );
		$this->assertSame( 100, $props[0]['stock'] );
	}

	public function test_setup_validate_drops_unknown_ids_and_apply_creates_pools() {
		$a    = $this->simple( 'Soap', 'S1', 2 );
		$snap = Setup_Advisor::snapshot();
		$ok   = Setup_Advisor::validate(
			array(
				array(
					'name'  => 'Soap bars',
					'stock' => 40,
					'links' => array(
						array( 'product_id' => $a->get_id(), 'qty' => 1 ),
						array( 'product_id' => 999999, 'qty' => 1 ),
						array( 'product_id' => $a->get_id(), 'qty' => -2 ),
					),
				),
				array(
					'name'  => 'Ghost',
					'links' => array( array( 'product_id' => 424242, 'qty' => 1 ) ),
				),
			),
			$snap
		);
		$this->assertCount( 1, $ok );
		$this->assertCount( 1, $ok[0]['links'] );
		$res = Setup_Advisor::apply( $ok );
		$this->assertCount( 1, $res['created'] );
		$this->assertSame( 40.0, $this->stock( $res['created'][0] ) );
		$this->assertSame( 40, wc_get_product( $a->get_id() )->get_stock_quantity(), 'mirrored after setup' );
	}

	public function test_ask_dates_and_rule_answers() {
		$events = Ask::events( '2026-10-25' );
		$this->assertSame( '2026-11-27', $events['black_friday']['date'] );
		$this->assertSame( '2026-11-30', $events['cyber_monday']['date'] );
		$this->assertSame( '2026-11-27', Ask::target_date( 'What runs out before Black Friday?', '2026-10-25' )['date'] );
		$this->assertSame( '2026-11-04', Ask::target_date( 'anything short in 10 days', '2026-10-25' )['date'] );
		$this->assertNull( Ask::target_date( 'best sellers', '2026-10-25' ) );
		$snap   = array(
			array( 'id' => 1, 'name' => 'Cans', 'unit' => 'cans', 'stock' => 20, 'low_threshold' => 5, 'per_day' => 4, 'days_left' => 5, 'stockout_date' => '2026-10-30', 'reorder_date' => '2026-10-25', 'reorder_qty' => 120, 'urgency' => 'now' ),
			array( 'id' => 2, 'name' => 'Mugs', 'unit' => '', 'stock' => 300, 'low_threshold' => 5, 'per_day' => 1, 'days_left' => 300, 'stockout_date' => '2027-08-21', 'reorder_date' => '2027-08-01', 'reorder_qty' => 10, 'urgency' => 'ok' ),
			array( 'id' => 3, 'name' => 'Caps', 'unit' => '', 'stock' => 3, 'low_threshold' => 5, 'per_day' => 0, 'days_left' => null, 'stockout_date' => null, 'reorder_date' => null, 'reorder_qty' => 0, 'urgency' => 'idle' ),
		);
		$a = Ask::answer( 'What will run out before Black Friday?', $snap, '2026-10-25' );
		$this->assertSame( array( 1 ), $a['pool_ids'] );
		$this->assertStringContainsString( 'Cans', $a['answer'] );
		$this->assertSame( array( 1 ), Ask::answer( 'What should I reorder?', $snap, '2026-10-25' )['pool_ids'] );
		$this->assertSame( array( 1, 2 ), Ask::answer( 'best sellers', $snap, '2026-10-25' )['pool_ids'] );
		$this->assertSame( array( 3 ), Ask::answer( 'what is low?', $snap, '2026-10-25' )['pool_ids'] );
		$this->assertSame( array( 3 ), Ask::answer( 'what is not selling', $snap, '2026-10-25' )['pool_ids'] );
		$this->assertSame( 'rules', Ask::answer( 'hello', $snap, '2026-10-25' )['source'] );
	}

	public function test_csv_encode_parse_and_formula_guard() {
		$csv = Csv::encode( array( 'name', 'note' ), array( array( 'A, "quoted"', '=HYPERLINK("x")' ), array( 'B', -5 ) ) );
		$this->assertStringContainsString( '"A, ""quoted"""', $csv );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( 'B,-5', $csv );
		$rows = Csv::parse( $csv );
		$this->assertSame( 'A, "quoted"', $rows[0]['name'] );
		$this->assertSame( '=HYPERLINK("x")', $rows[0]['note'] );
		$this->assertSame( 'x', Csv::parse( "a;b\nx;y\n" )[0]['a'], 'semicolon files' );
		$this->assertWPError( Csv::parse( "only-header\n" ) );
	}

	public function test_csv_pool_import_preview_then_apply() {
		$existing = $this->pool( 'Mugs', 10, array( 'sku' => 'MUG' ) );
		$text     = "name,sku,stock,unit\nMugs,MUG,25,pcs\nCups,CUP,40,pcs\nBad,BAD,abc,pcs\n";
		$preview  = Csv::import_pools( $text, true );
		$this->assertSame( 1, $preview['created'] );
		$this->assertSame( 1, $preview['updated'] );
		$this->assertSame( 1, $preview['skipped'] );
		$this->assertSame( 10.0, $this->stock( $existing ), 'dry run changes nothing' );
		$done = Csv::import_pools( $text, false );
		$this->assertSame( 1, $done['created'] );
		$this->assertSame( 25.0, $this->stock( $existing ) );
		$this->assertNotNull( Pools::find_by_sku( 'CUP' ) );
		$this->assertSame( 'import', Log::query( array( 'pool_id' => $existing ) )['rows'][0]['reason'] );
	}

	public function test_csv_links_import_and_exports() {
		$pool = $this->pool( 'Cans', 60, array( 'sku' => 'CAN' ) );
		$p    = $this->simple( 'Six', 'SIX', 5 );
		$res  = Csv::import_links( "pool_sku,product_sku,qty\nCAN,SIX,6\nCAN,NOPE,1\nNOPOOL,SIX,1\n", false );
		$this->assertSame( 1, $res['created'] );
		$this->assertSame( 2, $res['skipped'] );
		$this->assertSame( 10, wc_get_product( $p->get_id() )->get_stock_quantity() );
		$this->assertStringContainsString( 'CAN,Cans', Csv::export_links() );
		$this->assertStringContainsString( 'Cans,CAN,60', Csv::export_pools() );
		$this->assertStringContainsString( 'setup', Csv::export_log() );
		unset( $pool );
	}
}
