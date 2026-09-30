---
name: wp-plugin-audit
description: Use when asked to audit a WordPress plugin for inconsistencies, run a consistency/quality sweep, or "find inconsistencies" across code and docs. Fans out parallel checks across dimensions and verifies every finding before reporting.
---

# WordPress Plugin Consistency Audit

Read-only audit that surfaces inconsistencies across a WP plugin's code, config, and docs. Optimised for **recall with low false-positive rate**: every candidate is verified against the actual code before it reaches the report.

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

- "Audit the plugin", "find inconsistencies", "consistency/quality sweep".
- Before a release, or after a large refactor, to catch drift.

## Method

### 1. Fan out — 4 independent dimensions (parallel agents)

Dispatch one read-only agent per dimension (Explore or general-purpose), in a single message so they run concurrently. Each returns findings with `file:line`, the inconsistent value, and the expected/canonical form.

- **A — Version & metadata.** Cross-reference every version/metadata source: plugin header (`Version`, `Requires at least`, `Requires PHP`, `Tested up to`, `Text Domain`), the version constant, `readme.txt` (`Stable tag` + Changelog + Upgrade Notice), `composer.json`, the `.pot` `Project-Id-Version`, and the schema/DB version. Flag every mismatch; note fields that are *intentionally* independent (schema `$db_version` ≠ plugin version) so they aren't flagged.

  Also audit the **main plugin file header format** against the canonical PHPDoc DocBlock style (preferred over plain block comment):
  ```php
  /**
   * Plugin Name
   *
   * @package           PluginPackage
   * @author            Your Name
   * @copyright         2024 Your Name or Company Name
   * @license           GPL-2.0-or-later
   *
   * @wordpress-plugin
   * Plugin Name:       Plugin Name
   * Plugin URI:        https://example.com/plugin-name
   * Description:       Description of the plugin.
   * Version:           1.0.0
   * Requires at least: 5.2
   * Requires PHP:      7.2
   * Author:            Your Name
   * Author URI:        https://example.com
   * Text Domain:       plugin-slug
   * License:           GPL v2 or later
   * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
   * Update URI:        https://example.com/my-plugin/
   * Requires Plugins:  my-plugin, yet-another-plugin
   */
  ```
  Flag: missing `@wordpress-plugin` marker (distinguishes WP header from plain PHPDoc); missing `@package`/`@author`/`@copyright`/`@license` PHPDoc fields; `Description` over 140 characters; `License` slug not matching `License URI`; missing `Text Domain` when plugin has translated strings (requires Dimension B `__()` detection to confirm strings exist); using a plain `/* */` block instead of `/** */` PHPDoc block.
- **B — Naming / prefix / i18n.** Canonical prefix (e.g. `myplugin_` / `_myplugin_`) — flag legacy prefixes outside the migration file. Text-domain consistency on every `__()`/`_e()`/`esc_html__()`/`_n()`; missing translator comments on `sprintf`/`printf` with placeholders. `@package` tag variants. Option/transient/hook/REST/cookie/nonce/AJAX/asset-handle/CSS-class prefix uniformity.
- **C — Docs ↔ code.** Path references (renamed dirs), function/class/option/table names referenced in docs that no longer match code, documented commands that don't exist (`composer test:unit` etc.), test counts, architecture trees vs real files, behavior claims that contradict code. Distinguish *historical* docs (point-in-time, leave) from *current* docs (must match).
- **D — Code conventions.** DB-write style (ORM vs `$wpdb` per the repo's CLAUDE.md), docblock style, `@since` tags, capability/nonce coverage, return-type consistency, leftover renamed-dir refs, duplicated logic, escaping/sanitization uniformity. Tag each: bug-risk / convention / cosmetic.

Scale dimensions to the plugin; add domain-specific ones (REST security, capability model) when relevant.

### 2. Verify EVERY candidate before reporting

Do not trust agent output verbatim — agents over-report. For each finding, `grep`/`Read` the exact line and confirm it is real. Kill false positives. Real example caught this way: an `action_links` `sprintf('<a href="%s">%s</a>', …)` flagged for a "missing translator comment" is **not** translatable (pure HTML markup) → drop it.

### 3. Report (findings only — do NOT fix unless asked)

- Group by severity: 🔴 functional → 🟠 (security/i18n) → 🟡 stale/naming → ⚪ cosmetic.
- Each finding: `file:line`, what's wrong, what's correct.
- Include a **"Checked, NOT bugs"** section listing intentional patterns (so they don't get "fixed" by mistake): e.g. webhook with no capability check (HMAC-protected), ORM `update()` that routes to `$wpdb` internally, intentional legacy prefixes in the migration file.
- End with a suggested fix priority. Then ask whether to fix all or a subset.

## Notes

- Audit is read-only. Run on the current working tree (mention if it includes unmerged changes).
- When fixing afterward: many files span multiple finding-categories — a single cohesive "fix audit findings" commit with an enumerated body is cleaner than fragile per-scope partial staging.
- This audit covers *code consistency and conventions*. For directory-submission rules, route by question: trialware/freemium gating → `wp-trialware-check`; SVN deploy, assets, rejection patterns → `wp-org-submission`; deep security tracing → the `wp-security-auditor` **agent**. WordPress's official `wp-plugin-directory-guidelines` skill (the authoritative 18-guideline source) is **not installed** — do not cite it as if consulted.
- Findings here are read-only. To actually apply them, hand off to the `senior-code-reviewer` skill, which proposes a change plan and edits only after approval.

## References

- `references/checklist.md` — per-dimension grep commands, common false positives to kill, and the severity-grouped report template.
- `references/readme-txt.md` — readme.txt required/optional sections, field limits, Stable tag rules, common mistakes, and verification greps.
- `references/escaping-sanitization.md` — escaping functions by context, sanitization functions by input type, late-escape rule, common XSS/SQLi flags.
- `references/capability-nonce.md` — nonce creation/verification patterns, capability map, public webhook exception, false-positive patterns.
- `references/i18n-translator-comments.md` — all i18n function signatures, translator comment format and placement rules, variable/placeholder rules, common flags.
