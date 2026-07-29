<?php
/**
 * Reviews moderation list page — backed by WP_List_Table.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reviews moderation list page using core's WP_List_Table for
 * the table itself (bulk actions, sortable columns, row actions, etc.).
 */
class Bdrvw_ReviewsPage {

	const CAPABILITY = 'manage_options';

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Render the page.
	 */
	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->maybe_handle_single_action();
		$this->maybe_handle_bulk_action();

		$table = new Bdrvw_ReviewsListTable();
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter, no state change.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		?>
		<div class="wrap bdrvw-reviews-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Reviews', 'boldreview' ); ?></h1>
			<hr class="wp-header-end" />

			<form method="get">
				<input type="hidden" name="page" value="bdrvw-reviews" />

				<?php if ( '' !== $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>

				<?php $table->search_box( __( 'Search by reviewer, product or keyword', 'boldreview' ), 'bdrvw-reviews' ); ?>
				<?php $table->views(); ?>
				<?php $table->display(); ?>
			</form>

			<?php $this->render_panel(); ?>

			<div class="bdrvw-toast" id="bdrvw-toast" role="status" aria-live="polite">
				<span class="dashicons dashicons-yes"></span>
				<span class="bdrvw-toast__text"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * The slide-in panel shell. Both "View details" and "Edit review" load their
	 * body into it over AJAX, so the page only ever ships one empty container.
	 */
	protected function render_panel(): void {
		?>
		<div class="bdrvw-panel" id="bdrvw-review-panel" hidden>
			<div class="bdrvw-panel__overlay" data-bdrvw-panel-close></div>
			<div class="bdrvw-panel__dialog" role="dialog" aria-modal="true" aria-labelledby="bdrvw-panel-title">
				<div class="bdrvw-panel__head">
					<h2 class="bdrvw-panel__title" id="bdrvw-panel-title"></h2>
					<button type="button" class="bdrvw-panel__close" data-bdrvw-panel-close aria-label="<?php esc_attr_e( 'Close', 'boldreview' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="bdrvw-panel__body" data-bdrvw-panel-body></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Nonced URL for a single-row moderation action. Shared by the list table's
	 * action menu and the details panel so both link through the same handler.
	 */
	public static function action_url( int $id, string $action ): string {
		return add_query_arg(
			array(
				'page'         => 'bdrvw-reviews',
				'review_id'    => $id,
				'bdrvw_action' => $action,
				'_wpnonce'     => wp_create_nonce( 'bdrvw_row_action_' . $id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Per-row action links go through `?bdrvw_action=…&review_id=…`
	 * (GET with a nonce). Handle the redirect inside `render()` so it runs
	 * before any output.
	 */
	/**
	 * Handle row actions.
	 */
	protected function maybe_handle_single_action(): void {

		if ( ! isset( $_GET['bdrvw_action'], $_GET['review_id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$id     = absint( wp_unslash( $_GET['review_id'] ) );
		$action = sanitize_key( wp_unslash( $_GET['bdrvw_action'] ) );
		$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'bdrvw_row_action_' . $id ) ) {
			return;
		}

		$this->apply_action( $id, $action );

		$redirect = remove_query_arg(
			array(
				'bdrvw_action',
				'review_id',
				'_wpnonce',
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Apply the WP_List_Table bulk-action selection to every checked id.
	 *
	 * Both top and bottom action selects post into the same request, but only
	 * the non-"-1" one wins (core re-uses the same field name).
	 */
	protected function maybe_handle_bulk_action(): void {
		
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'bulk-bdrvw_reviews' ) ) {
			return;
		}

		$action  = '-1';
		$candidates = array(
			isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '',
			isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '',
		);
		foreach ( $candidates as $c ) {
			if ( '' !== $c && '-1' !== $c ) {
				$action = $c;
				break;
			}
		}
		if ( '-1' === $action ) {
			return;
		}

		$ids = isset( $_REQUEST['review_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['review_ids'] ) ) : array();
	
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			$this->apply_action( (int) $id, $action );
		}

		$redirect = remove_query_arg( array( 'action', 'action2', '_wpnonce', 'review_ids' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Translate an action slug into a model write.
	 */
	protected function apply_action( int $id, string $action ): void {
		
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		switch ( $action ) {
			case 'approve':
				Bdrvw_Review::bdrvw_update( $id, array( 'status' => 'approved' ) );
				break;
			case 'pending':
				Bdrvw_Review::bdrvw_update( $id, array( 'status' => 'pending' ) );
				break;
			case 'reject':
				Bdrvw_Review::bdrvw_update( $id, array( 'status' => 'rejected' ) );
				break;
			case 'spam':
				Bdrvw_Review::bdrvw_update( $id, array( 'status' => 'spam' ) );
				break;
			case 'trash':
				Bdrvw_Review::bdrvw_update( $id, array( 'status' => 'trash' ) );
				break;
			case 'delete':
				Bdrvw_Review::bdrvw_delete( $id );
				break;
		}
	}
}
