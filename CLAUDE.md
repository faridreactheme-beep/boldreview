# CLAUDE.md

This file guides Claude Code when working in the **BoldReview** WordPress plugin.

## Overview

BoldReview is a review plugin for posts, pages, custom post types and WooCommerce products, published on WordPress.org by Themewant. It has two modules: **Collection Review** (first-party reviews with multi-criteria ratings and photos) and **Google Reviews** (reviews pulled from the Google Places API).

- Version: `1.0.3`. Bump it in **three places together**: the `Version:` header and `BDRVW_VERSION` in [boldreview.php](boldreview.php), and `Stable tag` in [readme.txt](readme.txt).
- Requirements: PHP 7.4+, WP 5.8+. Do not use PHP 8-only syntax (no `match`, no named args, no union types, no constructor promotion, no `?->`).
- Text domain: `boldreview`. The POT file is [languages/boldreview.pot](languages/boldreview.pot). Translations load just in time, so do not add `load_plugin_textdomain()`.
- There is no build step and no `package.json`. JS and CSS in `assets/` are hand-written, and vendor libs (Select2, Swiper) ship minified. There is no test suite.
- Composer is only used for PSR-4 autoload. `vendor/` is optional, and [boldreview.php](boldreview.php) has its own `spl_autoload_register` fallback.

## Naming conventions (required for WP.org prefixing)

