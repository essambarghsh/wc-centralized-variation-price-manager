<?php
/**
 * Admin functionality for WC Centralized Variation Price Manager
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
     * Background processor instance
     *
     * @var CVPM_Background_Processor
     */
    private $background_processor;

    /**
     * Constructor
     */
    public function __construct() {
        $this->background_processor = new CVPM_Background_Processor();

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_cvpm_update_variation_prices', array( $this, 'ajax_update_variation_prices' ) );
        add_action( 'wp_ajax_cvpm_get_variation_count', array( $this, 'ajax_get_variation_count' ) );
        add_action( 'wp_ajax_cvpm_start_price_update', array( $this, 'ajax_start_price_update' ) );
        add_action( 'wp_ajax_cvpm_get_job_status', array( $this, 'ajax_get_job_status' ) );
        add_action( 'wp_ajax_cvpm_cancel_job', array( $this, 'ajax_cancel_job' ) );
        add_action( 'wp_ajax_cvpm_get_active_jobs', array( $this, 'ajax_get_active_jobs' ) );
    }

    /**
     * Add admin menu as top-level menu item
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Variation Prices', 'wc-centralized-variation-price-manager' ),
            __( 'Variation Prices', 'wc-centralized-variation-price-manager' ),
            'manage_woocommerce',
            'cvpm-variation-prices',
            array( $this, 'render_admin_page' ),
            'dashicons-tag',
            56
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_cvpm-variation-prices' !== $hook ) {
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

        // Get initial active jobs data
        $active_jobs = $this->background_processor->get_active_jobs();
        $processing_variation_ids = $this->background_processor->get_processing_variation_ids();

        wp_localize_script( 'cvpm-admin-js', 'cvpmData', array(
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( 'cvpm_update_prices' ),
            'confirmMessage'       => __( 'This will update %d variation(s). Continue?', 'wc-centralized-variation-price-manager' ),
            'updatingMessage'      => __( 'Updating...', 'wc-centralized-variation-price-manager' ),
            'updateButton'         => __( 'Update', 'wc-centralized-variation-price-manager' ),
            'errorMessage'         => __( 'An error occurred. Please try again.', 'wc-centralized-variation-price-manager' ),
            'invalidPriceError'    => __( 'Please enter valid prices (numbers only).', 'wc-centralized-variation-price-manager' ),
            // Progress dialog strings
            'dialogTitle'          => __( 'Updating Prices', 'wc-centralized-variation-price-manager' ),
            'processingText'       => __( 'Processing %1$d of %2$d variations', 'wc-centralized-variation-price-manager' ),
            'backgroundNote'       => __( 'You can close this page. Updates will continue in the background.', 'wc-centralized-variation-price-manager' ),
            'cancelButton'         => __( 'Cancel', 'wc-centralized-variation-price-manager' ),
            'closeButton'          => __( 'Close', 'wc-centralized-variation-price-manager' ),
            'completedText'        => __( 'Completed!', 'wc-centralized-variation-price-manager' ),
            'cancelledText'        => __( 'Cancelled', 'wc-centralized-variation-price-manager' ),
            'cancelConfirm'        => __( 'Are you sure you want to cancel? Some variations may have already been updated.', 'wc-centralized-variation-price-manager' ),
            // Active jobs strings
            'activeJobsTitle'      => __( 'Active Background Jobs', 'wc-centralized-variation-price-manager' ),
            'noActiveJobs'         => __( 'No active background jobs.', 'wc-centralized-variation-price-manager' ),
            'processingLabel'      => __( 'Processing...', 'wc-centralized-variation-price-manager' ),
            'variationsText'       => __( 'variations', 'wc-centralized-variation-price-manager' ),
            // Initial active jobs data
            'initialActiveJobs'    => $active_jobs,
            'initialProcessingIds' => $processing_variation_ids,
        ) );
    }

    /**
     * Format combination string for display
     * Separates the attribute slug (in small) from the value
     *
     * @param string $combination Raw combination string.
     * @return string Formatted HTML.
     */
    public function format_combination_display( $combination ) {
        $parts = explode( ' | ', $combination );
        $formatted_parts = array();

        foreach ( $parts as $part ) {
            // Split by ': ' to get slug and value
            $attr_parts = explode( ': ', $part, 2 );
            if ( count( $attr_parts ) === 2 ) {
                $slug = urldecode( $attr_parts[0] );
                $value = $attr_parts[1];
                $formatted_parts[] = sprintf(
                    '<span class="cvpm-attr-item"><small class="cvpm-attr-slug">%s</small><span class="cvpm-attr-value">%s</span></span>',
                    esc_html( $slug ),
                    esc_html( $value )
                );
            } else {
                // Fallback if format is unexpected
                $formatted_parts[] = esc_html( $part );
            }
        }

        return implode( '<span class="cvpm-attr-separator">|</span>', $formatted_parts );
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
                    'combination'    => $combo,
                    'variation_ids'  => array(),
                    'product_ids'    => array(),
                    'regular_price'  => $variation->regular_price,
                    'sale_price'     => $variation->sale_price,
                    'current_price'  => $variation->current_price,
                    'price_counts'   => array(), // Track price frequency
                );
            }
            
            $grouped[ $combo ]['variation_ids'][] = $variation->variation_id;
            if ( ! in_array( $variation->product_id, $grouped[ $combo ]['product_ids'], true ) ) {
                $grouped[ $combo ]['product_ids'][] = $variation->product_id;
            }

            // Track regular price frequency for inconsistency detection
            $price_key = (string) $variation->regular_price;
            if ( ! isset( $grouped[ $combo ]['price_counts'][ $price_key ] ) ) {
                $grouped[ $combo ]['price_counts'][ $price_key ] = 0;
            }
            $grouped[ $combo ]['price_counts'][ $price_key ]++;
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

        // Add product count and calculate inconsistent prices
        foreach ( $items as &$item ) {
            $item['product_count'] = count( $item['product_ids'] );
            $item['variation_count'] = count( $item['variation_ids'] );

            // Calculate inconsistent prices count
            $price_counts = $item['price_counts'];
            $item['inconsistent_count'] = 0;
            $item['has_inconsistent_prices'] = false;

            if ( count( $price_counts ) > 1 ) {
                // Find the most common price (majority)
                arsort( $price_counts );
                $majority_count = reset( $price_counts );
                $total_variations = array_sum( $price_counts );
                
                // Count variations with non-majority price
                $item['inconsistent_count'] = $total_variations - $majority_count;
                $item['has_inconsistent_prices'] = true;
            }

            // Remove price_counts from output as it's internal data
            unset( $item['price_counts'] );
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
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-centralized-variation-price-manager' ) );
        }

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

        $data = $this->get_unique_variations( $search, $page );
        $items = $data['items'];
        $total = $data['total'];
        $total_pages = $data['total_pages'];

        ?>
        <div class="wrap cvpm-wrap">
            <h1><?php esc_html_e( 'WC Centralized Variation Price Manager', 'wc-centralized-variation-price-manager' ); ?></h1>
            <p><?php esc_html_e( 'by Essam Barghsh / ashwab.com' ); ?></p>

            <!-- Search Box -->
            <form method="get" class="cvpm-search-form">
                <input type="hidden" name="page" value="cvpm-variation-prices">
                <p class="search-box">
                    <label class="screen-reader-text" for="variation-search-input"><?php esc_html_e( 'Search variations:', 'wc-centralized-variation-price-manager' ); ?></label>
                    <input type="search" id="variation-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search variations...', 'wc-centralized-variation-price-manager' ); ?>">
                    <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search', 'wc-centralized-variation-price-manager' ); ?>">
                    <?php if ( ! empty( $search ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=cvpm-variation-prices' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'wc-centralized-variation-price-manager' ); ?></a>
                    <?php endif; ?>
                </p>
            </form>

            <!-- Results Count -->
            <p class="cvpm-results-count">
                <?php
                printf(
                    /* translators: %d: number of unique variation combinations */
                    esc_html( _n( '%d unique variation combination found.', '%d unique variation combinations found.', $total, 'wc-centralized-variation-price-manager' ) ),
                    $total
                );
                ?>
            </p>

            <!-- Admin Notices Container -->
            <div id="cvpm-notices"></div>

            <!-- Active Background Jobs Card -->
            <div id="cvpm-active-jobs-container"></div>

            <?php if ( ! empty( $items ) ) : ?>
                <!-- Variations Table -->
                <table class="wp-list-table widefat fixed striped cvpm-table">
                    <thead>
                        <tr>
                            <th class="column-combination"><?php esc_html_e( 'Variation Combination', 'wc-centralized-variation-price-manager' ); ?></th>
                            <th class="column-regular-price"><?php esc_html_e( 'Regular Price', 'wc-centralized-variation-price-manager' ); ?></th>
                            <th class="column-sale-price"><?php esc_html_e( 'Sale Price', 'wc-centralized-variation-price-manager' ); ?></th>
                            <th class="column-current-price"><?php esc_html_e( 'Current Price', 'wc-centralized-variation-price-manager' ); ?></th>
                            <th class="column-products"><?php esc_html_e( 'Products', 'wc-centralized-variation-price-manager' ); ?></th>
                            <th class="column-actions"><?php esc_html_e( 'Actions', 'wc-centralized-variation-price-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $items as $item ) : ?>
                            <tr data-combination="<?php echo esc_attr( $item['combination'] ); ?>" data-variation-ids="<?php echo esc_attr( wp_json_encode( $item['variation_ids'] ) ); ?>" data-product-ids="<?php echo esc_attr( wp_json_encode( $item['product_ids'] ) ); ?>">
                                <td class="column-combination">
                                    <div class="cvpm-combination-display"><?php echo $this->format_combination_display( $item['combination'] ); ?></div>
                                    <small class="cvpm-variation-count">
                                        <?php
                                        printf(
                                            /* translators: %d: number of variations */
                                            esc_html( _n( '%d variation', '%d variations', $item['variation_count'], 'wc-centralized-variation-price-manager' ) ),
                                            $item['variation_count']
                                        );
                                        ?>
                                    </small>
                                </td>
                                <td class="column-regular-price">
                                    <input type="text" 
                                           class="cvpm-price-input regular-price-input" 
                                           value="<?php echo esc_attr( $item['regular_price'] ); ?>" 
                                           placeholder="<?php esc_attr_e( 'Regular price', 'wc-centralized-variation-price-manager' ); ?>"
                                           data-original="<?php echo esc_attr( $item['regular_price'] ); ?>">
                                </td>
                                <td class="column-sale-price">
                                    <input type="text" 
                                           class="cvpm-price-input sale-price-input" 
                                           value="<?php echo esc_attr( $item['sale_price'] ); ?>" 
                                           placeholder="<?php esc_attr_e( 'Sale price', 'wc-centralized-variation-price-manager' ); ?>"
                                           data-original="<?php echo esc_attr( $item['sale_price'] ); ?>">
                                </td>
                                <td class="column-current-price">
                                    <span class="cvpm-current-price"><?php echo wc_price( $item['current_price'] ); ?></span>
                                </td>
                                <td class="column-products">
                                    <span class="cvpm-product-count"><?php echo esc_html( $item['product_count'] ); ?></span>
                                    <?php if ( $item['inconsistent_count'] > 0 ) : ?>
                                        <span class="cvpm-inconsistent-badge" title="<?php esc_attr_e( 'Variations with different prices', 'wc-centralized-variation-price-manager' ); ?>">
                                            <?php
                                            printf(
                                                /* translators: %d: number of variations with different prices */
                                                esc_html__( '%d differ', 'wc-centralized-variation-price-manager' ),
                                                $item['inconsistent_count']
                                            );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions">
                                    <button type="button" class="button button-primary cvpm-update-btn">
                                        <?php esc_html_e( 'Update', 'wc-centralized-variation-price-manager' ); ?>
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
                    <p><?php esc_html_e( 'No variation combinations found.', 'wc-centralized-variation-price-manager' ); ?></p>
                    <?php if ( ! empty( $search ) ) : ?>
                        <p><?php esc_html_e( 'Try a different search term or', 'wc-centralized-variation-price-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cvpm-variation-prices' ) ); ?>"><?php esc_html_e( 'view all variations', 'wc-centralized-variation-price-manager' ); ?></a>.</p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Make sure you have variable products with variations in your WooCommerce store.', 'wc-centralized-variation-price-manager' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Progress Dialog -->
            <div id="cvpm-progress-overlay" class="cvpm-progress-overlay" style="display: none;">
                <div class="cvpm-progress-dialog">
                    <div class="cvpm-progress-header">
                        <h2 class="cvpm-progress-title"><?php esc_html_e( 'Updating Prices', 'wc-centralized-variation-price-manager' ); ?></h2>
                        <button type="button" class="cvpm-progress-close" aria-label="<?php esc_attr_e( 'Close', 'wc-centralized-variation-price-manager' ); ?>">&times;</button>
                    </div>
                    <div class="cvpm-progress-body">
                        <div class="cvpm-progress-bar-container">
                            <div class="cvpm-progress-bar" style="width: 0%;">
                                <span class="cvpm-progress-percentage">0%</span>
                            </div>
                        </div>
                        <p class="cvpm-progress-status"><?php esc_html_e( 'Starting...', 'wc-centralized-variation-price-manager' ); ?></p>
                        <div class="cvpm-progress-logs">
                            <div class="cvpm-logs-container"></div>
                        </div>
                        <p class="cvpm-progress-note">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e( 'You can close this page. Updates will continue in the background.', 'wc-centralized-variation-price-manager' ); ?>
                        </p>
                    </div>
                    <div class="cvpm-progress-footer">
                        <button type="button" class="button cvpm-cancel-btn"><?php esc_html_e( 'Cancel', 'wc-centralized-variation-price-manager' ); ?></button>
                        <button type="button" class="button button-primary cvpm-close-btn" style="display: none;"><?php esc_html_e( 'Close', 'wc-centralized-variation-price-manager' ); ?></button>
                    </div>
                </div>
            </div>
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
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) $_POST['variation_ids'] ) : array();

        wp_send_json_success( array( 'count' => count( $variation_ids ) ) );
    }

    /**
     * AJAX handler to update variation prices (optimized with direct SQL)
     */
    public function ajax_update_variation_prices() {
        global $wpdb;

        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Get and validate input
        $variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) $_POST['variation_ids'] ) : array();
        $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
        $regular_price = isset( $_POST['regular_price'] ) ? sanitize_text_field( wp_unslash( $_POST['regular_price'] ) ) : '';
        $sale_price = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';
        $clear_sale_price = isset( $_POST['sale_price'] ) && '' === $sale_price;

        if ( empty( $variation_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No variations selected.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Validate prices
        if ( '' !== $regular_price && ! is_numeric( $regular_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Regular price must be a valid number.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && ! is_numeric( $sale_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Sale price must be a valid number.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $regular_price && floatval( $regular_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Regular price cannot be negative.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && floatval( $sale_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Sale price cannot be negative.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Validate variation IDs, get parent products, and current prices in a single query
        $ids_placeholder = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
        $valid_variations = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_parent,
                    MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as current_regular,
                    MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as current_sale
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_regular_price', '_sale_price')
             WHERE p.ID IN ({$ids_placeholder}) 
             AND p.post_type = 'product_variation' 
             AND p.post_status IN ('publish', 'private')
             GROUP BY p.ID, p.post_parent",
            ...$variation_ids
        ) );

        if ( empty( $valid_variations ) ) {
            wp_send_json_error( array( 'message' => __( 'No valid variations found.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Filter out variations that already have the target prices
        $ids_to_update = array();
        $affected_products = array();
        $skipped_same_price = 0;
        
        foreach ( $valid_variations as $variation ) {
            $vid = (int) $variation->ID;
            $needs_update = false;
            
            // Check regular price
            if ( '' !== $regular_price && (string) $variation->current_regular !== (string) $regular_price ) {
                $needs_update = true;
            }
            
            // Check sale price
            if ( '' !== $sale_price && (string) $variation->current_sale !== (string) $sale_price ) {
                $needs_update = true;
            }
            
            // Check if clearing sale price
            if ( $clear_sale_price && '' !== $variation->current_sale && null !== $variation->current_sale ) {
                $needs_update = true;
            }
            
            if ( $needs_update ) {
                $ids_to_update[] = $vid;
                $parent_id = (int) $variation->post_parent;
                if ( ! in_array( $parent_id, $affected_products, true ) ) {
                    $affected_products[] = $parent_id;
                }
            } else {
                $skipped_same_price++;
            }
        }

        // If all variations already have the correct price
        if ( empty( $ids_to_update ) ) {
            wp_send_json_success( array(
                'message'           => sprintf(
                    /* translators: %d: number of variations */
                    _n(
                        '%d variation already has the correct price.',
                        '%d variations already have the correct prices.',
                        $skipped_same_price,
                        'wc-centralized-variation-price-manager'
                    ),
                    $skipped_same_price
                ),
                'updated_count'     => 0,
                'skipped_count'     => $skipped_same_price,
                'new_current_price' => '',
            ) );
        }

        $ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_update ), '%d' ) );

        // Begin transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Update regular price if provided
            if ( '' !== $regular_price ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_regular_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $regular_price ), $ids_to_update )
                ) );

                // Insert for variations without _regular_price
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                     SELECT p.ID, '_regular_price', %s
                     FROM {$wpdb->posts} p
                     WHERE p.ID IN ({$ids_placeholder})
                     AND NOT EXISTS (
                         SELECT 1 FROM {$wpdb->postmeta} pm 
                         WHERE pm.post_id = p.ID AND pm.meta_key = '_regular_price'
                     )",
                    array_merge( array( $regular_price ), $ids_to_update )
                ) );
            }

            // Update sale price
            if ( '' !== $sale_price ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_sale_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $sale_price ), $ids_to_update )
                ) );

                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                     SELECT p.ID, '_sale_price', %s
                     FROM {$wpdb->posts} p
                     WHERE p.ID IN ({$ids_placeholder})
                     AND NOT EXISTS (
                         SELECT 1 FROM {$wpdb->postmeta} pm 
                         WHERE pm.post_id = p.ID AND pm.meta_key = '_sale_price'
                     )",
                    array_merge( array( $sale_price ), $ids_to_update )
                ) );
            } elseif ( $clear_sale_price ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = '' 
                     WHERE meta_key = '_sale_price' 
                     AND post_id IN ({$ids_placeholder})",
                    $ids_to_update
                ) );
            }

            // Update _price meta (current active price)
            $active_price = ( '' !== $sale_price ) ? $sale_price : $regular_price;
            if ( '' !== $active_price ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $active_price ), $ids_to_update )
                ) );

                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                     SELECT p.ID, '_price', %s
                     FROM {$wpdb->posts} p
                     WHERE p.ID IN ({$ids_placeholder})
                     AND NOT EXISTS (
                         SELECT 1 FROM {$wpdb->postmeta} pm 
                         WHERE pm.post_id = p.ID AND pm.meta_key = '_price'
                     )",
                    array_merge( array( $active_price ), $ids_to_update )
                ) );
            }

            $wpdb->query( 'COMMIT' );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'message' => __( 'Database error occurred.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $updated_count = count( $ids_to_update );

        // Sync parent product prices and clear caches
        foreach ( $affected_products as $product_id ) {
            wc_delete_product_transients( $product_id );
            WC_Product_Variable::sync( $product_id );
            clean_post_cache( $product_id );
        }

        // Calculate new current price for response
        $new_current_price = '';
        if ( '' !== $sale_price ) {
            $new_current_price = wc_price( $sale_price );
        } elseif ( '' !== $regular_price ) {
            $new_current_price = wc_price( $regular_price );
        }

        // Build response message
        $message_parts = array();
        if ( $updated_count > 0 ) {
            $message_parts[] = sprintf(
                /* translators: %d: number of variations updated */
                _n(
                    '%d variation updated',
                    '%d variations updated',
                    $updated_count,
                    'wc-centralized-variation-price-manager'
                ),
                $updated_count
            );
        }
        if ( $skipped_same_price > 0 ) {
            $message_parts[] = sprintf(
                /* translators: %d: number of variations skipped */
                _n(
                    '%d already correct',
                    '%d already correct',
                    $skipped_same_price,
                    'wc-centralized-variation-price-manager'
                ),
                $skipped_same_price
            );
        }

        wp_send_json_success( array(
            'message'           => implode( ', ', $message_parts ) . '.',
            'updated_count'     => $updated_count,
            'skipped_count'     => $skipped_same_price,
            'new_current_price' => $new_current_price,
        ) );
    }

    /**
     * AJAX handler to start a background price update job
     */
    public function ajax_start_price_update() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Get and validate input
        $variation_ids = isset( $_POST['variation_ids'] ) ? array_map( 'absint', (array) $_POST['variation_ids'] ) : array();
        $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
        $regular_price = isset( $_POST['regular_price'] ) ? sanitize_text_field( wp_unslash( $_POST['regular_price'] ) ) : '';
        $sale_price = isset( $_POST['sale_price'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_price'] ) ) : '';

        if ( empty( $variation_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No variations selected.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Validate prices
        if ( '' !== $regular_price && ! is_numeric( $regular_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Regular price must be a valid number.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && ! is_numeric( $sale_price ) ) {
            wp_send_json_error( array( 'message' => __( 'Sale price must be a valid number.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $regular_price && floatval( $regular_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Regular price cannot be negative.', 'wc-centralized-variation-price-manager' ) ) );
        }

        if ( '' !== $sale_price && floatval( $sale_price ) < 0 ) {
            wp_send_json_error( array( 'message' => __( 'Sale price cannot be negative.', 'wc-centralized-variation-price-manager' ) ) );
        }

        // Start background job
        $job_id = $this->background_processor->start_job( $variation_ids, $product_ids, $regular_price, $sale_price );

        wp_send_json_success( array(
            'job_id'  => $job_id,
            'message' => __( 'Price update started.', 'wc-centralized-variation-price-manager' ),
        ) );
    }

    /**
     * AJAX handler to get job status
     */
    public function ajax_get_job_status() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $status = $this->background_processor->get_job_status( $job_id );

        if ( ! $status ) {
            wp_send_json_error( array( 'message' => __( 'Job not found.', 'wc-centralized-variation-price-manager' ) ) );
        }

        wp_send_json_success( $status );
    }

    /**
     * AJAX handler to cancel a job
     */
    public function ajax_cancel_job() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid job ID.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $result = $this->background_processor->cancel_job( $job_id );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to cancel job.', 'wc-centralized-variation-price-manager' ) ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Job cancelled.', 'wc-centralized-variation-price-manager' ),
        ) );
    }

    /**
     * AJAX handler to get active jobs
     */
    public function ajax_get_active_jobs() {
        // Verify nonce
        check_ajax_referer( 'cvpm_update_prices', 'nonce' );

        // Check capabilities
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-centralized-variation-price-manager' ) ) );
        }

        $active_jobs = $this->background_processor->get_active_jobs();
        $processing_variation_ids = $this->background_processor->get_processing_variation_ids();

        wp_send_json_success( array(
            'jobs'                     => $active_jobs,
            'processing_variation_ids' => $processing_variation_ids,
        ) );
    }
}
