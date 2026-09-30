---
name: wp-build-tools
description: Use when setting up or debugging the JavaScript/CSS build pipeline for a WordPress plugin — @wordpress/scripts, webpack, Vite, block editor assets, asset enqueuing with .asset.php files, compiling Sass/PostCSS, or reusing a JS/CSS library bundled by a dependency plugin (e.g. EDD/WooCommerce) instead of vendoring your own. Not for block registration logic or the Gutenberg API — use wordpress-dev.
---

# WordPress Plugin Build Tools

Configure and operate the JS/CSS build pipeline for WordPress plugins: `@wordpress/scripts` (webpack-based), Vite alternative, asset manifest handling, and correct enqueuing with the generated `.asset.php` dependency file.

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

- "Set up `@wordpress/scripts`", "configure webpack for my plugin".
- "Build blocks and admin scripts", "compile Sass for a plugin".
- "Why isn't my JS loading?", "fix asset enqueue with versioned hash".
- "Switch from @wordpress/scripts to Vite".
- "Set up separate entry points for front-end vs admin vs block editor".

**Not for:** Block registration, `block.json` structure, Gutenberg API, or PHP-side REST — no dedicated skill is installed for these; use `wordpress-dev` (see its `reference/blocks-react.md` and `reference/apis.md`). A project-specific dev skill outranks both when one owns that codebase.

## Method

### 1. Install @wordpress/scripts

```bash
npm install --save-dev @wordpress/scripts
```

**`package.json`:**
```json
{
  "scripts": {
    "build":   "wp-scripts build",
    "start":   "wp-scripts start",
    "lint:js": "wp-scripts lint-js",
    "lint:css": "wp-scripts lint-style"
  }
}
```

Default entry point: `src/index.js` → `build/index.js` + `build/index.asset.php`.

### 2. Multiple entry points

Create `webpack.config.js` at plugin root to override the default entry:

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
    ...defaultConfig,
    entry: {
        'admin':        './src/admin/index.js',
        'frontend':     './src/frontend/index.js',
        'block-editor': './src/blocks/index.js',
        'style-admin':  './src/admin/admin.scss',
    },
};
```

Outputs:
```
build/
├── admin.js          + admin.asset.php
├── frontend.js       + frontend.asset.php
├── block-editor.js   + block-editor.asset.php
└── style-admin.css   (no .asset.php for pure CSS entry)
```

### 3. Enqueue assets correctly

The `.asset.php` file contains the dependency array and a content hash — always use it.

```php
function my_plugin_enqueue_admin_assets() {
    $asset_file = plugin_dir_path( __FILE__ ) . 'build/admin.asset.php';
    if ( ! file_exists( $asset_file ) ) return;

    $asset = include $asset_file;

    wp_enqueue_script(
        'my-plugin-admin',
        plugin_dir_url( __FILE__ ) . 'build/admin.js',
        $asset['dependencies'],  // auto-includes wp-element, wp-i18n, etc.
        $asset['version'],       // content hash — cache busted on change
        true                     // in footer
    );

    wp_enqueue_style(
        'my-plugin-admin-style',
        plugin_dir_url( __FILE__ ) . 'build/style-admin.css',
        [],
        $asset['version']
    );

    // Pass PHP data to JS
    wp_localize_script( 'my-plugin-admin', 'myPluginData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'my_plugin_action' ),
        'apiUrl'  => rest_url( 'my-plugin/v1/' ),
    ] );
}
add_action( 'admin_enqueue_scripts', 'my_plugin_enqueue_admin_assets' );
```

For block assets registered via `block.json` — do NOT manually enqueue; WP handles it:
```php
register_block_type( __DIR__ . '/build/my-block' ); // reads block.json automatically
```

### 4. Sass / PostCSS

`@wordpress/scripts` supports Sass out of the box (via webpack sass-loader). No extra config needed for `.scss` files imported in JS:

```js
// src/admin/index.js
import './admin.scss';
```

For standalone `.scss` entry (CSS-only build):
```js
// webpack.config.js entry
entry: {
    'admin-styles': './src/admin/admin.scss',
}
```

Output: `build/admin-styles.css` (no `.asset.php` generated for pure CSS entries — hardcode version or use `filemtime()`).

PostCSS config (`postcss.config.js`) is picked up automatically if present:
```js
module.exports = {
    plugins: {
        autoprefixer: {},
        'postcss-custom-properties': {},
    },
};
```

### 5. Vite alternative

For non-block plugins where `@wordpress/scripts` dependency auto-detection isn't needed:

```bash
npm install --save-dev vite @vitejs/plugin-legacy
```

**`vite.config.js`:**
```js
import { defineConfig } from 'vite';
import legacy from '@vitejs/plugin-legacy';

