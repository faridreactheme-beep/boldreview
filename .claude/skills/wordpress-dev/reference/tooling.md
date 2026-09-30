# Tooling, Debugging & Compliance Reference

How a senior dev builds, lints, debugs, tests, and ships WordPress code. Detect what the
project actually uses before assuming — and confirm a tool is installed before claiming you
ran it.

This machine has: **PHP 8.2, WP-CLI 2.12, Composer 2.8, Node 24 / npm 11.** `phpcs` is **not**
on PATH (use Composer-local: `composer exec phpcs` or `vendor/bin/phpcs`). Sites are Local by
Flywheel under `/Users/riaz/Local Sites/<site>` (WordPress at `app/public`).

## WP-CLI — the developer's Swiss army knife

### FIRST: on Local (Flywheel) sites, plain `wp` fails — this is not a broken database

Every site in `~/Local Sites/` hits this. `wp` reports:

> **Error establishing a database connection**

…while MySQL is running perfectly. Cause: `wp-config.php` sets `DB_HOST` to `localhost`, which
sends PHP to the *default* socket path, but Local runs a **per-site** mysqld on its own socket.

**Do NOT conclude the DB is down.** Confirm with `ps aux | grep [m]ysqld`. Local also ships no
`mysql`/`mysqladmin` client binary, so those commands being missing proves nothing either.

**Fix — no edit to `wp-config.php`.** Write a small file:

```php
<?php
define( 'DB_HOST', 'localhost:/Users/riaz/Library/Application Support/Local/run/<SITE_ID>/mysql/mysqld.sock' );
```

then run from the WP root:

```bash
wp --require=/path/to/that-file.php <command>
```

`--require` loads *before* `wp-config.php`, so the constant is already defined and wp-config's own
`define()` becomes a harmless no-op (it warns "Constant DB_HOST already defined" — filter with
`grep -v`). First definition wins.

Find `<SITE_ID>` with `ls ~/Library/Application\ Support/Local/run/` — it is per-site, not global
(`QVop3Rrtd` = the `develop` env, verified 2026-08-04; `Rbi3BmC-z` = `dummy`). If a socket path
stops working, re-list the directory rather than assuming the site is broken.

**Why this matters:** it is the gate on `wp plugin check` (wp.org compliance scanning) and
`wp eval-file` runtime proofs — the difference between a static audit and a *verified* one. Any
skill that tells you to run `wp` assumes a standard install and will not mention this.

### High-value commands

Run from inside (or with `--path=`) the WordPress root.

```bash
wp --info                                   # environment sanity
wp core version ; wp core check-update
wp plugin list ; wp plugin activate <slug> ; wp theme list
wp eval 'var_dump( get_option( "myplugin_settings" ) );'   # run PHP in WP context
wp eval-file script.php                       # run a PHP file in WP context
wp db query "SELECT * FROM wp_options LIMIT 5;"   # raw SQL
wp option get myplugin_settings --format=json
wp post list --post_type=book --format=table
wp transient delete --all ; wp cache flush
wp cron event list ; wp cron event run --due-now
wp user create / wp user list                 # make test users/roles
wp rewrite flush                              # after CPT/rewrite changes
wp i18n make-pot . languages/slug.pot         # generate translation template
wp i18n make-json languages/                  # JS translations
wp scaffold plugin / block / post-type / taxonomy / plugin-tests
wp search-replace 'old' 'new' --dry-run       # safe migrations (always dry-run first)
wp media regenerate ; wp profile stage        # (profile cmd = add-on) perf profiling
```
`wp eval`/`eval-file` is the fastest way to verify behavior in real WP context without a
browser. `wp db query` to inspect tables. Always `--dry-run` destructive commands first.

## Composer + PHP_CodeSniffer (WordPress Coding Standards)

