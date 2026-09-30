# Block-Editor JavaScript & React Reference

The JS half of modern WordPress: the `@wordpress/data` store model, entity records, editing
post meta from the sidebar, SlotFill plugins, building standalone React admin apps, and
`@wordpress/api-fetch`. `reference/themes-blocks.md` covers a block's anatomy (`block.json`,
`edit`/`save`, dynamic render) — this file covers the **data and UI layer** around it. Build
everything here with `@wordpress/scripts` so dependencies are extracted automatically.

---

## 1. The `@wordpress/data` store model

WordPress ships a Redux-like global state layer. You read with **selectors**, write with
**actions**, and never touch state directly. In React, use the hooks — not the old HOCs.

```jsx
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

function MyPanel() {
    // Read — re-renders when the selected value changes. List EVERY dependency in the array.
    const postTitle = useSelect(
        ( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
        []
    );
    const isSaving = useSelect( ( select ) => select( editorStore ).isSavingPost(), [] );

    // Write
    const { editPost } = useDispatch( editorStore );
    const { createSuccessNotice } = useDispatch( noticesStore );

    return <button onClick={ () => editPost( { title: 'New' } ) }>Set title</button>;
}
```

- **Import the store object** (`store as coreStore`) and pass it to `select()/dispatch()` —
  passing the `'core'` string still works but the object form is tree-shakeable and typo-proof.
- **`useSelect` dependency array:** like `useEffect`. Anything from the outer scope the mapping
  callback uses must be listed, or you get stale reads. Empty `[]` only when the callback
  closes over nothing external.
- **`select` vs `resolveSelect`:** plain `select()` returns whatever is cached *now* (may be
  `undefined` on first call while a resolver runs); `resolveSelect()` returns a Promise that
  waits for resolution — use it in actions/event handlers, not in render.
- Legacy `withSelect`/`withDispatch` HOCs still exist; prefer hooks in new code.

### Core stores you'll actually use
| Store (import `store as …`) | Module | What it holds |
|---|---|---|
| `coreStore` | `@wordpress/core-data` | Entities: posts, users, taxonomies, **post meta**, site settings |
| `editorStore` | `@wordpress/editor` | The post being edited (title/content/meta/save state) |
| `blockEditorStore` | `@wordpress/block-editor` | Blocks, selection, insertion |
| `noticesStore` | `@wordpress/notices` | Admin/snackbar notices |
| `preferencesStore` | `@wordpress/preferences` | Per-user editor preferences |

> Note: in WP 6.6+ the post-editing UI consolidated under `@wordpress/editor`; older code used
> `@wordpress/edit-post`. Several `edit-post` exports (e.g. `PluginDocumentSettingPanel`) are
> **deprecated re-exports** — import them from `@wordpress/editor` in new code.

---

## 2. Entity records — the right way to read/write content

Don't hand-roll REST calls for posts/meta/users — `core-data` gives you cached, resolved,
auto-invalidating entity access.

```jsx
import { useEntityProp, useEntityRecord } from '@wordpress/core-data';

// Edit current post's meta (two-way bound to the editor's save):
const [ meta, setMeta ] = useEntityProp( 'postType', 'movie', 'meta' );
const rating = meta?.movie_rating ?? '';
setMeta( { ...meta, movie_rating: 5 } );          // queues into the post save — no extra request

// Load an arbitrary record by id:
const { record, isResolving } = useEntityRecord( 'postType', 'page', pageId );
```

- For meta to appear here it **must be registered with `show_in_rest => true`** and an
  `auth_callback` (see `reference/apis.md` → Meta). Unregistered meta is invisible to the editor.
- Imperative writes outside the post-save flow: `saveEntityRecord( 'postType', 'page', { id, title } )`
  via `useDispatch( coreStore )` — returns a Promise; surface errors as notices.
- `getEntityRecords( 'postType', 'post', query )` for lists; resolution is cached per-args.

---

## 3. Editing post meta from the sidebar (SlotFill plugins)

A "plugin" here is editor UI injected via SlotFill, registered with `registerPlugin`.

```jsx
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';   // not edit-post in 6.6+
import { TextControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';

const Panel = () => {
    const [ meta, setMeta ] = useEntityProp( 'postType', 'movie', 'meta' );
    return (
        <PluginDocumentSettingPanel name="movie-meta" title="Movie Details">
            <TextControl
                label="Director"
                value={ meta?.movie_director ?? '' }
                onChange={ ( v ) => setMeta( { ...meta, movie_director: v } ) }
            />
        </PluginDocumentSettingPanel>
    );
};
registerPlugin( 'movie-meta-panel', { render: Panel } );
```

Enqueue this on **`enqueue_block_editor_assets`**. Other useful SlotFills: `PluginSidebar`
(+`PluginSidebarMoreMenuItem`), `PluginPostStatusInfo`, `PluginPrePublishPanel`,
`PluginMoreMenuItem`. For block-toolbar/inspector extensions use `BlockControls` /
`InspectorControls` from `@wordpress/block-editor` inside the block's `edit`.

---

