<?php
/**
 *  PHP-PayPal-IPN Handler
 */

/*
NOTE: the IPN call is asynchronous and can arrive later than the browser is redirected to the success url by paypal
You cannot rely on setting up some details here and then using them in your success page.
 */

global $ld_lms_processing_id;
$ld_lms_processing_id = time();

global $ipn_log_filename;
$ipn_log_filename = '';

$wp_upload_dir    = wp_upload_dir();
$ld_ipn_logs_dir  = trailingslashit( $wp_upload_dir['basedir'] ) . 'learndash/paypal_ipn/';
$ipn_log_filename = trailingslashit( $ld_ipn_logs_dir ) . $ld_lms_processing_id . '.log';
if ( ! file_exists( $ld_ipn_logs_dir ) ) {
	if ( wp_mkdir_p( $ld_ipn_logs_dir ) == false ) {
		$ipn_log_filename = '';
	}
}
@file_put_contents( trailingslashit( $ld_ipn_logs_dir ) . 'index.php', '// nothing to see here' );


if ( ! function_exists( 'ld_ipn_debug' ) ) {
	function ld_ipn_debug( $msg ) {
		global $ld_lms_processing_id, $ipn_log_filename;

		if ( ( isset( $_GET['debug'] ) ) && ( ! empty( $ipn_log_filename ) ) ) {
			// error_log( "[$ld_lms_processing_id] " . $msg ."\r\n", 3, $ipn_log_filename );

			file_put_contents( $ipn_log_filename, learndash_adjust_date_time_display( time(), 'Y-m-d H:i:s' ) . ' [' . $ld_lms_processing_id . '] ' . $msg . "\r\n", FILE_APPEND );
		}
	}
}

ld_ipn_debug( 'DEBUG: _POST<pre>' . print_r( $_POST, true ) . '</pre>' );
ld_ipn_debug( 'DEBUG: _GET<pre>' . print_r( $_GET, true ) . '</pre>' );

ld_ipn_debug( 'IPN Listener Loading...' );
require __DIR__ . '/ipnlistener.php';
$listener = new IpnListener();

/**
 * Action for initial IpnListener to allow override of public attributes.
 *
 * @since 2.2.1.2
 *
 * @param Object  $listener Instance of IpnListener Class.
 */
do_action_ref_array( 'leandash_ipnlistener_init', array( &$listener ) );


ld_ipn_debug( 'IPN Listener Loaded' );

/*
While testing your IPN script you should be using a PayPal "Sandbox" (get an account at: https://developer.paypal.com )
When you are ready to go live change use_sandbox to false.*/

$paypal_settings                   = LearnDash_Settings_Section::get_section_settings_all( 'LearnDash_Settings_Section_PayPal' );
$paypal_settings['paypal_sandbox'] = ( 'yes' === $paypal_settings['paypal_sandbox'] ) ? 1 : 0;
ld_ipn_debug( 'DEBUG: paypal_settings<pre>' . print_r( $paypal_settings, true ) . '</pre>' );

ld_ipn_debug( 'Course Settings Loaded.' );

$listener->use_sandbox = false;

if ( ! empty( $paypal_settings['paypal_sandbox'] ) ) {
	$listener->use_sandbox = true;
	ld_ipn_debug( 'Sandbox Enabled.' );
}

try {
	ld_ipn_debug( 'Checking Post Method.' );
	$listener->requirePostMethod();
	$verified = $listener->processIpn();
	ld_ipn_debug( 'Post method check completed.' );
} catch ( Exception $e ) {
	ld_ipn_debug( 'Post method error: <pre>' . print_r( $e->getMessage(), true ) . '</pre>' );
	ld_ipn_debug( 'Found Exception. Ending Script.' );
	exit( 0 );
}
$transaction = $_POST;
$transaction = array_map( 'trim', $transaction );
$transaction = array_map( 'esc_attr', $transaction );

$transaction['log_file'] = basename( $ipn_log_filename );

if ( ( isset( $transaction['item_number'] ) ) && ( ! empty( $transaction['item_number'] ) ) ) {
	$transaction['course_id'] = absint( $transaction['item_number'] );
	$transaction['course']    = get_post( $transaction['course_id'] );
	if ( ( ! $transaction['course'] ) || ( ! is_a( $transaction['course'], 'WP_Post' ) ) || ( learndash_get_post_type_slug( 'course' ) !== $transaction['course']->post_type ) ) {
		$transaction['course_id'] = 0;
		$transaction['course']    = '';
	}
}	

