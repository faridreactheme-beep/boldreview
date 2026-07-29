<?php
/**
 * Review model (data access).
 *
 * Reviews are stored as native WordPress comments of type `review` — the same
 * place WooCommerce keeps product reviews — with the extra fields (title,
 * rating, per-criteria ratings) in comment meta. Sharing WooCommerce's storage
 * means reviews written before BoldReview was installed are simply part of the
 * set: there is nothing to import and nothing that can be left behind.
 *
 * Reads go through direct SQL (one query with the meta joined) so listing,
 * searching and sorting by rating stay a single round trip; writes go through
 * the WordPress comment API so caches, comment counts and third-party hooks all
 * fire as they should.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Models;

use BoldReview\Plugin\Core\Bdrvw_Photos;

defined( 'ABSPATH' ) || exit;

/**
 * Read / write reviews.
 *
 * Statuses: pending, approved, rejected, spam, trash.
 */
class Bdrvw_Review {

	const STATUSES = array( 'pending', 'approved', 'rejected', 'spam', 'trash' );

	/**
	 * Comment type every review is stored under. Matches WooCommerce's own type
	 * so existing product reviews are picked up as-is.
	 */
	const COMMENT_TYPE = 'review';

	/**
	 * Overall rating. Deliberately WooCommerce's meta key, so a product review
	 * written before BoldReview existed already carries its rating.
	 */
	const META_RATING = 'rating';

	/** Review headline. */
	const META_TITLE = 'bdrvw_title';

	/** Per-criterion ratings, JSON encoded. */
	const META_CRITERIA = 'bdrvw_criteria';

	/**
	 * Marks an unapproved review as explicitly rejected. WordPress has no
	 * comment status for "rejected", so it rides along with `comment_approved`
	 * being '0' (the same bucket as pending).
	 */
	const META_REJECTED = 'bdrvw_rejected';

	/** Last edit timestamp — comments have no native updated column. */
	const META_UPDATED = 'bdrvw_updated_at';

	/**
	 * Table reviews live in. Kept for add-ons that used to build their own
	 * queries against the model's table.
	 */
	public static function bdrvw_table(): string {
		global $wpdb;
		return $wpdb->comments;
	}

	/**
	 * Insert a new review.
	 *
	 * @param array<string,mixed> $data Sanitized payload.
	 * @return int Inserted ID or 0 on failure.
	 */
	public static function bdrvw_insert( array $data ): int {
		$now    = current_time( 'mysql' );
		$status = self::valid_status( (string) ( $data['status'] ?? 'pending' ) );

		$date   = self::normalize_date_bound( (string) ( $data['created_at'] ?? '' ), false );
		$date   = '' !== $date ? $date : $now;
		$parent = isset( $data['parent_id'] ) ? max( 0, (int) $data['parent_id'] ) : 0;

		$commentdata = array(
			'comment_post_ID'      => isset( $data['post_id'] ) ? (int) $data['post_id'] : 0,
			'user_id'              => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'comment_author'       => isset( $data['author_name'] ) ? (string) $data['author_name'] : '',
			'comment_author_email' => isset( $data['author_email'] ) ? (string) $data['author_email'] : '',
			'comment_author_url'   => isset( $data['author_url'] ) ? (string) $data['author_url'] : '',
			'comment_content'      => isset( $data['content'] ) ? (string) $data['content'] : '',
			'comment_author_IP'    => isset( $data['ip_address'] ) ? (string) $data['ip_address'] : '',
			'comment_agent'        => isset( $data['user_agent'] ) ? (string) $data['user_agent'] : '',
			'comment_type'         => self::COMMENT_TYPE,
			'comment_parent'       => $parent,
			'comment_approved'     => self::approved_flag( $status ),
			'comment_date'         => $date,
			'comment_date_gmt'     => get_gmt_from_date( $date ),
		);

		$id = wp_insert_comment( $commentdata );
		if ( ! $id ) {
			return 0;
		}

		$id = (int) $id;

		$rating = isset( $data['rating'] ) ? max( 0, min( 5, (int) $data['rating'] ) ) : 0;
		update_comment_meta( $id, self::META_RATING, $rating );
		update_comment_meta( $id, self::META_TITLE, isset( $data['title'] ) ? (string) $data['title'] : '' );
		update_comment_meta( $id, self::META_UPDATED, $now );

		$criteria = isset( $data['criteria'] ) && is_array( $data['criteria'] ) ? $data['criteria'] : array();

		if ( ! empty( $criteria ) ) {
			update_comment_meta( $id, self::META_CRITERIA, wp_json_encode( $criteria ) );
		}

		if ( 'rejected' === $status ) {
			update_comment_meta( $id, self::META_REJECTED, '1' );
		}

		return $id;
	}

