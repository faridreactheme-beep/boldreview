<?php
/**
 * Moves reviews written into the plugin's old custom table over to comments.
 *
 * Earlier versions of BoldReview stored reviews in `{prefix}bdrvw_reviews`.
 * They now live as comments alongside WooCommerce's own product reviews, so any
 * rows left in that table are copied across once and the table is left in place
 * (renamed out of the way is the site owner's call, not ours).
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * One-time, resumable copy of legacy rows into comments.
 */
class Bdrvw_LegacyMigration {

	/** Set once every legacy row has been copied. */
	const DONE_OPTION = 'bdrvw_legacy_migrated';

	/** Id of the last row copied, so a timed-out run can pick up where it left off. */
	const CURSOR_OPTION = 'bdrvw_legacy_migrated_upto';

	/** Legacy row id a comment came from — makes re-runs idempotent. */
	const META_LEGACY_ID = 'bdrvw_legacy_id';

	/** Rows per request. Small enough to never trouble a slow host. */
	const BATCH = 100;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_run' ) );
	}

	/**
	 * Copy one batch per admin request until the table is drained.
	 */
	public function maybe_run(): void {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}
		if ( ! $this->legacy_table_exists() ) {
			update_option( self::DONE_OPTION, 1, false );
			return;
		}

		$this->run_batch();
	}

	/**
	 * Whether the old table is still present.
	 */
	protected function legacy_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bdrvw_reviews';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (string) $found === $table;
	}

	/**
	 * Copy the next batch of legacy rows.
	 */
	protected function run_batch(): void {
		global $wpdb;

		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bdrvw_reviews WHERE id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				self::BATCH
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			update_option( self::DONE_OPTION, 1, false );
			delete_option( self::CURSOR_OPTION );
			return;
		}

		foreach ( $rows as $row ) {
			$this->migrate_row( $row );
			$cursor = (int) $row['id'];
		}

		update_option( self::CURSOR_OPTION, $cursor, false );
	}

	/**
	 * Copy a single legacy row, unless it has been copied already.
	 *
	 * @param array<string,mixed> $row Legacy table row.
	 */
	protected function migrate_row( array $row ): void {
		$legacy_id = (int) ( $row['id'] ?? 0 );
		if ( $legacy_id <= 0 || $this->already_migrated( $legacy_id ) ) {
			return;
		}

		$criteria = array();
		$raw      = (string) ( $row['criteria_ratings'] ?? '' );
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$criteria = $decoded;
			}
		}

		$new_id = Bdrvw_Review::bdrvw_insert(
			array(
				'post_id'      => (int) ( $row['post_id'] ?? 0 ),
				'user_id'      => (int) ( $row['user_id'] ?? 0 ),
				'author_name'  => (string) ( $row['author_name'] ?? '' ),
				'author_email' => (string) ( $row['author_email'] ?? '' ),
				'author_url'   => (string) ( $row['author_url'] ?? '' ),
				'title'        => (string) ( $row['title'] ?? '' ),
				'content'      => (string) ( $row['content'] ?? '' ),
				'rating'       => (int) ( $row['rating'] ?? 0 ),
				'criteria'     => $criteria,
				'status'       => Bdrvw_Review::valid_status( (string) ( $row['status'] ?? 'pending' ) ),
				'ip_address'   => (string) ( $row['ip_address'] ?? '' ),
				'user_agent'   => (string) ( $row['user_agent'] ?? '' ),
			)
		);

		if ( $new_id <= 0 ) {
			return;
		}

		add_comment_meta( $new_id, self::META_LEGACY_ID, $legacy_id, true );

		// bdrvw_insert() stamps "now"; keep the original submission date instead.
		$created = (string) ( $row['created_at'] ?? '' );
		if ( '' !== $created ) {
			wp_update_comment(
				array(
					'comment_ID'       => $new_id,
					'comment_date'     => $created,
					'comment_date_gmt' => get_gmt_from_date( $created ),
				)
			);
		}
	}

	/**
	 * Has this legacy row already been copied across?
	 */
	protected function already_migrated( int $legacy_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 1",
				self::META_LEGACY_ID,
				$legacy_id
			)
		);
		return ! empty( $found );
	}
}
