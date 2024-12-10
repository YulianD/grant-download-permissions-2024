<?php
/**
 * Plugin Name: Grant Download Permissions for Past WooCommerce Orders
 * Plugin URI:  https://github.com/woocommerce/grant-download-permissions-for-past-woocommerce-orders
 * Description: Grants download permissions for new files added to existing downloadable products.
 * Author:      [Tu Nombre]
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License:     GPLv2 or later
 *
 * @package Grant_Download_Permissions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class WooCommerce_Legacy_Grant_Download_Permissions {
    /**
     * Instance of this class.
     *
     * @var self
     */
    protected static $instance = null;

    /**
     * Initialize the plugin actions.
     */
    private function __construct() {
        // Stop if WooCommerce isn't activated.
        if ( ! class_exists( 'WooCommerce', false ) ) {
            return;
        }

        // Remove modern download permission action
        remove_action( 'woocommerce_process_product_file_download_paths', array( 'WC_Admin_Post_Types', 'process_product_file_download_paths' ), 10 );

        // Add custom download permission method
        add_action( 'woocommerce_process_product_file_download_paths', array( $this, 'grant_download_permissions' ), 10, 3 );
    }

    /**
     * Return an instance of this class.
     *
     * @return self
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Grant download permissions for existing orders.
     *
     * @param int    $product_id          Product identifier.
     * @param int    $variation_id        Optional product variation identifier.
     * @param array  $downloadable_files  Newly set files.
     */
    public function grant_download_permissions( $product_id, $variation_id, $downloadable_files ) {
        global $wpdb;

        // Use variation ID if present
        $target_product_id = $variation_id ?: $product_id;

        // Validate product
        $product = wc_get_product( $target_product_id );
        if ( ! $product ) {
            return;
        }

        // Compare existing and updated download IDs
        $existing_download_ids = array_keys( (array) $product->get_downloads() );
        $updated_download_ids  = array_keys( (array) $downloadable_files );
        $new_download_ids      = array_filter( array_diff( $updated_download_ids, $existing_download_ids ) );
        $removed_download_ids  = array_filter( array_diff( $existing_download_ids, $updated_download_ids ) );

        // Process changes if downloads modified
        if ( ! empty( $new_download_ids ) || ! empty( $removed_download_ids ) ) {
            // Get existing orders with this product
            $existing_orders = $wpdb->get_col( 
                $wpdb->prepare( 
                    "SELECT order_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE product_id = %d GROUP BY order_id", 
                    $target_product_id 
                ) 
            );

            foreach ( $existing_orders as $existing_order_id ) {
                $order = wc_get_order( $existing_order_id );

                if ( ! $order ) {
                    continue;
                }

                // Remove permissions for deleted downloads
                if ( ! empty( $removed_download_ids ) ) {
                    foreach ( $removed_download_ids as $download_id ) {
                        if ( apply_filters( 'woocommerce_process_product_file_download_paths_remove_access_to_old_file', true, $download_id, $target_product_id, $order ) ) {
                            $wpdb->query( 
                                $wpdb->prepare( 
                                    "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s", 
                                    $order->get_id(), 
                                    $target_product_id, 
                                    $download_id 
                                ) 
                            );
                        }
                    }
                }

                // Add permissions for new downloads
                if ( ! empty( $new_download_ids ) ) {
                    foreach ( $new_download_ids as $download_id ) {
                        if ( apply_filters( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', true, $download_id, $target_product_id, $order ) ) {
                            // Grant permission if it doesn't already exist
                            $existing_permission = $wpdb->get_var( 
                                $wpdb->prepare( 
                                    "SELECT 1 FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d AND product_id = %d AND download_id = %s", 
                                    $order->get_id(), 
                                    $target_product_id, 
                                    $download_id 
                                ) 
                            );

                            if ( ! $existing_permission ) {
                                wc_downloadable_file_permission( $download_id, $target_product_id, $order );
                            }
                        }
                    }
                }
            }
        }
    }
}

add_action( 'admin_init', array( 'WooCommerce_Legacy_Grant_Download_Permissions', 'get_instance' ) );
