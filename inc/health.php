<?php
/**
 * WP HTMX — Site Health info
 *
 * Adds a "WP HTMX" section to Tools → Site Health → Info
 * showing the runtime values of the two main plugin filters.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'debug_information', 'wphx_debug_information' );
/**
 * Adds WP HTMX runtime info to the Site Health → Info screen.
 *
 * @param array $info Existing debug sections.
 * @return array
 */
function wphx_debug_information( array $info ): array {
	$info['wp-htmx'] = [
		'label'      => __( 'WP HTMX', 'wp-htmx' ),
		'show_count' => false,
		'fields'     => [
			'htmx_src' => [
				'label' => __( 'HTMX script URL (wphx_htmx_src)', 'wp-htmx' ),
				'value' => (string) apply_filters( 'wphx_htmx_src', 'https://cdn.jsdelivr.net/npm/htmx.org@next/dist/htmx.min.js' ),
			],
			'htmx_template_dir' => [
				'label' => __( 'Template directory (wphx_template_dir)', 'wp-htmx' ),
				'value' => (string) apply_filters( 'wphx_template_dir', 'htmx' ),
			],
		],
	];

	return $info;
}
