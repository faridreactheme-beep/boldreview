---
name: wp-background-processing
description: Use when implementing background jobs, queued tasks, or long-running processes in a WordPress plugin — Action Scheduler (WooCommerce queue), WP_Background_Process (Delicious Brains pattern), WP Cron, batch processing with progress tracking, or retry/error handling for async jobs.
---

# WordPress Background Processing

Implement background jobs and queued tasks in WordPress plugins. Three primary tools with distinct trade-offs: Action Scheduler (persistent, battle-tested), `WP_Background_Process` (lightweight, no DB table), WP Cron (built-in, unreliable timing).

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

- "Process a large batch of items in the background", "queue a long-running task".
- "Set up Action Scheduler", "use WooCommerce queue".
- "Implement WP_Background_Process", "chunked batch import".
- "Background email sending", "async API calls".
- "Fix a WP Cron job not firing", "make scheduled tasks reliable".

**Not for:** One-off scheduled events (use `wp_schedule_single_event` directly). REST API async patterns — no dedicated skill is installed; use `wordpress-dev` (see its `reference/apis.md`), or the owning project skill if one covers that codebase.

## Method

### 1. Choose the right tool

| Tool | Persistent | Retry | Progress | Requires | Best for |
|---|---|---|---|---|---|
| Action Scheduler | ✅ DB table | ✅ Configurable | Via hooks | AS or WC | Reliable multi-step queues |
| WP_Background_Process | ✅ Options API | ❌ Manual | Via option | ~5KB class | Simple batches, no WC dep |
| WP Cron | ❌ (transient) | ❌ | ❌ | Nothing | Maintenance, low-priority tasks |

**Rule of thumb:** WooCommerce already installed → Action Scheduler. Simple background batch → `WP_Background_Process`. Periodic cleanup task → WP Cron.

### 2. Action Scheduler

Bundled with WooCommerce. Can also be installed as a standalone library.

**Standalone install:**
```bash
composer require woocommerce/action-scheduler
```
```php
require_once plugin_dir_path( __FILE__ ) . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
```

**Schedule and handle actions:**
```php
// Schedule a single action (fires once ASAP)
as_enqueue_async_action( 'my_plugin_process_item', [ 'item_id' => 123 ], 'my-plugin' );

// Schedule a recurring action
as_schedule_recurring_action( time(), HOUR_IN_SECONDS, 'my_plugin_hourly_sync', [], 'my-plugin' );

// Schedule a single action in the future
as_schedule_single_action( time() + 300, 'my_plugin_delayed_job', [ 'batch' => 1 ], 'my-plugin' );

// Handle the action
add_action( 'my_plugin_process_item', function( $item_id ) {
    $result = my_plugin_do_work( $item_id );
    if ( is_wp_error( $result ) ) {
        // AS will retry on exception; throw to trigger retry
        throw new \Exception( $result->get_error_message() );
    }
} );
```

**Batch queue (fan-out pattern):**
```php
function my_plugin_queue_all_items() {
    $items = get_posts( [ 'post_type' => 'my_type', 'posts_per_page' => -1, 'fields' => 'ids' ] );
    foreach ( $items as $id ) {
        // Skip if already scheduled
        if ( ! as_has_scheduled_action( 'my_plugin_process_item', [ 'item_id' => $id ], 'my-plugin' ) ) {
            as_enqueue_async_action( 'my_plugin_process_item', [ 'item_id' => $id ], 'my-plugin' );
        }
    }
}
```

**Cancel scheduled actions:**
```php
as_unschedule_action( 'my_plugin_hourly_sync', [], 'my-plugin' );
as_unschedule_all_actions( 'my_plugin_process_item', [], 'my-plugin' );
```

**Monitor via WP-CLI:**
```bash
wp action-scheduler list --group=my-plugin --status=pending
wp action-scheduler run --group=my-plugin
```

### 3. WP_Background_Process

Lightweight 2-class library using WP options + transients for queue state. No extra DB table.

```bash
composer require deliciousbrains/wp-background-processing
```

**Extend the class:**
```php
class My_Plugin_Batch_Process extends WP_Background_Process {

    protected $action = 'my_plugin_batch'; // Unique key — used for cron and option names

    protected function task( $item ) {
        // Process one item. Return false to remove from queue; return $item to re-queue.
        $result = my_plugin_process( $item['id'] );
        if ( is_wp_error( $result ) ) {
            // Log and skip — returning $item would re-queue endlessly
            error_log( 'my-plugin: failed item ' . $item['id'] . ': ' . $result->get_error_message() );
            return false;
        }
        return false; // Done — remove from queue
    }

    protected function complete() {
        parent::complete();
        update_option( 'my_plugin_batch_complete', time() );
        do_action( 'my_plugin_batch_complete' );
    }
}
```

**Push items and dispatch:**
```php
$process = new My_Plugin_Batch_Process();

$items = get_ids_to_process();
foreach ( $items as $id ) {
    $process->push_to_queue( [ 'id' => $id ] );
}
$process->save()->dispatch();
```

**Check status:**
```php
if ( $process->is_queue_empty() ) { /* done */ }
```

### 4. WP Cron

Built-in. Fires on page load — unreliable on low-traffic sites. Use for non-critical periodic tasks.

```php
// Register custom interval
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['every_15_minutes'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __( 'Every 15 Minutes', 'my-plugin' ),
    ];
    return $schedules;
} );

// Schedule on activation; clear on deactivation
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'my_plugin_cron_job' ) ) {
        wp_schedule_event( time(), 'every_15_minutes', 'my_plugin_cron_job' );
    }
} );
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'my_plugin_cron_job' );
} );

// Handle
add_action( 'my_plugin_cron_job', function() {
    my_plugin_do_maintenance();
} );
```

**Make WP Cron reliable on low-traffic sites:** Set up a real system cron to call `wp-cron.php` and disable the page-load trigger:

```bash
# System cron (every 5 minutes)
*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

### 5. Progress tracking

Track batch progress for admin UI display:

```php
// Increment progress in task()
protected function task( $item ) {
    $progress = get_option( 'my_plugin_batch_progress', [ 'done' => 0, 'total' => 0 ] );
    // ... do work ...
    $progress['done']++;
    update_option( 'my_plugin_batch_progress', $progress );
    return false;
}

// Poll via REST or AJAX
add_action( 'wp_ajax_my_plugin_batch_progress', function() {
    wp_send_json_success( get_option( 'my_plugin_batch_progress', [ 'done' => 0, 'total' => 0 ] ) );
} );
```

### 6. Error handling patterns

Action Scheduler retries on uncaught exceptions. Control retry behaviour:
```php
// Fail permanently (no retry)
ActionScheduler_Logger::instance()->log( $action_id, 'Permanent failure: ' . $reason );
throw new ActionScheduler_InvalidActionException( $reason );

// Retry with delay (reschedule from handler)
as_schedule_single_action( time() + 300, 'my_plugin_process_item', $args, 'my-plugin' );
return; // Don't throw — AS won't auto-retry this run
```

## Notes

- Action Scheduler stores pending/failed actions in `{prefix}actionscheduler_actions` table — visible in WC → Status → Scheduled Actions. Check there first when debugging stuck jobs.
- WP Cron events do **not** persist across deactivation — always clear on `register_deactivation_hook`.
- Background processes that modify many posts/options should run in small chunks (50–100 items) to avoid timeout and memory limits. Use `$process->memory_exceeded()` and `$process->time_exceeded()` checks from `WP_Background_Process` to self-limit.
- On multisite: Action Scheduler is per-site. For network-wide jobs, run from the main site or loop via `switch_to_blog()`.
