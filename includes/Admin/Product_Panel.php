<?php
/**
 * "Shared stock" fields in the WooCommerce product editor (simple products and each variation).
 *
 * @package Dev3\AiSharedStock
 */

namespace Dev3\AiSharedStock\Admin;

use Dev3\AiSharedStock\Data\Pools;
use Dev3\AiSharedStock\Rest\Rest_Controller;
use Dev3\AiSharedStock\Stock\Mirror;
use Dev3\AiSharedStock\Stock\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Quick linking from the product screen. Kits (several pools) and attribute rules are managed in the
 * DEV3 AI Shared Stock app; this panel lists them read-only.
 */
class Product_Panel {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product' ), 20 );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 20, 2 );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['dev3ss'] = array(
			'label'    => __( 'Shared stock', 'dev3-ai-shared-stock-for-woocommerce' ),
			'target'   => 'dev3ss_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 25,
		);
		return $tabs;
	}

	/**
	 * Pool options for a select.
	 *
	 * @return array id => label
	 */
	private function options() {
		$opts = array( '' => __( 'Not pooled', 'dev3-ai-shared-stock-for-woocommerce' ) );
		foreach ( Pools::all() as $pool ) {
			$opts[ $pool['id'] ] = $pool['name'] . ( '' !== $pool['sku'] ? ' (' . $pool['sku'] . ')' : '' ) . ' · ' . wc_format_decimal( $pool['stock'], 4, true ) . ' ' . $pool['unit'];
		}
		return $opts;
	}

	/**
	 * Direct (non-attribute) links of a product.
	 *
	 * @param int $product_id Product id.
	 * @return array[]
	 */
	private function direct_links( $product_id ) {
		return array_values(
			array_filter(
				Pools::all_links(),
				static function ( $l ) use ( $product_id ) {
					return (int) $product_id === $l['product_id'] && '' === $l['attr_key'];
				}
			)
		);
	}

	/**
	 * Panel markup.
	 */
	public function panel() {
		global $post;
		$product_id = $post ? (int) $post->ID : 0;
		$product    = wc_get_product( $product_id );
		$direct     = $this->direct_links( $product_id );
		$app        = admin_url( 'admin.php?page=' . DEV3SS_SLUG );
		echo '<div id="dev3ss_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';
		if ( count( $direct ) > 1 ) {
			echo '<p class="form-field"><strong>' . esc_html__( 'This product is a kit: it draws from several pools.', 'dev3-ai-shared-stock-for-woocommerce' ) . '</strong> ';
			echo '<a href="' . esc_url( $app . '#/pools' ) . '">' . esc_html__( 'Edit kits in DEV3 AI Shared Stock', 'dev3-ai-shared-stock-for-woocommerce' ) . '</a></p>';
		} else {
			woocommerce_wp_select(
				array(
					'id'          => 'dev3ss_pool',
					'label'       => __( 'Shared stock pool', 'dev3-ai-shared-stock-for-woocommerce' ),
					'options'     => $this->options(),
					'value'       => $direct ? (string) $direct[0]['pool_id'] : '',
					'desc_tip'    => true,
					'description' => __( 'Sales of this product deduct from the pool. For variable products this applies to every variation unless a variation picks its own pool.', 'dev3-ai-shared-stock-for-woocommerce' ),
				)
			);
			woocommerce_wp_text_input(
				array(
					'id'                => 'dev3ss_qty',
					'label'             => __( 'Units per sale', 'dev3-ai-shared-stock-for-woocommerce' ),
					'type'              => 'number',
					'value'             => $direct ? wc_format_decimal( $direct[0]['qty'], 4, true ) : '1',
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0.0001',
					),
					'desc_tip'          => true,
					'description'       => __( 'How many pool units one sale uses, e.g. 6 for a 6-pack.', 'dev3-ai-shared-stock-for-woocommerce' ),
				)
			);
		}
		wp_nonce_field( 'dev3ss_product_panel', 'dev3ss_panel_nonce' );
		echo '</div>';
		$resolved = $product && ! $product->is_type( 'variable' ) ? Resolver::for_product( $product ) : array();
		if ( $resolved ) {
			echo '<div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Stock comes from', 'dev3-ai-shared-stock-for-woocommerce' ) . '</strong><br>';
			foreach ( $resolved as $pool_id => $per ) {
				$pool = Pools::get( $pool_id );
				if ( $pool ) {
					/* translators: 1: pool, 2: units per sale, 3: unit, 4: stock */
					echo esc_html( sprintf( __( '%1$s: %2$s %3$s per sale (%4$s in stock)', 'dev3-ai-shared-stock-for-woocommerce' ), $pool['name'], wc_format_decimal( $per, 4, true ), $pool['unit'], wc_format_decimal( $pool['stock'], 4, true ) ) ) . '<br>';
				}
			}
			echo '<em>' . esc_html__( 'The stock quantity of this product is kept in sync with its pools.', 'dev3-ai-shared-stock-for-woocommerce' ) . '</em></p></div>';
		}
		echo '<div class="options_group"><p class="form-field"><a class="button" href="' . esc_url( $app . '#/pools' ) . '">' . esc_html__( 'Open DEV3 AI Shared Stock', 'dev3-ai-shared-stock-for-woocommerce' ) . '</a></p></div>';
		echo '</div>';
	}

	/**
	 * Applies a single-pool choice to a product or variation.
	 *
	 * @param int   $product_id Product / variation id.
	 * @param int   $pool_id    Pool id (0 = none).
	 * @param float $qty        Units per sale.
	 */
	public static function apply_choice( $product_id, $pool_id, $qty ) {
		$direct = array_values(
			array_filter(
				Pools::all_links(),
				static function ( $l ) use ( $product_id ) {
					return (int) $product_id === $l['product_id'] && '' === $l['attr_key'];
				}
			)
		);
		if ( count( $direct ) > 1 ) {
			return; // Kits are edited in the app.
		}
		$current = $direct ? $direct[0] : null;
		if ( ! $pool_id ) {
			if ( $current ) {
				Pools::delete_link( $current['id'] );
			}
			return;
		}
		$qty = $qty > 0 ? $qty : 1;
		if ( $current && (int) $current['pool_id'] === $pool_id && abs( (float) $current['qty'] - $qty ) < 0.00005 ) {
			return;
		}
		if ( $current && (int) $current['pool_id'] !== $pool_id ) {
			Pools::delete_link( $current['id'] );
		}
		Pools::add_link(
			$pool_id,
			array(
				'product_id' => $product_id,
				'qty'        => $qty,
			)
		);
	}

	/**
	 * Saves the product panel.
	 *
	 * @param int $post_id Product id.
	 */
	public function save_product( $post_id ) {
		if ( ! isset( $_POST['dev3ss_panel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dev3ss_panel_nonce'] ) ), 'dev3ss_product_panel' ) ) {
			return;
		}
		if ( ! current_user_can( Rest_Controller::capability() ) || ! isset( $_POST['dev3ss_pool'] ) ) {
			return;
		}
		$pool_id = absint( wp_unslash( $_POST['dev3ss_pool'] ) );
		$qty     = isset( $_POST['dev3ss_qty'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['dev3ss_qty'] ) ) ) : 1;
		self::apply_choice( (int) $post_id, $pool_id, $qty );
		Mirror::sync_product_tree( $post_id );
	}

	/**
	 * Variation fields.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function variation_fields( $loop, $variation_data, $variation ) {
		unset( $variation_data );
		$direct = $this->direct_links( $variation->ID );
		if ( count( $direct ) > 1 ) {
			echo '<p class="form-row form-row-full">' . esc_html__( 'This variation draws from several pools (kit). Edit it in DEV3 AI Shared Stock.', 'dev3-ai-shared-stock-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<div class="dev3ss-variation">';
		woocommerce_wp_select(
			array(
				'id'            => "dev3ss_var_pool_{$loop}",
				'name'          => "dev3ss_var_pool[{$loop}]",
				'label'         => __( 'Shared stock pool', 'dev3-ai-shared-stock-for-woocommerce' ),
				'options'       => $this->options(),
				'value'         => $direct ? (string) $direct[0]['pool_id'] : '',
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => "dev3ss_var_qty_{$loop}",
				'name'              => "dev3ss_var_qty[{$loop}]",
				'label'             => __( 'Units per sale', 'dev3-ai-shared-stock-for-woocommerce' ),
				'type'              => 'number',
				'value'             => $direct ? wc_format_decimal( $direct[0]['qty'], 4, true ) : '1',
				'wrapper_class'     => 'form-row form-row-last',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0.0001',
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves variation fields (checks WooCommerce's save-variations nonce).
	 *
	 * @param int $variation_id Variation id.
	 * @param int $i            Index.
	 */
	public function save_variation( $variation_id, $i ) {
		if ( false === check_ajax_referer( 'save-variations', 'security', false ) ) {
			return;
		}
		if ( ! current_user_can( Rest_Controller::capability() ) || ! isset( $_POST['dev3ss_var_pool'][ $i ] ) ) {
			return;
		}
		$pool_id = absint( wp_unslash( $_POST['dev3ss_var_pool'][ $i ] ) );
		$qty     = isset( $_POST['dev3ss_var_qty'][ $i ] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['dev3ss_var_qty'][ $i ] ) ) ) : 1;
		self::apply_choice( (int) $variation_id, $pool_id, $qty );
		Mirror::sync_product( $variation_id );
	}
}
