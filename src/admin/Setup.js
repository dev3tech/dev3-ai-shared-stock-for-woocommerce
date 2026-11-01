import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Checkbox, EmptyState, ErrorNotice, Inline, Notice, NumberField, PageHeader, TextField, useToast } from '../core3/ui';
import { qty, TD } from './util';

/**
 * One proposed pool (editable draft).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.proposal Proposal.
 * @param {boolean}  props.checked  Selected.
 * @param {Function} props.onToggle Toggle.
 * @param {Function} props.onChange Change.
 * @return {Element} Card.
 */
function Proposal( { proposal, checked, onToggle, onChange } ) {
	const set = ( key ) => ( v ) => onChange( { ...proposal, [ key ]: v } );
	const setLink = ( i, v ) => onChange( { ...proposal, links: proposal.links.map( ( l, j ) => ( j === i ? { ...l, qty: v } : l ) ) } );
	return (
		<div className={ checked ? 'dev3ss-proposal is-on' : 'dev3ss-proposal' }>
			<div className="dev3ss-proposal__head">
				<Checkbox label={ sprintf( /* translators: %s: pool */ __( 'Create "%s"', TD ), proposal.name ) } checked={ checked } onChange={ onToggle } />
				<Badge tone={ proposal.source === 'ai' ? 'brand' : 'neutral' }>{ proposal.source === 'ai' ? __( 'DEV3 AI', TD ) : __( 'Rule', TD ) }</Badge>
			</div>
			{ proposal.reason && <p className="dev3ss-muted">{ proposal.reason }</p> }
			<div className="dev3ss-grid3">
				<TextField label={ __( 'Pool name', TD ) } value={ proposal.name } onChange={ set( 'name' ) } />
				<TextField label={ __( 'Unit', TD ) } value={ proposal.unit } onChange={ set( 'unit' ) } />
				<NumberField label={ __( 'Opening stock', TD ) } value={ proposal.stock } onChange={ set( 'stock' ) } min={ 0 } step="any" />
			</div>
			<table className="dev3ss-mini">
				<caption className="dev3ss-ui-sr-only">{ __( 'Links', TD ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Draws from this pool', TD ) }</th>
						<th scope="col">{ __( 'Units per sale', TD ) }</th>
					</tr>
				</thead>
				<tbody>
					{ proposal.links.map( ( l, i ) => (
						<tr key={ `${ l.product_id }-${ l.attr_key }-${ l.attr_value }` }>
							<td>{ l.label }</td>
							<td>
								<NumberField label={ sprintf( /* translators: %s: item */ __( 'Units per sale for %s', TD ), l.label ) } hideLabel value={ l.qty } onChange={ ( v ) => setLink( i, v ) } min={ 0 } step="any" />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * "Set up pools for me": reads products and attributes and proposes shared pools.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onUsage  AI usage.
 * @return {Element} Screen.
 */
export default function Setup( { navigate, onUsage } ) {
	const toast = useToast();
	const [ proposals, setProposals ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ meta, setMeta ] = useState( null );
	const [ loading, setLoading ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ applying, setApplying ] = useState( false );

	const suggest = ( ai ) => {
		setLoading( ai ? 'ai' : 'rules' );
		setError( null );
		request( 'setup/suggest', { method: 'POST', data: { ai } } )
			.then( ( r ) => {
				setProposals( r.proposals );
				setSelected( Object.fromEntries( r.proposals.map( ( p, i ) => [ i, true ] ) ) );
				setMeta( r );
			} )
			.catch( setError )
			.finally( () => {
				setLoading( '' );
				if ( ai ) {
					onUsage();
				}
			} );
	};

	const chosen = ( proposals || [] ).filter( ( p, i ) => selected[ i ] );
	const apply = () => {
		setApplying( true );
		request( 'setup/apply', { method: 'POST', data: { proposals: chosen } } )
			.then( ( r ) => {
				toast.success( sprintf( /* translators: %d: pools */ _n( '%d pool created and products synced.', '%d pools created and products synced.', r.created.length, TD ), r.created.length ) );
				if ( r.errors.length ) {
					toast.warning( r.errors.slice( 0, 3 ).join( ' ' ) );
				}
				navigate( 'pools' );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setApplying( false ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Set up pools for me', TD ) } description={ __( 'DEV3 AI reads your products, variations and attributes and proposes shared pools: pack sizes of one item, variations cut from one material, kits made from the same parts.', TD ) } />
			<Card tone="ai" title={ __( 'Find shared stock in your catalogue', TD ) } description={ __( 'Nothing is created until you pick the pools and click Create. You can edit names, opening stock and units per sale first.', TD ) }>
				<Inline gap={ 8 }>
					<AiButton onClick={ () => suggest( true ) } loading={ loading === 'ai' } loadingText={ __( 'Reading your catalogue…', TD ) } disabled={ !! loading }>
						{ __( 'Propose pools with DEV3 AI', TD ) }
					</AiButton>
					<Button icon="list" onClick={ () => suggest( false ) } loading={ loading === 'rules' } disabled={ !! loading }>
						{ __( 'Use built-in rules (no AI)', TD ) }
					</Button>
				</Inline>
				<ErrorNotice error={ error } onRetry={ () => suggest( true ) } onDismiss={ () => setError( null ) } />
			</Card>
			{ proposals && (
				<Card
					title={ sprintf( /* translators: %d: count */ _n( '%d proposed pool', '%d proposed pools', proposals.length, TD ), proposals.length ) }
					description={ meta ? sprintf( /* translators: %s: products */ __( 'From %s products.', TD ), qty( meta.products ) ) + ( meta.model ? ` · ${ meta.model } · ${ qty( meta.tokens ) } tokens` : '' ) : '' }
					footer={
						proposals.length > 0 && (
							<>
								<span className="dev3ss-muted dev3ss-grow">{ __( 'Draft: not saved yet', TD ) }</span>
								<Button variant="ghost" onClick={ () => setProposals( null ) }>
									{ __( 'Reject all', TD ) }
								</Button>
								<Button variant="primary" icon="check" onClick={ apply } loading={ applying } disabled={ ! chosen.length }>
									{ sprintf( /* translators: %d: count */ _n( 'Create %d pool', 'Create %d pools', chosen.length, TD ), chosen.length ) }
								</Button>
							</>
						)
					}
				>
					{ proposals.length ? (
						<div className="dev3ss-stack">
							{ proposals.map( ( p, i ) => (
								<Proposal key={ i } proposal={ p } checked={ !! selected[ i ] } onToggle={ ( v ) => setSelected( { ...selected, [ i ]: v } ) } onChange={ ( next ) => setProposals( proposals.map( ( x, j ) => ( j === i ? next : x ) ) ) } />
							) ) }
						</div>
					) : (
						<EmptyState icon="search" title={ __( 'No shared stock found', TD ) } description={ __( 'Nothing in your catalogue clearly shares stock yet. Create pools by hand, or try DEV3 AI if you used the rules.', TD ) } action={ <Button href="#/pools/new">{ __( 'Create a pool', TD ) }</Button> } />
					) }
				</Card>
			) }
			{ ! proposals && <Notice tone="info">{ __( 'The built-in rules spot pack sizes in product names ("6-pack", "Case of 24"), pack-size variations, and material/fabric attributes shared by several products.', TD ) }</Notice> }
		</>
	);
}
