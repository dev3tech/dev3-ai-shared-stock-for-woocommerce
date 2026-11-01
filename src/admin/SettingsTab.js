import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, FormGrid, NumberField, PageHeader, Select, Textarea, TextField, Toggle, useToast } from '../core3/ui';
import { TD } from './util';

export const KEYS = [
	'inventory_enabled',
	'mirror_stock',
	'cart_guard',
	'bundle_mode',
	'default_threshold',
	'default_lead_time',
	'alerts_email',
	'alert_recipient',
	'anomaly_detection',
	'anomaly_sensitivity',
	'forecast_window',
	'safety_days',
	'cover_days',
	'ai_forecast_explain',
	'ai_po_emails',
	'ai_setup',
	'ai_anomalies',
	'ai_ask',
	'po_company',
	'po_signature',
];

/**
 * Plugin settings (keys registered in includes/Settings.php).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' ? settings[ k ] : form[ k ];
		} );
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', TD ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const footer = (
		<>
			<Button variant="ghost" onClick={ () => setForm( settings ) } disabled={ ! dirty }>
				{ __( 'Discard changes', TD ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save settings', TD ) }
			</Button>
		</>
	);

	return (
		<form onSubmit={ submit } className="dev3ss-stack">
			<PageHeader title={ __( 'Settings', TD ) } description={ __( 'How pools deduct, sync and alert, and which DEV3 AI features are on.', TD ) } />
			<Card title={ __( 'Stock deduction', TD ) }>
				<div className="dev3ss-stack">
					<Toggle label={ __( 'Deduct pool stock for orders', TD ) } checked={ !! form.inventory_enabled } onChange={ set( 'inventory_enabled' ) } help={ __( 'Orders, refunds with restock, cancellations and order edits move pool stock. Turn off to pause all deduction.', TD ) } />
					<Toggle label={ __( 'Keep product stock in sync with pools', TD ) } checked={ !! form.mirror_stock } onChange={ set( 'mirror_stock' ) } help={ __( 'Sets each linked product\'s WooCommerce stock to what its pools can supply, so the storefront, feeds and the block checkout show the shared stock.', TD ) } />
					<Toggle label={ __( 'Check the whole cart against pools', TD ) } checked={ !! form.cart_guard } onChange={ set( 'cart_guard' ) } help={ __( 'Stops two products from the same pool being bought beyond what the pool holds (classic and block cart/checkout).', TD ) } />
					<Select
						label={ __( 'Bundles and composite products', TD ) }
						value={ form.bundle_mode }
						onChange={ set( 'bundle_mode' ) }
						help={ __( 'Bundle plugins add the bundle and each bundled item as order lines. Choose which lines deduct when they are linked.', TD ) }
						options={ [
							{ value: 'both', label: __( 'Bundle and bundled items (whatever is linked)', TD ) },
							{ value: 'children', label: __( 'Only the bundled items', TD ) },
							{ value: 'container', label: __( 'Only the bundle itself', TD ) },
						] }
					/>
					<FormGrid>
						<NumberField label={ __( 'Default low-stock threshold', TD ) } value={ form.default_threshold } onChange={ set( 'default_threshold' ) } min={ 0 } />
						<NumberField label={ __( 'Default supplier lead time (days)', TD ) } value={ form.default_lead_time } onChange={ set( 'default_lead_time' ) } min={ 0 } max={ 365 } />
					</FormGrid>
				</div>
			</Card>
			<Card title={ __( 'Alerts', TD ) }>
				<div className="dev3ss-stack">
					<Toggle label={ __( 'Email low-stock and out-of-stock alerts', TD ) } checked={ !! form.alerts_email } onChange={ set( 'alerts_email' ) } />
					<TextField label={ __( 'Alert recipients', TD ) } value={ form.alert_recipient } onChange={ set( 'alert_recipient' ) } placeholder={ __( 'Site admin email', TD ) } help={ __( 'Up to 5 addresses, separated by commas.', TD ) } />
					<Toggle label={ __( 'Detect anomalies', TD ) } checked={ !! form.anomaly_detection } onChange={ set( 'anomaly_detection' ) } help={ __( 'Sudden stock drops and likely typos in manual changes or imports (an extra zero, swapped digits, below zero).', TD ) } />
					<Select
						label={ __( 'Anomaly sensitivity', TD ) }
						value={ form.anomaly_sensitivity }
						onChange={ set( 'anomaly_sensitivity' ) }
						options={ [
							{ value: 'low', label: __( 'Low: only extreme drops', TD ) },
							{ value: 'medium', label: __( 'Medium', TD ) },
							{ value: 'high', label: __( 'High: flag smaller spikes', TD ) },
						] }
					/>
				</div>
			</Card>
			<Card title={ __( 'Forecasts and reorders', TD ) }>
				<FormGrid>
					<NumberField label={ __( 'History window (days)', TD ) } value={ form.forecast_window } onChange={ set( 'forecast_window' ) } min={ 14 } max={ 365 } help={ __( 'Sales history used for forecasts.', TD ) } />
					<NumberField label={ __( 'Safety stock (days of sales)', TD ) } value={ form.safety_days } onChange={ set( 'safety_days' ) } min={ 0 } max={ 90 } help={ __( 'Minimum buffer; a statistical 95% buffer is used when larger.', TD ) } />
					<NumberField label={ __( 'Reorder covers (days)', TD ) } value={ form.cover_days } onChange={ set( 'cover_days' ) } min={ 1 } max={ 365 } help={ __( 'Days of sales a reorder should cover after it arrives.', TD ) } />
				</FormGrid>
			</Card>
			<Card tone="ai" title={ __( 'DEV3 AI features', TD ) } description={ __( 'Each feature has a non-AI fallback. AI results that would change data are shown as drafts you accept, edit or reject.', TD ) }>
				<div className="dev3ss-stack">
					<Toggle label={ __( 'Explain forecasts in plain words', TD ) } checked={ !! form.ai_forecast_explain } onChange={ set( 'ai_forecast_explain' ) } />
					<Toggle label={ __( 'Draft purchase-order emails', TD ) } checked={ !! form.ai_po_emails } onChange={ set( 'ai_po_emails' ) } />
					<Toggle label={ __( '"Set up pools for me"', TD ) } checked={ !! form.ai_setup } onChange={ set( 'ai_setup' ) } />
					<Toggle label={ __( 'Explain anomalies and suggest fixes', TD ) } checked={ !! form.ai_anomalies } onChange={ set( 'ai_anomalies' ) } />
					<Toggle label={ __( 'Ask-your-inventory chat', TD ) } checked={ !! form.ai_ask } onChange={ set( 'ai_ask' ) } />
				</div>
			</Card>
			<Card title={ __( 'Purchase orders', TD ) } footer={ footer }>
				<FormGrid>
					<TextField label={ __( 'Company name on POs', TD ) } value={ form.po_company } onChange={ set( 'po_company' ) } placeholder={ __( 'Site title', TD ) } />
				</FormGrid>
				<Textarea label={ __( 'Email signature', TD ) } value={ form.po_signature } onChange={ set( 'po_signature' ) } rows={ 3 } maxLength={ 500 } />
			</Card>
		</form>
	);
}
