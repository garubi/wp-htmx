# WP HTMX

**Contributors:** garubi  
**Tags:** htmx, hypermedia, ajax, rest, spa  
**Requires at least:** 6.0  
**Tested up to:** 6.9.4  
**Requires PHP:** 8.1  
**Stable tag:** 0.1.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Integrates HTMX into WordPress with a two-layer router, automatic WP nonce injection, and a filterable script URL.

## Description

**WP HTMX** loads the [HTMX](https://htmx.org/) library into the WordPress frontend and provides a lightweight PHP router for HTMX requests, using HTMX 4 (`@next`).

### What it does

* **Enqueues HTMX** automatically on all frontend pages (CDN or your own copy via filter).
* **Injects the WP nonce** (`wp_create_nonce('htmx')`) into every HTMX request header (`X-WP-Nonce`) so WordPress REST/AJAX routes can authenticate requests.
* **Provides `is_htmx()`** — a simple helper to detect HTMX requests server-side.
* **Two-layer router** for serving partial HTML fragments:
  * **Layer 1 – Registered routes** (`template_redirect`): explicit routes with callbacks, permission checks, nonce verification, validation, and template resolution.
  * **Layer 2 – Convention fallback** (`template_include`): for GET requests, auto-loads `htmx/{post_type}/archive.php` or `single.php` from your theme with no registration needed.

### Filters

**`wphx_htmx_src`** — Override the HTMX script URL (e.g. local copy or pinned version):

```php
add_filter( 'wphx_htmx_src', fn() => get_template_directory_uri() . '/js/htmx.min.js' );
```

**`wphx_template_dir`** — Override the base directory for HTMX templates (default: `htmx`). This applies to **all** template lookups: convention fallback, theme defaults, plugin bundled fallbacks, and the explicit `template` arg on routes.

```php
add_filter( 'wphx_template_dir', fn() => 'partials/htmx' );
```

You can read the actual values returned by the filter in the **Site Healt** section of the WP Dashboard.

### Registering routes

Hook into `wphx_register_routes` and call `wphx_register_route()`:

```php
add_action( 'wphx_register_routes', function () {

    // Simple GET with auto WP_Query and convention template
    wphx_register_route( '/posts/', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
    ] );

    // POST with explicit callback and permission check
    // 'template' is relative to wphx_template_dir (default: htmx/)
    wphx_register_route( '/posts/', [
        'methods'             => 'POST',
        'callback'            => 'my_create_post',
        'permission_callback' => fn() => current_user_can( 'publish_posts' ),
        'template'            => 'posts/row.php',
    ] );

    // Multiple methods on the same path
    wphx_register_route( '/posts/compact/', [
        [ 'methods' => 'GET',  'permission_callback' => '__return_true' ],
        [ 'methods' => 'POST', 'callback' => 'my_fn', 'template' => 'posts/compact/row.php' ],
    ] );
} );
```

### Convention fallback (Layer 2)

No registration needed for GET requests. Just create the template in your theme:

* `htmx/{post_type}/archive.php` — served for archive pages
* `htmx/{post_type}/single.php`  — served for single post pages

The standard `WP_Query` set by WordPress is already available in the template.

### Template resolution chain

For every request the router looks for a template in this order, stopping at the first match:

1. **Route `template` arg** — path relative to `{tpl_dir}`, e.g. `'posts/row.php'` resolves to `{tpl_dir}/posts/row.php`. A callable may return an absolute filesystem path to bypass `tpl_dir` entirely.
2. **Theme — specific with variant** — `{tpl_dir}/{resource}/{mode}-{variant}.php`
3. **Theme — specific** — `{tpl_dir}/{resource}/{mode}.php`
4. **Theme — default override** — `{tpl_dir}/defaults/{mode}.php` (override plugin defaults without touching individual post types)
5. **Plugin — bundled fallback** — `wp-htmx/defaults/{mode}.php` (always available, works in any theme)
6. **No template found** — returns 204 No Content (Layer 1) or lets WordPress render the normal page (Layer 2)

`{tpl_dir}` is the value of `wphx_template_dir` (default: `htmx`). It is the base for **all** lookups — both convention-based and explicit `template` args.

The same chain applies to Layer 1 registered routes, Layer 2 convention fallback, and the error template in `send_error()`.

### Customising default templates

The plugin ships with minimal, class-agnostic defaults (`error.php`, `archive.php`, `single.php`) that use the CSS classes `wphx-error`, `wphx-empty`, `wphx-list`, `wphx-single`. You can style or replace them without editing the plugin:

* **Override all defaults at once** — copy `wp-htmx/defaults/` into your theme as `htmx/defaults/`. The plugin bundled templates will never be reached.
* **Override only one mode** — e.g. create `{theme}/htmx/defaults/archive.php`. The plugin fallback is used only for the other modes.
* **Override for a specific post type** — create `{theme}/htmx/{post_type}/archive.php` (or `single.php`). This takes priority over both the theme default and the plugin bundled template.

### Template variables

Inside any HTMX template the `$request` variable (`WPHX_Request`) is available:

```php
$posts  = $request->get_result();   // WP_Query, WP_Post, or custom callback result
$search = $request->get_param( 's' );

// HTMX response header helpers
$request->hx_trigger( 'refreshCount' );
$request->hx_push_url( '/posts/' );
```

## Installation

1. Upload the `wp-htmx` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Add `htmx/` templates to your theme and register routes via the `wphx_register_routes` action.

## Frequently Asked Questions

### Does it work with HTMX 1.x / 2.x?

The plugin targets HTMX 4 (`@next`) from jsDelivr. HTMX 4 changed the nonce event to `htmx:config:request`. Older versions used `htmx:configRequest` — if you need compatibility, use the `wphx_htmx_src` filter to point to an older version and add your own inline nonce script.

### How do I use a locally hosted HTMX file?

```php
add_filter( 'wphx_htmx_src', function() {
    return get_template_directory_uri() . '/js/htmx.min.js';
} );
```

### How is the WP nonce verified server-side?

The plugin injects the nonce into `X-WP-Nonce` on every HTMX request. Server-side, read it with:

```php
wp_verify_nonce( $_SERVER['HTTP_X_WP_NONCE'] ?? '', 'htmx' )
```

The router does this automatically for POST and DELETE routes.

## Changelog

### 0.1.0

* Initial release: asset loader, `is_htmx()` helper, two-layer router (`WPHX_Router`, `WPHX_Request`).

## Upgrade Notice

### 0.1.0

Initial release.
