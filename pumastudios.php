<?php
/*
Plugin Name: Puma Studios
Plugin URI: https://github.com/KnBrckr/pumastudios
Description: Site Specific Tweaks and Shortcodes
Version: 0.5
Author: Kenneth J. Brucker
Author URI: http://action-a-day.com
Domain Path: /languages
Text Domain: pumastudios

Copyright: 2017 Kenneth J. Brucker (email: ken.brucker@action-a-day.com)

This file is part of pumastudios site modifications, a plugin for Wordpress.

*/

// Protect from direct execution
if (!defined('WP_PLUGIN_DIR')) {
	header('Status: 403 Forbidden');
  header('HTTP/1.1 403 Forbidden');
  exit();
}

global $pumastudios;

// ===============================
// = Define the shortcodes class =
// ===============================
if ( ! class_exists('pumastudios')) {
	class pumastudios {

		/**
		 * Setup Plugin in WP context - must be called after instantiating object
		 *
		 * @return void
		 */
		function setup()
		{
			// Run the init during WP init processing
			add_action('init', array($this, 'wp_init'));
		}

		/**
		 * Run during WordPress wp_init
		 *
		 * @return void
		 */
		function wp_init()
		{
			/**
			 * Define Short Codes
			 */
			add_shortcode( "page-children", array($this, "sc_page_children") );

			/**
			 * Take care of woocommerce customizations
			 */
			if ( class_exists( 'WooCommerce' ) ) {
				add_action( 'wp_loaded', array($this, 'woocommerce_customize') );
			}

			/**
			 * Filter admin_url scheme when SSL is not being used.
			 *
			 * Only required if FORCE_SSL_ADMIN is enabled
			 */
			if ( force_ssl_admin() ) {
				add_filter( 'admin_url', array( $this, 'fix_admin_ajax_url' ), 10, 3 );
			}

			/**
			 * Adjust slug for uploaded files to include mime type
			 */
			add_filter( 'wp_insert_attachment_data', array( $this, 'filter_attachment_slug' ), 10, 2 );

			/**
			 * Remove Thrive Themes 'clone' option from WooCommerce products
			 */
			if ( is_admin() ) {
				add_action( 'load-edit.php', array( $this, 'remove_thrive_duplicate_link_row' ));
			}

			/**
			 * Add excerpt box to page edit screen
			 */
	     	add_post_type_support( 'page', 'excerpt' );

		}

		/**
		 * sc_page_children
		 *
		 * Implements shortcode [page-children]
		 *
		 * [page-children class=<class> page_id=<id> order_by=<order>]
		 *
		 * @return text HTML list containing entries for each child of the current page.
		 **/
		function sc_page_children($atts, $content = null)
		{
			global $id;

			/**
			 * Retrieve shortcode attributes
			 */
			extract(shortcode_atts(array(
				"page_id" => $id,
				"class" => 'page-children',
				"order_by" => 'title'
			), $atts));

			/**
			 * Sanitize fields
			 */
			$page_id = ( int ) $page_id;
			$order_by = in_array( $order_by, array( 'title', 'order', 'date' ) ) ? $order_by : 'title';
			if ( 'order' == $order_by ) $order_by = 'menu_order';

			/**
			 * Collect children of target page
			 */
			$children_of_page = get_children(array("post_parent"=>$page_id, "post_type"=>"page", "orderby" => $order_by, "order" => "ASC", "post_status" => "publish"));
			if (empty($children_of_page)) {
				return "";
			}

			$text = "<ul class=" . esc_attr( $class ) . ">";
			foreach ($children_of_page as $child_post) {
				$text .= "<li><a href='".get_bloginfo('wpurl')."/".get_page_uri($child_post->ID)."'> $child_post->post_title </a></li>";
			}
			$text .= "</ul>";
			return $text;
		}

		/**
		 * For Product pages, remove the duplicate link that Thrive would add
		 */
		function remove_thrive_duplicate_link_row()
		{
			$screen = get_current_screen();

			if ( !$screen ) return;

			if ( 'product' == $screen->post_type ) {
				remove_filter( 'post_row_actions', 'thrive_make_duplicate_link_row', 10 );
				remove_filter( 'page_row_actions', 'thrive_make_duplicate_link_row', 10 );
			}
		}

		/**
		 * Take care of some WooCommerce customizations
		 *
		 * @return void
		 */
		function woocommerce_customize()
		{
			/**
			 * Remove annoy message to install wootheme updater
			 */
			remove_action( 'admin_notices', 'woothemes_updater_notice' );

			/**
			 * Change Backorder text
			 */
			// add_filter( 'woocommerce_get_availability', array( $this, 'woo_change_backorder_text' ), 100, 2 );
			
			/**
			 * Add a woocommerce filter to enable the use of Document Manager documents as downloadable content
			 */
			add_filter( 'woocommerce_downloadable_file_exists', array( $this, 'filter_woo_downloadable_file_exists' ), 10, 2 );
		}
		
		/**
		 * Allow WooCommerce to understand 
		 *
		 * Uses filter defined in class-wc-product-download.php:file_exists()
		 *
		 * @param boolean $file_exists Earlier filters may have already decided if file exists
		 * @param string $file_url path to the downloadable file
		 */
		function filter_woo_downloadable_file_exists( $file_exists, $file_url ) {
			if ( '/content' === substr( $file_url, 0, 8 ) ) {
				$filepath = realpath( WP_CONTENT_DIR . substr( $file_url, 8 ) );
				return file_exists( $filepath );
			}
			
			return $file_exists;
		}


		/**
		 * Change "backorder" text
		 *
		 * @param array $availability
		 * @param WC_Product $product
		 * @return array
		 */
		function woo_change_backorder_text( $availability, $product )
		{
			if ( 'available-on-backorder' == $availability['class'] ) {
				$availability['availability'] = 'Made to Order';
			}

			return $availability;
		}

		/**
		 * Fix scheme (http/https) used for admin-ajax.php
		 *
		 * If FORCE_SSL_ADMIN is set, admin_url() will return a URL with https scheme, even if
		 * the front-end is using http.
		 *
		 * Cookies sent via https are secure by default and not available to http: content.
		 * This can break some site features served by AJAX.
		 *
		 * @param string   $url     The complete admin area URL including scheme and path.
		 * @param string   $path    Path relative to the admin area URL. Blank string if no path is specified.
		 * @param int|null $blog_id Site ID, or null for the current site.
		 * @return string  Repaired Admin URL
		 */
		function fix_admin_ajax_url($url, $path, $blog_id)
		{
			/**
			 * Scheme for admin-ajax.php should match scheme for current page
			 */
		    if ( $path == 'admin-ajax.php' ) {
				return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
		    }

		    return $url;
		}

		/**
		 * Filter attachment post data before it is added to the database
		 *  - Add mime type to post_name to reduce slug collisions
		 *
		 * @param array $data    Array of santized attachment post data
		 * @param array $postarr Array of unsanitized attachment post data
 		 * @return $data, array of post data
		 */
		function filter_attachment_slug($data, $postarr)
		{
			/**
			 * Only work on attachment types
			 */
			if ( ! array_key_exists( 'post_type', $data ) || 'attachment' != $data['post_type'] )
				return $data;

			/**
			 * Add mime type to the post title to build post-name
			 */
			$post_title = array_key_exists( 'post_title', $data ) ? $data['post_title'] : $postarr['post_title'];
			$post_mime_type = array_key_exists( 'post_mime_type', $data ) ? $data['post_mime_type'] : $postarr['post_mime_type'];
			$post_mime_type = str_replace( '/', '-', $post_mime_type );
			$post_name = sanitize_title( $post_title . '-' . $post_mime_type );

			/**
			 * Generate unique slug for post name
			 */
			$post_ID = array_key_exists( 'ID', $data ) ? $data['ID'] : $postarr['ID'];
			$post_status = array_key_exists( 'post_status', $data ) ? $data['post_status'] : $postarr['post_status'];
			$post_type = array_key_exists( 'post_type', $data ) ? $data['post_type'] : $postarr['post_type'];
			$post_parent = array_key_exists( 'post_parent', $data ) ? $data['post_parent'] : $postarr['post_parent'];

			$post_name = wp_unique_post_slug( $post_name, $post_ID, $post_status, $post_type, $post_parent );
			$data['post_name'] = $post_name;

			return $data;
		}
	}
}

// =========================
// = Plugin initialization =
// =========================

$pumastudios = new pumastudios();
$pumastudios->setup();

?>