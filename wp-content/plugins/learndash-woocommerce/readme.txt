=== WooCommerce for LearnDash ===
Author: LearnDash
Author URI: https://learndash.com
Plugin URI: https://learndash.com/add-on/woocommerce/ 
LD Requires at least: 3.0
Slug: learndash-woocommerce
Tags: integration, woocommerce,
Requires at least: 5.0
Tested up to: 5.4
Requires PHP: 7.0
Stable tag: 1.7.0

Integrate LearnDash LMS with WooCommerce.

== Description ==

Integrate LearnDash LMS with WooCommerce.

WooCommerce is the most popular shopping cart software for WordPress. Most WordPress themes are compatible with WooCommerce. This add-on allows you to sell your LearnDash created courses with the WooCommerce shopping cart.

= Integration Features = 

* Easily map courses to products
* Associate one, or multiple courses to a single product
* Automatic course access removal
* Works with any payment gateway
* Works with WooCommerce Subscription

See the [Add-on](https://learndash.com/add-on/woocommerce/) page for more information.

== Installation ==

If the auto-update is not working, verify that you have a valid LearnDash LMS license via LEARNDASH LMS > SETTINGS > LMS LICENSE. 

Alternatively, you always have the option to update manually. Please note, a full backup of your site is always recommended prior to updating. 

1. Deactivate and delete your current version of the add-on.
1. Download the latest version of the add-on from our [support site](https://support.learndash.com/article-categories/free/).
1. Upload the zipped file via PLUGINS > ADD NEW, or to wp-content/plugins.
1. Activate the add-on plugin via the PLUGINS menu.

== Changelog ==

= 1.7.0 =
* Added subscription parameter to remove course on billing cycle completion filter hook
* Added filter hook to customize retroactive tool per batch value so users can change it depending on their server specifications
* Added process enrollment queue 1 at a time to prevent timemout error
* Added WC requires and tested headers
* Added silent course enrollment feature to prevent long loading time in products with many associated courses
* Added reduce minimum products count for background course enrollment because it caused timeout error
* Updated plugin name and description to match with other addons
* Updated change assoicated course field label
* Fixed fatal error `WC_Order_Refund` object not having `get_customer_id()` method `use get_user_id()` instead
* Fixed CSS issue where `select2` dropdown is missing
* Fixed learndash WooCommerce cron hook not registered

View the full changelog [here](https://www.learndash.com/add-on/woocommerce/).