if ( ! empty( $transaction['course_id'] ) ) {
	$course_settings = learndash_get_setting( $transaction['course_id'] );

	if ( isset( $transaction['mc_gross'] ) ) {
		if ( ( isset( $course_settings['course_price_type'] ) ) && ( 'paynow' === $course_settings['course_price_type'] ) ) {
			if ( ( isset( $course_settings['course_price'] ) ) && ( ! empty( $course_settings['course_price'] ) ) ) {
				$server_course_price = preg_replace( '/[^0-9.]/', '', $course_settings['course_price'] );
				$server_course_price = number_format( floatval( $server_course_price ), 2, '.', '' );

				$ipn_course_price = preg_replace( '/[^0-9.]/', '', $transaction['mc_gross'] );
				$ipn_course_price = floatval( $ipn_course_price );
				ld_ipn_debug( 'DEBUG: IPN GrossTax [' . $ipn_course_price . ']' );

				if ( isset( $transaction['tax'] ) ) {
					$ipn_tax_price = preg_replace( '/[^0-9.]/', '', $transaction['tax'] );
				} else {
					$ipn_tax_price = 0;
				}
				$ipn_tax_price = floatval( $ipn_tax_price );
				ld_ipn_debug( 'DEBUG: IPN Tax [' . $ipn_tax_price . ']' );

				$ipn_course_price = $ipn_course_price - $ipn_tax_price;
				$ipn_course_price = number_format( floatval( $ipn_course_price ), 2, '.', '' );
				ld_ipn_debug( 'DEBUG: IPN Gross - Tax (result) [' . $ipn_course_price . ']' );

				if ( $server_course_price == $ipn_course_price ) {
					ld_ipn_debug( 'IPN Price match: IPN Price [' . $ipn_course_price . '] Course Price [' . $server_course_price . ']' );
				} else {
					ld_ipn_debug( 'Error: IPN Price mismatch: IPN Price [' . $ipn_course_price . '] Course Price [' . $server_course_price . ']' );
					$verified = false;
				}
			}
		}
	} else {
		ld_ipn_debug( "Error: Missing 'mc_gross' in IPN data" );
		$verified = false;
	}
} else {
	ld_ipn_debug( "Error: Missing 'item_number' in IPN data" );
	$verified = false;
}

$admin_email  = get_option( 'admin_email' );
if ( ! empty( $admin_email ) ) {
	$admin_email = sanitize_email( $admin_email );
}
if ( ! is_email( $admin_email ) ) {
	ld_ipn_debug( "Error: Invalid 'admin_email' get_option: " . $admin_email );
	exit();
}

$seller_email = $paypal_settings['paypal_email'];
if ( ! empty( $seller_email ) ) {
	$seller_email = sanitize_email( $seller_email );
}
if ( ! is_email( $seller_email ) ) {
	ld_ipn_debug( "Error: Invalid 'seller_email' in PayPal settings: " . $seller_email );
	exit();
}


ld_ipn_debug( 'Loaded Email IDs. Notification Email: ' . $admin_email . ' Seller Email: ' . $seller_email );
$notify_on_valid_ipn = 1;

ld_ipn_debug( 'Payment Verified? : ' . ( ( $verified ) ? 'YES' : 'NO' ) );
/*The processIpn() method returned true if the IPN was "VERIFIED" and false if it was "INVALID".*/

