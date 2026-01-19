<?php
/**
 * Background Processor for WC Centralized Variation Price Manager
 *
 * Handles background processing of price updates using WooCommerce Action Scheduler
 *
 * @package Centralized_Variation_Price_Manager
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CVPM Background Processor Class
 */
class CVPM_Background_Processor {

    /**
     * Batch size for processing variations (increased from 10 to 100 for better performance)
     *
     * @var int
     */
    private $batch_size = 100;

    /**
     * Maximum log entries to keep per job
     *
     * @var int
     */
    private $max_logs = 100;

    /**
     * Action Scheduler hook name
     *
     * @var string
     */
    const BATCH_HOOK = 'cvpm_process_price_batch';

    /**
     * Cleanup hook name
     *
     * @var string
     */
    const CLEANUP_HOOK = 'cvpm_cleanup_completed_jobs';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( self::BATCH_HOOK, array( $this, 'process_batch' ), 10, 2 );
        add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_old_jobs' ) );

        // Schedule cleanup if not already scheduled
        if ( ! as_next_scheduled_action( self::CLEANUP_HOOK ) ) {
            as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::CLEANUP_HOOK );
        }
    }

    /**
     * Start a new price update job
     *
     * @param array  $variation_ids Array of variation IDs to update.
     * @param array  $product_ids   Array of parent product IDs.
     * @param string $regular_price New regular price.
     * @param string $sale_price    New sale price.
     * @return string Job ID.
     */
    public function start_job( $variation_ids, $product_ids, $regular_price, $sale_price ) {
        $job_id = 'cvpm_job_' . uniqid();

        $job_data = array(
            'status'        => 'processing',
            'total'         => count( $variation_ids ),
            'processed'     => 0,
            'variation_ids' => $variation_ids,
            'product_ids'   => $product_ids,
            'regular_price' => $regular_price,
            'sale_price'    => $sale_price,
            'logs'          => array(
                array(
                    'time'    => current_time( 'timestamp' ),
                    'message' => sprintf(
                        /* translators: %d: number of variations */
                        __( 'Started processing %d variations...', 'wc-centralized-variation-price-manager' ),
                        count( $variation_ids )
                    ),
                ),
            ),
            'created_at'    => current_time( 'timestamp' ),
            'updated_at'    => current_time( 'timestamp' ),
        );

        update_option( $job_id, $job_data, false );

        // Schedule batch processing - reduced stagger time from 2s to 1s
        $batches = array_chunk( $variation_ids, $this->batch_size );
        foreach ( $batches as $batch_index => $batch ) {
            as_schedule_single_action(
                time() + $batch_index, // Stagger batches by 1 second (reduced from 2)
                self::BATCH_HOOK,
                array(
                    'job_id'      => $job_id,
                    'batch_index' => $batch_index,
                ),
                'cvpm'
            );
        }

        return $job_id;
    }

    /**
     * Process a batch of variations using optimized direct database queries
     *
     * @param string $job_id      Job ID.
     * @param int    $batch_index Batch index.
     */
    public function process_batch( $job_id, $batch_index ) {
        global $wpdb;

        $job_data = get_option( $job_id );

        if ( ! $job_data || 'cancelled' === $job_data['status'] ) {
            return;
        }

        $start = $batch_index * $this->batch_size;
        $batch_ids = array_slice( $job_data['variation_ids'], $start, $this->batch_size );

        if ( empty( $batch_ids ) ) {
            return;
        }

        $regular_price = $job_data['regular_price'];
        $sale_price = $job_data['sale_price'];
        $clear_sale_price = array_key_exists( 'sale_price', $job_data ) && '' === $sale_price;
        $batch_logs = array();
        $updated_count = 0;
        $skipped_invalid = 0;
        $skipped_same_price = 0;

        // Validate variation IDs and get current prices in a single query
        $ids_placeholder = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
        $valid_variations = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_parent,
                    MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) as regular_price,
                    MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) as sale_price
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key IN ('_regular_price', '_sale_price')
             WHERE p.ID IN ({$ids_placeholder}) 
             AND p.post_type = 'product_variation' 
             AND p.post_status IN ('publish', 'private')
             GROUP BY p.ID, p.post_parent",
            ...$batch_ids
        ) );

        $valid_ids = array();
        $ids_to_update = array();
        $parent_ids = array();
        
        foreach ( $valid_variations as $variation ) {
            $vid = (int) $variation->ID;
            $valid_ids[] = $vid;
            
            // Check if this variation needs updating
            $needs_update = false;
            
            // Check regular price
            if ( '' !== $regular_price && (string) $variation->regular_price !== (string) $regular_price ) {
                $needs_update = true;
            }
            
            // Check sale price
            if ( '' !== $sale_price && (string) $variation->sale_price !== (string) $sale_price ) {
                $needs_update = true;
            }
            
            // Check if clearing sale price
            if ( $clear_sale_price && '' !== $variation->sale_price && null !== $variation->sale_price ) {
                $needs_update = true;
            }
            
            if ( $needs_update ) {
                $ids_to_update[] = $vid;
                $parent_ids[ $vid ] = (int) $variation->post_parent;
            } else {
                $skipped_same_price++;
            }
        }

        // Track invalid variations
        $skipped_invalid = count( array_diff( $batch_ids, $valid_ids ) );

        if ( empty( $ids_to_update ) ) {
            $batch_logs[] = sprintf(
                /* translators: 1: batch number, 2: skipped same price, 3: skipped invalid */
                __( 'Batch %1$d: No updates needed (already correct: %2$d%3$s)', 'wc-centralized-variation-price-manager' ),
                $batch_index + 1,
                $skipped_same_price,
                $skipped_invalid > 0 ? sprintf( __(', invalid: %d', 'wc-centralized-variation-price-manager' ), $skipped_invalid ) : ''
            );
            $this->update_job_progress( $job_id, count( $batch_ids ), $batch_logs );
            return;
        }

        // Use direct SQL for bulk price updates (much faster than WC product objects)
        $ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_update ), '%d' ) );

        // Begin transaction for consistency
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Update regular price if provided
            if ( '' !== $regular_price ) {
                // Update existing _regular_price meta
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_regular_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $regular_price ), $ids_to_update )
                ) );

                // Insert _regular_price for variations that don't have it
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
                // Update existing _sale_price meta
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_sale_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $sale_price ), $ids_to_update )
                ) );

                // Insert _sale_price for variations that don't have it
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
                // Clear sale price if explicitly set to empty
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
                // Update existing _price meta
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} 
                     SET meta_value = %s 
                     WHERE meta_key = '_price' 
                     AND post_id IN ({$ids_placeholder})",
                    array_merge( array( $active_price ), $ids_to_update )
                ) );

                // Insert _price for variations that don't have it
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
            $updated_count = count( $ids_to_update );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            $batch_logs[] = sprintf(
                /* translators: 1: batch number, 2: error message */
                __( 'Batch %1$d: Error - %2$s', 'wc-centralized-variation-price-manager' ),
                $batch_index + 1,
                $e->getMessage()
            );
            $this->update_job_progress( $job_id, count( $batch_ids ), $batch_logs );
            return;
        }

        // Get unique parent product IDs (only for updated variations)
        $affected_products = array_unique( array_values( $parent_ids ) );

        // Sync parent products and clear caches (batch operation)
        foreach ( $affected_products as $product_id ) {
            // Clear product transients
            wc_delete_product_transients( $product_id );

            // Sync variable product price range
            WC_Product_Variable::sync( $product_id );

            // Clear object cache for this product
            clean_post_cache( $product_id );
        }

        // Add batch completion log with detailed skip info
        $skip_parts = array();
        if ( $skipped_same_price > 0 ) {
            $skip_parts[] = sprintf( __( '%d already correct', 'wc-centralized-variation-price-manager' ), $skipped_same_price );
        }
        if ( $skipped_invalid > 0 ) {
            $skip_parts[] = sprintf( __( '%d invalid', 'wc-centralized-variation-price-manager' ), $skipped_invalid );
        }
        
        $batch_logs[] = sprintf(
            /* translators: 1: batch number, 2: count updated, 3: skip details */
            __( 'Batch %1$d: Updated %2$d variations%3$s', 'wc-centralized-variation-price-manager' ),
            $batch_index + 1,
            $updated_count,
            ! empty( $skip_parts ) ? ' (' . implode( ', ', $skip_parts ) . ')' : ''
        );

        if ( ! empty( $affected_products ) ) {
            $batch_logs[] = sprintf(
                /* translators: %d: number of products */
                __( 'Synced %d parent products', 'wc-centralized-variation-price-manager' ),
                count( $affected_products )
            );
        }

        // Update job progress once at the end of batch
        $this->update_job_progress( $job_id, count( $batch_ids ), $batch_logs );
    }

    /**
     * Update job progress with batch results (single database operation per batch)
     *
     * @param string $job_id         Job ID.
     * @param int    $processed_count Number of variations processed in this batch.
     * @param array  $logs           Log messages to add.
     */
    private function update_job_progress( $job_id, $processed_count, $logs = array() ) {
        $job_data = get_option( $job_id );

        if ( ! $job_data ) {
            return;
        }

        // Update processed count
        $job_data['processed'] += $processed_count;
        $job_data['updated_at'] = current_time( 'timestamp' );

        // Add all logs at once
        foreach ( $logs as $message ) {
            $job_data['logs'][] = array(
                'time'    => current_time( 'timestamp' ),
                'message' => $message,
            );
        }

        // Keep only the last N log entries
        if ( count( $job_data['logs'] ) > $this->max_logs ) {
            $job_data['logs'] = array_slice( $job_data['logs'], -$this->max_logs );
        }

        // Check if job is complete
        if ( $job_data['processed'] >= $job_data['total'] ) {
            $job_data['status'] = 'completed';
            $job_data['logs'][] = array(
                'time'    => current_time( 'timestamp' ),
                'message' => __( 'All variations updated successfully!', 'wc-centralized-variation-price-manager' ),
            );
        }

        // Single database write per batch
        update_option( $job_id, $job_data, false );
    }

    /**
     * Get job status
     *
     * @param string $job_id Job ID.
     * @return array|false Job data or false if not found.
     */
    public function get_job_status( $job_id ) {
        $job_data = get_option( $job_id );

        if ( ! $job_data ) {
            return false;
        }

        $percentage = 0;
        if ( $job_data['total'] > 0 ) {
            $percentage = round( ( $job_data['processed'] / $job_data['total'] ) * 100 );
        }

        return array(
            'status'     => $job_data['status'],
            'total'      => $job_data['total'],
            'processed'  => $job_data['processed'],
            'percentage' => $percentage,
            'logs'       => $job_data['logs'],
        );
    }

    /**
     * Cancel a job
     *
     * @param string $job_id Job ID.
     * @return bool True on success.
     */
    public function cancel_job( $job_id ) {
        $job_data = get_option( $job_id );

        if ( ! $job_data ) {
            return false;
        }

        // Mark as cancelled
        $job_data['status'] = 'cancelled';
        $job_data['logs'][] = array(
            'time'    => current_time( 'timestamp' ),
            'message' => __( 'Job cancelled by user.', 'wc-centralized-variation-price-manager' ),
        );
        $job_data['updated_at'] = current_time( 'timestamp' );
        update_option( $job_id, $job_data, false );

        // Unschedule pending actions
        as_unschedule_all_actions( self::BATCH_HOOK, array( 'job_id' => $job_id ), 'cvpm' );

        return true;
    }

    /**
     * Clean up old completed jobs
     */
    public function cleanup_old_jobs() {
        global $wpdb;

        // Find all job options older than 1 hour
        $hour_ago = current_time( 'timestamp' ) - HOUR_IN_SECONDS;

        $job_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cvpm_job_%'"
        );

        foreach ( $job_options as $option_name ) {
            $job_data = get_option( $option_name );

            if ( $job_data && isset( $job_data['updated_at'] ) ) {
                // Delete completed/cancelled jobs older than 1 hour
                if ( in_array( $job_data['status'], array( 'completed', 'cancelled' ), true ) 
                    && $job_data['updated_at'] < $hour_ago ) {
                    delete_option( $option_name );
                }
            }
        }
    }

    /**
     * Get all variation IDs currently being processed in active jobs
     *
     * @return array Array of variation IDs being processed.
     */
    public function get_processing_variation_ids() {
        global $wpdb;

        $variation_ids = array();

        $job_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cvpm_job_%'"
        );

        foreach ( $job_options as $option_name ) {
            $job_data = get_option( $option_name );

            if ( $job_data && 'processing' === $job_data['status'] && isset( $job_data['variation_ids'] ) ) {
                $variation_ids = array_merge( $variation_ids, $job_data['variation_ids'] );
            }
        }

        return array_unique( $variation_ids );
    }

    /**
     * Get all active jobs
     *
     * @return array Array of active job data.
     */
    public function get_active_jobs() {
        global $wpdb;

        $jobs = array();

        $job_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cvpm_job_%'"
        );

        foreach ( $job_options as $option_name ) {
            $job_data = get_option( $option_name );

            if ( $job_data && 'processing' === $job_data['status'] ) {
                $status = $this->get_job_status( $option_name );
                if ( $status ) {
                    $status['variation_ids'] = isset( $job_data['variation_ids'] ) ? $job_data['variation_ids'] : array();
                    $jobs[] = array(
                        'job_id' => $option_name,
                        'data'   => $status,
                    );
                }
            }
        }

        return $jobs;
    }
}
