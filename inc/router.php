<?php
/**
 * WP HTMX — Router
 *
 * Two-layer architecture:
 *
 *  Layer 1 — Registered routes (template_redirect, priority 5)
 *    Handles explicit routes registered via wphx_register_route().
 *    Parses REQUEST_URI directly. Everything resolves here with exit().
 *    Supports GET, POST, DELETE (PUT/PATCH normalised to POST).
 *
 *  Layer 2 — Convention fallback (template_include, priority 5)
 *    Active only for GET, only if Layer 1 did not handle the request.
 *    Just create the file htmx/{post_type}/archive.php or single.php in your theme.
 *    WP_Query is already set by WordPress (native URLs).
 *
 * URL patterns:
 *   /posts/                   → resource=posts,   variant=null,    id=null
 *   /posts/compact/           → resource=posts,   variant=compact, id=null
 *   /posts/1234               → resource=posts,   variant=null,    id=1234
 *   /posts/compact/1234       → resource=posts,   variant=compact, id=1234
 *
 * DELETE:
 *   wp_trash_post($id)        — default (soft delete)
 *   wp_delete_post($id, true) — if force_delete=true as param, body or HX-Force-Delete header
 *
 * Template resolution:
 *   {tpl_dir} (default: 'htmx', filterable via 'wphx_template_dir') is the base for ALL lookups.
 *   Priority chain (id present → single, absent → archive):
 *     1. Explicit 'template' arg — path relative to {tpl_dir}, resolved with locate_template()
 *        (callable variant may return an absolute path and bypass tpl_dir)
 *     2. Theme — {tpl_dir}/{resource}/{mode}-{variant}.php  (specific, with variant)
 *     3. Theme — {tpl_dir}/{resource}/{mode}.php             (specific)
 *     4. Theme — {tpl_dir}/defaults/{mode}.php               (theme overrides of plugin defaults)
 *     5. Plugin — wp-htmx/defaults/{mode}.php                (bundled fallback, always available)
 *     6. null → 204 No Content (Layer 1) or original WP template (Layer 2)
 *
 *   To override plugin defaults without writing a full specific template,
 *   copy wp-htmx/defaults/ into your theme as {tpl_dir}/defaults/ and edit the copies.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Register an HTMX route.
 *
 * Single method:
 *   wphx_register_route( '/posts/compact/', [
 *       'methods'             => 'GET',
 *       'callback'            => null,            // auto-dispatch (WP_Query)
 *       'template'            => null,            // auto fallback: htmx/posts/archive-compact.php
 *       'error_template'      => null,            // template for inline errors (e.g. form with per-field messages)
 *       'permission_callback' => fn() => is_user_logged_in(),
 *       'sanitize_callback'   => null,
 *       'validate_callback'   => null,
 *   ] );
 *
 * Multiple methods on the same path (register_rest_route style):
 *   wphx_register_route( '/posts/', [
 *       [ 'methods' => 'GET',  'permission_callback' => fn() => is_user_logged_in() ],
 *       [ 'methods' => 'POST', 'callback' => 'my_create_fn', ... ],
 *   ] );
 *
 * validate_callback contract:
 *   Receives WPHX_Request, must return:
 *     true       → validation passed, continues
 *     false      → generic error "Invalid data." (HTTP 400)
 *     WP_Error   → error with per-field messages (HTTP 400); if error_template is set
 *                  the full WP_Error is available in the template via $request->get_result()
 *
 *   Recommended pattern — aggregate per-field errors:
 *
 *   'validate_callback' => function( WPHX_Request $request ): true|WP_Error {
 *       $errors = new WP_Error();
 *
 *       foreach ( [ 'title', 'content' ] as $field ) {
 *           if ( empty( $request->get_param( $field ) ) ) {
 *               $errors->add( $field, "Field «{$field}» is required." );
 *           }
 *       }
 *
 *       return $errors->has_errors() ? $errors : true;
 *   },
 *
 * error_template — inline form validation (Hypermedia Systems pattern):
 *   If set, included instead of htmx/defaults/error.php when validation fails.
 *   Usually the same template as the form: the form controls its own swap
 *   (hx-swap="outerHTML") so the server does NOT send HX-Reswap.
 *   The full WP_Error with per-field messages is in $request->get_result().
 *   User-submitted values are in $request->get_param('field') for pre-filling.
 *
 *   Route:
 *   wphx_register_route( '/users/create/', [
 *       'methods'           => 'POST',
 *       'template'          => 'htmx/users/form.php',
 *       'error_template'    => 'htmx/users/form.php',
 *       'validate_callback' => function( WPHX_Request $request ): true|WP_Error {
 *           $errors = new WP_Error();
 *           if ( username_exists( $request->get_param( 'username' ) ) ) {
 *               $errors->add( 'username', 'Username already taken.' );
 *           }
 *           if ( strlen( (string) $request->get_param( 'password' ) ) < 8 ) {
 *               $errors->add( 'password', 'Password too short (min 8 chars).' );
 *           }
 *           return $errors->has_errors() ? $errors : true;
 *       },
 *   ] );
 *
 *   Template (htmx/users/form.php):
 *   <?php $errors = $request->get_result() instanceof WP_Error ? $request->get_result() : null; ?>
 *   <form hx-post="/users/create/" hx-target="this" hx-swap="outerHTML">
 *       <div class="form-group <?= $errors?->get_error_message('username') ? 'has-error' : '' ?>">
 *           <label>Username</label>
 *           <input type="text" name="username"
 *                  value="<?= esc_attr( $request->get_param( 'username' ) ?? '' ) ?>">
 *           <?php if ( $msg = $errors?->get_error_message( 'username' ) ): ?>
 *               <span class="help-block"><?= esc_html( $msg ) ?></span>
 *           <?php endif; ?>
 *       </div>
 *   </form>
 *
 * active validation — single field in real time:
 *   Does not need error_template. The callback must NOT return WP_Error:
 *   use HTTP 200 to swap inline feedback without interference.
 *   The template decides what to display based on $request->get_result().
 *
 *   wphx_register_route( '/validate/email/', [
 *       'methods'  => 'POST',
 *       'callback' => function( WPHX_Request $request ) {
 *           $email = $request->get_param( 'email' );
 *           if ( ! is_email( $email ) )   return 'Invalid email.';
 *           if ( email_exists( $email ) ) return 'Email already registered.';
 *           return null;
 *       },
 *       'template' => 'htmx/validation/email.php',
 *   ] );
 *
 *   HTML:
 *   <input type="email" name="email"
 *          hx-post="/validate/email/" hx-trigger="change delay:300ms"
 *          hx-target="#email-feedback" hx-swap="innerHTML">
 *   <span id="email-feedback"></span>
 *
 * @param string $route_path  E.g. '/posts/compact/' (leading slash required).
 * @param array  $args        Handler arguments or array of handlers (multi-method).
 */
