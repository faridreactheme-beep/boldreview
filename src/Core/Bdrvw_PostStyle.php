<?php
/**
 * Per-post rating style override.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shared meta keys + resolver for the per-post "Rating Style" override, so the
 * admin meta box and the frontend renderer agree on one source of truth.
 *
 * When a post enables the override, its chosen summary / input style wins over
 * the global Display settings for that post only. Left off, the post keeps using
 * the global style.
 */
class Bdrvw_PostStyle {

	/**
	 * Meta key: whether this post overrides the global rating style (1/0).
	 */
	const META_ENABLED = '_bdrvw_style_override';

	/**
	 * Meta key: chosen rating-summary style for this post.
	 */
	const META_SUMMARY = '_bdrvw_summary_style';

	/**
	 * Meta key: chosen rating-input style for this post.
	 */
	const META_INPUT = '_bdrvw_input_style';

	/**
	 * Valid rating-summary style keys (mirror the plugin's Display settings).
	 *
	 * @var string[]
	 */
	const SUMMARY_STYLES = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );

	/**
	 * Valid rating-input style keys.
	 *
	 * @var string[]
	 */
	const INPUT_STYLES = array( 'stars', 'slider', 'bar', 'square', 'pill' );

	/**
	 * Build the style-override array for a post from its meta, or an empty array
	 * when the post doesn't override. Shaped to feed straight into the renderer's
	 * style-override handling (same keys as the shortcode attributes).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string> e.g. array( 'summary_style' => 'pie', 'input_style' => 'pill' ).
	 */
	public static function overrides( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		if ( ! (int) get_post_meta( $post_id, self::META_ENABLED, true ) ) {
			return array();
		}

		$out     = array();
		$summary = (string) get_post_meta( $post_id, self::META_SUMMARY, true );
		if ( in_array( $summary, self::SUMMARY_STYLES, true ) ) {
			$out['summary_style'] = $summary;
		}
		$input = (string) get_post_meta( $post_id, self::META_INPUT, true );
		if ( in_array( $input, self::INPUT_STYLES, true ) ) {
			$out['input_style'] = $input;
		}

		return $out;
	}
}
