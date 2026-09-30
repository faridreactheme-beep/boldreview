# Commercial Products & Ecosystem Reference

What turns "WordPress code" into a **distributable product**: the activation→uninstall
lifecycle, roles/capabilities, commercial distribution (custom update servers, licensing,
Pro/Lite gating), privacy/GDPR, WooCommerce extensions, transactional email, multisite, and
environment awareness. Read the relevant section before building anything that ships to users
you don't control.

---

## 1. The plugin lifecycle — activation, deactivation, uninstall

These three hooks have **different rules and different timing**. Get them wrong and you leak
data, leave orphaned cron, or fatal on update.

```php
register_activation_hook( __FILE__, 'myplugin_activate' );
register_deactivation_hook( __FILE__, 'myplugin_deactivate' );
// Uninstall: prefer a static uninstall.php (below) over register_uninstall_hook().
```

- **Activation** runs **once** when the plugin is enabled. Do: create custom tables
  (`dbDelta`), seed default options, add roles/caps, schedule cron, store `db_version`. Then
  **`flush_rewrite_rules()`** — but only here, *after* your CPTs/rewrites are registered (so
  register them on `init` unconditionally, and just flush on activation). Activation runs
  before `init` on the activating request, so don't assume your own `init` hooks have fired —
  call your registration functions directly if you need them.
- **Deactivation** runs when disabled (often temporary — an update deactivates+reactivates).
  Do: **unschedule cron** (`wp_clear_scheduled_hook`), flush rewrite rules, clear transients.
  **Do NOT delete user data here** — deactivation is not uninstall.
- **Uninstall** runs on **delete**. Two ways, **never both**:
  - **`uninstall.php`** in the plugin root (preferred). WP loads it in isolation with the
    constant `WP_UNINSTALL_PLUGIN` defined — **guard on it and bail if absent.** Your plugin's
    main file is *not* loaded, so re-`require` or re-declare anything you use.
  - `register_uninstall_hook( __FILE__, callback )` — the callback must be a **static method
    or plain function** (the object isn't available at uninstall).
- **Data-removal policy:** make destructive cleanup **opt-in** (a "delete all data on uninstall"
  setting). Drop custom tables, delete options/meta/transients, remove roles/caps, delete
  scheduled events. On **multisite**, loop sites (below) — uninstall fires once at network level.

```php
// uninstall.php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;            // never run standalone
if ( ! (bool) get_option( 'myplugin_delete_on_uninstall' ) ) return;
delete_option( 'myplugin_settings' );
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}myplugin_entries" );  // tables can't be prepared as identifiers in DROP
```

### Schema upgrades (run on load, not just activation)
Activation doesn't fire on a plugin **update** (the new version's files just replace the old).
So gate migrations on a stored version, checked early on every load:

```php
add_action( 'plugins_loaded', function () {
    if ( get_option( 'myplugin_db_version' ) !== MYPLUGIN_DB_VERSION ) {
        myplugin_install_or_upgrade_schema();          // idempotent dbDelta + data migration
        update_option( 'myplugin_db_version', MYPLUGIN_DB_VERSION );
    }
} );
```

---

## 2. Roles & capabilities — create them, don't just check them

`reference/security.md` covers *checking* caps. Products often need to *create* them.

```php
// On activation — add a custom cap to existing roles, or a whole role:
$role = get_role( 'administrator' );
$role && $role->add_cap( 'manage_myplugin' );
add_role( 'myplugin_manager', __( 'MyPlugin Manager', 'td' ), array(
    'read' => true, 'manage_myplugin' => true,
) );
```

- Adding/removing caps **persists in the DB** (`wp_user_roles` option) — do it on
  activation/uninstall, **not** on every load (that thrashes the option).
- For a **CPT** with granular caps, set `'capability_type' => 'book'` + `'map_meta_cap' => true`,
  then grant the generated caps (`edit_books`, `edit_others_books`, `publish_books`, …) to the
  roles that should have them. Without granting, even admins may not see the CPT.
- On **uninstall**, `remove_cap`/`remove_role` what you added.
- Filter `map_meta_cap` for fully custom authorization on custom-table records.

---

## 3. Commercial distribution — updates & licensing for non-.org products

A paid/self-hosted plugin doesn't get .org updates. You provide them.

### Custom update server
WordPress checks updates via a transient; hook it to point at your server:

