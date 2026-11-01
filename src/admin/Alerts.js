import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, EmptyState, ErrorNotice, Inline, NumberField, PageHeader, SkeletonCard, Tabs, useToast } from '../core3/ui';
import { date, qty, TD } from './util';

const TYPE = {
	low_stock: __( 'Low stock', TD ),
	out_of_stock: __( 'Out of stock', TD ),
	anomaly: __( 'Anomaly', TD ),
	forecast: __( 'Forecast', TD ),
};

/**
 * Suggested correction shown as a draft: accept, edit or reject.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.alert    Alert.
 * @param {number}   props.value    Suggested stock.
 * @param {string}   props.source   rules|ai.
 * @param {Function} props.onFixed  Fixed handler.
 * @param {Function} props.onReject Reject handler.
 * @return {Element} Draft box.
 */
function FixDraft( { alert, value, source, onFixed, onReject } ) {
	const toast = useToast();
	const [ editing, setEditing ] = useState( false );
	const [ stock, setStock ] = useState( value );
	const [ busy, setBusy ] = useState( false );
	const apply = () => {
		setBusy( true );
		request( `alerts/${ alert.id }/fix`, { method: 'POST', data: { stock } } )
			.then( ( next ) => {
				toast.success( sprintf( /* translators: %s: stock */ __( 'Stock corrected to %s.', TD ), qty( stock ) ) );
				onFixed( next );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="dev3ss-fix">
			<div className="dev3ss-fix__head">
				<Badge tone="brand">{ source === 'ai' ? __( 'DEV3 AI suggestion', TD ) : __( 'Suggested fix', TD ) }</Badge>
				<span className="dev3ss-muted">{ __( 'Not applied yet', TD ) }</span>
			</div>
			{ editing ? (
				<NumberField label={ __( 'Correct stock level', TD ) } value={ stock } onChange={ setStock } min={ 0 } step="any" />
			) : (
				<p>
					{ sprintf( /* translators: 1: current, 2: suggested */ __( 'Set stock from %1$s back to %2$s.', TD ), qty( alert.detail.after ), qty( stock ) ) }
				</p>
			) }
			<Inline gap={ 8 }>
				<Button size="sm" variant="primary" icon="check" onClick={ apply } loading={ busy } disabled={ stock === '' }>
					{ __( 'Accept', TD ) }
				</Button>
				<Button size="sm" icon="edit" onClick={ () => setEditing( ! editing ) }>
					{ editing ? __( 'Done', TD ) : __( 'Edit', TD ) }
				</Button>
				<Button size="sm" variant="ghost" icon="x" onClick={ onReject }>
					{ __( 'Reject', TD ) }
				</Button>
			</Inline>
		</div>
	);
}

/**
 * One alert row.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.alert    Alert.
 * @param {Function} props.onChange Updated alert.
 * @param {Function} props.onUsage  AI usage.
 * @return {Element} Row.
 */
function AlertItem( { alert, onChange, onUsage } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ rejected, setRejected ] = useState( false );
	const ai = alert.detail.ai;
	const suggested = ai && ai.suggested_stock !== null && ai.suggested_stock !== undefined ? { value: ai.suggested_stock, source: 'ai' } : alert.detail.suggested !== null && alert.detail.suggested !== undefined ? { value: alert.detail.suggested, source: 'rules' } : null;
	const post = ( action ) => {
		setBusy( action );
		setError( null );
		request( `alerts/${ alert.id }/${ action }`, { method: 'POST' } )
			.then( onChange )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				if ( action === 'explain' ) {
					onUsage();
				}
			} );
	};
	return (
		<li className={ `dev3ss-alert is-${ alert.severity }` }>
			<div className="dev3ss-alert__main">
				<Inline gap={ 8 }>
					<Badge tone={ alert.severity === 'danger' ? 'danger' : alert.severity === 'warning' ? 'warning' : 'info' } dot>
						{ TYPE[ alert.type ] || alert.type }
					</Badge>
					{ alert.pool_name && <a href={ `#/pools/${ alert.pool_id }` }>{ alert.pool_name }</a> }
					<span className="dev3ss-muted">{ date( alert.created_at, true ) }</span>
				</Inline>
				<p className="dev3ss-alert__msg">{ alert.message }</p>
				{ ai && (
					<div className="dev3ss-ai-box">
						<strong>{ ai.likely_cause }</strong>
						<p>{ ai.explanation }</p>
						<span className="dev3ss-muted">{ sprintf( /* translators: %s: confidence */ __( 'Confidence: %s', TD ), ai.confidence ) }</span>
					</div>
				) }
				{ suggested && ! rejected && alert.status === 'open' && ! alert.detail.fixed_to && <FixDraft alert={ alert } value={ suggested.value } source={ suggested.source } onFixed={ onChange } onReject={ () => setRejected( true ) } /> }
				{ alert.detail.fixed_to !== undefined && <p className="dev3ss-muted">{ sprintf( /* translators: %s: stock */ __( 'Corrected to %s.', TD ), qty( alert.detail.fixed_to ) ) }</p> }
				<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
			</div>
			<div className="dev3ss-alert__actions">
				{ alert.type === 'anomaly' && ! ai && (
					<AiButton size="sm" onClick={ () => post( 'explain' ) } loading={ busy === 'explain' } loadingText={ __( 'Reviewing…', TD ) }>
						{ __( 'Explain with DEV3 AI', TD ) }
					</AiButton>
				) }
				{ alert.status === 'open' ? (
					<Button size="sm" variant="ghost" onClick={ () => post( 'dismiss' ) } loading={ busy === 'dismiss' }>
						{ __( 'Dismiss', TD ) }
					</Button>
				) : (
					<Button size="sm" variant="ghost" onClick={ () => post( 'reopen' ) } loading={ busy === 'reopen' }>
						{ __( 'Reopen', TD ) }
					</Button>
				) }
			</div>
		</li>
	);
}

