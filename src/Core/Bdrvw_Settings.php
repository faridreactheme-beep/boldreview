<?php
/**
 * Settings store. Wraps the options API.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised settings provider.
 */
class Bdrvw_Settings {

	const OPTION_KEY = 'bdrvw_settings';

	/**
	 * Cached settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled_post_types'     => array( 'post' ),
			'excluded_post_ids'      => array(),
			'auto_inject_position'   => 'after_content',
			'notify_admin_on_submit' => 1,
			'max_reviews_per_user'   => 1,
			'review_limit_by'        => '',
			'email_whitelist'        => '',
			'blocked_ips'            => '',
			'require_login'          => 0,
			'auto_approve'           => 0,
			'blacklist'              => array(
				'integration' => 'comments',  
				'action'      => 'unapprove',
				'words'       => '',          
			),
			'allow_photo_review'     => 0,
			'captcha'                => Bdrvw_Captcha::defaults(),
			'enable_user_review'     => 1,
			'enable_author_review'   => 0,
			'reviews_per_page'       => 10,
			'display_template'       => 'comment',
			'display_layout'         => 'list',
			'comment_fields'         => array(
				'avatar'   => 1,
				'stars'    => 1,
				'author'   => 1,
				'date'     => 1,
				'title'    => 1,
				'content'  => 1,
				'criteria' => 1,
				'url'      => 0,
				'email'    => 0,
			),
			'form_product_header'    => 0,
			'summary_style'          => 'bars',
			'rating_input_style'     => 'stars',
			'rating_input_style_enabled' => 1,
			'form_criteria_enabled'  => 1,
			'show_average'           => 1,
			'show_count'             => 1,
			'show_rating_summary'    => 1,
			'show_author_average'    => 1,
			'show_user_average'      => 0,
			'wc_review_who_can'       => 'anyone',    
			'wc_review_verified_label' => 1,         
			'wc_review_order_status'  => 'completed',
			'fields'                 => array(
				'author_name'  => 1,
				'author_email' => 1,
				'author_url'   => 0,
				'title'        => 1,
				'content'      => 1,
			),
			'required_fields'        => array(
				'author_name'  => 1,
				'author_email' => 1,
				'author_url'   => 0,
				'title'        => 0,
				'content'      => 1,
				'rating'       => 1,
			),
			'criteria'               => array(),
			'criteria_groups'        => array(),
			'brand_color'            => '#6c5ce7',
			'star_color'             => '#f5a623',
			'strings'                => array(
				'form_heading'      => 'Write a review',
				'submit_label'      => 'Submit review',
				'rating_label'      => 'Your rating',
				'name_label'        => 'Name',
				'email_label'       => 'Email',
				'url_label'         => 'Website',
				'title_label'       => 'Title',
				'summary_label'     => 'Your review',
				'success_message'   => 'Successfully submitted review',
				'login_required'    => 'Please log in to submit a review.',
				'no_reviews'        => 'No reviews yet.',
			),
			'google_reviews'         => array(
				'place_id'           => '',
				'api_key'            => '',
				'limit'              => 3,
				'min_rating'         => 4,
				'columns'            => 3, // cards per row (grid) on desktop.
				'show_avatar'        => 1,
				'layout'             => 'grid',
				'template'           => 'template_1',
				'business_name'      => '',
				'business_address'   => '',
				'business_rating'    => 0,
				'business_total'     => 0,
				'business_url'       => '',
				'business_icon'      => '',
				'business_types'     => array(),
				'reviews_cache'      => array(),
				'cached_at'          => 0,
				'cache_ttl'          => 10080, // minutes (7 days) — keeps API hits rare by default.
				'last_refresh_attempt' => 0,
				'is_demo'            => false,
				'accumulate'         => 0,
				'hide_no_comment'    => 0,
				'hide_rating_text'   => 0,
				'show_arrows'        => 1,
				'show_reviewer_pic'  => 1,
				'show_platform_logo' => 1,
				'show_platform_stars'=> 1,
				'date_format'        => 'relative',
				'review_text'        => 'truncate',
			),
		);
	}

	/**
	 * Get all settings (deep-merged with defaults).
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored      = get_option( self::OPTION_KEY, array() );
		$merged      = self::deep_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		$this->cache = $merged;

		return $merged;
	}

	/**
	 * Deep merge: $overrides wins for scalar values, but missing keys in nested arrays fall back to $defaults.
	 *
	 * @param array<string,mixed> $defaults  Defaults.
	 * @param array<string,mixed> $overrides Stored overrides.
	 * @return array<string,mixed>
	 */
	protected static function deep_merge( array $defaults, array $overrides ): array {
		$out = $defaults;
		foreach ( $overrides as $k => $v ) {
			if ( isset( $defaults[ $k ] ) && is_array( $defaults[ $k ] ) && is_array( $v ) ) {
				// Indexed arrays (criteria, enabled_post_types) replace wholesale; associative arrays deep-merge.
				$is_assoc = array_keys( $defaults[ $k ] ) !== range( 0, count( $defaults[ $k ] ) - 1 );
				$out[ $k ] = $is_assoc ? self::deep_merge( $defaults[ $k ], $v ) : $v;
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Get a setting by key (supports dot notation, one level deep).
	 *
	 * @param string $key     Dot path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		if ( false === strpos( $key, '.' ) ) {
			return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		}

		[ $top, $sub ] = explode( '.', $key, 2 );
		if ( ! isset( $all[ $top ] ) || ! is_array( $all[ $top ] ) ) {
			return $default;
		}
		return array_key_exists( $sub, $all[ $top ] ) ? $all[ $top ][ $sub ] : $default;
	}

	/**
	 * Persist a settings payload, merging into the current stored value.
	 * If $only_keys is provided, only those top-level keys are updated.
	 *
	 * @param array<string,mixed> $data      Raw posted data.
	 * @param array<int,string>   $only_keys Restrict to these top-level keys.
	 * @return array<string,mixed> Final stored array.
	 */
	public function save( array $data, array $only_keys = array() ): array {
		$current = $this->all();
		$cleaned = Bdrvw_Sanitizer::settings( $data, $only_keys );

		$merged = $current;
		foreach ( $cleaned as $k => $v ) {
			if ( 'strings' === $k && is_array( $v ) ) {
				
				$merged['strings'] = array_replace( (array) ( $current['strings'] ?? array() ), $v );
			} elseif ( 'google_reviews' === $k && is_array( $v ) ) {
				
				$merged['google_reviews'] = array_replace( (array) ( $current['google_reviews'] ?? array() ), $v );
			} else {
				$merged[ $k ] = $v;
			}
		}

		update_option( self::OPTION_KEY, $merged );
		$this->cache = $merged;
		return $merged;
	}

	/**
	 * Reset to defaults.
	 */
	public function reset(): array {
		$defaults = self::defaults();
		update_option( self::OPTION_KEY, $defaults );
		$this->cache = $defaults;
		return $defaults;
	}

	/**
	 * Reset only the given top-level keys to defaults, leaving other keys intact.
	 *
	 * @param array<int,string> $only_keys Top-level keys to revert.
	 * @return array<string,mixed> Full settings array after reset.
	 */
	public function reset_keys( array $only_keys ): array {
		$defaults = self::defaults();
		$current  = $this->all();
		foreach ( $only_keys as $key ) {
			if ( array_key_exists( $key, $defaults ) ) {
				$current[ $key ] = $defaults[ $key ];
			}
		}
		update_option( self::OPTION_KEY, $current );
		$this->cache = $current;
		return $current;
	}
}
