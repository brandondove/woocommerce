<?php
/**
 * Contains the query functions for WooCommerce which alter the front-end post queries and loops.
 *
 * @class 		woocommerce
 * @package		WooCommerce
 * @category	Class
 * @author		WooThemes
 */
class woocommerce_query {
	
	var $unfiltered_product_ids 	= array(); // Unfilted product ids (before layered nav etc)
	var $filtered_product_ids 		= array(); // Filted product ids (after layered nav)
	var $post__in 					= array(); // Product id's that match the layered nav + price filter
	var $meta_query 				= ''; 		// The meta query for the page
	var $layered_nav_post__in 		= array(); // posts matching layered nav only
	var $layered_nav_product_ids 	= array();	// Stores posts matching layered nav, so price filter can find max price in view
	
	/** constructor */
	function __construct() {
		add_filter( 'parse_query', array( &$this, 'parse_query') );
		add_action('wp', array( &$this, 'remove_parse_query') );
	}
	
	/**
	 * Query the products, applying sorting/ordering etc. This applies to the main wordpress loop
	 */
	function parse_query( $q ) {
		
		if (is_admin()) return;
		    
		// Only apply to product categories, the product post archive, the shop page, and product tags
	    if ( ( isset( $q->query_vars['suppress_filters'] ) && true == $q->query_vars['suppress_filters'] ) || (!$q->is_tax( 'product_cat' ) && !$q->is_post_type_archive( 'product' ) && !$q->is_page( get_option('woocommerce_shop_page_id') ) && !$q->is_tax( 'product_tag' ))) return;
		
		// Meta query
		$meta_query = (array) $q->get( 'meta_query' );
	    $meta_query[] = $this->visibility_meta_query();
	    $meta_query[] = $this->stock_status_meta_query();
		
		// Ordering
		$ordering = $this->get_catalog_ordering_args();
		
		// Get a list of post id's which match the current filters set (in the layered nav and price filter)
		$post__in = array_unique(apply_filters('loop_shop_post_in', array()));
		
		// Ordering query vars
		$q->set( 'orderby', $ordering['orderby'] );
		$q->set( 'order', $ordering['order'] );
		if (isset($ordering['meta_key'])) $q->set( 'meta_key', $ordering['meta_key'] );
	
		// Query vars that affect posts shown
		$q->set( 'post_type', 'product' );
		$q->set( 'meta_query', $meta_query );
	    $q->set( 'post__in', $post__in );
	    $q->set( 'posts_per_page', apply_filters('loop_shop_per_page', get_option('posts_per_page')) );
	    
	    // Store variables
	    $this->post__in = $post__in;
	    $this->meta_query = $meta_query;
	
	    // We're on a shop page so queue the woocommerce_get_products_in_view function
	    add_action('wp', array( &$this, 'get_products_in_view' ), 2);
	}
	
	/**
	 * Remove parse_query so it only applies to main loop
	 */
	function remove_parse_query() {
		remove_filter( 'parse_query', array( &$this, 'parse_query') ); 
	}
	
	/**
	 * Get an unpaginated list all product ID's (both filtered and unfiltered)
	 */
	function get_products_in_view() {
		
		global $wp_query;
		
		$unfiltered_product_ids = array();
		
		// Get all visible posts, regardless of filters
	    $unfiltered_product_ids = get_posts(
			array_merge( 
				$wp_query->query,
				array(
					'post_type' 	=> 'product',
					'numberposts' 	=> -1,
					'post_status' 	=> 'publish',
					'meta_query' 	=> $this->meta_query,
					'fields' 		=> 'ids'
				)
			)
		);
		
		// Store the variable
		$this->unfiltered_product_ids = $unfiltered_product_ids;
		
		// Also store filtered posts ids...
		if (sizeof($this->post__in)>0) :
			$this->filtered_product_ids = array_intersect($this->unfiltered_product_ids, $this->post__in);
		else :
			$this->filtered_product_ids = $this->unfiltered_product_ids;
		endif;
		
		// And filtered post ids which just take layered nav into consideration (to find max price in the price widget)
		if (sizeof($this->layered_nav_post__in)>0) :
			$this->layered_nav_product_ids = array_intersect($this->unfiltered_product_ids, $this->layered_nav_post__in);
		else :
			$this->layered_nav_product_ids = $this->unfiltered_product_ids;
		endif;
	}
	
	/**
	 * Returns an array of arguments for ordering products based on the selected values
	 */
	function get_catalog_ordering_args() {
		$current_order = (isset($_SESSION['orderby'])) ? $_SESSION['orderby'] : 'title';
		
		switch ($current_order) :
			case 'date' :
				$orderby = 'date';
				$order = 'desc';
				$meta_key = '';
			break;
			case 'price' :
				$orderby = 'meta_value_num';
				$order = 'asc';
				$meta_key = 'price';
			break;
			default :
				$orderby = 'title';
				$order = 'asc';
				$meta_key = '';
			break;
		endswitch;
		
		$args = array();
		
		$args['orderby'] = $orderby;
		$args['order'] = $order;
		if ($meta_key) $args['meta_key'] = $meta_key;
		
		return $args;
	}
	
	/**
	 * Returns a meta query to handle product visibility
	 */
	function visibility_meta_query( $compare = 'IN' ) {
		if ( is_search() ) $in = array( 'visible', 'search' ); else $in = array( 'visible', 'catalog' );
	
	    $meta_query = array(
	        'key' => 'visibility',
	        'value' => $in,
	        'compare' => $compare
	    );
	    
	    return $meta_query;
	}
	
	/**
	 * Returns a meta query to handle product stock status
	 */
	function stock_status_meta_query( $status = 'instock' ) {
		$meta_query = array();
		if (get_option('woocommerce_hide_out_of_stock_items')=='yes') :
			 $meta_query = array(
		        'key' 		=> 'stock_status',
				'value' 	=> $status,
				'compare' 	=> '='
		    );
		endif;
		return $meta_query;
	}
	
	/**
	 * Get a list of product id's which should be hidden from the frontend; useful for custom queries and loops
	 */
	function get_hidden_product_ids() {
		
		$meta_query = array();
		$meta_query[] = $this->visibility_meta_query( 'NOT IN' );
	    $meta_query[] = $this->stock_status_meta_query( 'outofstock' );
		
		$hidden_product_ids = get_posts(array(
			'post_type' 	=> 'product',
			'numberposts' 	=> -1,
			'post_status' 	=> 'publish',
			'meta_query' 	=> $meta_query,
			'fields' 		=> 'ids'
		));
		
		return (array) $hidden_product_ids;
	}	
 
}