	/**
	 * Update an existing review.
	 *
	 * @param int                 $id   Review id.
	 * @param array<string,mixed> $data Fields to set.
	 */
	public static function bdrvw_update( int $id, array $data ): bool {
		if ( $id <= 0 || empty( $data ) ) {
			return false;
		}
		if ( null === self::find( $id ) ) {
			return false;
		}

		$data = array_intersect_key( $data, self::updatable_fields() );
		if ( empty( $data ) ) {
			return false;
		}

		$core = array();
		if ( array_key_exists( 'content', $data ) ) {
			$core['comment_content'] = (string) $data['content'];
		}
		if ( array_key_exists( 'author_name', $data ) ) {
			$core['comment_author'] = (string) $data['author_name'];
		}
		if ( array_key_exists( 'author_email', $data ) ) {
			$core['comment_author_email'] = (string) $data['author_email'];
		}
		if ( array_key_exists( 'author_url', $data ) ) {
			$core['comment_author_url'] = (string) $data['author_url'];
		}
		if ( ! empty( $core ) ) {
			$core['comment_ID'] = $id;
			
			wp_update_comment( wp_slash( $core ) );
		}

		if ( array_key_exists( 'title', $data ) ) {
			update_comment_meta( $id, self::META_TITLE, (string) $data['title'] );
		}
		if ( array_key_exists( 'rating', $data ) ) {
			update_comment_meta( $id, self::META_RATING, max( 0, min( 5, (int) $data['rating'] ) ) );
		}
		if ( array_key_exists( 'criteria_ratings', $data ) ) {
			$criteria = $data['criteria_ratings'];
			if ( is_array( $criteria ) ) {
				$criteria = wp_json_encode( $criteria );
			}
			$criteria = (string) $criteria;
			if ( '' === $criteria ) {
				delete_comment_meta( $id, self::META_CRITERIA );
			} else {
				update_comment_meta( $id, self::META_CRITERIA, $criteria );
			}
		}

		if ( array_key_exists( 'photos', $data ) ) {
			Bdrvw_Photos::sync_for_review( $id, (array) $data['photos'] );
		}

		if ( array_key_exists( 'status', $data ) ) {
			self::set_status( $id, self::valid_status( (string) $data['status'] ) );
		}

		update_comment_meta( $id, self::META_UPDATED, current_time( 'mysql' ) );

		return true;
	}

	/**
	 * The score to show for one row.
	 *
	 * Falls back to the average of the per-criterion scores when there is no
	 * overall rating. With the overall star input switched off, a review carries
	 * only criteria — the frontend has always averaged them for display, and
	 * without this the admin list showed those reviews as unrated, which reads
	 * as data loss rather than a setting.
	 *
	 * @param array<string,mixed> $row Row as returned by the model.
	 */
	public static function effective_rating( array $row ): float {
		$rating = (float) ( $row['rating'] ?? 0 );
		if ( $rating > 0 ) {
			return $rating;
		}

		$criteria = isset( $row['criteria'] ) && is_array( $row['criteria'] ) ? $row['criteria'] : array();
		$sum      = 0;
		$count    = 0;
		foreach ( $criteria as $value ) {
			$value = (int) $value;
			if ( $value > 0 ) {
				$sum += $value;
				$count++;
			}
		}

		return $count > 0 ? round( $sum / $count, 1 ) : 0.0;
	}

	/**
	 * Fields a write may target, keyed by field name.
	 *
	 * The edit form asks the same question before deciding which inputs to
	 * render, so a field can never appear in the UI that a save would silently
	 * drop. Add-ons widen the set; unknown keys are ignored either way.
	 *
	 * @return array<string,string>
	 */
	public static function updatable_fields(): array {
		/**
		 * Filters the fields an update may write.
		 *
		 * @param array<string,string> $allowed Field => ignored (kept for
		 *                                      backwards compatibility with the
		 *                                      previous column => format map).
		 */
		return (array) apply_filters(
			'bdrvw_review_updatable_fields',
			array(
				'status'           => '%s',
				'title'            => '%s',
				'content'          => '%s',
				'rating'           => '%d',
				'criteria_ratings' => '%s',
				'author_name'      => '%s',
				'author_email'     => '%s',
				'photos'           => '%s',
			)
		);
	}

