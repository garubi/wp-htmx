<?php
/**
 * WP HTMX — default single fragment
 *
 * Fallback used when no theme template is found for a single-item response.
 * Works for both Layer 1 (registered routes) and Layer 2 (convention fallback).
 *
 * Available variables:
 *   $request  (WPHX_Request|null)  present in Layer 1 requests.
 *              $request->get_result() returns the WP_Post object.
 *              In Layer 2 $request is NOT available: use get_queried_object().
 *
 * To customise this template, either:
 *   a) Create htmx/{post_type}/single.php in your theme (specific override)
 *   b) Copy this file to {theme}/htmx/defaults/single.php  (default override)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var WPHX_Request|null $request */
$post = isset( $request ) ? $request->get_result() : get_queried_object(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

if ( ! ( $post instanceof WP_Post ) ) : ?>
	<p class="wphx-empty"><?php esc_html_e( 'Item not found.', 'wp-htmx' ); ?></p>
<?php else : ?>
	<article class="wphx-single">
		<h2><?php echo esc_html( $post->post_title ); ?></h2>
		<div class="wphx-content"><?php echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) ); ?></div>
	</article>
<?php endif;
