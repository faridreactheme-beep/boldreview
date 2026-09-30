# WordPress Core API Reference

The API surface a senior dev reaches for. Exact names, signatures, and the gotchas that bite.
Always prefer a core API over hand-rolling; core handles caching, escaping, hooks, and
multisite correctly.

## Hooks — actions & filters (the heart of WordPress)

- **Actions** *do* something (side effects): `add_action( $hook, $callback, $priority = 10, $accepted_args = 1 )`,
  fired with `do_action()`. Callbacks return nothing.
- **Filters** *transform* a value and **must return it**: `add_filter( … )`, applied with
  `apply_filters( $hook, $value, …$args )`. Forgetting to `return` is the #1 filter bug.
- **Priority** orders callbacks (lower runs first; default 10). **`$accepted_args`** must
  match how many args you read — a common bug is reading `$post_id` but registering with the
  default `1` accepted arg.
- **Remove/replace:** `remove_action`/`remove_filter` must use the *same callback identity &
  priority*. Closures and dynamic class instances are hard to remove — register named methods
  if you may need to unhook.
- **Expose your own** extension points with `do_action`/`apply_filters` and **document them
  with PHPDoc** (`@since`, `@param` per arg). Once shipped, a hook name + arg order is a
  **stable public API** — rename only via deprecation, never break consumers.
- **Lifecycle order (memorize):** `muplugins_loaded` → `plugins_loaded` → `init` →
  `wp_loaded` → (request) `template_redirect` → `wp` → `wp_head`/`wp_enqueue_scripts` →
  loop → `wp_footer` → `shutdown`. Admin: `admin_init`, `admin_menu`, `admin_enqueue_scripts`.
  **Do translatable/registration work on `init`**, not earlier.

## Options API — small site-wide settings

```php
get_option( $name, $default );
add_option( $name, $value, '', $autoload );      // $autoload: 'yes'|'no' (or true/false WP 6.6+)
update_option( $name, $value, $autoload );        // creates if missing
delete_option( $name );
```

- **Autoload discipline:** autoloaded options load on **every** request. Store big/rarely-used
  data with `autoload = false`. (WP 6.6 added explicit autoload handling + the
  `wp_autoload_values_to_autoload` filter.) One bloated autoloaded option is a classic
  site-wide perf bug.
- **One array option > many rows** for a related settings group. Sanitize each key.
- Multisite-wide: `get_site_option`/`update_site_option` (network level).

## Settings API — admin settings pages done right

```php
register_setting( 'mygroup', 'myplugin_settings', array(
    'type'              => 'array',
    'sanitize_callback' => 'myplugin_sanitize_settings',  // sanitize EVERY field here
    'default'           => array(),
) );
add_settings_section( 'main', __( 'Main', 'td' ), '__return_false', 'myplugin' );
add_settings_field( 'api_key', __( 'API Key', 'td' ), 'myplugin_field_api_key', 'myplugin', 'main' );
```
Render the form with `settings_fields( 'mygroup' )` + `do_settings_sections( 'myplugin' )`
(this also emits the nonce). Capability-gate the page (`manage_options`). The
`sanitize_callback` is your single choke point — sanitize/validate each field there.

## Transients & object cache — caching layer

```php
set_transient( $key, $value, HOUR_IN_SECONDS );  // also MINUTE_/DAY_/WEEK_IN_SECONDS
get_transient( $key );                            // false if missing/expired
delete_transient( $key );
```

- Transients are persistent (DB) unless a persistent object cache (Redis/Memcached) is
  installed — then they live there. Multisite-wide: `*_site_transient`.
- For request-scoped or cache-plugin-backed values, the **object cache**:
  `wp_cache_get/set/delete( $key, $group, $expire )`. Non-persistent without a backend (lives
  one request) — fine for de-duping within a request.
- **Cache-and-rebuild pattern:** check transient → if false, compute → `set_transient` →
  return. Invalidate on the write that changes the underlying data. Never cache per-user data
  under a shared key.

## Data modelling — choose the right store

| Need | Use |
|---|---|
| A handful of scalar settings | one **option** (array), autoload off if large |
| Editable, listable, queryable content with an editor UI | **Custom Post Type** + post meta |
| Categorization / relationships | **Custom Taxonomy** |
| A few fields attached to a post/user/term | **meta** (`*_post_meta`, `register_post_meta`) |
| High-volume, relational, heavily-queried records (entries, logs, line items) | **Custom table** (`dbDelta`, indexes, prepared queries) |
| Expensive, expiring computed data | **Transient** / object cache |