	/**
	 * Move a review to a status, using the core transitions so trash/spam get
	 * their bookkeeping meta and the comment counts stay right.
	 */
	protected static function set_status( int $id, string $status ): void {
		
		$before   = get_comment( $id );
		$previous = $before
			? self::status_from_row( (string) $before->comment_approved, get_comment_meta( $id, self::META_REJECTED, true ) )
			: '';

		if ( 'rejected' === $status ) {
			update_comment_meta( $id, self::META_REJECTED, '1' );
		} else {
			delete_comment_meta( $id, self::META_REJECTED );
		}

		$current = $before;
		if ( $current ) {
			$was = (string) $current->comment_approved;
			if ( 'trash' === $was && 'trash' !== $status ) {
				wp_untrash_comment( $id );
			} elseif ( 'spam' === $was && 'spam' !== $status ) {
				wp_unspam_comment( $id );
			}
		}

		$map = array(
			'approved' => 'approve',
			'pending'  => 'hold',
			'rejected' => 'hold',
			'spam'     => 'spam',
			'trash'    => 'trash',
		);
		wp_set_comment_status( $id, $map[ $status ] ?? 'hold' );

		if ( $previous === $status ) {
			return;
		}

		/**
		 * Fires when a review's moderation status actually changes.
		 *
		 * Raised here rather than at each call site so every route reaches it —
		 * the admin list's row and bulk actions moderate through this model, and
		 * an integration listening for "approved" has to hear those too.
		 *
		 * @param int    $id       Review id.
		 * @param string $status   Status it moved to.
		 * @param string $previous Status it came from.
		 */
		do_action( 'bdrvw_review_status_changed', $id, $status, $previous );
	}

	/**
	 * `comment_approved` value for a BoldReview status.
	 */
	protected static function approved_flag( string $status ): string {
		switch ( $status ) {
			case 'approved':
				return '1';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return '0';
		}
	}

	/**
	 * Delete a review permanently.
	 */
	public static function bdrvw_delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		
		Bdrvw_Photos::delete_for_review( $id );

