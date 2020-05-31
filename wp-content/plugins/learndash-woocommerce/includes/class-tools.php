<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
* Tools class
*/
class Learndash_WooCommerce_Tools {
	
	/**
	 * Hook functions
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_ld_wc_retroactive_access', array( $this, 'ajax_retroactive_access' ) );

		add_filter( 'woocommerce_debug_tools', array( $this, 'course_retroactive_access_tool' ) );
		// add_action( 'learndash_woocommerce_cron', array( $this, 'cron_execute_action_queue' ) );
		// add_action( 'admin_notices', array( $this, 'output_notice' ) );
	}

	/**
	 * Enqueue admin scripts
	 * @return void
	 */
	public function enqueue_admin_scripts() {
		$screen = get_current_screen();

		$prefix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( $screen->id == 'woocommerce_page_wc-status' && isset( $_GET['tab'] ) && $_GET['tab'] == 'tools' ) {
			wp_enqueue_style( 'ld-wc-tools', LEARNDASH_WOOCOMMERCE_PLUGIN_URL . 'assets/css/tools' . $prefix . '.css', array(), LEARNDASH_WOOCOMMERCE_VERSION );

			wp_enqueue_script( 'ld-wc-tools', LEARNDASH_WOOCOMMERCE_PLUGIN_URL . 'assets/js/tools' . $prefix . '.js', array( 'jquery' ), LEARNDASH_WOOCOMMERCE_VERSION, true );

			wp_localize_script( 'ld-wc-tools', 'LD_WC_Tools_Params', array(
				'text' => array(
					'status' => __( 'Status', 'learndash-woocommerce' ),
					'complete' => __( 'Complete', 'learndash-woocommerce' ),
					'keep_page_open' => __( 'Please keep this page open until the process is complete.', 'learndash-woocommerce' ),
					'retroactive_button' => __( 'Check LearnDash course access', 'learndash-woocommerce' ),
				),
				'nonce' => array(
					'retroactive_access' => wp_create_nonce( 'ld_wc_retroactive_access' ),
				),
			) );
		}
	}

	/**
	 * AJAX hook for processing retroactive tool
	 * @return void
	 */
	public function ajax_retroactive_access() {
		if ( ! isset( $_POST['nonce'] ) ) {
			wp_die();
		}

		if ( ! wp_verify_nonce( $_POST['nonce'], 'ld_wc_retroactive_access' ) ) {
			wp_die();
		}

		$step               = intval( $_POST['step'] );
		$per_batch          = apply_filters( 'learndash_woocommerce_retroactive_tool_per_batch', 10 );
		$offset             = ( $step - 1 ) * $per_batch;
		$order_total        = (array) wp_count_posts( 'shop_order' );
		$order_total        = array_sum( $order_total );
		$subscription_total = (array) wp_count_posts( 'shop_subscription' );
		$subscription_total = array_sum( $subscription_total );
		$total              = $order_total + $subscription_total;

		// Process orders
		$orders = wc_get_orders( array(
			'limit'  => $per_batch,
			'offset' => $offset,
			'order'  => 'ASC',
		) );

		// Foreach orders
		foreach ( $orders as $order ) {
			$status = $order->get_status();
			$id     = $order->get_id();

			// skip order that is part of subscription
			if ( function_exists( 'wcs_order_contains_subscription' ) ) {
				// Workaround for WC_Order_Refund because wcs_order_contains_subscription() only accepts WC_Order object or ID
				if ( ( 'refunded' == $status && is_a( $order, 'WC_Order' ) ) || is_a( $order, 'WC_Order_Refund' ) ) {
					Learndash_WooCommerce::remove_course_access( $id );
					continue;
				}

				if (  wcs_order_contains_subscription( $order, 'any' ) ) {
					continue;
				}
			}

			switch ( $status ) {
				case 'completed':
				case 'processing':
					Learndash_WooCommerce::add_course_access( $id );
					break;
				
				case 'pending':
				case 'on-hold':
				case 'cancelled':
				case 'refunded':
				case 'failed':
					Learndash_WooCommerce::remove_course_access( $id );
					break;
			}
		}

		// Process subscriptions only after orders process is complete
		$subscriptions = array();
		if ( empty( $orders ) ) {
			// Process subscriptions
			if ( function_exists( 'wcs_get_subscriptions' ) ) {
				if ( ! empty( $_POST['subscription_step'] ) && ! empty( $_POST['last_order_step'] ) ) {
					$last_order_step   = $_POST['last_order_step'];
					$subscription_step = $_POST['subscription_step'];
				} else {
					$last_order_step   = $step - 1;
					$subscription_step = 1;
				}

				$subscription_offset = ( $subscription_step - 1 ) * $per_batch;

				// Get subscriptions
				$subscriptions = wcs_get_subscriptions( array(
					'subscriptions_per_page' => $per_batch,
					'offset'                 => $subscription_offset,
					'order'                  => 'ASC',
				) );

				foreach ( $subscriptions as $subscription ) {
					$status = $subscription->get_status();
					$id     = $subscription->get_id();

					switch ( $status ) {
						case 'active':
							Learndash_WooCommerce::add_subscription_course_access( $subscription );
							break;
						
						case 'cancelled':
						case 'on-hold':
						case 'expired':
							Learndash_WooCommerce::remove_subscription_course_access( $subscription );
							break;
					}
				}
			}	
		}

		if ( ! empty( $orders ) || ! empty( $subscriptions ) ) {
			$last_order_step    = isset( $last_order_step ) && ! empty( $last_order_step ) ? $last_order_step : 0;
			$subscription_step    = isset( $subscription_step ) && ! empty( $subscription_step ) ? $subscription_step : 0;
			
			$order_offset        = empty( $last_order_step ) ? ( $step - 1 ) * $per_batch : ( $last_order_step - 1 ) * $per_batch;
			$subscription_offset = ! empty( $subscription_step ) ? ( $subscription_step - 1 ) * $per_batch : 0;
			$offset              = $order_offset + $subscription_offset;

			$percentage 		= number_format( ( ( $offset + $per_batch ) / $total ) * 100, 0 );
			$percentage			= $percentage > 100 ? 100 : $percentage;

			$returned_subscription_step = $subscription_step > 0 ? $subscription_step + 1 : 0;

			$return = array(
				'step'              => intval( $step + 1 ),
				'last_order_step'   => intval( $last_order_step ),
				'subscription_step' => intval( $returned_subscription_step ),
				'percentage'        => intval( $percentage ),
			);
		} else {
			// done
			$return = array(
				'step' => 'complete',
			);
		}

		echo json_encode( $return );

		wp_die();
	}

