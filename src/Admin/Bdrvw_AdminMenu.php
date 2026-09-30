<?php
/**
 * Admin menu registrar.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Modules;
use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers BoldReview "Review Collection" top-level menu and subpages.
 */
class Bdrvw_AdminMenu {

	const CAPABILITY      = 'manage_options';
	const MENU_SLUG       = 'bdrvw';
	const REVIEWS_SLUG    = 'bdrvw-reviews';
	const SETTINGS_SLUG   = 'bdrvw-settings';
	const TOOLS_SLUG      = 'bdrvw-tools';

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
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_styles' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Fix the custom PNG menu icon size in the admin sidebar.
	 */
	public function menu_icon_styles(): void {
		?>
		<style id="bdrvw-menu-icon">
			#adminmenu #toplevel_page_<?php echo esc_attr( self::MENU_SLUG ); ?> .wp-menu-image img {
				width: 20px;
				height: 20px;
				padding: 7px 0;
				object-fit: contain;
				opacity: 0.6;
			}
			#adminmenu #toplevel_page_<?php echo esc_attr( self::MENU_SLUG ); ?>:hover .wp-menu-image img,
			#adminmenu #toplevel_page_<?php echo esc_attr( self::MENU_SLUG ); ?>.wp-has-current-submenu .wp-menu-image img,
			#adminmenu #toplevel_page_<?php echo esc_attr( self::MENU_SLUG ); ?>.current .wp-menu-image img {
				opacity: 1;
			}
		</style>
		<?php
	}

	/**
	 * Add menu / submenu pages.
	 */
	public function add_menus(): void {
		add_menu_page(
			__( 'BoldReview', 'boldreview' ),
			__( 'BoldReview', 'boldreview' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			BDRVW_URL . 'assets/image/boldmenu-icon.png',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'boldreview' ),
			__( 'Dashboard', 'boldreview' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		$reviews_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'All Reviews', 'boldreview' ),
			__( 'All Reviews', 'boldreview' ),
			self::CAPABILITY,
			self::REVIEWS_SLUG,
			array( $this, 'render_reviews_page' )
		);
		if ( $reviews_hook ) {
			add_action( "load-{$reviews_hook}", array( $this, 'register_reviews_screen_options' ) );
		}

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'boldreview' ),
			__( 'Settings', 'boldreview' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tools', 'boldreview' ),
			__( 'Tools', 'boldreview' ),
			self::CAPABILITY,
			self::TOOLS_SLUG,
			array( $this, 'render_tools_page' )
		);
	}

	/**
	 * Handle settings form submissions.
	 */
	public function maybe_save_settings(): void {
		if ( ! isset( $_POST['bdrvw_settings_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'boldreview' ) );
		}
		$nonce = isset( $_POST['bdrvw_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bdrvw_settings_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bdrvw_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'boldreview' ) );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted_settings = isset( $_POST['bdrvw_settings'] ) && is_array( $_POST['bdrvw_settings'] )
		? wp_unslash( $_POST['bdrvw_settings'] )
		: array();


		$module    = isset( $_POST['bdrvw_settings_module'] ) ? sanitize_key( wp_unslash( $_POST['bdrvw_settings_module'] ) ) : '';
		
		$tab       = isset( $_POST['bdrvw_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['bdrvw_settings_tab'] ) ) : '';
		$only_keys = Bdrvw_SettingsPage::module_keys( $module );

		$this->settings->save( $submitted_settings, $only_keys );

		set_transient( 'bdrvw_admin_notice_' . get_current_user_id(), 'saved', 60 );

		$redirect = add_query_arg(
			array_filter(
				array(
					'page'   => self::SETTINGS_SLUG,
					'module' => $module ?: null,
					'tab'    => $tab ?: null,
				)
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render dashboard (module grid) page.
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		( new Bdrvw_DashboardPage( $this->settings ) )->render();
	}

	/**
	 * Register the per-page screen option for the Reviews list. Hooked into
	 * `load-{reviews_hook}` so it only attaches on that admin page.
	 */
	public function register_reviews_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Reviews per page', 'boldreview' ),
				'default' => 20,
				'option'  => 'bdrvw_reviews_per_page',
			)
		);
	}

	/**
	 * Save the per-page screen option choice. Filter callback for `set-screen-option`.
	 *
	 * @param mixed  $status Current return value (false by default).
	 * @param string $option Option name being saved.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		if ( 'bdrvw_reviews_per_page' === $option ) {
			return (int) $value;
		}
		return $status;
	}

	/**
	 * Render reviews moderation page.
	 */
	public function render_reviews_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		( new Bdrvw_ReviewsPage( $this->settings ) )->render();
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		( new Bdrvw_SettingsPage( $this->settings ) )->render();
	}

	/**
	 * Render tools page (import/export + rollback).
	 */
	public function render_tools_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		( new Bdrvw_ToolsPage( $this->settings ) )->render();
	}
}