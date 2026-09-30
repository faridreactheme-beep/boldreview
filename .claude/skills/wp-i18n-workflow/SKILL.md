---
name: wp-i18n-workflow
description: Use when managing the full translation workflow for a WordPress plugin — generating POT files with wp i18n make-pot, compiling .po to .mo and .json, setting up JavaScript translations with wp_set_script_translations, submitting to translate.wordpress.org, or debugging missing translations.
---

# WordPress Plugin i18n Workflow

Full translation pipeline for WordPress plugins: POT generation, PO/MO compilation, JavaScript translations, translate.wordpress.org GlotPress, and language pack distribution. Covers the coding conventions and the tooling workflow.

## Self-learning mode (ALWAYS ON)

This skill improves itself. Treat every task as a chance to make this skill more expert —
always target becoming more skillful.

**After any task that used this skill** — and immediately whenever you discover something
durable mid-task — update this skill (`SKILL.md`, and/or its `reference/` files) to encode
what you learned, so the next session starts smarter. This is a standing instruction, not
optional.

**Capture** (what makes the skill more skillful):
- Corrected assumptions or facts the skill got wrong, omitted, or that have since changed.
- Non-obvious gotchas, pitfalls, and "I wish I'd known that" moments.
- New/changed file locations, commands, conventions, or tooling realities.
- A sharper workflow or better step ordering than what's written here.

**Do NOT capture:** one-off conversation details, secrets/credentials, anything already
covered here, or task-specific scratch notes. Keep edits tight and high-signal — append to
the right existing section, never bloat. Every edit must make the skill strictly better.

**Never write to a symlinked/upstream skill.** Some skills under `~/.claude/skills/` are
symlinks into an external git repo (e.g. the `wp-dev-skills` fork). Those are read-only:
editing one dirties that repo and the change is lost on the next `git pull`. If the lesson
came from using such a skill, record it in the nearest skill that IS locally owned — the
project-specific one if there is one, otherwise the matching generalist (`wordpress-dev`,
`wordpress-qa`) — and say which upstream skill it relates to.

**Self-check before you finish a task:** "What did I learn that this skill should have told
me up front?" If anything, write it in now.

## When to use

- "Generate a POT file for my plugin", "update translation strings".
- "Set up JavaScript translations", "translate strings in React/block editor".
- "Submit to translate.wordpress.org", "set up language packs".
- "Why aren't my translations loading?", "debug missing .mo file".
- "Add translator comments", "handle plurals and context strings".

**Not for:** PHPCS i18n sniff violations — use `wp-coding-standards`. Checking i18n completeness in an audit — use `wp-plugin-audit` Dimension B.

## Environment — read before running any `wp` command

On a site under `~/Local Sites/` (Local by Flywheel), plain `wp` fails with **"Error establishing
a database connection"** even though MySQL is running fine — Local uses a per-site socket while
`wp-config.php` points at `localhost`. **Do not report the database as down.**

Run instead: `wp --require=<file-that-defines-DB_HOST> <command>`, where the file sets
`DB_HOST` to `localhost:~/Library/Application Support/Local/run/<SITE_ID>/mysql/mysqld.sock`.
Find `<SITE_ID>` with `ls ~/Library/Application\ Support/Local/run/`.

This gates `wp i18n make-pot` — the core command of this skill — so it will bite on the very
first step. Full recipe: `wordpress-dev/reference/tooling.md` → "WP-CLI".

## Method

### 1. PHP i18n conventions

All translatable strings must use the plugin's **text domain** consistently. The text domain must match the `Text Domain:` header and the `load_plugin_textdomain()` call.

```php
// Basic translation
__( 'Settings', 'my-plugin' )
_e( 'Save Changes', 'my-plugin' )        // echo version

// With HTML context (escape + translate combined)
esc_html__( 'Error message', 'my-plugin' )
esc_attr__( 'Tooltip text', 'my-plugin' )

// Plurals
_n( '%d item', '%d items', $count, 'my-plugin' )
sprintf( _n( '%d item', '%d items', $count, 'my-plugin' ), $count )

// Context strings (disambiguation for translators)
_x( 'Post', 'noun: a blog post', 'my-plugin' )
_ex( 'Draft', 'verb: save as draft', 'my-plugin' )

// Plural with context
_nx( '%d reply', '%d replies', $count, 'comment count', 'my-plugin' )
```

**Translator comments** — required for strings with placeholders:
```php
/* translators: %s: plugin version number */
sprintf( __( 'Version %s', 'my-plugin' ), MY_PLUGIN_VERSION )

/* translators: 1: post title, 2: author name */
sprintf( __( '"%1$s" by %2$s', 'my-plugin' ), $title, $author )
```
Comment must be on the line immediately before the function call and start with `translators:`.

### 2. Load text domain

```php
add_action( 'init', function() {
    load_plugin_textdomain(
        'my-plugin',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
} );
```

For WP.org plugins, language packs are auto-loaded from `translate.wordpress.org` — `load_plugin_textdomain()` only needed for bundled `.mo` files or local development.

### 3. Generate POT file

```bash
# WP-CLI (preferred)
wp i18n make-pot . languages/my-plugin.pot \
  --domain=my-plugin \
  --exclude=vendor,node_modules,tests,build \
  --headers='{"Project-Id-Version":"My Plugin 1.0.0","Report-Msgid-Bugs-To":"https://github.com/my-org/my-plugin/issues"}'

# Update existing POT (merges new strings, marks removed as obsolete)
wp i18n make-pot . languages/my-plugin.pot --domain=my-plugin
```