Most quality WP projects lint with **WPCS**. Typical dev deps:
```json
{
  "require-dev": {
    "squizlabs/php_codesniffer": "^3",
    "wp-coding-standards/wpcs": "^3",
    "phpcompatibility/phpcompatibility-wp": "*",
    "dealerdirect/phpcodesniffer-composer-installer": "*"
  }
}
```
Run (Composer-local, since `phpcs` isn't global here):
```bash
composer install
composer exec phpcs -- --standard=WordPress path/to/file.php   # or vendor/bin/phpcs
composer exec phpcbf -- --standard=WordPress path/to/file.php   # auto-fix what's safe
```
A `phpcs.xml.dist` usually pins the standard, `testVersion`, text-domain, and prefixes. Honor
it. WPCS rulesets: `WordPress` (all), `WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`.

**PHPStan** (static analysis) with `szepeviktor/phpstan-wordpress` for type-level bugs:
`composer exec phpstan -- analyse`. Use if the project has a `phpstan.neon`.

**`php -l <file>`** is always available and always required on every changed PHP file — do it
even when nothing else is set up.

## JS / block build — @wordpress/scripts

For blocks and modern editor JS, projects use `@wordpress/scripts` (zero-config webpack +
Babel + ESLint + Prettier + JEST):
```bash
npm install
npm run start          # dev build + watch
npm run build          # production build → build/ with *.asset.php (deps + version)
npm run lint:js ; npm run lint:css ; npm run format
npm run packages-update
```
Point `block.json` asset fields at `build/…`. The generated `*.asset.php` gives you the exact
dependency array + a content hash for cache-busting — pass it to `register_block_type`/enqueue.

**`@wordpress/env` (wp-env)** spins up a disposable Docker WordPress for development/testing
(`.wp-env.json`): `npx wp-env start` / `wp-env run cli wp ...` / `npx wp-env run tests-cli ...`.
Great for clean-room repro and running the PHP test suite.

## Debugging

In `wp-config.php` (dev only — never on production):
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );      // → wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false ); // log, don't render to page
define( 'SCRIPT_DEBUG', true );      // unminified core JS/CSS
define( 'SAVEQUERIES', true );       // record all queries ($wpdb->queries)
```
- Tail the log: `wp-content/debug.log`. Treat **any** PHP notice/deprecation as a bug to fix.
- **Query Monitor** plugin: the essential dev tool — queries (slow/duplicate), hooks fired,
  HTTP calls, REST, enqueued assets, caps checks, PHP errors, transients. Install it for any
  perf/behavior investigation.
- `error_log( print_r( $var, true ) )` / `wp_debug_backtrace_summary()` for ad-hoc tracing.
- Use `WP_DEBUG` to catch "called incorrectly" (`_doing_it_wrong`) notices — e.g. the WP 6.7+
  early-textdomain warning.

## Testing

- **PHP unit/integration:** PHPUnit + the WordPress test suite (`wp scaffold plugin-tests`,
  or `wp-env`'s `tests-cli`). Test activation, data round-trips, hooks firing, permission
  gates. Use the WP test factories (`$this->factory->post->create()`).
- **JS:** Jest (bundled in `@wordpress/scripts`, `npm run test:unit`).
- **E2E:** Playwright (`@wordpress/scripts` `test:e2e`, or `@wordpress/e2e-test-utils-playwright`)
  for editor/block flows.
- Even without a suite, **trace every code path by reading it adversarially** (see SKILL Step 5)
  and verify behavior with `wp eval`.

## Rebranding / renaming a plugin

**Do it BEFORE the first .org release.** WordPress.org never renames a slug after approval — the
only path is a new submission and abandoning the listing. Pre-publication there are zero users, so
you need **no migration shims, no legacy option aliases, no dual-firing hooks**: tables, options,
caps, hooks, blocks and the REST namespace just change identity. Post-publication every one of those
is a break, and the rename stops being mechanical.

- **Inventory first, in this order:** persisted state (tables, options, transient prefixes, user/post
  meta — watch for `_underscore`-prefixed keys, capabilities, taxonomies, cron hooks); public API
  (hooks fired, shortcodes, block names + category, REST namespace, admin menu slug, privacy
  exporter slug, sitemap provider name); then code (namespace, constants, text domain, CSS
  prefixes). **`uninstall.php` is usually a ready-made manifest of the persisted half** — if it is
  complete enough to clean up after the plugin, it is complete enough to drive the rename.
- **Check the casing variants before writing any sed.** If the codebase only uses
  `UPPER`/`Title`/`lower` (no camelCase hybrids), three **case-sensitive** passes do the entire job
  with no collisions. Verify with per-variant counts first; a single case-insensitive pass destroys
  casing everywhere.
- **The new slug must be `[a-z]+` if the plugin registers a sitemap provider.** Core's rewrite is
  `^wp-sitemap-([a-z]+?)-([a-z\d_-]+?)-(\d+?)\.xml$` — the *provider* group is letters only. A slug
  with a hyphen or underscore registers, appears in the sitemap index, and **404s forever**.
- **The folder name must match the text domain** — Plugin Check derives the expected domain from the
  folder, so rename the directory and the main PHP file too.
- **A blind rename breaks English.** `an oldname_hook` → `an newname_hook` is wrong when the initial
  sound changes. Always grep `\b[Aa]n +<newname>` and `\b[Aa] +<oldname-initial-vowel>` afterwards.
  Linters and tests cannot see this; only a read-through can.
- **Regenerate, never sed:** the Composer autoloader (`dump-autoload -o` — `autoload_psr4.php` maps
  the old namespace until you do, so *nothing loads*), the npm lockfile (`npm install
  --package-lock-only`; a stale `name` makes `npm ci` fail EUSAGE), the JS build, and the `.pot`
  (a sed'd catalog keeps wrong headers and stale line numbers).
- **Hand-edit what tokens miss:** the phpcs `PrefixAllGlobals` + `text_domain` allowlists (miss these
  and phpcs errors on *every* hook you fire), `composer.json` name + PSR-4 + script file lists,
  `package.json`, CI workflow artifact names, the release script's file manifest, and any raster
  brand art (logos, banners, icons, screenshots — sed cannot touch PNGs).
- **Verify with a survivor grep that includes generated output**, then re-run the full gate and
  compare test counts to the pre-rename baseline. Identical counts is the proof the rename was
  mechanical; a changed count means something was renamed that shouldn't have been.

## WordPress.org compliance (if shipping to the directory)

- **Plugin Check (PCP)** — the official `plugin-check` plugin (or `wp plugin check <slug>` with
  the CLI command): flags security, i18n, header, and guideline violations the .org review bot
  catches. Run it before submitting — against the **extracted release zip**, not the working tree.
  **It derives the expected text domain from the FOLDER NAME**, so checking an artifact unpacked
  into `myplugin-relcheck/` yields thousands of bogus `TextDomainMismatch` errors; pass
  `--slug=<real-slug>`. Note it does NOT check guideline #4 (source accessibility) — verify by hand
  that every minified file in the zip has a readable source and that the build tools
  (`package.json` + bundler config) ship or are publicly reachable.
- **Theme Check** plugin / **Theme Unit Test** data for themes; block themes also validated by
  the directory's automated checks.
- `readme.txt` must follow the standard format (`Stable tag`, `Tested up to`, `Requires PHP`,
  `Requires at least`, changelog) — validate against the readme validator. Keep `Stable tag` =
  plugin header `Version`.
- Guidelines: no obfuscated/remote code, no external loading of JS/CSS from CDNs, declare every
  external/3rd-party service and its privacy/data flow in the readme, GPL-compatible licensing
  for all bundled code, no tracking without explicit consent, sanitize/escape everywhere (the
  bot scans for it).
- Don't bundle dev tooling (`node_modules`, `vendor` dev deps, `.git`) in the shipped zip.

## Versioning discipline (release)

Keep these in lockstep on release: plugin header `Version:` = the `VERSION` constant =
`readme.txt` `Stable tag:`. Bump a `DB_VERSION` constant **only** on schema change (gate
`dbDelta` upgrades on it). Bump the asset version (or use `filemtime`) whenever shipped JS/CSS
changes so browsers re-fetch. For themes, the version lives in `style.css`.
