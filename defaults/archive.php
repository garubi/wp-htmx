<?php
/**
 * WP HTMX — default archive fragment
 *
 * Fallback used when no theme template is found for a collection response.
 * Works for both Layer 1 (registered routes) and Layer 2 (convention fallback).
 *
 * Available variables:
 *   $request  (WPHX_Request|null)  present in Layer 1 requests.
 *              $request->get_result() returns the WP_Query object.
 *              In Layer 2 $request is NOT available: use $GLOBALS['wp_query'].
 *
 * To customise this template, either:
 *   a) Create htmx/{post_type}/archive.php in your theme (specific override)
 *   b) Copy this file to {theme}/htmx/defaults/archive.php  (default override)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var WPHX_Request|null $request */
$query = isset( $request ) ? $request->get_result() : ( $GLOBALS['wp_query'] ?? null );

if ( ! ( $query instanceof WP_Query ) || ! $query->have_posts() ) : ?>
	<p class="wphx-empty"><?php esc_html_e( 'No items found.', 'wp-htmx' ); ?></p>
<?php else : ?>
	<ul class="wphx-list">
		<?php while ( $query->have_posts() ) : $query->the_post(); ?>
			<li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
		<?php endwhile; wp_reset_postdata(); ?>
	</ul>
<?php endif;