### Custom Post Types & taxonomies
```php
register_post_type( 'book', array(
    'public'       => true,
    'show_in_rest' => true,            // REQUIRED for the block editor & REST
    'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
    'has_archive'  => true,
    'rewrite'      => array( 'slug' => 'books' ),
    'menu_icon'    => 'dashicons-book',
    'capability_type' => 'post',       // map_meta_cap => true for granular caps
) );
register_taxonomy( 'genre', 'book', array( 'public' => true, 'show_in_rest' => true, 'hierarchical' => true ) );
```
Register on `init`. Flush rewrite rules **only on activation** (not every load).
`show_in_rest => true` is mandatory for Gutenberg support.

### Meta — register it (don't just `update_post_meta` blindly)
```php
register_post_meta( 'book', 'isbn', array(
    'type'              => 'string',
    'single'            => true,
    'show_in_rest'      => true,        // exposes to block editor / REST
    'sanitize_callback' => 'sanitize_text_field',
    'auth_callback'     => function() { return current_user_can( 'edit_posts' ); },
) );
```
`get_post_meta( $id, $key, true )` (single) / `update_post_meta` / `delete_post_meta`.
Same pattern for `register_term_meta`, `register_user_meta`. Registering meta gives you
sanitization, REST exposure, and auth in one place.

### Custom tables
- Define schema in an activation routine with `dbDelta()`; store a `db_version` option and
  run upgrades when it changes. Add proper **indexes** for your query columns.
- Always `$wpdb->prepare()`; reference the name via `$wpdb->prefix . 'my_table'`.
- Custom tables don't get WP's CPT UI, caps, revisions, or REST for free — you build those.

## WP_Query & the loop — query content correctly

```php
$q = new WP_Query( array(
    'post_type'      => 'book',
    'posts_per_page' => 20,
    'no_found_rows'  => true,     // skip pagination count when you don't need it
    'fields'         => 'ids',    // when you only need IDs (lighter)
    'post_status'    => 'publish',
) );
```
- Modify the **main** query with `pre_get_posts` (guard `! is_admin() && $q->is_main_query()`),
  never `query_posts()` (it clobbers the main query).
