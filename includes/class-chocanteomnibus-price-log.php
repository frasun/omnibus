<?php
/**
 * Database operations
 *
 * @package ChocanteOmnibus
 */

defined( 'ABSPATH' ) || exit;

/**
 * The ChocanteOmnibus class.
 */
class ChocanteOmnibus_Price_Log {
	const PRICE_LOG_TABLE      = 'chocante_omnibus_price_log';
	const PRICE_LOG_EXPIRATION = 31;
	const CLEAN_HOOK           = 'chocante_omnibus_clean';

	/**
	 * WordPress database helper
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Price log table name prefixed
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor
	 *
	 * @param wpdb $wpdb WordPress database abstraction.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb       = $wpdb;
		$this->table_name = $this->wpdb->prefix . self::PRICE_LOG_TABLE;

		$this->init();
	}

	/**
	 * Create price log database table
	 */
	public function activate() {
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $this->table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		product_id bigint(20) unsigned NOT NULL,
		price varchar(255) NOT NULL,
		date_changed date NOT NULL default (CURRENT_DATE),
		PRIMARY KEY  (id),
		KEY product_id (product_id),
		KEY product_price_index (product_id, price),
		KEY product_date_index (product_id, date_changed),
		KEY date_changed (date_changed)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Remove price log database table
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( self::CLEAN_HOOK );
	}

	/**
	 * Remove price log database table
	 */
	public function uninstall() {
		$query = sprintf( 'DROP TABLE IF EXISTS %s', $this->table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $query );

		delete_post_meta_by_key( ChocanteOmnibus::PRODUCT_META_KEY );
	}

	/**
	 * Register hooks
	 */
	private function init() {
		if ( is_admin() ) {
			// Handle product updates.
			add_action( 'save_post_product', array( $this, 'save_product_price' ), 9, 3 );
			add_action( 'woocommerce_ajax_save_product_variations', array( $this, 'save_variation_price' ), 9, 3 );
		}

		// Daily clean old logs.
		add_action( self::CLEAN_HOOK, array( $this, 'clean_old_logs' ) );
		if ( ! wp_next_scheduled( self::CLEAN_HOOK ) ) {
			wp_schedule_event( strtotime( 'today 00:00' ), 'daily', self::CLEAN_HOOK );
		}
	}

	/**
	 * Save product price information to database
	 *
	 * @param int    $product_id Product ID.
	 * @param string $price Product price.
	 */
	private function log_price_to_db( $product_id, $price ) {
		$this->wpdb->insert(
			$this->table_name,
			array(
				'product_id' => $product_id,
				'price'      => $price,
			)
		);
	}

	/**
	 * Save product price information to database
	 *
	 * @param int         $product_id Product ID.
	 * @param string|null $price Product price.
	 */
	private function log_price( $product_id, $price ) {
		if ( isset( $price ) && '' !== $price ) {
			$formatted_price = $this->format_price( $price );

			// phpcs:ignore
			$query = $this->wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE product_id = %s AND price = %s;", $product_id, $formatted_price );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $this->wpdb->get_results( $query );

			if ( empty( $results ) ) {
				$this->log_price_to_db( $product_id, $this->format_price( $formatted_price ) );
			}
		}
	}

	/**
	 * Log product regular price
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_price( $product_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( wp_is_post_revision( $product_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$price = isset( $_POST['_regular_price'] ) ? sanitize_text_field( wp_unslash( ( $_POST['_regular_price'] ) ) ) : null;

		$this->log_price( $product_id, $price );
	}

	/**
	 * Log product variation regular price
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_variation_price( $product_id ) {
		$variations = wc_get_product( $product_id )->get_children();

		foreach ( $variations as $index => $variation_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$price = isset( $_POST['variable_regular_price'][ $index ] ) ? sanitize_text_field( wp_unslash( ( $_POST['variable_regular_price'][ $index ] ) ) ) : null;

			$this->log_price( $variation_id, $price );
		}
	}

	/**
	 * Formats price before saving to db
	 *
	 * @param string|int|float $price Price.
	 */
	private function format_price( $price ) {
		$val = strval( $price );
		$val = str_replace( ' ', '', $val );
		$val = str_replace( ',', '.', $val );
		$val = preg_replace( '/\.(?=.*\.)/', '', $val );
		return $val;
	}

	/**
	 * Return lowest price from price log or null
	 *
	 * @param int $product_id Product ID.
	 */
	public function get_lowest_price( $product_id ) {
		// phpcs:ignore
		$query = $this->wpdb->prepare( "SELECT price FROM {$this->table_name} WHERE product_id = %s AND date_changed < CURRENT_DATE() ORDER BY ABS(price) ASC LIMIT 1;", $product_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $query );

		return count( $results ) > 0 ? $results[0]->price : null;
	}

	/**
	 * Remove old price logs
	 *
	 * @param int $days_to_remember Number of days that logs should be kept.
	 */
	public function clean_old_logs( $days_to_remember = self::PRICE_LOG_EXPIRATION ) {
		// phpcs:ignore
		$query = $this->wpdb->prepare( "DELETE FROM {$this->table_name} WHERE date_changed < DATE_SUB(CURRENT_DATE(), INTERVAL %d DAY);", $days_to_remember );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $query );
	}
}
