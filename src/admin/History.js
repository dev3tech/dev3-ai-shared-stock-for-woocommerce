import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, TextField, useToast } from '../core3/ui';
import { date, download, qty, REASONS, TD } from './util';

/**
 * Stock history log with filters, server pagination and CSV export.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.params Route params ([ poolId ]).
 * @return {Element} Screen.
 */
export default function History( { params = [] } ) {
	const toast = useToast();
	const [ pools, setPools ] = useState( [] );
	const [ poolId, setPoolId ] = useState( params[ 0 ] || '' );
	const [ reason, setReason ] = useState( '' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const perPage = 25;

	useEffect( () => {
		request( 'pools' )
			.then( setPools )
			.catch( () => {} );
	}, [] );

	const query = `pool_id=${ poolId || 0 }&reason=${ reason }&from=${ from }&to=${ to }`;
	useEffect( () => {
		setData( null );
		setError( null );
		request( `history?${ query }&page=${ page }&per_page=${ perPage }` ).then( setData ).catch( setError );
	}, [ query, page ] );

	const exportCsv = () =>
		request( `export?type=history&pool_id=${ poolId || 0 }` )
			.then( ( r ) => download( r.filename, r.csv ) )
			.catch( ( err ) => toast.error( err ) );

	const reset = ( fn ) => ( v ) => {
		fn( v );
		setPage( 1 );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Stock history', TD ) }
				description={ __( 'Every change to every pool: orders, refunds, cancellations, order edits, manual changes, imports and deliveries.', TD ) }
				actions={
					<Button icon="copy" onClick={ exportCsv }>
						{ __( 'Export CSV', TD ) }
					</Button>
				}
			/>
			<ErrorNotice error={ error } />
			<Card flush>
				<div className="dev3ss-toolbar">
					<Inline gap={ 12 }>
						<Select label={ __( 'Pool', TD ) } value={ poolId } onChange={ reset( setPoolId ) } options={ [ { value: '', label: __( 'All pools', TD ) }, ...pools.map( ( p ) => ( { value: String( p.id ), label: p.name } ) ) ] } />
						<Select label={ __( 'Reason', TD ) } value={ reason } onChange={ reset( setReason ) } options={ [ { value: '', label: __( 'All reasons', TD ) }, ...Object.entries( REASONS ).map( ( [ value, label ] ) => ( { value, label } ) ) ] } />
						<TextField label={ __( 'From', TD ) } type="date" value={ from } onChange={ reset( setFrom ) } />
						<TextField label={ __( 'To', TD ) } type="date" value={ to } onChange={ reset( setTo ) } />
					</Inline>
				</div>
				<ResponsiveTable
					caption={ __( 'Stock history', TD ) }
					loading={ ! data && ! error }
					rows={ data ? data.rows : [] }
					pagination={ data ? { page, perPage, total: data.total, onPageChange: setPage } : undefined }
					empty={ { icon: 'clock', title: __( 'No changes match', TD ), description: __( 'Try other filters.', TD ) } }
					columns={ [
						{ key: 'created_at', label: __( 'When', TD ), primary: true, nowrap: true, render: ( r ) => date( r.created_at, true ) },
						{ key: 'pool_name', label: __( 'Pool', TD ), render: ( r ) => <a href={ `#/pools/${ r.pool_id }` }>{ r.pool_name || `#${ r.pool_id }` }</a> },
						{ key: 'reason', label: __( 'Reason', TD ), render: ( r ) => REASONS[ r.reason ] || r.reason },
						{ key: 'delta', label: __( 'Change', TD ), align: 'end', render: ( r ) => <span className={ r.delta < 0 ? 'dev3ss-delta is-neg' : 'dev3ss-delta is-pos' }>{ ( r.delta > 0 ? '+' : '' ) + qty( r.delta ) }</span> },
						{ key: 'stock_after', label: __( 'Stock after', TD ), align: 'end', render: ( r ) => `${ qty( r.stock_after ) } ${ r.unit || '' }` },
						{ key: 'note', label: __( 'Note', TD ), hideOnMobile: true },
					] }
				/>
			</Card>
		</>
	);
}
