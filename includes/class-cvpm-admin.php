<?php
/**
 * Admin functionality for Centralized Variation Price Manager
 *
 * @package Centralized_Variation_Price_Manager
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CVPM Admin Class
 */
class CVPM_Admin {

    /**
     * Number of items per page
     *
     * @var int
     */
    private $per_page = 50;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_cvpm_update_variation_prices', array( $this, 'ajax_update_variation_prices' ) );
        add_action( 'wp_ajax_cvpm_get_variation_count', array( $this, 'ajax_get_variation_count' ) );
    }

    /**
     * Add admin menu under WooCommerce
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Variation Prices', 'centralized-variation-price-manager' ),
            __( 'Variation Prices', 'centralized-variation-price-manager' ),
            'manage_woocommerce',
            'cvpm-variation-prices',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'woocommerce_page_cvpm-variation-prices' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'cvpm-admin-css',
            CVPM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CVPM_VERSION
        );

        wp_enqueue_script(
            'cvpm-admin-js',
            CVPM_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            CVPM_VERSION,
            true
        );

        wp_localize_script( 'cvpm-admin-js', 'cvpmData', array(
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'cvpm_update_prices' ),
            'confirmMessage'    => __( 'This will update %d product(s). Continue?', 'centralized-variation-price-manager' ),
            'updatingMessage'   => __( 'Updating...', 'centralized-variation-price-manager' ),
            'updateButton'      => __( 'Update', 'centralized-variation-price-manager' ),
            'errorMessage'      => __( 'An error occurred. Please try again.', 'centralized-variation-price-manager' ),
            'invalidPriceError' => __( 'Please enter valid prices (numbers only).', 'centralized-variation-price-manager' ),
        ) );
    }

    /**
     * Get unique variation combinations from the database
     *
     * @param string $search Search term.
     * @param int    $page   Page number.
     * @return array
     */
    public function get_unique_variations( $search = '', $page = 1 ) {
        global $wpdb;

        // Get all variations with their attribute meta
        $sql = "
            SELECT 
                p.ID as variation_id,
                p.post_parent as product_id,
                GROUP_CONCAT(
                    CONCAT(
                        REPLACE(pm.meta_key, 'attribute_', ''),
                        ': ',
                        pm.meta_value
                    ) 
                    ORDER BY pm.meta_key 
                    SEPARATOR ' | '
                ) as combination,
                price.meta_value as current_price,
                regular.meta_value as regular_price,
                sale.meta_value as sale_price
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} price ON p.ID = price.post_id AND price.meta_key = '_price'
            LEFT JOIN {$wpdb->postmeta} regular ON p.ID = regular.post_id AND regular.meta_key = '_regular_price'
            LEFT JOIN {$wpdb->postmeta} sale ON p.ID = sale.post_id AND sale.meta_key = '_sale_price'
            WHERE p.post_type = 'product_variation'
            AND p.post_status IN ('publish', 'private')
            AND pm.meta_key LIKE 'attribute_%'
            AND pm.meta_value != ''
            GROUP BY p.ID
        ";

        $all_variations = $wpdb->get_results( $sql );

        if ( empty( $all_variations ) ) {
            return array(
                'items'       => array(),
                'total'       => 0,
                'total_pages' => 0,
            );
        }

        // Group by combination
        $grouped = array();
        foreach ( $all_variations as $variation ) {
            $combo = $variation->combination;
            
            if ( ! isset( $grouped[ $combo ] ) ) {
                $grouped[ $combo ] = array(
                    'combination'   => $combo,
                    'variation_ids' => array(),
                    'product_ids'   => array(),
                    'regular_price' => $variation->regular_price,
                    'sale_price'    => $variation->sale_price,
                    'current_price' => $variation->current_price,
                );
            }
            
            $grouped[ $combo ]['variation_ids'][] = $variation->variation_id;
            if ( ! in_array( $variation->product_id, $grouped[ $combo ]['product_ids'], true ) ) {
                $grouped[ $combo ]['product_ids'][] = $variation->product_id;
            }
        }

        // Convert to indexed array
        $items = array_values( $grouped );

        // Apply search filter
        if ( ! empty( $search ) ) {
            $search = strtolower( $search );
            $items = array_filter( $items, function( $item ) use ( $search ) {
                return strpos( strtolower( $item['combination'] ), $search ) !== false;
            } );
            $items = array_values( $items );
        }

        // Sort by combination
        usort( $items, function( $a, $b ) {
            return strcmp( $a['combination'], $b['combination'] );
        } );

        $total = count( $items );
        $total_pages = ceil( $total / $this->per_page );
        $offset = ( $page - 1 ) * $this->per_page;

        // Paginate
        $items = array_slice( $items, $offset, $this->per_page );

        // Add product count
        foreach ( $items as &$item ) {
            $item['product_count'] = count( $item['product_ids'] );
            $item['variation_count'] = count( $item['variation_ids'] );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
        );
    }

    /**
     * Render the admin page
     */
    public function render_admin_page() {
        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'centralized-variation-price-manager' ) );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

        $data = $this->get_unique_variations( $search, $page );
        $items = $data['items'];
        $total = $data['total'];
        $total_pages = $data['total_pages'];

        ?>
        <div class="wrap cvpm-wrap">
            <h1><?php esc_html_e( 'Centralized Variation Price Manager', 'centralized-variation-price-manager' ); ?></h1>

            <!-- Warning Box -->
            <div class="cvpm-warning-box">
                <h3>⚠️ <?php esc_html_e( 'IMPORTANT: Understanding WooCommerce Variations', 'centralized-variation-price-manager' ); ?></h3>
                <p><?php esc_html_e( 'Before using this plugin, you must understand how WooCommerce variations work:', 'centralized-variation-price-manager' ); ?></p>
                <ol>
                    <li><strong><?php esc_html_e( 'ATTRIBUTES:', 'centralized-variation-price-manager' ); ?></strong> <?php esc_html_e( 'These are created under Products → Attributes (e.g., "Size", "Color")', 'centralized-variation-price-manager' ); ?></li>
                    <li><strong><?php esc_html_e( 'ATTRIBUTE TERMS/VALUES:', 'centralized-variation-price-manager' ); ?></strong> <?php esc_html_e( 'These are the values for each attribute (e.g., for "Size": Small, Medium, Large)', 'centralized-variation-price-manager' ); ?></li>
                    <li><strong><?php esc_html_e( 'PRODUCT VARIATIONS:', 'centralized-variation-price-manager' ); ?></strong> <?php esc_html_e( 'These are combinations of attribute values assigned to variable products with specific prices', 'centralized-variation-price-manager' ); ?></li>
                </ol>
                <p><?php esc_html_e( 'This plugin updates prices based on SPECIFIC VARIATION COMBINATIONS in your products, NOT attribute terms directly. The same attribute value (e.g., "Red") may have different prices in different products or when combined with other attributes.', 'centralized-variation-price-manager' ); ?></p>
                <p class="cvpm-backup-warning">⚠️ <?php esc_html_e( 'BACKUP YOUR DATABASE BEFORE MAKING BULK CHANGES!', 'centralized-variation-price-manager' ); ?></p>
            </div>

            <!-- Search Box -->
            <form method="get" class="cvpm-search-form">
                <input type="hidden" name="page" value="cvpm-variation-prices">
                <p class="search-box">
                    <label class="screen-reader-text" for="variation-search-input"><?php esc_html_e( 'Search variations:', 'centralized-variation-price-manager' ); ?></label>
                    <input type="search" id="variation-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search variations...', 'centralized-variation-price-manager' ); ?>">
                    <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search', 'centralized-variation-price-manager' ); ?>">
                    <?php if ( ! empty( $search ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=cvpm-variation-prices' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'centralized-variation-price-manager' ); ?></a>
                    <?php endif; ?>
                </p>
            </form>

            <!-- Results Count -->
            <p class="cvpm-results-count">
                <?php
                printf(
                    /* translators: %d: number of unique variation combinations */
                    esc_html( _n( '%d unique variation combination found.', '%d unique variation combinations found.', $total, 'centralized-variation-price-manager' ) ),
                    $total
                );
                ?>
            </p>

            <!-- Admin Notices Container -->
            <div id="cvpm-notices"></div>

            <?php if ( ! empty( $items ) ) : ?>
                <!-- Variations Table -->
                <table class="wp-list-table widefat fixed striped cvpm-table">
                    <thead>
                        <tr>
                            <th class="column-combination"><?php esc_html_e( 'Variation Combination', 'centralized-variation-price-manager' ); ?></th>
                            <th class="column-regular-price"><?php esc_html_e( 'Regular Price', 'centralized-variation-price-manager' ); ?></th>
                            <th class="column-sale-price"><?php esc_html_e( 'Sale Price', 'centralized-variation-price-manager' ); ?></th>
                            <th class="column-current-price"><?php esc_html_e( 'Current Price', 'centralized-variation-price-manager' ); ?></th>
                            <th class="column-products"><?php esc_html_e( 'Products', 'centralized-variation-price-manager' ); ?></th>
                            <th class="column-actions"><?php esc_html_e( 'Actions', 'centralized-variation-price-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $items as $item ) : ?>
                            <tr data-combination="<?php echo esc_attr( $item['combination'] ); ?>" data-variation-ids="<?php echo esc_attr( wp_json_encode( $item['variation_ids'] ) ); ?>" data-product-ids="<?php echo esc_attr( wp_json_encode( $item['product_ids'] ) ); ?>">
                                <td class="column-combination">
                                    <strong><?php echo esc_html( $item['combination'] ); ?></strong>
                                    <br>
                                    <small class="cvpm-variation-count">
                                        <?php
                                        printf(
                                            /* translators: %d: number of variations */
                                            esc_html( _n( '%d variation', '%d variations', $item['variation_count'], 'centralized-variation-price-manager' ) ),
                                            $item['variation_count']
                                        );
                                        ?>
                                    </small>
                                </td>
                                <td class="column-regular-price">
                                    <input type="text" 
                                           class="cvpm-price-input regular-price-input" 
                                           value="<?php echo esc_attr( $item['regular_price'] ); ?>" 
                                           placeholder="<?php esc_attr_e( 'Regular price', 'centralized-variation-price-manager' ); ?>"
                                           data-original="<?php echo esc_attr( $item['regular_price'] ); ?>">
                                </td>
                                <td class="column-sale-price">
                                    <input type="text" 
                                           class="cvpm-price-input sale-price-input" 
                                           value="<?php echo esc_attr( $item['sale_price'] ); ?>" 
                                           placeholder="<?php esc_attr_e( 'Sale price', 'centralized-variation-price-manager' ); ?>"
                                           data-original="<?php echo esc_attr( $item['sale_price'] ); ?>">
                                </td>
                                <td class="column-current-price">
                                    <span class="cvpm-current-price"><?php echo esc_html( wc_price( $item['current_price'] ) ); ?></span>
                                </td>
                                <td class="column-products">
                                    <span class="cvpm-product-count"><?php echo esc_html( $item['product_count'] ); ?></span>
                                </td>
                                <td class="column-actions">
                                    <button type="button" class="button button-primary cvpm-update-btn">
                                        <?php esc_html_e( 'Update', 'centralized-variation-price-manager' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            $pagination_args = array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $page,
                            );

                            if ( ! empty( $search ) ) {
                                $pagination_args['add_args'] = array( 's' => $search );
                            }

                            echo wp_kses_post( paginate_links( $pagination_args ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <div class="cvpm-no-results">
                    <p><?php esc_html_e( 'No variation combinations found.', 'centralized-variation-price-manager' ); ?></p>
                    <?php if ( ! empty( $search ) ) : ?>
                        <p><?php esc_html_e( 'Try a different search term or', 'centralized-variation-price-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cvpm-variation-prices' ) ); ?>"><?php esc_html_e( 'view all variations', 'centralized-variation-price-manager' ); ?></a>.</p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Make sure you have variable products with variations in your WooCommerce store.', 'centralized-variation-price-manager' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX handler to get variation count for confirmation
     */
    public function ajax_get_variation_count() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'centralized-variation-price-manager' ) ) );
        }

        $variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) $_POST['variation_ids'] ) : array();

        wp_send_json_success( array( 'count' => count( $variation_ids ) ) );
    }

    /**
     * AJAX handler to update variation prices
     */
    public function ajax_update_variation_prices() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'centralized-variation-price-manager' ) ) );
        }

        // Get and validate input
        $variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) $_POST['variation_ids'] ) : array();
        $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
        $regular_price = isset( $_POST['regular_price'] ) ? sanitize_text_field( wp_unslash( $_POST['regular_price'] ) ) : '';
        $sale_price = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';

        if ( empty( $variation_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No variations selected.', 'centralized-variation-price-manager' ) ) );
        }

        // Validate prices
        if ( '' !== $regular_price && ! is_numeric( $regular_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Regular price must be a valid number.', 'centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && ! is_numeric( $sale_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Sale price must be a valid number.', 'centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $regular_price && floatval( $regular_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Regular price cannot be negative.', 'centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && floatval( $sale_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Sale price cannot be negative.', 'centralized-variation-price-manager' ) ) );
        }

        $updated_count = 0;
        $affected_products = array();

        foreach ( $variation_ids as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                continue;
            }

            // Update regular price
            if ( '' !== $regular_price ) {
                $variation->set_regular_price( $regular_price );
            }

            // Update sale price
            if ( '' !== $sale_price ) {
                $variation->set_sale_price( $sale_price );
            } elseif ( '' === $sale_price && isset( $_POST['sale_price'] ) ) {
                // Clear sale price if explicitly set to empty
                $variation->set_sale_price( '' );
            }

            // Save the variation
            $variation->save();

            $updated_count++;
            $parent_id = $variation->get_parent_id();
            if ( ! in_array( $parent_id, $affected_products, true ) ) {
                $affected_products[] = $parent_id;
            }
        }

        // Sync parent product prices
        foreach ( $affected_products as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                // Clear transients
                wc_delete_product_transients( $product_id );
                
                // Sync variable product prices
                if ( $product->is_type( 'variable' ) ) {
                    $product->sync();
                    $product->save();
                }
            }
        }

        // Calculate new current price for response
        $new_current_price = '';
        if ( '' !== $sale_price ) {
            $new_current_price = wc_price( $sale_price );
        } elseif ( '' !== $regular_price ) {
            $new_current_price = wc_price( $regular_price );
        }

        wp_send_json_success( array(
            'message'       => sprintf(
                /* translators: %d: number of variations updated */
                _n(
                    '%d variation updated successfully.',
                    '%d variations updated successfully.',
                    $updated_count,
                    'centralized-variation-price-manager'
                ),
                $updated_count
            ),
            'updated_count' => $updated_count,
            'new_current_price' => $new_current_price,
        ) );
    }
}
