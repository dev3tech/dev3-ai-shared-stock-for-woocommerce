import { __ } from '@wordpress/i18n';
import Icon, { BrandMark } from './icons';
import { ToastProvider } from './Toast';
import { cx } from './utils';

/**
 * Splits "DEV3 AI Review Summarizer & Sentiment Analyzer for WooCommerce" into a short title and
 * the "for …" part, so long CodeCanyon names fit the header.
 *
 * @param {string} name Full product name.
 * @return {Object} { brand, title, platform }
 */
export function splitName( name ) {
	const full = String( name || '' ).trim();
	const brandMatch = full.match( /^(DEV3(?: AI)?)\s+/ );
	const brand = brandMatch ? brandMatch[ 1 ] : '';
	const rest = brand ? full.slice( brandMatch[ 0 ].length ) : full;
	const forMatch = rest.match( /^(.*?)\s+for\s+(.+)$/ );
	return { brand, title: forMatch ? forMatch[ 1 ] : rest, platform: forMatch ? forMatch[ 2 ] : '' };
}

/**
 * Admin app frame: DEV3 header (icon, product name, version, actions) and responsive navigation:
 * a sidebar when the app is at least 960px wide, horizontally scrolling tabs below that (or a
 * select with `narrowNav="select"`). Width is measured with a container query, so it adapts to the
 * wp-admin menu being folded/unfolded and to the mobile admin. Includes the ToastProvider.
 *
 * @param {Object}   props            Props.
 * @param {string}   props.name       Product name ("DEV3 AI …"); split into title + "for …".
 * @param {string}   props.tagline    Optional subtitle (defaults to "for <platform>").
 * @param {*}        props.icon       Image URL or element (default: DEV3 sparkle mark).
 * @param {string}   props.version    Version shown as a badge.
 * @param {Array}    props.nav        [ { id, label, icon, badge, href } ].
 * @param {string}   props.active     Active nav id.
 * @param {Function} props.onNavigate ( id ) => void. Without it, links go to `#/<id>`.
 * @param {*}        props.actions    Header actions (e.g. <UsageMeter compact />, a help link).
 * @param {string}   props.narrowNav  tabs | select.
 * @param {*}        props.footer     Footer under the content.
 * @param {*}        props.children   Page content.
 * @return {Element} Shell.
 */
export function AppShell( { name, tagline, icon, version, nav = [], active, onNavigate, actions, narrowNav = 'tabs', footer, className, children } ) {
	const parts = splitName( name );
	const sub = tagline !== undefined ? tagline : parts.platform && `for ${ parts.platform }`;
	const go = ( e, item ) => {
		if ( item.href ) {
			return;
		}
		if ( onNavigate ) {
			e.preventDefault();
			onNavigate( item.id );
			window.location.hash = `#/${ item.id }`;
		}
	};
	const current = nav.find( ( n ) => n.id === active );

	return (
		<ToastProvider>
			<div className={ cx( 'dev3ss-ui-shell', className ) }>
				<header className="dev3ss-ui-shell__header">
					<div className="dev3ss-ui-shell__brand">
						<span className="dev3ss-ui-shell__icon">{ typeof icon === 'string' ? <img src={ icon } alt="" width="36" height="36" /> : icon || <BrandMark size={ 36 } /> }</span>
						<div className="dev3ss-ui-shell__names">
							<p className="dev3ss-ui-shell__name">
								{ parts.brand && <span className="dev3ss-ui-shell__dev3">{ parts.brand }</span> }
								<span className="dev3ss-ui-shell__title">{ parts.title }</span>
								{ version && <span className="dev3ss-ui-shell__version">v{ version }</span> }
							</p>
							{ sub && <p className="dev3ss-ui-shell__tagline">{ sub }</p> }
						</div>
					</div>
					{ actions && <div className="dev3ss-ui-shell__actions">{ actions }</div> }
				</header>
				<div className={ cx( 'dev3ss-ui-shell__body', nav.length === 0 && 'is-nonav' ) }>
					{ nav.length > 0 && (
						<nav className={ cx( 'dev3ss-ui-shell__nav', `is-narrow-${ narrowNav }` ) } aria-label={ __( 'Plugin sections', 'dev3-ai-shared-stock-for-woocommerce' ) }>
							<ul className="dev3ss-ui-shell__navlist">
								{ nav.map( ( item ) => (
									<li key={ item.id }>
										<a
											href={ item.href || `#/${ item.id }` }
											className={ cx( 'dev3ss-ui-shell__navlink', item.id === active && 'is-active' ) }
											aria-current={ item.id === active ? 'page' : undefined }
											onClick={ ( e ) => go( e, item ) }
										>
											{ item.icon && <Icon name={ item.icon } size={ 16 } /> }
											<span>{ item.label }</span>
											{ item.badge !== undefined && item.badge !== null && item.badge !== '' && <span className="dev3ss-ui-shell__navbadge">{ item.badge }</span> }
										</a>
									</li>
								) ) }
							</ul>
							{ narrowNav === 'select' && (
								<div className="dev3ss-ui-shell__navselect">
									<label className="dev3ss-ui-sr-only" htmlFor="dev3ss-ui-navselect">
										{ __( 'Section', 'dev3-ai-shared-stock-for-woocommerce' ) }
									</label>
									<select
										id="dev3ss-ui-navselect"
										className="dev3ss-ui-input dev3ss-ui-select"
										value={ current ? current.id : '' }
										onChange={ ( e ) => {
											const item = nav.find( ( n ) => n.id === e.target.value );
											if ( item && item.href ) {
												window.location.href = item.href;
												return;
											}
											if ( onNavigate ) {
												onNavigate( e.target.value );
											}
											window.location.hash = `#/${ e.target.value }`;
										} }
									>
										{ nav.map( ( item ) => (
											<option key={ item.id } value={ item.id }>
												{ item.label }
											</option>
										) ) }
									</select>
								</div>
							) }
						</nav>
					) }
					<main className="dev3ss-ui-shell__main" id="dev3ss-ui-main" tabIndex={ -1 }>
						{ children }
						{ footer && <div className="dev3ss-ui-shell__footer">{ footer }</div> }
					</main>
				</div>
			</div>
		</ToastProvider>
	);
}

export default AppShell;
