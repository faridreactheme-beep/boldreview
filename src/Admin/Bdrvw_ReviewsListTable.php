<?php
/**
 * Reviews admin list — extends core WP_List_Table for the standard table UI
 * (bulk actions, sortable columns, hover row actions, native pagination,
 * status views and screen options).
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The native-WP "All Reviews" table.
 */
class Bdrvw_ReviewsListTable extends \WP_List_Table {

	/**
	 * Status filter currently in effect (e.g. "pending"). Empty means "all".
	 *
	 * @var string
	 */
	protected $current_status = '';

	/**
	 * Per-status counts, used by `get_views()` for the tab labels.
	 *
	 * @var array<string,int>
	 */
	protected $counts = array();

	/**
	 * Minimum rating currently filtered on (0 = no rating filter).
	 *
	 * @var int
	 */
	protected $current_rating = 0;

	/**
	 * Kind of entry currently filtered on — `review`, `comment`, `reply`, or ''.
	 *
	 * @var string
	 */
	protected $current_type = '';

	/**
	 * Requested entry type, '' when the filter is off or the value is unknown.
	 */
	protected static function requested_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$type = isset( $_GET['review_type'] ) ? sanitize_key( wp_unslash( $_GET['review_type'] ) ) : '';
		return Bdrvw_Review::valid_type( $type );
	}

	/**
	 * Entry-type filter choices.
	 *
	 * One per kind of row the list can hold, matching what the Rating column
	 * shows: "Reviews" carry a rating, "Comments" don't, "Replies" are the
	 * answers written under either.
	 *
	 * @return array<string,string>
	 */
	protected static function type_choices(): array {
		return array(
			'review'  => __( 'Reviews', 'boldreview' ),
			'comment' => __( 'Comments', 'boldreview' ),
			'reply'   => __( 'Replies', 'boldreview' ),
		);
	}

	/**
	 * Requested minimum rating, 0 when the filter is off or out of range.
	 */
	protected static function requested_rating(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$rating = isset( $_GET['rating'] ) ? (int) $_GET['rating'] : 0;
		return ( $rating >= 1 && $rating <= 5 ) ? $rating : 0;
	}

	/**
	 * Requested sort direction — `newest` (default) or `oldest`.
	 */
	protected static function requested_sort(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$sort = isset( $_GET['bdrvw_sort'] ) ? sanitize_key( wp_unslash( $_GET['bdrvw_sort'] ) ) : '';
		return 'oldest' === $sort ? 'oldest' : 'newest';
	}

	/**
	 * Rating filter choices — "N star & above", plus an exact match at 5 since
	 * nothing sits above it.
	 *
	 * @return array<int,string>
	 */
	protected static function rating_choices(): array {
		return array(
			5 => __( '5 star', 'boldreview' ),
			4 => __( '4 star & above', 'boldreview' ),
			3 => __( '3 star & above', 'boldreview' ),
			2 => __( '2 star & above', 'boldreview' ),
			1 => __( '1 star & above', 'boldreview' ),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bdrvw_review',
				'plural'   => 'bdrvw_reviews',
				'ajax'     => false,
				'screen'   => 'bdrvw-reviews',
			)
		);
	}

	/**
	 * Columns shown in the table header.
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'id'       => __( 'ID', 'boldreview' ),
			'review'   => __( 'Review', 'boldreview' ),
			'reviewer' => __( 'Reviewer', 'boldreview' ),
			'rating'   => __( 'Rating', 'boldreview' ),
			'post'     => __( 'In response to', 'boldreview' ),
			'status'   => __( 'Status', 'boldreview' ),
			'date'     => __( 'Submitted on', 'boldreview' ),
			'action'   => __( 'Action', 'boldreview' ),
		);
	}

	/**
	 * Columns the user can sort by — header keys mapped to DB column + initial dir.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'id'     => array( 'id', false ),
			'rating' => array( 'rating', false ),
			'status' => array( 'status', false ),
			'date'   => array( 'created_at', true ),
		);
	}

	/**
	 * The "primary" column carries the mobile toggle. `cb` would otherwise be
	 * picked because it is first, which hides the row content on small screens.
	 */
	protected function get_default_primary_column_name(): string {
		return 'review';
	}

	/**
	 * Per-status tabs above the table.
	 */
	protected function get_views(): array {
		$labels = array(
			'all'      => __( 'All', 'boldreview' ),
			'pending'  => __( 'Pending', 'boldreview' ),
			'approved' => __( 'Approved', 'boldreview' ),
			'rejected' => __( 'Rejected', 'boldreview' ),
			'spam'     => __( 'Spam', 'boldreview' ),
			'trash'    => __( 'Trash', 'boldreview' ),
		);

		
		$rating = self::requested_rating();
		$sort   = self::requested_sort();
		$type   = self::requested_type();

		$views = array();
		foreach ( $labels as $key => $label ) {
			$is_active = ( '' === $this->current_status && 'all' === $key ) || $this->current_status === $key;
			$url       = add_query_arg(
				array_filter(
					array(
						'page'        => 'bdrvw-reviews',
						'status'      => $key,
						'review_type' => '' !== $type ? $type : null,
						'rating'      => $rating > 0 ? $rating : null,
						'bdrvw_sort'  => 'oldest' === $sort ? 'oldest' : null,
					)
				),
				admin_url( 'admin.php' )
			);
			$count     = isset( $this->counts[ $key ] ) ? (int) $this->counts[ $key ] : 0;
			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$is_active ? ' class="current"' : '',
				esc_html( $label ),
				$count
			);
		}
		return $views;
	}

	/**
	 * Rating + sort filters, rendered into the tablenav above the table (they
	 * submit with the page's GET form alongside the status and search).
	 *
	 * @param string $which "top" or "bottom".
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$rating = self::requested_rating();
		$sort   = self::requested_sort();
		$type   = self::requested_type();
		?>
		<div class="alignleft actions bdrvw-filters">
			<label class="screen-reader-text" for="bdrvw-filter-type"><?php esc_html_e( 'Filter by type', 'boldreview' ); ?></label>
			<select name="review_type" id="bdrvw-filter-type">
				<option value=""><?php esc_html_e( 'All types', 'boldreview' ); ?></option>
				<?php foreach ( self::type_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="bdrvw-filter-rating"><?php esc_html_e( 'Filter by rating', 'boldreview' ); ?></label>
			<select name="rating" id="bdrvw-filter-rating">
				<option value=""><?php esc_html_e( 'All ratings', 'boldreview' ); ?></option>
				<?php foreach ( self::rating_choices() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $rating, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="bdrvw-filter-sort"><?php esc_html_e( 'Sort reviews', 'boldreview' ); ?></label>
			<select name="bdrvw_sort" id="bdrvw-filter-sort">
				<option value="newest" <?php selected( $sort, 'newest' ); ?>><?php esc_html_e( 'Newest first', 'boldreview' ); ?></option>
				<option value="oldest" <?php selected( $sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'boldreview' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'boldreview' ), 'button', 'bdrvw-filter', false ); ?>
		</div>
		<?php
	}

	/**
	 * Search box with a placeholder spelling out what the query matches. Core's
	 * `search_box()` has no placeholder support, hence the override.
	 *
	 * @param string $text     Label text.
	 * @param string $input_id Base id for the input.
	 */
	public function search_box( $text, $input_id ): void {
		$input_id = $input_id . '-search-input';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search by reviewer, product or keyword…', 'boldreview' ); ?>"
			/>
			<?php submit_button( __( 'Search', 'boldreview' ), 'button', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

	/**
	 * Bulk actions available in the dropdown above/below the table.
	 */
	protected function get_bulk_actions(): array {
		return array(
			'approve' => __( 'Approve', 'boldreview' ),
			'pending' => __( 'Mark as Pending', 'boldreview' ),
			'reject'  => __( 'Reject', 'boldreview' ),
			'spam'    => __( 'Mark as Spam', 'boldreview' ),
			'trash'   => __( 'Move to Trash', 'boldreview' ),
			'delete'  => __( 'Delete permanently', 'boldreview' ),
		);
	}

	/**
	 * Wire the rows into core list-table state: items, columns, pagination.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$this->current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged                = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search               = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby              = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order                = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable

		$this->current_rating = self::requested_rating();
		$this->current_type   = self::requested_type();

		if ( '' === $orderby ) {
			$orderby = 'created_at';
			$order   = 'oldest' === self::requested_sort() ? 'asc' : 'desc';
		}

		$per_page = (int) $this->get_items_per_page( 'bdrvw_reviews_per_page', 20 );

		$result = Bdrvw_Review::query(
			array(
				'status'     => '' === $this->current_status || 'all' === $this->current_status ? '' : $this->current_status,
				'type'       => $this->current_type,
				'rating_min' => $this->current_rating,
				'page'       => $paged,
				'per_page'   => $per_page,
				'search'     => $search,
				'orderby'    => $orderby,
				'order'      => $order,
				'include_comments' => true,
				'include_replies'  => true,
			)
		);

		$this->counts = Bdrvw_Review::counts_by_status( true, $this->current_type, true );

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => (int) $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) $result['pages'],
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="review_ids[]" value="%d" />',
			(int) ( $item['id'] ?? 0 )
		);
	}

	/**
	 * Review id column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_id( $item ): string {
		return '<span class="bdrvw-review-id">#' . (int) ( $item['id'] ?? 0 ) . '</span>';
	}

	/**
	 * Reviewer column — name with email below.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_reviewer( $item ): string {
		$name  = (string) ( $item['author_name'] ?? '' );
		$email = (string) ( $item['author_email'] ?? '' );

		$out = '<strong>' . esc_html( '' !== $name ? $name : __( 'Anonymous', 'boldreview' ) ) . '</strong>';
		if ( '' !== $email ) {
			$out .= '<br /><a href="mailto:' . esc_attr( antispambot( $email ) ) . '">' . esc_html( antispambot( $email ) ) . '</a>';
		}
		return $out;
	}

	/**
	 * Review column — thumbnail of the reviewed item, plus title + excerpt.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_review( $item ): string {
		$post_id = (int) ( $item['post_id'] ?? 0 );
		$parent  = (int) ( $item['parent_id'] ?? 0 );
		$title   = (string) ( $item['title'] ?? '' );
		$excerpt = wp_trim_words( wp_strip_all_tags( (string) ( $item['content'] ?? '' ) ), 28 );
		// Products only — a review left on an ordinary post shows no image.
		$thumb = Bdrvw_ReviewPanel::thumb_url( $post_id );

		$out = '<div class="bdrvw-review-cell">';
		if ( '' !== $thumb ) {
			$out .= '<img class="bdrvw-review-cell__thumb" src="' . esc_url( $thumb ) . '" alt="" />';
		}
		$out .= '<div class="bdrvw-review-cell__text">';
		if ( $parent > 0 ) {
			// Without this a reply reads as a review of its own once the filter
			// is back on "All types".
			$out .= $this->in_reply_to_html( $parent );
		}
		if ( '' !== $title ) {
			$out .= '<strong>' . esc_html( $title ) . '</strong>';
		}
		if ( '' !== $excerpt ) {
			$out .= '<span class="bdrvw-review-cell__excerpt">' . esc_html( $excerpt ) . '</span>';
		}
		if ( '' === $title && '' === $excerpt ) {
			$out .= '<span class="bdrvw-review-cell__excerpt">&mdash;</span>';
		}
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * "In reply to <author>." line shown above a reply's own text, the way core's
	 * comments list marks a threaded answer. The name opens the parent row's
	 * detail panel, so the review being answered is one click away.
	 *
	 * @param int $parent_id Id of the review or comment being replied to.
	 */
	protected function in_reply_to_html( int $parent_id ): string {
		$line = Bdrvw_ReviewPanel::in_reply_to_html( array( 'parent_id' => $parent_id ) );

		if ( '' === $line ) {
			return '<span class="bdrvw-reply-tag">' . esc_html__( 'Reply', 'boldreview' ) . '</span>';
		}

		return $line;
	}

	/**
	 * Action column — kebab menu holding view / edit / moderation actions.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_action( $item ): string {
		$id = (int) ( $item['id'] ?? 0 );

		$out  = '<div class="bdrvw-rowmenu" data-bdrvw-rowmenu>';
		$out .= '<button type="button" class="bdrvw-rowmenu__btn" data-bdrvw-rowmenu-toggle aria-haspopup="true" aria-expanded="false"'
			. ' aria-label="' . esc_attr__( 'Review actions', 'boldreview' ) . '"><span aria-hidden="true">&#8942;</span></button>';
		$out .= '<div class="bdrvw-rowmenu__list" role="menu">';

		$out .= '<button type="button" class="bdrvw-rowmenu__item" role="menuitem" data-bdrvw-view="' . esc_attr( (string) $id ) . '">'
			. esc_html__( 'View details', 'boldreview' ) . '</button>';

		if ( ! Bdrvw_ReviewPanel::is_reply( $item ) ) {
			$out .= '<button type="button" class="bdrvw-rowmenu__item" role="menuitem" data-bdrvw-reply="' . esc_attr( (string) $id ) . '">'
				. '<span class="dashicons dashicons-admin-comments bdrvw-rowmenu__icon" aria-hidden="true"></span>'
				. esc_html__( 'Reply', 'boldreview' ) . '</button>';
		}

		$is_reply = Bdrvw_ReviewPanel::is_reply( $item );
		$label    = $is_reply ? __( 'Edit reply', 'boldreview' ) : __( 'Edit review', 'boldreview' );

		if ( Bdrvw_ReviewPanel::edit_enabled( $item ) ) {
			$out .= '<button type="button" class="bdrvw-rowmenu__item" role="menuitem" data-bdrvw-edit="' . esc_attr( (string) $id ) . '">'
				. esc_html( $label ) . '</button>';
		} else {
			
			$out .= '<span class="bdrvw-rowmenu__item is-plan" role="menuitem" aria-disabled="true">'
				. esc_html( $label )
				. '<span class="bdrvw-rowmenu__crown">' . Bdrvw_ReviewPanel::crown_svg( 15 ) . '</span></span>';
		}

		foreach ( $this->build_row_actions( $item ) as $link ) {
			$out .= $link;
		}

		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Rating column — dashicon stars.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_rating( $item ): string {
		
		$score  = Bdrvw_Review::effective_rating( $item );
		$filled = (int) round( $score );

		$label = $score > 0
			? sprintf(
				/* translators: %s: rating value, e.g. "4.5". */
				__( '%s out of 5', 'boldreview' ),
				number_format_i18n( $score, 1 )
			)
			: __( 'No rating', 'boldreview' );

		$out = '<span class="bdrvw-stars-cell" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$cls  = $i <= $filled ? 'dashicons-star-filled' : 'dashicons-star-empty';
			$out .= '<span class="dashicons ' . esc_attr( $cls ) . '"></span>';
		}
		$out .= '</span>';
		return $out;
	}

	/**
	 * Post column — link to edit screen when available.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_post( $item ): string {
		$post_id = (int) ( $item['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return '&mdash;';
		}
		
		$title = get_the_title( $post_id );
		$title = '' !== $title ? $title : '#' . $post_id;
		$edit  = get_edit_post_link( $post_id );

		if ( $edit ) {
			return '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
		}

		return esc_html( $title );
	}

	/**
	 * Status pill.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_status( $item ): string {
		$status = (string) ( $item['status'] ?? '' );
		return '<span class="bdrvw-status bdrvw-status--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	/**
	 * Date column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_date( $item ): string {
		$ts  = (string) ( $item['created_at'] ?? '' );
		if ( '' === $ts ) {
			return '&mdash;';
		}
		return esc_html(
			sprintf(
				/* translators: 1: date, 2: time */
				__( '%1$s at %2$s', 'boldreview' ),
				mysql2date( get_option( 'date_format' ), $ts ),
				mysql2date( get_option( 'time_format' ), $ts )
			)
		);
	}

	/**
	 * Default column fallback.
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Moderation entries for the row's action menu — the set on offer depends on
	 * the review's current status (a trashed review gets restore + delete, an
	 * approved one gets no "approve", and so on).
	 *
	 * @param array<string,mixed> $item Row.
	 * @return array<string,string>
	 */
	protected function build_row_actions( array $item ): array {
		$id     = (int) ( $item['id'] ?? 0 );
		$status = (string) ( $item['status'] ?? '' );

		$actions = array();

		if ( 'trash' === $status ) {
			$actions['approve'] = $this->row_action_link( $id, 'approve', __( 'Restore', 'boldreview' ) );
			$actions['delete']  = $this->row_action_link( $id, 'delete', __( 'Delete permanently', 'boldreview' ), true );
			return $actions;
		}

		if ( 'approved' !== $status ) {
			$actions['approve'] = $this->row_action_link( $id, 'approve', __( 'Approve', 'boldreview' ) );
		}
		if ( 'pending' !== $status ) {
			$actions['pending'] = $this->row_action_link( $id, 'pending', __( 'Mark as pending', 'boldreview' ) );
		}
		if ( 'rejected' !== $status ) {
			$actions['reject'] = $this->row_action_link( $id, 'reject', __( 'Reject', 'boldreview' ) );
		}
		if ( 'spam' !== $status ) {
			$actions['spam'] = $this->row_action_link( $id, 'spam', __( 'Move to spam', 'boldreview' ), true );
		}
		$actions['trash'] = $this->row_action_link( $id, 'trash', __( 'Move to trash', 'boldreview' ), true );

		return $actions;
	}

	/**
	 * Helper — build a single action-menu <a>.
	 *
	 * @param int    $id     Review id.
	 * @param string $action Action slug.
	 * @param string $label  Display label.
	 * @param bool   $danger Render in red (and confirm first, for `delete`).
	 */
	protected function row_action_link( int $id, string $action, string $label, bool $danger = false ): string {
		$confirm = 'delete' === $action
			? ' onclick="return confirm(\'' . esc_js( __( 'Delete this review permanently?', 'boldreview' ) ) . '\')"'
			: '';
		return sprintf(
			'<a class="bdrvw-rowmenu__item%s" role="menuitem" href="%s"%s>%s</a>',
			$danger ? ' is-danger' : '',
			esc_url( Bdrvw_ReviewsPage::action_url( $id, $action ) ),
			$confirm,
			esc_html( $label )
		);
	}

	/**
	 * Empty-state message.
	 */
	public function no_items(): void {
		esc_html_e( 'No reviews found.', 'boldreview' );
	}
}
