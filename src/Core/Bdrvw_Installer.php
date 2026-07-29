<?php
/**
 * Installer / activation handler.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds default settings and schedules the plugin's cron.
 *
 * Reviews themselves need no table: they are stored as native comments (see
 * Bdrvw_Review), which is what lets a site's pre-existing WooCommerce product
 * reviews show up in BoldReview untouched.
 */
class Bdrvw_Installer {

	const DB_VERSION = '2.0.0';

	/**
	 * Run on activation.
	 */
	public function activate(): void {
		$this->seed_settings();
		update_option( 'bdrvw_db_version', self::DB_VERSION );
		if ( ! wp_next_scheduled( 'bdrvw_google_reviews_refresh' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bdrvw_google_reviews_refresh' );
		}
		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'bdrvw_google_reviews_refresh' );
		flush_rewrite_rules();
	}

	/**
	 * Seed default settings if not already present.
	 */
	protected function seed_settings(): void {
		if ( false !== get_option( 'bdrvw_settings' ) ) {
			return;
		}

		add_option( 'bdrvw_settings', Bdrvw_Settings::defaults() );
	}
}
