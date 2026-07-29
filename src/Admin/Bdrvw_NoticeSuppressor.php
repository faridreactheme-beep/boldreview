<?php
/**
 * Suppresses third-party admin notices on BoldReview screens.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Other plugins/themes hook into the core notice actions, which WordPress prints
 * at the top of the page wrap — right above the BoldReview app header. This
 * removes those callbacks on BoldReview admin pages so the UI stays clean.
 *
 * BoldReview's own "Settings saved" message is rendered inline (not through these
 * hooks), so it is unaffected.
 */
class Bdrvw_NoticeSuppressor {

	/**
	 * Core hooks WordPress uses to output admin notices.
	 *
	 * @var array<int,string>
	 */
	const NOTICE_HOOKS = array(
		'admin_notices',
		'all_admin_notices',
		'user_admin_notices',
		'network_admin_notices',
	);

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'in_admin_header', array( $this, 'maybe_suppress' ), PHP_INT_MAX );
	}

	/**
	 * Strip all admin-notice callbacks when on a BoldReview screen.
	 */
	public function maybe_suppress(): void {
		if ( ! $this->is_bdrvw_screen() ) {
			return;
		}

		foreach ( self::NOTICE_HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * Whether the current admin screen belongs to BoldReview.
	 */
	private function is_bdrvw_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		
		$screen = get_current_screen();
		return $screen && false !== strpos( (string) $screen->id, 'bdrvw' );
	}
}
