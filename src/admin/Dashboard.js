import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, ResponsiveTable, SkeletonCard, Stat, StatGrid } from '../core3/ui';
import { Sparkline } from './charts';
import { date, daysText, reorderBy, qty, REASONS, TD, UrgencyBadge, withUnit } from './util';

/**
 * Overview: KPIs, pools at risk, open alerts, latest stock movements.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { settings, navigate } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'dashboard' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'Dashboard', TD ) } />
				<SkeletonCard lines={ 3 } />
				<SkeletonCard lines={ 6 } />
			</>
		);
	}
	const k = data.kpis;
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', TD ) }
				description={ __( 'Shared stock across your products, what runs out next and what to reorder.', TD ) }
				actions={
					<>
						<Button icon="plus" variant="primary" href="#/pools/new">
							{ __( 'New pool', TD ) }
						</Button>
						<Button icon="sparkles" variant="ai" href="#/setup">
							{ __( 'Set up pools for me', TD ) }
						</Button>
					</>
				}
			/>

			{ data.woo && ! data.woo.manage_stock && (
				<Notice tone="warning" title={ __( 'Stock management is off in WooCommerce', TD ) } actions={ <Button size="sm" href={ config.manageStockUrl }>{ __( 'Open inventory settings', TD ) }</Button> }>
					{ __( 'WooCommerce only reduces stock for orders when "Enable stock management" is on. Turn it on so orders deduct from your pools.', TD ) }
				</Notice>
			) }
			{ settings && settings.api_key_set === false && config.canManage && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', TD ) } actions={ <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', TD ) }</Button> }>
					{ __( 'Add a Google Gemini API key for plain-language forecasts, purchase-order emails, pool setup and inventory chat. Pools, deduction, alerts and statistical forecasts work without it.', TD ) }
				</Notice>
			) }

			<StatGrid>
				<Stat label={ __( 'Active pools', TD ) } value={ qty( k.active ) } icon="box" tone="brand" hint={ sprintf( /* translators: %s: links */ __( '%s product links', TD ), qty( k.links ) ) } />
				<Stat label={ __( 'Reorder now', TD ) } value={ qty( k.reorder_now ) } icon="alert" tone={ k.reorder_now ? 'danger' : 'success' } hint={ sprintf( /* translators: %s: count */ __( '%s run out within 14 days', TD ), qty( k.risk_14 ) ) } />
				<Stat label={ __( 'Low or out of stock', TD ) } value={ qty( k.low + k.out ) } icon="pause" tone={ k.low + k.out ? 'warning' : 'success' } hint={ sprintf( /* translators: %s: out of stock */ __( '%s out of stock', TD ), qty( k.out ) ) } />
				<Stat label={ __( 'Open alerts', TD ) } value={ qty( k.alerts ) } icon="bolt" tone={ k.alerts ? 'warning' : 'neutral' } hint={ sprintf( /* translators: %s: POs */ __( '%s purchase orders on the way', TD ), qty( k.open_pos ) ) } />
			</StatGrid>

			<Card
				title={ __( 'Runs out first', TD ) }
				description={ __( 'Active pools sorted by days of stock left at the current pace.', TD ) }
				actions={
					<Button size="sm" variant="ghost" href="#/forecasts" iconRight="external">
						{ __( 'All forecasts', TD ) }
					</Button>
				}
				flush
			>
				<ResponsiveTable
					caption={ __( 'Pools at risk', TD ) }
					rows={ data.at_risk }
					onRowClick={ ( r ) => navigate( `pools/${ r.id }` ) }
					empty={ {
						icon: 'box',
						title: __( 'No pools yet', TD ),
						description: __( 'Create a pool, link the products that share its stock, and sales start deducting from it.', TD ),
						action: (
							<Button variant="primary" href="#/pools/new">
								{ __( 'Create a pool', TD ) }
							</Button>
						),
					} }
					columns={ [
						{ key: 'name', label: __( 'Pool', TD ), primary: true, render: ( r ) => <a href={ `#/pools/${ r.id }` }>{ r.name }</a> },
						{ key: 'stock', label: __( 'Stock', TD ), align: 'end', nowrap: true, render: ( r ) => withUnit( r.stock, r.unit ) },
						{ key: 'trend', label: __( '30-day usage', TD ), hideOnMobile: true, render: ( r ) => <Sparkline values={ r.series } /> },
						{ key: 'days_left', label: __( 'Days left', TD ), align: 'end', nowrap: true, render: ( r ) => daysText( r.days_left ) },
						{ key: 'reorder', label: __( 'Reorder', TD ), nowrap: true, render: ( r ) => {
							if ( ! r.reorder_qty ) {
								return '–';
							}
							return r.urgency === 'now' || r.urgency === 'out' ? sprintf( /* translators: %s: qty */ __( '%s now', TD ), withUnit( r.reorder_qty, r.unit ) ) : sprintf( /* translators: 1: qty, 2: date */ __( '%1$s by %2$s', TD ), withUnit( r.reorder_qty, r.unit ), reorderBy( r ) );
						} },
						{ key: 'urgency', label: __( 'Status', TD ), render: ( r ) => <UrgencyBadge urgency={ r.urgency } /> },
					] }
				/>
			</Card>

			<Columns min={ 340 }>
				<Card
					title={ __( 'Open alerts', TD ) }
					actions={
						<Button size="sm" variant="ghost" href="#/alerts">
							{ __( 'View all', TD ) }
						</Button>
					}
				>
					{ data.alerts.length ? (
						<ul className="dev3ss-list">
							{ data.alerts.map( ( a ) => (
								<li key={ a.id }>
									<Badge tone={ a.severity === 'danger' ? 'danger' : 'warning' } dot>
										{ a.type === 'anomaly' ? __( 'Anomaly', TD ) : a.type === 'forecast' ? __( 'Forecast', TD ) : __( 'Stock', TD ) }
									</Badge>
									<span>{ a.message }</span>
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState compact icon="check" title={ __( 'All clear', TD ) } description={ __( 'Low stock, sudden drops and likely typos show up here.', TD ) } />
					) }
				</Card>
				<Card
					title={ __( 'Latest stock movements', TD ) }
					actions={
						<Button size="sm" variant="ghost" href="#/history">
							{ __( 'Full history', TD ) }
						</Button>
					}
				>
					{ data.recent.length ? (
						<ul className="dev3ss-list">
							{ data.recent.map( ( r ) => (
								<li key={ r.id }>
									<span className={ r.delta < 0 ? 'dev3ss-delta is-neg' : 'dev3ss-delta is-pos' }>{ ( r.delta > 0 ? '+' : '' ) + qty( r.delta ) }</span>
									<span>
										<strong>{ r.pool_name || `#${ r.pool_id }` }</strong> · { REASONS[ r.reason ] || r.reason }
										{ r.order_id ? ` #${ r.order_id }` : '' }
									</span>
									<span className="dev3ss-muted">{ date( r.created_at, true ) }</span>
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState compact icon="clock" title={ __( 'No movements yet', TD ) } description={ __( 'Orders, refunds, cancellations and manual changes are logged here.', TD ) } />
					) }
				</Card>
			</Columns>
		</>
	);
}
