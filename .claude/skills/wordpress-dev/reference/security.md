# WordPress Security Reference

The single most important WordPress skill: **sanitize on input, validate, escape on output**,
and **gate every privileged action with a capability check + a nonce.** This file is the
authoritative function-selection guide. When in doubt, escape later (at output) and harder.

## The data-trust model

Treat as **untrusted**: `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER` (yes,
including `REQUEST_URI`, `HTTP_REFERER`, `HTTP_HOST`), uploaded files, anything from the DB,
any remote API/feed, shortcode/block attributes, URL params, and option values that a user
could have set. Trust nothing until you've sanitized or validated it; escape it again on the
way out regardless.

**Superglobals are slashed.** WordPress adds slashes to `$_GET/$_POST/$_REQUEST/$_COOKIE`.
Always `wp_unslash()` **before** sanitizing/using them:

```php
$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
```

## 1. Sanitize on input — pick by data type

| Incoming data | Sanitizer |
|---|---|
| Single-line text | `sanitize_text_field()` |
| Multi-line text | `sanitize_textarea_field()` |
| Email | `sanitize_email()` (then validate with `is_email()`) |
| Integer | `absint()` (non-negative) / `intval()` |
| Float | `floatval()` |
| URL for storage/redirect | `esc_url_raw()` / `sanitize_url()` (WP 5.9+ alias) |
| Slug / key (lowercase, `a-z0-9_-`) | `sanitize_key()` |
| Title → slug | `sanitize_title()` |
| File name | `sanitize_file_name()` |
| HTML class | `sanitize_html_class()` |
| Hex color | `sanitize_hex_color()` |
| Rich HTML you intend to keep | `wp_kses_post()` or `wp_kses( $html, $allowed )` |
| Array of values | iterate and sanitize **each** by its own type |
| JSON / serialized blob | **decode first**, then sanitize each value by type — never sanitize the blob as one string |

### Read superglobals, NOT `filter_input()` — three independent reasons

`$_GET`/`$_POST`/`$_REQUEST` are the correct source. `filter_input()` looks tidier and is wrong here:

