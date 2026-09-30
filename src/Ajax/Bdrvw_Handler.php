<?php
/**
 * AJAX endpoint handler.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Ajax;

use BoldReview\Plugin\Admin\Bdrvw_ReviewPanel;
use BoldReview\Plugin\Admin\Bdrvw_SettingsPage;
use BoldReview\Plugin\Core\Bdrvw_Modules;
use BoldReview\Plugin\Core\Bdrvw_Photos;
use BoldReview\Plugin\Core\Bdrvw_Sanitizer;
use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Frontend\Bdrvw_ReviewRenderer;
use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Secure AJAX endpoints: submit, moderate, delete.
 */
class Bdrvw_Handler {

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
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_bdrvw_submit', array( $this, 'submit' ) );
		add_action( 'wp_ajax_nopriv_bdrvw_submit', array( $this, 'submit' ) );
		add_action( 'wp_ajax_bdrvw_moderate', array( $this, 'moderate' ) );
		add_action( 'wp_ajax_bdrvw_delete', array( $this, 'delete' ) );
		add_action( 'wp_ajax_bdrvw_toggle_module', array( $this, 'toggle_module' ) );
		add_action( 'wp_ajax_bdrvw_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_bdrvw_reset_module', array( $this, 'reset_module' ) );
		add_action( 'wp_ajax_bdrvw_search_posts', array( $this, 'search_posts' ) );
		add_action( 'wp_ajax_bdrvw_review_panel', array( $this, 'review_panel' ) );
		add_action( 'wp_ajax_bdrvw_save_review', array( $this, 'save_review' ) );
		add_action( 'wp_ajax_bdrvw_add_reply', array( $this, 'add_reply' ) );
	}

	/**
	 * AJAX: persist the "Edit review" form — reviews and replies alike.
	 *
	 * Only the fields the form actually rendered are written. A reply has no
	 * rating, headline, criteria or photos, so those keys are simply absent
	 * from its post and are left untouched rather than being blanked.
	 */
	public function save_review(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$review = $id > 0 ? Bdrvw_Review::find( $id ) : null;
		if ( null === $review ) {
			wp_send_json_error( array( 'message' => __( 'Review not found.', 'boldreview' ) ), 404 );
		}

		$is_reply = (int) ( $review['parent_id'] ?? 0 ) > 0;

		$email_raw = isset( $_POST['author_email'] ) ? sanitize_text_field( wp_unslash( $_POST['author_email'] ) ) : '';
		$email     = sanitize_email( $email_raw );
		if ( '' !== $email_raw && ! is_email( $email ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please enter a valid email address.', 'boldreview' ),
					'field'   => 'author_email',
				),
				422
			);
		}

		$data = array(
			'status'  => Bdrvw_Review::valid_status( isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '' ),
			'content' => isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '',
		);

		if ( isset( $_POST['author_name'] ) ) {
			$data['author_name'] = sanitize_text_field( wp_unslash( $_POST['author_name'] ) );
		}
		if ( isset( $_POST['author_email'] ) ) {
			$data['author_email'] = $email;
		}

		if ( ! $is_reply ) {
			if ( isset( $_POST['title'] ) ) {
				$data['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
			}
			if ( isset( $_POST['rating'] ) ) {
				$data['rating'] = max( 0, min( 5, (int) $_POST['rating'] ) );
			}

			$criteria = array();
			if ( isset( $_POST['criteria'] ) && is_array( $_POST['criteria'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- key + value sanitized in the loop.
				foreach ( (array) wp_unslash( $_POST['criteria'] ) as $key => $val ) {
					$key = sanitize_key( (string) $key );
					$val = max( 0, min( 5, (int) $val ) );
					if ( '' !== $key && $val > 0 ) {
						$criteria[ $key ] = $val;
					}
				}
				$data['criteria_ratings'] = empty( $criteria ) ? '' : (string) wp_json_encode( $criteria );
			}

			if ( ! empty( $_POST['photos_present'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int below.
				$posted         = isset( $_POST['photos'] ) ? (array) wp_unslash( $_POST['photos'] ) : array();
				$data['photos'] = array_values( array_filter( array_map( 'absint', $posted ) ) );
			}
		}

		if ( ! Bdrvw_Review::bdrvw_update( $id, $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Update failed.', 'boldreview' ) ), 500 );
		}

		
		if ( ! $is_reply && ! empty( $_FILES[ Bdrvw_Photos::FIELD ] ) ) {
			
			$uploads = map_deep( (array) $_FILES[ Bdrvw_Photos::FIELD ], 'sanitize_text_field' );
			Bdrvw_Photos::attach_uploads( $id, (int) ( $review['post_id'] ?? 0 ), $uploads );
		}


		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => $is_reply
					? __( 'Reply updated', 'boldreview' )
					: __( 'Review updated', 'boldreview' ),
			)
		);
	}

	/**
	 * AJAX: body markup for the "View details" / "Edit review" side panel.
	 */
	public function review_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_GET['_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'view';

		$review = Bdrvw_Review::find( $id );
		if ( null === $review ) {
			wp_send_json_error( array( 'message' => __( 'Review not found.', 'boldreview' ) ), 404 );
		}

		$panel = new Bdrvw_ReviewPanel( $this->settings );
		$mode  = in_array( $mode, array( 'edit', 'reply' ), true ) ? $mode : 'view';

		if ( 'reply' === $mode && Bdrvw_ReviewPanel::is_reply( $review ) ) {
			$mode = 'view';
		}

		switch ( $mode ) {
			case 'edit':
				$html = $panel->edit_form_html( $review );
				break;
			case 'reply':
				$html = $panel->reply_form_html( $review );
				break;
			default:
				$html = $panel->details_html( $review );
		}

		wp_send_json_success(
			array(
				'id'    => $id,
				'mode'  => $mode,
				'title' => $this->panel_title( $mode, Bdrvw_ReviewPanel::is_reply( $review ) ),
				'html'  => $html,
			)
		);
	}

	/**
	 * AJAX: post a reply to a review.
	 *
	 * Stored as an ordinary child comment — the same shape WordPress and
	 * WooCommerce already use for review replies, so the theme renders it
	 * without any help from us.
	 */
	public function add_reply(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$parent = $id > 0 ? Bdrvw_Review::find( $id ) : null;
		if ( null === $parent ) {
			wp_send_json_error( array( 'message' => __( 'Review not found.', 'boldreview' ) ), 404 );
		}
		if ( (int) ( $parent['parent_id'] ?? 0 ) > 0 ) {
			wp_send_json_error( array( 'message' => __( 'You can only reply to a review.', 'boldreview' ) ), 400 );
		}

		$content = isset( $_POST['content'] ) ? trim( wp_kses_post( wp_unslash( $_POST['content'] ) ) ) : '';
		if ( '' === $content ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please write a reply first.', 'boldreview' ),
					'field'   => 'content',
				),
				422
			);
		}

		$user = wp_get_current_user();
		$now  = current_time( 'mysql' );

		$reply_id = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $parent['post_id'],
				'comment_parent'       => $id,
				'comment_content'      => $content,
				'comment_type'         => 'comment',
				'comment_approved'     => 1,
				'user_id'              => (int) $user->ID,
				'comment_author'       => (string) $user->display_name,
				'comment_author_email' => (string) $user->user_email,
				'comment_author_url'   => (string) $user->user_url,
				'comment_date'         => $now,
				'comment_date_gmt'     => get_gmt_from_date( $now ),
			)
		);

		if ( ! $reply_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not post the reply. Please try again.', 'boldreview' ) ), 500 );
		}

		/**
		 * Fires after an admin replies to a review.
		 *
		 * @param int $reply_id New comment id.
		 * @param int $id       Review replied to.
		 */
		do_action( 'bdrvw_review_replied', (int) $reply_id, $id );

		wp_send_json_success(
			array(
				'id'      => (int) $reply_id,
				'message' => __( 'Reply posted', 'boldreview' ),
			)
		);
	}

	/**
	 * Heading for the side panel. A reply is not a review, and calling it one
	 * makes the stripped-down edit form look broken rather than deliberate.
	 *
	 * @param string $mode     `edit`, `reply` or `view`.
	 * @param bool   $is_reply Whether the row is a reply.
	 */
	protected function panel_title( string $mode, bool $is_reply ): string {
		if ( 'reply' === $mode ) {
			return __( 'Reply to review', 'boldreview' );
		}
		if ( 'edit' === $mode ) {
			return $is_reply ? __( 'Edit reply', 'boldreview' ) : __( 'Edit review', 'boldreview' );
		}
		return $is_reply ? __( 'Reply details', 'boldreview' ) : __( 'Review details', 'boldreview' );
	}

	/**
	 * AJAX: Select2 search for posts of a given post type.
	 * Powers the criteria-group "Show on" picker in Rating Summary settings.
	 */
	public function search_posts(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_GET['_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$pt   = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
		$per  = 20;
		
		$allowed = array_map( 'sanitize_key', (array) $this->settings->get( 'enabled_post_types', array() ) );

		if ( '' === $pt || ! in_array( $pt, $allowed, true ) || ! post_type_exists( $pt ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'boldreview' ) ), 400 );
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $pt,
				'post_status'            => array( 'publish', 'private', 'draft' ),
				's'                      => $term,
				'posts_per_page'         => $per,
				'paged'                  => $page,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = array(
				'id'   => (int) $p->ID,
				'text' => '' !== $p->post_title ? $p->post_title : sprintf( '#%d', (int) $p->ID ),
			);
		}

		wp_send_json_success(
			array(
				'items'       => $items,
				'has_more'    => $page < (int) $query->max_num_pages,
				'total'       => (int) $query->found_posts,
				'page'        => $page,
			)
		);
	}

	/**
	 * AJAX: persist a per-module settings payload without a page reload.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['bdrvw_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bdrvw_settings_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_save_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		
		$submitted_settings = array();

		if ( isset( $_POST['bdrvw_settings'] ) && is_array( $_POST['bdrvw_settings'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Bdrvw_Sanitizer::settings().
			$submitted_settings = isset( $_POST['bdrvw_settings'] ) && is_array( $_POST['bdrvw_settings'] ) ? wp_unslash( $_POST['bdrvw_settings'] ) : array();
		}
	
		$module    = isset( $_POST['bdrvw_settings_module'] ) ? sanitize_key( wp_unslash( $_POST['bdrvw_settings_module'] ) ) : '';
		$only_keys = Bdrvw_SettingsPage::module_keys( $module );

		if ( empty( $only_keys ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'boldreview' ) ), 400 );
		}

		$this->settings->save( $submitted_settings, $only_keys );

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully', 'boldreview' ),
			)
		);
	}

	/**
	 * AJAX: reset a single module's settings back to defaults.
	 */
	public function reset_module(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		if ( '' === $module || ! Bdrvw_Modules::get( $module ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'boldreview' ) ), 400 );
		}

		$only_keys = Bdrvw_SettingsPage::module_keys( $module );
		$this->settings->reset_keys( $only_keys );

		wp_send_json_success(
			array(
				'message' => __( 'Module reset to defaults', 'boldreview' ),
			)
		);
	}

	/**
	 * Toggle a module on/off (admin only).
	 */
	public function toggle_module(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$active = ! empty( $_POST['active'] );

		if ( '' === $module || ! Bdrvw_Modules::get( $module ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'boldreview' ) ), 400 );
		}

		Bdrvw_Modules::set_active( $module, $active );

		wp_send_json_success(
			array(
				'module'       => $module,
				'active'       => $active,
				'active_count' => count( Bdrvw_Modules::active() ),
				'total'        => count( Bdrvw_Modules::definitions() ),
			)
		);
	}

	/**
	 * Handle a public review submission.
	 */
	public function submit(): void {
		// Verify nonce.
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_submit' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		
		if ( $this->settings->get( 'require_login' ) && ! is_user_logged_in() ) {
			wp_send_json_error(
				array( 'message' => (string) $this->settings->get( 'strings.login_required' ) ),
				401
			);
		}

	
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field in Bdrvw_Sanitizer::review_payload().
		$raw = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
		$payload = Bdrvw_Sanitizer::review_payload( $raw );

	
		if ( $payload['post_id'] <= 0 || ! get_post( $payload['post_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid target post.', 'boldreview' ) ), 400 );
		}
		$post_type = get_post_type( $payload['post_id'] );
		$enabled   = (array) $this->settings->get( 'enabled_post_types', array() );
		if ( ! in_array( $post_type, $enabled, true ) ) {
			
			$post_token = isset( $raw['post_token'] ) ? sanitize_text_field( (string) $raw['post_token'] ) : '';
			if ( '' === $post_token || ! wp_verify_nonce( $post_token, 'bdrvw_review_post_' . $payload['post_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Reviews are disabled for this content.', 'boldreview' ) ), 403 );
			}
		}

		/**
		 * Filter an eligibility gate error for a submission. Integrations (e.g. the
		 * WooCommerce verified-owner requirement) return a non-empty message to
		 * reject the review before it is stored.
		 *
		 * @param string              $error     Rejection message (empty allows).
		 * @param array<string,mixed> $payload   Sanitized review payload.
		 * @param string              $post_type Target post type.
		 */
		$gate_error = (string) apply_filters( 'bdrvw_submit_gate_error', '', $payload, (string) $post_type );
		if ( '' !== $gate_error ) {
			wp_send_json_error( array( 'message' => $gate_error ), 403 );
		}

		$required = (array) $this->settings->get( 'required_fields' );
		$fields   = (array) $this->settings->get( 'fields' );
		$errors   = array();

		$rating_input_on = (bool) $this->settings->get( 'rating_input_style_enabled', 1 );
		if ( $rating_input_on && ! empty( $required['rating'] ) && $payload['rating'] < 1 ) {
			$errors['rating'] = __( 'Please choose a rating.', 'boldreview' );
		}
		foreach ( array( 'author_name', 'author_email', 'author_url', 'title', 'content' ) as $f ) {
			if ( empty( $fields[ $f ] ) ) {
				$payload[ $f ] = '';
				continue;
			}
			if ( ! empty( $required[ $f ] ) && '' === trim( (string) $payload[ $f ] ) ) {
				$errors[ $f ] = __( 'This field is required.', 'boldreview' );
			}
		}
		if ( ! empty( $fields['author_email'] ) && '' !== $payload['author_email'] && ! is_email( $payload['author_email'] ) ) {
			$errors['author_email'] = __( 'Please enter a valid email address.', 'boldreview' );
		}

		$form_criteria = ( new Bdrvw_ReviewRenderer( $this->settings ) )->form_criteria( $this->settings->all() );
		$submitted_criteria = is_array( $payload['criteria'] ) ? $payload['criteria'] : array();
		foreach ( $form_criteria as $crit ) {
			$c_key = (string) ( $crit['key'] ?? '' );
			if ( '' === $c_key ) {
				continue;
			}
			if ( (int) ( $submitted_criteria[ $c_key ] ?? 0 ) < 1 ) {
				$errors[ 'criteria[' . $c_key . ']' ] = __( 'Please choose a rating.', 'boldreview' );
			}
		}

		/**
		 * Filter the field-level errors for a submission.
		 *
		 * Anything the form carries that isn't a review field validates here —
		 * the anti-spam question, and whatever provider an add-on puts in
		 * its place. Keys are the posted field names, so the frontend can pin
		 * each message to the input it belongs to.
		 *
		 * @param array<string,string> $errors  Errors collected so far.
		 * @param array<string,mixed>  $payload Sanitized review payload.
		 * @param array<string,mixed>  $raw     Raw, unslashed posted data.
		 */
		$errors = (array) apply_filters( 'bdrvw_submit_field_errors', $errors, $payload, $raw );

		if ( $errors ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the errors and try again.', 'boldreview' ),
					'fields'  => $errors,
				),
				422
			);
		}

		
		$user_id = get_current_user_id();

	
		$max_per_user = (int) $this->settings->get( 'max_reviews_per_user', 1 );
		if ( $max_per_user > 0 ) {
			$already = Bdrvw_Review::count_user_reviews_on_post(
				(int) $user_id,
				(int) $payload['post_id'],
				(string) $payload['author_email']
			);
			if ( $already >= $max_per_user ) {
				wp_send_json_error(
					array(
						'message' => 1 === $max_per_user
							? __( 'You have already submitted a review for this item.', 'boldreview' )
							: sprintf(
								/* translators: %d: number of reviews allowed per user per post. */
								_n( 'You can only submit %d review for this item.', 'You can only submit %d reviews for this item.', $max_per_user, 'boldreview' ),
								$max_per_user
							),
					),
					429
				);
			}
		}

		$limit_by = (string) $this->settings->get( 'review_limit_by', '' );

		if ( 'ip_address' === $limit_by ) {
			$ip = $this->client_ip();

			if ( '' !== $ip && self::ip_is_blocked( $ip, (string) $this->settings->get( 'blocked_ips', '' ) ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Your review could not be submitted.', 'boldreview' ) ),
					403
				);
			}

			if ( '' !== $ip && Bdrvw_Review::count_reviews_on_post_by( (int) $payload['post_id'], 'ip_address', $ip ) > 0 ) {
				wp_send_json_error(
					array( 'message' => __( 'A review has already been submitted from this device for this item.', 'boldreview' ) ),
					429
				);
			}
		}

		if ( 'email' === $limit_by ) {
			$email = (string) $payload['author_email'];
			// A whitelisted address is exempt — that is the whole point of it.
			$exempt = self::list_has( $email, (string) $this->settings->get( 'email_whitelist', '' ) );

			if ( '' !== $email && ! $exempt && Bdrvw_Review::count_reviews_on_post_by( (int) $payload['post_id'], 'email', $email ) > 0 ) {
				wp_send_json_error(
					array( 'message' => __( 'A review has already been submitted from this email address for this item.', 'boldreview' ) ),
					429
				);
			}
		}

		
		$blacklisted = $this->is_blacklisted( $payload );
		if ( $blacklisted ) {
			$action = (string) $this->settings->get( 'blacklist.action', 'unapprove' );
			if ( 'reject' === $action ) {
				wp_send_json_error(
					array( 'message' => __( 'Your review could not be submitted.', 'boldreview' ) ),
					403
				);
			}
		}

		// Build row. A blacklisted review can never auto-approve — it stays pending.
		$status = ( $this->settings->get( 'auto_approve' ) && ! $blacklisted ) ? 'approved' : 'pending';

		$data = array(
			'post_id'      => (int) $payload['post_id'],
			'user_id'      => (int) $user_id,
			'author_name'  => (string) $payload['author_name'],
			'author_email' => (string) $payload['author_email'],
			'author_url'   => (string) $payload['author_url'],
			'title'        => (string) $payload['title'],
			'content'      => (string) $payload['content'],
			'rating'       => (int) $payload['rating'],
			'criteria'     => $payload['criteria'],
			'status'       => $status,
			'ip_address'   => $this->client_ip(),
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
		);

		$id = Bdrvw_Review::bdrvw_insert( $data );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save your review. Please try again.', 'boldreview' ) ), 500 );
		}

		if ( Bdrvw_Photos::enabled( $this->settings ) && ! empty( $_FILES[ Bdrvw_Photos::FIELD ] ) ) {
			
			$uploads = map_deep( (array) $_FILES[ Bdrvw_Photos::FIELD ], 'sanitize_text_field' );
			Bdrvw_Photos::attach_uploads( $id, (int) $payload['post_id'], $uploads );
		}

		/**
		 * Fires when a review is submitted.
		 *
		 * @param int   $id   Review id.
		 * @param array $data Review data.
		 */
		do_action( 'bdrvw_review_submitted', $id, $data );

		$list_html = '';
		if ( 'approved' === $status ) {
			$layout   = sanitize_key( (string) $this->settings->get( 'display_layout', 'list' ) );
			$template = sanitize_key( (string) $this->settings->get( 'display_template', 'classic' ) );
			$limit    = max( 1, min( 100, (int) $this->settings->get( 'reviews_per_page', 10 ) ) );
			$result   = Bdrvw_Review::query(
				array(
					'post_id'  => (int) $payload['post_id'],
					'status'   => 'approved',
					'per_page' => $limit,
					'page'     => 1,
				)
			);
			$aggregate = Bdrvw_Review::aggregate_for_post( (int) $payload['post_id'] );
			$renderer  = new \BoldReview\Plugin\Frontend\Bdrvw_ReviewRenderer( $this->settings );
			$list_html = $renderer->render_list( $result, $aggregate, (int) $payload['post_id'], $layout, $template, 1 );
		}

		wp_send_json_success(
			array(
				'message'   => (string) $this->settings->get( 'strings.success_message' ),
				'status'    => $status,
				'post_id'   => (int) $payload['post_id'],
				'list_html' => $list_html,
			)
		);
	}

	/**
	 * Moderate review (admin only).
	 */
	public function moderate(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( $id <= 0 || ! in_array( $status, Bdrvw_Review::STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'boldreview' ) ), 400 );
		}

		$ok = Bdrvw_Review::bdrvw_update( $id, array( 'status' => $status ) );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Update failed.', 'boldreview' ) ), 500 );
		}

		// The model raises `bdrvw_review_status_changed` for every route.

		wp_send_json_success( array( 'id' => $id, 'status' => $status ) );
	}

	/**
	 * Delete review (admin only).
	 */
	public function delete(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
		$nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'boldreview' ) ), 400 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'boldreview' ) ), 400 );
		}

		if ( ! Bdrvw_Review::bdrvw_delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Delete failed.', 'boldreview' ) ), 500 );
		}

		do_action( 'bdrvw_review_deleted', $id );

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Decide whether a review submission is blacklisted.
	 *
	 * Depending on the "Blacklist" integration setting this checks the reviewer's
	 * name, email, website, review text and IP against either BoldReview's own
	 * word list or WordPress' Disallowed Comment Keys.
	 *
	 * @param array<string,mixed> $payload Sanitized review payload.
	 */
	protected function is_blacklisted( array $payload ): bool {
		$integration = (string) $this->settings->get( 'blacklist.integration', 'comments' );

		$author  = (string) $payload['author_name'];
		$email   = (string) $payload['author_email'];
		$url     = (string) $payload['author_url'];
		$content = trim( (string) $payload['title'] . "\n" . (string) $payload['content'] );
		$ip      = $this->client_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( 'comments' === $integration ) {
			if ( function_exists( 'wp_check_comment_disallowed_list' ) ) {
				return (bool) wp_check_comment_disallowed_list( $author, $email, $url, $content, $ip, $ua );
			}
			return false;
		}

		$words = (string) $this->settings->get( 'blacklist.words', '' );
		return $this->match_disallowed_words( $words, array( $author, $email, $url, $content, $ip ) );
	}

	/**
	 * Match a newline-separated word list against a set of haystacks, mirroring
	 * WordPress' own disallowed-list logic: each word is a case-insensitive,
	 * substring (regex-quoted) match.
	 *
	 * @param string             $list      Words/phrases, one per line.
	 * @param array<int,string>  $haystacks Values to scan.
	 */
	protected function match_disallowed_words( string $list, array $haystacks ): bool {
		$list = trim( $list );
		if ( '' === $list ) {
			return false;
		}

		$target = implode( "\n", array_map( 'strval', $haystacks ) );
		$words  = preg_split( '/\r\n|\r|\n/', $list );

		foreach ( (array) $words as $word ) {
			$word = trim( $word );
			if ( '' === $word ) {
				continue;
			}
			if ( preg_match( '#' . preg_quote( $word, '#' ) . '#i', $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Best-effort client IP capture.
	 */
	/**
	 * Split one of the admin's line- or comma-separated lists into trimmed,
	 * lowercased entries.
	 *
	 * @param string $raw Raw setting value.
	 * @return array<int,string>
	 */
	protected static function parse_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$parts = preg_split( '/[,\r\n]+/', $raw );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = strtolower( trim( (string) $part ) );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Whether a value appears in one of those lists.
	 *
	 * @param string $value Value to look for.
	 * @param string $raw   Raw setting value.
	 */
	protected static function list_has( string $value, string $raw ): bool {
		$value = strtolower( trim( $value ) );
		return '' !== $value && in_array( $value, self::parse_list( $raw ), true );
	}

	/**
	 * Whether an IP is on the blocklist.
	 *
	 * A trailing `*` stands for the rest of the address, so `203.0.113.*` covers
	 * that whole block — the usual case, since a nuisance rarely comes back from
	 * the exact same address twice.
	 *
	 * @param string $ip  Address the request came from.
	 * @param string $raw Raw setting value.
	 */
	protected static function ip_is_blocked( string $ip, string $raw ): bool {
		$ip = strtolower( trim( $ip ) );
		if ( '' === $ip ) {
			return false;
		}

		foreach ( self::parse_list( $raw ) as $entry ) {
			if ( $entry === $ip ) {
				return true;
			}
			if ( '*' === substr( $entry, -1 ) ) {
				$prefix = rtrim( substr( $entry, 0, -1 ), '.:' );
				if ( '' !== $prefix && ( 0 === strpos( $ip, $prefix . '.' ) || 0 === strpos( $ip, $prefix . ':' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	protected function client_ip(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return substr( (string) $ip, 0, 100 );
	}
}
