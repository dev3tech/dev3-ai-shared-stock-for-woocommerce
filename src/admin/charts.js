import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { date, qty, TD } from './util';

/**
 * Daily usage bar chart (single series: the title names it, so no legend).
 * Thin bars with rounded tops anchored to the baseline, a recessive average line,
 * per-bar hover/focus tooltip and a screen-reader table.
 *
 * @param {Object} props        Props.
 * @param {Object} props.series Y-m-d => units, oldest first.
 * @param {string} props.unit   Unit.
 * @param {number} props.rate   Forecast rate per day (drawn as a dashed line).
 * @param {number} props.height Height in px.
 * @return {Element} Chart.
 */
export function UsageBars( { series = {}, unit = '', rate = 0, height = 160 } ) {
	const [ hover, setHover ] = useState( null );
	const entries = Object.entries( series );
	const values = entries.map( ( [ , v ] ) => Number( v ) || 0 );
	const max = Math.max( 1, rate, ...values );
	const n = Math.max( 1, entries.length );
	const W = 600;
	const H = height;
	const pad = { top: 12, bottom: 22, left: 4, right: 4 };
	const plotH = H - pad.top - pad.bottom;
	const slot = ( W - pad.left - pad.right ) / n;
	const barW = Math.max( 2, Math.min( 14, slot - 2 ) );
	const y = ( v ) => pad.top + plotH - ( v / max ) * plotH;
	const total = values.reduce( ( a, b ) => a + b, 0 );
	if ( ! entries.length || total <= 0 ) {
		return <p className="dev3ss-chart__empty">{ __( 'No sales in the last 30 days yet. The chart fills in as orders come in.', TD ) }</p>;
	}
	const active = hover !== null ? entries[ hover ] : null;
	return (
		<figure className="dev3ss-chart">
			<div className="dev3ss-chart__plot" style={ { height: H } }>
				<svg viewBox={ `0 0 ${ W } ${ H }` } preserveAspectRatio="none" role="img" aria-label={ sprintf( /* translators: 1: total, 2: unit */ __( 'Daily usage over the last 30 days, %1$s %2$s in total', TD ), qty( total ), unit ) }>
					<line x1={ pad.left } x2={ W - pad.right } y1={ pad.top + plotH } y2={ pad.top + plotH } className="dev3ss-chart__axis" />
					{ entries.map( ( [ d, v ], i ) => {
						const val = Number( v ) || 0;
						const x = pad.left + i * slot + ( slot - barW ) / 2;
						const top = y( val );
						const h = Math.max( val > 0 ? 2 : 0, pad.top + plotH - top );
						const r = Math.min( 4, barW / 2, h );
						return (
							<g key={ d }>
								{ h > 0 && <path className={ hover === i ? 'dev3ss-chart__bar is-hover' : 'dev3ss-chart__bar' } d={ `M${ x },${ pad.top + plotH } V${ pad.top + plotH - h + r } Q${ x },${ pad.top + plotH - h } ${ x + r },${ pad.top + plotH - h } H${ x + barW - r } Q${ x + barW },${ pad.top + plotH - h } ${ x + barW },${ pad.top + plotH - h + r } V${ pad.top + plotH } Z` } /> }
								<rect x={ pad.left + i * slot } y={ pad.top } width={ slot } height={ plotH } fill="transparent" onMouseEnter={ () => setHover( i ) } onMouseLeave={ () => setHover( null ) } />
							</g>
						);
					} ) }
					{ rate > 0 && <line x1={ pad.left } x2={ W - pad.right } y1={ y( rate ) } y2={ y( rate ) } className="dev3ss-chart__rate" /> }
				</svg>
				{ active && (
					<div className="dev3ss-chart__tip" style={ { left: `${ Math.min( 88, Math.max( 12, ( ( hover + 0.5 ) / n ) * 100 ) ) }%` } } role="status">
						<strong>{ qty( active[ 1 ] ) }</strong> { unit }
						<span>{ date( active[ 0 ] ) }</span>
					</div>
				) }
				<div className="dev3ss-chart__labels" aria-hidden="true">
					<span>{ date( entries[ 0 ][ 0 ] ) }</span>
					<span>{ date( entries[ entries.length - 1 ][ 0 ] ) }</span>
				</div>
			</div>
			<figcaption className="dev3ss-chart__caption">
				{ rate > 0 && (
					<span className="dev3ss-chart__key">
						<i aria-hidden="true" className="dev3ss-chart__key-rate" />
						{ sprintf( /* translators: 1: rate, 2: unit */ __( 'Forecast rate: %1$s %2$s/day', TD ), qty( rate ), unit ) }
					</span>
				) }
			</figcaption>
			<table className="dev3ss-ui-sr-only">
				<caption>{ __( 'Daily usage', TD ) }</caption>
				<tbody>
					{ entries.map( ( [ d, v ] ) => (
						<tr key={ d }>
							<th scope="row">{ d }</th>
							<td>{ qty( v ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</figure>
	);
}

/**
 * Tiny inline trend (decorative; the row shows the numbers).
 *
 * @param {Object}   props        Props.
 * @param {number[]} props.values Values.
 * @return {Element|null} Sparkline.
 */
export function Sparkline( { values = [] } ) {
	if ( values.length < 2 || ! values.some( ( v ) => v > 0 ) ) {
		return null;
	}
	const max = Math.max( ...values, 1 );
	const W = 80;
	const H = 24;
	const pts = values.map( ( v, i ) => `${ ( i / ( values.length - 1 ) ) * W },${ H - 2 - ( v / max ) * ( H - 4 ) }` ).join( ' ' );
	return (
		<svg className="dev3ss-spark" width={ W } height={ H } viewBox={ `0 0 ${ W } ${ H }` } aria-hidden="true" focusable="false">
			<polyline points={ pts } fill="none" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
		</svg>
	);
}
