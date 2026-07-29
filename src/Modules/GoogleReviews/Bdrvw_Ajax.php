<?php
/**
 * Google Reviews AJAX endpoints (search + connect).
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Modules\GoogleReviews;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers for the admin tab: live search and "Connect" button.
 */
class Bdrvw_Ajax {

	const CAPABILITY = 'manage_options';

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Bdrvw_Client
	 */
	private $client;

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings, Bdrvw_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_bdrvw_google_search', array( $this, 'search' ) );
		add_action( 'wp_ajax_bdrvw_google_connect', array( $this, 'connect' ) );
		add_action( 'wp_ajax_bdrvw_google_disconnect', array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_bdrvw_google_preview', array( $this, 'preview' ) );
	}

	/**
	 * Validate capability + nonce. Replies with JSON error and exits on failure.
	 */
	protected function guard(): void {
		
		if (
			! isset( $_REQUEST['_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_REQUEST['_nonce'] ) ),
				'bdrvw_admin'
			)
		) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'boldreview' ),
				),
				400
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'boldreview' ) ), 403 );
		}
	}

	/**
	 * Honor an API key typed into the Connect form but not yet saved. The client
	 * only uses it when no saved/constant key exists, so this never overrides a
	 * configured key — it just lets a brand-new key work on the first connect
	 * without a Save + reload.
	 */
	protected function apply_key_override(): void {
		$key = isset( $_REQUEST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in guard().
		if ( '' !== $key ) {
			$this->client->use_api_key( $key );
		}
	}

	/**
	 * Live autocomplete search: type business name / place_id / Maps URL.
	 */
	public function search(): void {
		$this->guard();
		$this->apply_key_override();
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in guard().
		if ( '' === $q ) {
			wp_send_json_success( array( 'items' => array(), 'demo' => ! $this->client->has_api_key() ) );
		}
		$items = $this->client->search( $q );
		wp_send_json_success(
			array(
				'items' => $items,
				'demo'  => ! $this->client->has_api_key(),
			)
		);
	}

	/**
	 * Connect a place_id — fetch details + reviews and persist them so the
	 * preview pane and the frontend shortcode have data ready.
	 */
	public function connect(): void {
		$this->guard();
		$this->apply_key_override();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		$place_id   = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['place_id'] ) ) : '';
		$sel_name   = isset( $_POST['name'] )     ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) )     : '';
		$sel_addr   = isset( $_POST['address'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['address'] ) )  : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $place_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing place id.', 'boldreview' ) ), 400 );
		}

		$details = $this->client->details( $place_id );
		if ( ! is_array( $details ) ) {
			$err = $this->client->last_error();
			wp_send_json_error(
				array(
					'message' => '' !== $err
						? sprintf( /* translators: %s: Google API error message. */ __( 'Google API error: %s', 'boldreview' ), $err )
						: __( 'Could not fetch place details. Check that your API key is valid and the Places API is enabled.', 'boldreview' ),
				),
				502
			);
		}

		
		$is_demo_place = 0 === strpos( $place_id, 'demo-' ) || ! $this->client->has_api_key();
		if ( $is_demo_place ) {
			if ( '' !== $sel_name ) {
				$details['name'] = $sel_name;
			}
			if ( '' !== $sel_addr ) {
				$details['address'] = $sel_addr;
			}
		}

		$gr                      = (array) $this->settings->get( 'google_reviews', array() );
		$gr['place_id']          = (string) $details['place_id'];
		$gr['business_name']     = (string) $details['name'];
		$gr['business_address']  = (string) $details['address'];
		$gr['business_rating']   = (float) $details['rating'];
		$gr['business_total']    = (int) $details['total'];
		$gr['business_url']      = (string) $details['url'];
		$gr['business_icon']     = (string) ( $details['icon'] ?? '' );
		$gr['business_types']    = (array) ( $details['types'] ?? array() );
		$gr['reviews_cache']     = (array) $details['reviews'];
		$gr['cached_at']         = time();
		$gr['is_demo']           = ! $this->client->has_api_key() || 0 === strpos( $place_id, 'demo-' );

		$posted_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
		if ( '' !== $posted_key && empty( $gr['is_demo'] ) && '' === (string) ( $gr['api_key'] ?? '' ) ) {
			$gr['api_key'] = $posted_key;
		}

		$this->settings->save( array( 'google_reviews' => $gr ), array( 'google_reviews' ) );

		wp_send_json_success(
			array(
				'message'  => __( 'Connected to Google Business successfully.', 'boldreview' ),
				'business' => array(
					'name'    => $gr['business_name'],
					'address' => $gr['business_address'],
					'rating'  => $gr['business_rating'],
					'total'   => $gr['business_total'],
					'url'     => $gr['business_url'],
					'icon'    => $gr['business_icon'],
					'types'   => $gr['business_types'],
				),
				'cards'    => $this->preview_cards_payload(),
			)
		);
	}

	/**
	 * Clear the connected place + cached reviews so the preview reverts to
	 * its empty/demo state.
	 */
	public function disconnect(): void {
		$this->guard();
		$gr                     = (array) $this->settings->get( 'google_reviews', array() );
		$gr['place_id']         = '';
		$gr['business_name']    = '';
		$gr['business_address'] = '';
		$gr['business_rating']  = 0;
		$gr['business_total']   = 0;
		$gr['business_url']     = '';
		$gr['reviews_cache']    = array();
		$gr['cached_at']        = 0;
		$gr['is_demo']          = false;
		$this->settings->save( array( 'google_reviews' => $gr ), array( 'google_reviews' ) );
		wp_send_json_success( array( 'cards' => $this->preview_cards_payload() ) );
	}

	/**
	 * Return the preview card markup for a given layout + template combo.
	 * Used by the live preview pane when the admin switches layouts/templates.
	 */
	public function preview(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified in guard().
		$layout   = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( (string) $_GET['layout'] ) ) : 'grid';
		$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( (string) $_GET['template'] ) ) : 'template_1';
		$columns  = isset( $_GET['columns'] ) ? (int) $_GET['columns'] : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_send_json_success(
			array(
				'html' => Bdrvw_Frontend::render_preview( $this->settings, $layout, $template, $columns ),
			)
		);
	}

	/**
	 * Pre-rendered preview cards for every (layout, template) combo. The admin
	 * JS keys into this map on radio change to avoid an AJAX round-trip.
	 *
	 * @return array<string,string>
	 */
	protected function preview_cards_payload(): array {
		$out     = array();
		$layouts = array_keys( Bdrvw_SettingsRenderer::layouts() );
		foreach ( $layouts as $layout ) {
			foreach ( Bdrvw_SettingsRenderer::templates_for_layout( $layout ) as $tpl ) {
				$out[ $layout . '__' . $tpl ] = Bdrvw_Frontend::render_preview( $this->settings, $layout, $tpl );
			}
		}
		return $out;
	}
}
