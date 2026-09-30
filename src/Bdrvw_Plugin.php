<?php
/**
 * Main plugin bootstrap.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin;

use BoldReview\Plugin\Admin\Bdrvw_AdminMenu;
use BoldReview\Plugin\Admin\Bdrvw_Assets as AdminAssets;
use BoldReview\Plugin\Admin\Bdrvw_CommentsScreen;
use BoldReview\Plugin\Admin\Bdrvw_NoticeSuppressor;
use BoldReview\Plugin\Core\Bdrvw_LegacyMigration;
use BoldReview\Plugin\Admin\Bdrvw_PostColumns;
use BoldReview\Plugin\Admin\Bdrvw_PostStyleMetaBox;
use BoldReview\Plugin\Admin\Bdrvw_ReviewPanel;
use BoldReview\Plugin\Admin\Bdrvw_ToolsPage;
use BoldReview\Plugin\Ajax\Bdrvw_Handler as AjaxHandler;
use BoldReview\Plugin\Core\Bdrvw_Captcha;
use BoldReview\Plugin\Core\Bdrvw_Notifier;
use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Frontend\Bdrvw_Assets as FrontendAssets;
use BoldReview\Plugin\Frontend\Bdrvw_Shortcodes;
use BoldReview\Plugin\Integrations\Bdrvw_WooCommerce;
use BoldReview\Plugin\Modules\GoogleReviews\Bdrvw_Module as GoogleReviewsModule;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container / service registrar.
 */
final class Bdrvw_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Bdrvw_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services keyed by id.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Get singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {}

	/**
	 * Boot all services.
	 */
	public function boot(): void {
		// Translations are loaded automatically by WordPress (just-in-time) since 4.6; no load_plugin_textdomain() needed.
		$settings = new Bdrvw_Settings();
		$this->services['settings'] = $settings;

		if ( is_admin() ) {
			$this->services['admin_menu']       = new Bdrvw_AdminMenu( $settings );
			$this->services['admin_assets']     = new AdminAssets( $settings );
			$this->services['notice_suppressor'] = new Bdrvw_NoticeSuppressor();
			$this->services['post_style_metabox'] = new Bdrvw_PostStyleMetaBox( $settings );
			
			$this->services['tools_page'] = new Bdrvw_ToolsPage( $settings );
			$this->services['tools_page']->register();

			$this->services['admin_menu']->register();
			$this->services['admin_assets']->register();
			$this->services['notice_suppressor']->register();
			$this->services['post_style_metabox']->register();

			$this->services['post_columns'] = new Bdrvw_PostColumns();
			$this->services['post_columns']->register();

			$this->services['legacy_migration'] = new Bdrvw_LegacyMigration();
			$this->services['legacy_migration']->register();

			add_action( 'bdrvw_review_edit_save_control', array( Bdrvw_ReviewPanel::class, 'render_save_control' ) );
		}

		$this->services['comments_screen'] = new Bdrvw_CommentsScreen();
		$this->services['comments_screen']->register();

		$this->services['frontend_assets'] = new FrontendAssets( $settings );
		$this->services['shortcodes']      = new Bdrvw_Shortcodes( $settings );
		$this->services['ajax']            = new AjaxHandler( $settings );
		$this->services['notifier']        = new Bdrvw_Notifier( $settings );
		// Renders on the frontend, verifies during admin-ajax — so it boots on
		// both sides, not inside the is_admin() block above.
		$this->services['captcha']         = new Bdrvw_Captcha( $settings );

		$this->services['frontend_assets']->register();
		$this->services['shortcodes']->register();
		$this->services['ajax']->register();
		$this->services['notifier']->register();
		$this->services['captcha']->register();

		$this->services['module_google_reviews'] = new GoogleReviewsModule( $settings );
		$this->services['module_google_reviews']->register();

		// WooCommerce bridge — active only when the `product` post type is enabled.
		$this->services['integration_woocommerce'] = new Bdrvw_WooCommerce( $settings, $this->services['shortcodes'] );
		$this->services['integration_woocommerce']->register();

		do_action( 'bdrvw_booted', $this );
	}

	/**
	 * Get a registered service.
	 *
	 * @param string $id Service id.
	 * @return object|null
	 */
	public function get( string $id ) {
		return $this->services[ $id ] ?? null;
	}
}