		return (bool) wp_delete_comment( $id, true );
	}

	/**
	 * Get a single review by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare(
				self::base_select( true ) . ' WHERE ' . self::base_where( true, true ) . ' AND c.comment_ID = %d',
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Query reviews with filters.
	 *
	 * @param array<string,mixed> $args Filters: status, post_id, user_id, rating_min, per_page, page, orderby, order, search.
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'status'           => '',
			'type'             => '',
			'post_id'          => 0,
			'user_id'          => 0,
			'rating_min'       => 0,
			'per_page'         => 20,
			'page'             => 1,
			'orderby'          => 'created_at',
			'order'            => 'DESC',
			'search'           => '',
			'date_from'        => '',
			'date_to'          => '',
			'include_comments' => false,
			'include_replies'  => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$with_comments = ! empty( $args['include_comments'] );
		$with_replies  = ! empty( $args['include_replies'] );

		$where  = array( self::base_where( $with_comments, $with_replies ) );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[] = self::status_where( self::valid_status( (string) $args['status'] ) );
		} else {
			
			$where[] = "c.comment_approved NOT IN ('trash', 'post-trashed')";
		}
		$type = self::valid_type( (string) $args['type'] );
		if ( '' !== $type ) {
			$where[] = self::type_where( $type );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'c.comment_post_ID = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'c.user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['rating_min'] ) ) {
			$where[]  = 'CAST(mr.meta_value AS DECIMAL(4,2)) >= %d';
			$params[] = max( 1, min( 5, (int) $args['rating_min'] ) );
		}
		
		$from = self::normalize_date_bound( (string) ( $args['date_from'] ?? '' ), false );
		if ( '' !== $from ) {
			$where[]  = 'c.comment_date >= %s';
			$params[] = $from;
		}
		$to = self::normalize_date_bound( (string) ( $args['date_to'] ?? '' ), true );
		if ( '' !== $to ) {
			$where[]  = 'c.comment_date <= %s';
			$params[] = $to;
		}
		if ( '' !== $args['search'] ) {
			// Reviewer, review text — and the title of the item reviewed, so an
			// admin can search by product name.
			$where[]  = '(c.comment_author LIKE %s OR c.comment_author_email LIKE %s OR mt.meta_value LIKE %s'
				. " OR c.comment_content LIKE %s OR c.comment_post_ID IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s))";
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby_map = array(
			'id'         => 'c.comment_ID',
			'created_at' => 'c.comment_date',
			'rating'     => 'CAST(mr.meta_value AS DECIMAL(4,2))',
			'status'     => 'c.comment_approved',
		);
		$orderby = $orderby_map[ (string) $args['orderby'] ] ?? 'c.comment_date';
		$order   = strtoupper( (string) $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = 'SELECT COUNT(*) ' . self::base_from( $with_comments ) . ' WHERE ' . $where_sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) ( empty( $params ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) );

		$data_sql    = self::base_select( $with_comments ) . " WHERE {$where_sql} ORDER BY {$orderby} {$order}, c.comment_ID DESC LIMIT %d OFFSET %d";
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::decode_row( $row );
		}

		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * How many reviews a post already holds from one email address or one IP.
	 *
	 * Deliberately independent of the account check above: the "Limit reviews by"
	 * setting exists precisely for visitors who are not logged in, where the
	 * address or the connection is all there is to go on.
	 *
	 * Spam is excluded — a submission caught by the filter should not be what
	 * stops someone from writing a genuine review.
	 *
	 * @param int    $post_id Post being reviewed.
	 * @param string $field   `email` or `ip_address`.
	 * @param string $value   The address or IP to match.
	 */
	public static function count_reviews_on_post_by( int $post_id, string $field, string $value ): int {
		global $wpdb;

		$value = trim( $value );
		if ( $post_id <= 0 || '' === $value ) {
			return 0;
		}

		$comparisons = array(
			'email'      => 'LOWER(c.comment_author_email) = %s',
			'ip_address' => 'LOWER(c.comment_author_IP) = %s',
		);
		if ( ! isset( $comparisons[ $field ] ) ) {
			return 0;
		}

		$sql = 'SELECT COUNT(*) FROM ' . $wpdb->comments . ' c WHERE ' . self::base_where()
			. ' AND ' . $comparisons[ $field ]
			. " AND c.comment_post_ID = %d AND c.comment_approved <> 'spam'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, strtolower( $value ), $post_id ) );
	}

	/**
	 * Whether an imported row is already here.
	 *
	 * Matched on post, author, timestamp and text together: any one of those
	 * repeats legitimately (the same person reviewing twice, two people posting
	 * in the same second), all four together means the file is being imported a
	 * second time.
	 *
	 * @param int    $post_id      Post the row belongs to.
	 * @param string $author_email Author email, may be empty for a guest.
	 * @param string $created_at   Submission timestamp from the file.
	 * @param string $content      Review text.
	 */
	public static function duplicate_exists( int $post_id, string $author_email, string $created_at, string $content ): bool {
		global $wpdb;

		$date = self::normalize_date_bound( $created_at, false );
		if ( $post_id <= 0 || '' === $date ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				WHERE comment_post_ID = %d
					AND comment_author_email = %s
					AND comment_date = %s
					AND comment_content = %s
				LIMIT 1",
				$post_id,
				$author_email,
				$date,
				$content
			)
		);

		return null !== $found;
	}

	/**
	 * Turn a date filter into a `Y-m-d H:i:s` bound, or '' when there is none.
	 *
	 * A bare `Y-m-d` covers the whole day — the end bound stretches to 23:59:59
	 * rather than midnight, which would drop everything written that day.
	 *
	 * @param string $value Raw value, `Y-m-d` or a full datetime.
	 * @param bool   $is_end Whether this is the upper bound.
	 */
	protected static function normalize_date_bound( string $value, bool $is_end ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ( $is_end ? ' 23:59:59' : ' 00:00:00' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Answers written under the given reviews, grouped by the review they answer.
	 *
	 * `query()` deliberately leaves replies out — they must never be averaged
	 * into a rating — so the frontend fetches them separately, in one query for
	 * the whole page of reviews, and threads them underneath.
	 *
	 * Oldest first: a thread reads top to bottom, unlike the review list itself.
	 *
	 * @param array<int,int> $parent_ids Ids of the reviews being displayed.
	 * @param string         $status     Status the replies must have.
	 * @return array<int,array<int,array<string,mixed>>> Rows keyed by parent id.
	 */
	public static function replies_for( array $parent_ids, string $status = 'approved' ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = self::base_select()
			. ' WHERE c.comment_parent IN (' . $in . ')'
			. ' AND ' . self::status_where( self::valid_status( $status ) )
			. ' ORDER BY c.comment_date ASC, c.comment_ID ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$reply                        = self::decode_row( $row );
			$out[ (int) $reply['parent_id'] ][] = $reply;
		}

		return $out;
	}

	/**
	 * Post types BoldReview is switched on for. Ordinary comments only count as
	 * reviews on these; a comment on anything else is left alone.
	 *
	 * @return array<int,string>
	 */
	public static function enabled_post_types(): array {
		$settings = get_option( 'bdrvw_settings', array() );
		$types    = ( is_array( $settings ) && ! empty( $settings['enabled_post_types'] ) )
			? (array) $settings['enabled_post_types']
			: array( 'post' );

		$types = array_filter( array_map( 'sanitize_key', $types ) );

		return array_values( array_unique( $types ) );
	}

	/**
	 * SELECT clause projecting a comment + its review meta into the row shape
	 * the rest of the plugin expects.
	 *
	 * @param bool $include_comments Also treat plain comments as (unrated) reviews.
	 */
	protected static function base_select( bool $include_comments = false ): string {
		return 'SELECT c.comment_ID AS id,
				c.comment_post_ID AS post_id,
				c.user_id AS user_id,
				c.comment_author AS author_name,
				c.comment_author_email AS author_email,
				c.comment_author_url AS author_url,
				c.comment_content AS content,
				c.comment_parent AS parent_id,
				c.comment_author_IP AS ip_address,
				c.comment_agent AS user_agent,
				c.comment_date AS created_at,
				c.comment_approved AS comment_approved,
				mt.meta_value AS title,
				mr.meta_value AS rating,
				mc.meta_value AS criteria_ratings,
				mj.meta_value AS rejected_flag,
				mu.meta_value AS updated_at '
			. self::base_from( $include_comments );
	}

	/**
	 * FROM + the meta joins, shared by the SELECT and the COUNT.
	 *
	 * @param bool $include_comments Join posts too, so plain comments can be
	 *                               limited to the enabled post types.
	 */
	protected static function base_from( bool $include_comments = false ): string {
		global $wpdb;
		$meta = $wpdb->commentmeta;

		$sql = "FROM {$wpdb->comments} c";
		if ( $include_comments ) {
			$sql .= " INNER JOIN {$wpdb->posts} bp ON bp.ID = c.comment_post_ID";
		}

		return $sql . "
			LEFT JOIN {$meta} mt ON mt.comment_id = c.comment_ID AND mt.meta_key = '" . self::META_TITLE . "'
			LEFT JOIN {$meta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'
			LEFT JOIN {$meta} mc ON mc.comment_id = c.comment_ID AND mc.meta_key = '" . self::META_CRITERIA . "'
			LEFT JOIN {$meta} mj ON mj.comment_id = c.comment_ID AND mj.meta_key = '" . self::META_REJECTED . "'
			LEFT JOIN {$meta} mu ON mu.comment_id = c.comment_ID AND mu.meta_key = '" . self::META_UPDATED . "'";
	}

	/**
	 * Rows that count as reviews.
	 *
	 * Always the review-type comments (BoldReview's own plus WooCommerce's
	 * product reviews). With $include_comments on, ordinary comments left on an
	 * enabled post type join them — they carry no rating meta, so they list as
	 * unrated.
	 *
	 * Replies are left out unless asked for: every rating calculation on this
	 * model wants the top level only, and an answer written under a review must
	 * never be averaged into it. The admin list is the one caller that wants
	 * them, so it can offer a Replies filter.
	 *
	 * @param bool $include_comments Whether plain comments count too.
	 * @param bool $include_replies  Whether answers written under them count too.
	 */
	protected static function base_where( bool $include_comments = false, bool $include_replies = false ): string {
		global $wpdb;

		$types = self::enabled_post_types();
		$list  = ! empty( $types ) ? "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'" : '';

		$where = "c.comment_type = '" . self::COMMENT_TYPE . "'";

		if ( $include_comments && '' !== $list ) {
			$where .= " OR ( c.comment_type IN ('', 'comment') AND bp.post_type IN ({$list}) )";
		}

		if ( ! $include_replies ) {
			return '(' . $where . ') AND c.comment_parent = 0';
		}

		$parent = "pc.comment_type = '" . self::COMMENT_TYPE . "'";
		if ( '' !== $list ) {
			$parent .= " OR pc.comment_post_ID IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$list}))";
		}
		$where .= " OR ( c.comment_parent > 0 AND EXISTS ("
			. " SELECT 1 FROM {$wpdb->comments} pc WHERE pc.comment_ID = c.comment_parent AND ( {$parent} ) ) )";

		return '(' . $where . ')';
	}

	/**
	 * WHERE fragment selecting a single BoldReview status. "Rejected" shares
	 * `comment_approved = '0'` with pending and is told apart by its meta flag.
	 */
	protected static function status_where( string $status ): string {
		switch ( $status ) {
			case 'approved':
				return "c.comment_approved = '1'";
			case 'spam':
				return "c.comment_approved = 'spam'";
			case 'trash':
				return "c.comment_approved IN ('trash', 'post-trashed')";
			case 'rejected':
				return "c.comment_approved = '0' AND mj.meta_value = '1'";
			case 'pending':
			default:
				return "c.comment_approved = '0' AND mj.meta_value IS NULL";
		}
	}

	/**
	 * Normalise a kind-of-entry filter.
	 *
	 * `review` is a rated entry left on the item itself, `comment` one left the
	 * same way but carrying no rating, `reply` an answer written underneath
	 * either. An empty string (or anything unrecognised) means all three.
	 */
	public static function valid_type( string $type ): string {
		return in_array( $type, array( 'review', 'comment', 'reply' ), true ) ? $type : '';
	}

	/**
	 * Whether a row carries a rating of any kind.
	 *
	 * Criteria-only submissions count: with the overall star input switched off
	 * a review scores each criterion and nothing else, and it is still a review.
	 *
	 * COALESCE rather than a bare comparison because both meta joins are LEFT —
	 * a missing row yields NULL, and `NOT ( NULL > 0 )` is NULL, which would
	 * quietly drop the very rows the `comment` filter exists to collect.
	 */
	protected static function rated_expr(): string {
		return "( COALESCE( CAST(mr.meta_value AS DECIMAL(4,2)), 0 ) > 0 OR COALESCE( mc.meta_value, '' ) <> '' )";
	}

	/**
	 * WHERE fragment narrowing to one kind of entry.
	 *
	 * Split by what the row actually shows, not by how it is stored: the Rating
	 * column is what an admin sees, so anything landing under "Reviews" has to
	 * have a rating in it. The comment type is no help here — WooCommerce writes
	 * unrated rows as `review` too, and a reply is stored as an ordinary comment
	 * exactly like a plain one, so only the parent tells those apart.
	 *
	 * The three cases are exhaustive and disjoint, so every listed row belongs
	 * to exactly one of them.
	 */
	protected static function type_where( string $type ): string {
		switch ( $type ) {
			case 'review':
				return 'c.comment_parent = 0 AND ' . self::rated_expr();
			case 'comment':
				return 'c.comment_parent = 0 AND NOT ' . self::rated_expr();
			case 'reply':
			default:
				return 'c.comment_parent > 0';
		}
	}

	/**
	 * Average rating + count for a post (approved only).
	 *
	 * @return array{average:float,count:int,breakdown:array<int,int>}
	 */
	public static function aggregate_for_post( int $post_id ): array {
		global $wpdb;

		$breakdown = array(
			1 => 0,
			2 => 0,
			3 => 0,
			4 => 0,
			5 => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ROUND(CAST(mr.meta_value AS DECIMAL(4,2))) AS rating, COUNT(*) AS c '
					. "FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->commentmeta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'
					WHERE " . self::base_where() . " AND c.comment_post_ID = %d AND c.comment_approved = '1'
					AND CAST(mr.meta_value AS DECIMAL(4,2)) > 0
					GROUP BY rating",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$sum   = 0;
		$count = 0;
		foreach ( (array) $rows as $r ) {
			$rating = (int) $r['rating'];
			$c      = (int) $r['c'];
			if ( $rating >= 1 && $rating <= 5 ) {
				$breakdown[ $rating ] = $c;
			}
			$sum   += $rating * $c;
			$count += $c;
		}

		return array(
			'average'   => $count > 0 ? round( $sum / $count, 2 ) : 0.0,
			'count'     => $count,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Count approved reviews for a post regardless of whether they carry an
	 * overall star rating. Used when the overall rating input is disabled and
	 * the "count" must still reflect criteria-only submissions.
	 */
	public static function approved_count_for_post( int $post_id ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c WHERE " . self::base_where()
					. " AND c.comment_post_ID = %d AND c.comment_approved = '1'",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $count;
	}

	/**
	 * Per-criterion average ratings for a post (approved only).
	 * Returns key => [ average, count ].
	 *
	 * @return array<string,array{average:float,count:int}>
	 */
	public static function criteria_averages_for_post( int $post_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT mc.meta_value FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} mc ON mc.comment_id = c.comment_ID AND mc.meta_key = '" . self::META_CRITERIA . "'
				WHERE " . self::base_where() . " AND c.comment_post_ID = %d AND c.comment_approved = '1' AND mc.meta_value <> ''",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$totals = array();
		$counts = array();
		foreach ( (array) $rows as $json ) {
			$decoded = json_decode( (string) $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $key => $val ) {
				$rating = (int) $val;
				if ( $rating < 1 || $rating > 5 ) {
					continue;
				}
				$key            = (string) $key;
				$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + $rating;
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}

		$out = array();
		foreach ( $totals as $key => $sum ) {
			$c           = (int) $counts[ $key ];
			$out[ $key ] = array(
				'average' => $c > 0 ? round( $sum / $c, 2 ) : 0.0,
				'count'   => $c,
			);
		}
		return $out;
	}

	/**
	 * Does any published item of this post type carry a rating?
	 *
	 * Cheap enough to call on every list-table load: it stops at the first hit
	 * and rides the comment/meta indexes. Answered once per request.
	 */
	public static function post_type_has_ratings( string $post_type ): bool {
		static $cache = array();

		if ( '' === $post_type ) {
			return false;
		}
		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'
				INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
				WHERE " . self::base_where() . " AND p.post_type = %s AND c.comment_approved = '1'
				AND CAST(mr.meta_value AS DECIMAL(4,2)) > 0
				LIMIT 1",
				$post_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cache[ $post_type ] = ! empty( $found );

		return $cache[ $post_type ];
	}

	/**
	 * Average rating across every review on posts written by an author.
	 *
	 * @return array{average:float,count:int}
	 */
	public static function author_average( int $author_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(CAST(mr.meta_value AS DECIMAL(4,2))) AS avg_r, COUNT(*) AS c '
					. "FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->commentmeta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'
					INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
					WHERE " . self::base_where() . " AND p.post_author = %d AND c.comment_approved = '1'
					AND CAST(mr.meta_value AS DECIMAL(4,2)) > 0",
				$author_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array(
			'average' => $row && $row['avg_r'] ? round( (float) $row['avg_r'], 2 ) : 0.0,
			'count'   => $row ? (int) $row['c'] : 0,
		);
	}

	/**
	 * Average rating across every review a given user wrote.
	 *
	 * @return array{average:float,count:int}
	 */
	public static function user_average( int $user_id ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(CAST(mr.meta_value AS DECIMAL(4,2))) AS avg_r, COUNT(*) AS c '
					. "FROM {$wpdb->comments} c
					INNER JOIN {$wpdb->commentmeta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'
					WHERE " . self::base_where() . " AND c.user_id = %d AND c.comment_approved = '1'
					AND CAST(mr.meta_value AS DECIMAL(4,2)) > 0",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array(
			'average' => $row && $row['avg_r'] ? round( (float) $row['avg_r'], 2 ) : 0.0,
			'count'   => $row ? (int) $row['c'] : 0,
		);
	}

	/**
	 * Has the given user already reviewed the post?
	 */
	public static function user_has_reviewed( int $user_id, int $post_id ): bool {
		return self::count_user_reviews_on_post( $user_id, $post_id ) > 0;
	}

	/**
	 * Count how many non-spam reviews a given identity already has on a post.
	 * Logged-in users are identified by `user_id`; logged-out visitors by their
	 * submitted `author_email` (case-insensitive). Pass `''` to skip the email
	 * path.
	 */
	public static function count_user_reviews_on_post( int $user_id, int $post_id, string $author_email = '' ): int {
		global $wpdb;
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( $user_id > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->comments} c WHERE " . self::base_where()
						. " AND c.user_id = %d AND c.comment_post_ID = %d AND c.comment_approved <> 'spam'",
					$user_id,
					$post_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$author_email = trim( $author_email );
		if ( '' === $author_email ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c WHERE " . self::base_where()
					. " AND LOWER(c.comment_author_email) = %s AND c.comment_post_ID = %d AND c.comment_approved <> 'spam'",
				strtolower( $author_email ),
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Counts grouped by status (for the list-table tabs).
	 *
	 * @param bool   $include_comments Count plain comments on enabled post types too.
	 * @param string $type             Narrow to `review`, `comment` or `reply`; '' counts all.
	 * @param bool   $include_replies  Count replies too.
	 * @return array<string,int>
	 */
	public static function counts_by_status( bool $include_comments = false, string $type = '', bool $include_replies = false ): array {
		global $wpdb;

		$join = $include_comments ? " INNER JOIN {$wpdb->posts} bp ON bp.ID = c.comment_post_ID" : '';

		// The tab counts have to answer the same question the list does, or the
		// numbers would advertise rows the current filter hides.
		$type     = self::valid_type( $type );
		$and_type = '';
		if ( '' !== $type ) {
			$and_type = ' AND ' . self::type_where( $type );
			
			$join .= " LEFT JOIN {$wpdb->commentmeta} mr ON mr.comment_id = c.comment_ID AND mr.meta_key = '" . self::META_RATING . "'"
				. " LEFT JOIN {$wpdb->commentmeta} mc ON mc.comment_id = c.comment_ID AND mc.meta_key = '" . self::META_CRITERIA . "'";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			'SELECT c.comment_approved AS approved, mj.meta_value AS rejected_flag, COUNT(*) AS c '
				. "FROM {$wpdb->comments} c" . $join . "
				LEFT JOIN {$wpdb->commentmeta} mj ON mj.comment_id = c.comment_ID AND mj.meta_key = '" . self::META_REJECTED . "'
				WHERE " . self::base_where( $include_comments, $include_replies ) . $and_type . '
				GROUP BY approved, rejected_flag',
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out        = array_fill_keys( self::STATUSES, 0 );
		$out['all'] = 0;
		foreach ( (array) $rows as $r ) {
			$status          = self::status_from_row( (string) $r['approved'], $r['rejected_flag'] ?? null );
			$c               = (int) $r['c'];
			$out[ $status ] += $c;

			// Mirrors the "All" query: trash is counted in its own tab only.
			if ( 'trash' !== $status ) {
				$out['all'] += $c;
			}
		}
		return $out;
	}

	/**
	 * Translate a comment's approval value (plus the rejected flag) into a
	 * BoldReview status.
	 *
	 * @param string      $approved      Raw `comment_approved` value.
	 * @param string|null $rejected_flag Raw rejected meta value, if any.
	 */
	protected static function status_from_row( string $approved, $rejected_flag ): string {
		switch ( $approved ) {
			case '1':
			case 'approve':
			case 'approved':
				return 'approved';
			case 'spam':
				return 'spam';
			case 'trash':
			case 'post-trashed':
				return 'trash';
			default:
				return '1' === (string) $rejected_flag ? 'rejected' : 'pending';
		}
	}

	/**
	 * Validate a status string.
	 */
	public static function valid_status( string $status ): string {
		return in_array( $status, self::STATUSES, true ) ? $status : 'pending';
	}

	/**
	 * Turn a joined comment row into the review array the plugin passes around.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	protected static function decode_row( array $row ): array {
		$raw = (string) ( $row['criteria_ratings'] ?? '' );

		$out = array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'post_id'          => (int) ( $row['post_id'] ?? 0 ),
			'parent_id'        => (int) ( $row['parent_id'] ?? 0 ),
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'author_name'      => (string) ( $row['author_name'] ?? '' ),
			'author_email'     => (string) ( $row['author_email'] ?? '' ),
			'author_url'       => (string) ( $row['author_url'] ?? '' ),
			'title'            => (string) ( $row['title'] ?? '' ),
			'content'          => (string) ( $row['content'] ?? '' ),
			'rating'           => (int) round( (float) ( $row['rating'] ?? 0 ) ),
			'criteria_ratings' => '',
			'criteria'         => array(),
			'status'           => self::status_from_row( (string) ( $row['comment_approved'] ?? '0' ), $row['rejected_flag'] ?? null ),
			'ip_address'       => (string) ( $row['ip_address'] ?? '' ),
			'user_agent'       => (string) ( $row['user_agent'] ?? '' ),
			'created_at'       => (string) ( $row['created_at'] ?? '' ),
			'updated_at'       => (string) ( $row['updated_at'] ?? ( $row['created_at'] ?? '' ) ),
		);

		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out['criteria'] = $decoded;
			}
		}

		return $out;
	}
}
