<?php
/**
 * Frontend shortcodes.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Frontend;

use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Core\Bdrvw_PostStyle;
use BoldReview\Plugin\Models\Bdrvw_Review;


defined( 'ABSPATH' ) || exit;

/**
 * Registers the [bold_reviews] and [bold_review_form] shortcodes.
 */
class Bdrvw_Shortcodes {

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Renderer.
	 *
	 * @var Bdrvw_ReviewRenderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings ) {
		$this->settings = $settings;
		$this->renderer = new Bdrvw_ReviewRenderer( $settings );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_shortcode( 'bold_reviews', array( $this, 'reviews' ) );
		add_shortcode( 'bold_review_form', array( $this, 'form' ) );

		add_filter( 'the_content', array( $this, 'maybe_append_to_content' ), 20 );
	}

	/**
	 * Append the reviews list + form to single-post content when the current
	 * post type is enabled and the post id is not excluded.
	 *
	 * @param string $content Original post content.
	 */
	public function maybe_append_to_content( $content ) {
		
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( is_admin() || is_feed() ) {
			return $content;
		}

		$position = (string) $this->settings->get( 'auto_inject_position', 'after_content' );
		if ( 'disabled' === $position ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return $content;
		}

		$post_type = (string) get_post_type( $post_id );

		
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) ) {
			return $content;
		}

		$enabled = (array) $this->settings->get( 'enabled_post_types', array() );
		if ( ! in_array( $post_type, $enabled, true ) ) {
			return $content;
		}

		$excluded = (array) $this->settings->get( 'excluded_post_ids', array() );
		if ( in_array( $post_id, array_map( 'intval', $excluded ), true ) ) {
			return $content;
		}

		if ( has_shortcode( $content, 'bold_review_form' ) || has_shortcode( $content, 'bold_reviews' ) ) {
			return $content;
		}

		$injected = $this->form( array( 'post_id' => $post_id ) );
		if ( 'before_content' === $position ) {
			return $injected . $content;
		}
		return $content . $injected;
	}

	/**
	 * Render reviews list.
	 *
	 * Layout + template are intentionally NOT shortcode attributes — they're
	 * always read from the admin Display settings so editors don't have to
	 * touch shortcode markup when switching styles.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function reviews( $atts ): string {
		$atts = shortcode_atts(
			array(
				'post_id'       => get_the_ID(),
				'limit'         => $this->settings->get( 'reviews_per_page', 10 ),
				'paged'         => 1,
				'summary_style' => '',
				'input_style'   => '',
			),
			(array) $atts,
			'bold_reviews'
		);

		$post_id  = absint( $atts['post_id'] );
		$layout   = sanitize_key( (string) $this->settings->get( 'display_layout', 'list' ) );
		$template = sanitize_key( (string) $this->settings->get( 'display_template', 'classic' ) );
		$limit    = max( 1, min( 100, (int) $atts['limit'] ) );
		$paged    = max( 1, (int) $atts['paged'] );

		if ( ! empty( $_GET['bdrvw_paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$paged = max( 1, (int) $_GET['bdrvw_paged'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$result    = Bdrvw_Review::query(
			array(
				'post_id'  => $post_id,
				'status'   => 'approved',
				'per_page' => $limit,
				'page'     => $paged,
			)
		);
		$aggregate = Bdrvw_Review::aggregate_for_post( $post_id );

		return $this->renderer->render_list( $result, $aggregate, $post_id, $layout, $template, $paged, $this->style_overrides( $atts, $post_id ) );
	}

	/**
	 * Parse a shortcode id list into a clean array of unique positive integers.
	 * Accepts commas and/or whitespace as separators, e.g. "12, 15 20".
	 *
	 * @param string $raw Raw `id` attribute value.
	 * @return array<int,int>
	 */
	private function parse_ids( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$parts = preg_split( '/[\s,]+/', trim( $raw ) );
		$ids   = array();
		foreach ( (array) $parts as $part ) {
			$id = (int) $part;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Build the style-override array. Precedence, highest first:
	 *   1. explicit shortcode attributes,
	 *   2. the post's own "Rating Style" override (meta box),
	 *   3. nothing — the renderer then falls back to the admin Display settings.
	 *
	 * @param array<string,mixed> $atts    Shortcode attributes.
	 * @param int                 $post_id Post the form/list renders for.
	 * @return array<string,string>
	 */
	private function style_overrides( array $atts, int $post_id ): array {
		
		$overrides = Bdrvw_PostStyle::overrides( $post_id );

		if ( ! empty( $atts['summary_style'] ) ) {
			$overrides['summary_style'] = sanitize_key( (string) $atts['summary_style'] );
		}
		if ( ! empty( $atts['input_style'] ) ) {
			$overrides['input_style'] = sanitize_key( (string) $atts['input_style'] );
		}
		return $overrides;
	}

	/**
	 * Render the existing reviews list (in the selected admin template) followed
	 * by the submission form — mirrors the WooCommerce review-tab flow.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function form( $atts ): string {
		$atts = shortcode_atts(
			array(
				'post_id'       => get_the_ID(),
				'post_type'     => '',
				'id'            => '',
				'limit'         => $this->settings->get( 'reviews_per_page', 10 ),
				'paged'         => 1,
				'summary_style' => '',
				'input_style'   => '',
			),
			(array) $atts,
			'bold_review_form'
		);

		
		$allowed_ids = $this->parse_ids( (string) $atts['id'] );
		if ( ! empty( $allowed_ids ) ) {
			$current_id = (int) get_the_ID();
			if ( $current_id <= 0 || ! in_array( $current_id, $allowed_ids, true ) ) {
				return '';
			}
		}

		$post_id = absint( $atts['post_id'] );
		if ( $post_id <= 0 ) {
			return '';
		}

		$override_type = sanitize_key( (string) $atts['post_type'] );
		if ( '' === $override_type ) {
			$post_type = get_post_type( $post_id );
			$enabled   = (array) $this->settings->get( 'enabled_post_types', array() );
			if ( $post_type && ! in_array( $post_type, $enabled, true ) ) {
				return '';
			}
		}

		$layout   = sanitize_key( (string) $this->settings->get( 'display_layout', 'list' ) );
		$template = sanitize_key( (string) $this->settings->get( 'display_template', 'classic' ) );
		$limit    = max( 1, min( 100, (int) $atts['limit'] ) );
		$paged    = max( 1, (int) $atts['paged'] );
		if ( ! empty( $_GET['bdrvw_paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$paged = max( 1, (int) $_GET['bdrvw_paged'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$result    = Bdrvw_Review::query(
			array(
				'post_id'  => $post_id,
				'status'   => 'approved',
				'per_page' => $limit,
				'page'     => $paged,
			)
		);

		$aggregate = Bdrvw_Review::aggregate_for_post( $post_id );

		$overrides = $this->style_overrides( $atts, $post_id );
		$list_html = $this->renderer->render_list( $result, $aggregate, $post_id, $layout, $template, $paged, $overrides );

		if ( $this->settings->get( 'require_login' ) && ! is_user_logged_in() ) {
			$form_html = $this->renderer->render_login_required();
		} else {
			$form_html = $this->renderer->render_form( $post_id, false, $overrides );
		}

		return $list_html . $form_html;
	}
}