- Namespace: `BoldReview\Plugin\` maps to `src/` (PSR-4). File name = class name.
- Every class is prefixed `Bdrvw_` (e.g. `Bdrvw_Settings`). Constants use `BDRVW_`. Global functions use `bdrvw_`.
- Options, hooks, AJAX actions, nonces, meta keys, script handles and CSS classes are all prefixed `bdrvw` / `bdrvw-`. Google Reviews hooks use `bdrvw_gr_`, and Collection Review settings-tab hooks use `bdrvw_cr_`.
- Coding style follows the WordPress Coding Standards: tabs, `array()` syntax, Yoda conditions and docblocks on every method. Every PHP file starts with an `ABSPATH` guard (`defined( 'ABSPATH' ) || exit;`).

## Architecture

```
boldreview.php                 constants, autoloader, activation hooks, boots on plugins_loaded
src/Bdrvw_Plugin.php           singleton service container; boot() creates and register()s every service
src/Core/                      settings, sanitizer, installer, modules registry, captcha, photos, notifier, legacy migration
src/Models/Bdrvw_Review.php    all review persistence and queries (static methods)
src/Admin/                     menu, dashboard, reviews list table, review edit panel, settings page, tools page
src/Ajax/Bdrvw_Handler.php     all core admin-ajax endpoints
src/Frontend/                  shortcodes, review renderer (form, list, summary), frontend assets
src/Integrations/              WooCommerce bridge
src/Modules/GoogleReviews/     self-contained module with its own Ajax, Client, Frontend, SettingsRenderer and assets/
src/helper-functions.php       bdrvw_allowed_html() (the wp_kses allow-list used for rendered output)
```

To add a service, construct it in `Bdrvw_Plugin::boot()`, store it in `$this->services[...]` and call `register()`. Admin-only services go inside the `is_admin()` block. Anything used during admin-ajax (for example captcha verification) must boot outside that block, because `is_admin()` is true during AJAX but the frontend also needs it. Other code can get services through `Bdrvw_Plugin::instance()->get( 'id' )`.

## Data model: reviews are native comments

There is **no custom table**. Reviews are WP comments with `comment_type = 'review'`, which is the same type WooCommerce uses. That is why existing WooCommerce product reviews appear in BoldReview without changes.

- Comment meta keys (constants on `Bdrvw_Review`): `rating` (shared with WooCommerce), `bdrvw_title`, `bdrvw_criteria` (per-criterion scores), `bdrvw_rejected`, `bdrvw_updated_at`, and `bdrvw_photos` (in `Bdrvw_Photos`, max 3 photos of 5 MB each).
- Statuses are `pending`, `approved`, `rejected`, `spam` and `trash`. `rejected` is not a native WP status: it is `comment_approved = 0` plus the `bdrvw_rejected` flag. Always go through `Bdrvw_Review::set_status()` and related methods instead of `wp_set_comment_status()` directly, so the `bdrvw_review_status_changed` action fires.
- Replies are child comments of a review.
- Queries in `Bdrvw_Review` use raw `$wpdb` SQL joining `commentmeta`. Keep everything `$wpdb->prepare()`d, and only interpolate class constants or whitelisted values.
- `Bdrvw_CommentsScreen` hides reviews from the normal Comments screen, comment counts and theme comment lists, and redirects the WooCommerce reviews page to BoldReview. Account for these filters when debugging "missing comment" issues.
- `Bdrvw_LegacyMigration` copies rows from the old `{prefix}bdrvw_reviews` table into comments once, in resumable batches of 100 on `admin_init`. It tracks progress with the `bdrvw_legacy_migrated` and `bdrvw_legacy_migrated_upto` options and stores the source row in `bdrvw_legacy_id` meta. Do not reintroduce the table.
- Post meta for per-post style overrides: `_bdrvw_style_override`, `_bdrvw_summary_style` and `_bdrvw_input_style` (see `Bdrvw_PostStyle`).

## Settings and modules

- All settings are stored in one option, `bdrvw_settings`, read through `Bdrvw_Settings`. `Bdrvw_Settings::defaults()` is the source of truth for keys and defaults. Activation seeds it only if it is missing.
- Active modules are stored in `bdrvw_active_modules`, managed by `Bdrvw_Modules`.
- **Important:** when a module's settings form saves, only the top-level keys listed in that module's `section_keys` (in `Bdrvw_Modules::definitions()`) are kept. **A new setting must be added to `defaults()`, to the right `section_keys` list, and to `Bdrvw_Sanitizer::settings()`**, or it is silently dropped. Add-ons extend this through the `bdrvw_module_definitions` filter.
- Every value must be sanitized in `Bdrvw_Sanitizer` (filters: `bdrvw_sanitize_settings`, `bdrvw_gr_sanitize_settings`, `bdrvw_captcha_sanitize_settings`).

## Frontend

- Shortcodes: `[bold_reviews]`, `[bold_review_form]`, `[bdrvw_google]`, plus one per Google layout: `[bdrvw_google_grid]`, `[bdrvw_google_list]`, `[bdrvw_google_sidebar]` and `[bdrvw_google_popup]`.
- Reviews and the form are appended through `the_content` (priority 20) on enabled post types, based on `auto_inject_position` and `excluded_post_ids`.
- `Bdrvw_ReviewRenderer` builds all review HTML. Summary styles are `bars`, `point`, `pie`, `hbars`, `stripes`, `gauge`, `tiles` and `overview`. Rating input styles are `stars`, `slider`, `bar`, `square` and `pill`.
- Escape late. For large HTML blocks, use `wp_kses( $html, bdrvw_allowed_html() )`. If you add new markup tags or attributes, extend that allow-list.

## WooCommerce integration

`Bdrvw_WooCommerce` is a no-op unless `product` is in `enabled_post_types`. When active, it replaces the product Reviews tab, overrides `woocommerce_product_get_average_rating`, `get_review_count` and `get_rating_counts` with BoldReview aggregates (cached per request), adds the verified-buyer badge, and gates submissions through `wc_review_who_can` and `wc_review_order_status`. Check whether WooCommerce is loaded before calling WC functions.

## Google Reviews module

- `Bdrvw_Client` calls both the legacy Places API (`maps.googleapis.com`) and Places API (New) (`places.googleapis.com/v1`) using `wp_remote_*`. It stores up to 50 reviews in settings.
- Hourly cron `bdrvw_google_reviews_refresh` is scheduled on activation and cleared on deactivation.
- Layouts and templates can be extended through the `bdrvw_gr_*` filters. Swiper (bundled, v11.1.14) is loaded only by this module.

## Security rules (enforced everywhere, so keep them)

- Admin capability is `manage_options` (the `CAPABILITY` constant on each admin class).
- Every admin AJAX handler checks `current_user_can()` first and then `wp_verify_nonce()`. The nonces are `bdrvw_admin` for general admin actions and `bdrvw_save_settings` for settings saves.
- The public submission endpoint `wp_ajax(_nopriv)_bdrvw_submit` checks the `bdrvw_submit` nonce plus a per-post token `bdrvw_review_post_{post_id}`. It then runs captcha (`bdrvw_submit_field_errors`), the submit gate (`bdrvw_submit_gate_error`), the blacklist, IP blocks, the email whitelist, per-user limits and duplicate detection.
- The Tools page uses `admin-post.php` actions (`bdrvw_export_reviews`, `bdrvw_import_reviews`, `bdrvw_rollback` and `bdrvw_rollback_refresh`). Rollback also requires `update_plugins`.
- Sanitize input with `wp_unslash()` plus the right `sanitize_*`. Run all SQL through `$wpdb->prepare()`.

## Extension hooks (used by a Pro/add-on, so do not rename them)

Actions: `bdrvw_booted`, `bdrvw_review_submitted`, `bdrvw_review_status_changed`, `bdrvw_review_deleted`, `bdrvw_review_replied`, `bdrvw_form_before_actions`, `bdrvw_review_edit_save_control`, `bdrvw_criteria_card_footer`, `bdrvw_cr_tab_*`, `bdrvw_cr_advanced_*` (slack, discord, video_review, notification_template) and `bdrvw_gr_blocked_words_control`.

Filters: `bdrvw_module_definitions`, `bdrvw_allowed_html`, `bdrvw_review_author_badge`, `bdrvw_submit_gate_error`, `bdrvw_submit_field_errors`, `bdrvw_captcha_providers`, `bdrvw_review_updatable_fields`, `bdrvw_rollback_versions` and the `bdrvw_gr_*` family.

The free plugin renders empty hook points for Pro features. It must stay fully functional without them: no locked features or upsell trialware (a WP.org guideline).

## WordPress.org compliance

- No remote assets from CDNs. Bundle everything locally. The only exception is the Google Maps JS API, loaded on the admin side with the user's own key.
- No tracking and no obfuscated code. Keep `readme.txt` accurate, and document external services (Google Places API) in it.
- Use the `plugin-review-guideline` and `security` agents or the `.claude/skills/wp-*` skills before a release.

## Workflow

- Git repo: commit messages are short and lowercase (e.g. `review criteria issue fix`).
- Local site: `googlereview` (Local by Flywheel), plugin path `app/public/wp-content/plugins/boldreview`.
- PHP lint check: `php -l <file>`. No PHPCS config is committed, so follow WPCS by hand.
