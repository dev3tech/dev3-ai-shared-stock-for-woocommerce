import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Drawer, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, TextField, useToast } from '../core3/ui';
import PoolForm, { usePoolForm } from './PoolForm';
import { daysText, StockBadge, TD, UrgencyBadge, withUnit } from './util';

/**
 * Pool list with search, status filter and a create drawer.
 *
 * @param {Object}   props            Props.
 * @param {Function} props.navigate   Router.
 * @param {boolean}  props.createOpen Open the create drawer on mount (#/pools/new).
 * @return {Element} Screen.
 */
export default function Pools( { navigate, createOpen = false } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ open, setOpen ] = useState( createOpen );
	const [ busy, setBusy ] = useState( false );
	const [ form, setForm, errors, setFromError ] = usePoolForm();

	const load = () => {
		setError( null );
		request( 'pools' ).then( setRows ).catch( setError );
	};
	useEffect( load, [] );

	const close = () => {
		setOpen( false );
		if ( createOpen ) {
			navigate( 'pools' );
		}
	};

	const create = ( e ) => {
		e.preventDefault();
		setBusy( true );
		request( 'pools', { method: 'POST', data: { ...form, stock: form.stock === '' ? 0 : form.stock } } )
			.then( ( pool ) => {
				toast.success( __( 'Pool created. Now link the products that share it.', TD ) );
				navigate( `pools/${ pool.id }` );
			} )
			.catch( ( err ) => {
				if ( ! setFromError( err ) ) {
					toast.error( err );
				}
			} )
			.finally( () => setBusy( false ) );
	};

	const q = search.trim().toLowerCase();
	const visible = ( rows || [] ).filter( ( p ) => ( ! status || p.status === status || ( status === 'low' && p.stock <= p.low_threshold ) ) && ( ! q || p.name.toLowerCase().includes( q ) || p.sku.toLowerCase().includes( q ) ) );

	return (
		<>
			<PageHeader
				title={ __( 'Stock pools', TD ) }
				description={ __( 'A pool is one physical stock that several products, variations or attribute values sell from.', TD ) }
				actions={
					<>
						<Button variant="primary" icon="plus" onClick={ () => setOpen( true ) }>
							{ __( 'New pool', TD ) }
						</Button>
						<Button variant="ai" icon="sparkles" href="#/setup">
							{ __( 'Set up pools for me', TD ) }
						</Button>
					</>
				}
			/>
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<div className="dev3ss-toolbar">
					<Inline gap={ 12 }>
						<TextField label={ __( 'Search pools', TD ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search by name or SKU', TD ) } />
						<Select
							label={ __( 'Filter by status', TD ) }
							hideLabel
							value={ status }
							onChange={ setStatus }
							options={ [
								{ value: '', label: __( 'All pools', TD ) },
								{ value: 'active', label: __( 'Active', TD ) },
								{ value: 'paused', label: __( 'Paused', TD ) },
								{ value: 'low', label: __( 'Low or out of stock', TD ) },
							] }
						/>
					</Inline>
				</div>
				<ResponsiveTable
					caption={ __( 'Stock pools', TD ) }
					loading={ ! rows && ! error }
					rows={ visible }
					pageSize={ 20 }
					defaultSort={ { key: 'name', dir: 'asc' } }
					onRowClick={ ( r ) => navigate( `pools/${ r.id }` ) }
					empty={
						rows && rows.length
							? { icon: 'search', title: __( 'No pools match', TD ), description: __( 'Try another search or filter.', TD ) }
							: {
									icon: 'box',
									title: __( 'No pools yet', TD ),
									description: __( 'Create your first pool, or let DEV3 AI read your catalogue and propose pools.', TD ),
									action: (
										<Button variant="primary" onClick={ () => setOpen( true ) }>
											{ __( 'Create a pool', TD ) }
										</Button>
									),
							  }
					}
					columns={ [
						{ key: 'name', label: __( 'Pool', TD ), primary: true, sortable: true, render: ( r ) => ( <span><a href={ `#/pools/${ r.id }` }>{ r.name }</a>{ r.sku && <span className="dev3ss-muted dev3ss-sku">{ r.sku }</span> }</span> ) },
						{ key: 'stock', label: __( 'Stock', TD ), sortable: true, align: 'end', nowrap: true, render: ( r ) => withUnit( r.stock, r.unit ) },
						{ key: 'links', label: __( 'Linked', TD ), sortable: true, align: 'end', hideOnMobile: true },
						{ key: 'days', label: __( 'Days left', TD ), sortable: true, align: 'end', nowrap: true, sortValue: ( r ) => ( r.forecast && r.forecast.days_left !== null ? r.forecast.days_left : 1e9 ), render: ( r ) => ( r.forecast ? daysText( r.forecast.days_left ) : '–' ) },
						{ key: 'level', label: __( 'Level', TD ), render: ( r ) => <StockBadge pool={ r } /> },
						{ key: 'urgency', label: __( 'Forecast', TD ), hideOnMobile: true, render: ( r ) => ( r.forecast ? <UrgencyBadge urgency={ r.forecast.urgency } /> : '–' ) },
					] }
				/>
			</Card>

			<Drawer
				open={ open }
				onClose={ close }
				size="md"
				title={ __( 'New stock pool', TD ) }
				description={ __( 'You can link products right after creating it.', TD ) }
				footer={
					<>
						<Button variant="ghost" onClick={ close }>
							{ __( 'Cancel', TD ) }
						</Button>
						<Button variant="primary" type="submit" form="dev3ss-new-pool" loading={ busy }>
							{ __( 'Create pool', TD ) }
						</Button>
					</>
				}
			>
				<form id="dev3ss-new-pool" onSubmit={ create } className="dev3ss-stack">
					<PoolForm value={ form } onChange={ setForm } isNew errors={ errors } />
				</form>
			</Drawer>
		</>
	);
}
