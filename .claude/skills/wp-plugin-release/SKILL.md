---
name: wp-plugin-release
description: Use when bumping or releasing a WordPress plugin version, syncing version numbers, or updating readme.txt / changelog. Keeps every version source coherent so Stable tag, header, and constant never drift.
---

# WordPress Plugin Release / Version Sync

Bump a WP plugin version coherently. Prevents the classic drift where the plugin header says one version, the `readme.txt` `Stable tag` another, and the `.pot` a third.

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

- "Release X.Y.Z", "bump the version", "update the changelog / readme.txt".
- After substantial work has landed under an unreleased version.

**Scope check first.** This skill only syncs version markers. For a full release — merging feature
branches, reconciling changelog/readme across features, building the artifact, then pre-release QA
— `release-merge` owns that flow and this is one step inside its Phase 2. When the two disagree,
**`release-merge`'s project-specific notes win**; they were learned from real releases.

## Environment — read before running any `wp` command

On a site under `~/Local Sites/` (Local by Flywheel), plain `wp` fails with **"Error establishing
a database connection"** even though MySQL is running fine — Local uses a per-site socket while
`wp-config.php` points at `localhost`. **Do not report the database as down.**

Run instead: `wp --require=<file-that-defines-DB_HOST> <command>`, where the file sets
`DB_HOST` to `localhost:~/Library/Application Support/Local/run/<SITE_ID>/mysql/mysqld.sock`.
Find `<SITE_ID>` with `ls ~/Library/Application\ Support/Local/run/`.

This gates `wp i18n make-pot` (regenerating the `.pot` before a build) and `wp plugin check`
(wp.org compliance). Full recipe: `wordpress-dev/reference/tooling.md` → "WP-CLI".

## First: determine the real current state

```bash
git tag                       # any release tags?
gh release list               # any published releases?
grep -n "Version:" *.php       # plugin header
grep -n "_VERSION'" *.php       # version constant
grep -n "Stable tag" readme.txt
```

If header/constant/Stable-tag disagree, that drift IS the problem — pick the target version and sync all of them. Choose the bump by semver: new backward-compatible features → minor; fixes only → patch; breaking → major. Internal-only refactors (dir rename) don't force a major.

## Sources to update (all, in lockstep)

1. **Plugin header** `* Version: X.Y.Z` (main plugin file).
2. **Version constant** `define( 'PLUGIN_VERSION', 'X.Y.Z' )`.
3. **`readme.txt` `Stable tag: X.Y.Z`** — and `Tested up to` / `Requires PHP` if they changed.
4. **`readme.txt` Changelog** — add a `= X.Y.Z =` block listing what shipped (security, features, fixes), grouped.
5. **`readme.txt` Upgrade Notice** — add `= X.Y.Z =` one-liner (why upgrade).
6. **`.pot`** — regenerate so `Project-Id-Version` matches and new strings are captured:
   ```bash
   composer makepot           # or: wp i18n make-pot . languages/<slug>.pot --exclude=...
   ```

## Do NOT bump

- **Schema / DB version** (e.g. `LicenseModel::$db_version`) — independent of plugin version. Only bump when the table actually changed, since it gates data migrations.
- Historical changelog entries or point-in-time docs.

## Verify

```bash
composer lint && composer analyze && composer test
grep -rn "X\.Y\.Z\|<old version>" --include=*.php --include=readme.txt .   # confirm sync, spot stragglers
```

Then commit (`docs:`/`chore:` for a pure version+readme bump), and ship via the repo's contribution flow (branch → PR → merge; never squash if the repo says so).

## References

- `references/readme-txt-skeleton.txt` — full WP.org `readme.txt` skeleton (all sections) with the release-sync checklist of every version source baked in as a trailing comment.
