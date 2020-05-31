<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'tecnomaster' );

/** MySQL database username */
define( 'DB_USER', 'root' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'KROdlOe-vC&pZd:1PWDo4(XqG9Hk@>:x|5XzeKwFI|W$i=EDNS8LV<&fu%DNuF<w' );
define( 'SECURE_AUTH_KEY',  'zjTf&GSClYQ(@!x=M|jH@EjIf5^I6-l1>qPlaRX|OaWw}q8$8JEf(W /]iKInM~:' );
define( 'LOGGED_IN_KEY',    'qk`X$n1+,57~YRZ< }-O.4RwCgHPn<Hz^SGr.KL0;Y7K=*OC*gUw]F54PuQAC0lo' );
define( 'NONCE_KEY',        ' dDNpQqDb7MjKI2i3F0rMDgy i4Ztk=p1dH-O8MEFlyz5w+@q>M%LMfXGrhR8f1)' );
define( 'AUTH_SALT',        'iHNgA17$?3?M}aE7{_-`qg67#<PFEy>GVJgd{t$F3n].|KQLCTP6(:] n-JxamL3' );
define( 'SECURE_AUTH_SALT', 'lG@)>IE_/~:0bS0lGNf)%Pw~wB[PJwLy/.H:7K&-k{*-J:381XNS|YLJ=+3&)Ojz' );
define( 'LOGGED_IN_SALT',   '$Ikn9;$>u%RqW7D EdddYhN!fpqzRK1*Ko}KzgPZ3S:{d`BT{:O5X-CXYe[L1ael' );
define( 'NONCE_SALT',       '*ATcP~lMa}$my%&8B@R<4cJGJA2?8|<7U-/LCLMP;9vh2N~`Bj;f2b)o:N6fvOrZ' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'tecno_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
