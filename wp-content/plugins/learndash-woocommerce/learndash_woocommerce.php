<?php
/**
 * Plugin Name: LearnDash LMS - WooCommerce Integration
 * Plugin URI: http://www.learndash.com/work/woocommerce/
 * Description: LearnDash LMS addon plugin to integrate LearnDash LMS with WooCommerce.
 * Version: 1.7.0
 * Author: LearnDash
 * Author URI: http://www.learndash.com
 * Domain Path: /languages/
 * Text Domain: learndash-woocommerce
 * WC requires at least: 3.0.0
 * WC tested up to: 4.0
 */

class Learndash_WooCommerce {
	public $debug = false;

	public function __construct() {
		self::setup_constants();

		self::includes();

		add_action( 'admin_init', array( __CLASS__, 'requires_wc' ) );

		// Setup translation
		add_action( 'plugins_loaded', array( __CLASS__, 'load_translation' ) );

		// Meta box
		add_filter( 'product_type_selector', array( __CLASS__, 'add_product_type' ), 10, 1 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_course_selector' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'add_scripts' ) );
		add_action( 'save_post', array( __CLASS__, 'store_related_courses' ), 10, 2 );

		// Product variation hooks
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_course_selector' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'store_variation_related_courses' ), 10, 2 );

		// Order hook
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'add_course_access' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'add_course_access' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'add_course_access' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'remove_course_access' ), 10, 1 );

