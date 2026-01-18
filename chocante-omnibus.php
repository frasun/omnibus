<?php
/**
 * Plugin Name: Omnibus
 * Description: Collect information about product price changes and display the lowest price.
 * Version: 1.1.1
 * Author: Chocante
 * Text Domain: chocante-omnibus
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package ChocanteOmnibus
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MAIN_PLUGIN_FILE' ) ) {
	define( 'MAIN_PLUGIN_FILE', __FILE__ );
}

/**
 * Current plugin version.
 */
define( 'CHOCANTE_OMNIBUS_VERSION', '1.0.0' );

require_once plugin_dir_path( __FILE__ ) . '/includes/class-chocanteomnibus.php';
add_action( 'plugins_loaded', 'chocante_omnibus_init', 10 );

/**
 * Initialize the plugin
 */
function chocante_omnibus_init() {
	load_plugin_textdomain( 'chocante-omnibus', false, plugin_basename( __DIR__ ) . '/languages' );

	ChocanteOmnibus::instance();
}

register_activation_hook( __FILE__, 'chocante_omnibus_activate' );

/**
 * Activation hook
 */
function chocante_omnibus_activate() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'chocante_omnibus_missing_wc_notice' );
		return;
	}

	ChocanteOmnibus::instance()->activate();

	register_deactivation_hook( __FILE__, 'chocante_omnibus_deactivate' );
	register_uninstall_hook( __FILE__, 'chocante_omnibus_uninstall' );
}

/**
 * WooCommerce fallback notice
 */
function chocante_omnibus_missing_wc_notice() {
	/* translators: %s WC download URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Omnibus requires WooCommerce to be installed and active. You can download %s here.', 'chocante-omnibus' ), '<a href="https://woo.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

/**
 * Deactivation hook
 */
function chocante_omnibus_deactivate() {
	ChocanteOmnibus::instance()->deactivate();
}

/**
 * Uninstallation hook
 */
function chocante_omnibus_uninstall() {
	ChocanteOmnibus::instance()->uninstall();
}
