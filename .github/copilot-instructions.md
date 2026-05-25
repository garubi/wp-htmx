# WordPress Plugin: WP HTMX

## Overview

**WP HTMX** integrates [HTMX 4](https://htmx.org/) (`@next`) into WordPress. It enqueues the HTMX library, injects a WP nonce into every HTMX request, and provides a two-layer PHP router for serving partial HTML fragments.

- **Plugin entry point**: [wp-htmx.php](../wp-htmx.php)
- **Full documentation**: [readme.txt.md](../readme.txt.md)

## Architecture

```
wp-htmx.php          ← Entry point, loads inc/ files
inc/
  assets.php         ← Enqueues HTMX, injects nonce via htmx:config:request event
  router.php         ← Two-layer router (WPHX_Router + WPHX_Request classes)
  health.php         ← Adds runtime filter values to WP Site Health → Info
defaults/
  archive.php        ← Bundled fallback template for collection responses
  single.php         ← Bundled fallback template for single-item responses
  error.php          ← Bundled fallback template for errors
```

## Two-Layer Router

**Layer 1 — Registered routes** (`template_redirect`, priority 5):
- Explicit routes registered via `wphx_register_route()` inside `wphx_register_routes` action.
- Handles GET, POST, DELETE (PUT/PATCH normalised to POST).
- Performs: nonce check (POST/DELETE), permission check, sanitize, validate, callback, template.

**Layer 2 — Convention fallback** (`template_include`, priority 5):
- Active only for GET requests not handled by Layer 1.
- No registration needed. Create `htmx/{post_type}/archive.php` or `single.php` in the theme.
- `WP_Query` is already set by WordPress (native URLs).

## URL Pattern

```
/posts/              → resource=posts, variant=null,    id=null  → archive mode
/posts/compact/      → resource=posts, variant=compact, id=null  → archive mode
/posts/1234          → resource=posts, variant=null,    id=1234  → single mode
/posts/compact/1234  → resource=posts, variant=compact, id=1234  → single mode
```

## Template Resolution Chain

`{tpl_dir}` defaults to `htmx`, overridable via `wphx_template_dir` filter. Applied to ALL lookups.

1. Route `template` arg (relative to `{tpl_dir}`, or callable returning absolute path)
2. Theme: `{tpl_dir}/{resource}/{mode}-{variant}.php`
3. Theme: `{tpl_dir}/{resource}/{mode}.php`
4. Theme: `{tpl_dir}/defaults/{mode}.php` (theme override of plugin defaults)
5. Plugin: `wp-htmx/defaults/{mode}.php` (always available)
6. `null` → 204 No Content (Layer 1) / original WP template (Layer 2)

## Public API

### `wphx_register_route( string $route_path, array $args )`

Register a route inside the `wphx_register_routes` action:

```php
add_action( 'wphx_register_routes', function () {

    // Simple GET — auto WP_Query + convention template
    wphx_register_route( '/posts/', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
    ] );

    // POST with validation and explicit template
    wphx_register_route( '/posts/', [
        'methods'             => 'POST',
        'callback'            => 'my_create_post',
        'permission_callback' => fn() => current_user_can( 'publish_posts' ),
        'template'            => 'posts/row.php',         // relative to tpl_dir
        'validate_callback'   => function( WPHX_Request $request ): true|WP_Error {
            $errors = new WP_Error();
            if ( empty( $request->get_param( 'title' ) ) ) {
                $errors->add( 'title', 'Title is required.' );
            }
            return $errors->has_errors() ? $errors : true;
        },
    ] );
} );
```

**Handler args**:

| Key | Default | Description |
|-----|---------|-------------|
| `methods` | `'GET'` | HTTP method(s) |
| `callback` | `null` | Callable receiving `WPHX_Request`; return value stored in `$request->get_result()` |
| `template` | `null` | Path relative to `{tpl_dir}`. Falls back to convention chain if omitted. |
| `error_template` | `null` | Template included when `validate_callback` returns `WP_Error` |
| `permission_callback` | `'__return_true'` | Must return bool |
| `sanitize_callback` | `null` | Receives `WPHX_Request` |
| `validate_callback` | `null` | Receives `WPHX_Request`, returns `true\|false\|WP_Error` |

### `is_htmx(): bool`

Returns `true` if the current request was sent by HTMX (`HX-Request` header present).

### `WPHX_Request`

Available as `$request` in templates and callbacks:

```php
$request->get_param( 'key', $default );  // merged GET + POST/JSON body
$request->get_result();                  // return value of callback
$request->get_header( 'HX-Target' );    // read any request header
$request->get_id();                      // numeric id from URL (or null)
$request->get_variant();                 // variant from URL (or null)

// Response header helpers (HTMX 4)
$request->hx_trigger( 'refreshList' );
$request->hx_trigger( ['showMessage' => 'Saved!'] );
$request->hx_redirect( '/some-url/' );
$request->hx_refresh();
$request->hx_push_url( '/new-url/' );
$request->hx_location( '/url/', ['target' => '#content'] );
$request->hx_reswap( 'outerHTML' );
$request->hx_retarget( '#my-list' );
$request->set_response_header( 'X-Custom', 'value' );
```

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `wphx_htmx_src` | jsDelivr CDN `htmx.org@next` | Override HTMX script URL |
| `wphx_template_dir` | `'htmx'` | Base directory for all template lookups |

Check runtime values at **Tools → Site Health → Info → WP HTMX**.

## Nonce Handling

- Nonce action: `'htmx'`
- Automatically injected as `X-WP-Nonce` header on every HTMX request (via `htmx:config:request` DOM event).
- Layer 1 verifies nonce for POST and DELETE requests. Verification skipped for GET.
- Server-side read: `$_SERVER['HTTP_X_WP_NONCE']` (handled internally by the router).

## DELETE requests

`wp_trash_post($id)` by default (soft delete). Add `force_delete=true` as param or `HX-Force-Delete: true` header for hard delete (`wp_delete_post($id, true)`). Check with `$request->is_force_delete()`.

## Coding Standards

- **PHP 8.1+** required, **WordPress 6.0+**
- WordPress Coding Standards (PHPCS): tabs, snake_case, `wphx_` prefix on all globals
- All output must be escaped: `esc_html()`, `esc_attr()`, `esc_url()`
- Check `if ( ! defined( 'ABSPATH' ) ) exit;` at top of every file

## Common Patterns

### Form with inline validation (Hypermedia Systems pattern)

Use `error_template` pointing to the same form template; the form controls its own swap (`hx-swap="outerHTML"`):

```php
wphx_register_route( '/users/create/', [
    'methods'           => 'POST',
    'template'          => 'users/form.php',
    'error_template'    => 'users/form.php',
    'validate_callback' => function( WPHX_Request $request ): true|WP_Error {
        $errors = new WP_Error();
        if ( username_exists( $request->get_param( 'username' ) ) ) {
            $errors->add( 'username', 'Username already taken.' );
        }
        return $errors->has_errors() ? $errors : true;
    },
] );
```

Template accesses errors via `$request->get_result()` which is a `WP_Error` on validation failure.

### Real-time single-field validation

Do NOT return `WP_Error` from `validate_callback` for active validation — use the `callback` directly and return your own value; the template renders feedback based on `$request->get_result()`.

## HTMX 4 Compatibility — Important

> **Ogni volta che generi o citi esempi, pattern o snippet HTMX, verifica che siano aggiornati per HTMX 4 o successivo.**
> Se non lo sono, aggiornali seguendo la [guida di migrazione ufficiale](https://four.htmx.org/docs/get-started/migration).

Questo plugin usa `htmx.org@next` (HTMX 4). Le differenze rispetto a v1/v2 più rilevanti per questo codebase:
- L'evento per iniettare headers è `htmx:config:request` (non `htmx:configRequest`)
- L'header di request per l'elemento sorgente è `HX-Source` (non `HX-Trigger`)
- Il campo per i headers nell'evento è `event.detail.ctx.request.headers`

## Gotchas

1. **Layer 2 has no `$request` variable** — use `$GLOBALS['wp_query']` in convention fallback templates.
2. **`wphx_template_dir` filter is global** — it applies to ALL lookups, including explicit `template` args.
3. **HTMX 4 breaking change** — nonce injection uses `htmx:config:request` event (not `htmx:configRequest` from v1/v2); `HX-Source` replaces `HX-Trigger` as the request header for the source element.
4. **No build step** — plain PHP/JS, no webpack or npm. `composer.json` only for `wp i18n make-pot`.
5. **Default CSS classes** — bundled templates use `wphx-error`, `wphx-empty`, `wphx-list`, `wphx-single`. Override templates in `{tpl_dir}/defaults/` to avoid touching the plugin.
