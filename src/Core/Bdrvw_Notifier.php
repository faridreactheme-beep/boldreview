<?php
/**
 * Email notifications for review activity.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to plugin actions and sends admin-facing notification emails.
 */
class Bdrvw_Notifier {

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
		add_action( 'bdrvw_review_submitted', array( $this, 'maybe_notify_admin' ), 10, 2 );
	}

	/**
	 * Send the admin a notification email about a freshly-submitted review.
	 *
	 * No-op when the "Email admin on new review" toggle is off.
	 *
	 * @param int                $id   Review id.
	 * @param array<string,mixed> $data Saved row.
	 */
	public function maybe_notify_admin( int $id, array $data ): void {
		if ( empty( $this->settings->get( 'notify_admin_on_submit', 1 ) ) ) {
			return;
		}

		$to = get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$site_name  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$post_id    = (int) ( $data['post_id'] ?? 0 );
		$post_title = $post_id ? get_the_title( $post_id ) : '';
		$status     = (string) ( $data['status'] ?? 'pending' );

		$author = (string) ( $data['author_name'] ?? '' );
		$email  = (string) ( $data['author_email'] ?? '' );
		$rating = (int) ( $data['rating'] ?? 0 );
		$title  = (string) ( $data['title'] ?? '' );
		$body   = wp_strip_all_tags( (string) ( $data['content'] ?? '' ) );

		$subject = sprintf(
			/* translators: 1: site name, 2: review status (pending/approved). */
			__( '[%1$s] New review submitted (%2$s)', 'boldreview' ),
			$site_name,
			$status
		);

		$lines = array();
		/* translators: %s: site name. */
		$lines[] = sprintf( __( 'A new review has been submitted on %s.', 'boldreview' ), $site_name );
		$lines[] = '';
		if ( '' !== $post_title ) {
			/* translators: %s: post title the review was left on. */
			$lines[] = sprintf( __( 'On: %s', 'boldreview' ), $post_title );
		}
		/* translators: %s: review author name. */
		$lines[] = sprintf( __( 'Author: %s', 'boldreview' ), $author );
		if ( '' !== $email ) {
			/* translators: %s: review author email address. */
			$lines[] = sprintf( __( 'Email: %s', 'boldreview' ), $email );
		}
		/* translators: %d: review rating out of 5. */
		$lines[] = sprintf( __( 'Rating: %d / 5', 'boldreview' ), $rating );
		/* translators: %s: review status (pending/approved). */
		$lines[] = sprintf( __( 'Status: %s', 'boldreview' ), $status );
		if ( '' !== $title ) {
			/* translators: %s: review title. */
			$lines[] = sprintf( __( 'Title: %s', 'boldreview' ), $title );
		}
		if ( '' !== $body ) {
			$lines[] = '';
			$lines[] = __( 'Review:', 'boldreview' );
			$lines[] = $body;
		}
		$lines[] = '';
		$lines[] = __( 'Moderate this review:', 'boldreview' );
		$lines[] = admin_url( 'admin.php?page=bdrvw-reviews' );

		$message = implode( "\n", $lines );

		/**
		 * Filter the admin notification email arguments before sending.
		 *
		 * @param array $args { to, subject, message, headers } — modifiable by site code.
		 * @param int   $id   Review id.
		 * @param array $data Review data.
		 */
		$args = apply_filters(
			'bdrvw_admin_notification_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => array(),
			),
			$id,
			$data
		);

		wp_mail(
			(string) ( $args['to'] ?? $to ),
			(string) ( $args['subject'] ?? $subject ),
			(string) ( $args['message'] ?? $message ),
			(array) ( $args['headers'] ?? array() )
		);
	}
}
