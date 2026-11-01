<?php
/**
 * CSV import / export for pools, links and the stock history.
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Csv;

use Dev3\AiSharedStock\Data\Log;
use Dev3\AiSharedStock\Data\Pools;

defined( 'ABSPATH' ) || exit;

/**
 * Pools CSV columns: id, name, sku, stock, unit, low_threshold, backorders, status, supplier_name,
 * supplier_email, lead_time_days, unit_cost, notes.
 * Links CSV columns: pool_sku (or pool_id), product_sku (or product_id), attribute, value, qty.
 */
class Csv {

	const POOL_COLUMNS = array( 'id', 'name', 'sku', 'stock', 'unit', 'low_threshold', 'backorders', 'status', 'supplier_name', 'supplier_email', 'lead_time_days', 'unit_cost', 'notes' );
	const LINK_COLUMNS = array( 'pool_id', 'pool_sku', 'pool_name', 'product_id', 'product_sku', 'product_name', 'attribute', 'value', 'qty' );
	const LOG_COLUMNS  = array( 'date', 'pool_id', 'pool', 'change', 'stock_after', 'reason', 'order_id', 'product_id', 'note' );
	const MAX_ROWS     = 5000;

	/**
	 * Encodes rows as CSV (formula-injection safe).
	 *
	 * @param string[] $header Header.
	 * @param array[]  $rows   Rows (lists).
	 * @return string
	 */
	public static function encode( array $header, array $rows ) {
		$lines = array( self::line( $header ) );
		foreach ( $rows as $row ) {
			$lines[] = self::line( $row );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line.
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	private static function line( array $cells ) {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = is_bool( $cell ) ? ( $cell ? '1' : '0' ) : (string) $cell;
			if ( '' !== $cell && preg_match( '/^[=+\-@\t\r]/', $cell ) && ! is_numeric( $cell ) ) {
				$cell = "'" . $cell; // Spreadsheet formula injection guard.
			}
			$out[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}
		return implode( ',', $out );
	}

	/**
	 * Parses CSV text into associative rows (header row = keys, lower-cased).
	 *
	 * @param string $text CSV.
	 * @return array[]|\WP_Error
	 */
	public static function parse( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'dev3ss_csv_empty', __( 'The CSV file is empty.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$delim  = substr_count( strtok( $text, "\n" ), ';' ) > substr_count( strtok( $text, "\n" ), ',' ) ? ';' : ',';
		$rows   = array();
		$row    = array();
		$cell   = '';
		$quoted = false;
		$len    = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $quoted ) {
				if ( '"' === $ch ) {
					if ( $i + 1 < $len && '"' === $text[ $i + 1 ] ) {
						$cell .= '"';
						++$i;
					} else {
						$quoted = false;
					}
				} else {
					$cell .= $ch;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$quoted = true;
			} elseif ( $delim === $ch ) {
				$row[] = $cell;
				$cell  = '';
			} elseif ( "\n" === $ch || "\r" === $ch ) {
				if ( "\r" === $ch && $i + 1 < $len && "\n" === $text[ $i + 1 ] ) {
					++$i;
				}
				$row[]  = $cell;
				$rows[] = $row;
				$row    = array();
				$cell   = '';
			} else {
				$cell .= $ch;
			}
		}
		if ( '' !== $cell || $row ) {
			$row[]  = $cell;
			$rows[] = $row;
		}
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $r ) {
					return '' !== trim( implode( '', $r ) );
				}
			)
		);
		if ( count( $rows ) < 2 ) {
			return new \WP_Error( 'dev3ss_csv_rows', __( 'The CSV needs a header row and at least one data row.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( count( $rows ) - 1 > self::MAX_ROWS ) {
			/* translators: %d: max rows */
			return new \WP_Error( 'dev3ss_csv_big', sprintf( __( 'Import up to %d rows at a time.', 'dev3-ai-shared-stock-for-woocommerce' ), self::MAX_ROWS ), array( 'status' => 400 ) );
		}
		$header = array_map(
			static function ( $h ) {
				return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( $h ) ) ) );
			},
			array_shift( $rows )
		);
		$out    = array();
		foreach ( $rows as $r ) {
			$assoc = array();
			foreach ( $header as $i => $key ) {
				$value         = isset( $r[ $i ] ) ? trim( $r[ $i ] ) : '';
				$assoc[ $key ] = 0 === strpos( $value, "'" ) && preg_match( '/^\'[=+\-@]/', $value ) ? substr( $value, 1 ) : $value;
			}
			$out[] = $assoc;
		}
		return $out;
	}

	/**
	 * Pools CSV.
	 *
	 * @return string
	 */
	public static function export_pools() {
		$rows = array();
		foreach ( Pools::all() as $p ) {
			$row = array();
			foreach ( self::POOL_COLUMNS as $col ) {
				$row[] = $p[ $col ];
			}
			$rows[] = $row;
		}
		return self::encode( self::POOL_COLUMNS, $rows );
	}

	/**
	 * Links CSV.
	 *
	 * @return string
	 */
	public static function export_links() {
		$rows = array();
		foreach ( Pools::all_links() as $l ) {
			$pool    = Pools::get( $l['pool_id'] );
			$product = $l['product_id'] && function_exists( 'wc_get_product' ) ? wc_get_product( $l['product_id'] ) : null;
			$rows[]  = array(
				$l['pool_id'],
				$pool ? $pool['sku'] : '',
				$pool ? $pool['name'] : '',
				$l['product_id'],
				$product ? $product->get_sku() : '',
				$product ? $product->get_name() : '',
				$l['attr_key'],
				$l['attr_value'],
				$l['qty'],
			);
		}
		return self::encode( self::LINK_COLUMNS, $rows );
	}

	/**
	 * History CSV.
	 *
	 * @param array $args Filters.
	 * @return string
	 */
	public static function export_log( array $args = array() ) {
		$rows = array();
		foreach ( Log::export_rows( $args ) as $r ) {
			$rows[] = array( $r['created_at'], $r['pool_id'], $r['pool_name'], $r['delta'], $r['stock_after'], $r['reason'], $r['order_id'], $r['product_id'], $r['note'] );
		}
		return self::encode( self::LOG_COLUMNS, $rows );
	}

	/**
	 * Imports (or previews) a pools CSV. Rows match an existing pool by id, then SKU; others are created.
	 * Stock changes are logged with reason "import".
	 *
	 * @param string $text    CSV.
	 * @param bool   $dry_run Preview only.
	 * @return array|\WP_Error { created, updated, skipped, errors[], preview[] }
	 */
	public static function import_pools( $text, $dry_run = true ) {
		$rows = self::parse( $text );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! array_key_exists( 'name', $rows[0] ) && ! array_key_exists( 'sku', $rows[0] ) ) {
			return new \WP_Error( 'dev3ss_csv_columns', __( 'The pools CSV needs a "name" or "sku" column.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$report = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
			'preview' => array(),
		);
		foreach ( $rows as $n => $row ) {
			$line     = $n + 2;
			$existing = null;
			if ( ! empty( $row['id'] ) ) {
				$existing = Pools::get( (int) $row['id'], true );
			}
			if ( ! $existing && ! empty( $row['sku'] ) ) {
				$existing = Pools::find_by_sku( $row['sku'] );
			}
			$fields = array();
			foreach ( array( 'name', 'sku', 'unit', 'low_threshold', 'status', 'supplier_name', 'supplier_email', 'lead_time_days', 'unit_cost', 'notes' ) as $col ) {
				if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
					$fields[ $col ] = $row[ $col ];
				}
			}
			if ( isset( $row['backorders'] ) && '' !== $row['backorders'] ) {
				$fields['backorders'] = in_array( strtolower( $row['backorders'] ), array( '1', 'yes', 'true', 'y' ), true );
			}
			$stock = isset( $row['stock'] ) && '' !== $row['stock'] ? $row['stock'] : null;
			if ( null !== $stock && ! is_numeric( str_replace( ',', '.', $stock ) ) ) {
				/* translators: %d: line */
				$report['errors'][] = sprintf( __( 'Line %d: stock is not a number.', 'dev3-ai-shared-stock-for-woocommerce' ), $line );
				++$report['skipped'];
				continue;
			}
			$stock = null === $stock ? null : (float) str_replace( ',', '.', $stock );
			$check = Pools::clean( $fields, $existing );
			if ( is_wp_error( $check ) ) {
				/* translators: 1: line, 2: error */
				$report['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $line, $check->get_error_message() );
				++$report['skipped'];
				continue;
			}
			$action              = $existing ? 'update' : 'create';
			$report['preview'][] = array(
				'line'   => $line,
				'action' => $action,
				'name'   => $existing ? ( $fields['name'] ?? $existing['name'] ) : ( $fields['name'] ?? '' ),
				'from'   => $existing ? $existing['stock'] : null,
				'to'     => null === $stock ? ( $existing ? $existing['stock'] : 0 ) : Pools::num( $stock ),
			);
			if ( $dry_run ) {
				++$report[ $existing ? 'updated' : 'created' ];
				continue;
			}
			if ( $existing ) {
				$res = Pools::update( $existing['id'], $fields );
				if ( ! is_wp_error( $res ) && null !== $stock ) {
					$res = Pools::set_stock( $existing['id'], $stock, 'import', array( 'note' => __( 'CSV import', 'dev3-ai-shared-stock-for-woocommerce' ) ) );
				}
			} else {
				$res = Pools::create( $fields + array( 'stock' => (float) $stock ) );
			}
			if ( is_wp_error( $res ) ) {
				/* translators: 1: line, 2: error */
				$report['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $line, $res->get_error_message() );
				++$report['skipped'];
				continue;
			}
			++$report[ $existing ? 'updated' : 'created' ];
		}
		$report['preview'] = array_slice( $report['preview'], 0, 200 );
		return $report;
	}

	/**
	 * Imports (or previews) a links CSV.
	 *
	 * @param string $text    CSV.
	 * @param bool   $dry_run Preview only.
	 * @return array|\WP_Error
	 */
	public static function import_links( $text, $dry_run = true ) {
		$rows = self::parse( $text );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$report = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
			'preview' => array(),
		);
		foreach ( $rows as $n => $row ) {
			$line = $n + 2;
			$pool = ! empty( $row['pool_id'] ) ? Pools::get( (int) $row['pool_id'] ) : null;
			if ( ! $pool && ! empty( $row['pool_sku'] ) ) {
				$pool = Pools::find_by_sku( $row['pool_sku'] );
			}
			$product_id = ! empty( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			if ( ! $product_id && ! empty( $row['product_sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$product_id = (int) wc_get_product_id_by_sku( $row['product_sku'] );
				if ( ! $product_id ) {
					/* translators: 1: line, 2: sku */
					$report['errors'][] = sprintf( __( 'Line %1$d: no product with SKU %2$s.', 'dev3-ai-shared-stock-for-woocommerce' ), $line, $row['product_sku'] );
					++$report['skipped'];
					continue;
				}
			}
			if ( ! $pool ) {
				/* translators: %d: line */
				$report['errors'][] = sprintf( __( 'Line %d: pool not found (use pool_id or pool_sku).', 'dev3-ai-shared-stock-for-woocommerce' ), $line );
				++$report['skipped'];
				continue;
			}
			$link = array(
				'product_id' => $product_id,
				'attr_key'   => (string) ( $row['attribute'] ?? '' ),
				'attr_value' => (string) ( $row['value'] ?? '' ),
				'qty'        => isset( $row['qty'] ) && '' !== $row['qty'] ? (float) str_replace( ',', '.', $row['qty'] ) : 1,
			);
			if ( $link['qty'] <= 0 || ( ! $product_id && ( '' === $link['attr_key'] || '' === $link['attr_value'] ) ) ) {
				/* translators: %d: line */
				$report['errors'][] = sprintf( __( 'Line %d: needs a product or an attribute + value, and a quantity above 0.', 'dev3-ai-shared-stock-for-woocommerce' ), $line );
				++$report['skipped'];
				continue;
			}
			$report['preview'][] = array(
				'line'   => $line,
				'action' => 'link',
				'name'   => $pool['name'],
				'from'   => $product_id ? '#' . $product_id : $link['attr_key'] . '=' . $link['attr_value'],
				'to'     => $link['qty'],
			);
			if ( $dry_run ) {
				++$report['created'];
				continue;
			}
			$res = Pools::add_link( $pool['id'], $link );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: line, 2: error */
				$report['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'dev3-ai-shared-stock-for-woocommerce' ), $line, $res->get_error_message() );
				++$report['skipped'];
				continue;
			}
			++$report['created'];
		}
		$report['preview'] = array_slice( $report['preview'], 0, 200 );
		return $report;
	}
}