1. **It reads the ORIGINAL request table, which `wp_magic_quotes()` never touched.** WP re-slashes the
   superglobals during load, so `$_POST` needs exactly one `wp_unslash()` — but `filter_input()` returns
   the *unslashed* value already. `filter_input() + wp_unslash()` is a **double-unslash that silently
   eats every backslash the user typed** (`Rolex \Daytona` → `Rolex Daytona`; a password containing `\`
   can never be entered). This is a live data-corruption bug, not a style issue.
2. **WPCS is blind to it.** `WordPress.Security.NonceVerification` and `ValidatedSanitizedInput` only
   inspect superglobals — a codebase using `filter_input()` gets a **vacuous 0-error phpcs run**, and a
   .org reviewer grepping for `$_POST` can't see your guards either. Both read as "no protection".
3. **It returns `NULL` under CLI** (and for `INPUT_SERVER` under some FastCGI configs), so input helpers
   become untestable from PHPUnit/WP-CLI — which is how bug #1 survives.

Sniff-clean shape — superglobal, unslash and sanitize in **one statement**, with `isset()`/`is_scalar()`
as a separate early return. Sanitizing on a later line makes `InputNotSanitized`/`MissingUnslash` fire,
and suppressing *those* is what looks bad; only the nonce ignore is legitimate:

```php
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleSubmission() before any field is read.
if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { return $default; }
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handleSubmission() before any field is read.
return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
```

`.Missing` for `$_POST`, `.Recommended` for `$_GET`; **always** write a real justification after `--`.
`$_SERVER` is not slashed by WP (unslash is a no-op there, but WPCS demands it). Passwords must be read
**raw** — no `sanitize_text_field()`, which strips tags and collapses whitespace. And after converting a
helper, grep its **consumers** for a second `wp_unslash` that used to compensate.

Validation ≠ sanitization. After sanitizing, **validate** that the value is allowed:
`is_email()`, `in_array( $v, $allowed, true )` for enums, `array_key_exists()`, range checks,
`wp_http_validate_url()` for outbound URLs, regex for formats. Reject (don't silently coerce)
invalid privileged input.

## 2. Escape on output — pick by context (late escaping)

Escape **at the moment of output**, in the template/echo — not when storing. Match the
**context** the value lands in:

| Output context | Function |
|---|---|
| HTML text node | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL in `href`/`src` | `esc_url()` |
| Inside `<textarea>` | `esc_textarea()` |
| Inline JS value | `esc_js()` (better: pass data via `wp_localize_script`/`wp_add_inline_script` and JSON) |
| Rich HTML (post content allowlist) | `wp_kses_post()` |
| Rich HTML (custom allowlist) | `wp_kses( $html, $allowed_html )` |
| Integer in output | `(int)` / `absint()` then echo |
| Translated string to echo | `esc_html__()`, `esc_attr__()`, `esc_html_e()`, `esc_attr_e()` |

- **Storage vs output for URLs:** `esc_url_raw()`/`sanitize_url()` for DB and redirects;
  `esc_url()` for HTML output.
- **`_e()`/`__()` are NOT escaping.** Use the `esc_*__`/`esc_*_e` variants when echoing.
- `printf`/`sprintf` with translated format strings: escape the *result* or use
  `esc_html()` around it; never trust `%s` to be safe.
- Even data you "know" is safe (your own option, a post title) gets escaped on output —
  defense in depth, and it costs nothing.

## 3. Nonces — stop CSRF on every state-changing action

A nonce proves the request came from a legitimate, intentional user action on your site.
Required on **every** form, link, AJAX, and REST call that changes state.

**Create:**
```php
wp_nonce_field( 'myplugin_save_thing', '_myplugin_nonce' );   // form hidden field
$url = wp_nonce_url( $action_url, 'myplugin_delete_thing' );    // action link
$nonce = wp_create_nonce( 'myplugin_action' );                 // for JS/AJAX
```

**Verify (and `die`/bail on failure):**
```php
check_admin_referer( 'myplugin_save_thing', '_myplugin_nonce' ); // admin forms
check_ajax_referer( 'myplugin_action', 'nonce' );                // admin-ajax
if ( ! wp_verify_nonce( $_POST['_myplugin_nonce'] ?? '', 'myplugin_save_thing' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'textdomain' ) );
}
```

- **REST API** uses the `wp_rest` nonce sent in the `X-WP-Nonce` header; `wp_localize_script`
  it from `wp_create_nonce( 'wp_rest' )`. The permission callback (below) is the real gate.
- Nonces are **not** authentication — they're tied to user+action+lifetime (default 12–24h).
  Always pair with a capability check.

## 4. Capabilities — stop the wrong user (authorization)

Check **capabilities**, never roles (`administrator`). Caps survive role customization and
multisite.

```php
if ( ! current_user_can( 'manage_options' ) ) {            // settings-level
    wp_die( esc_html__( 'Insufficient permissions.', 'textdomain' ) );
}
if ( ! current_user_can( 'edit_post', $post_id ) ) { … }   // object-level (meta cap)
```

Common caps: `manage_options` (admin/settings), `edit_posts`/`publish_posts`,
`edit_post`/`delete_post`/`read_post` (per-object meta caps — always prefer the object form
when acting on a specific item, to prevent IDOR), `upload_files`, `list_users`,
`manage_categories`. Custom post types should declare `capability_type`/`map_meta_cap` so
they integrate with this system. For custom tables, you choose and check the cap yourself.

**The rule:** capability check **and** nonce on every admin-post, AJAX, REST-write, and form
handler. Plus an **object-level** check whenever the action targets a specific record (verify
the current user may touch *that* row/post — not just that they're an editor).

## 5. SQL safety — always prepare

```php
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}my_table WHERE status = %s AND id = %d",
        $status,
        $id
    )
);
```

- Placeholders: `%s` string, `%d` integer, `%f` float, **`%i` identifier** (table/column,
  WP 6.2+). Never put a variable directly in the query string.
- `LIKE`: `$wpdb->prepare( "… LIKE %s", '%' . $wpdb->esc_like( $term ) . '%' )`.
- `IN (...)`: build the placeholder list dynamically:
  `$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );` then prepare with `...$ids`.
- The helpers `$wpdb->insert()/update()/delete()/replace()` auto-prepare data (pass format
  arrays) — prefer them for single-row writes.
- Table names: `$wpdb->prefix`, `$wpdb->posts`, `$wpdb->postmeta`, `$wpdb->users`, … — never
  hardcode `wp_`. `esc_sql()` only for values you can't parameterize (rare).
- `dbDelta()` for schema (CREATE/ALTER) — it has strict formatting rules (two spaces after
  `PRIMARY KEY`, lowercase types, a `KEY` per index). Version-gate it.

## 6. File uploads & filesystem

- Handle uploads with `wp_handle_upload()` / `media_handle_upload()` — never trust the
  client-provided name or type.
- Verify type by content: `wp_check_filetype_and_ext()`; restrict to an allowlist of
  extensions/MIME types. Block SVG and other executable/scriptable types unless you sanitize
  them and the user is trusted.
- Write only via the **WP Filesystem API** (`WP_Filesystem()`), or to `wp_upload_dir()` —
  never assume direct `fopen` permissions; respect `FS_METHOD`.
- Never include/`require` a user-controlled path (LFI/RFI); never pass user input to
  `system()/exec()/shell_exec()/eval()`.

## 7. Outbound requests (SSRF) & remote data

- Use `wp_remote_get()/wp_remote_post()` (respect timeouts, check `is_wp_error`, check
  response code). For user-supplied URLs, validate with `wp_http_validate_url()` and prefer
  `wp_safe_remote_*` to block internal/loopback addresses.
- Treat **all** remote/API/feed responses as untrusted input — sanitize before use, escape on
  output.

## 8. Other must-knows

- **Redirects:** `wp_safe_redirect()` by default (allowlisted hosts). Use plain
  `wp_redirect()` only for an intentionally user-configured external URL, and `exit;` after.
- **KSES:** `wp_kses_post()` for post-like HTML; `wp_kses( $html, $allowed, $protocols )` for
  a custom allowlist. Use to neutralize stored XSS in rich fields.
- **Object injection:** never `unserialize()` untrusted data. WP's `maybe_unserialize()` on
  attacker-controlled values is still risky — prefer JSON (`wp_json_encode` / `json_decode`).
- **Authentication surface:** consider disabling/limiting XML-RPC and REST user enumeration
  if not needed; rate-limit/lockout is out of core scope (note it, don't hand-roll crypto).
- **Secrets:** never hardcode API keys/passwords; store in options (or constants in
  `wp-config.php`), never echo them, never log them.
- **Don't roll your own crypto/escaping/SQL-quoting** — use core functions; they're audited
  and context-correct.

## Security review checklist (run before "done")

- [ ] Every `$_GET/$_POST/$_REQUEST/$_COOKIE/$_SERVER` read is `wp_unslash()`'d + sanitized.
- [ ] Every echo/print/template output is escaped with the context-correct function.
- [ ] Every state-changing handler (form, AJAX, admin-post, REST write, cron-triggered-by-user)
      has a **nonce** check **and** a **capability** check — plus an **object-level** cap check
      when acting on a specific record.
- [ ] Every REST route declares a real `permission_callback` (never omitted; `__return_true`
      only for genuinely public reads).
- [ ] Every SQL statement is `$wpdb->prepare()`'d or uses the safe `insert/update/delete`
      helpers; no string-built queries; no hardcoded table prefix.
- [ ] File uploads are type-checked by content and allowlisted; no user-controlled
      include/require/exec.
- [ ] Outbound user-supplied URLs validated; `wp_safe_*` used where appropriate.
- [ ] Redirects use `wp_safe_redirect` unless an external URL is intentional.
- [ ] No `eval`/`extract`/`create_function`/untrusted `unserialize`/`@`-silencing.
- [ ] Errors/exceptions don't leak paths, queries, or secrets to users (only to `debug.log`).