export default defineConfig( {
    plugins: [ legacy( { targets: [ 'defaults', 'ie >= 11' ] } ) ],
    build: {
        outDir: 'build',
        rollupOptions: {
            input: {
                admin: 'src/admin/index.js',
                frontend: 'src/frontend/index.js',
            },
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name]-[hash].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
} );
```

**Caveat:** Vite does not generate `.asset.php`. Manage WP script dependencies manually, or use `wp-scripts` for anything that imports `@wordpress/*` packages (they must be `externals`).

### 6. Externals — don't bundle WordPress packages

`@wordpress/scripts` automatically externalises all `@wordpress/*` imports (they're on the global `wp` object). If you use a custom webpack config, preserve this:

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
// defaultConfig already has the correct externals — spread it, don't replace it
module.exports = { ...defaultConfig, entry: { ... } };
```

Never `import { useState } from 'react'` in WP code — import from `@wordpress/element`:
```js
import { useState, useEffect } from '@wordpress/element';
```

### 7. `.gitignore` and production builds

```gitignore
node_modules/
build/
```

Include `build/` in the SVN/release zip but NOT in git. In the release workflow (`wp-plugin-release` + `wp-org-submission`), run `npm run build` before zipping.

CI build step for GitHub Actions:
```yaml
- uses: actions/setup-node@v4
  with:
    node-version: '20'
    cache: 'npm'
- run: npm ci
- run: npm run build
```

### 8. Reuse a dependency's bundled library instead of vendoring your own

When a plugin you already hard-depend on (e.g. EDD, WooCommerce) ships a front-end library you need — Tom Select, Select2, Choices, flatpickr — enqueue *its* copy rather than vendoring a second one. Saves bundle size and a maintenance surface, at the cost of coupling to the host's file paths.

```php
function my_plugin_enqueue_tom_select(): bool {
    if ( ! defined( 'EDD_PLUGIN_URL' ) ) {
        return false; // dependency not active — caller falls back to native <select>
    }
    $url = EDD_PLUGIN_URL;
    $dir = defined( 'EDD_PLUGIN_DIR' ) ? EDD_PLUGIN_DIR : '';
    $js  = 'assets/vendor/js/tom-select.complete.min.js';
    $css = 'assets/build/css/admin/chosen.min.css'; // host's TS skin lives here

    // Guard the paths so a host restructure degrades gracefully, never fatals.
    if ( $dir && ( ! file_exists( $dir . $js ) || ! file_exists( $dir . $css ) ) ) {
        return false;
    }
    $ver = defined( 'EDD_VERSION' ) ? EDD_VERSION : MY_PLUGIN_VERSION;
    wp_enqueue_script( 'my-plugin-tom-select', $url . $js, [], $ver, true );
    wp_enqueue_style( 'my-plugin-tom-select', $url . $css, [], $ver );
    return true;
}
// Make your own script depend on it only when present:
$dep = my_plugin_enqueue_tom_select() ? [ 'my-plugin-tom-select' ] : [];
wp_enqueue_script( 'my-plugin-admin', $assets . 'js/admin.js', $dep, MY_PLUGIN_VERSION, true );
```

Rules that make this hold up:

- **Build against the host's own constant/handle**, not a hardcoded URL into another plugin's directory. Prefer reusing a registered handle (`wp_enqueue_script('edd-tom-select')`) when the host registers it on *all* admin pages; if registration is page-scoped or order-dependent, register your own handle pointing at the bundled file (as above) for deterministic loading.
- **Always degrade.** Return a flag; init JS behind `if (typeof TomSelect !== 'undefined')`; leave the markup a real `<select>` so it works with the library absent.
- **Initialise in JS, don't fight the host's skin in markup.** For a remote/AJAX field, give the library a `load` callback hitting your `wp_ajax_*` endpoint and sync any hidden companion field (e.g. a stored label) on change.
- **Expect to override the host's styling.** The bundled skin is themed for the host. Re-skin the library's classes (`.ts-control`, `.ts-dropdown`, etc.) to your design system. WordPress admin skins carry version-gated, high-specificity selectors — EDD's `body[class*="branch-7"]` rules (WP 6.7+) out-specify a plain `.my-wrap` scope — so targeted `!important` is often required to win, and load your stylesheet after the host's.

## Notes

- When borrowing a host plugin's bundled library, pin nothing about its internal version; treat the file paths as the contract and guard them (see §8). Document the coupling in the PR so a host upgrade that moves the files is easy to trace.
- Always use `npm ci` (not `npm install`) in CI — respects `package-lock.json` exactly.
- `@wordpress/scripts` pins its webpack/babel versions; don't add conflicting `webpack` or `babel-loader` to `devDependencies`.
- For TypeScript: `@wordpress/scripts` supports `.ts`/`.tsx` out of the box — just rename files and add `tsconfig.json`.
- Minimum Node version for `@wordpress/scripts` v27+: Node 20.
- Use `wp-scripts lint-js` and `wp-scripts lint-style` in CI alongside PHPCS (`wp-coding-standards`) for full code quality coverage.