Commit `languages/my-plugin.pot` to git. Translators use this as the source.

### 4. Compile PO → MO

`.po` files are human-editable; `.mo` are compiled binary files loaded by PHP.

```bash
# Single file
wp i18n make-mo languages/my-plugin-fr_FR.po

# All PO files in the directory
wp i18n make-mo languages/

# Using msgfmt (gettext tools)
msgfmt languages/my-plugin-fr_FR.po -o languages/my-plugin-fr_FR.mo
```

File naming convention: `{text-domain}-{locale}.po` / `.mo`
Examples: `my-plugin-fr_FR.mo`, `my-plugin-de_DE.mo`, `my-plugin-pt_BR.mo`

### 5. JavaScript translations

**Block editor / React components** — use `@wordpress/i18n`:

```js
import { __, _n, _x, sprintf } from '@wordpress/i18n';

const label = __( 'Save settings', 'my-plugin' );
const count = sprintf( _n( '%d item', '%d items', total, 'my-plugin' ), total );
const ctx   = _x( 'Draft', 'button label', 'my-plugin' );
```

**Generate JSON translation files:**
```bash
# From PO file — produces my-plugin-fr_FR-{hash}.json
wp i18n make-json languages/my-plugin-fr_FR.po --no-purge
```

**Register JS translations in PHP:**
```php
function my_plugin_set_script_translations() {
    wp_set_script_translations(
        'my-plugin-editor',   // script handle (must be enqueued)
        'my-plugin',          // text domain
        plugin_dir_path( __FILE__ ) . 'languages'
    );
}
add_action( 'init', 'my_plugin_set_script_translations' );
```

For blocks registered via `block.json`, WP auto-calls `wp_set_script_translations` if `textdomain` is set in `block.json`:
```json
{
    "textdomain": "my-plugin",
    "editorScript": "file:./index.js"
}
```

### 6. translate.wordpress.org (GlotPress)

WP.org plugins get a GlotPress project automatically once approved. Language packs are built weekly and distributed via the WP update system.

**Setup steps:**
1. Plugin approved on WP.org → GlotPress project auto-created at `translate.wordpress.org/projects/wp-plugins/your-slug/`
2. Ensure `languages/` dir exists in SVN trunk with the `.pot` file
3. GlotPress imports strings from trunk automatically (or trigger via SVN commit)
4. Community translators contribute at `translate.wordpress.org`
5. At 95% translation completion, a language pack is created and distributed to users

**WP.org translation validator:** `https://i18n.svn.wordpress.org/`

**Correct POT headers for GlotPress:**
```
Project-Id-Version: My Plugin 1.0.0
Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/my-plugin
Last-Translator: FULL NAME <EMAIL@ADDRESS>
Language-Team: LANGUAGE <LL@li.org>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
```

### 7. Debugging missing translations

**Checklist:**
```bash
# 1. Verify text domain matches header and load_plugin_textdomain()
grep -r "Text Domain:" *.php
grep -r "load_plugin_textdomain" includes/

# 2. Verify .mo file exists and locale matches WP locale
wp option get WPLANG   # should match e.g. fr_FR
ls languages/          # should have my-plugin-fr_FR.mo

# 3. Verify .mo can be loaded
wp eval "echo load_plugin_textdomain('my-plugin', false, 'path/to/languages/');"

# 4. Verify string is in POT
grep -A2 "your string" languages/my-plugin.pot

# 5. For JS translations — check JSON files exist
ls languages/*.json

# 6. Check wp_set_script_translations fires after script is enqueued
# (Must call AFTER wp_enqueue_script, usually on 'init' or 'enqueue_scripts')
```

**Common failure modes:**

| Symptom | Cause | Fix |
|---|---|---|
| Strings show in English only | `.mo` missing or wrong locale | Run `wp i18n make-mo languages/` |
| JS strings not translated | JSON file missing or wrong handle | Run `wp i18n make-json`, verify handle |
| POT out of date | New strings not extracted | Re-run `wp i18n make-pot` |
| Translator comment not picked up | Not on immediately preceding line | Move comment to line above call |
| WP.org language pack not appearing | < 95% translated | Complete translations on translate.wordpress.org |

### 8. Automation — sync POT on release

Add to GitHub Actions or release workflow:
```yaml
- name: Generate POT
  run: wp i18n make-pot . languages/my-plugin.pot --domain=my-plugin --exclude=vendor,node_modules,build
- name: Compile MO files
  run: wp i18n make-mo languages/
- name: Generate JS JSON
  run: wp i18n make-json languages/ --no-purge
```

## Notes

- Never concatenate translatable strings: `__( 'Hello' ) . ' ' . __( 'World' )` — translators can't reorder. Use `sprintf( __( 'Hello %s', 'my-plugin' ), $name )`.
- Never use variables as the first argument: `__( $dynamic_string, 'my-plugin' )` — POT extractors can't find these strings.
- RTL languages (Arabic, Hebrew, Farsi): WordPress detects RTL from the locale and loads `rtl.css` automatically. Mirror your `style.css` in `style-rtl.css` for layout flips.
- `wp i18n` commands require WP-CLI 2.2+. In CI, install via `curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`.
