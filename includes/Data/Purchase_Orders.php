<?php
/**
 * Purchase orders to suppliers (drafted by DEV3 AI or a template, sent by the admin).
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Data;

defined( 'ABSPATH' ) || exit;

/**
 * PO repository: draft -> sent -> received (adds stock) | cancelled.
 */
class Purchase_Orders {

	const STATUSES = array( 'draft', 'sent', 'received', 'cancelled' );

	/**
	 * Normalises a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		return array(
			'id'             => (int) $row['id'],
			'pool_id'        => (int) $row['pool_id'],
			'pool_name'      => isset( $row['pool_name'] ) ? (string) $row['pool_name'] : '',
			'unit'           => isset( $row['unit'] ) ? (string) $row['unit'] : '',
			'supplier_name'  => (string) $row['supplier_name'],
			'supplier_email' => (string) $row['supplier_email'],
			'qty'            => Pools::num( $row['qty'] ),
			'unit_cost'      => Pools::num( $row['unit_cost'] ),
			'expected_date'  => $row['expected_date'] ? (string) $row['expected_date'] : '',
			'subject'        => (string) $row['subject'],
			'body'           => (string) $row['body'],
			'status'         => (string) $row['status'],
			'created_at'     => (string) $row['created_at'],
			'sent_at'        => $row['sent_at'] ? (string) $row['sent_at'] : '',
			'received_at'    => $row['received_at'] ? (string) $row['received_at'] : '',
		);
	}

	/**
	 * Lists POs.
	 *
	 * @param array $args { status, pool_id }.
	 * @return array[]
	 */
	public static function all( array $args = array() ) {
		global $wpdb;
		$table = Tables::name( 'orders' );
		$pools = Tables::name( 'pools' );
		$where = 'o.id > %d';
		$vals  = array( 0 );
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where .= ' AND o.status = %s';
			$vals[] = $args['status'];
		}
		if ( ! empty( $args['pool_id'] ) ) {
			$where .= ' AND o.pool_id = %d';
			$vals[] = (int) $args['pool_id'];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT o.*, p.name AS pool_name, p.unit AS unit FROM {$table} o LEFT JOIN {$pools} p ON p.id = o.pool_id WHERE {$where} ORDER BY o.created_at DESC, o.id DESC LIMIT 500", $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * One PO.
	 *
	 * @param int $id PO id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'orders' );
		$pools = Tables::name( 'pools' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT o.*, p.name AS pool_name, p.unit AS unit FROM {$table} o LEFT JOIN {$pools} p ON p.id = o.pool_id WHERE o.id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Validates PO fields.
	 *
	 * @param array $data Raw.
	 * @return array|\WP_Error
	 */
	public static function clean( array $data ) {
		$out = array();
		if ( isset( $data['qty'] ) ) {
			$out['qty'] = round( (float) $data['qty'], 4 );
			if ( $out['qty'] <= 0 ) {
				return new \WP_Error( 'dev3ss_po_qty', __( 'Order quantity must be more than 0.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}
		if ( isset( $data['unit_cost'] ) ) {
			$out['unit_cost'] = max( 0, round( (float) $data['unit_cost'], 4 ) );
		}
		if ( array_key_exists( 'expected_date', $data ) ) {
			$d                    = (string) $data['expected_date'];
			$out['expected_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null;
		}
		if ( isset( $data['supplier_name'] ) ) {
			$out['supplier_name'] = mb_substr( sanitize_text_field( (string) $data['supplier_name'] ), 0, 190 );
		}
		if ( isset( $data['supplier_email'] ) ) {
			$out['supplier_email'] = sanitize_email( (string) $data['supplier_email'] );
		}
		if ( isset( $data['subject'] ) ) {
			$out['subject'] = mb_substr( sanitize_text_field( (string) $data['subject'] ), 0, 255 );
		}
		if ( isset( $data['body'] ) ) {
			$out['body'] = mb_substr( sanitize_textarea_field( (string) $data['body'] ), 0, 6000 );
		}
		return $out;
	}

	/**
	 * Creates a draft PO.
	 *
	 * @param int   $pool_id Pool id.
	 * @param array $data    Fields.
	 * @return int|\WP_Error
	 */
	public static function create( $pool_id, array $data ) {
		global $wpdb;
		$pool = Pools::get( $pool_id );
		if ( ! $pool ) {
			return Pools::missing();
		}
		$clean = self::clean( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( empty( $clean['qty'] ) ) {
			return new \WP_Error( 'dev3ss_po_qty', __( 'Order quantity must be more than 0.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$row = array_merge(
			array(
				'supplier_name'  => $pool['supplier_name'],
				'supplier_email' => $pool['supplier_email'],
				'unit_cost'      => $pool['unit_cost'],
				'expected_date'  => null,
				'subject'        => '',
				'body'           => '',
			),
			$clean,
			array(
				'pool_id'    => (int) $pool_id,
				'status'     => 'draft',
				'created_by' => get_current_user_id(),
				'created_at' => Pools::now(),
			)
		);
		$wpdb->insert( Tables::name( 'orders' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a draft PO.
	 *
	 * @param int   $id   PO id.
	 * @param array $data Fields.
	 * @return array|\WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$po = self::get( $id );
		if ( ! $po ) {
			return self::missing();
		}
		$clean = self::clean( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( $clean ) {
			$wpdb->update( Tables::name( 'orders' ), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::get( $id );
	}

	/**
	 * Emails the PO to the supplier and marks it sent.
	 *
	 * @param int $id PO id.
	 * @return array|\WP_Error
	 */
	public static function send( $id ) {
		global $wpdb;
		$po = self::get( $id );
		if ( ! $po ) {
			return self::missing();
		}
		if ( ! is_email( $po['supplier_email'] ) ) {
			return new \WP_Error( 'dev3ss_po_email', __( 'Add a supplier email to the pool or the purchase order first.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( in_array( $po['status'], array( 'received', 'cancelled' ), true ) ) {
			return new \WP_Error( 'dev3ss_po_closed', __( 'This purchase order is closed.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		/**
		 * Filters the purchase-order email before it is sent.
		 *
		 * @param array $mail { to, subject, body, headers }.
		 * @param array $po   Purchase order.
		 */
		$mail = apply_filters(
			'dev3ss_po_email',
			array(
				'to'      => $po['supplier_email'],
				'subject' => '' !== $po['subject'] ? $po['subject'] : sprintf( /* translators: %s: pool name */ __( 'Purchase order: %s', 'dev3-ai-shared-stock-for-woocommerce' ), $po['pool_name'] ),
				'body'    => $po['body'],
				'headers' => array( 'Reply-To: ' . get_option( 'admin_email' ) ),
			),
			$po
		);
		if ( ! wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] ) ) {
			return new \WP_Error( 'dev3ss_po_mail', __( 'WordPress could not send the email. Check your site\'s mail setup.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'orders' ),
			array(
				'status'  => 'sent',
				'sent_at' => Pools::now(),
			),
			array( 'id' => (int) $id )
		);
		return self::get( $id );
	}

	/**
	 * Marks a PO received and adds its quantity (or the received quantity) to the pool.
	 *
	 * @param int        $id  PO id.
	 * @param float|null $qty Received quantity (null = ordered quantity).
	 * @return array|\WP_Error
	 */
	public static function receive( $id, $qty = null ) {
		global $wpdb;
		$po = self::get( $id );
		if ( ! $po ) {
			return self::missing();
		}
		if ( in_array( $po['status'], array( 'received', 'cancelled' ), true ) ) {
			return new \WP_Error( 'dev3ss_po_closed', __( 'This purchase order is closed.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$qty = null === $qty ? (float) $po['qty'] : round( (float) $qty, 4 );
		if ( $qty <= 0 ) {
			return new \WP_Error( 'dev3ss_po_qty', __( 'Received quantity must be more than 0.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$after = Pools::adjust(
			$po['pool_id'],
			$qty,
			'restock',
			array(
				/* translators: %d: purchase order number */
				'note' => sprintf( __( 'Purchase order #%d received', 'dev3-ai-shared-stock-for-woocommerce' ), $po['id'] ),
			)
		);
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'orders' ),
			array(
				'status'      => 'received',
				'received_at' => Pools::now(),
			),
			array( 'id' => (int) $id )
		);
		return self::get( $id );
	}

	/**
	 * Cancels a PO.
	 *
	 * @param int $id PO id.
	 * @return array|\WP_Error
	 */
	public static function cancel( $id ) {
		global $wpdb;
		$po = self::get( $id );
		if ( ! $po ) {
			return self::missing();
		}
		if ( 'received' === $po['status'] ) {
			return new \WP_Error( 'dev3ss_po_closed', __( 'This purchase order is closed.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$wpdb->update( Tables::name( 'orders' ), array( 'status' => 'cancelled' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Open (sent, not received) quantity per pool.
	 *
	 * @return array pool_id => { qty, expected_date }
	 */
	public static function incoming() {
		$out = array();
		foreach ( self::all( array( 'status' => 'sent' ) ) as $po ) {
			$cur                   = $out[ $po['pool_id'] ] ?? array(
				'qty'           => 0,
				'expected_date' => '',
			);
			$cur['qty']           += $po['qty'];
			$cur['expected_date']  = $po['expected_date'] && ( ! $cur['expected_date'] || $po['expected_date'] < $cur['expected_date'] ) ? $po['expected_date'] : $cur['expected_date'];
			$out[ $po['pool_id'] ] = $cur;
		}
		return $out;
	}

	/**
	 * Not-found error.
	 *
	 * @return \WP_Error
	 */
	public static function missing() {
		return new \WP_Error( 'dev3ss_po_missing', __( 'That purchase order no longer exists.', 'dev3-ai-shared-stock-for-woocommerce' ), array( 'status' => 404 ) );
	}
}
