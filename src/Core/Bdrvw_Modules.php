<?php
/**
 * Module registry. Defines every BoldReview "Review Collection" module
 * (Google Reviews, Collection Review).
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Modular dashboard registry.
 */
class Bdrvw_Modules {

	const OPTION_KEY = 'bdrvw_active_modules';

	/**
	 * Module definitions. Keyed by slug.
	 *
	 * Each entry:
	 *   - label        : Display name.
	 *   - tagline      : One-liner for the dashboard card.
	 *   - description  : Longer description for the settings header.
	 *   - icon         : Dashicons slug (no "dashicons-" prefix).
	 *   - color        : Card accent colour (pastel bg derived in CSS).
	 *   - default      : Whether this module is enabled by default.
	 *   - section_keys : Top-level settings keys this module is allowed to save.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$definitions = array(
			'collection_review' => array(
				'label'        => __( 'Collection Review', 'boldreview' ),
				'tagline'      => __( 'Collect first-party reviews on your own site and control how they appear on the frontend.', 'boldreview' ),
				'description'  => __( 'Configure the review form, fields, criteria and frontend rating display in one place.', 'boldreview' ),
				'icon'         => 'edit',
				'color'        => 'purple',
				'default'      => true,
				'section_keys' => array(
					'enabled_post_types', 'excluded_post_ids', 'auto_inject_position', 'notify_admin_on_submit', 'max_reviews_per_user', 'require_login', 'auto_approve', 'blacklist',
					'enable_user_review', 'enable_author_review', 'reviews_per_page', 'allow_photo_review',
					'fields', 'required_fields', 'criteria', 'criteria_groups', 'strings',
					'display_layout', 'display_template', 'summary_style', 'rating_input_style',
					'rating_input_style_enabled', 'form_criteria_enabled',
					'comment_fields', 'form_product_header',
					'show_average', 'show_count', 'show_rating_summary',
					'show_author_average', 'show_user_average',
					'wc_review_who_can', 'wc_review_verified_label', 'wc_review_order_status',
					'review_limit_by', 'email_whitelist', 'blocked_ips',
					'recaptcha',
					'notification_template', 'allow_video_review', 'notifications',
				),
			),
			'google_reviews' => array(
				'label'        => __( 'Google Reviews', 'boldreview' ),
				'tagline'      => __( 'Pull verified reviews from your Google Business Profile and showcase social proof.', 'boldreview' ),
				'description'  => __( 'Connect a Google Place ID to import star ratings and snippets directly from Google.', 'boldreview' ),
				'icon'         => 'google',
				'color'        => 'pink',
				'default'      => true,
				'section_keys' => array( 'google_reviews' ),
			),
		);

		/**
		 * Filter the module definitions.
		 *
		 * Add-ons that put their own controls in an existing module's tabs use
		 * this to append the settings keys those controls save under — a key not
		 * listed in `section_keys` is dropped when the module's form is saved.
		 *
		 * @param array<string,array<string,mixed>> $definitions Module definitions keyed by slug.
		 */
		return (array) apply_filters( 'bdrvw_module_definitions', $definitions );
	}

	/**
	 * Default active set: all modules with default = true.
	 *
	 * @return array<int,string>
	 */
	public static function default_active(): array {
		$out = array();
		foreach ( self::definitions() as $slug => $def ) {
			if ( ! empty( $def['default'] ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Get all module slugs currently active.
	 *
	 * @return array<int,string>
	 */
	public static function active(): array {
		$stored = get_option( self::OPTION_KEY, null );
		if ( null === $stored ) {
			return self::default_active();
		}
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$defs = self::definitions();
		return array_values( array_filter( $stored, static function ( $slug ) use ( $defs ) {
			return isset( $defs[ $slug ] );
		} ) );
	}

	/**
	 * Is a module active?
	 */
	public static function is_active( string $slug ): bool {
		return in_array( $slug, self::active(), true );
	}

	/**
	 * Toggle module on/off and persist. Returns the new active state (bool).
	 */
	public static function set_active( string $slug, bool $active ): bool {
		$defs = self::definitions();
		if ( ! isset( $defs[ $slug ] ) ) {
			return false;
		}
		$current = self::active();
		if ( $active ) {
			if ( ! in_array( $slug, $current, true ) ) {
				$current[] = $slug;
			}
		} else {
			$current = array_values( array_diff( $current, array( $slug ) ) );
		}
		update_option( self::OPTION_KEY, $current );
		return $active;
	}

	/**
	 * Convenience: get a module definition (or null if it doesn't exist).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$defs = self::definitions();
		return $defs[ $slug ] ?? null;
	}
}
