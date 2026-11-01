import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import { Sparkline, UsageBars } from '../../src/admin/charts';
import { daysText, qty, withUnit } from '../../src/admin/util';
import PoDraft from '../../src/admin/PoDraft';
import Setup from '../../src/admin/Setup';
import Ask from '../../src/admin/Ask';
import Alerts from '../../src/admin/Alerts';
import Dashboard from '../../src/admin/Dashboard';
import ImportExport from '../../src/admin/ImportExport';
import { act } from 'react';
import { click, render } from './render';

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const calls = () => apiFetch.mock.calls.map( ( c ) => [ c[ 0 ].method || 'GET', c[ 0 ].url.replace( '/wp-json/dev3ss/v1/', '' ), c[ 0 ].data ] );

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

describe( 'formatters', () => {
	it( 'formats quantities and days', () => {
		expect( qty( 12.5 ) ).toMatch( /12[.,]5/ );
		expect( qty( null ) ).toBe( '–' );
		expect( withUnit( 6, 'cans' ) ).toBe( '6 cans' );
		expect( daysText( null ) ).toBe( 'No sales yet' );
		expect( daysText( 0 ) ).toBe( 'Out now' );
		expect( daysText( 12.4 ) ).toBe( '12 days' );
	} );
} );

describe( 'charts', () => {
	it( 'draws one bar per day with sales, a rate line and an accessible table', async () => {
		const series = { '2026-10-01': 3, '2026-10-02': 0, '2026-10-03': 5 };
		const view = await render( <UsageBars series={ series } unit="cans" rate={ 2.5 } /> );
		expect( view.$$( '.dev3ss-chart__bar' ) ).toHaveLength( 2 );
		expect( view.$( '.dev3ss-chart__rate' ) ).not.toBeNull();
		expect( view.$$( 'table tr' ) ).toHaveLength( 3 );
		expect( view.$( 'svg' ).getAttribute( 'aria-label' ) ).toMatch( /8 cans/ );
	} );

	it( 'shows an empty message without sales and hides empty sparklines', async () => {
		const view = await render(
			<>
				<UsageBars series={ { '2026-10-01': 0 } } />
				<Sparkline values={ [ 0, 0 ] } />
			</>
		);
		expect( view.queryByText( /No sales in the last 30 days/ ) ).not.toBeNull();
		expect( view.$( '.dev3ss-spark' ) ).toBeNull();
	} );
} );

