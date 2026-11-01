import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, EmptyState, Modal, Notice, NumberField, Select, Skeleton, Tabs, TextField } from '../core3/ui';
import { qty, TD } from './util';

const TYPE_LABEL = {
	simple: __( 'Simple', TD ),
	variable: __( 'Variable (all variations)', TD ),
	variation: __( 'Variation', TD ),
};

/**
 * Product search with a selectable result list.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.selected Selected product.
 * @param {Function} props.onSelect Select handler.
 * @return {Element} Picker.
 */
export function ProductPicker( { selected, onSelect } ) {
	const [ q, setQ ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const timer = useRef();
	useEffect( () => {
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => {
			setResults( null );
			request( `products/search?q=${ encodeURIComponent( q.trim() ) }` )
				.then( setResults )
				.catch( () => setResults( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer.current );
	}, [ q ] );
	return (
		<div className="dev3ss-picker">
			<TextField label={ __( 'Find a product or variation', TD ) } type="search" value={ q } onChange={ setQ } placeholder={ __( 'Name or SKU', TD ) } data-autofocus />
			<div className="dev3ss-picker__list" role="listbox" aria-label={ __( 'Products', TD ) }>
				{ results === null && <Skeleton lines={ 4 } /> }
				{ results && ! results.length && <EmptyState compact icon="search" title={ __( 'No products found', TD ) } /> }
				{ results &&
					results.map( ( p ) => (
						<button type="button" key={ p.id } role="option" aria-selected={ selected && selected.id === p.id } className={ selected && selected.id === p.id ? 'dev3ss-picker__item is-selected' : 'dev3ss-picker__item' } onClick={ () => onSelect( p ) }>
							<span className="dev3ss-picker__name">{ p.name }</span>
							<span className="dev3ss-picker__meta">
								<Badge>{ TYPE_LABEL[ p.type ] || p.type }</Badge>
								{ p.sku && <span>{ p.sku }</span> }
								{ p.stock !== null && <span>{ sprintf( /* translators: %s: stock */ __( 'stock %s', TD ), qty( p.stock ) ) }</span> }
								{ p.pools > 0 && <Badge tone="info">{ sprintf( /* translators: %d: pools */ __( 'in %d pool(s)', TD ), p.pools ) }</Badge> }
							</span>
						</button>
					) ) }
			</div>
		</div>
	);
}

/**
 * Modal to add a product/variation link or an attribute rule to a pool.
 *
 * @param {Object}   props        Props.
 * @param {boolean}  props.open   Open.
 * @param {Object}   props.pool   Pool.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onSaved Called with the updated pool.
 * @return {Element} Modal.
 */
export default function LinkEditor( { open, pool, onClose, onSaved } ) {
	const [ tab, setTab ] = useState( 'product' );
	const [ product, setProduct ] = useState( null );
	const [ amount, setAmount ] = useState( 1 );
	const [ attrs, setAttrs ] = useState( null );
	const [ attrKey, setAttrKey ] = useState( '' );
	const [ attrValue, setAttrValue ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( open && tab === 'attribute' && attrs === null ) {
			request( 'attributes' )
				.then( setAttrs )
				.catch( () => setAttrs( [] ) );
		}
	}, [ open, tab, attrs ] );
	useEffect( () => {
		if ( open ) {
			setProduct( null );
			setAmount( 1 );
			setError( '' );
		}
	}, [ open ] );

	const attr = ( attrs || [] ).find( ( a ) => a.key === attrKey );
	const canSave = amount > 0 && ( tab === 'product' ? !! product : attrKey && attrValue );

	const save = () => {
		setBusy( true );
		setError( '' );
		const data = tab === 'product' ? { product_id: product.id, qty: amount } : { product_id: product ? product.id : 0, attr_key: attrKey, attr_value: attrValue, qty: amount };
		request( `pools/${ pool.id }/links`, { method: 'POST', data } )
			.then( ( next ) => {
				onSaved( next );
				onClose();
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			open={ open }
			onClose={ onClose }
			size="lg"
			title={ sprintf( /* translators: %s: pool */ __( 'Link to %s', TD ), pool.name ) }
			description={ __( 'Every sale of a linked item deducts "units per sale" from this pool.', TD ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', TD ) }
					</Button>
					<Button variant="primary" onClick={ save } disabled={ ! canSave } loading={ busy }>
						{ __( 'Add link', TD ) }
					</Button>
				</>
			}
		>
			<Tabs
				label={ __( 'Link type', TD ) }
				variant="pills"
				value={ tab }
				onChange={ setTab }
				tabs={ [
					{ id: 'product', label: __( 'Product or variation', TD ) },
					{ id: 'attribute', label: __( 'Attribute rule', TD ) },
				] }
			/>
			<div className="dev3ss-stack">
				{ tab === 'product' ? (
					<ProductPicker selected={ product } onSelect={ setProduct } />
				) : (
					<>
						<p className="dev3ss-muted">{ __( 'Stock rule by attribute value: every product or variation with this value draws from the pool, e.g. all "Linen" items share the linen fabric stock.', TD ) }</p>
						{ attrs && ! attrs.length && <Notice tone="info">{ __( 'No global attributes yet. Create them under Products > Attributes.', TD ) }</Notice> }
						<div className="dev3ss-grid2">
							<Select
								label={ __( 'Attribute', TD ) }
								value={ attrKey }
								onChange={ ( v ) => {
									setAttrKey( v );
									setAttrValue( '' );
								} }
								placeholder={ __( 'Choose…', TD ) }
								options={ ( attrs || [] ).map( ( a ) => ( { value: a.key, label: a.label } ) ) }
							/>
							<Select label={ __( 'Value', TD ) } value={ attrValue } onChange={ setAttrValue } placeholder={ __( 'Choose…', TD ) } options={ ( attr ? attr.terms : [] ).map( ( t ) => ( { value: t.slug, label: t.name } ) ) } disabled={ ! attr } />
						</div>
						<details className="dev3ss-details">
							<summary>{ __( 'Limit the rule to one product (optional)', TD ) }</summary>
							<ProductPicker selected={ product } onSelect={ ( p ) => setProduct( product && product.id === p.id ? null : p ) } />
						</details>
					</>
				) }
				<NumberField label={ __( 'Units per sale', TD ) } value={ amount } onChange={ setAmount } min={ 0 } step="any" help={ sprintf( /* translators: %s: unit */ __( 'Pool %s used by one item sold. A 6-pack uses 6; half a metre of fabric uses 0.5.', TD ), pool.unit || __( 'units', TD ) ) } />
				{ error && <Notice tone="error">{ error }</Notice> }
			</div>
		</Modal>
	);
}
