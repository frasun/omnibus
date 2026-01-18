<?php
/**
 * Fired during plugin activation
 *
 * @package ChocanteOmnibus
 */

defined( 'ABSPATH' ) || exit;

/**
 * The ChocanteOmnibus class.
 */
class ChocanteOmnibus {
	/**
	 * This class instance.
	 *
	 * @var \ChocanteOmnibus Single instance of this class.
	 */
	private static $instance;

	/**
	 * The current version of the plugin.
	 *
	 * @var string The current version of the plugin.
	 */
	protected $version;

	/**
	 * Price Log
	 *
	 * @var ChocanteOmnibus_Price_Log
	 */
	public $price_log;

	const PRODUCT_META_KEY = 'chocante_omnibus_lowest_price';
	const ELEMENT_CLASS    = 'chocante-omnibus';

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;

		if ( defined( 'CHOCANTE_OMNIBUS_VERSION' ) ) {
			$this->version = CHOCANTE_OMNIBUS_VERSION;
		} else {
			$this->version = '1.0.0';
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-chocanteomnibus-price-log.php';
		$this->price_log = new ChocanteOmnibus_Price_Log( $wpdb );

		$this->init();
	}

	/**
	 * Cloning is forbidden
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'chocante-omnibus' ), $this->version );
	}

	/**
	 * Unserializing instances of this class is forbidden
	 */
	public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'chocante-omnibus' ), $this->version );
	}

	/**
	 * Gets the main instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @return \ChocanteOmnibus
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		$this->price_log->activate();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		$this->price_log->deactivate();
	}

	/**
	 * Plugin uninstallation
	 */
	public function uninstall() {
		$this->price_log->uninstall();
	}

	/**
	 * Register hooks
	 */
	private function init() {
		// Display lowest price on the front-end or via front-end ajax requests.
		add_filter( 'woocommerce_get_price_html', array( $this, 'display_lowest_price' ), 10, 2 );
		add_filter( 'default_post_metadata', array( $this, 'default_lowest_price' ), 10, 3 );

		// Manage lowest price meta on product changes.
		add_action( 'save_post_product', array( $this, 'on_product_save' ), 10, 3 );
		add_action( 'woocommerce_ajax_save_product_variations', array( $this, 'on_variations_save' ), 10, 3 );

		// Manage scheduled sales.
		add_action( 'wc_after_products_starting_sales', array( $this, 'on_scheduled_sale_start' ), 10, 1 );
		add_action( 'wc_after_products_ending_sales', array( $this, 'on_scheduled_sale_end' ), 10, 1 );

		// Convert lowest price to user selected currency.
		if ( function_exists( 'wcml_is_multi_currency_on' ) && wcml_is_multi_currency_on() ) {
			add_filter( 'wcml_price_custom_fields', array( $this, 'wcml_lowest_price' ), 10 );
		}
	}

	/**
	 * Append lowest price to product price
	 *
	 * @param string                         $price Product price html.
	 * @param WC_Product|WC_Product_Variable $product Product.
	 *
	 * @return string
	 */
	public function display_lowest_price( $price, $product ) {
		// Do not modify price display if product is not on sale or out of stock.
		if ( ! $product->is_on_sale() || ! $product->is_in_stock() ) {
			return $price;
		}

		// Handle quick edit in admin - modify only GET request.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_admin() && ( ! wp_doing_ajax() || ! empty( $_POST ) ) ) {
			return $price;
		}

		// Get product or variation meta.
		if ( $product instanceof WC_Product_Variable ) {
			$variations = $product->get_visible_children();

			// Do not modify price display if product type is variable and price is a range (has more than one visible variation).
			if ( count( $variations ) > 1 ) {
				return $price;
			}

			$lowest_price = wc_price( get_post_meta( $variations[0], self::PRODUCT_META_KEY, true ) );
		} else {
			$lowest_price = wc_price( get_post_meta( $product->get_id(), self::PRODUCT_META_KEY, true ) );
		}

		// translators: Lowest price info copy.
		$lowest_price_element = '<span class=' . self::ELEMENT_CLASS . '>' . sprintf( __( 'Lowest price prior to sale: %s', 'chocante-omnibus' ), $lowest_price ) . '</span>';

		return $price . $lowest_price_element;
	}

	/**
	 * Return regular price if lowest price is not available
	 * Used for products that are on sale before installing the plugin
	 *
	 * @param mixed  $value         The value to return - a single metadata value, or an array of values.
	 * @param int    $object_id     Post ID.
	 * @param string $meta_key      Meta key.
	 * @return string               Lowest price or regular price.
	 */
	public function default_lowest_price( $value, $object_id, $meta_key ) {
		if ( self::PRODUCT_META_KEY !== $meta_key ) {
			return $value;
		}

		return get_post_meta( $object_id, '_regular_price', true );
	}

	/**
	 * Add lowest price to WCML price fields
	 *
	 * @param array $price_fields WCML price fields.
	 *
	 * @return array
	 */
	public function wcml_lowest_price( $price_fields ) {
		array_push(
			$price_fields,
			self::PRODUCT_META_KEY
		);

		return $price_fields;
	}

	/**
	 * Manage lowest price meta on product changes
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_save( $product_id ) {
		if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product_Simple ) {
			// phpcs:ignore
			$sale_price     = isset( $_POST['_sale_price'] ) ? $_POST['_sale_price'] : null;
			// phpcs:ignore
			$sale_date_from = isset( $_POST['_sale_price_dates_from'] ) ? $_POST['_sale_price_dates_from'] : null;

			$this->update_product_data( $product_id, $sale_price, $sale_date_from );
		} elseif ( $product instanceof WC_Product_Variable ) {
			$this->on_variations_save( $product_id );
		}
	}

	/**
	 * Manage lowest price meta on product variation changes
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_variations_save( $product_id ) {
		$variations = wc_get_product( $product_id )->get_children();

		foreach ( $variations as $index => $variation_id ) {
			// phpcs:ignore
			$sale_price     = isset( $_POST['variable_sale_price'][ $index ] ) ? $_POST['variable_sale_price'][ $index ] : null;
			// phpcs:ignore
			$sale_date_from = isset( $_POST['variable_sale_price_dates_from'][ $index ] ) ? $_POST['variable_sale_price_dates_from'][ $index ] : null;

			$this->update_product_data( $variation_id, $sale_price, $sale_date_from );
		}
	}

	/**
	 * Handle changes in product sale data
	 *
	 * @param int         $product_id Product ID.
	 * @param string|null $sale_price Product sale price.
	 * @param string|null $sale_from Product sale start date.
	 */
	private function update_product_data( $product_id, $sale_price = null, $sale_from = null ) {
		$sale_from_date_field = isset( $sale_from ) ? wc_clean( wp_unslash( $sale_from ) ) : null;

		// Scheduled sales.
		if ( isset( $sale_from_date_field ) ) {
			$sale_from_date = new DateTime( $sale_from_date_field );
			$today          = new DateTime();

			if ( $sale_from_date > $today ) {
				return;
			}
		}

		$is_on_sale = isset( $sale_price ) && wc_clean( wp_unslash( $sale_price ) ) !== '';

		$this->manage_lowest_price_meta( $product_id, $is_on_sale );
	}

	/**
	 * Helper function to manage lowest price meta on changes
	 *
	 * @param int  $product_id Product / product variation ID.
	 * @param bool $is_on_sale If product sale price changed.
	 */
	private function manage_lowest_price_meta( $product_id, $is_on_sale ) {
		$was_on_sale = '' !== get_post_meta( $product_id, self::PRODUCT_META_KEY, true );

		// Add lowest price meta.
		if ( ! $was_on_sale && $is_on_sale ) {
			$this->add_lowest_price_meta( $product_id );
		}

		// Delete lowest price meta.
		if ( $was_on_sale && ! $is_on_sale ) {
			$this->remove_lowest_price_meta( $product_id );
		}
	}

	/**
	 * Manage scheduled sales start
	 *
	 * @param int[] $product_ids Product IDs.
	 */
	public function on_scheduled_sale_start( $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			$this->add_lowest_price_meta( $product_id );
			$this->clear_product_cache( $product_id );
		}
	}

	/**
	 * Manage scheduled sales start
	 *
	 * @param int[] $product_ids Product IDs.
	 */
	public function on_scheduled_sale_end( $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			$this->remove_lowest_price_meta( $product_id );
			$this->clear_product_cache( $product_id );
		}
	}

	/**
	 * Add lowest price meta field to product
	 *
	 * @param int $product_id Product ID.
	 */
	private function add_lowest_price_meta( $product_id ) {
		$lowest_price_from_log = $this->price_log->get_lowest_price( $product_id );
		// If there is no entry in price log save current regular price.
		$lowest_price = isset( $lowest_price_from_log ) ? $lowest_price_from_log : wc_get_product( $product_id )->get_regular_price();

		update_post_meta( $product_id, self::PRODUCT_META_KEY, $lowest_price );
	}

	/**
	 * Delete lowest price meta field to product
	 *
	 * @param int $product_id Product ID.
	 */
	private function remove_lowest_price_meta( $product_id ) {
		delete_post_meta( $product_id, self::PRODUCT_META_KEY );
	}

	/**
	 * Clear product page from cache.
	 *
	 * @param int $product_id Product ID.
	 */
	private function clear_product_cache( $product_id ) {
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $product_id );
		}
	}
}