		// New hooks for WC subscription
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'remove_subscription_course_access' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( __CLASS__, 'remove_subscription_course_access' ) );
		add_action( 'woocommerce_subscription_status_expired', array( __CLASS__, 'remove_subscription_course_access' ) );
		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'add_subscription_course_access' ) );

		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'remove_course_access_on_billing_cycle_completion' ), 10, 2 );

		// Silent background course enrollment process
		add_action( 'learndash_woocommerce_cron', array( __CLASS__, 'process_silent_course_enrollment' ) );

		// Force user to log in or create account if there is LD course in WC cart
		add_action( 'woocommerce_checkout_init', array( __CLASS__, 'force_login' ), 10, 1 );

		// Auto complete course transaction
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'auto_complete_transaction' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'auto_complete_transaction' ) );

		// Remove course increment record if a course unenrolled manually
		add_action( 'learndash_delete_user_data', array( $this, 'remove_access_increment_count' ) );
	}

	public static function setup_constants() {
		if ( ! defined( 'LEARNDASH_WOOCOMMERCE_VERSION' ) ) {
			define( 'LEARNDASH_WOOCOMMERCE_VERSION', '1.7.0' );
		}

		// Plugin file
		if ( ! defined( 'LEARNDASH_WOOCOMMERCE_FILE' ) ) {
			define( 'LEARNDASH_WOOCOMMERCE_FILE', __FILE__ );
		}		

		// Plugin folder path
		if ( ! defined( 'LEARNDASH_WOOCOMMERCE_PLUGIN_PATH' ) ) {
			define( 'LEARNDASH_WOOCOMMERCE_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
		}

		// Plugin folder URL
		if ( ! defined( 'LEARNDASH_WOOCOMMERCE_PLUGIN_URL' ) ) {
			define( 'LEARNDASH_WOOCOMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}
	}

	public static function includes() {
		include LEARNDASH_WOOCOMMERCE_PLUGIN_PATH . 'includes/class-cron.php';
		include LEARNDASH_WOOCOMMERCE_PLUGIN_PATH . 'includes/class-tools.php';
	}

	public static function requires_wc() {
		if ( ! class_exists( 'WooCommerce' ) || version_compare( WC_VERSION, '3.0', '<' )  ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );

			add_action( 'admin_notices', array( __CLASS__, 'upgrade_wc_notice' ) );

			unset( $_GET['activate'] );
		}
	}

	public static function upgrade_wc_notice() {
		?>

		<div class="notice notice-error is-dismissible">
			<p><?php _e( 'LearnDash WooCommerce addon requires WooCommerce 3.0 or above. Please activate and upgrade your WooCommerce. Reactivate this addon again after you activate or upgrade WooCommerce.', 'learndash-woocommerce' ); ?></p>
		</div>

		<?php
	}

	public static function load_translation()
	{
		global $wp_version;
		// Set filter for plugin language directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'ld_woocommerce_languages_directory', $lang_dir );

		$get_locale = get_locale();

		if ( $wp_version >= '4.7' ) {
			$get_locale = get_user_locale();
		}

		$mofile = sprintf( '%s-%s.mo', 'learndash-woocommerce', $get_locale );
		$mofile = WP_LANG_DIR . 'plugins/' . $mofile;

		if ( file_exists( $mofile ) ) {			
			load_textdomain( 'learndash-woocommerce', $mofile );
		} else {
			load_plugin_textdomain( 'learndash-woocommerce', $deprecated = false, $lang_dir );
		}

		// include translations/update class
		include LEARNDASH_WOOCOMMERCE_PLUGIN_PATH . 'includes/class-translations-ld-woocommerce.php';
	}

	public static function add_product_type( $types ) {
		$types['course'] = __( 'Course', 'learndash-woocommerce' );
		return $types;
	}

	public static function add_scripts() {
		wp_enqueue_script( 'ld_wc', plugins_url( '/learndash_woocommerce.js', __FILE__ ), array( 'jquery' ), LEARNDASH_WOOCOMMERCE_VERSION );
	}

	public static function add_front_scripts() {
		wp_enqueue_script( 'ld_wc_front', plugins_url( '/front.js', __FILE__ ), array( 'jquery' ), LEARNDASH_WOOCOMMERCE_VERSION );
	}

	public static function render_course_selector() {
		global $post;

		// JS script to make tax fields visible on course type
		?>

		<script type="text/javascript">
			jQuery(document).ready(function($) {
				const $tax_field_group = $( '._tax_status_field' ).parent( '.options_group' );

				$tax_field_group.addClass( 'show_if_course' );

				$( window ).on( 'load', function( e ) {
					e.preventDefault();
					if ( $( '#product-type' ).val() == 'course' ) {
						$tax_field_group.show();
					}
				});
			});
		</script>

		<?php

		$courses_options = self::list_courses();

		/**
		 * Filter for course selector class names
		 *
		 * @param string   	   Default class names
		 * @param object $post WP_Post object
		 * @var string New modified class names
		 */
		$class = apply_filters( 'learndash_woocommerce_course_selector_class', 'options_group show_if_course show_if_simple', $post );
		
		echo '<div class="' . $class . '">';

		wp_nonce_field( 'save_post', 'ld_wc_nonce' );

		$values = get_post_meta( $post->ID, '_related_course', true );
		if ( ! $values ) {
			$values = array();
		}

		self::woocommerce_wp_select_multiple( array(
			'id'          => '_related_course[]',
			'class'		  => 'select2 regular-width select short ld_related_courses',
			'label'       => __( 'LearnDash Courses', 'learndash-woocommerce' ),
			'options'     => $courses_options,
			'desc_tip'    => true,
			'description' => __( 'You can select multiple courses to sell together holding the SHIFT key when clicking.', 'learndash-woocommerce' ),
			'value' => $values,
		) );

		echo '</div>';
	}

	public static function store_related_courses( $id, $post ) {
		if ( ! isset( $_POST['ld_wc_nonce'] ) || ! wp_verify_nonce( $_POST['ld_wc_nonce'], 'save_post' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $post->post_type === 'product' ) {
			return;
		}

		if ( isset( $_POST['_related_course'] ) && ! empty( $_POST['_related_course'] ) ) {
			$related_courses = array_map( 'intval', $_POST['_related_course'] );
			update_post_meta( $id, '_related_course', $related_courses );
		} else {
			update_post_meta( $id, '_related_course', array() );
		}
	}

	public static function render_variation_course_selector( $loop, $data, $variation )
	{
		$courses_options = self::list_courses();
		
		echo '<div class="form-field form-row form-row-full">';

		wp_nonce_field( 'save_post', 'ld_wc_nonce' );

		$values = get_post_meta( $variation->ID, '_related_course', true );
		if ( ! $values ) {
			$values = array();
		}

		self::woocommerce_wp_select_multiple( array(
			'id'          => '_related_course['. $loop . '][]',
			'class'		  => 'select2 full-width select short ld_related_courses_variation',
			'label'       => __( 'LearnDash Courses', 'learndash-woocommerce' ),
			'options'     => $courses_options,
			'desc_tip'    => true,
			'description' => __( 'You can select multiple courses to sell together holding the SHIFT key when clicking.', 'learndash-woocommerce' ),
			'value' => $values,
		) );

		echo '</div>';
	}

	public static function store_variation_related_courses( $variation_id, $loop )
	{
		if ( ! isset( $_POST['ld_wc_nonce'] ) || ! wp_verify_nonce( $_POST['ld_wc_nonce'], 'save_post' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! $post->post_type === 'product' ) {
			return;
		}

		if ( isset( $_POST['_related_course'] ) && ! empty( $_POST['_related_course'] ) ) {
			$related_courses = array();
			foreach ( $_POST['_related_course'] as $key => $value ) {
				if ( isset( $value ) && ! empty( $value ) ) {
					$related_courses[ $key ] = array_map( 'intval', $value );
				} else {
					$related_courses[ $key ] = array();
				}

				update_post_meta( $variation_id, '_related_course', $related_courses[ $loop ] );
			}
		} else {
			update_post_meta( $variation_id, '_related_course', array() );
		}
	}

	/**
	 * Remove course when order is refunded
	 * @param  int    $order_id  Order ID
	 * @param  int    $refund_id Refund ID
	 */
	public static function remove_course_access( $order_id  )
	{
		$order = wc_get_order( $order_id );
		if ( $order !== false ) {
			$products = $order->get_items();
			foreach ( $products as $product ) {
				if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
					$courses_id = get_post_meta( $product['variation_id'], '_related_course', true );	
				} else {
					$courses_id = get_post_meta( $product['product_id'], '_related_course', true );
				}

				if ( $courses_id && is_array( $courses_id ) ) {
					foreach ( $courses_id as $cid ) {
						self::update_remove_course_access( $cid, $order->get_user_id(), $order_id );
					}
				}
			}
		}
	}

	public static function add_course_access( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order !== false ) {
			$products = $order->get_items();

			$courses_count = 0;
			array_walk( $products, function( $product ) use ( &$courses_count ) {
				if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
					$courses = get_post_meta( $product['variation_id'], '_related_course', true );	
				} else {
					$courses = get_post_meta( $product['product_id'], '_related_course', true );
				}

				$courses_count += count( $courses );
			} );

			if ( $courses_count >= self::get_products_count_for_silent_course_enrollment() && current_filter() !== 'learndash_woocommerce_cron' ) {
				self::enqueue_silent_course_enrollment( array( 'order_id' => $order_id ) );
			} else {
				foreach ( $products as $product ) {
					if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
						$courses_id = get_post_meta( $product['variation_id'], '_related_course', true );	
					} else {
						$courses_id = get_post_meta( $product['product_id'], '_related_course', true );
					}

					if ( $courses_id && is_array( $courses_id ) ) {
						foreach ( $courses_id as $cid ) {
							self::update_add_course_access( $cid, $order->get_user_id(), $order_id );

							// if WooCommerce subscription plugin enabled
							// if ( class_exists( 'WC_Subscriptions' ) ) {
							// 	// If it's a subscription...
							// 	if ( WC_Subscriptions_Order::order_contains_subscription($order) || WC_Subscriptions_Renewal_Order::is_renewal( $order ) ) {
							// 		error_log("Subscription (may be renewal) detected");
							// 		if ( $sub_key = WC_Subscriptions_Manager::get_subscription_key($order_id, $product['product_id'] ) ) {
							// 			error_log("Subscription key: " . $sub_key );
							// 			$subscription_r = WC_Subscriptions_Manager::get_subscription( $sub_key );
							// 			$start_date = $subscription_r['start_date'];
							// 			error_log( "Start Date:" . $start_date );
							// 			update_user_meta( $order->get_user_id(), "course_".$cid."_access_from", strtotime( $start_date ) );
							// 		}
							// 	}
							// }
						}
					}
				}
			}
		}
	}	

	public static function debug( $msg ) {
		$original_log_errors = ini_get( 'log_errors' );
		$original_error_log  = ini_get( 'error_log' );
		ini_set( 'log_errors', true );
		ini_set( 'error_log', dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'debug.log' );

		global $ld_sf_processing_id;
		if ( empty( $ld_sf_processing_id ) ) {
			$ld_sf_processing_id = time();
		}

		if ( isset( $_GET['debug'] ) || self::debug ) {
			error_log( "[$ld_sf_processing_id] " . print_r( $msg, true ) );
		} //Comment This line to stop logging debug messages.

		ini_set( 'log_errors', $original_log_errors );
		ini_set( 'error_log', $original_error_log );
	}

	public static function list_courses() {
		global $post;
		$postid = $post->ID;
		query_posts( array( 'post_type' => 'sfwd-courses', 'posts_per_page' => - 1 ) );
		$courses = array();
		while ( have_posts() ) {
			the_post();
			$courses[ get_the_ID() ] = get_the_title();
		}
		wp_reset_query();
		$post = get_post( $postid );

		return $courses;
	}

	/**
	 * Handle subscription status change to remove LD course access
	 * @param  object $subscription WC_Subscription object
	 * @return void
	 */
	public static function remove_subscription_course_access( $subscription )
	{
		if ( ! apply_filters( 'ld_woocommerce_remove_subscription_course_access', true, $subscription, current_filter() ) ) {
			return;
		}

		// Get products related to this order
		$products = $subscription->get_items();

		foreach ( $products as $product ) {
			if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
				$courses_id = get_post_meta( $product['variation_id'], '_related_course', true );	
			} else {
				$courses_id = get_post_meta( $product['product_id'], '_related_course', true );
			}

			if ( $courses_id && is_array( $courses_id ) ) {
				foreach ( $courses_id as $course_id ) {
				self::update_remove_course_access( $course_id, $subscription->get_user_id(), $subscription->get_id() );

				foreach ( $subscription->get_related_orders() as $o_id ) {
						self::update_remove_course_access( $course_id, $subscription->get_user_id(), $o_id );
					}
				}
			}
		}
	}

	/**
	 * Handle subscription status change to add LD course access
	 * @param  object $subscription WC_Subscription object
	 * @return void
	 */
	public static function add_subscription_course_access( $subscription )
	{
		if ( ! apply_filters( 'ld_woocommerce_add_subscription_course_access', true, $subscription, current_filter() ) ) {
			return;
		}

		$products    = $subscription->get_items();
		$start_date  = $subscription->get_date( 'date_created' );
		$customer_id = $subscription->get_user_id();

		$courses_count = 0;
		array_walk( $products, function( $product ) use ( &$courses_count ) {
			if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
				$courses = get_post_meta( $product['variation_id'], '_related_course', true );	
			} else {
				$courses = get_post_meta( $product['product_id'], '_related_course', true );
			}

			$courses_count += count( $courses );
		} );

		if ( $courses_count >= self::get_products_count_for_silent_course_enrollment() && current_filter() !== 'learndash_woocommerce_cron' ) {
			self::enqueue_silent_course_enrollment( array( 'subscription_id' => $subscription->get_id() ) );
		} else {
			foreach ( $products as $product ) {
				if ( isset( $product['variation_id'] ) && ! empty( $product['variation_id'] ) ) {
					$courses_id = get_post_meta( $product['variation_id'], '_related_course', true );	
				} else {
					$courses_id = get_post_meta( $product['product_id'], '_related_course', true );
				}

				// Update access to the courses
				if ( $courses_id && is_array( $courses_id ) ) {
					foreach ( $courses_id as $course_id ) {
						if(  empty( $customer_id ) || empty( $course_id ) ) {
							error_log( "User id: " . $customer_id . " Course Id:" . $course_id );
							return;
						}

						self::update_add_course_access( $course_id, $customer_id, $subscription->get_id() );
						// Replace start date to keep the drip feeding working
						update_user_meta( $customer_id, 'course_' . $course_id . '_access_from', strtotime( $start_date ) );
					}
				}
			}
		}			
	}

	/**
	 * Remove course access when user completes billing cycle
	 * 
	 * @param  object $subscription WC_Subscription object
	 * @param  array  $last_order   Last order details
	 */
	public static function remove_course_access_on_billing_cycle_completion( $subscription, $last_order )
	{
		if ( self::is_course_access_removed_on_subscription_billing_cycle_completion( $subscription ) ) {
			
			$next_payment_date = $subscription->calculate_date( 'next_payment' );

			// Check if there's no next payment date
			// See calculate_date() in class-wc-subscriptions.php
			if ( 0 == $next_payment_date ) {
				self::remove_subscription_course_access( $subscription );
			}
		}
	}

	/**
	 * Enqueue course enrollment in database for product with many courses
	 * 
	 * @param  array  $args Order/subscription arg in this format: 
	 *                      array( 'order_id' => $order_id ) OR 
	 *                      array( 'subscription_id' => $subscription_id )
	 * @return void
	 */
	public static function enqueue_silent_course_enrollment( $args ) {
		$queue = get_option( 'learndash_woocommerce_silent_course_enrollment_queue', array() );

		if ( ! empty( $args['order_id'] ) ) {
			$queue[ $args['order_id'] ] = $args;
		} elseif( ! empty( $args['subscription_id'] ) ) {
			$queue[ $args['subscription_id'] ] = $args;
		}

		update_option( 'learndash_woocommerce_silent_course_enrollment_queue', $queue );
	}

	/**
	 * Process silent background course enrollment using cron
	 * 
	 * @return void
	 */
	public static function process_silent_course_enrollment() {
		$queue = get_option( 'learndash_woocommerce_silent_course_enrollment_queue', array() );

		$processed_queue = array_slice( $queue, 0, 1, true );

		foreach ( $processed_queue as $id => $args ) {
			if ( ! empty( $args['order_id'] ) ) {
				self::add_course_access( $args['order_id'] );
			} elseif ( ! empty( $args['subscription_id'] ) ) {
				self::add_subscription_course_access( wcs_get_subscription( $args['subscription_id'] ) );
			}

			unset( $queue[ $id ] );

			update_option( 'learndash_woocommerce_silent_course_enrollment_queue', $queue );
		}
	}

	/**
	 * Force user to login when there is a LD course in cart
	 * 
	 * @param  object $checkout Checkout object
	 */
	public static function force_login( $checkout )
	{
		$cart_items = WC()->cart->cart_contents;
		if ( is_array( $cart_items ) ) {
			foreach ( $cart_items as $key => $item ) {
				$courses = get_post_meta( $item['data']->get_id(), '_related_course', true );
				$courses = maybe_unserialize( $courses );
				
				if ( isset( $courses ) && is_array( $courses ) ) {
					foreach ( $courses as $course ) {
						if ( $course != 0 ) {
							self::add_front_scripts();
							break 2;
						}
					}
				}
			}
		}
	}

	/**
	 * Autocomplete transaction if all cart items are course items
	 * @param  int    $order_id
	 */
	public static function auto_complete_transaction( $order_id )
	{
		if ( ! apply_filters( 'learndash_woocommerce_auto_complete_order', true, $order_id ) ) {
			return;
		}

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order->is_paid() ) {
			return;
		}

		if ( 'completed' == $order->get_status() ) {
			return;
		}

		$items = $order->get_items();
		$payment_method = $order->get_payment_method();

		$manual_payment_methods = apply_filters( 'learndash_woocommerce_manual_payment_methods', array(
			'bacs', 'cheque', 'cod'
		) );

		// If using manual payment, bail
		if ( in_array( $payment_method, $manual_payment_methods ) ) {
			return;
		}

		$found = array();
		foreach ( $items as $item ) {
			// If variation product
			if ( $item->get_variation_id() > 0 ) {
				$item_id = $item->get_variation_id();
				$courses = get_post_meta( $item->get_variation_id(), '_related_course', true );
			} 
			// Else if normal product
			elseif ( $item->get_product_id() > 0 ) {
				$item_id = $item->get_product_id();
				$courses = get_post_meta( $item->get_product_id(), '_related_course', true );
			}

			if ( ( is_array( $courses ) && ! empty( $courses ) && ! in_array( 0, $courses ) ) ||
				( is_array( $courses ) && count( $courses ) > 1 && in_array( 0, $courses ) ||
				( $item->is_type( 'virtual' ) || $item->is_type( 'downloadable' ) ) )
			) {
				$found[] = $item_id;
			}
		}

		// Autocomplete transaction if all items are course
		if ( count( $found ) == count( $items ) ) {
			$order->update_status( 'completed' );
		}
	}

	/**
	 * Remove course access count if user data is removed
	 * 
	 * @param  int    $user_id     
	 */
	public function remove_access_increment_count( $user_id ) {
		delete_user_meta( $user_id, '_learndash_woocommerce_enrolled_courses_access_counter' );
	}

	/**
	 * Add course access
	 * 
	 * @param int $course_id ID of a course
	 * @param int $user_id   ID of a user
	 */
	private static function update_add_course_access( $course_id, $user_id, $order_id )
	{
		self::increment_course_access_counter( $course_id, $user_id, $order_id );

		// check if user already enrolled
		if ( ! self::is_user_enrolled_to_course( $user_id, $course_id ) ) {
			ld_update_course_access( $user_id, $course_id );
		} elseif ( self::is_user_enrolled_to_course( $user_id, $course_id ) && ld_course_access_expired( $course_id, $user_id ) ) {
			
			// Remove access first
			// @todo: only remove access counter from old WC orders
			self::reset_course_access_counter( $course_id, $user_id );
			ld_update_course_access( $user_id, $course_id, $remove = true );

			// Re-enroll to get new access from value
			self::increment_course_access_counter( $course_id, $user_id, $order_id );
			ld_update_course_access( $user_id, $course_id );
		}
	}

	/**
	 * Add course access
	 * 
	 * @param int $course_id ID of a course
	 * @param int $user_id   ID of a user
	 * @param int $order_id  ID of an order
	 */
	private static function update_remove_course_access( $course_id, $user_id, $order_id )
	{
		$courses = self::decrement_course_access_counter( $course_id, $user_id, $order_id );

		if ( ! isset( $courses[ $course_id ] ) || empty( $courses[ $course_id ] ) ) {
			ld_update_course_access( $user_id, $course_id, $remove = true );
		}
	}

	/**
	 * Check if a user is already enrolled to a course
	 * 
	 * @param  integer $user_id   User ID
	 * @param  integer $course_id Course ID
	 * @return boolean            True if enrolled|false otherwise
	 */
	private static function is_user_enrolled_to_course( $user_id = 0, $course_id = 0 ) {
		$enrolled_courses = learndash_user_get_enrolled_courses( $user_id );

		if ( is_array( $enrolled_courses ) && in_array( $course_id, $enrolled_courses ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get all LearnDash courses
	 * 
	 * @return object LearnDash course
	 */
	private static function get_learndash_courses()
	{
		global $wpdb;
		$query = "SELECT posts.* FROM $wpdb->posts posts WHERE posts.post_type = 'sfwd-courses' AND posts.post_status = 'publish' ORDER BY posts.post_title";

		return $wpdb->get_results( $query, OBJECT );
	}

	/**
	 * Add enrolled course record to a user
	 *
	 * @param int $course_id ID of a course
	 * @param int $user_id   ID of a user
	 * @param int $order_id  ID of an order
	 */
	private static function increment_course_access_counter( $course_id, $user_id, $order_id )
	{
		$courses = self::get_courses_access_counter( $user_id );

		if ( isset( $courses[ $course_id ] ) && ! is_array( $courses[ $course_id ] ) ) {
			$courses[ $course_id ] = array();
		}

		if ( ! isset( $courses[ $course_id ] ) || ( isset( $courses[ $course_id] ) && array_search( $order_id, $courses[ $course_id ] ) === false ) ) {
			// Add order ID to course access counter
			$courses[ $course_id ][] = $order_id;
		}

		update_user_meta( $user_id, '_learndash_woocommerce_enrolled_courses_access_counter', $courses );

		return $courses;
	}

	/**
	 * Delete enrolled course record from a user
	 * 
	 * @param int $course_id ID of a course
	 * @param int $user_id   ID of a user
	 * @param int $order_id  ID of an order
	 */
	private static function decrement_course_access_counter( $course_id, $user_id, $order_id )
	{
		$courses = self::get_courses_access_counter( $user_id );
		
		if ( isset( $courses[ $course_id ] ) && ! is_array( $courses[ $course_id ] ) ) {
			$courses[ $course_id ] = array();
		}

		if ( isset( $courses[ $course_id ] ) ) {
			$keys = array_keys( $courses[ $course_id ], $order_id );
			if ( is_array( $keys ) ) {
				foreach ( $keys as $key ) {
					unset( $courses[ $course_id ][ $key ] );
				}
			}
		}

		update_user_meta( $user_id, '_learndash_woocommerce_enrolled_courses_access_counter', $courses );

		return $courses;
	}

	/**
	 * Reset course access counter
	 * 
	 * @param  int 	  $course_id Course ID
	 * @param  int 	  $user_id   User ID
	 * @return void
	 */
	private static function reset_course_access_counter( $course_id, $user_id ) {
		$courses = self::get_courses_access_counter( $user_id );
		
		if ( isset( $courses[ $course_id ] ) ) {
			unset( $courses[ $course_id ] );
		}

		update_user_meta( $user_id, '_learndash_woocommerce_enrolled_courses_access_counter', $courses );
	}

	/**
	 * Get user enrolled course access counter
	 * 
	 * @param  int $user_id ID of a user
	 * @return array        Course access counter array
	 */
	private static function get_courses_access_counter( $user_id )
	{
		$courses = get_user_meta( $user_id, '_learndash_woocommerce_enrolled_courses_access_counter', true );

		if ( ! empty( $courses ) ) {
			$courses = maybe_unserialize( $courses );
		} else {
			$courses = array();
		}
		
		return $courses;
	}

	/**
	 * Get setting if course access should be removed when user completeng subscription payment billing cycle
	 *
	 * @param  object $subscription WC_Subscription object
	 * @return boolean
	 */
	public static function is_course_access_removed_on_subscription_billing_cycle_completion( $subscription )
	{
		return apply_filters( 'learndash_woocommerce_remove_course_access_on_subscription_billing_cycle_completion', false, $subscription );
	}

	/**
	 * Output a select input box.
	 *
	 * @param array $field
	 */
	public static function woocommerce_wp_select_multiple( $field ) {
		global $thepostid, $post;

		?>

		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$( '.select2.regular-width' ).show().select2({
				});

				$( '.select2.full-width' ).show().select2({
					width: '100%'
				});
			});
		</script>

		<style>
			.select2-container--open .select2-dropdown {
				position: relative;
			}

			/* Required to hide the select field on initial load */
			.woocommerce_options_panel select.ld_related_courses {
				display: none;
			}

			.woocommerce_options_panel .select2-container--default .select2-selection--multiple,
			#variable_product_options .select2-container--default .select2-selection--multiple {
				border: 1px solid #ddd !important;
			}
		</style>

		<?php

		$thepostid              = empty( $thepostid ) ? $post->ID : $thepostid;
		$field['class']         = isset( $field['class'] ) ? $field['class'] : 'select short';
		$field['style']         = isset( $field['style'] ) ? $field['style'] : '';
		$field['wrapper_class'] = isset( $field['wrapper_class'] ) ? $field['wrapper_class'] : '';
		$field['value']         = isset( $field['value'] ) ? $field['value'] : get_post_meta( $thepostid, $field['id'], true );
		$field['name']          = isset( $field['name'] ) ? $field['name'] : $field['id'];
		$field['desc_tip']      = isset( $field['desc_tip'] ) ? $field['desc_tip'] : false;

		// Custom attribute handling
		$custom_attributes = array();

		if ( ! empty( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ) {

			foreach ( $field['custom_attributes'] as $attribute => $value ) {
				$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $value ) . '"';
			}
		}

		echo '<p class="form-field ' . esc_attr( $field['id'] ) . '_field ' . esc_attr( $field['wrapper_class'] ) . '">
			<label for="' . esc_attr( $field['id'] ) . '">' . wp_kses_post( $field['label'] ) . '</label>';

		if ( ! empty( $field['description'] ) && false !== $field['desc_tip'] ) {
			echo wc_help_tip( $field['description'] );
		}

		echo '<select id="' . esc_attr( $field['id'] ) . '" name="' . esc_attr( $field['name'] ) . '" class="' . esc_attr( $field['class'] ) . '" style="' . esc_attr( $field['style'] ) . '" ' . implode( ' ', $custom_attributes ) . ' multiple="multiple">';

		foreach ( $field['options'] as $key => $value ) {
			$selected = in_array( $key, $field['value'] ) ? 'selected="selected"' : '';
			echo '<option value="' . esc_attr( $key ) . '" ' . $selected . '>' . esc_html( $value ) . '</option>';
		}

		echo '</select> ';

		if ( ! empty( $field['description'] ) && false === $field['desc_tip'] ) {
			echo '<span class="description">' . wp_kses_post( $field['description'] ) . '</span>';
		}

		echo '</p>';
	}

	public static function get_products_count_for_silent_course_enrollment() {
		return apply_filters( 'learndash_woocommerce_products_count_for_silent_course_enrollment', 5 );
	}

	/**
	 * Output a custom error log file
	 * @param  mixed  $message Message
	 */
	public static function log( $message = '' ) {
		$file = LEARNDASH_WOOCOMMERCE_PLUGIN_PATH . 'error.log';

		if ( ! file_exists( $file ) ) {
			$handle = fopen( $file, 'a+' );
			fclose( $handle );
		}

		error_log( print_r( $message, true ), 3, $file );
	}
}
new Learndash_WooCommerce();