function wphx_register_route( string $route_path, array $args ): void {
	WPHX_Router::instance()->register( $route_path, $args );
}

// ─── WPHX_Request ────────────────────────────────────────────────────────────

/**
 * Represents the current HTMX request (analogous to WP_REST_Request).
 */
class WPHX_Request {

	private string  $method;
	private string  $resource;
	private ?string $variant;
	private ?int    $id;
	private array   $params;
	private array   $response_headers = [];
	private mixed   $callback_result  = null;

	public function __construct( string $method, string $resource, ?string $variant, ?int $id ) {
		$this->method   = $method;
		$this->resource = $resource;
		$this->variant  = $variant;
		$this->id       = $id;
		$this->params   = $this->build_params();
	}

	/**
	 * Builds the params array by merging query string and request body.
	 */
	private function build_params(): array {
		$params = [];

		foreach ( $_GET as $k => $v ) {
			$params[ sanitize_key( $k ) ] = $v;
		}

		$content_type = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) );
		if ( str_contains( $content_type, 'application/json' ) ) {
			$raw  = file_get_contents( 'php://input' );
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				foreach ( $json as $k => $v ) {
					$params[ sanitize_key( $k ) ] = $v;
				}
			}
		} else {
			foreach ( $_POST as $k => $v ) {
				$params[ sanitize_key( $k ) ] = $v;
			}
		}

		return $params;
	}

	public function get_method(): string   { return $this->method; }
	public function get_resource(): string { return $this->resource; }
	public function get_variant(): ?string { return $this->variant; }
	public function get_id(): ?int         { return $this->id; }
	public function get_params(): array    { return $this->params; }

	public function get_param( string $key, mixed $default = null ): mixed {
		return $this->params[ $key ] ?? $default;
	}

	/**
	 * Reads a request header.
	 * E.g. get_header('HX-Target') reads $_SERVER['HTTP_HX_TARGET'].
	 */
	public function get_header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return isset( $_SERVER[ $key ] )
			? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) )
			: null;
	}

	/**
	 * Sets a response header to send before the template.
	 */
	public function set_response_header( string $name, string $value ): void {
		$this->response_headers[ $name ] = $value;
	}

	public function get_response_headers(): array { return $this->response_headers; }

	public function set_result( mixed $result ): void { $this->callback_result = $result; }

	/**
	 * Returns the callback result, available in the template.
	 */
	public function get_result(): mixed { return $this->callback_result; }

	/**
	 * Checks if force_delete is requested (param, body or HX-Force-Delete header).
	 */
	public function is_force_delete(): bool {
		$param = $this->get_param( 'force_delete' );
		if ( null !== $param ) {
			return filter_var( $param, FILTER_VALIDATE_BOOLEAN );
		}
		return strtolower( $this->get_header( 'HX-Force-Delete' ) ?? '' ) === 'true';
	}

	// ── HTMX 4: request header readers ───────────────────────────────────────────

	/**
	 * True if the request comes from an element with hx-boost.
	 * Header: HX-Boosted
	 */
	public function is_boosted(): bool {
		return $this->get_header( 'HX-Boosted' ) === 'true';
	}

	/**
	 * True if the request is a browser history restore.
	 * Header: HX-History-Restore-Request
	 */
	public function is_history_restore(): bool {
		return $this->get_header( 'HX-History-Restore-Request' ) === 'true';
	}

	/**
	 * Current browser URL at the time of the request.
	 * Header: HX-Current-URL
	 */
	public function get_current_url(): ?string {
		return $this->get_header( 'HX-Current-URL' );
	}

	/**
	 * Source element that triggered the request (format: tagName#id, e.g. button#submit).
	 * Header: HX-Source (replaces HX-Trigger as request header in HTMX 4).
	 */
	public function get_source(): ?string {
		return $this->get_header( 'HX-Source' );
	}

	/**
	 * Response target (format: tagName#id).
	 * Header: HX-Target
	 */
	public function get_target(): ?string {
		return $this->get_header( 'HX-Target' );
	}

	/**
	 * Request type: "full" (boost/navigation) or "partial" (normal HTMX request).
	 * Header: HX-Request-Type (new in HTMX 4)
	 */
	public function get_request_type(): ?string {
		return $this->get_header( 'HX-Request-Type' );
	}

	/**
	 * True if this is a "partial" request (normal HTMX, not boost).
	 * Falls back to true if the header is absent (compatibility).
	 */
	public function is_partial(): bool {
		$type = $this->get_request_type();
		return null === $type || 'partial' === $type;
	}

	// ── HTMX 4: response header helpers ──────────────────────────────────────────

	/**
	 * Triggers client-side events after swap.
	 * Header: HX-Trigger
	 *
	 * @param string|array $events  Event name, CSV, or array ['event' => data].
	 *
	 * @example $request->hx_trigger('refreshList');
	 * @example $request->hx_trigger(['showMessage' => 'Saved!']);
	 */
	public function hx_trigger( string|array $events ): void {
		$value = is_array( $events ) ? wp_json_encode( $events ) : $events;
		$this->set_response_header( 'HX-Trigger', $value );
	}

	/**
	 * AJAX navigation to a new URL (without full page reload).
	 * Header: HX-Location
	 *
	 * @param string $url     Destination URL.
	 * @param array  $config  Options: target, swap, select, values, headers, ...
	 */
	public function hx_location( string $url, array $config = [] ): void {
		if ( ! empty( $config ) ) {
			$config['path'] = $url;
			$this->set_response_header( 'HX-Location', wp_json_encode( $config ) );
		} else {
			$this->set_response_header( 'HX-Location', $url );
		}
	}

	/**
	 * Client-side redirect with full page reload.
	 * Header: HX-Redirect
	 *
	 * @param string $url  Destination URL.
	 */
	public function hx_redirect( string $url ): void {
		$this->set_response_header( 'HX-Redirect', $url );
	}

	/**
	 * Forces a full page reload.
	 * Header: HX-Refresh: true
	 */
	public function hx_refresh(): void {
		$this->set_response_header( 'HX-Refresh', 'true' );
	}

	/**
	 * Pushes a URL into the browser history (pushState).
	 * Header: HX-Push-Url
	 *
	 * @param string|false $url  URL to push, or false to disable.
	 */
	public function hx_push_url( string|false $url ): void {
		$this->set_response_header( 'HX-Push-Url', false === $url ? 'false' : $url );
	}

	/**
	 * Replaces the current browser history entry (replaceState).
	 * Header: HX-Replace-Url
	 *
	 * @param string|false $url  URL to set, or false to disable.
	 */
	public function hx_replace_url( string|false $url ): void {
		$this->set_response_header( 'HX-Replace-Url', false === $url ? 'false' : $url );
	}

	/**
	 * Overrides the swap target from the server response.
	 * Header: HX-Retarget
	 *
	 * @param string $selector  CSS selector for the new target.
	 */
	public function hx_retarget( string $selector ): void {
		$this->set_response_header( 'HX-Retarget', $selector );
	}

	/**
	 * Overrides the swap style.
	 * Header: HX-Reswap
	 *
	 * @param string $swap_style  E.g. 'innerHTML', 'outerHTML', 'beforeend', 'delete', 'innerMorph'.
	 */
	public function hx_reswap( string $swap_style ): void {
		$this->set_response_header( 'HX-Reswap', $swap_style );
	}

	/**
	 * Overrides the content selection from the response.
	 * Header: HX-Reselect
	 *
	 * @param string $selector  CSS selector of the fragment to use.
	 */
	public function hx_reselect( string $selector ): void {
		$this->set_response_header( 'HX-Reselect', $selector );
	}
}

