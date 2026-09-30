# Themes & Blocks Reference

Covers classic themes, block (FSE) themes, and building Gutenberg blocks. First **identify
which kind of theme** you're in (see SKILL Step 1) — the workflow is completely different.

---

## A. Classic themes

### Template hierarchy (what file renders what)
WordPress picks the most specific matching template, falling back to `index.php`. Key paths:

- Front page: `front-page.php` → `home.php` (blog) → `index.php`
- Single post: `single-{post-type}.php` → `single.php` → `singular.php` → `index.php`
- Page: `page-{slug}.php` → `page-{id}.php` → `page.php` → `singular.php` → `index.php`
- Archive: `archive-{post-type}.php` → `archive.php` → `index.php`
- Taxonomy: `taxonomy-{tax}-{term}.php` → `taxonomy-{tax}.php` → `taxonomy.php` → `archive.php`
- Category/Tag: `category-{slug|id}.php` → `category.php` → `archive.php`
- Search: `search.php`; 404: `404.php`; author/date: `author.php`/`date.php`
- Attachment: `{mime}.php` → `attachment.php` → `single.php`

Use `get_header()`, `get_footer()`, `get_sidebar()`, and **`get_template_part( 'template-parts/content', get_post_type() )`**
for reusable chunks (pass `$args` since WP 5.5). Locate files with `locate_template()`.

### The Loop
```php
if ( have_posts() ) :
    while ( have_posts() ) : the_post();
        the_title( '<h2>', '</h2>' );
        the_content();      // filtered, safe-ish; the_excerpt() for excerpts
    endwhile;
else :
    esc_html_e( 'Nothing found.', 'textdomain' );
endif;
```
Secondary loops: `new WP_Query(...)` then `wp_reset_postdata()` after. **Escape template
output** — `the_*` template tags are generally filtered, but `get_*` values, meta, and option
values you echo must be escaped (`esc_html`, `esc_attr`, `esc_url`).

### functions.php essentials
- `add_action( 'after_setup_theme', … )`: `add_theme_support( 'post-thumbnails' | 'title-tag' | 'html5' | 'responsive-embeds' | 'editor-styles' | 'align-wide' | 'custom-logo' )`, `register_nav_menus()`, `load_theme_textdomain()`.
- `add_action( 'widgets_init', … )`: `register_sidebar()`.
- `add_action( 'wp_enqueue_scripts', … )`: enqueue `style.css` + scripts (with deps + version).
- Modify the main query via **`pre_get_posts`** (guard `! is_admin() && $q->is_main_query()`).

