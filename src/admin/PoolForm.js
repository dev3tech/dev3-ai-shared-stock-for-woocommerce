import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FormGrid, NumberField, Select, Textarea, TextField, Toggle } from '../core3/ui';
import { TD } from './util';

export const EMPTY_POOL = {
	name: '',
	sku: '',
	unit: 'units',
	stock: 0,
	low_threshold: 5,
	backorders: false,
	status: 'active',
	supplier_name: '',
	supplier_email: '',
	lead_time_days: 7,
	unit_cost: 0,
	notes: '',
};

/**
 * Pool fields (create + edit). Controlled by the parent through value/onChange.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    Values.
 * @param {Function} props.onChange Change handler (full object).
 * @param {boolean}  props.isNew    Show the opening stock field.
 * @param {Object}   props.errors   Field errors.
 * @return {Element} Form fields.
 */
export default function PoolForm( { value, onChange, isNew = false, errors = {} } ) {
	const set = ( key ) => ( v ) => onChange( { ...value, [ key ]: v } );
	return (
		<>
			<FormGrid>
				<TextField label={ __( 'Pool name', TD ) } value={ value.name } onChange={ set( 'name' ) } required error={ errors.name } placeholder={ __( 'e.g. Cola cans', TD ) } data-autofocus />
				<TextField label={ __( 'Pool SKU', TD ) } value={ value.sku } onChange={ set( 'sku' ) } help={ __( 'Optional. Used to match rows in CSV imports.', TD ) } />
				{ isNew && <NumberField label={ __( 'Opening stock', TD ) } value={ value.stock } onChange={ set( 'stock' ) } step="any" help={ __( 'Physical units you have now.', TD ) } /> }
				<TextField label={ __( 'Unit', TD ) } value={ value.unit } onChange={ set( 'unit' ) } placeholder={ __( 'units, cans, metres, kg', TD ) } />
				<NumberField label={ __( 'Low-stock threshold', TD ) } value={ value.low_threshold } onChange={ set( 'low_threshold' ) } min={ 0 } step="any" help={ __( 'Alert when stock falls to this level.', TD ) } />
				<Select
					label={ __( 'Status', TD ) }
					value={ value.status }
					onChange={ set( 'status' ) }
					options={ [
						{ value: 'active', label: __( 'Active: sales deduct from this pool', TD ) },
						{ value: 'paused', label: __( 'Paused: ignored by orders and carts', TD ) },
					] }
				/>
			</FormGrid>
			<Toggle label={ __( 'Allow backorders', TD ) } checked={ !! value.backorders } onChange={ set( 'backorders' ) } help={ __( 'Keep selling when the pool reaches zero. Linked products are set to "Allow, but notify customer".', TD ) } />
			<h3 className="dev3ss-subhead">{ __( 'Supplier', TD ) }</h3>
			<FormGrid>
				<TextField label={ __( 'Supplier name', TD ) } value={ value.supplier_name } onChange={ set( 'supplier_name' ) } />
				<TextField label={ __( 'Supplier email', TD ) } type="email" value={ value.supplier_email } onChange={ set( 'supplier_email' ) } error={ errors.supplier_email } help={ __( 'Purchase orders are emailed here.', TD ) } />
				<NumberField label={ __( 'Lead time (days)', TD ) } value={ value.lead_time_days } onChange={ set( 'lead_time_days' ) } min={ 0 } max={ 365 } help={ __( 'Days from ordering to delivery. Drives reorder dates.', TD ) } />
				<NumberField label={ __( 'Unit cost', TD ) } value={ value.unit_cost } onChange={ set( 'unit_cost' ) } min={ 0 } step="any" />
			</FormGrid>
			<Textarea label={ __( 'Notes', TD ) } value={ value.notes } onChange={ set( 'notes' ) } rows={ 2 } maxLength={ 2000 } />
		</>
	);
}

/**
 * Hook: form state + server error mapping.
 *
 * @param {Object} initial Initial values.
 * @return {Array} [ value, setValue, errors, setFromError ]
 */
export function usePoolForm( initial ) {
	const [ value, setValue ] = useState( { ...EMPTY_POOL, ...( initial || {} ) } );
	const [ errors, setErrors ] = useState( {} );
	const setFromError = ( err ) => {
		const map = { dev3ss_pool_name: 'name', dev3ss_pool_sku: 'sku', dev3ss_pool_email: 'supplier_email' };
		const key = err && map[ err.code ];
		setErrors( key ? { [ key ]: err.message } : {} );
		return !! key;
	};
	return [ value, ( v ) => ( setValue( v ), setErrors( {} ) ), errors, setFromError ];
}
