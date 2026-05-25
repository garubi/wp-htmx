<?php
/**
 * WP HTMX — default error fragment
 *
 * Included by the router when a request fails (403, 404, 400, 500, …) and
 * no custom error_template is set on the route.
 *
 * The router already sends:
 *   - The appropriate HTTP status code
 *   - HX-Reswap: innerHTML  (swaps into the same target as the triggering element)
 *
 * Available variables:
 *   $request  (WPHX_Request)  current request object
 *              $request->get_result() returns a WP_Error with the error message.
 *
 * To customise this template, copy it to your theme:
 *   {theme}/htmx/defaults/error.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var WPHX_Request $request */
$error   = isset( $request ) ? $request->get_result() : null;
$message = $error instanceof WP_Error
	? $error->get_error_message()
	: __( 'An error occurred. Please try again.', 'wp-htmx' );
?>
<p class="wphx-error"><?php echo esc_html( $message ); ?></p>
