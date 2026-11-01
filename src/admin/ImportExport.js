import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, Inline, Notice, PageHeader, ResponsiveTable, Select, useToast } from '../core3/ui';
import { download, qty, TD } from './util';

const SAMPLES = {
	pools: 'name,sku,stock,unit,low_threshold,supplier_name,supplier_email,lead_time_days,unit_cost\nCola cans,COLA-CAN,480,cans,48,Fizz Wholesale,orders@fizz.example,7,0.35\n',
	links: 'pool_sku,product_sku,attribute,value,qty\nCOLA-CAN,COLA-1,,,1\nCOLA-CAN,COLA-6,,,6\nLINEN,,pa_material,linen,1.5\n',
};

/**
 * CSV import (with a dry-run preview) and export of pools, links and history.
 *
 * @return {Element} Screen.
 */
export default function ImportExport() {
	const toast = useToast();
	const [ type, setType ] = useState( 'pools' );
	const [ csv, setCsv ] = useState( '' );
	const [ fileName, setFileName ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( '' );

	const exportCsv = ( what ) =>
		request( `export?type=${ what }` )
			.then( ( r ) => download( r.filename, r.csv ) )
			.catch( ( err ) => toast.error( err ) );

	const onFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		setPreview( null );
		if ( ! file ) {
			return;
		}
		if ( file.size > 5 * 1024 * 1024 ) {
			toast.error( __( 'Files up to 5 MB, please.', TD ) );
			return;
		}
		const reader = new window.FileReader();
		reader.onload = () => {
			setCsv( String( reader.result || '' ) );
			setFileName( file.name );
		};
		reader.readAsText( file );
	};

	const run = ( dry ) => {
		setBusy( dry ? 'preview' : 'import' );
		request( 'import', { method: 'POST', data: { type, csv, dry_run: dry } } )
			.then( ( r ) => {
				if ( dry ) {
					setPreview( r );
				} else {
					setPreview( null );
					setCsv( '' );
					setFileName( '' );
					toast.success( sprintf( /* translators: 1: created, 2: updated, 3: skipped */ __( 'Import done: %1$d created, %2$d updated, %3$d skipped.', TD ), r.created, r.updated, r.skipped ) );
				}
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<>
			<PageHeader title={ __( 'Import / export', TD ) } description={ __( 'Move pools, links and stock levels in and out with CSV files. Every imported stock change is logged.', TD ) } />
			<Columns min={ 340 }>
				<Card title={ __( 'Export', TD ) } description={ __( 'UTF-8 CSV files that open in Excel, Numbers and Google Sheets.', TD ) }>
					<div className="dev3ss-stack">
						<Button icon="copy" onClick={ () => exportCsv( 'pools' ) }>
							{ __( 'Pools and stock levels', TD ) }
						</Button>
						<Button icon="copy" onClick={ () => exportCsv( 'links' ) }>
							{ __( 'Product and attribute links', TD ) }
						</Button>
						<Button icon="copy" onClick={ () => exportCsv( 'history' ) }>
							{ __( 'Stock history', TD ) }
						</Button>
					</div>
				</Card>
				<Card title={ __( 'Import', TD ) } description={ __( 'Rows match existing pools by id, then SKU; other rows create new pools. Preview first, then import.', TD ) }>
					<div className="dev3ss-stack">
						<Select
							label={ __( 'What are you importing?', TD ) }
							value={ type }
							onChange={ ( v ) => {
								setType( v );
								setPreview( null );
							} }
							options={ [
								{ value: 'pools', label: __( 'Pools (name, sku, stock, unit…)', TD ) },
								{ value: 'links', label: __( 'Links (pool_sku, product_sku, attribute, value, qty)', TD ) },
							] }
						/>
						<div className="dev3ss-file">
							<label htmlFor="dev3ss-csv" className="dev3ss-ui-field__label">
								{ __( 'CSV file', TD ) }
							</label>
							<input id="dev3ss-csv" type="file" accept=".csv,text/csv" onChange={ onFile } />
							{ fileName && <span className="dev3ss-muted">{ fileName }</span> }
						</div>
						<Inline gap={ 8 }>
							<Button variant="primary" onClick={ () => run( true ) } disabled={ ! csv } loading={ busy === 'preview' }>
								{ __( 'Preview import', TD ) }
							</Button>
							<Button variant="link" onClick={ () => download( `shared-stock-${ type }-sample.csv`, SAMPLES[ type ] ) }>
								{ __( 'Download a sample file', TD ) }
							</Button>
						</Inline>
					</div>
				</Card>
			</Columns>
			{ preview && (
				<Card
					title={ __( 'Preview', TD ) }
					description={ sprintf( /* translators: 1: created, 2: updated, 3: skipped */ __( '%1$d to create, %2$d to update, %3$d skipped. Nothing has changed yet.', TD ), preview.created, preview.updated, preview.skipped ) }
					footer={
						<>
							<Button variant="ghost" onClick={ () => setPreview( null ) }>
								{ __( 'Cancel', TD ) }
							</Button>
							<Button variant="primary" icon="check" onClick={ () => run( false ) } loading={ busy === 'import' } disabled={ ! preview.preview.length }>
								{ __( 'Import now', TD ) }
							</Button>
						</>
					}
					flush
				>
					{ preview.errors.length > 0 && (
						<div className="dev3ss-pad">
							<Notice tone="warning" title={ __( 'Some rows will be skipped', TD ) }>
								<ul>
									{ preview.errors.slice( 0, 10 ).map( ( e ) => (
										<li key={ e }>{ e }</li>
									) ) }
								</ul>
							</Notice>
						</div>
					) }
					<ResponsiveTable
						caption={ __( 'Import preview', TD ) }
						rows={ preview.preview }
						rowKey="line"
						pageSize={ 20 }
						columns={ [
							{ key: 'line', label: __( 'Line', TD ), align: 'end' },
							{ key: 'name', label: __( 'Pool', TD ), primary: true },
							{ key: 'action', label: __( 'Action', TD ), render: ( r ) => <Badge tone={ r.action === 'create' ? 'success' : 'info' }>{ r.action === 'create' ? __( 'Create', TD ) : r.action === 'link' ? __( 'Link', TD ) : __( 'Update', TD ) }</Badge> },
							{ key: 'from', label: type === 'links' ? __( 'Item', TD ) : __( 'Stock now', TD ), align: 'end', render: ( r ) => ( r.from === null ? '–' : typeof r.from === 'number' ? qty( r.from ) : r.from ) },
							{ key: 'to', label: type === 'links' ? __( 'Units per sale', TD ) : __( 'New stock', TD ), align: 'end', render: ( r ) => qty( r.to ) },
						] }
					/>
				</Card>
			) }
		</>
	);
}
