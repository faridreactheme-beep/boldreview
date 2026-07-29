<?php
/**
 * Keeps reviews out of the WordPress Comments screen.
 *
 * Reviews are comments, so without this they would show up twice: once in
 * BoldReview → Reviews and again under Comments, where the editor knows nothing
 * about ratings, criteria or the rejected status. They are filtered out of that
 * screen and its counts, and the WooCommerce product-reviews page points here
 * instead.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Comments-screen bridge.
 */
class Bdrvw_CommentsScreen {

	/**
	 * Register hooks.
	 */
	public function register(): void {

		add_filter( 'comments_clauses', array( $this, 'exclude_reviews' ), 100, 2 );
		add_filter( 'wp_count_comments', array( $this, 'discount_reviews' ), 100, 2 );
		add_filter( 'comments_template_query_args', array( $this, 'exclude_from_theme_list' ) );
		add_filter( 'get_comments_number', array( $this, 'discount_post_comment_number' ), 10, 2 );

		add_action( 'wp_insert_comment', array( $this, 'flush_counts' ) );
		add_action( 'deleted_comment', array( $this, 'flush_counts' ) );
		add_action( 'transition_comment_status', array( $this, 'flush_counts_on_transition' ), 10, 3 );

		add_action( 'load-edit-comments.php', array( $this, 'maybe_redirect_review_screen' ) );
		add_action( 'load-comment.php', array( $this, 'maybe_redirect_review_edit' ) );
		add_action( 'admin_menu', array( $this, 'redirect_woo_reviews_page' ), 99 );
		
	}

	/**
	 * Keep reviews out of the theme's comment list.
	 *
	 * On a product this never mattered — WooCommerce renders its own tab — but
	 * a review left on an ordinary post would otherwise turn up among that
	 * post's comments as well as in BoldReview's list.
	 *
	 * @param array<string,mixed> $args Comment query args for the theme template.
	 * @return array<string,mixed>
	 */
	public function exclude_from_theme_list( $args ) {
		$args = is_array( $args ) ? $args : array();

		$excluded = isset( $args['type__not_in'] ) ? (array) $args['type__not_in'] : array();
		$excluded[] = Bdrvw_Review::COMMENT_TYPE;

		$args['type__not_in'] = array_values( array_unique( $excluded ) );

		return $args;
	}

	/**
	 * BoldReview's own reviews screen.
	 */
	public static function reviews_url(): string {
		return admin_url( 'admin.php?page=bdrvw-reviews' );
	}