```php
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;
    $remote = myplugin_fetch_remote_version();   // your API: returns version, package URL (license-gated), etc.
    if ( version_compare( MYPLUGIN_VERSION, $remote->version, '<' ) ) {
        $transient->response[ MYPLUGIN_BASENAME ] = (object) array(
            'slug'        => 'myplugin',
            'plugin'      => MYPLUGIN_BASENAME,
            'new_version' => $remote->version,
            'package'     => $remote->download_url,   // signed/license-checked ZIP URL
            'url'         => $remote->homepage,
        );
    }
    return $transient;
} );
add_filter( 'plugins_api', 'myplugin_plugins_api', 20, 3 );  // the "View details" modal
```

- **Don't hand-roll this if you can avoid it.** Battle-tested options:
  **Plugin Update Checker** (YahnisElsts, GPL) for a self-hosted server; **Freemius** or
  **EDD Software Licensing** for a full store + licensing + updates SaaS.
- The download `package` URL must enforce the license server-side (the filter is client-side
  and trivially bypassed — the *gate* is your server returning 403 for invalid licenses).

### License validation pattern
- Store the key in an option; validate against your API on activation and on a **scheduled
  cron** (e.g. daily), caching the result in a transient. Fail **soft** (warn, keep working
  for a grace period) rather than bricking the site on a transient network error.
- Never embed secrets that gate features purely client-side — a determined user can flip a
  boolean. Server-gate anything that costs you money (updates, premium API calls).

### Pro/Lite (freemium) architecture
- Ship Lite on .org; Pro as a **separate add-on plugin** that depends on Lite's hooks — Pro
  must not duplicate or fork Lite code. Lite exposes `do_action`/`apply_filters` seams; Pro
  registers on them. (See the `boldform` project skills for a concrete contract.)
- Pro detects Lite (`function_exists`/`class_exists` or a version constant) and shows an admin
  notice if missing/too-old. Guard every Pro feature behind both "Lite present" and "license
  valid". Keep the hook contract **stable** — renaming a Lite hook breaks every Pro version.

---

## 4. Privacy / GDPR — exporters, erasers, policy text

Required for any product touching personal data sold into EU/UK/CA markets.

```php
add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
    $exporters['myplugin'] = array(
        'exporter_friendly_name' => __( 'MyPlugin Entries', 'td' ),
        'callback'               => 'myplugin_export_personal_data',   // paginated; returns ['data'=>[], 'done'=>bool]
    );
    return $exporters;
} );
add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
    $erasers['myplugin'] = array(
        'eraser_friendly_name' => __( 'MyPlugin Entries', 'td' ),
        'callback'             => 'myplugin_erase_personal_data',      // returns ['items_removed'=>n, 'done'=>bool, …]
    );
    return $erasers;
} );
add_action( 'admin_init', function () {
    if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
        wp_add_privacy_policy_content( 'MyPlugin', wp_kses_post( '<p>What data we store…</p>' ) );
    }
} );
```

- Both callbacks are **paginated** (called with `$email, $page`) — return `done => false`
  while more pages remain. Match data by the user's email.
- These power the core Tools → Export/Erase Personal Data flow. Storing personal data (emails,
  IPs, form entries) without them is a compliance gap reviewers and customers flag.

---

## 5. WooCommerce extension development

A large share of paid WP products are WooCommerce extensions. WooCommerce is a *plugin*, so
the same hook/security rules apply — plus its own conventions.

### Bootstrapping safely
```php
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {              // WC not active → bail gracefully
        add_action( 'admin_notices', 'myext_wc_missing_notice' );
        return;
    }
    // ... register your WC integration here
} );

// Declare HPOS (custom order tables) compatibility — REQUIRED or WC warns/disables you (WC 8.2+):
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks', __FILE__, true
        );
    }
} );
```

### HPOS — the #1 modern gotcha
**High-Performance Order Storage** moves orders out of `wp_posts`/`wp_postmeta` into custom
tables. Code that assumes orders are posts breaks. Rules:

- **Never** query orders with `WP_Query`/`get_posts`/`get_post_meta` — use the **CRUD/data
  store**: `wc_get_orders( $args )`, `wc_get_order( $id )`, `$order->get_meta( $key )`,
  `$order->update_meta_data()`, `$order->save()`.
- Same for products: `wc_get_product( $id )`, `$product->get_price()`, `$product->save()` —
  not raw post meta. CRUD objects work whether HPOS is on or off.

### Common extension surfaces
- **Payment gateway:** extend `WC_Payment_Gateway`, set `$this->id`, build settings via
  `init_form_fields()`/`init_settings()`, implement `process_payment( $order_id )` returning
  `array( 'result' => 'success', 'redirect' => … )`, handle the webhook/IPN on a REST route or
  `woocommerce_api_*`. Register with `add_filter( 'woocommerce_payment_gateways', … )`.
- **Settings:** add a tab/section via `woocommerce_get_settings_pages` or
  `woocommerce_settings_tabs_array`; or use the gateway/integration settings API. Honor WC's
  own sanitization.
