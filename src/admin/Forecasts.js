import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ErrorNotice, JobProgress, PageHeader, ResponsiveTable, Stat, StatGrid, Tabs, Toggle, useToast } from '../core3/ui';
import { Sparkline } from './charts';
import { daysText, reorderBy, qty, TD, UrgencyBadge, withUnit } from './util';

/**
 * Forecast + reorder table for every active pool, and the background forecast job.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onUsage  AI usage happened.
 * @return {Element} Screen.
 */
export default function Forecasts( { navigate, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ explain, setExplain ] = useState( false );
	const [ filter, setFilter ] = useState( 'all' );

	const load = () => {
		setError( null );
		request( 'forecasts' )
			.then( ( d ) => {
				setData( d );
				const last = d.jobs && d.jobs[ 0 ];
				if ( last && ( last.status === 'running' || last.status === 'queued' ) ) {
					setJob( last );
				}
			} )
			.catch( setError );
	};
	useEffect( load, [] );

	const run = () =>
		request( 'forecasts/run', { method: 'POST', data: { explain } } )
			.then( setJob )
			.catch( ( err ) => toast.error( err ) );

	const rows = ( data ? data.pools : [] ).map( ( p ) => ( { ...p, ...( p.forecast || {} ), id: p.id, stock: p.stock } ) );
	const groups = {
		all: rows,
		now: rows.filter( ( r ) => r.urgency === 'now' || r.urgency === 'out' ),
		soon: rows.filter( ( r ) => r.urgency === 'soon' ),
		ok: rows.filter( ( r ) => r.urgency === 'ok' || r.urgency === 'idle' ),
	};

	return (
		<>
			<PageHeader
				title={ __( 'Forecasts & reorders', TD ) }
				description={ __( 'Statistics first: a blended daily rate, trend, weekday pattern and a 95% safety buffer set the stockout date, reorder date and quantity.', TD ) }
				actions={
					<Button variant="primary" icon="refresh" onClick={ run } disabled={ job && ( job.status === 'running' || job.status === 'queued' ) }>
						{ __( 'Recalculate all', TD ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card>
				<Toggle label={ __( 'Also explain at-risk pools with DEV3 AI', TD ) } checked={ explain } onChange={ setExplain } help={ __( 'Adds a plain-language AI summary for pools that need a reorder soon. Uses your Gemini key.', TD ) } />
				{ job && (
					<JobProgress
						jobId={ job.id }
						job={ job }
						title={ __( 'Forecasting pools', TD ) }
						onDone={ ( j ) => {
							toast.success( j.failed ? __( 'Forecasts finished with some errors.', TD ) : __( 'Forecasts updated.', TD ) );
							onUsage();
							load();
						} }
					/>
				) }
			</Card>
			<StatGrid>
				<Stat label={ __( 'Reorder now', TD ) } value={ qty( groups.now.length ) } tone={ groups.now.length ? 'danger' : 'success' } icon="alert" />
				<Stat label={ __( 'Within 7 days', TD ) } value={ qty( groups.soon.length ) } tone={ groups.soon.length ? 'warning' : 'neutral' } icon="clock" />
				<Stat label={ __( 'Healthy or idle', TD ) } value={ qty( groups.ok.length ) } tone="success" icon="check" />
			</StatGrid>
			<Card flush>
				<div className="dev3ss-toolbar">
					<Tabs
						label={ __( 'Filter forecasts', TD ) }
						value={ filter }
						onChange={ setFilter }
						tabs={ [
							{ id: 'all', label: __( 'All', TD ), badge: String( groups.all.length ) },
							{ id: 'now', label: __( 'Reorder now', TD ), badge: String( groups.now.length ) },
							{ id: 'soon', label: __( 'Soon', TD ), badge: String( groups.soon.length ) },
							{ id: 'ok', label: __( 'Healthy', TD ), badge: String( groups.ok.length ) },
						] }
					/>
				</div>
				<ResponsiveTable
					caption={ __( 'Forecasts', TD ) }
					loading={ ! data && ! error }
					rows={ groups[ filter ] }
					pageSize={ 25 }
					defaultSort={ { key: 'days_left', dir: 'asc' } }
					onRowClick={ ( r ) => navigate( `pools/${ r.id }` ) }
					empty={ { icon: 'chart', title: __( 'Nothing here', TD ), description: __( 'Forecasts appear for active pools once they have sales history.', TD ) } }
					columns={ [
						{ key: 'name', label: __( 'Pool', TD ), primary: true, sortable: true, render: ( r ) => <a href={ `#/pools/${ r.id }` }>{ r.name }</a> },
						{ key: 'stock', label: __( 'Stock', TD ), align: 'end', sortable: true, nowrap: true, render: ( r ) => withUnit( r.stock, r.unit ) },
						{ key: 'rate_per_day', label: __( 'Per day', TD ), align: 'end', sortable: true, render: ( r ) => qty( r.rate_per_day ) },
						{ key: 'spark', label: __( 'Last 30 days', TD ), hideOnMobile: true, render: ( r ) => <Sparkline values={ Object.values( r.series || {} ) } /> },
						{ key: 'trend_pct', label: __( 'Trend', TD ), align: 'end', hideOnMobile: true, sortable: true, render: ( r ) => ( r.history_days >= 14 ? `${ r.trend_pct > 0 ? '+' : '' }${ qty( r.trend_pct ) }%` : '–' ) },
						{ key: 'days_left', label: __( 'Days left', TD ), align: 'end', sortable: true, nowrap: true, sortValue: ( r ) => ( r.days_left === null || r.days_left === undefined ? 1e9 : r.days_left ), render: ( r ) => daysText( r.days_left ) },
						{ key: 'reorder_date', label: __( 'Reorder by', TD ), sortable: true, nowrap: true, render: ( r ) => reorderBy( r ) },
						{ key: 'reorder_qty', label: __( 'Suggested qty', TD ), align: 'end', sortable: true, nowrap: true, render: ( r ) => ( r.reorder_qty ? withUnit( r.reorder_qty, r.unit ) : '–' ) },
						{ key: 'urgency', label: __( 'Status', TD ), render: ( r ) => <UrgencyBadge urgency={ r.urgency } /> },
					] }
				/>
			</Card>
			<p className="dev3ss-muted">{ sprintf( /* translators: %s: setting link text */ __( 'Lead time comes from each pool; safety days and cover days are in %s.', TD ), __( 'Settings', TD ) ) }</p>
		</>
	);
}
