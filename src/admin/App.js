import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, Notice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Pools from './Pools';
import PoolDetail from './PoolDetail';
import Forecasts from './Forecasts';
import PurchaseOrders from './PurchaseOrders';
import Alerts from './Alerts';
import History from './History';
import Setup from './Setup';
import Ask from './Ask';
import ImportExport from './ImportExport';
import SettingsTab from './SettingsTab';
import { TD } from './util';

const NAV_ALL = [
	{ id: 'dashboard', label: __( 'Dashboard', TD ), icon: 'dashboard' },
	{ id: 'pools', label: __( 'Stock pools', TD ), icon: 'box' },
	{ id: 'forecasts', label: __( 'Forecasts', TD ), icon: 'chart' },
	{ id: 'orders', label: __( 'Purchase orders', TD ), icon: 'file' },
	{ id: 'alerts', label: __( 'Alerts', TD ), icon: 'alert' },
	{ id: 'history', label: __( 'Stock history', TD ), icon: 'clock' },
	{ id: 'setup', label: __( 'Set up pools', TD ), icon: 'sparkles' },
	{ id: 'ask', label: __( 'Ask your inventory', TD ), icon: 'help' },
	{ id: 'import', label: __( 'Import / export', TD ), icon: 'copy' },
	{ id: 'settings', label: __( 'Settings', TD ), icon: 'settings', admin: true },
	{ id: 'api', label: __( 'API Config', TD ), icon: 'key', admin: true },
	{ id: 'usage', label: __( 'Usage Logs', TD ), icon: 'list', admin: true },
];

export default function App() {
	const nav = NAV_ALL.filter( ( n ) => ! n.admin || config.canSettings || config.canManage );
	const [ route, navigate ] = useHashRoute( nav.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ alertCount, setAlertCount ] = useState( 0 );
	const canSettings = !! ( config.canSettings || config.canManage );

	const load = () => {
		setError( null );
		if ( ! canSettings ) {
			setSettings( { api_key_set: false } );
			return;
		}
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		request( 'alerts?status=open' )
			.then( ( rows ) => setAlertCount( rows.length ) )
			.catch( () => {} );
	}, [ route.id ] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const onUsage = () => setMeter( ( n ) => n + 1 );
	const shared = { settings, navigate, onUsage, params: route.params };

	const screens = {
		dashboard: () => <Dashboard { ...shared } />,
		pools: () => ( route.params[ 0 ] === 'new' ? <Pools { ...shared } createOpen /> : route.params[ 0 ] ? <PoolDetail key={ route.params[ 0 ] } id={ Number( route.params[ 0 ] ) } { ...shared } /> : <Pools { ...shared } /> ),
		forecasts: () => <Forecasts { ...shared } />,
		orders: () => <PurchaseOrders { ...shared } />,
		alerts: () => <Alerts { ...shared } onChange={ setAlertCount } />,
		history: () => <History key={ route.params.join( '/' ) } { ...shared } />,
		setup: () => <Setup { ...shared } />,
		ask: () => <Ask { ...shared } />,
		import: () => <ImportExport { ...shared } />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', TD ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', TD ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', TD ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', TD ) } />
				<UsageTab />
			</>
		),
	};

	const navWithBadges = nav.map( ( n ) => ( n.id === 'alerts' && alertCount ? { ...n, badge: String( alertCount ) } : n ) );

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Shared Stock & Inventory for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ navWithBadges }
			active={ route.id }
			actions={ canSettings ? <UsageMeter compact refreshKey={ meter } /> : null }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', TD ) }
		>
			{ config.wooActive === false && <Notice tone="error" title={ __( 'WooCommerce is not active', TD ) }>{ __( 'Shared stock pools need WooCommerce. Activate it to continue.', TD ) }</Notice> }
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
