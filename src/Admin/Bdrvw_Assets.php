<?php
/**
 * Admin asset registrar.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin CSS/JS on BoldReview pages only.
 */
class Bdrvw_Assets {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on BoldReview screens only.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, 'bdrvw' ) ) {
			return;
		}

		$css_path = BDRVW_DIR . 'assets/css/admin.css';
		$js_path  = BDRVW_DIR . 'assets/js/admin.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : BDRVW_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : BDRVW_VERSION;

		$select2_base = BDRVW_URL . 'assets/vendor/select2/';
		if ( ! wp_style_is( 'select2', 'registered' ) ) {
			wp_register_style(
				'select2',
				$select2_base . 'select2.min.css',
				array(),
				'4.1.0-rc.0'
			);
		}
		if ( ! wp_script_is( 'select2', 'registered' ) ) {
			wp_register_script(
				'select2',
				$select2_base . 'select2.min.js',
				array( 'jquery' ),
				'4.1.0-rc.0',
				true
			);
		}
		
		wp_enqueue_style( 'select2' );
		wp_enqueue_script( 'select2' );

		wp_enqueue_style(
			'bdrvw-admin',
			BDRVW_URL . 'assets/css/admin.css',
			array( 'dashicons', 'select2' ),
			$css_ver
		);

		wp_enqueue_script(
			'bdrvw-admin',
			BDRVW_URL . 'assets/js/admin.js',
			array( 'jquery', 'select2' ),
			$js_ver,
			true
		);

		wp_localize_script(
			'bdrvw-admin',
			'BdrvwAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bdrvw_admin' ),
				'i18n'    => array(
					'confirmDelete'   => __( 'Delete this review permanently?', 'boldreview' ),
					'moduleEnabled'   => __( 'Module enabled', 'boldreview' ),
					'moduleDisabled'  => __( 'Module disabled', 'boldreview' ),
					'toggleFailed'    => __( 'Could not update module. Please try again.', 'boldreview' ),
					'statusOn'        => __( 'This module is active', 'boldreview' ),
					'statusOff'       => __( 'This module is disabled', 'boldreview' ),
					'statusOnHint'    => __( 'Edits below will affect what visitors see on your site.', 'boldreview' ),
					'statusOffHint'   => __( 'Enable it to apply these settings on the frontend.', 'boldreview' ),
					'saving'          => __( 'Saving…', 'boldreview' ),
					'saved'           => __( 'Settings saved successfully', 'boldreview' ),
					'saveFailed'      => __( 'Could not save settings. Please try again.', 'boldreview' ),
					'confirmReset'    => __( 'Reset this module to defaults? Unsaved changes will be lost.', 'boldreview' ),
					'resetting'       => __( 'Resetting…', 'boldreview' ),
					'resetDone'       => __( 'Module reset to defaults', 'boldreview' ),
					'resetFailed'     => __( 'Could not reset module. Please try again.', 'boldreview' ),
					'newGroup'        => __( 'New group', 'boldreview' ),
					'confirmRemoveGroup' => __( 'Remove this criteria group?', 'boldreview' ),
					'searchPlaceholder'  => __( 'Search…', 'boldreview' ),
					'noResults'          => __( 'No results found', 'boldreview' ),
					'searching'          => __( 'Searching…', 'boldreview' ),
					'loadMore'           => __( 'Loading more…', 'boldreview' ),
						'panelFailed'        => __( 'Could not load this review. Please try again.', 'boldreview' ),
					'photoRemove'        => __( 'Remove photo', 'boldreview' ),
					'saving'             => __( 'Saving…', 'boldreview' ),
					'reviewSaved'        => __( 'Review updated', 'boldreview' ),
					'reviewFailed'       => __( 'Could not save. Please try again.', 'boldreview' ),
					'replySent'          => __( 'Reply posted', 'boldreview' ),
					'replyFailed'        => __( 'Could not post the reply. Please try again.', 'boldreview' ),
					/* translators: %d: number of files that were skipped. */
					'photosSkipped'      => __( '%d file(s) were skipped — the review is at its photo limit.', 'boldreview' ),
				),
			)
		);

		$s = $this->settings->all();
		$css = sprintf(
			':root{--bdrvw-brand:%s;--bdrvw-star:%s;}',
			sanitize_hex_color( $s['brand_color'] ),
			sanitize_hex_color( $s['star_color'] )
		);
		wp_add_inline_style( 'bdrvw-admin', $css );
	}
}