### Child themes (the correct way to customize someone else's theme)
- `style.css` header with `Template: parent-slug`; enqueue parent + child styles (don't rely on `@import`).
- Override a template by copying it into the child; override a function only if the parent
  wrapped it in `if ( ! function_exists() )` (pluggable). Otherwise hook the parent's
  actions/filters. **Never edit the parent theme directly.**

---

## B. Block (FSE / Full Site Editing) themes

A block theme renders entirely from **block markup** + `theme.json`; there's often no
`header.php`/`index.php` PHP loop. Detect by presence of `theme.json` + `templates/*.html`.

### Required structure
```
style.css            (header only — name/version/etc.)
theme.json           (the design + settings contract)
templates/           index.html, single.html, page.html, archive.html, 404.html …
parts/               header.html, footer.html, …
patterns/            *.php block patterns (auto-registered)
styles/              *.json  global style variations
functions.php        (optional — enqueue, supports, register patterns/block styles)
```

### theme.json — the single source of design truth
- `"$schema"` + `"version": 3` (current). Three top sections that matter:
  - **`settings`** — what's *available*: `color.palette`, `typography.fontSizes`/`fontFamilies`,
    `spacing.spacingSizes`, `layout.contentSize`/`wideSize`, `useRootPaddingAwareAlignments`,
    and toggles (`color.custom`, `appearanceTools`). Settings generate CSS **custom properties**
    (`--wp--preset--color--…`) and editor controls.
  - **`styles`** — the *defaults*: global `color`, `typography`, `spacing`, `elements`
    (`link`, `button`, `heading`), and **`blocks`** (per-block-type styles).
  - **`templateParts`** + **`customTemplates`** — register parts and selectable page templates.
- **Style variations:** drop alternate `theme.json`-shaped files in `styles/` — users switch
  them in the Site Editor. Great for light/dark or brand variants.
- Prefer theme.json over hand-written CSS for anything it can express; it keeps the editor and
  front-end in sync and exposes controls to users. Enqueue extra CSS only for what theme.json
  can't do.

### Templates & parts
- `.html` files are block markup (HTML comments like `<!-- wp:group -->`). Use the **Site
  Editor** to build, then export/copy markup into files for version control.
- Reference parts with `<!-- wp:template-part {"slug":"header"} /-->`.
- Dynamic data via core blocks (Post Title, Post Content, Query Loop) and **block bindings**
  (WP 6.5+) to bind block attributes to post meta / custom sources.

### Block patterns (file-based, auto-registered)
Put a `.php` file in `patterns/` with a header comment block; WP 6.0+ auto-registers it:
```php
<?php
/**
 * Title: Hero
 * Slug: mytheme/hero
 * Categories: featured
 * Keywords: hero, banner
 */
?>
<!-- wp:cover ... --> ... <!-- /wp:cover -->
```
Use `esc_*`/`__()` inside pattern PHP for any dynamic/translatable bits. Register a pattern
**category** with `register_block_pattern_category()` if needed.

---

## C. Building Gutenberg blocks

### Anatomy
A block is registered from **`block.json`** (metadata) and implemented in JS (`edit`/`save`)
or PHP (`render_callback` for dynamic blocks).

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "myplugin/card",
  "title": "Card",
  "category": "widgets",
  "icon": "id-card",
  "attributes": { "heading": { "type": "string", "source": "html", "selector": "h3" } },
  "supports": { "html": false, "align": true, "color": { "background": true } },
  "textdomain": "myplugin",
  "editorScript": "file:./index.js",
  "style": "file:./style-index.css",
  "viewScript": "file:./view.js",
  "render": "file:./render.php"
}
```

### Register
```php
add_action( 'init', function() {
    register_block_type( __DIR__ . '/build/card' );  // reads block.json; handles all assets
} );
```
`register_block_type` with the directory path auto-enqueues `editorScript`/`style`/`viewScript`
and wires `render` (dynamic). Always register on `init`.

### Static vs dynamic
- **Static:** `save()` returns markup stored in post content. Changing markup later requires a
  **block deprecation** (`deprecated` array) or you get "block validation" errors.
- **Dynamic:** omit/return `null` from `save()` and provide a **`render_callback`** /
  `render` PHP file — markup is generated at render time (always current; ideal for
  query-driven or frequently-changing output). Escape all output in the render file.

### edit/save (JS, `@wordpress/scripts`)
```jsx
import { useBlockProps, RichText } from '@wordpress/block-editor';
export default function Edit( { attributes, setAttributes } ) {
    const props = useBlockProps();
    return <div { ...props }>
        <RichText tagName="h3" value={ attributes.heading }
            onChange={ ( heading ) => setAttributes( { heading } ) } />
    </div>;
}
```
- Use `@wordpress/components` (InspectorControls, PanelBody, ToolbarGroup) for the sidebar/
  toolbar; `InnerBlocks` / `useInnerBlocksProps` for nested content.
- `useBlockProps()` (editor) and `useBlockProps.save()` (save) are **required** in apiVersion
  2+ for alignment/supports/styles to work.
- Build with `@wordpress/scripts` (`wp-scripts build`/`start`); it emits `build/` + an
  `*.asset.php` with the correct dependency array and version. Point `block.json` at `build/`.
- **Interactivity API** (`@wordpress/interactivity`, `viewScriptModule`) for front-end
  interactivity without a heavy framework — preferred over jQuery for new view scripts.

### Block extras
- **Block styles:** `register_block_style()` (PHP) or `registerBlockStyle` (JS) to add a style
  variant to any block.
- **Block variations:** `registerBlockVariation` for preset configurations of a block.
- **Filters:** `blocks.registerBlockType` and `editor.BlockEdit` (JS hooks) to extend core
  blocks — the block-editor equivalent of PHP filters. Don't fork core blocks; extend them.
- **Block bindings** (WP 6.5+): bind attributes to dynamic sources (post meta, custom) so
  static blocks show live data.

---

## Theme/block gotchas

- Block editor requires CPTs/meta to declare `show_in_rest => true`, or they won't appear.
- Static blocks break on markup change without a `deprecated` migration — prefer dynamic for
  anything that may evolve.
- `theme.json` overrides much CSS via specificity/custom properties — debug with the editor's
  generated styles before adding `!important`.
- Don't enqueue front-end CSS the theme.json already provides; don't duplicate editor vs front
  styles (use `enqueue_block_assets` for shared, `enqueue_block_editor_assets` for editor-only).
- Child block themes override parent templates/parts by same-named files; design via a child
  `theme.json` or a `styles/` variation.