/**
 * Alerts screen: low stock, out of stock, forecast risk, anomalies (sudden drops, likely typos).
 *
 * @param {Object}   props          Props.
 * @param {Function} props.onChange Open count changed.
 * @param {Function} props.onUsage  AI usage.
 * @return {Element} Screen.
 */
export default function Alerts( { onChange, onUsage } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( 'open' );
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ scanning, setScanning ] = useState( false );

	const load = () => {
		setRows( null );
		setError( null );
		request( `alerts?status=${ status }` )
			.then( ( r ) => {
				setRows( r );
				if ( status === 'open' && onChange ) {
					onChange( r.length );
				}
			} )
			.catch( setError );
	};
	useEffect( load, [ status ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const scan = () => {
		setScanning( true );
		request( 'alerts/scan', { method: 'POST' } )
			.then( ( r ) => {
				toast.info( r.raised ? sprintf( /* translators: %d: alerts */ __( '%d new anomalies found.', TD ), r.raised ) : __( 'No new anomalies.', TD ) );
				if ( status === 'open' ) {
					setRows( r.alerts );
					onChange( r.alerts.length );
				}
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setScanning( false ) );
	};

	const replace = ( next ) => {
		const list = rows.map( ( r ) => ( r.id === next.id ? next : r ) ).filter( ( r ) => status === 'all' || r.status === status );
		setRows( list );
		if ( status === 'open' ) {
			onChange( list.length );
		}
	};

	return (
		<>
			<PageHeader
				title={ __( 'Alerts', TD ) }
				description={ __( 'Low and out-of-stock pools, pools that will run out before a delivery can arrive, sudden stock drops and likely data-entry errors.', TD ) }
				actions={
					<Button icon="search" onClick={ scan } loading={ scanning }>
						{ __( 'Scan for anomalies', TD ) }
					</Button>
				}
			/>
			<Tabs
				label={ __( 'Alert status', TD ) }
				value={ status }
				onChange={ setStatus }
				tabs={ [
					{ id: 'open', label: __( 'Open', TD ) },
					{ id: 'dismissed', label: __( 'Dismissed', TD ) },
					{ id: 'all', label: __( 'All', TD ) },
				] }
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			{ ! rows && ! error && <SkeletonCard lines={ 4 } /> }
			{ rows && (
				<Card flush>
					{ rows.length ? (
						<ul className="dev3ss-alerts">
							{ rows.map( ( a ) => (
								<AlertItem key={ a.id } alert={ a } onChange={ replace } onUsage={ onUsage } />
							) ) }
						</ul>
					) : (
						<EmptyState icon="check" title={ status === 'open' ? __( 'No open alerts', TD ) : __( 'Nothing here', TD ) } description={ __( 'Alerts are raised as stock changes and by the daily scan.', TD ) } />
					) }
				</Card>
			) }
		</>
	);
}
