<?php
/**
 * WP HTMX — Assets
 *
 * - Exposes is_htmx() to check if the current request was sent by HTMX
 * - Loads HTMX (CDN or local) via the 'wphx_htmx_src' filter
 * - Injects the WP nonce in the X-WP-Nonce header on every HTMX request
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'is_htmx' ) ) {
	/**
	 * Returns true if the current request was sent by HTMX.
	 *
	 * @return bool
	 */
	function is_htmx(): bool {
		return isset( $_SERVER['HTTP_HX_REQUEST'] );
	}
}

add_action( 'wp_enqueue_scripts', 'wphx_enqueue_htmx' );
/**
 * Enqueues HTMX and injects the WP nonce into the X-WP-Nonce header.
 *
 * The HTMX script URL can be overridden via the 'wphx_htmx_src' filter,
 * e.g. to use a locally hosted copy or a pinned version:
 *
 *   add_filter( 'wphx_htmx_src', fn() => get_template_directory_uri() . '/js/htmx.min.js' );
 *
 * The nonce action is 'htmx'; it is read server-side as $_SERVER['HTTP_X_WP_NONCE'].
 */
function wphx_enqueue_htmx(): void {
	$default_src = 'https://cdn.jsdelivr.net/npm/htmx.org@next/dist/htmx.min.js';

	/**
	 * Filters the HTMX script URL.
	 *
	 * Override to use a locally hosted file or a specific/pinned version.
	 *
	 * @param string $src Default: htmx.org@next from jsDelivr CDN.
	 */
	$src = (string) apply_filters( 'wphx_htmx_src', $default_src );

	wp_enqueue_script(
		'htmx',
		$src,
		[],
		null,
		[ 'strategy' => 'defer' ]
	);

	$nonce = wp_create_nonce( 'htmx' );
	wp_add_inline_script(
		'htmx',
		// HTMX 4: event renamed to htmx:config:request, nonce in detail.ctx.request.headers
		'document.addEventListener("DOMContentLoaded", function () {
			document.body.addEventListener("htmx:config:request", function (event) {
				event.detail.ctx.request.headers["X-WP-Nonce"] = ' . wp_json_encode( $nonce ) . ';
			});
		});'
	);
}