## 4. `@wordpress/components` — the UI kit

Use these instead of raw HTML for editor/admin UI so it matches WordPress and is accessible:
`PanelBody`, `TextControl`, `SelectControl`, `ToggleControl`, `RangeControl`, `Button`,
`ComboboxControl`, `Notice`, `Spinner`, `Card`, `__experimentalNumberControl`, color/gradient
pickers, `MediaUpload` (media library). Components prefixed `__experimental`/`__unstable` can
change between releases — pin your `@wordpress/components` version and re-check on upgrade.

---

## 5. `@wordpress/api-fetch` — talking to REST from JS

```jsx
import apiFetch from '@wordpress/api-fetch';

const things = await apiFetch( { path: '/myplugin/v1/things?per_page=20' } );      // GET
await apiFetch( { path: '/myplugin/v1/things', method: 'POST', data: { name } } ); // write
```

- `apiFetch` auto-attaches the **`X-WP-Nonce`** (`wp_rest`) and the site root — when the editor
  bootstraps it. In a **standalone admin page** you must prime it:
  `apiFetch.use( apiFetch.createNonceMiddleware( nonce ) )` and
  `apiFetch.use( apiFetch.createRootURLMiddleware( restRootUrl ) )`, passing values you
  localized from PHP (`wp_create_nonce('wp_rest')`, `rest_url()`).
- Pagination headers (`X-WP-Total`, `X-WP-TotalPages`) require `parse: false` to read the raw
  `Response`. Errors reject with `{ code, message, data: { status } }` — catch and show a
  `Notice`.
- The REST route's `permission_callback` is still the real gate (see `reference/apis.md`) — the
  nonce alone is not authorization.

---

## 6. Building a standalone React admin page

For a settings/dashboard page that's a full React app (not a SlotFill):

```php
// PHP — register the page and enqueue the wp-scripts build with its generated deps:
add_action( 'admin_menu', fn() => add_menu_page(
    'MyPlugin', 'MyPlugin', 'manage_options', 'myplugin', fn() => print '<div id="myplugin-root"></div>'
) );
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_myplugin' !== $hook ) return;          // your page only
    $asset = require MYPLUGIN_DIR . 'build/admin.asset.php';   // { dependencies, version }
    wp_enqueue_script( 'myplugin-admin', plugins_url( 'build/admin.js', __FILE__ ),
        $asset['dependencies'], $asset['version'], true );
    wp_enqueue_style( 'wp-components' );                       // get the component styles
    wp_localize_script( 'myplugin-admin', 'MyPluginAdmin', array(
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'restUrl' => esc_url_raw( rest_url() ),
    ) );
} );
```

```jsx
// JS — mount with @wordpress/element (React wrapper):
import { createRoot } from '@wordpress/element';
import App from './App';
const el = document.getElementById( 'myplugin-root' );
if ( el ) createRoot( el ).render( <App /> );    // createRoot is WP 6.2+ (React 18); older: render()
```

- Use `@wordpress/element` (not a bundled `react`) so you share WordPress's React and keep the
  bundle small — wp-scripts externalizes it into the `*.asset.php` dependency array.
- Enqueue `wp-components` style or your UI is unstyled. Load only on **your** admin screen
  (check `$hook`).

---

## 7. Notices — user feedback the WordPress way

```jsx
const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );
createSuccessNotice( 'Saved.', { type: 'snackbar' } );      // toast; omit type for a banner
```

Don't `alert()` or roll your own toast — the notices store renders in the editor/admin chrome
and is accessible.

---

## 8. A custom data store (when local state isn't enough)

For app-wide state shared across components, register your own store instead of prop-drilling:

```jsx
import { createReduxStore, register } from '@wordpress/data';
const store = createReduxStore( 'myplugin/data', {
    reducer, actions, selectors, resolvers,    // resolvers lazy-load via apiFetch on first select
} );
register( store );
```

Then `useSelect( ( s ) => s( 'myplugin/data' ).getThings() )`. Resolvers turn a selector into a
lazy data fetch (call apiFetch, then `dispatch` a "received" action) — the same mechanism
`core-data` uses.

---

## Block-editor JS gotchas

- **Stale reads:** an incomplete `useSelect` dependency array is the most common bug — the
  panel shows old data. List every external value the callback reads.
- **Wrong package import:** importing editor APIs from `@wordpress/edit-post` (deprecated path)
  vs `@wordpress/editor` causes console deprecation warnings; use the new path.
- **Meta not showing:** unregistered meta, or meta without `show_in_rest`, is invisible to
  `useEntityProp` — fix it in PHP, not JS.
- **`apiFetch` 401/403 on a standalone page:** you forgot the nonce/root middleware — the editor
  sets them up automatically but a custom admin page doesn't.
- **Don't bundle your own React/Redux** — externalize via `@wordpress/element` and
  `@wordpress/data` (wp-scripts does this; verify the `*.asset.php` deps).
- **`__experimental*` APIs** can break on a WP release — avoid in shipped products where you
  can, and pin `@wordpress/*` versions if you must use them.