if ( $verified ) {
	ld_ipn_debug( 'Sure, Verfied! Moving Ahead.' );
	/*
	  Once you have a verified IPN you need to do a few more checks on the POST
	fields--typically against data you stored in your database during when the
	end user made a purchase (such as in the "success" page on a web payments
	standard button). The fields PayPal recommends checking are:
	1. Check the $_POST['payment_status'] is "Completed"
	2. Check that $_POST['txn_id'] has not been previously processed
	3. Check that $_POST['receiver_email'] is get_option('EVI_Paypal_Seller_email')
	4. Check that $_POST['payment_amount'] and $_POST['payment_currency']
	are correct
	 */

	// note: This is just notification for us. Paypal has already made up its mind and the payment has been processed
	// (you can't cancel that here)
	ld_ipn_debug( 'Receiver Email: ' . $transaction['receiver_email'] . ' Valid Receiver Email? :' . ( ( $transaction['receiver_email'] == $seller_email ) ? 'YES' : 'NO' ) );

	if ( $transaction['receiver_email'] != $seller_email ) {

		if ( $admin_email != '' ) {
			// mail( $admin_email, 'Warning: IPN with invalid receiver email!', $listener->getTextReport() );
			ld_ipn_debug( 'Warning! IPN with invalid receiver email!' );
		} else {
			// error_log( 'notification email not set' );
		}
		// We abort here to prevent fake IPN simulator posts creating users, etc.
		exit();
	}

	ld_ipn_debug( 'Payment Status: ' . $transaction['payment_status'] . ' Completed? :' . ( ( 'Completed' === $transaction['payment_status'] ) ? 'YES' : 'NO' ) );

	if ( 'Completed' === $transaction['payment_status'] ) {
		ld_ipn_debug( 'Sure, Completed! Moving Ahead.' );
		// a customer has purchased from this website
		// add him to database for customer support

		// get / add user

		$email = sanitize_email( $transaction['payer_email'] );
		if ( ! is_email( $email ) ) {
			ld_ipn_debug( "Error: Invalid 'payer_email' in IPN data: ". $email );
			exit();
		}
		ld_ipn_debug( 'Payment Email: ' . $email );

		if ( ! empty( $transaction['custom'] ) ) {
			$user = get_user_by( 'id', absint( $transaction['custom'] ) );
			if ( ( ! $user ) || ( ! is_a( $user, 'WP_User' ) ) ) {
				ld_ipn_debug( "Error: Unknown user 'custom' in IPN data: ". absint( $transaction['custom'] ) );
				exit();
			}
			ld_ipn_debug( 'User ID [' . $transaction['custom'] . '] passed back by Paypal. Checking if user exists. User Found: ' . ( ! empty( $user->ID ) ? 'Yes' : 'No' ) );
		}

		if ( ! empty( $user->ID ) ) {

			$user_id = $user->ID;
			ld_ipn_debug( 'User found. Passed back by Paypal. User ID: ' . $user_id );

		} elseif ( is_user_logged_in() ) {

			ld_ipn_debug( 'User is logged in.' );
			$user    = wp_get_current_user();
			$user_id = $user->ID;
			ld_ipn_debug( 'User is logged in. User Id: ' . $user_id );

		} else {

			ld_ipn_debug( 'User not logged in.' );

			if ( $user_id = email_exists( $email ) ) {

				ld_ipn_debug( 'User email exists. User Found. User Id: ' . $user_id );
				$user = get_user_by( 'id', $user_id );

			} else {

				ld_ipn_debug( 'User email does not exists. Checking available username...' );
				$username = $email;

				if ( username_exists( $email ) ) {

					ld_ipn_debug( 'Username matching email found, cannot use. Looking further with $count_$email.' );
					$count = 1;

					do {
						$new_username = $count . '_' . $email;
						$count++;
					} while ( username_exists( $new_username ) );

					$username = $new_username;
					ld_ipn_debug( 'Accepting user with $username as :' . $new_username );
				}

				$random_password = wp_generate_password( 12, false );
				ld_ipn_debug( 'Creating User with username:' . $username . ' email: ' . $email );
				$user_id = wp_create_user( $username, $random_password, $email );
				ld_ipn_debug( 'User created with user_id: ' . $user_id );
				$user = get_user_by( 'id', $user_id );
				// Handle all three versions of WP wp_new_user_notification
				global $wp_version;
				if ( version_compare( $wp_version, '4.3.0', '<' ) ) {
					wp_new_user_notification( $user_id, $user_pass );
				} elseif ( version_compare( $wp_version, '4.3.0', '==' ) ) {
					wp_new_user_notification( $user_id, 'both' );
				} elseif ( version_compare( $wp_version, '4.3.1', '>=' ) ) {
					wp_new_user_notification( $user_id, null, 'both' );
				}
				ld_ipn_debug( 'Notification Sent.' );

			}
		}

		// record in course
		ld_ipn_debug( 'Starting to give course access...' );
		$meta = ld_update_course_access( absint( $user_id ), $transaction['course_id'] );

		/*
		// Removed 2020-03-31: Not really sure why this is here. There is no user meta '_sfwd-courses'
		$usermeta = get_user_meta( $user_id, '_sfwd-courses', true );
		ld_ipn_debug( 'Fetched User Meta:' . $usermeta );

		if ( empty( $usermeta) ) {
			$usermeta = $course_id;
		} else {
			$usermeta .= ",$course_id";
		}

		update_user_meta( $user_id, '_sfwd-courses', $usermeta );
		ld_ipn_debug( 'Updated user meta:' . $usermeta );
		*/

		// log transaction
		ld_ipn_debug( 'Starting Transaction Creation.' );
		$course_title = '';
		if ( ! empty( $transaction['course'] ) ) {
			$course_title = $transaction['course']->post_title;
		}

		ld_ipn_debug( 'Course Title: ' . $course_title );

		$post_id = wp_insert_post(
			array(
				'post_title'  => "Course {$course_title} Purchased By {$email}",
				'post_type'   => 'sfwd-transactions',
				'post_status' => 'publish',
				'post_author' => $user_id,
			)
		);
		ld_ipn_debug( 'Created Transaction. Post Id: ' . $post_id );

		foreach ( $transaction as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
	}

	ld_ipn_debug( 'IPN Processing Completed Successfully.' );
	$notifyOnValid = $notify_on_valid_ipn != '' ? $notify_on_valid_ipn : '0';

} else {

	/*
	 An Invalid IPN *may* be caused by a fraudulent transaction attempt. It's a good idea to have a developer or sys admin
	manually investigate any invalid IPN.*/
	ld_ipn_debug( 'Invalid IPN. Shutting Down Processing.' );
}

// we're done here
