<?php
/*
Plugin Name: WooCommerce - WP E-Commerce -> WooCommerce Converter
Plugin URI: http://www.woothemes.com/woocommerce
Description: Convert products, product categories, and product variations from WP E-Commerce to WooCommerce.
Author: WooThemes
Author URI: http://woothemes.com/
Version: 1.1.7
Text Domain: woo_wpec
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '25fd8a5bcfc78fde45cdfaebfa8b6f14', '19002' );

/**
 * Check if WooCommerce is active
 **/
if ( ! is_woocommerce_active() )
	return;

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** Display verbose errors */
if ( ! defined( 'IMPORT_DEBUG' ) )
	define( 'IMPORT_DEBUG', false );

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * WordPress Importer class
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
				check_admin_referer('woo_wpec_converter');
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

		// show error message when WP E-Commerce plugin is active
		if ( class_exists( 'WP_eCommerce' ) )
		echo '<div class="error"><p>'.__('Please deactivate your WP E-Commerce plugin.', 'woo_wpec').'</p></div>';

		echo '<p>'.__('Analysing WP E-Commerce products&hellip;', 'woo_wpec').'</p>';

		echo '<ol>';

		$products = $wpdb->get_var("SELECT count(*) FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent = '0'");
		printf( '<li>'.__('<b>%d</b> products were identified', 'woo_wpec').'</li>', $products );

		$categories = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc_product_category'");
		printf( '<li>'.__('<b>%d</b> product categories were identified', 'woo_wpec').'</li>', $categories );

		$attributes = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc-variation' AND parent = '0'");
		printf( '<li>'.__('<b>%d</b> product attributes were identified', 'woo_wpec').'</li>', $attributes );

		$attribute_terms = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc-variation' AND parent != '0'");
		printf( '<li>'.__('<b>%d</b> product attribute terms were identified', 'woo_wpec').'</li>', $attribute_terms );

		$variations = $wpdb->get_var("SELECT count(*) FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent != '0'");
		printf( '<li>'.__('<b>%d</b> product variations were identified', 'woo_wpec').'</li>', $variations );

		echo '</ol>';

		if ( $products || $categories || $attributes || $attribute_terms || $variations ) {

			?>
			<form name="woo_wpec" id="woo_wpec" action="admin.php?import=woo_wpec&amp;step=1" method="post">
			<?php wp_nonce_field('woo_wpec_converter'); ?>
			<p class="submit"><input type="submit" name="submit" class="button" value="<?php _e('Convert Now', 'woo_wpec'); ?>" /></p>
			</form>
			<?php

			echo '<p>'.__('<b>Please backup your database first</b>. We are not responsible for any harm or wrong doing this plugin may cause. Users are fully responsible for their own use. This plugin is to be used WITHOUT warranty.', 'woo_wpec').'</p>';

		}

		echo '</div>';
	}

	// Convert
	function convert() {
		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_attributes();
		$this->process_products();
		$this->process_variations();
		wp_suspend_cache_invalidation( false );
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

			$converted = $wpdb->query( "UPDATE $wpdb->term_taxonomy SET taxonomy = 'product_cat' WHERE taxonomy = 'wpsc_product_category'" );

			if ( $converted > 0 ) {
				clean_term_cache($ids, 'product_cat', true);
				printf( '<p>'.__('<b>%d</b> product categories were converted', 'woo_wpec').'</p>', $converted );
			}

		}

	}

	// Convert product attributes
	function process_attributes() {
		global $wpdb, $woocommerce;

		$count = $wpdb->get_var("SELECT count(*) FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc-variation' AND parent = '0'");

		if ( $count ) {

			$q = "
				SELECT t.term_id,t.name
				FROM $wpdb->term_taxonomy AS tt, $wpdb->terms AS t
				WHERE
					tt.taxonomy = 'wpsc-variation'
					AND tt.parent = '0'
					AND tt.term_id = t.term_id
				";
			$attributes = $wpdb->get_results($q);

			if ( !empty( $attributes ) ) {

				$ids = array();

				foreach ( $attributes as $attribute ) {

					$old_id = $attribute->term_id;

					$attribute_name = woocommerce_sanitize_taxonomy_name($attribute->name);
					$attribute_type = 'select';
					$attribute_label = $attribute->name;

					if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
						$attribute_taxonomy_name = wc_attribute_taxonomy_name( $attribute_name );
					} else {
						$attribute_taxonomy_name = $woocommerce->attribute_taxonomy_name( $attribute_name );
					}

					if ( $attribute_name && $attribute_type && !taxonomy_exists( $attribute_taxonomy_name ) ) {

						$wpdb->insert( $wpdb->prefix . "woocommerce_attribute_taxonomies", array( 'attribute_name' => $attribute_name, 'attribute_label' => $attribute_label, 'attribute_type' => $attribute_type ), array( '%s', '%s' ) );

						printf( '<p>'.__('<b>%s</b> product attributes was converted', 'woo_wpec').'</p>', $attribute_name );

					}
					else {

						printf( '<p>'.__('<b>%s</b> product attributes does exist', 'woo_wpec').'</p>', $attribute_name );

					}

					$attribute_terms = $wpdb->get_col("SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'wpsc-variation' AND parent = '$old_id'");

					if ( !empty( $attribute_terms ) ) {

						$attribute_terms = implode(', ', (array)$attribute_terms );
						$wpdb->query( "UPDATE $wpdb->term_taxonomy SET taxonomy = '$attribute_taxonomy_name', parent = '0' WHERE term_id IN ($attribute_terms)" );

						clean_term_cache($attribute_terms, $attribute_taxonomy_name, true);

					}

					$wpdb->query( "DELETE FROM $wpdb->term_taxonomy WHERE term_id = '$old_id'" );
					$wpdb->query( "DELETE FROM $wpdb->terms WHERE term_id = '$old_id'" );

				}

			}

		}

	}

	// Convert products
	function process_products() {

		$this->results = 0;

		$timeout = 600;
		if( !ini_get( 'safe_mode' ) )
			set_time_limit( $timeout );

		global $wpdb;

		// weight unit in WPEC = pound, ounce, gram, kilogram
		// weight unit in WC = lbs, kg
		$weight_unit = get_option( 'woocommerce_weight_unit' );
		if ( $weight_unit ) $weight_unit = 'kg';

		// dimension unit in WPEC = in, cm, meter
		// weight unit in WC = in, cm
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );
		if ( $dimension_unit ) $dimension_unit = 'cm';

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
					update_post_meta( $id, '_regular_price', $meta['_wpsc_price'][0] );
					delete_post_meta( $id, '_wpsc_price' );
				}

				// sale_price
				if ( isset($meta['_wpsc_special_price'][0]) && $meta['_wpsc_special_price'][0]>0 ) {
					update_post_meta( $id, '_sale_price', $meta['_wpsc_special_price'][0] );
					delete_post_meta( $id, '_wpsc_special_price' );
				}

				// price (regular_price/sale_price)
				if ( isset($meta['_wpsc_special_price'][0]) && $meta['_wpsc_special_price'][0]>0 ) {
					update_post_meta( $id, '_price', $meta['_wpsc_special_price'][0] );
				}
				else {
					update_post_meta( $id, '_price', $meta['_wpsc_price'][0] );
				}

				// sale_price_dates_from
				update_post_meta( $id, '_sale_price_dates_from', '' );

				// sale_price_dates_to
				update_post_meta( $id, '_sale_price_dates_to', '' );

				// visibility: visible
				update_post_meta( $id, '_visibility', 'visible' );

				// featured: yes / no
				$featured_ids = get_option( 'sticky_products' );
				if ( !is_array($featured_ids) )
					$featured_ids = array($featured_ids);
				$featured = ( in_array( $id, $featured_ids ) ) ? 'yes' : 'no';
				update_post_meta( $id, '_featured', $featured );

				// sku:
				if ( isset($meta['_wpsc_sku'][0]) ) {
					update_post_meta( $id, '_sku', $meta['_wpsc_sku'][0] );
					delete_post_meta( $id, '_wpsc_sku' );
				}

				// stock_status : instock / outofstock
				// manage_stock : yes / no
				// stock : external = 0
				// backorders : no
				if ( isset($meta['_wpsc_stock'][0]) ) {
					if ( $meta['_wpsc_stock'][0] === '' ) {
						update_post_meta( $id, '_stock_status', 'instock' );
						update_post_meta( $id, '_manage_stock', 'no' );
						update_post_meta( $id, '_stock', '0' );
						update_post_meta( $id, '_backorders', 'no' );
					}
					elseif ( $meta['_wpsc_stock'][0] === '0' ) {
						update_post_meta( $id, '_stock_status', 'outofstock' );
						update_post_meta( $id, '_manage_stock', 'yes' );
						update_post_meta( $id, '_stock', '0' );
						update_post_meta( $id, '_backorders', 'no' );
					}
					elseif ( $meta['_wpsc_stock'][0] > 0 ) {
						update_post_meta( $id, '_stock_status', 'instock' );
						update_post_meta( $id, '_manage_stock', 'yes' );
						update_post_meta( $id, '_stock', $meta['_wpsc_stock'][0] );
						update_post_meta( $id, '_backorders', 'no' );
					}
				}

				// virtual (yes/no)
				update_post_meta( $id, '_virtual', 'no' );

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
					update_post_meta( $id, '_downloadable', 'yes' );
					update_post_meta( $id, '_file_path', $downloads[0]->guid );
				}

				// weight
				if ( isset($meta_data['weight']) && $meta_data['weight'] ) {
					$old_weight = $meta_data['weight'];
					$old_weight_unit = $meta_data['weight_unit'];
					$new_weight = $this->convert_weight($old_weight, $old_weight_unit, $weight_unit);
					update_post_meta( $id, '_weight', $new_weight );
				}

				// length
				if ( isset($meta_data['dimensions']['length']) && $meta_data['dimensions']['length'] ) {
					$old_length = $meta_data['dimensions']['length'];
					$old_length_unit = $meta_data['dimensions']['length_unit'];
					$new_length = $this->convert_dimension($old_length, $old_length_unit, $dimension_unit);
					update_post_meta( $id, '_length', $new_length );
				}

				// width
				if ( isset($meta_data['dimensions']['width']) && $meta_data['dimensions']['width'] ) {
					$old_width = $meta_data['dimensions']['width'];
					$old_width_unit = $meta_data['dimensions']['width_unit'];
					$new_width = $this->convert_dimension($old_width, $old_width_unit, $dimension_unit);
					update_post_meta( $id, '_width', $new_width );
				}

				// height
				if ( isset($meta_data['dimensions']['height']) && $meta_data['dimensions']['height'] ) {
					$old_height = $meta_data['dimensions']['height'];
					$old_height_unit = $meta_data['dimensions']['height_unit'];
					$new_height = $this->convert_dimension($old_height, $old_height_unit, $dimension_unit);
					update_post_meta( $id, '_height', $new_height );
				}

				// tax_status
				update_post_meta( $id, '_tax_status', 'taxable' );

				// tax_class
				update_post_meta( $id, '_tax_class', '' );

				// per_product_shipping
				if ( $product_type == 'simple' || $product_type == 'variable' ) {
					if ( isset($meta_data['shipping']['local']) && $meta_data['shipping']['local'] ) {
						update_post_meta( $id, 'per_product_shipping', $meta_data['shipping']['local'] );
					}
				}
				
				// auto-generate thumbnail if one doesn't exist
				if ( ! has_post_thumbnail( $id ) ) {
					printf( '<p>'.__('Featured image does not exist. Auto-generating thumbnail for product.', 'woo_wpec').'</p>', $title );
					self::generate_thumbmail($id);
				}

				// convert post type
				if ( set_post_type( $id, 'product' ) ) {
					$this->results++;
					printf( '<p>'.__('<b>%s</b> product was converted', 'woo_wpec').'</p>', $title );
				}

			}

		}

	}
	
	// Generate Thumbnails for Product
    static function generate_thumbmail( $post_id ) {
		
		global $wpdb;

		$post = get_post($dummy_wp = $post_id);

		// reset post parent id
		$post_parent_id = $post->post_parent === 0 ? $post->ID : $post->post_parent;

		// check whether Post Thumbnail is already set for this post.
		if ( has_post_thumbnail($post_parent_id) ) return "has thumbnail";

		// case 1: there is an image attachment we can use
		// found all images attachments from the post
		$attachments = array_values(get_children(array(
			'post_parent' => $post_parent_id, 
			'post_status' => 'inherit', 
			'post_type' => 'attachment', 
			'post_mime_type' => 'image', 
			'order' => 'ASC', 
			'orderby' => 'menu_order ID') 
		));

		// if attachment found, set the first attachment as thumbnail
		if( sizeof($attachments) > 0 ) {
			update_post_meta( $post_parent_id, '_thumbnail_id', $attachments[0]->ID );
		return;
		}

		// case 2: need to search for an image from content
		// find image from content
		// check is there any image we can use
		$image_url = self::found_image_url($post->post_content);

		// if no url found, do nothing
		if( $image_url == null ) return;

		// try to create an image attchment from given image url, and use it as thumbnail
		$post_thumbnail_id = self::create_post_attachment_from_url($image_url);

		// update post thumbnail meta if thumbnail found
		if(is_int($post_thumbnail_id)) {
			update_post_meta( $post_parent_id, '_thumbnail_id', $post_thumbnail_id );
		}

		return;
	}
	
    /**
     * @return Integer if attachment id if attachment is used. 
     * @return String if image url if external image is used.
     * @return NULL if fail
     */
    static function found_image_url($html)
    {
        $matches = array();
        
        // images
        $pattern = '/<img[^>]*src=\"?(?<src>[^\"]*)\"?[^>]*>/im';
        preg_match( $pattern, $html, $matches ); 
        if($matches['src']) {
            return $matches['src'];
        }
        
        // youtube
        $pattern = "/(http:\/\/www.youtube.com\/watch\?.*v=|http:\/\/www.youtube-nocookie.com\/.*v\/|http:\/\/www.youtube.com\/embed\/|http:\/\/www.youtube.com\/v\/)(?<id>[\w-_]+)/i";
        preg_match( $pattern, $html, $matches ); 
        if( $matches['id'] ) {
            return "http://img.youtube.com/vi/{$matches['id']}/0.jpg";
        }
        
        // vimeo
        $pattern = "/(http:\/\/vimeo.com\/|http:\/\/player.vimeo.com\/video\/|http:\/\/vimeo.com\/moogaloop.swf?.*clip_id=)(?<id>[\d]+)/i";
        preg_match( $pattern, $html, $matches ); 
        if( $vimeo_id = $matches['id'] ) {
            $hash = unserialize(file_get_contents("http://vimeo.com/api/v2/video/{$vimeo_id}.php"));
            return "{$hash[0]['thumbnail_medium']}";
        }
        
        // dailymotion
        // http://www.dailymotion.com/thumbnail/150x150/video/xexakq
        $pattern = "/(http:\/\/www.dailymotion.com\/swf\/video\/)(?<id>[\w\d]+)/i";
        preg_match( $pattern, $html, $matches ); 
        if( $matches['id'] ) {
            return "http://www.dailymotion.com/thumbnail/150x150/video/{$matches['id']}.jpg";
        }
        
        return null;
    }
    
    /**
     * Function to fetch the image from URL and generate the required thumbnails
     * @return Attachment ID
     */
    static function create_post_attachment_from_url($imageUrl = null)
    {
        if(is_null($imageUrl)) return null;
        
        // get file name
        $filename = substr($imageUrl, (strrpos($imageUrl, '/'))+1);
        if (!(($uploads = wp_upload_dir(current_time('mysql')) ) && false === $uploads['error'])) {
            return null;
        }
    
        // Generate unique file name
        $filename = wp_unique_filename( $uploads['path'], $filename );
    
        // move the file to the uploads dir
        $new_file = $uploads['path'] . "/$filename";
        
        // download file
        if (!ini_get('allow_url_fopen')) {
            $file_data = self::curl_get_file_contents($imageUrl);
        } else {
            $file_data = @file_get_contents($imageUrl);
        }
        
        // fail to download image.
        if (!$file_data) {
            return null;
        }
        
        file_put_contents($new_file, $file_data);
        
        // Set correct file permissions
        $stat = stat( dirname( $new_file ));
        $perms = $stat['mode'] & 0000666;
        @chmod( $new_file, $perms );
        
        // get the file type. Must to use it as a post thumbnail.
        $wp_filetype = wp_check_filetype( $filename, $mimes );
        
        extract( $wp_filetype );
        
        // no file type! No point to proceed further
        if ( ( !$type || !$ext ) && !current_user_can( 'unfiltered_upload' ) ) {
            return null;
        }
        
        // construct the attachment array
        $attachment = array(
            'post_mime_type' => $type,
            'guid' => $uploads['url'] . "/$filename",
            'post_parent' => null,
            'post_title' => '',
            'post_content' => '',
        );
    
        // insert attachment
        $thumb_id = wp_insert_attachment($attachment, $file, $post_id);
        
        // error!
        if ( is_wp_error($thumb_id) ) {
            return null;
        }
        
        require_once(ABSPATH . '/wp-admin/includes/image.php');
        wp_update_attachment_metadata( $thumb_id, wp_generate_attachment_metadata( $thumb_id, $new_file ) );
        
        return $thumb_id;
    }
    
    /**
     * Function to fetch the contents of URL using curl in absense of allow_url_fopen.
     * 
     * Copied from user comment on php.net (http://in.php.net/manual/en/function.file-get-contents.php#82255)
     */
    static function curl_get_file_contents($URL) {
        $c = curl_init();
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_URL, $URL);
        $contents = curl_exec($c);
        curl_close($c);
    
        if ($contents) {
            return $contents;
        }
        
        return FALSE;
    }

	// Convert product variations
	function process_variations() {

		$this->results = 0;

		$timeout = 600;
		if( !ini_get( 'safe_mode' ) )
			set_time_limit( $timeout );

		global $wpdb, $woocommerce;

		// weight unit in WPEC = pound, ounce, gram, kilogram
		// weight unit in WC = lbs, kg
		$weight_unit = get_option( 'woocommerce_weight_unit' );
		if ( $weight_unit ) $weight_unit = 'kg';

		// dimension unit in WPEC = in, cm, meter
		// weight unit in WC = in, cm
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );
		if ( $dimension_unit ) $dimension_unit = 'cm';

		$count = $wpdb->get_var("SELECT count(*) FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent != '0'");

		if ( $count ) {

			$variations = $wpdb->get_results("SELECT ID,post_parent FROM $wpdb->posts WHERE post_type = 'wpsc-product' AND post_parent != '0'");

			foreach ( $variations as $variation ) {

				$parent_id = $variation->post_parent;

				$id = $variation->ID;

				$meta = get_post_custom($variation->ID);
				$meta_data = unserialize($meta['_wpsc_product_metadata'][0]);

				// update attributes

				// price
				if ( isset($meta['_wpsc_price'][0]) ) {
					update_post_meta( $id, '_price', $meta['_wpsc_price'][0] );
					delete_post_meta( $id, '_wpsc_price' );
				}

				// sale_price
				if ( isset($meta['_wpsc_special_price'][0]) && $meta['_wpsc_special_price'][0]>0 ) {
					update_post_meta( $id, '_sale_price', $meta['_wpsc_special_price'][0] );
					delete_post_meta( $id, '_wpsc_special_price' );
				}

				// weight
				if ( isset($meta_data['weight']) && $meta_data['weight'] ) {
					$old_weight = $meta_data['weight'];
					$old_weight_unit = $meta_data['weight_unit'];
					$new_weight = $this->convert_weight($old_weight, $old_weight_unit, $weight_unit);
					update_post_meta( $id, '_weight', $new_weight );
				}

				// stock
				if ( isset($meta['_wpsc_stock'][0]) ) {
					update_post_meta( $id, '_stock', $meta['_wpsc_stock'][0] );
					delete_post_meta( $id, '_wpsc_stock' );
				}

				// sku
				if ( isset($meta['_wpsc_sku'][0]) ) {
					update_post_meta( $id, '_sku', $meta['_wpsc_sku'][0] );
					delete_post_meta( $id, '_wpsc_sku' );
				}

				// virtual
				update_post_meta( $id, '_virtual', 'no' );

				// downloadable
				update_post_meta( $id, '_downloadable', 'no' );

				// download_limit
				update_post_meta( $id, '_download_limit', '' );

				// file_path
				update_post_meta( $id, '_file_path', '' );

				// get product attributes in array
				$attribute_taxonomies = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."woocommerce_attribute_taxonomies;");
				$attributes_name = array();
				if ( $attribute_taxonomies ) {
					foreach ($attribute_taxonomies as $tax) {
						if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
							$attributes_name[] = wc_attribute_taxonomy_name( $tax->attribute_name );
						} else {
							$attributes_name[] = $woocommerce->attribute_taxonomy_name( $tax->attribute_name );
						}
					}
				}

				// get product atribute terms from this variations
				$attributes_name_string = "'" . implode("', '", $attributes_name) . "'";
				$q = "SELECT t.term_id,t.name,t.slug,tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ($attributes_name_string) AND tr.object_id IN ($id)";
				$terms = $wpdb->get_results($q);

				// update attribute_ meta
				foreach ($terms as $term) :
					update_post_meta( $id, 'attribute_' . sanitize_title($term->taxonomy), $term->slug );
				endforeach;

				// Generate a useful post title for product variation
				$title = array();

				foreach ($terms as $term) {
					if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
						$title[] = wc_attribute_taxonomy_name( $term->taxonomy ) .': '.$term->name;
					} else {
						$title[] = $woocommerce->attribute_taxonomy_name( $term->taxonomy ) .': '.$term->name;
					}
				}

				$sku_string = '#'.$id;
				if ( isset($meta['_wpsc_sku'][0]) && $meta['_wpsc_sku'][0] )
					$sku_string .= ' SKU: ' . $meta['_wpsc_sku'][0];

				$title = '#' . $parent_id . ' Variation ('.$sku_string.') - ' . implode(', ', $title);

				// get parent product type.
				$parent_type_terms = wp_get_object_terms( $parent_id, 'product_type' );
				if (!is_wp_error($parent_type_terms) && $parent_type_terms) {
					$parent_type_term = current($parent_type_terms);
					$product_type = $parent_type_term->slug;
				}
				else {
					$product_type = 'simple';
				}

				// parent product still use simple product type. let's fix it
				if ( $product_type != 'variable' ) {

					$q = "SELECT t.term_id,t.name,tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id INNER JOIN $wpdb->term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ($attributes_name_string) AND tr.object_id IN ($parent_id)";

					$parent_terms = $wpdb->get_results($q);

					// get product attributes terms
					$parent_attributes = array();
					foreach( $parent_terms as $parent_term ) {
						$parent_attributes[$parent_term->taxonomy][] = $parent_term->name;
					}

					// create product atributes meta
					$new_attributes = array();
					$i=0;
					foreach ( $parent_attributes as $key => $parent_attribute ) {
						$i++;
						$new_attributes[$key]['name'] = $key;
						$new_attributes[$key]['value'] = '';
						$new_attributes[$key]['position'] = $i;
						$new_attributes[$key]['is_visible'] = 1;
						$new_attributes[$key]['is_variation'] = 1;
						$new_attributes[$key]['is_taxonomy'] = 1;
						$values = explode(",", $attribute['value']);
					}
					wp_set_object_terms( $parent_id, 'variable', 'product_type');
					update_post_meta( $parent_id, '_product_attributes', $new_attributes );
				}

				// convert post type and mark it converted
				$converted = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_type = 'product_variation', post_title = '%s', post_status = 'publish' WHERE ID = %d", $title, $id ) );
				if ( !is_wp_error($converted) ) {
					$this->results++;
					printf( '<p>'.__('<b>%s</b> product variation was converted', 'woo_wpec').'</p>', $title );
				}

			}

		}

	}

	// Based on wpsc_convert_weight function in WP E-Commerce plugin.
	// Credits: GetShopped.org
	function convert_weight($in_weight, $in_unit, $out_unit = 'kg', $raw = false) {
		switch($in_unit) {
			case "kilogram":
			$intermediate_weight = $in_weight * 1000;
			break;

			case "gram":
			$intermediate_weight = $in_weight;
			break;

			case "once":
			case "ounce":
			$intermediate_weight = ($in_weight / 16) * 453.59237;
			break;

			case "pound":
			default:
			$intermediate_weight = $in_weight * 453.59237;
			break;
		}
		switch($out_unit) {
			case "kg":
			$weight = $intermediate_weight / 1000;
			break;

			case "lbs":
			default:
			$weight = $intermediate_weight / 453.59237;
			break;
		}
		if($raw)
			return $weight;
		return round($weight, 2);
	}

	function convert_dimension($in_dimension, $in_unit, $out_unit = 'cm', $raw = false) {
		switch($in_unit) {
			case "in":
			$intermediate_dimension = $in_dimension / 0.393700787402;
			break;

			case "meter":
			$intermediate_dimension = $in_dimension * 100;
			break;

			case "cm":
			default:
			$intermediate_dimension = $in_dimension;
			break;
		}
		switch($out_unit) {
			case "in":
			$dimension = $in_dimension * 0.393700787402;
			break;

			case "cm":
			default:
			$dimension = $in_dimension;
			break;
		}
		if($raw)
			return $dimension;
		return round($dimension, 2);
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
	register_importer( 'woo_wpec', 'WP E-Commerce To WooCommerce Converter', __('Convert products, product categories, and product variations from WP E-Commerce to WooCommerce.', 'woo_wpec'), array( $GLOBALS['woo_wpec'], 'dispatch' ) );

}
add_action( 'admin_init', 'woo_wpec_importer_init' );