	/**
	 * Drop review-type comments from the Comments list query.
	 *
	 * Scoped to that one admin screen so feeds, the frontend comment list and
	 * any other WP_Comment_Query elsewhere are left completely alone.
	 *
	 * @param array<string,string> $clauses Comment query clauses.
	 * @param \WP_Comment_Query    $query   Query instance.
	 * @return array<string,string>
	 */
	public function exclude_reviews( $clauses, $query ) {
		unset( $query );

		if ( ! is_admin() || ! $this->on_comments_screen() ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['where'] .= ' AND NOT ' . $this->managed_where( $wpdb->comments );

		return $clauses;
	}

	/**
	 * SQL matching every comment BoldReview moderates — the review-type ones
	 * plus comments on the post types reviews are enabled for.
	 *
	 * This is deliberately the same set the Reviews list shows, so a comment is
	 * never in both places: whatever BoldReview took over disappears from the
	 * Comments screen, and everything else stays. Replies are part of that set —
	 * the Reviews list carries them under its own filter, so leaving them here
	 * would strand an answer on one screen and the review it answers on another.
	 *
	 * @param string $table Table name or alias to qualify the columns with.
	 */
	protected function managed_where( string $table ): string {
		$sql = $table . ".comment_type = '" . esc_sql( Bdrvw_Review::COMMENT_TYPE ) . "'";

		$types = Bdrvw_Review::enabled_post_types();
		if ( ! empty( $types ) ) {
			global $wpdb;
			$list = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
			$sql .= " OR ( {$table}.comment_type IN ('', 'comment')"
				. " AND {$table}.comment_post_ID IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$list})) )";
		}

		return '( ' . $sql . ' )';
	}

	/**
	 * Comment counts with reviews left out.
	 *
	 * `wp_count_comments()` short-circuits on this filter — it hands over an
	 * empty array and uses whatever comes back — so the counts are rebuilt here
	 * the way core does, minus the review type. Without it the admin menu keeps
	 * a "pending" bubble for reviews the Comments screen refuses to show, a
	 * count you could never clear from there. (That mismatch is exactly what
	 * other review plugins leave behind.)
	 *
	 * @param array|object $counts  Empty array from core, or another plugin's answer.
	 * @param int          $post_id Post the counts are for, 0 for site-wide.
	 * @return array|object
	 */
	public function discount_reviews( $counts, $post_id ) {
		// Someone earlier already answered — don't fight over it.
		if ( ! empty( $counts ) ) {
			return $counts;
		}

		$post_id   = (int) $post_id;
		$cache_key = "bdrvw-comments-{$post_id}";

		$cached = wp_cache_get( $cache_key, 'counts' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$where = 'NOT ' . $this->managed_where( $wpdb->comments );
		if ( $post_id > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->comments}.comment_post_ID = %d", $post_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			"SELECT comment_approved, COUNT(*) AS num FROM {$wpdb->comments} WHERE {$where} GROUP BY comment_approved",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$stats = array(
			'approved'     => 0,
			'moderated'    => 0,
			'spam'         => 0,
			'trash'        => 0,
			'post-trashed' => 0,
		);

		foreach ( (array) $rows as $row ) {
			$num = (int) $row['num'];
			switch ( (string) $row['comment_approved'] ) {
				case '1':
					$stats['approved'] += $num;
					break;
				case '0':
					$stats['moderated'] += $num;
					break;
				case 'spam':
					$stats['spam'] += $num;
					break;
				case 'trash':
					$stats['trash'] += $num;
					break;
				case 'post-trashed':
					$stats['post-trashed'] += $num;
					break;
			}
		}

		// The two derived totals, composed exactly as core composes them.
		$stats['all']            = $stats['approved'] + $stats['moderated'];
		$stats['total_comments'] = $stats['all'] + $stats['spam'];

		$stats = (object) $stats;
		wp_cache_set( $cache_key, $stats, 'counts' );

		return $stats;
	}

	/**
	 * Take reviews out of a post's "N comments" number.
	 *
	 * `wp_posts.comment_count` counts every approved comment whatever its type,
	 * so a post with reviews would advertise comments the theme's list does not
	 * show. Products are left alone — their count is the review count, and
	 * WooCommerce reads it through its own getters.
	 *
	 * @param string|int $count   Comment count for the post.
	 * @param int        $post_id Post id.
	 * @return int
	 */
	public function discount_post_comment_number( $count, $post_id ) {
		$post_id = (int) $post_id;
		$count   = (int) $count;

		if ( $count <= 0 || $post_id <= 0 || 'product' === get_post_type( $post_id ) ) {
			return $count;
		}

		$reviews = (int) get_comments(
			array(
				'count'                     => true,
				'update_comment_meta_cache' => false,
				'orderby'                   => 'none',
				'post_id'                   => $post_id,
				'status'                    => 'approve',
				'type'                      => Bdrvw_Review::COMMENT_TYPE,
			)
		);

		return max( 0, $count - $reviews );
	}

	/**
	 * Drop the cached counts after a comment is added or removed.
	 *
	 * @param int $comment_id Comment id.
	 */
	public function flush_counts( $comment_id ): void {
		$comment = get_comment( $comment_id );
		wp_cache_delete( 'bdrvw-comments-0', 'counts' );
		if ( $comment ) {
			wp_cache_delete( 'bdrvw-comments-' . (int) $comment->comment_post_ID, 'counts' );
		}
	}

	/**
	 * Drop the cached counts when a comment changes status.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Previous status.
	 * @param \WP_Comment $comment    Comment object.
	 */
	public function flush_counts_on_transition( $new_status, $old_status, $comment ): void {
		unset( $new_status, $old_status );
		wp_cache_delete( 'bdrvw-comments-0', 'counts' );
		if ( $comment ) {
			wp_cache_delete( 'bdrvw-comments-' . (int) $comment->comment_post_ID, 'counts' );
		}
	}

	/**
	 * Screens that list comments to the admin: the Comments page itself
	 * (including WooCommerce's `comment_type=review` variant) and the
	 * dashboard's Recent Comments widget. Reviews are filtered out of both;
	 * comment queries anywhere else are left untouched.
	 */
	protected function on_comments_screen(): bool {
		global $pagenow;
		return in_array( $pagenow, array( 'edit-comments.php', 'index.php' ), true );
	}

	/**
	 * `edit-comments.php?comment_type=review` (and the product-scoped variant)
	 * exist only to list reviews — send those to BoldReview instead of showing
	 * a screen we have just emptied.
	 */
	public function maybe_redirect_review_screen(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$comment_type = isset( $_GET['comment_type'] ) ? sanitize_key( wp_unslash( $_GET['comment_type'] ) ) : '';
		$post_type    = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		// phpcs:enable

		if ( Bdrvw_Review::COMMENT_TYPE === $comment_type || 'product' === $post_type ) {
			wp_safe_redirect( self::reviews_url() );
			exit;
		}
	}

	/**
	 * Opening a review in the core comment editor would silently drop its
	 * rating and criteria on save — send those edits to BoldReview.
	 */
	public function maybe_redirect_review_edit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$comment_id = isset( $_GET['c'] ) ? absint( wp_unslash( $_GET['c'] ) ) : 0;
		if ( $comment_id <= 0 ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || Bdrvw_Review::COMMENT_TYPE !== (string) $comment->comment_type ) {
			return;
		}

		wp_safe_redirect( self::reviews_url() );
		exit;
	}

	/**
	 * Point WooCommerce's own "Reviews" submenu at BoldReview.
	 */
	public function redirect_woo_reviews_page(): void {
		global $submenu;
		if ( empty( $submenu['edit.php?post_type=product'] ) ) {
			return;
		}

		foreach ( $submenu['edit.php?post_type=product'] as $index => $item ) {
			if ( isset( $item[2] ) && 'product-reviews' === $item[2] ) {
				$submenu['edit.php?post_type=product'][ $index ][2] = self::reviews_url();
			}
		}
	}
}
