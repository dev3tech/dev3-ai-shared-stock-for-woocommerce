import { __, sprintf } from '@wordpress/i18n';
import { Badge } from '../core3/ui';

const TD = 'dev3-ai-shared-stock-for-woocommerce';

/**
 * Formats a stock number (no trailing zeros, max 2 decimals).
 *
 * @param {number} n Number.
 * @return {string} Text.
 */
export function qty( n ) {
	if ( n === null || n === undefined || n === '' ) {
		return '–';
	}
	return Number( n ).toLocaleString( undefined, { maximumFractionDigits: 2 } );
}

/**
 * Quantity with unit.
 *
 * @param {number} n    Number.
 * @param {string} unit Unit.
 * @return {string} Text.
 */
export function withUnit( n, unit ) {
	return unit ? `${ qty( n ) } ${ unit }` : qty( n );
}

/**
 * Y-m-d or MySQL datetime (UTC) to a short local date.
 *
 * @param {string}  value    Date.
 * @param {boolean} withTime Include the time.
 * @return {string} Text.
 */
export function date( value, withTime = false ) {
	if ( ! value ) {
		return '–';
	}
	const iso = /^\d{4}-\d{2}-\d{2}$/.test( value ) ? `${ value }T12:00:00` : `${ String( value ).replace( ' ', 'T' ) }Z`;
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return value;
	}
	const opts = { day: 'numeric', month: 'short', year: 'numeric' };
	if ( withTime ) {
		opts.hour = '2-digit';
		opts.minute = '2-digit';
	}
	return d.toLocaleString( undefined, opts );
}

/**
 * Days left as text.
 *
 * @param {number|null} days Days.
 * @return {string} Text.
 */
export function daysText( days ) {
	if ( days === null || days === undefined ) {
		return __( 'No sales yet', TD );
	}
	if ( days <= 0 ) {
		return __( 'Out now', TD );
	}
	if ( days < 1 ) {
		return __( 'Under a day', TD );
	}
	/* translators: %s: days */
	return sprintf( __( '%s days', TD ), qty( Math.round( days ) ) );
}

export const URGENCY = {
	out: { tone: 'danger', label: __( 'Out of stock', TD ) },
	now: { tone: 'danger', label: __( 'Reorder now', TD ) },
	soon: { tone: 'warning', label: __( 'Reorder soon', TD ) },
	ok: { tone: 'success', label: __( 'Healthy', TD ) },
	idle: { tone: 'neutral', label: __( 'No recent sales', TD ) },
};

/**
 * Urgency badge.
 *
 * @param {Object} props         Props.
 * @param {string} props.urgency Key.
 * @return {Element} Badge.
 */
export function UrgencyBadge( { urgency } ) {
	const u = URGENCY[ urgency ] || URGENCY.idle;
	return (
		<Badge tone={ u.tone } dot>
			{ u.label }
		</Badge>
	);
}

export const REASONS = {
	order: __( 'Order', TD ),
	refund: __( 'Refund', TD ),
	cancel: __( 'Cancelled order', TD ),
	edit: __( 'Order edited', TD ),
	manual: __( 'Manual', TD ),
	import: __( 'CSV import', TD ),
	restock: __( 'Restock (PO)', TD ),
	setup: __( 'Opening stock', TD ),
};

export const PO_STATUS = {
	draft: { tone: 'neutral', label: __( 'Draft', TD ) },
	sent: { tone: 'info', label: __( 'Sent', TD ) },
	received: { tone: 'success', label: __( 'Received', TD ) },
	cancelled: { tone: 'neutral', label: __( 'Cancelled', TD ) },
};

/**
 * Stock level badge for a pool.
 *
 * @param {Object} props      Props.
 * @param {Object} props.pool Pool.
 * @return {Element} Badge.
 */
export function StockBadge( { pool } ) {
	if ( pool.status === 'paused' ) {
		return <Badge>{ __( 'Paused', TD ) }</Badge>;
	}
	if ( pool.stock <= 0 ) {
		return (
			<Badge tone="danger" dot>
				{ __( 'Out of stock', TD ) }
			</Badge>
		);
	}
	if ( pool.stock <= pool.low_threshold ) {
		return (
			<Badge tone="warning" dot>
				{ __( 'Low', TD ) }
			</Badge>
		);
	}
	return (
		<Badge tone="success" dot>
			{ __( 'In stock', TD ) }
		</Badge>
	);
}

/**
 * Starts a browser download of text.
 *
 * @param {string} filename File name.
 * @param {string} text     Content.
 */
export function download( filename, text ) {
	const blob = new window.Blob( [ '﻿' + text ], { type: 'text/csv;charset=utf-8' } );
	const url = window.URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

/**
 * Today as Y-m-d (local).
 *
 * @param {number} addDays Offset.
 * @return {string} Date.
 */
export function today( addDays = 0 ) {
	const d = new Date();
	d.setDate( d.getDate() + addDays );
	return `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
}

/**
 * Reorder date text ("Now" when already due).
 *
 * @param {Object} f Forecast-like row (urgency, reorder_date).
 * @return {string} Text.
 */
export function reorderBy( f ) {
	if ( ! f || ! f.reorder_date ) {
		return '–';
	}
	return f.urgency === 'now' || f.urgency === 'out' ? __( 'now', TD ) : date( f.reorder_date );
}

export { TD };
