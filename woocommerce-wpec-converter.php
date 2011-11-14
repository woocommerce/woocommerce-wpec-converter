<?php
/*
Plugin Name: WooCommerce - WP E-Commerce Converter
Plugin URI: http://www.woothemes.com/
Description: Convert products, product categories, and more from WP E-Commerce to WooCommerce.
Author: Agus MU
Author URI: http://agusmu.com/
Version: 1.0
Text Domain: woo_wpec
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

/**
 * Check if WooCommerce is active
 **/
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
	return;
	
if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** Display verbose errors */
define( 'IMPORT_DEBUG', false );

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * WordPress Importer class for managing the import process of a WXR file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class Woo_WPEC_Converter extends WP_Importer {

	var $results;

	function Woo_WPEC_Converter() { /* nothing */ }

	/**
	 * Registered callback function for the WooCommerce - WP E-Commerce Converter
	 *
	 */
	function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
				$this->analyze();
				break;
			case 1:
				$this->convert();
				break;
		}

		$this->footer();
	}

	// Display import page title
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'WP E-Commerce To WooCommerce Converter', 'woo_wpec' ) . '</h2>';

		$updates = get_plugin_updates();
		$basename = plugin_basename(__FILE__);
		if ( isset( $updates[$basename] ) ) {
			$update = $updates[$basename];
			echo '<div class="error"><p><strong>';
			printf( __( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'woo_wpec' ), $update->update->new_version );
			echo '</strong></p></div>';
		}
	}

	// Close div.wrap
	function footer() {
		echo '</div>';
	}

	// Analyze
	function analyze() {
		global $wpdb;
		echo '<div class="narrow">';

		echo '<p>'.__('Analyzing WP E-Commerce products&hellip;', 'woo_wpec').'</p>';
		
		echo '<ol>';

		$products = $wpdb->get_var("SELECT count(*) FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent = '0'");
		printf( '<li>'.__('<b>%d</b> products were identified', 'woo_wpec').'</li>', $products );

		$categories = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc_product_category'");
		printf( '<li>'.__('<b>%d</b> product categories were identified', 'woo_wpec').'</li>', $categories );

		echo '</ol>';
		
		if ( $products || $categories ) {
		
			echo '<br/><p><a class="button" href="'.admin_url( 'admin.php?import=woo_wpec&amp;step=1' ).'">'.__('Convert Now', 'woo_wpec').'</a></p>';

			echo '<br/><p>'.__('<b>Please backup your database first</b>. We are not responsible for any harm or wrong doing this plugin may cause. Users are fully responsible for their own use. This plugin is to be used WITHOUT warranty.', 'woo_wpec').'</p>';
		
		}

		echo '</div>';
	}

	// Convert
	function convert() {
		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_products();
		wp_suspend_cache_invalidation( false );
	}

	// Convert products
	function process_products() {

		$this->results = 0;

		$timeout = 600;
		if( !ini_get( 'safe_mode' ) )
			set_time_limit( $timeout );

		global $wpdb;

		$weight_unit = get_option( 'woocommerce_weight_unit' );
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );
		
		$count = $wpdb->get_var("SELECT count(*) FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent = '0'");

		if ( $count ) {
			
			$products = $wpdb->get_results("SELECT ID,post_title FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent = '0'");
			
			foreach ( $products as $product ) {
			
				$id = $product->ID;
				$title = $product->post_title;
				
				$meta = get_post_custom($product->ID);
				$meta_data = unserialize($meta['_wpsc_product_metadata'][0]);
				
				// product_url (external)
				if (!empty($meta_data['external_link'])) {
					update_post_meta( $id, 'product_url', $meta_data['external_link'] );
					$product_type = 'external';
				}
				else {
					$product_type = 'simple';
				}
					
				// product_type : simple/grouped/external/variable
				wp_set_object_terms($id, $product_type, 'product_type');
				
				// regular_price
				if ( isset($meta['_wpsc_price'][0]) ) {
					update_post_meta( $id, 'regular_price', $meta['_wpsc_price'][0] );	
					delete_post_meta( $id, '_wpsc_price' );	
				}
				
				// sale_price
				if ( isset($meta['_wpsc_special_price'][0]) && $meta['_wpsc_special_price'][0]>0 ) {
					update_post_meta( $id, 'sale_price', $meta['_wpsc_special_price'][0] );	
					delete_post_meta( $id, '_wpsc_special_price' );	
				}
				
				// price (regular_price/sale_price)
				if ( isset($meta['_wpsc_special_price'][0]) && $meta['_wpsc_special_price'][0]>0 ) {
					update_post_meta( $id, 'price', $meta['_wpsc_special_price'][0] );	
				}
				else {
					update_post_meta( $id, 'price', $meta['_wpsc_price'][0] );	
				}
				
				// sale_price_dates_from
				update_post_meta( $id, 'sale_price_dates_from', '' );	
				
				// sale_price_dates_to
				update_post_meta( $id, 'sale_price_dates_to', '' );	
				
				// visibility: visible
				update_post_meta( $id, 'visibility', 'visible' );
				
				// featured: yes / no
				$featured_ids = get_option( 'sticky_products' );
				if ( !is_array($featured_ids) )
					$featured_ids = array($featured_ids);
				$featured = ( in_array( $id, $featured_ids ) ) ? 'yes' : 'no';
				update_post_meta( $id, 'featured', $featured );	
				
				// sku: 
				if ( isset($meta['_wpsc_sku'][0]) ) {
					update_post_meta( $id, 'sku', $meta['_wpsc_sku'][0] );	
					delete_post_meta( $id, '_wpsc_sku' );	
				}
				
				// stock_status : instock / outofstock
				// manage_stock : yes / no
				// stock : external = 0
				// backorders : no
				if ( isset($meta['_wpsc_stock'][0]) ) {
					if ( $meta['_wpsc_stock'][0] === '' ) {
						update_post_meta( $id, 'stock_status', 'instock' );	
						update_post_meta( $id, 'manage_stock', 'no' );
						update_post_meta( $id, 'stock', '0' );
						update_post_meta( $id, 'backorders', 'no' );
					}
					elseif ( $meta['_wpsc_stock'][0] === '0' ) {
						update_post_meta( $id, 'stock_status', 'outofstock' );	
						update_post_meta( $id, 'manage_stock', 'yes' );
						update_post_meta( $id, 'stock', '0' );
						update_post_meta( $id, 'backorders', 'no' );
					}
					elseif ( $meta['_wpsc_stock'][0] > 0 ) {
						update_post_meta( $id, 'stock_status', 'instock' );	
						update_post_meta( $id, 'manage_stock', 'yes' );
						update_post_meta( $id, 'stock', $meta['_wpsc_stock'][0] );
						update_post_meta( $id, 'backorders', 'no' );
					}
				}

				// virtual (yes/no)
				update_post_meta( $id, 'virtual', 'no' );	
				
				// downloadable (yes/no)
				// file_path (downloadable)
				$args = array(
					'post_parent' => $id,
					'post_type' => 'wpsc-product-file',
					'post_status' => 'any',
					'numberposts' => 1
				);
				$downloads = (array)get_posts($args);
				if ( count( $downloads ) ) {
					update_post_meta( $id, 'downloadable', 'yes' );	
					update_post_meta( $id, 'file_path', $downloads[0]->guid );
				}
				
				// weight
				if ( isset($meta_data['weight']) && $meta_data['weight'] ) {
					$weight = $meta_data['weight'];
					// standart weight unit in WPEC is lbs
					if ( $weight_unit == 'kg' )
						$weight = round( $weight * 0.45359237, 2 );
					update_post_meta( $id, 'weight', $weight );
				}

				// length
				if ( isset($meta_data['dimensions']['length']) && $meta_data['dimensions']['length'] ) {
					$length = $meta_data['dimensions']['length'];
					// standart dimension unit in WPEC is inch
					if ( $dimension_unit == 'cm' )
						$length = round( $length / 0.393700787402, 2 );
					update_post_meta( $id, 'length', $length );
				}
				
				// width
				if ( isset($meta_data['dimensions']['width']) && $meta_data['dimensions']['width'] ) {
					$width = $meta_data['dimensions']['width'];
					// standart dimension unit in WPEC is inch
					if ( $dimension_unit == 'cm' )
						$width = round( $width / 0.393700787402, 2 );
					update_post_meta( $id, 'width', $width );
				}

				// height
				if ( isset($meta_data['dimensions']['height']) && $meta_data['dimensions']['height'] ) {
					$height = $meta_data['dimensions']['height'];
					// standart dimension unit in WPEC is inch
					if ( $dimension_unit == 'cm' )
						$height = round( $height / 0.393700787402, 2 );
					update_post_meta( $id, 'height', $height );
				}

				// tax_status
				update_post_meta( $id, 'tax_status', 'taxable' );	
				
				// tax_class
				update_post_meta( $id, 'tax_class', '' );	

				// per_product_shipping
				if ( $product_type == 'simple' || $product_type == 'variable' ) {
					if ( isset($meta_data['shipping']['local']) && $meta_data['shipping']['local'] ) {
						update_post_meta( $id, 'per_product_shipping', $meta_data['shipping']['local'] );
					}
				}

				// convert post type
				$converted = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_type = 'product', comment_status = 'open' WHERE ID = '{$id}'" ) );
				if ( !is_wp_error($converted) ) {
					$this->results++;
					printf( '<p>'.__('<b>%s</b> product were converted', 'woo_wpec').'</p>', $title );
				}
				
			}
			
		}
		
	}

	// Convert product categories
	function process_categories() {
		global $wpdb;

		$count = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc_product_category'");

		if ( $count ) {
			
			$terms = $wpdb->get_results("SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc_product_category'");
			
			$ids = array();
			foreach ( $terms as $term ) {
				$ids[] = $term->term_id;
			}
			
			$converted = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->term_taxonomy SET taxonomy = 'product_cat' WHERE taxonomy = 'wpsc_product_category'" ) );

			if ( $converted > 0 ) {
				clean_term_cache($ids, 'product_cat', true);
				printf( '<p>'.__('<b>%d</b> product categories were converted', 'woo_wpec').'</p>', $converted );
			}
			
		}
		
	}

}

} // class_exists( 'WP_Importer' )

function woo_wpec_importer_init() {
	load_plugin_textdomain( 'woo_wpec', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * Woo_WPEC_Converter object for registering the import callback
	 * @global Woo_WPEC_Converter $woo_wpec
	 */
	$GLOBALS['woo_wpec'] = new Woo_WPEC_Converter();
	register_importer( 'woo_wpec', 'WP E-Commerce To WooCommerce Converter', __('Convert products, product categories, and more from WP E-Commerce to WooCommerce.', 'woo_wpec'), array( $GLOBALS['woo_wpec'], 'dispatch' ) );
	
}
add_action( 'admin_init', 'woo_wpec_importer_init' );