describe( 'PoDraft', () => {
	const pool = { id: 7, name: 'Cola cans', unit: 'cans', supplier_email: 'orders@fizz.example', forecast: { reorder_qty: 120 } };

	it( 'drafts with DEV3 AI, then saves the accepted draft as a PO and sends it', async () => {
		apiFetch
			.mockResolvedValueOnce( { pool_id: 7, qty: 120, expected_date: '2026-11-01', subject: 'PO: Cola cans', body: 'Hello', source: 'ai', model: 'gemini-2.5-flash', tokens: 300, supplier_email: 'orders@fizz.example' } )
			.mockResolvedValueOnce( { id: 3, status: 'draft', supplier_email: 'orders@fizz.example' } )
			.mockResolvedValueOnce( { id: 3, status: 'sent', supplier_email: 'orders@fizz.example' } );
		const onSaved = jest.fn();
		const view = await render( wrap( <PoDraft pool={ pool } onSaved={ onSaved } onUsage={ () => {} } /> ) );
		await click( view.getByText( 'Draft PO email with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 0 ] ).toEqual( [ 'POST', 'pools/7/po-draft', { qty: 120, date: '', ai: true } ] );
		expect( view.queryByText( 'Not saved yet' ) ).not.toBeNull();
		await click( view.getByText( 'Save as draft PO' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 1 ][ 1 ] ).toBe( 'purchase-orders' );
		expect( calls()[ 1 ][ 2 ] ).toMatchObject( { pool_id: 7, qty: 120, expected_date: '2026-11-01', subject: 'PO: Cola cans', body: 'Hello' } );
		expect( onSaved ).toHaveBeenCalled();
		await click( view.getByText( 'Email to supplier' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 2 ][ 1 ] ).toBe( 'purchase-orders/3/send' );
	} );

	it( 'uses the template without AI and can reject the draft', async () => {
		apiFetch.mockResolvedValueOnce( { qty: 120, expected_date: '2026-11-01', subject: 'Template', body: 'Body', source: 'template' } );
		const view = await render( wrap( <PoDraft pool={ pool } /> ) );
		await click( view.getByText( 'Use template' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 0 ][ 2 ].ai ).toBe( false );
		expect( view.queryByText( 'Template purchase-order draft' ) ).not.toBeNull();
		await click( view.getByText( 'Reject' ).closest( 'button' ) );
		expect( view.queryByText( 'Template purchase-order draft' ) ).toBeNull();
	} );
} );

describe( 'Setup', () => {
	it( 'shows proposals as drafts and only creates the selected ones', async () => {
		const proposals = [
			{ name: 'Cola can', unit: 'cans', stock: 120, reason: 'Pack sizes', source: 'ai', links: [ { product_id: 11, label: 'Cola can', qty: 1 }, { product_id: 12, label: 'Cola 6-pack', qty: 6 } ] },
			{ name: 'Material: Linen', unit: 'm', stock: 0, reason: 'Shared fabric', source: 'rules', links: [ { product_id: 0, attr_key: 'pa_material', attr_value: 'linen', label: 'Any product with Material = Linen', qty: 1 } ] },
		];
		apiFetch.mockResolvedValueOnce( { proposals, products: 40, model: 'gemini-2.5-flash', tokens: 900 } ).mockResolvedValueOnce( { created: [ 5 ], errors: [] } );
		const navigate = jest.fn();
		const view = await render( wrap( <Setup navigate={ navigate } onUsage={ () => {} } /> ) );
		await click( view.getByText( 'Propose pools with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( view.queryByText( '2 proposed pools' ) ).not.toBeNull();
		expect( view.queryByText( 'Draft: not saved yet' ) ).not.toBeNull();
		const boxes = view.$$( 'input[type="checkbox"]' );
		await click( boxes[ 1 ] );
		await click( view.getByText( 'Create 1 pool' ).closest( 'button' ) );
		await view.flush();
		const [ method, url, data ] = calls()[ 1 ];
		expect( [ method, url ] ).toEqual( [ 'POST', 'setup/apply' ] );
		expect( data.proposals ).toHaveLength( 1 );
		expect( data.proposals[ 0 ].name ).toBe( 'Cola can' );
		expect( navigate ).toHaveBeenCalledWith( 'pools' );
	} );
} );

describe( 'Ask', () => {
	it( 'sends the question with history and shows the answer source', async () => {
		apiFetch.mockResolvedValueOnce( { answer: '2 pools run out before Black Friday.', pool_ids: [ 4 ], follow_up: [ 'What should I reorder?' ], source: 'rules' } );
		const view = await render( wrap( <Ask settings={ { api_key_set: false } } onUsage={ () => {} } /> ) );
		await click( view.getByText( 'What will run out before Black Friday?' ) );
		await view.flush();
		expect( calls()[ 0 ][ 2 ] ).toEqual( { question: 'What will run out before Black Friday?', history: [], ai: false } );
		expect( view.queryByText( '2 pools run out before Black Friday.' ) ).not.toBeNull();
		expect( view.queryByText( 'Built-in answer' ) ).not.toBeNull();
		expect( view.$( 'a[href="#/pools/4"]' ) ).not.toBeNull();
	} );
} );

describe( 'Alerts', () => {
	it( 'offers a suggested correction as a draft and applies it on accept', async () => {
		const alert = { id: 9, pool_id: 2, pool_name: 'Mugs', type: 'anomaly', severity: 'danger', message: 'Mugs was changed by hand from 12 to 120.', detail: { rule: 'extra_zero', before: 12, after: 120, suggested: 12 }, status: 'open', created_at: '2026-10-20 10:00:00' };
		apiFetch.mockResolvedValueOnce( [ alert ] ).mockResolvedValueOnce( { ...alert, status: 'dismissed', detail: { ...alert.detail, fixed_to: 12 } } );
		const onChange = jest.fn();
		const view = await render( wrap( <Alerts onChange={ onChange } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.queryByText( 'Suggested fix' ) ).not.toBeNull();
		expect( view.queryByText( 'Not applied yet' ) ).not.toBeNull();
		await click( view.getByText( 'Accept' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 1 ] ).toEqual( [ 'POST', 'alerts/9/fix', { stock: 12 } ] );
		expect( onChange ).toHaveBeenLastCalledWith( 0 );
	} );
} );

describe( 'Dashboard', () => {
	it( 'renders KPIs and the at-risk table', async () => {
		apiFetch.mockResolvedValueOnce( {
			kpis: { pools: 3, active: 3, links: 7, low: 1, out: 0, reorder_now: 1, risk_14: 2, alerts: 1, used_30: 40, open_pos: 0 },
			at_risk: [ { id: 1, name: 'Cola cans', unit: 'cans', stock: 30, days_left: 4, reorder_qty: 120, reorder_date: '2026-10-25', urgency: 'now', series: [ 1, 2 ] } ],
			alerts: [],
			recent: [],
			woo: { manage_stock: true },
		} );
		const view = await render( wrap( <Dashboard settings={ { api_key_set: true } } navigate={ () => {} } /> ) );
		await view.flush();
		expect( view.queryByText( 'Cola cans' ) ).not.toBeNull();
		expect( view.queryByText( 'Reorder now' ) ).not.toBeNull();
		expect( view.queryByText( 'All clear' ) ).not.toBeNull();
	} );
} );

describe( 'ImportExport', () => {
	it( 'previews an import before applying it', async () => {
		apiFetch.mockResolvedValueOnce( { created: 1, updated: 0, skipped: 0, errors: [], preview: [ { line: 2, action: 'create', name: 'Cola cans', from: null, to: 480 } ] } ).mockResolvedValueOnce( { created: 1, updated: 0, skipped: 0, errors: [], preview: [] } );
		const view = await render( wrap( <ImportExport /> ) );
		const input = view.$( '#dev3ss-csv' );
		const file = new window.File( [ 'name,stock\nCola cans,480\n' ], 'pools.csv', { type: 'text/csv' } );
		Object.defineProperty( input, 'files', { value: [ file ] } );
		await act( async () => {
			input.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			await new Promise( ( r ) => setTimeout( r, 30 ) );
		} );
		await view.flush();
		await click( view.getByText( 'Preview import' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 0 ][ 2 ] ).toMatchObject( { type: 'pools', dry_run: true } );
		expect( calls()[ 0 ][ 2 ].csv ).toContain( 'Cola cans' );
		await click( view.getByText( 'Import now' ).closest( 'button' ) );
		await view.flush();
		expect( calls()[ 1 ][ 2 ].dry_run ).toBe( false );
	} );
} );