	/**
	 * Get the latest option
	 * 
	 * @return array Addon settings
	 */
	public function get_options() {
		wp_cache_delete( 'learndash_woocommerce_settings', 'options' );
		$options = get_option( 'learndash_woocommerce_settings', array() );

		return $options;
	}

	/**
	 * Add tools button for LD WooCommerce
	 * 
	 * @param  array  $tools Existing tools
	 * @return array         New tools
	 */
	public function course_retroactive_access_tool( $tools ) {
		$tools['learndash_retroactive_access'] = array(
			'name' => __( 'LearnDash retroactive course access', 'learndash-woocommerce' ),
			'button' => __( 'Check LearnDash course access', 'learndash-woocommerce' ),
			'desc' => __( 'Check LearnDash course access of WooCommerce integration. Enroll and unenroll users according to WooCommerce purchase/subscription data.', 'learndash-woocommerce' ),
			// 'callback' => array( $this, 'execute_course_retroactive_access' ),
			'callback' => function() {}
		);

		return $tools;
	}

	/**
	 * Callback for retroactive access tool action
	 */
	public function execute_course_retroactive_access() {
		$options = $this->get_options();

		$action_queue = isset( $options['action_queue'] ) && ! empty( $options['action_queue'] ) ? $options['action_queue'] : array();
		$action_queue = serialize( $action_queue );
		if ( false === strpos( $action_queue, 'retroactive_access' ) ) {
			$options['action_queue'][] = array( 'name' => 'retroactive_access' );
			update_option( 'learndash_woocommerce_settings', $options, false );
		}


		return __( 'The process is being done in the background. Please wait a while for the process to be finished.', 'learndash-woocommerce' );
	}