- **Product data:** custom fields via `woocommerce_product_options_*` action +
  `woocommerce_process_product_meta` save; store on the product CRUD object.
- **Templates:** override by copying WC templates into `yourtheme/woocommerce/…`, or filter
  with `wc_get_template`/`woocommerce_locate_template`. Prefer hooks
  (`woocommerce_before_main_content`, `woocommerce_single_product_summary`, …) over template
  overrides — overrides rot when WC updates them.
- **Cart/Checkout Blocks (Store API):** the block checkout does **not** fire classic
  `woocommerce_checkout_*` hooks. Integrate via the **Store API** and the
  `IntegrationInterface` / `woocommerce_blocks_loaded` + `ExtendSchema`, or register additional
  checkout fields with `woocommerce_register_additional_checkout_field` (WC 8.9+). Declare
  `cart_checkout_blocks` compatibility (above).
- **Logging:** `wc_get_logger()->debug( $msg, array( 'source' => 'myext' ) )` → WooCommerce →
  Status → Logs. Don't `error_log` payment data.

---

## 6. Transactional email — `wp_mail`

```php
$sent = wp_mail(
    $to,
    $subject,
    $message,                                    // HTML only if you set the content type below
    array( 'Content-Type: text/html; charset=UTF-8', 'From: Name <no-reply@example.com>' )
);
```

- Set HTML per-message via the `Content-Type` header (above) or globally via the
  `wp_mail_content_type` filter — **remove the filter after** so you don't turn every site
  email into HTML.
- Configure the underlying PHPMailer (SMTP, DKIM, reply-to) on the **`phpmailer_init`** action.
- **Deliverability is not core's job:** default PHP mail lands in spam. For a product that must
  deliver, recommend/integrate an SMTP/API provider (the site's SMTP plugin, or your own
  `phpmailer_init` SMTP config) — don't promise reliable delivery from bare `wp_mail`.
- `wp_mail` returns a bool; log failures via the `wp_mail_failed` action.

---

## 7. Multisite depth

```php
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
        switch_to_blog( $blog_id );
        myplugin_install_or_upgrade_schema();    // e.g. create tables per-site on network activation
        restore_current_blog();
    }
}
```

- **Always pair** `switch_to_blog()` with `restore_current_blog()` (and never leave a switch
  open across a request). `$wpdb` repoints to the switched site's tables automatically.
- **Network-activated** plugins run on every site; check `is_plugin_active_for_network()`.
  Activation hooks receive a `$network_wide` arg — loop sites when true (creating per-site
  tables/options); also handle **new sites created later** via the `wp_initialize_site` action
  (replaces the deprecated `wpmu_new_blog`).
- **Network admin UI:** `network_admin_menu` for menus, `add_submenu_page` under
  `settings.php`; store network settings with `get_site_option`/`update_site_option`.
- Capabilities differ: super-admin-only actions check `is_super_admin()` /
  `manage_network_options`. Some functions (`activate_plugins`) behave per-site.

---

## 8. Environment awareness

```php
wp_get_environment_type();   // 'local' | 'development' | 'staging' | 'production' (default 'production')
wp_get_development_mode();    // '' | 'core' | 'plugin' | 'theme' | 'all'  (WP 6.3+)
```

- Set via `WP_ENVIRONMENT_TYPE` (constant or env var). Use it to gate debug output, sandbox vs
  live payment keys, test-email redirection — never ship a product that enables debug behavior
  on `production`.
- `wp_get_development_mode()` controls whether certain caches (e.g. `theme.json`, block-type
  registration) are bypassed — relevant when iterating on themes/blocks locally.
- Read `WP_DEBUG` for verbosity, but branch *behavior* on the environment type, not on
  `WP_DEBUG`.

---

## Products checklist (before shipping a release)

- [ ] Activation creates schema/options/caps + flushes rewrites; idempotent on re-activate.
- [ ] Deactivation clears cron + rewrites but **keeps** user data.
- [ ] `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`; destructive cleanup is opt-in; loops
      multisite.
- [ ] Schema upgrade gated on a stored `db_version`, run on load (not only activation).
- [ ] Custom updates/licensing enforced **server-side**; soft-fail on network errors.
- [ ] Personal data has a privacy exporter + eraser + policy text.
- [ ] WooCommerce ext declares HPOS (+ blocks) compatibility and uses CRUD, not raw post meta.
- [ ] Email sets content type safely and doesn't promise delivery from bare `wp_mail`.
- [ ] Multisite: every `switch_to_blog` is restored; new-site hook handled.
- [ ] No debug/test behavior active when `wp_get_environment_type()` is `production`.
