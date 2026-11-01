import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Drawer, ErrorNotice, Inline, Modal, NumberField, PageHeader, ResponsiveTable, Tabs, useToast } from '../core3/ui';
import { date, PO_STATUS, TD, withUnit } from './util';

/**
 * Purchase orders: draft -> sent (emailed) -> received (adds stock) | cancelled.
 *
 * @return {Element} Screen.
 */
export default function PurchaseOrders() {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ status, setStatus ] = useState( '' );
	const [ view, setView ] = useState( null );
	const [ receive, setReceive ] = useState( null );
	const [ amount, setAmount ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );

	const load = () => {
		setError( null );
		request( 'purchase-orders' ).then( setRows ).catch( setError );
	};
	useEffect( load, [] );

	const act = ( po, action, data ) => {
		setBusy( `${ action }-${ po.id }` );
		return request( `purchase-orders/${ po.id }/${ action }`, { method: 'POST', data } )
			.then( ( next ) => {
				setRows( ( list ) => list.map( ( r ) => ( r.id === next.id ? next : r ) ) );
				if ( view && view.id === next.id ) {
					setView( next );
				}
				const msg = { send: __( 'Purchase order emailed.', TD ), receive: __( 'Received: stock added to the pool.', TD ), cancel: __( 'Purchase order cancelled.', TD ) };
				toast.success( msg[ action ] );
				return next;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	const visible = ( rows || [] ).filter( ( r ) => ! status || r.status === status );
	const count = ( s ) => String( ( rows || [] ).filter( ( r ) => r.status === s ).length );

	return (
		<>
			<PageHeader title={ __( 'Purchase orders', TD ) } description={ __( 'Draft them from a pool\'s reorder card (with DEV3 AI or a template), email them to the supplier, then mark them received to add the stock.', TD ) } />
			<ErrorNotice error={ error } onRetry={ load } />
			<Card flush>
				<div className="dev3ss-toolbar">
					<Tabs
						label={ __( 'Filter purchase orders', TD ) }
						value={ status }
						onChange={ setStatus }
						tabs={ [
							{ id: '', label: __( 'All', TD ) },
							{ id: 'draft', label: __( 'Drafts', TD ), badge: count( 'draft' ) },
							{ id: 'sent', label: __( 'Sent', TD ), badge: count( 'sent' ) },
							{ id: 'received', label: __( 'Received', TD ) },
						] }
					/>
				</div>
				<ResponsiveTable
					caption={ __( 'Purchase orders', TD ) }
					loading={ ! rows && ! error }
					rows={ visible }
					pageSize={ 20 }
					onRowClick={ setView }
					empty={ { icon: 'file', title: __( 'No purchase orders yet', TD ), description: __( 'Open a pool and use "Draft PO email with DEV3 AI" or "Use template".', TD ), action: <Button href="#/forecasts">{ __( 'See what to reorder', TD ) }</Button> } }
					columns={ [
						{ key: 'id', label: __( 'PO', TD ), primary: true, render: ( r ) => sprintf( /* translators: 1: id, 2: pool */ __( '#%1$d · %2$s', TD ), r.id, r.pool_name ) },
						{ key: 'supplier_name', label: __( 'Supplier', TD ), hideOnMobile: true, render: ( r ) => r.supplier_name || r.supplier_email || '–' },
						{ key: 'qty', label: __( 'Quantity', TD ), align: 'end', nowrap: true, render: ( r ) => withUnit( r.qty, r.unit ) },
						{ key: 'expected_date', label: __( 'Needed by', TD ), nowrap: true, render: ( r ) => date( r.expected_date ) },
						{ key: 'status', label: __( 'Status', TD ), render: ( r ) => <Badge tone={ ( PO_STATUS[ r.status ] || {} ).tone }>{ ( PO_STATUS[ r.status ] || {} ).label }</Badge> },
					] }
					rowActions={ ( r ) => (
						<Inline gap={ 4 }>
							{ r.status === 'draft' && (
								<Button size="sm" onClick={ ( e ) => ( e.stopPropagation(), act( r, 'send' ) ) } loading={ busy === `send-${ r.id }` } disabled={ ! r.supplier_email }>
									{ __( 'Send', TD ) }
								</Button>
							) }
							{ ( r.status === 'draft' || r.status === 'sent' ) && (
								<Button
									size="sm"
									variant="primary"
									onClick={ ( e ) => {
										e.stopPropagation();
										setAmount( r.qty );
										setReceive( r );
									} }
								>
									{ __( 'Receive', TD ) }
								</Button>
							) }
						</Inline>
					) }
				/>
			</Card>

			<Drawer
				open={ !! view }
				onClose={ () => setView( null ) }
				title={ view ? sprintf( /* translators: %d: id */ __( 'Purchase order #%d', TD ), view.id ) : '' }
				description={ view ? `${ view.pool_name } · ${ withUnit( view.qty, view.unit ) }` : '' }
				footer={
					view && (
						<>
							{ view.status !== 'received' && view.status !== 'cancelled' && (
								<Button variant="ghost" onClick={ () => act( view, 'cancel' ) } loading={ busy === `cancel-${ view.id }` }>
									{ __( 'Cancel PO', TD ) }
								</Button>
							) }
							{ view.status === 'draft' && (
								<Button variant="primary" onClick={ () => act( view, 'send' ) } loading={ busy === `send-${ view.id }` } disabled={ ! view.supplier_email }>
									{ __( 'Email to supplier', TD ) }
								</Button>
							) }
						</>
					)
				}
			>
				{ view && (
					<dl className="dev3ss-dl">
						<dt>{ __( 'Status', TD ) }</dt>
						<dd>
							<Badge tone={ ( PO_STATUS[ view.status ] || {} ).tone }>{ ( PO_STATUS[ view.status ] || {} ).label }</Badge>
						</dd>
						<dt>{ __( 'To', TD ) }</dt>
						<dd>{ view.supplier_email || __( 'No supplier email', TD ) }</dd>
						<dt>{ __( 'Needed by', TD ) }</dt>
						<dd>{ date( view.expected_date ) }</dd>
						<dt>{ __( 'Subject', TD ) }</dt>
						<dd>{ view.subject }</dd>
						<dt>{ __( 'Email', TD ) }</dt>
						<dd>
							<pre className="dev3ss-mail">{ view.body }</pre>
						</dd>
						{ view.sent_at && (
							<>
								<dt>{ __( 'Sent', TD ) }</dt>
								<dd>{ date( view.sent_at, true ) }</dd>
							</>
						) }
						{ view.received_at && (
							<>
								<dt>{ __( 'Received', TD ) }</dt>
								<dd>{ date( view.received_at, true ) }</dd>
							</>
						) }
					</dl>
				) }
			</Drawer>

			<Modal
				open={ !! receive }
				onClose={ () => setReceive( null ) }
				size="sm"
				title={ __( 'Receive delivery', TD ) }
				description={ receive ? sprintf( /* translators: %s: pool */ __( 'Adds the received quantity to %s.', TD ), receive.pool_name ) : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setReceive( null ) }>
							{ __( 'Cancel', TD ) }
						</Button>
						<Button variant="primary" loading={ receive && busy === `receive-${ receive.id }` } disabled={ ! amount } onClick={ () => act( receive, 'receive', { qty: amount } ).then( () => setReceive( null ) ) }>
							{ __( 'Add to stock', TD ) }
						</Button>
					</>
				}
			>
				<NumberField label={ __( 'Quantity received', TD ) } value={ amount } onChange={ setAmount } min={ 0 } step="any" data-autofocus />
			</Modal>
		</>
	);
}