- Always `wp_reset_postdata()` after a secondary loop.
- Avoid `posts_per_page => -1` on unbounded data; avoid uncached `meta_query`/`tax_query` on
  hot paths (they're slow — cache results in a transient).
- Conditional tags only work after the main query: `is_singular`, `is_home`, `is_front_page`,
  `is_archive`, `is_tax`, `is_page`, `is_single`, `is_search`, `is_404`.

## REST API — first-class read/write surface

```php
add_action( 'rest_api_init', function() {
    register_rest_route( 'myplugin/v1', '/things/(?P<id>\d+)', array(
        'methods'             => WP_REST_Server::READABLE,      // GET; CREATABLE=POST, EDITABLE, DELETABLE
        'callback'            => 'myplugin_get_thing',
        'permission_callback' => function( $req ) {             // NEVER omit this
            return current_user_can( 'read' );
        },
        'args'                => array(
            'id' => array(
                'required'          => true,
                'sanitize_callback' => 'absint',
                'validate_callback' => function( $v ) { return is_numeric( $v ); },
            ),
        ),
    ) );
} );
```
- **`permission_callback` is mandatory** (WP warns if missing). Return `true` only for
  genuinely public reads; otherwise check a capability. This — not the nonce — is the real
  authorization gate. Logged-in JS calls send the `wp_rest` nonce in `X-WP-Nonce`.
- Return `WP_REST_Response` / `rest_ensure_response()`; errors as `WP_Error` with a status.
- Use `args` `sanitize_callback`/`validate_callback` so input is clean before your callback.
- Prefer **registered post-meta with `show_in_rest`** over custom routes when the data hangs
  off a post — you get the endpoint for free.
- **Authentication methods:** logged-in browser JS → **cookie + `wp_rest` nonce** (`X-WP-Nonce`).
  External/headless clients → **Application Passwords** (core since 5.6; per-user, revocable,
  sent as HTTP Basic auth over HTTPS) — the standard for server-to-server. OAuth/JWT need a
  plugin. Whichever the client uses, your `permission_callback` still does the authorization.
  The block-editor JS layer (`@wordpress/api-fetch`, entity records) is covered in
  `reference/blocks-react.md`.

## Asset pipeline — enqueue, never inline

```php
add_action( 'wp_enqueue_scripts', function() {                 // front-end
    wp_enqueue_style( 'myplugin', plugins_url( 'build/style.css', __FILE__ ), array(), $ver );
    wp_enqueue_script( 'myplugin', plugins_url( 'build/app.js', __FILE__ ), array( 'wp-element' ), $ver, array( 'in_footer' => true ) );
    wp_set_script_translations( 'myplugin', 'myplugin', plugin_dir_path( __FILE__ ) . 'languages' );
    wp_localize_script( 'myplugin', 'MyPluginData', array( 'restUrl' => esc_url_raw( rest_url() ), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
} );
```
- Right hook: `wp_enqueue_scripts` (front), `admin_enqueue_scripts` (admin, takes `$hook` —
  load conditionally for your page only), `enqueue_block_editor_assets` (block editor),
  `enqueue_block_assets` (editor + front).
- **Always** pass deps + a real `$ver` (constant or `filemtime`) for cache-busting. Load
  **conditionally** — never enqueue everything globally.
- Pass data to JS with `wp_localize_script` or `wp_add_inline_script` (or, with wp-scripts,
  generated `*.asset.php` deps). Never echo `<script>` blocks with PHP-interpolated values.
- WP 6.5+ has the **Script Modules API** (`wp_register_script_module`/`wp_enqueue_script_module`)
  for ESM and the Interactivity API.

## WP-Cron & background work

```php
if ( ! wp_next_scheduled( 'myplugin_daily' ) ) {
    wp_schedule_event( time(), 'daily', 'myplugin_daily' );    // also 'hourly','twicedaily'
}
add_action( 'myplugin_daily', 'myplugin_run_daily' );
wp_schedule_single_event( time() + 60, 'myplugin_once', array( $arg ) );  // one-off, e.g. defer slow work
```
- WP-Cron is **request-triggered**, not real cron — low-traffic sites may run late. For
  reliability set `define( 'DISABLE_WP_CRON', true )` + a real system cron hitting
  `wp-cron.php`.
- For high-volume/guaranteed background jobs use **Action Scheduler** (bundled with
  WooCommerce; available standalone) — it's queue-backed and retries.
- Add custom intervals via the `cron_schedules` filter. Unschedule on deactivation.

## Internationalization (i18n)

```php
__( 'Text', 'textdomain' );            // return
esc_html__( 'Text', 'textdomain' );    // return + escape  ← prefer for output
esc_html_e( 'Text', 'textdomain' );    // echo + escape
_x( 'Post', 'noun', 'textdomain' );    // disambiguate by context
_n( '%s item', '%s items', $n, 'textdomain' );   // plural — wrap result in sprintf
/* translators: %s is the user name. */
printf( esc_html__( 'Hi %s', 'textdomain' ), esc_html( $name ) );
```
- **Text domain must equal the plugin/theme slug**, a string literal (no variables/constants).
- **Timing (WP 6.7+):** don't call translation functions before `init` — doing so triggers a
  `_load_textdomain_just_in_time was called incorrectly` notice. Register/translate on/after
  `init`.
- `.org`-hosted plugins/themes get translations auto-loaded; for others ship `.mo`/`.l10n.php`
  and (pre-4.6 patterns aside) it generally just works. Generate the template with
  `wp i18n make-pot . languages/slug.pot`.
- **JS i18n:** `wp_set_script_translations()` + JSON files (`wp i18n make-json`). Use
  `@wordpress/i18n` (`__`, `_n`, `sprintf`) in JS.
- Never concatenate translated fragments; use placeholders + `printf`. Add `translators:`
  comments for any string with placeholders.

## Handy globals & helpers (don't hardcode)

- Paths/URLs: `plugin_dir_path()/plugins_url()/plugin_basename()`,
  `get_stylesheet_directory()/get_template_directory()` (+ `_uri`), `home_url()/site_url()/admin_url()/rest_url()`,
  `wp_upload_dir()`.
- DB props: `$wpdb->prefix`, `$wpdb->posts/postmeta/users/usermeta/options/terms`.
- Current context: `get_current_user_id()`, `wp_get_current_user()`, `get_current_screen()`.
- Time: use `current_time( 'mysql' )` / `time()`; store UTC, display with site offset.
- Environment: `wp_get_environment_type()` (`local`/`development`/`staging`/`production`) —
  branch behavior on it, not on `WP_DEBUG`. See `reference/products.md` § Environment awareness.