	/**
	 * Execute action queue
	 *
	 * Hooked to once per minute cron schedule.
	 */
	public function cron_execute_action_queue() {
		$lock_file = WP_CONTENT_DIR . '/uploads/learndash/learndash-woocommerce/process-lock.txt';
		$dirname   = dirname( $lock_file );

		if ( ! is_dir( $dirname ) ) {
			wp_mkdir_p( $dirname );
		}

		$lock_fp   = fopen( $lock_file, 'c+' );

		// Now try to get exclusive lock on the file. 
		if ( ! flock( $lock_fp, LOCK_EX | LOCK_NB ) ) { 
			// If you can't lock then abort because another process is already running
			exit(); 
		}

		$options = $this->get_options();

		if ( ! isset( $options['action_queue'] ) || ( isset( $options['action_queue'] ) && empty( $options['action_queue'] ) ) ) {
			return;
		}

		foreach ( $options['action_queue'] as $key => $action ) {
			switch ( $action['name'] ) {
				case 'retroactive_access':
					$this->check_retroactive_access( $key, $action );
					break;
			}

			// unset( $options['action_queue'][ $key ] );
		}

		// update_option( 'learndash_woocommerce_settings', $options, false );
	}

	/**
	 * Check retroactive access tool function
	 */
	public function check_retroactive_access( $key, $args ) {
		// Process orders and subscription in batch
		$limit  = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? $args['limit'] : 50;
		$page   = isset( $args['page'] ) && is_numeric( $args['page'] ) ? $args['page'] : 1;
		$offset = ( $page - 1 ) * $limit;

		// Get orders
		$orders = wc_get_orders( array(
			'limit'  => $limit,
			'offset' => $offset,
			'order'  => 'ASC',
		) );
		// Foreach orders
		foreach ( $orders as $order ) {
			$status = $order->get_status();
			$id     = $order->get_id();

			// skip order that is part of subscription
			if ( function_exists( 'wcs_order_contains_subscription' ) ) {
				// Workaround for WC_Order_Refund because wcs_order_contains_subscription() only accepts WC_Order object or ID
				if ( 'refunded' == $status || ! is_a( $order, 'WC_Order' ) ) {
					Learndash_WooCommerce::remove_course_access( $id );
					continue;
				}

				if (  wcs_order_contains_subscription( $order, 'any' ) ) {
					continue;
				}
			}

			switch ( $status ) {
				case 'completed':
				case 'processing':
					Learndash_WooCommerce::add_course_access( $id );
					break;
				
				case 'pending':
				case 'on-hold':
				case 'cancelled':
				case 'refunded':
				case 'failed':
					Learndash_WooCommerce::remove_course_access( $id );
					break;
			}
		}

		$subscriptions = array();
		if ( function_exists( 'wcs_get_subscriptions' ) ) {
			// Get subscriptions
			$subscriptions = wcs_get_subscriptions( array(
				'subscriptions_per_page' => $limit,
				'offset'                 => $offset,
				'order'                  => 'ASC',
			) );

			foreach ( $subscriptions as $subscription ) {
				$status = $subscription->get_status();
				$id     = $subscription->get_id();

				switch ( $status ) {
					case 'active':
						Learndash_WooCommerce::add_subscription_course_access( $subscription );
						break;
					
					case 'cancelled':
					case 'on-hold':
					case 'expired':
						Learndash_WooCommerce::remove_subscription_course_access( $subscription );
						break;
				}
			}	
		}

		$options = $this->get_options();
		unset( $options['action_queue'][ $key ] );

		// Exit if both $orders and $subscriptions are empty, no next batch
		if ( empty( $orders ) && empty( $subscriptions ) ) {
			if ( ! in_array( 'retroactive_access', $options['action_queue_success'] ) ) {
				$options['action_queue_success'][] = 'retroactive_access';
			}
			update_option( 'learndash_woocommerce_settings', $options, false );
			return;
		}

		// Add action queue for the next iteration
		$options['action_queue'][] = array(
			'name'   => 'retroactive_access',
			'limit'  => $limit,
			'page'   => $page + 1,
		);

		update_option( 'learndash_woocommerce_settings', $options, false );
	}

	public function output_notice() {
		$options = $this->get_options();

		if ( ! empty( $options['action_queue_success'] ) ) {

			foreach ( $options['action_queue_success'] as $key => $name ) {

				switch ( $name ) {
					case 'retroactive_access':
						?>
						<div class="notice notice-success">
							<p>
								<?php _e( 'The LearnDash WooCommerce retroactive course access process has been successfully done.', 'learndash-woocommerce' ); ?>
							</p>
						</div>

						<?php
						break;
				}
				
				unset( $options['action_queue_success'][ $key ] );
			}

			update_option( 'learndash_woocommerce_settings', $options, false );
		}
	}
}

new Learndash_WooCommerce_Tools();