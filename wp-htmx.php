<?php
/**
 * Plugin Name:     WP HTMX
 * Plugin URI:      https://github.com/garubi/wp-htmx
 * Description:     Integrates HTMX into WordPress.
 * Author:          Stefano Garuti
 * Author URI:      https://github.com/garubi
 * Text Domain:     wp-htmx
 * Domain Path:     /languages
 * Version:         0.1.0
 * Requires at least: 6.0
 * Requires PHP:    8.1
 *
 * @package         Wp_Htmx
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/router.php';
require_once __DIR__ . '/inc/health.php';
