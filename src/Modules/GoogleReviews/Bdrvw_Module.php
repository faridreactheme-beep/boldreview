<?php
/**
 * Google Reviews module bootstrap.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Modules\GoogleReviews;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Google Reviews module's services (AJAX, frontend, settings renderer).
 *
 * The admin tab UI is rendered by SettingsRenderer (invoked from SettingsPage),
 * AJAX endpoints by Ajax, and frontend shortcode by Frontend.
 */
class Bdrvw_Module {

	/** Shared handle for the Swiper library (sidebar review slider). */
	const SWIPER_HANDLE = 'bdrvw-swiper';

	/** Swiper version pinned for the CDN assets. */
	const SWIPER_VERSION = '11.1.14';

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Google Places API client.
	 *
	 * @var Bdrvw_Client
	 */
	private $client;

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings ) {
		$this->settings = $settings;
		$this->client   = new Bdrvw_Client( $settings );
	}

	/**
	 * Register hooks for this module.
	 */
	public function register(): void {
		( new Bdrvw_Ajax( $this->settings, $this->client ) )->register();
		( new Bdrvw_Frontend( $this->settings ) )->register();

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'bdrvw_google_reviews_refresh', array( $this, 'cron_refresh' ) );

		if ( ! wp_next_scheduled( 'bdrvw_google_reviews_refresh' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bdrvw_google_reviews_refresh' );
		}
	}

	/**
	 * WP-Cron callback — runs hourly and re-fetches reviews only when the
	 * saved cache has exceeded the user's configured TTL. The Client itself
	 * is the gate; this just gives it a wakeup tick independent of frontend
	 * traffic so cold sites still get refreshed data.
	 */
	public function cron_refresh(): void {
		$this->client->refresh_if_stale();
	}

	/**
	 * Enqueue admin CSS/JS — restricted to BoldReview admin screens by hook prefix.
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, 'bdrvw' ) ) {
			return;
		}
		$css     = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/admin.css';
		$js      = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/admin.js';
		$fe_css  = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/frontend.css';
		$fe_js   = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/frontend.js';
		$cv      = file_exists( $css ) ? (string) filemtime( $css ) : BDRVW_VERSION;
		$jv      = file_exists( $js ) ? (string) filemtime( $js ) : BDRVW_VERSION;
		$fe_cv   = file_exists( $fe_css ) ? (string) filemtime( $fe_css ) : BDRVW_VERSION;
		$fe_jv   = file_exists( $fe_js ) ? (string) filemtime( $fe_js ) : BDRVW_VERSION;

		self::register_swiper();
		wp_enqueue_style( self::SWIPER_HANDLE );
		wp_enqueue_script( self::SWIPER_HANDLE );

		
		wp_enqueue_style(
			'bdrvw-google-reviews',
			BDRVW_URL . 'src/Modules/GoogleReviews/assets/frontend.css',
			array( self::SWIPER_HANDLE ),
			$fe_cv
		);
		wp_enqueue_script(
			'bdrvw-google-reviews',
			BDRVW_URL . 'src/Modules/GoogleReviews/assets/frontend.js',
			array( self::SWIPER_HANDLE ),
			$fe_jv,
			true
		);

		wp_enqueue_style(
			'bdrvw-google-reviews-admin',
			BDRVW_URL . 'src/Modules/GoogleReviews/assets/admin.css',
			array( 'bdrvw-admin', 'bdrvw-google-reviews' ),
			$cv
		);

		$deps    = array( 'jquery', 'bdrvw-admin' );
		$api_key = self::resolve_api_key( $this->settings );
		if ( '' !== $api_key ) {
			wp_enqueue_script(
				'bdrvw-google-maps-js',
				'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $api_key ) . '&libraries=places',
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external Google CDN script, versioned by Google.
				true
			);
			$deps[] = 'bdrvw-google-maps-js';
		}

		wp_enqueue_script(
			'bdrvw-google-reviews-admin',
			BDRVW_URL . 'src/Modules/GoogleReviews/assets/admin.js',
			$deps,
			$jv,
			true
		);

		wp_localize_script(
			'bdrvw-google-reviews-admin',
			'BdrvwGoogleReviews',
			array(
				'hasMapsJs' => '' !== $api_key,
			)
		);
	}

	/**
	 * Enqueue frontend CSS + JS for the Google Reviews shortcodes.
	 * Lazy: only loads on singular pages whose content uses any of the
	 * `[bdrvw_google*]` shortcodes (the generic one or a per-layout slug).
	 */
	public function enqueue_frontend_assets(): void {
		self::register_frontend_assets();

		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! $post ) {
			return;
		}
		$content    = (string) $post->post_content;
		$shortcodes = array_merge( array( Bdrvw_Frontend::SHORTCODE ), array_values( Bdrvw_Frontend::layout_shortcodes() ) );
		foreach ( $shortcodes as $sc ) {
			if ( has_shortcode( $content, $sc ) ) {
				self::ensure_frontend_assets();
				return;
			}
		}
	}

	/**
	 * Register (idempotently) the Google Reviews frontend CSS/JS and the Swiper
	 * dependency they rely on. Registering only — nothing is printed on the page
	 * until one of these handles is enqueued.
	 */
	public static function register_frontend_assets(): void {
		self::register_swiper();

		if ( ! wp_style_is( 'bdrvw-google-reviews', 'registered' ) ) {
			$css = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/frontend.css';
			$cv  = file_exists( $css ) ? (string) filemtime( $css ) : BDRVW_VERSION;
			wp_register_style(
				'bdrvw-google-reviews',
				BDRVW_URL . 'src/Modules/GoogleReviews/assets/frontend.css',
				array( self::SWIPER_HANDLE, 'dashicons' ),
				$cv
			);
		}
		if ( ! wp_script_is( 'bdrvw-google-reviews', 'registered' ) ) {
			$js = BDRVW_DIR . 'src/Modules/GoogleReviews/assets/frontend.js';
			$jv = file_exists( $js ) ? (string) filemtime( $js ) : BDRVW_VERSION;
			wp_register_script(
				'bdrvw-google-reviews',
				BDRVW_URL . 'src/Modules/GoogleReviews/assets/frontend.js',
				array( self::SWIPER_HANDLE ),
				$jv,
				true
			);
		}
	}

	/**
	 * Ensure the Google Reviews frontend assets are registered AND enqueued.
	 *
	 * Safe to call late — e.g. from the shortcode render callback, which is the
	 * only reliable moment for placements that live outside the main post
	 * content (Elementor and other page builders, sidebar widgets, block
	 * templates, FSE parts). Styles enqueued at this point are emitted by
	 * WordPress's late-styles pass in the footer, so the reviews are always
	 * styled no matter where the shortcode is placed.
	 */
	public static function ensure_frontend_assets(): void {
		if ( is_admin() ) {
			return;
		}
		self::register_frontend_assets();
		wp_enqueue_style( self::SWIPER_HANDLE );
		wp_enqueue_script( self::SWIPER_HANDLE );
		wp_enqueue_style( 'bdrvw-google-reviews' );
		wp_enqueue_script( 'bdrvw-google-reviews' );
	}

	/**
	 * Render the admin settings tab UI (called from Bdrvw_SettingsPage::module_google_reviews).
	 *
	 * @param array<string,mixed> $s Settings array.
	 */
	public function render_settings( array $s ): void {
		( new Bdrvw_SettingsRenderer( $this->settings ) )->render( $s );
	}

	/**
	 * Register the Swiper library (CSS + JS) from the bundled vendor copy.
	 * Idempotent — safe to call from both the admin and frontend enqueue paths.
	 * The sidebar review slider depends on this.
	 *
	 * Public so an add-on that registers a slider-style layout can reuse the exact
	 * same bundled Swiper (and handle) instead of shipping a second copy.
	 */
	public static function register_swiper(): void {
		
		$base = BDRVW_URL . 'src/Modules/GoogleReviews/assets/vendor/swiper/';
		if ( ! wp_style_is( self::SWIPER_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::SWIPER_HANDLE,
				$base . 'swiper-bundle.min.css',
				array(),
				self::SWIPER_VERSION
			);
		}
		if ( ! wp_script_is( self::SWIPER_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SWIPER_HANDLE,
				$base . 'swiper-bundle.min.js',
				array(),
				self::SWIPER_VERSION,
				true
			);
		}
	}

	/**
	 * Resolve the active API key. Looks at, in order:
	 *   1. BDRVW_GOOGLE_API_KEY constant (set by developer in wp-config.php).
	 *   2. Saved settings value (kept for back-compat with older installs).
	 *   3. Empty string → triggers demo mode in Client.
	 */
	public static function resolve_api_key( Bdrvw_Settings $settings ): string {
		if ( defined( 'BDRVW_GOOGLE_API_KEY' ) && '' !== BDRVW_GOOGLE_API_KEY ) {
			return (string) BDRVW_GOOGLE_API_KEY;
		}
		$gr = (array) $settings->get( 'google_reviews', array() );
		return (string) ( $gr['api_key'] ?? '' );
	}

	/**
	 * Top-level settings keys this module owns. Mirrored into Bdrvw_Modules::definitions().
	 *
	 * @return array<int,string>
	 */
	public static function section_keys(): array {
		return array( 'google_reviews' );
	}
}
