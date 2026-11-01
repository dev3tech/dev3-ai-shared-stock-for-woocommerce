import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Notice, PageHeader, Spinner, Toggle } from '../core3/ui';
import { TD } from './util';

const STARTERS = [
	__( 'What will run out before Black Friday?', TD ),
	__( 'What should I reorder this week?', TD ),
	__( 'Which pools sell fastest?', TD ),
	__( 'Is anything low on stock?', TD ),
];

/**
 * Ask-your-inventory chat (DEV3 AI, with a built-in rules answerer when AI is off).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.onUsage  AI usage.
 * @return {Element} Screen.
 */
export default function Ask( { settings, onUsage } ) {
	const [ turns, setTurns ] = useState( [] );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ useAi, setUseAi ] = useState( settings && settings.api_key_set !== false && settings.ai_ask !== false );
	const logRef = useRef( null );

	useEffect( () => {
		if ( logRef.current ) {
			logRef.current.scrollTop = logRef.current.scrollHeight;
		}
	}, [ turns, busy ] );

	const ask = ( question ) => {
		const q = String( question || '' ).trim();
		if ( ! q || busy ) {
			return;
		}
		const history = turns.map( ( t ) => ( { role: t.role, text: t.text } ) );
		setTurns( ( list ) => [ ...list, { role: 'user', text: q } ] );
		setText( '' );
		setBusy( true );
		request( 'ask', { method: 'POST', data: { question: q, history, ai: useAi } } )
			.then( ( r ) => setTurns( ( list ) => [ ...list, { role: 'assistant', text: r.answer, source: r.source, follow: r.follow_up || [], notice: r.notice, pools: r.pools || ( r.pool_ids || [] ).map( ( id ) => ( { id, name: `#${ id }` } ) ) } ] ) )
			.catch( ( err ) => setTurns( ( list ) => [ ...list, { role: 'assistant', text: err.message, source: 'error', follow: [] } ] ) )
			.finally( () => {
				setBusy( false );
				if ( useAi ) {
					onUsage();
				}
			} );
	};

	const submit = ( e ) => {
		e.preventDefault();
		ask( text );
	};
	const last = turns.length ? turns[ turns.length - 1 ] : null;

	return (
		<>
			<PageHeader title={ __( 'Ask your inventory', TD ) } description={ __( 'Questions in plain words, answered from your pools, sales pace, forecasts and open purchase orders.', TD ) } />
			<Card tone="ai" className="dev3ss-chatcard">
				<div className="dev3ss-chat" ref={ logRef } aria-live="polite">
					{ ! turns.length && (
						<div className="dev3ss-chat__intro">
							<p>{ __( 'Try one of these:', TD ) }</p>
							<div className="dev3ss-chips">
								{ STARTERS.map( ( s ) => (
									<button type="button" key={ s } className="dev3ss-chip" onClick={ () => ask( s ) }>
										{ s }
									</button>
								) ) }
							</div>
						</div>
					) }
					{ turns.map( ( t, i ) => (
						<div key={ i } className={ `dev3ss-msg is-${ t.role }${ t.source === 'error' ? ' is-error' : '' }` }>
							{ t.role === 'assistant' && <span className="dev3ss-msg__who">{ t.source === 'ai' ? <Badge tone="brand">{ __( 'DEV3 AI', TD ) }</Badge> : <Badge>{ __( 'Built-in answer', TD ) }</Badge> }</span> }
							<p>{ t.text }</p>
							{ t.pools && t.pools.length > 0 && (
								<p className="dev3ss-msg__links">
									{ t.pools.slice( 0, 6 ).map( ( p ) => (
										<a key={ p.id } href={ `#/pools/${ p.id }` }>
											{ p.name } →
										</a>
									) ) }
								</p>
							) }
							{ t.notice && <span className="dev3ss-muted">{ t.notice }</span> }
						</div>
					) ) }
					{ busy && (
						<div className="dev3ss-msg is-assistant">
							<Spinner label={ __( 'Thinking', TD ) } />
						</div>
					) }
				</div>
				{ last && last.role === 'assistant' && last.follow && last.follow.length > 0 && (
					<div className="dev3ss-chips">
						{ last.follow.map( ( s ) => (
							<button type="button" key={ s } className="dev3ss-chip" onClick={ () => ask( s ) }>
								{ s }
							</button>
						) ) }
					</div>
				) }
				<form className="dev3ss-chat__form" onSubmit={ submit }>
					<label className="dev3ss-ui-sr-only" htmlFor="dev3ss-ask">
						{ __( 'Your question', TD ) }
					</label>
					<input id="dev3ss-ask" className="dev3ss-ui-input" value={ text } onChange={ ( e ) => setText( e.target.value ) } placeholder={ __( 'e.g. What runs out before Christmas?', TD ) } maxLength={ 500 } />
					<Button type="submit" variant="primary" disabled={ ! text.trim() || busy }>
						{ __( 'Ask', TD ) }
					</Button>
				</form>
				<Toggle label={ __( 'Answer with DEV3 AI', TD ) } checked={ useAi } onChange={ setUseAi } help={ __( 'Off: built-in answers for dates, reorders, best sellers and low stock. No AI cost.', TD ) } />
			</Card>
			{ settings && settings.api_key_set === false && <Notice tone="info">{ __( 'No API key yet, so answers come from the built-in rules. Add a key under API Config for free-form questions.', TD ) }</Notice> }
		</>
	);
}
