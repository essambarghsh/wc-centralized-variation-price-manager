<?php
/**
 * Plugin Name: WC Centralized Variation Price Manager
 * Plugin URI: https://esssam.com
 * Description: Manage WooCommerce product variation prices from a centralized interface. Update prices for all products with the same variation combination at once.
 * Version: 1.0.0
 * Author: Essam Barghsh
 * Author URI: https://esssam.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-centralized-variation-price-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 *
 * @package Centralized_Variation_Price_Manager
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'CVPM_VERSION', '1.0.0' );
define( 'CVPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CVPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CVPM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active
 *
 * @return bool
 */
function cvpm_is_woocommerce_active() {
    return in_array(
        'woocommerce/woocommerce.php',
        apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
        true
    );
}

/**
 * Display admin notice if WooCommerce is not active
 */
function cvpm_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <?php
            printf(
                /* translators: %s: WooCommerce plugin name */
                esc_html__( '%s requires WooCommerce to be installed and active.', 'wc-centralized-variation-price-manager' ),
                '<strong>WC Centralized Variation Price Manager</strong>'
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function cvpm_init() {
    // Check if WooCommerce is active
    if ( ! cvpm_is_woocommerce_active() ) {
        add_action( 'admin_notices', 'cvpm_woocommerce_missing_notice' );
        return;
    }

    // Load text domain for translations
    load_plugin_textdomain(
        'wc-centralized-variation-price-manager',
        false,
        dirname( CVPM_PLUGIN_BASENAME ) . '/languages'
    );

    // Include required classes
    require_once CVPM_PLUGIN_DIR . 'includes/class-cvpm-background-processor.php';
    require_once CVPM_PLUGIN_DIR . 'includes/class-cvpm-admin.php';

    // Initialize admin
    if ( is_admin() ) {
        new CVPM_Admin();
    }
}
add_action( 'plugins_loaded', 'cvpm_init' );

/**
 * Plugin activation hook
 */
function cvpm_activate() {
    // Check if WooCommerce is active
    if ( ! cvpm_is_woocommerce_active() ) {
        deactivate_plugins( CVPM_PLUGIN_BASENAME );
        wp_die(
            esc_html__( 'WC Centralized Variation Price Manager requires WooCommerce to be installed and active.', 'wc-centralized-variation-price-manager' ),
            'Plugin Activation Error',
            array( 'back_link' => true )
        );
    }

    // Set activation flag for welcome message
    set_transient( 'cvpm_activation_redirect', true, 30 );
}
register_activation_hook( __FILE__, 'cvpm_activate' );

/**
 * Plugin deactivation hook
 */
function cvpm_deactivate() {
    // Clean up transients
    delete_transient( 'cvpm_activation_redirect' );
}
register_deactivation_hook( __FILE__, 'cvpm_deactivate' );

/**
 * Declare HPOS compatibility
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
