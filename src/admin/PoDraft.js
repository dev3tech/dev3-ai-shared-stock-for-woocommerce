import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Button, ErrorNotice, FormGrid, Inline, NumberField, TextField, useToast } from '../core3/ui';
import { TD, withUnit } from './util';

const FIELDS = [
	{ key: 'subject', label: __( 'Subject', TD ), maxLength: 255 },
	{ key: 'body', label: __( 'Email', TD ), multiline: true, maxLength: 6000 },
];

/**
 * Purchase-order email: draft with DEV3 AI (or a template), review, accept as a draft PO, then send.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.pool    Pool (with forecast).
 * @param {Function} props.onSaved Called with the saved PO.
 * @param {Function} props.onUsage AI usage happened.
 * @return {Element} Panel.
 */
export default function PoDraft( { pool, onSaved, onUsage } ) {
	const toast = useToast();
	const f = pool.forecast || {};
	const [ amount, setAmount ] = useState( f.reorder_qty || '' );
	const [ when, setWhen ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ loading, setLoading ] = useState( '' );
	const [ accepting, setAccepting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ sending, setSending ] = useState( false );

	const generate = ( ai ) => {
		setLoading( ai ? 'ai' : 'template' );
		setError( null );
		setSaved( null );
		request( `pools/${ pool.id }/po-draft`, { method: 'POST', data: { qty: amount === '' ? null : amount, date: when, ai } } )
			.then( ( d ) => {
				setDraft( d );
				setAmount( d.qty );
				setWhen( d.expected_date );
			} )
			.catch( setError )
			.finally( () => {
				setLoading( '' );
				if ( ai && onUsage ) {
					onUsage();
				}
			} );
	};

	const accept = ( values ) => {
		setAccepting( true );
		request( 'purchase-orders', {
			method: 'POST',
			data: { pool_id: pool.id, qty: amount, expected_date: when, subject: values.subject, body: values.body, supplier_email: draft.supplier_email, supplier_name: draft.supplier_name, unit_cost: draft.unit_cost },
		} )
			.then( ( po ) => {
				setSaved( po );
				setDraft( null );
				toast.success( __( 'Saved as a draft purchase order.', TD ) );
				if ( onSaved ) {
					onSaved( po );
				}
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setAccepting( false ) );
	};

	const send = () => {
		setSending( true );
		request( `purchase-orders/${ saved.id }/send`, { method: 'POST' } )
			.then( ( po ) => {
				setSaved( po );
				toast.success( sprintf( /* translators: %s: email */ __( 'Purchase order emailed to %s.', TD ), po.supplier_email ) );
				if ( onSaved ) {
					onSaved( po );
				}
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setSending( false ) );
	};

	return (
		<div className="dev3ss-stack">
			<FormGrid>
				<NumberField label={ sprintf( /* translators: %s: unit */ __( 'Quantity (%s)', TD ), pool.unit || __( 'units', TD ) ) } value={ amount } onChange={ setAmount } min={ 0 } step="any" help={ f.reorder_qty ? sprintf( /* translators: %s: qty */ __( 'Suggested: %s', TD ), withUnit( f.reorder_qty, pool.unit ) ) : undefined } />
				<TextField label={ __( 'Needed by', TD ) } type="date" value={ when } onChange={ setWhen } help={ __( 'Blank = today + lead time.', TD ) } />
			</FormGrid>
			{ ! pool.supplier_email && <p className="dev3ss-muted">{ __( 'Tip: add a supplier email to the pool so the order can be sent from here.', TD ) }</p> }
			<Inline gap={ 8 }>
				<AiButton onClick={ () => generate( true ) } loading={ loading === 'ai' } disabled={ !! loading }>
					{ __( 'Draft PO email with DEV3 AI', TD ) }
				</AiButton>
				<Button variant="secondary" icon="file" onClick={ () => generate( false ) } loading={ loading === 'template' } disabled={ !! loading }>
					{ __( 'Use template', TD ) }
				</Button>
			</Inline>
			<ErrorNotice error={ error } onRetry={ () => generate( true ) } onDismiss={ () => setError( null ) } />
			{ draft && (
				<AiDraftReview
					title={ draft.source === 'ai' ? __( 'DEV3 AI purchase-order draft', TD ) : __( 'Template purchase-order draft', TD ) }
					fields={ FIELDS }
					draft={ { subject: draft.subject, body: draft.body } }
					meta={ draft.source === 'ai' ? { model: draft.model, tokens: draft.tokens, cached: draft.cached } : undefined }
					acceptLabel={ __( 'Save as draft PO', TD ) }
					onAccept={ accept }
					onReject={ () => setDraft( null ) }
					onRegenerate={ () => generate( draft.source === 'ai' ) }
					regenerating={ !! loading }
					accepting={ accepting }
				/>
			) }
			{ saved && (
				<div className="dev3ss-callout">
					<span>
						{ saved.status === 'sent'
							? sprintf( /* translators: 1: id, 2: email */ __( 'PO #%1$d sent to %2$s.', TD ), saved.id, saved.supplier_email )
							: sprintf( /* translators: %d: id */ __( 'PO #%d saved as a draft.', TD ), saved.id ) }
					</span>
					<Inline gap={ 8 }>
						{ saved.status === 'draft' && (
							<Button size="sm" variant="primary" icon="external" onClick={ send } loading={ sending } disabled={ ! saved.supplier_email }>
								{ __( 'Email to supplier', TD ) }
							</Button>
						) }
						<Button size="sm" variant="ghost" href="#/orders">
							{ __( 'All purchase orders', TD ) }
						</Button>
					</Inline>
				</div>
			) }
		</div>
	);
}