// ─── WPHX_Router ─────────────────────────────────────────────────────────────

/**
 * HTMX Router. Singleton managing Layer 1 and Layer 2.
 */
class WPHX_Router {

	/**
	 * True after Layer 1 has handled the request.
	 * Layer 2 (template_include) uses this flag to skip.
	 */
	public static bool $handled = false;

	private static ?self $instance = null;

	/**
	 * Registered routes.
	 * Structure: [ 'posts/compact' => [ 'GET' => [...args], 'POST' => [...args] ] ]
	 */
	private array $routes = [];

	private function __construct() {
		add_action( 'template_redirect', [ $this, 'serve_request' ], 5 );
		add_filter( 'template_include',  [ $this, 'convention_fallback' ], 5 );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Registration ──────────────────────────────────────────────────────────

	public function register( string $route_path, array $args ): void {
		// Normalise: '/posts/compact/' → 'posts/compact'
		$route_key = trim( $route_path, '/' );

		// Supports both a single handler and an array of handlers (multi-method)
		$handlers = isset( $args[0] ) && is_array( $args[0] ) ? $args : [ $args ];

		foreach ( $handlers as $handler ) {
			$method = $this->normalize_method( $handler['methods'] ?? 'GET' );
			$this->routes[ $route_key ][ $method ] = [
				'callback'            => $handler['callback']            ?? null,
				'template'            => $handler['template']            ?? null,
				'error_template'      => $handler['error_template']      ?? null,
				'permission_callback' => $handler['permission_callback'] ?? '__return_true',
				'sanitize_callback'   => $handler['sanitize_callback']   ?? null,
				'validate_callback'   => $handler['validate_callback']   ?? null,
			];
		}
	}

	// ── Layer 1: template_redirect ────────────────────────────────────────────

	public function serve_request(): void {
		if ( ! is_htmx() ) return;

		$uri    = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$parsed = $this->parse_uri( $uri );
		if ( ! $parsed ) return;

		[ 'resource' => $resource, 'variant' => $variant, 'id' => $id ] = $parsed;

		$method = $this->normalize_method( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$route  = $this->find_route( $resource, $variant, $method );
		if ( ! $route ) return; // no registered route → Layer 2 or normal WP

		$request = new WPHX_Request( $method, $resource, $variant, $id );

		// Nonce for mutating methods
		if ( in_array( $method, [ 'POST', 'DELETE' ], true ) ) {
			if ( ! $this->verify_nonce() ) {
				self::$handled = true;
				$this->send_error( 403, __( 'Invalid nonce. Please refresh the page and try again.', 'wp-htmx' ), $request );
				return;
			}
		}

		// Permission check
		if ( ! call_user_func( $route['permission_callback'], $request ) ) {
			self::$handled = true;
			$this->send_error( 403, __( 'You do not have permission to perform this action.', 'wp-htmx' ), $request );
			return;
		}

		// Validation
		// The callback must return true (ok), WP_Error (per-field messages), or false (generic error).
		if ( $route['validate_callback'] ) {
			$valid = call_user_func( $route['validate_callback'], $request );
			if ( is_wp_error( $valid ) ) {
				self::$handled = true;
				$this->send_error( 400, $valid, $request, $route['error_template'] );
				return;
			}
			if ( false === $valid ) {
				self::$handled = true;
				$this->send_error( 400, __( 'Invalid data.', 'wp-htmx' ), $request, $route['error_template'] );
				return;
			}
		}

		// Sanitization
		if ( $route['sanitize_callback'] ) {
			call_user_func( $route['sanitize_callback'], $request );
		}

		// Explicit callback or auto-dispatch
		$callback = $route['callback'];
		$result   = $callback
			? call_user_func( $callback, $request )
			: $this->dispatch_auto( $request );

		if ( is_wp_error( $result ) ) {
			self::$handled = true;
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			$this->send_error( $status, $result, $request, $route['error_template'] );
			return;
		}

		$request->set_result( $result );

		// Resolve template
		$template_file = $this->resolve_template( $resource, $variant, $id, $route['template'], $request );

		if ( ! $template_file ) {
			// No template → 204 No Content
			self::$handled = true;
			status_header( 204 );
			foreach ( $request->get_response_headers() as $name => $value ) {
				header( "{$name}: {$value}" );
			}
			exit;
		}

		// Send response
		self::$handled = true;
		status_header( 200 );
		foreach ( $request->get_response_headers() as $name => $value ) {
			header( "{$name}: {$value}" );
		}
		// $request is available in the template via include scope
		include $template_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		exit;
	}

	// ── Layer 2: template_include ─────────────────────────────────────────────

	/**
	 * Convention fallback for GET HTMX requests without a registered route.
	 *
	 * Template lookup order:
	 *   1. Theme — {tpl_dir}/{post_type}/{mode}.php   (specific)
	 *   2. Theme — {tpl_dir}/defaults/{mode}.php      (theme defaults)
	 *   3. Plugin — wp-htmx/defaults/{mode}.php       (bundled)
	 *   4. Original WP template (no HTMX interception)
	 */
	public function convention_fallback( string $template ): string {
		if ( self::$handled ) return $template;
		if ( ! is_htmx() ) return $template;
		if ( 'GET' !== $this->normalize_method( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return $template;
		}

		$qo        = get_queried_object();
		$post_type = '';
		$mode      = 'archive';

		if ( $qo instanceof WP_Post ) {
			$post_type = $qo->post_type;
			$mode      = 'single';
		} elseif ( $qo instanceof WP_Post_Type ) {
			$post_type = $qo->name;
		} else {
			$post_type = get_query_var( 'post_type' ) ?: 'post';
		}

		if ( ! $post_type ) return $template;

		$tpl_dir        = trim( (string) apply_filters( 'wphx_template_dir', 'htmx' ), '/' );

		// 1. Specific theme template
		$convention_tpl = locate_template( "{$tpl_dir}/{$post_type}/{$mode}.php" );
		if ( $convention_tpl ) {
			self::$handled = true;
			return $convention_tpl;
		}

		// 2. Theme default template
		$default_tpl = locate_template( "{$tpl_dir}/defaults/{$mode}.php" );
		if ( $default_tpl ) {
			self::$handled = true;
			return $default_tpl;
		}

		// 3. Plugin bundled default template
		$plugin_tpl = $this->plugin_template( "{$mode}.php" );
		if ( $plugin_tpl ) {
			self::$handled = true;
			return $plugin_tpl;
		}

		return $template;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Parses REQUEST_URI into [resource, variant, id].
	 *
	 * /posts/                  → resource=posts,   variant=null,    id=null
	 * /posts/compact/          → resource=posts,   variant=compact, id=null
	 * /posts/1234              → resource=posts,   variant=null,    id=1234
	 * /posts/compact/1234      → resource=posts,   variant=compact, id=1234
	 *
	 * @return array{resource:string,variant:?string,id:?int}|null
	 */
	private function parse_uri( string $uri ): ?array {
		// Remove query string
		$path = strtok( $uri, '?' ) ?: '/';

		// Remove WP subdirectory install prefix if present
		$home_path = (string) parse_url( home_url(), PHP_URL_PATH );
		if ( $home_path && $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( empty( $segments ) ) return null;

		$resource = sanitize_key( $segments[0] );
		$id       = null;
		$variant  = null;

		// Trailing numeric segment is the ID
		$last = $segments[ count( $segments ) - 1 ];
		if ( count( $segments ) > 1 && ctype_digit( $last ) ) {
			$id     = (int) $last;
			$middle = array_slice( $segments, 1, count( $segments ) - 2 );
		} else {
			$middle = array_slice( $segments, 1 );
		}

		if ( ! empty( $middle ) ) {
			$variant = implode( '-', array_map( 'sanitize_key', $middle ) );
		}

		return compact( 'resource', 'variant', 'id' );
	}

	/**
	 * Finds a registered route for resource/variant/method.
	 *
	 * @return array|null  Handler arguments, or null if not found.
	 */
	private function find_route( string $resource, ?string $variant, string $method ): ?array {
		$route_key = $variant ? "{$resource}/{$variant}" : $resource;
		return $this->routes[ $route_key ][ $method ] ?? null;
	}

	/**
	 * Resolves the template file to include.
	 *
	 * wphx_template_dir is the base directory for ALL template lookups, including
	 * the explicit 'template' arg. Paths in 'template' are always relative to tpl_dir.
	 *
	 * Exception: a callable 'template' may return an absolute path (file_exists check),
	 * which is used as-is, bypassing tpl_dir.
	 *
	 * Hierarchy (id present → single, absent → archive):
	 *   1. Explicit 'template' arg  → {tpl_dir}/{template}  (tpl_dir-relative)
	 *   2. Theme — {tpl_dir}/{resource}/{mode}-{variant}.php  (specific, with variant)
	 *   3. Theme — {tpl_dir}/{resource}/{mode}.php             (specific)
	 *   4. Theme — {tpl_dir}/defaults/{mode}.php               (theme defaults)
	 *   5. Plugin — wp-htmx/defaults/{mode}.php                (bundled)
	 *   6. null → 204 No Content
	 */
	private function resolve_template(
		string               $resource,
		?string              $variant,
		?int                 $id,
		string|callable|null $explicit_tpl,
		WPHX_Request         $request
	): ?string {
		// wphx_template_dir is the base for ALL template lookups.
		$tpl_dir = trim( (string) apply_filters( 'wphx_template_dir', 'htmx' ), '/' );

		if ( $explicit_tpl ) {
			// Supports template as callable: fn(WPHX_Request): string|null
			if ( is_callable( $explicit_tpl ) ) {
				$explicit_tpl = (string) call_user_func( $explicit_tpl, $request );
				if ( ! $explicit_tpl ) return null;
				// Callable may return an absolute path — use directly in that case.
				if ( file_exists( $explicit_tpl ) ) return realpath( $explicit_tpl ) ?: $explicit_tpl;
			}
			if ( ! $explicit_tpl ) return null;
			// String template: resolved under tpl_dir (same root as all other templates).
			$found = locate_template( $tpl_dir . '/' . ltrim( $explicit_tpl, '/' ) );
			return $found ?: null;
		}

		$mode       = $id ? 'single' : 'archive';
		$candidates = [];

		if ( $variant ) {
			$candidates[] = "{$tpl_dir}/{$resource}/{$mode}-{$variant}.php";
		}
		$candidates[] = "{$tpl_dir}/{$resource}/{$mode}.php";
		$candidates[] = "{$tpl_dir}/defaults/{$mode}.php";

		foreach ( $candidates as $tpl ) {
			$found = locate_template( $tpl );
			if ( $found ) return $found;
		}

		// Final fallback: plugin bundled default templates
		return $this->plugin_template( "{$mode}.php" );
	}

	/**
	 * Auto-dispatch: performs WP operations based on method + id.
	 *
	 * GET    no-id  → new WP_Query(post_type + params)
	 * GET    id     → get_post(id)
	 * POST   no-id  → wp_insert_post(params)
	 * POST   id     → wp_update_post(id + params)
	 * DELETE id     → wp_trash_post(id) | wp_delete_post(id,true) if force_delete
	 * DELETE no-id  → WP_Error 400
	 */
	private function dispatch_auto( WPHX_Request $request ): mixed {
		$method   = $request->get_method();
		$resource = $request->get_resource();
		$id       = $request->get_id();
		$params   = $request->get_params();

		switch ( $method ) {

			case 'GET':
				if ( $id ) {
					$post = get_post( $id );
					if ( ! $post || $post->post_type !== $resource ) {
						return new WP_Error(
							'not_found',
							__( 'Item not found.', 'wp-htmx' ),
							[ 'status' => 404 ]
						);
					}
					return $post;
				}
				// Archive: WP_Query from GET params
				$query_args = array_merge(
					[ 'post_type' => $resource, 'posts_per_page' => 20 ],
					$params
				);
				$query = new WP_Query( $query_args );
				// Make the query global so standard template loops work
				$GLOBALS['wp_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				return $query;

			case 'POST':
				if ( $id ) {
					$result = wp_update_post(
						wp_slash( array_merge( $params, [ 'ID' => $id, 'post_type' => $resource ] ) ),
						true
					);
				} else {
					$result = wp_insert_post(
						wp_slash( array_merge( $params, [ 'post_type' => $resource ] ) ),
						true
					);
				}
				if ( is_wp_error( $result ) ) return $result;
				return get_post( $result );

			case 'DELETE':
				if ( ! $id ) {
					return new WP_Error(
						'missing_id',
						__( 'DELETE requires an ID in the URL.', 'wp-htmx' ),
						[ 'status' => 400 ]
					);
				}
				$post = get_post( $id );
				if ( ! $post || $post->post_type !== $resource ) {
					return new WP_Error(
						'not_found',
						__( 'Item not found.', 'wp-htmx' ),
						[ 'status' => 404 ]
					);
				}
				$result = $request->is_force_delete()
					? wp_delete_post( $id, true )
					: wp_trash_post( $id );

				if ( ! $result ) {
					return new WP_Error(
						'delete_failed',
						__( 'Unable to delete the item.', 'wp-htmx' ),
						[ 'status' => 500 ]
					);
				}
				return $result;
		}

		return new WP_Error(
			'unsupported_method',
			__( 'HTTP method not supported.', 'wp-htmx' ),
			[ 'status' => 405 ]
		);
	}

	/**
	 * Sends an error response (HTML fragment) and exits.
	 *
	 * If $error_template is set, it is included instead of htmx/defaults/error.php
	 * and HX-Reswap is NOT sent (the form controls its own swap via hx-swap).
	 * The full WP_Error (with all per-field codes) is available in the template
	 * via $request->get_result().
	 *
	 * In HTMX 4 4xx/5xx responses are already swapped by default.
	 * HX-Reswap: innerHTML (sent only for the generic template) explicitly sets
	 * the swap style and is still valid as a fallback for HTMX 2.x.
	 *
	 * @param int               $status          HTTP status code (e.g. 400, 403, 404).
	 * @param string|WP_Error   $error           Error message or WP_Error with per-field codes.
	 * @param WPHX_Request      $request         Current request.
	 * @param string|null       $error_template  Custom template instead of default (optional).
	 */
	private function send_error( int $status, string|WP_Error $error, WPHX_Request $request, ?string $error_template = null ): void {
		status_header( $status );

		// Normalise to WP_Error and store in request (available in template via $request->get_result())
		$wp_error = is_wp_error( $error ) ? $error : new WP_Error( 'error', $error );
		$request->set_result( $wp_error );

		$tpl_dir = trim( (string) apply_filters( 'wphx_template_dir', 'htmx' ), '/' );

		if ( $error_template ) {
			// Custom template (e.g. form with inline errors):
			// the form manages its own swap → do not force HX-Reswap
			$tpl = locate_template( $error_template )
				?: ( file_exists( $error_template ) ? $error_template : null );
		} else {
			// Generic template: force innerHTML into the same target
			header( 'HX-Reswap: innerHTML' );
			$tpl = locate_template( "{$tpl_dir}/defaults/error.php" )
				?: $this->plugin_template( 'error.php' );
		}

		if ( $tpl ) {
			include $tpl; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		} else {
			$message = $wp_error->get_error_message();
			echo '<p class="wphx-error">' . esc_html( $message ) . '</p>';
		}
		exit;
	}

	/**
	 * Returns the absolute path of a bundled plugin default template.
	 *
	 * Looks inside wp-htmx/defaults/. Returns null if the file does not exist.
	 *
	 * @param string $relative  Filename relative to wp-htmx/defaults/, e.g. 'error.php'.
	 * @return string|null  Absolute path, or null if not found.
	 */
	private function plugin_template( string $relative ): ?string {
		$path = plugin_dir_path( __FILE__ ) . '../defaults/' . ltrim( $relative, '/' );
		return file_exists( $path ) ? realpath( $path ) : null;
	}

	/**
	 * Normalises the HTTP method: PUT/PATCH → POST.
	 */
	private function normalize_method( string $method ): string {
		$method = strtoupper( sanitize_text_field( $method ) );
		return in_array( $method, [ 'PUT', 'PATCH' ], true ) ? 'POST' : $method;
	}

	/**
	 * Verifies the WP nonce sent by HTMX.
	 * Reads X-WP-Nonce (header) or _wpnonce (request param).
	 */
	private function verify_nonce(): bool {
		$nonce = null;

		if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}

		if ( null === $nonce ) {
			wp_set_current_user( 0 );
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'htmx' );
	}
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────

add_action( 'wp_loaded', function () {
	// Instantiate the router (registers template_redirect + template_include hooks)
	WPHX_Router::instance();
	// Allow plugins and themes to register their routes
	do_action( 'wphx_register_routes' );
} );
