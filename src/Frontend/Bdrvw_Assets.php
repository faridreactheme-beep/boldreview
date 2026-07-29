<?php
/**
 * Frontend asset registrar.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Frontend;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues frontend CSS/JS.
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets.
	 */
	public function enqueue(): void {
		
		$css_path = BDRVW_DIR . 'assets/css/frontend.css';
		$js_path  = BDRVW_DIR . 'assets/js/frontend.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : BDRVW_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : BDRVW_VERSION;

		wp_register_style(
			'bdrvw-frontend-css',
			BDRVW_URL . 'assets/css/frontend.css',
			array(),
			$css_ver
		);
		wp_register_script(
			'bdrvw-frontend-js',
			BDRVW_URL . 'assets/js/frontend.js',
			array(),
			$js_ver,
			true
		);

		wp_enqueue_style( 'bdrvw-frontend-css' );
		wp_enqueue_script( 'bdrvw-frontend-js' );

		wp_localize_script(
			'bdrvw-frontend-js',
			'BdrvwFront',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'bdrvw_submit' ),
				'photoMax'      => \BoldReview\Plugin\Core\Bdrvw_Photos::MAX_PHOTOS,
				'photoMaxBytes' => \BoldReview\Plugin\Core\Bdrvw_Photos::MAX_BYTES,
				'i18n'          => array(
					'submitting'           => __( 'Submitting…', 'boldreview' ),
					'errorGeneric'         => __( 'Something went wrong. Please try again.', 'boldreview' ),
					'success'              => $this->settings->get( 'strings.success_message', __( 'Thanks! Your review has been received.', 'boldreview' ) ),
					'fieldRequired'        => __( 'Please complete the required fields.', 'boldreview' ),
					'errorRateAtLeastOne'  => __( 'Please rate at least one criterion before submitting.', 'boldreview' ),
					'errorRatingRequired'  => __( 'Please choose a rating.', 'boldreview' ),
					/* translators: %d: number of files that were skipped. */
					'photosSkipped'        => __( '%d file(s) were skipped — too many, or too large.', 'boldreview' ),
					'photoRemove'          => __( 'Remove photo', 'boldreview' ),
				),
			)
		);

		$s   = $this->settings->all();
		$css = sprintf(
			':root{--bdrvw-brand:%s;--bdrvw-star:%s;}',
			sanitize_hex_color( $s['brand_color'] ),
			sanitize_hex_color( $s['star_color'] )
		);
		wp_add_inline_style( 'bdrvw-frontend-css', $css );
	}
}
