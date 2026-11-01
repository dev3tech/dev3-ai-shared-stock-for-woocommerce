import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, Drawer, ErrorNotice, IconButton, Inline, Modal, Notice, NumberField, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid, Tabs, TextField, useToast } from '../core3/ui';
import { UsageBars } from './charts';
import LinkEditor from './LinkEditor';
import PoDraft from './PoDraft';
import PoolForm, { usePoolForm } from './PoolForm';
import { date, daysText, PO_STATUS, qty, REASONS, StockBadge, TD, UrgencyBadge, withUnit } from './util';

const KIND = {
	product: __( 'Product', TD ),
	variable: __( 'All variations', TD ),
	variation: __( 'Variation', TD ),
	attribute: __( 'Attribute rule', TD ),
};

/**
 * One pool: stock, forecast + AI explanation, reorder/PO, links, linked products, history.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Pool id.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onUsage  AI usage happened.
 * @return {Element} Screen.
 */
export default function PoolDetail( { id, navigate, onUsage } ) {
	const toast = useToast();
	const [ pool, setPool ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ history, setHistory ] = useState( null );
	const [ linkOpen, setLinkOpen ] = useState( false );
	const [ adjustOpen, setAdjustOpen ] = useState( false );
	const [ editOpen, setEditOpen ] = useState( false );
	const [ deleteOpen, setDeleteOpen ] = useState( false );
	const [ explaining, setExplaining ] = useState( false );
	const [ aiError, setAiError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ mode, setMode ] = useState( 'add' );
	const [ amount, setAmount ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ form, setForm, errors, setFromError ] = usePoolForm();

	const loadHistory = () =>
		request( `history?pool_id=${ id }&per_page=10` )
			.then( ( r ) => setHistory( r.rows ) )
			.catch( () => setHistory( [] ) );
	const load = () => {
		setError( null );
		request( `pools/${ id }` ).then( setPool ).catch( setError );
		loadHistory();
	};
	useEffect( load, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return (
			<>
				<PageHeader title={ __( 'Stock pool', TD ) } back={ { href: '#/pools', label: __( 'All pools', TD ) } } />
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! pool ) {
		return (
			<>
				<PageHeader title={ __( 'Stock pool', TD ) } back={ { href: '#/pools', label: __( 'All pools', TD ) } } />
				<SkeletonCard lines={ 3 } />
				<SkeletonCard lines={ 6 } />
			</>
		);
	}
	const f = pool.forecast || {};
	const updated = ( next ) => {
		setPool( next );
		loadHistory();
	};

	const adjust = ( e ) => {
		e.preventDefault();
		setBusy( true );
		request( `pools/${ id }/adjust`, { method: 'POST', data: { mode, qty: amount === '' ? 0 : amount, note } } )
			.then( ( next ) => {
				updated( next );
				setAdjustOpen( false );
				setAmount( '' );
				setNote( '' );
				toast.success( sprintf( /* translators: %s: stock */ __( 'Stock is now %s.', TD ), withUnit( next.stock, next.unit ) ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const openEdit = () => {
		setForm( pool );
		setEditOpen( true );
	};
	const saveEdit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const { name, sku, unit, low_threshold: low, backorders, status, supplier_name: sn, supplier_email: se, lead_time_days: lead, unit_cost: cost, notes } = form;
		request( `pools/${ id }`, { method: 'PUT', data: { name, sku, unit, low_threshold: low, backorders, status, supplier_name: sn, supplier_email: se, lead_time_days: lead, unit_cost: cost, notes } } )
			.then( ( next ) => {
				updated( next );
				setEditOpen( false );
				toast.success( __( 'Pool saved.', TD ) );
			} )
			.catch( ( err ) => ( setFromError( err ) ? null : toast.error( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const remove = () => {
		setBusy( true );
		request( `pools/${ id }`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Pool deleted. Its history is kept.', TD ) );
				navigate( 'pools' );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const unlink = ( link ) =>
		request( `links/${ link.id }`, { method: 'DELETE' } )
			.then( ( next ) => {
				setPool( next );
				toast.success( __( 'Link removed.', TD ) );
			} )
			.catch( ( err ) => toast.error( err ) );

	const resync = () =>
		request( `pools/${ id }/sync`, { method: 'POST' } )
			.then( ( r ) => {
				setPool( r.pool );
				toast.success( sprintf( /* translators: %d: products */ __( '%d product stock levels updated.', TD ), r.updated ) );
			} )
			.catch( ( err ) => toast.error( err ) );

	const explain = () => {
		setExplaining( true );
		setAiError( null );
		request( `pools/${ id }/explain`, { method: 'POST' } )
			.then( ( next ) => setPool( { ...pool, forecast: next } ) )
			.catch( setAiError )
			.finally( () => {
				setExplaining( false );
				onUsage();
			} );
	};

	const ai = f.ai && f.ai.hash === f.hash ? f.ai : null;

	return (
		<>
			<PageHeader
				back={ { href: '#/pools', label: __( 'All pools', TD ) } }
				eyebrow={ pool.sku ? sprintf( /* translators: %s: sku */ __( 'SKU %s', TD ), pool.sku ) : __( 'Stock pool', TD ) }
				title={ pool.name }
				actions={
					<>
						<Button variant="primary" icon="edit" onClick={ () => setAdjustOpen( true ) }>
							{ __( 'Adjust stock', TD ) }
						</Button>
						<Button icon="settings" onClick={ openEdit }>
							{ __( 'Edit pool', TD ) }
						</Button>
						<IconButton icon="trash" variant="ghost" label={ __( 'Delete pool', TD ) } onClick={ () => setDeleteOpen( true ) } />
					</>
				}
			/>
			{ pool.status === 'paused' && <Notice tone="warning">{ __( 'This pool is paused: orders and carts ignore it until you set it back to active.', TD ) }</Notice> }
			<StatGrid>
				<Stat label={ __( 'In stock', TD ) } value={ withUnit( pool.stock, pool.unit ) } icon="box" tone="brand" hint={ <StockBadge pool={ pool } /> } />
				<Stat label={ __( 'Uses per day', TD ) } value={ qty( f.rate_per_day ) } icon="chart" hint={ f.history_days >= 14 && Math.abs( f.trend_pct ) >= 10 ? sprintf( /* translators: %s: percent */ __( '%s%% vs previous period', TD ), ( f.trend_pct > 0 ? '+' : '' ) + qty( f.trend_pct ) ) : __( 'Steady', TD ) } />
				<Stat label={ __( 'Days left', TD ) } value={ daysText( f.days_left ) } icon="clock" tone={ f.urgency === 'now' || f.urgency === 'out' ? 'danger' : f.urgency === 'soon' ? 'warning' : 'neutral' } hint={ f.stockout_date ? sprintf( /* translators: %s: date */ __( 'Runs out around %s', TD ), date( f.stockout_date ) ) : '' } />
				<Stat label={ __( 'Reorder', TD ) } value={ f.reorder_qty ? withUnit( f.reorder_qty, pool.unit ) : '–' } icon="file" hint={ <UrgencyBadge urgency={ f.urgency } /> } />
			</StatGrid>

			<Columns min={ 360 }>
				<Card title={ __( 'Stockout forecast', TD ) } description={ sprintf( /* translators: 1: days, 2: confidence */ __( 'Statistics from the last %1$d days · %2$s confidence', TD ), f.history_days || 0, f.confidence || 'low' ) }>
					<UsageBars series={ f.series } unit={ pool.unit } rate={ f.rate_per_day } />
					<p className="dev3ss-explain">{ f.explanation }</p>
					{ ai ? (
						<div className="dev3ss-ai-box">
							<Inline align="between">
								<strong>{ __( 'DEV3 AI explains', TD ) }</strong>
								<Badge tone={ ai.risk === 'high' ? 'danger' : ai.risk === 'medium' ? 'warning' : 'success' }>{ sprintf( /* translators: %s: risk */ __( '%s risk', TD ), ai.risk ) }</Badge>
							</Inline>
							<p>{ ai.summary }</p>
							{ ai.tips && ai.tips.length > 0 && (
								<ul>
									{ ai.tips.map( ( t ) => (
										<li key={ t }>{ t }</li>
									) ) }
								</ul>
							) }
						</div>
					) : (
						<AiButton onClick={ explain } loading={ explaining } loadingText={ __( 'Explaining…', TD ) }>
							{ __( 'Explain with DEV3 AI', TD ) }
						</AiButton>
					) }
					<ErrorNotice error={ aiError } onRetry={ explain } onDismiss={ () => setAiError( null ) } />
				</Card>
				<Card tone="ai" title={ __( 'Reorder & purchase order', TD ) } description={ f.urgency === 'now' || f.urgency === 'out' ? sprintf( /* translators: 1: qty, 2: lead */ __( 'Order about %1$s now (lead time %2$d days).', TD ), withUnit( f.reorder_qty, pool.unit ), pool.lead_time_days ) : f.reorder_date ? sprintf( /* translators: 1: qty, 2: date, 3: lead */ __( 'Order about %1$s by %2$s (lead time %3$d days).', TD ), withUnit( f.reorder_qty, pool.unit ), date( f.reorder_date ), pool.lead_time_days ) : __( 'No reorder needed until sales pick up.', TD ) }>
					<PoDraft pool={ pool } onUsage={ onUsage } onSaved={ () => request( `pools/${ id }` ).then( setPool ) } />
					{ pool.purchase_orders && pool.purchase_orders.length > 0 && (
						<ul className="dev3ss-list">
							{ pool.purchase_orders.slice( 0, 4 ).map( ( po ) => (
								<li key={ po.id }>
									<Badge tone={ ( PO_STATUS[ po.status ] || {} ).tone }>{ ( PO_STATUS[ po.status ] || {} ).label }</Badge>
									<span>{ sprintf( /* translators: 1: id, 2: qty */ __( 'PO #%1$d · %2$s', TD ), po.id, withUnit( po.qty, pool.unit ) ) }</span>
									<span className="dev3ss-muted">{ date( po.expected_date ) }</span>
								</li>
							) ) }
						</ul>
					) }
				</Card>
			</Columns>

			<Card
				title={ __( 'What draws from this pool', TD ) }
				description={ __( 'Products, variations and attribute rules. A product linked to several pools is a kit and deducts from each.', TD ) }
				actions={
					<Button size="sm" variant="primary" icon="plus" onClick={ () => setLinkOpen( true ) }>
						{ __( 'Add link', TD ) }
					</Button>
				}
				flush
			>
				<ResponsiveTable
					caption={ __( 'Links', TD ) }
					rows={ pool.links }
					empty={ {
						icon: 'box',
						title: __( 'Nothing linked yet', TD ),
						description: __( 'Link the products, variations or attribute values that sell from this stock.', TD ),
						action: (
							<Button variant="primary" onClick={ () => setLinkOpen( true ) }>
								{ __( 'Add the first link', TD ) }
							</Button>
						),
					} }
					columns={ [
						{ key: 'label', label: __( 'Linked item', TD ), primary: true },
						{ key: 'kind', label: __( 'Type', TD ), render: ( r ) => <Badge tone={ r.kind === 'attribute' ? 'info' : 'neutral' }>{ KIND[ r.kind ] || r.kind }</Badge> },
						{ key: 'qty', label: __( 'Units per sale', TD ), align: 'end', render: ( r ) => withUnit( r.qty, pool.unit ) },
					] }
					rowActions={ ( r ) => <IconButton icon="trash" label={ sprintf( /* translators: %s: item */ __( 'Remove link to %s', TD ), r.label ) } onClick={ () => unlink( r ) } /> }
				/>
			</Card>

			<Card
				title={ __( 'Product stock kept in sync', TD ) }
				description={ __( 'WooCommerce stock for each item = pool stock ÷ units per sale (the lowest across its pools).', TD ) }
				actions={
					<Button size="sm" icon="refresh" onClick={ resync }>
						{ __( 'Resync now', TD ) }
					</Button>
				}
				flush
			>
				<ResponsiveTable
					caption={ __( 'Linked products', TD ) }
					rows={ pool.products }
					pageSize={ 10 }
					empty={ { icon: 'inbox', title: __( 'No products yet', TD ), description: __( 'Products appear here once they are linked.', TD ) } }
					columns={ [
						{ key: 'name', label: __( 'Product', TD ), primary: true, render: ( r ) => ( r.edit_url ? <a href={ r.edit_url }>{ r.name }</a> : r.name ) },
						{ key: 'sku', label: __( 'SKU', TD ), hideOnMobile: true },
						{ key: 'per', label: __( 'Uses', TD ), align: 'end', render: ( r ) => withUnit( r.per, pool.unit ) },
						{ key: 'stock', label: __( 'Woo stock', TD ), align: 'end', render: ( r ) => qty( r.stock ) },
						{ key: 'status', label: __( 'Status', TD ), render: ( r ) => <Badge tone={ r.status === 'instock' ? 'success' : r.status === 'onbackorder' ? 'warning' : 'danger' }>{ r.status === 'instock' ? __( 'In stock', TD ) : r.status === 'onbackorder' ? __( 'Backorder', TD ) : __( 'Out of stock', TD ) }</Badge> },
					] }
				/>
			</Card>

			<Card
				title={ __( 'Recent history', TD ) }
				actions={
					<Button size="sm" variant="ghost" href={ `#/history/${ id }` }>
						{ __( 'Full history', TD ) }
					</Button>
				}
				flush
			>
				<ResponsiveTable
					caption={ __( 'Recent history', TD ) }
					loading={ history === null }
					rows={ history || [] }
					empty={ { icon: 'clock', title: __( 'No changes yet', TD ) } }
					columns={ [
						{ key: 'created_at', label: __( 'When', TD ), primary: true, render: ( r ) => date( r.created_at, true ) },
						{ key: 'reason', label: __( 'Reason', TD ), render: ( r ) => REASONS[ r.reason ] || r.reason },
						{ key: 'delta', label: __( 'Change', TD ), align: 'end', render: ( r ) => <span className={ r.delta < 0 ? 'dev3ss-delta is-neg' : 'dev3ss-delta is-pos' }>{ ( r.delta > 0 ? '+' : '' ) + qty( r.delta ) }</span> },
						{ key: 'stock_after', label: __( 'Stock after', TD ), align: 'end', render: ( r ) => qty( r.stock_after ) },
						{ key: 'note', label: __( 'Note', TD ), hideOnMobile: true },
					] }
				/>
			</Card>

			<LinkEditor open={ linkOpen } pool={ pool } onClose={ () => setLinkOpen( false ) } onSaved={ ( next ) => ( setPool( next ), toast.success( __( 'Link added. Product stock synced.', TD ) ) ) } />

			<Modal
				open={ adjustOpen }
				onClose={ () => setAdjustOpen( false ) }
				size="sm"
				title={ __( 'Adjust stock', TD ) }
				description={ sprintf( /* translators: %s: stock */ __( 'Now: %s. Every change is logged.', TD ), withUnit( pool.stock, pool.unit ) ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setAdjustOpen( false ) }>
							{ __( 'Cancel', TD ) }
						</Button>
						<Button variant="primary" type="submit" form="dev3ss-adjust" loading={ busy } disabled={ amount === '' }>
							{ __( 'Save change', TD ) }
						</Button>
					</>
				}
			>
				<form id="dev3ss-adjust" onSubmit={ adjust } className="dev3ss-stack">
					<Tabs
						label={ __( 'Adjustment type', TD ) }
						variant="pills"
						value={ mode }
						onChange={ setMode }
						tabs={ [
							{ id: 'add', label: __( 'Add', TD ) },
							{ id: 'remove', label: __( 'Remove', TD ) },
							{ id: 'set', label: __( 'Set to', TD ) },
						] }
					/>
					<NumberField label={ mode === 'set' ? __( 'New stock level', TD ) : __( 'Quantity', TD ) } value={ amount } onChange={ setAmount } min={ 0 } step="any" required data-autofocus />
					<TextField label={ __( 'Note (optional)', TD ) } value={ note } onChange={ setNote } placeholder={ __( 'e.g. Stock count, damaged, delivery', TD ) } maxLength={ 200 } />
				</form>
			</Modal>

			<Drawer
				open={ editOpen }
				onClose={ () => setEditOpen( false ) }
				title={ __( 'Edit pool', TD ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEditOpen( false ) }>
							{ __( 'Cancel', TD ) }
						</Button>
						<Button variant="primary" type="submit" form="dev3ss-edit-pool" loading={ busy }>
							{ __( 'Save pool', TD ) }
						</Button>
					</>
				}
			>
				<form id="dev3ss-edit-pool" onSubmit={ saveEdit } className="dev3ss-stack">
					<PoolForm value={ form } onChange={ setForm } errors={ errors } />
				</form>
			</Drawer>

			<Modal
				open={ deleteOpen }
				onClose={ () => setDeleteOpen( false ) }
				size="sm"
				title={ __( 'Delete this pool?', TD ) }
				description={ __( 'Its links and alerts are removed. Product stock stays at its current level and the stock history is kept.', TD ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setDeleteOpen( false ) } data-autofocus>
							{ __( 'Cancel', TD ) }
						</Button>
						<Button variant="danger" onClick={ remove } loading={ busy }>
							{ __( 'Delete pool', TD ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