add_action( 'init', 'learndash_woocommerce_set_course_as_virtual' );
/**
 * Establish the Course Product type that is virtual, and sold individually
 */
function learndash_woocommerce_set_course_as_virtual() {
	if (class_exists('WC_Product')) {
		class WC_Product_Course extends WC_Product {

			/**
			 * Initialize course product.
			 *
			 * @param mixed $product
			 */
			public function __construct( $product ) {
				parent::__construct( $product );

				$this->product_type = 'course';
				$this->supports = array(
					'ajax_add_to_cart',
				);
				$this->set_virtual( true );
				$this->set_sold_individually( true );
			}


			/**
			 * Get the add to cart button text
			 *
			 * @return string
			 */
			public function add_to_cart_text() {
				$text = $this->is_purchasable() ? __( 'Add to cart', 'learndash-woocommerce' ) : __( 'Read More', 'learndash-woocommerce' );
				return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
			}

			/**
			 * Set the add to cart button URL used on the /shop/ page
			 *
			 * @return string
			 * @since 1.3.1
			 */
			public function add_to_cart_url() {
				// Code copied from WP Simple Product function of same name
				$url = $this->is_purchasable() && $this->is_in_stock() ? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $this->get_id() ) ) : get_permalink( $this->get_id() );
				return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
			}
		}
	}
}


/**
 * Add To Cart template, use the simple template
 */
add_action( 'woocommerce_course_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
