<?php
/**
 * Centralised sanitization helpers.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizer for settings and review payloads.
 */
class Bdrvw_Sanitizer {

	/**
	 * Sanitize a settings payload. If $only_keys is provided, ONLY those top-level
	 * keys are sanitized and returned. Useful for per-section saves so that keys
	 * not rendered in the current section aren't blown away.
	 *
	 * @param array<string,mixed> $data      Raw posted data.
	 * @param array<int,string>   $only_keys Restrict to these top-level keys.
	 * @return array<string,mixed>
	 */
	public static function settings( array $data, array $only_keys = array() ): array {
		$defaults = Bdrvw_Settings::defaults();
		$clean    = array();
		$allowed  = ! empty( $only_keys ) ? $only_keys : array_keys( $defaults );

		foreach ( $allowed as $key ) {
			switch ( $key ) {
				case 'enabled_post_types':
					$clean['enabled_post_types'] = isset( $data['enabled_post_types'] ) && is_array( $data['enabled_post_types'] )
						? array_values( array_filter( array_map( 'sanitize_key', $data['enabled_post_types'] ) ) )
						: array();
					break;

				case 'excluded_post_ids':
					$clean['excluded_post_ids'] = isset( $data['excluded_post_ids'] ) && is_array( $data['excluded_post_ids'] )
						? array_values( array_unique( array_filter( array_map( 'absint', $data['excluded_post_ids'] ) ) ) )
						: array();
					break;

				case 'auto_inject_position':
					$allowed_pos = array( 'after_content', 'before_content', 'disabled' );
					$pos         = isset( $data['auto_inject_position'] ) ? sanitize_key( (string) $data['auto_inject_position'] ) : '';
					$clean['auto_inject_position'] = in_array( $pos, $allowed_pos, true ) ? $pos : 'after_content';
					break;

				case 'notify_admin_on_submit':
					$clean['notify_admin_on_submit'] = ! empty( $data['notify_admin_on_submit'] ) ? 1 : 0;
					break;

				case 'max_reviews_per_user':
					$raw   = isset( $data['max_reviews_per_user'] ) ? (int) $data['max_reviews_per_user'] : 1;
					$clean['max_reviews_per_user'] = max( 0, min( 50, $raw ) );
					break;

				case 'review_limit_by':
					$by = isset( $data['review_limit_by'] ) ? sanitize_key( (string) $data['review_limit_by'] ) : '';
					$clean['review_limit_by'] = in_array( $by, array( 'email', 'ip_address' ), true ) ? $by : '';
					break;

				case 'email_whitelist':
				case 'blocked_ips':
					$clean[ $key ] = isset( $data[ $key ] ) ? sanitize_textarea_field( (string) $data[ $key ] ) : '';
					break;

				case 'require_login':
				case 'auto_approve':
				case 'enable_user_review':
				case 'enable_author_review':
				case 'rating_input_style_enabled':
				case 'form_criteria_enabled':
				case 'show_average':
				case 'show_count':
				case 'show_rating_summary':
				case 'show_author_average':
				case 'show_user_average':
				case 'form_product_header':
				case 'allow_photo_review':
				case 'wc_review_verified_label':
					$clean[ $key ] = ! empty( $data[ $key ] ) ? 1 : 0;
					break;

				case 'wc_review_who_can':
					$who = isset( $data['wc_review_who_can'] ) ? sanitize_key( (string) $data['wc_review_who_can'] ) : '';
					$clean['wc_review_who_can'] = in_array( $who, array( 'anyone', 'verified' ), true ) ? $who : 'anyone';
					break;

				case 'wc_review_order_status':
					$status         = isset( $data['wc_review_order_status'] ) ? sanitize_key( (string) $data['wc_review_order_status'] ) : '';
					$allowed_status = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled' );
					$clean['wc_review_order_status'] = in_array( $status, $allowed_status, true ) ? $status : 'completed';
					break;

				case 'blacklist':
					$bl_in       = isset( $data['blacklist'] ) && is_array( $data['blacklist'] ) ? $data['blacklist'] : array();
					$integration = isset( $bl_in['integration'] ) ? sanitize_key( (string) $bl_in['integration'] ) : 'comments';
					if ( ! in_array( $integration, array( 'boldreview', 'comments' ), true ) ) {
						$integration = 'comments';
					}
					$action = isset( $bl_in['action'] ) ? sanitize_key( (string) $bl_in['action'] ) : 'unapprove';
					if ( ! in_array( $action, array( 'unapprove', 'reject' ), true ) ) {
						$action = 'unapprove';
					}
					$clean['blacklist'] = array(
						'integration' => $integration,
						'action'      => $action,
						'words'       => isset( $bl_in['words'] ) ? sanitize_textarea_field( (string) $bl_in['words'] ) : '',
					);
					break;

				case 'comment_fields':
					$clean['comment_fields'] = array();
					foreach ( $defaults['comment_fields'] as $cf_key => $_unused ) {
						$clean['comment_fields'][ $cf_key ] = ! empty( $data['comment_fields'][ $cf_key ] ) ? 1 : 0;
					}
					break;

				case 'reviews_per_page':
					$clean['reviews_per_page'] = isset( $data['reviews_per_page'] )
						? max( 1, min( 100, (int) $data['reviews_per_page'] ) )
						: (int) $defaults['reviews_per_page'];
					break;

				case 'display_template':
					$allowed_tpl = array( 'classic', 'modern', 'minimal', 'comment' );
					$tpl         = isset( $data['display_template'] ) ? sanitize_key( (string) $data['display_template'] ) : '';
					$clean['display_template'] = in_array( $tpl, $allowed_tpl, true ) ? $tpl : 'classic';
					break;

				case 'display_layout':
					$allowed_lay = array( 'list', 'grid' );
					$layout      = isset( $data['display_layout'] ) ? sanitize_key( (string) $data['display_layout'] ) : '';
					$clean['display_layout'] = in_array( $layout, $allowed_lay, true ) ? $layout : 'list';
					break;

				case 'summary_style':
					$allowed_sum = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );
					$style       = isset( $data['summary_style'] ) ? sanitize_key( (string) $data['summary_style'] ) : '';
					$clean['summary_style'] = in_array( $style, $allowed_sum, true ) ? $style : 'bars';
					break;

				case 'rating_input_style':
					$allowed_input = array( 'stars', 'slider', 'bar', 'square', 'pill' );
					$inp           = isset( $data['rating_input_style'] ) ? sanitize_key( (string) $data['rating_input_style'] ) : '';
					$clean['rating_input_style'] = in_array( $inp, $allowed_input, true ) ? $inp : 'stars';
					break;

				case 'fields':
					$clean['fields'] = array();
					foreach ( $defaults['fields'] as $f_key => $_unused ) {
						$clean['fields'][ $f_key ] = ! empty( $data['fields'][ $f_key ] ) ? 1 : 0;
					}
					break;

				case 'required_fields':
					$clean['required_fields'] = array();
					foreach ( $defaults['required_fields'] as $f_key => $_unused ) {
						$clean['required_fields'][ $f_key ] = ! empty( $data['required_fields'][ $f_key ] ) ? 1 : 0;
					}
					break;

				case 'criteria':
					$criteria = array();
					/**
					 * Filter the maximum number of form criteria that can be saved.
					 * The free plugin caps at 3; BoldReview Pro raises this so its
					 * "Add more" button can persist additional criteria.
					 *
					 * @param int $max Maximum criteria count.
					 */
					$max_criteria = max( 1, (int) apply_filters( 'bdrvw_max_criteria', 3 ) );
					if ( isset( $data['criteria'] ) && is_array( $data['criteria'] ) ) {
						foreach ( $data['criteria'] as $row ) {
							if ( ! is_array( $row ) ) {
								continue;
							}
							$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
							if ( '' === $label ) {
								continue;
							}
							$key_raw = isset( $row['key'] ) && '' !== (string) $row['key'] ? (string) $row['key'] : $label;
							$key     = sanitize_key( $key_raw );
							if ( '' === $key ) {
								$key = 'crit_' . substr( md5( $label ), 0, 8 );
							}
							$criteria[] = array(
								'key'   => $key,
								'label' => $label,
							);
							if ( count( $criteria ) >= $max_criteria ) {
								break;
							}
						}
					}
					$clean['criteria'] = $criteria;
					break;

				case 'criteria_groups':
					$clean['criteria_groups'] = self::criteria_groups( $data['criteria_groups'] ?? array() );
					break;

				case 'strings':
					// Only return strings present in $data â others must be preserved by the caller.
					$clean['strings'] = array();
					if ( isset( $data['strings'] ) && is_array( $data['strings'] ) ) {
						foreach ( $data['strings'] as $sk => $sv ) {
							if ( ! is_string( $sk ) || ! isset( $defaults['strings'][ $sk ] ) ) {
								continue;
							}
							$value = sanitize_text_field( (string) $sv );
							$clean['strings'][ $sk ] = '' !== $value ? $value : $defaults['strings'][ $sk ];
						}
					}
					break;

				case 'brand_color':
					$clean['brand_color'] = isset( $data['brand_color'] )
						? self::hex_color( (string) $data['brand_color'], (string) $defaults['brand_color'] )
						: (string) $defaults['brand_color'];
					break;

				case 'star_color':
					$clean['star_color'] = isset( $data['star_color'] )
						? self::hex_color( (string) $data['star_color'], (string) $defaults['star_color'] )
						: (string) $defaults['star_color'];
					break;

				case 'google_reviews':
					$gr_in                  = (array) ( $data['google_reviews'] ?? array() );
					$clean['google_reviews'] = self::module_struct(
						$gr_in,
						(array) $defaults['google_reviews'],
						array(
							'place_id'           => 'text',
							'api_key'            => 'text',
							'limit'              => array( 'int', 1, 50 ),
							'min_rating'         => array( 'int', 1, 5 ),
							'columns'            => array( 'int', 1, 4 ),
							'show_avatar'        => 'bool',
							// Layout/template are validated below via the filterable
							// Bdrvw_Frontend::normalize_*() helpers so add-on-registered
							// values survive sanitisation.
							'layout'             => 'text',
							'template'           => 'text',
							'business_name'      => 'text',
							'business_address'   => 'text',
							'business_rating'    => array( 'float', 0, 5 ),
							'business_total'     => array( 'int', 0, 1000000 ),
							'business_url'       => 'url',
							'business_icon'      => 'url',
							'cache_ttl'          => array( 'int', 5, 43200 ), // minutes: 5 min â¦ 30 days.
							'is_demo'            => 'bool',
							'accumulate'         => 'bool',

							// Display tab.
							'hide_no_comment'    => 'bool',
							'hide_rating_text'   => 'bool',
							'show_reply'         => 'bool',
							'show_verified'      => 'bool',
							'show_arrows'        => 'bool',
							'show_reviewer_pic'  => 'bool',
							'show_platform_logo' => 'bool',
							'show_platform_stars'=> 'bool',
							'date_format'        => array( 'enum', array( 'relative', 'M j, Y', 'F j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'wp' ) ),
							'review_text'        => array( 'enum', array( 'truncate', 'full', 'scroll' ) ),
						)
					);
				
					if ( array_key_exists( 'business_types', $gr_in ) && is_array( $gr_in['business_types'] ) ) {
						$clean['google_reviews']['business_types'] = array_values(
							array_filter(
								array_map( 'sanitize_text_field', $gr_in['business_types'] )
							)
						);
					} else {
						unset( $clean['google_reviews']['business_types'] );
					}
					
					if ( array_key_exists( 'reviews_cache', $gr_in ) && is_array( $gr_in['reviews_cache'] ) ) {
						$clean['google_reviews']['reviews_cache'] = self::sanitize_review_cache( $gr_in['reviews_cache'] );
					} else {
						unset( $clean['google_reviews']['reviews_cache'] );
					}
					if ( array_key_exists( 'cached_at', $gr_in ) ) {
						$clean['google_reviews']['cached_at'] = max( 0, (int) $gr_in['cached_at'] );
					} else {
						unset( $clean['google_reviews']['cached_at'] );
					}
					if ( array_key_exists( 'last_refresh_attempt', $gr_in ) ) {
						$clean['google_reviews']['last_refresh_attempt'] = max( 0, (int) $gr_in['last_refresh_attempt'] );
					} else {
						unset( $clean['google_reviews']['last_refresh_attempt'] );
					}

					$fe = '\\BoldReview\\Plugin\\Modules\\GoogleReviews\\Bdrvw_Frontend';
					if ( isset( $clean['google_reviews']['template'] ) && class_exists( $fe ) ) {
						$clean['google_reviews']['template'] = $fe::normalize_template( (string) $clean['google_reviews']['template'] );
					}
					if ( isset( $clean['google_reviews']['layout'] ) && class_exists( $fe ) ) {
						$clean['google_reviews']['layout'] = $fe::normalize_layout( (string) $clean['google_reviews']['layout'] );
					}

					/**
					 * Sanitize add-on Google Reviews settings the free build doesn't
					 * know about (e.g. the Pro word filter's `blocked_words`). Add-ons
					 * read their raw value from $gr_in and add the cleaned key; when a
					 * key isn't posted they must leave it out so partial saves from
					 * other tabs don't wipe it.
					 *
					 * @param array<string,mixed> $clean_gr Cleaned google_reviews settings.
					 * @param array<string,mixed> $gr_in    Raw posted google_reviews settings.
					 */
					$clean['google_reviews'] = (array) apply_filters( 'bdrvw_gr_sanitize_settings', $clean['google_reviews'], $gr_in );
					break;
			}
		}

		/**
		 * Sanitize top-level settings keys the free build has no case for.
		 *
		 * An add-on that renders a control into one of the free plugin's tabs
		 * (see `bdrvw_cr_advanced_notification_template`) owns that key's
		 * sanitisation: it reads the raw value from $data and adds the cleaned
		 * one. A key that wasn't posted must be left out entirely, so saving a
		 * different tab doesn't wipe it.
		 *
		 * @param array<string,mixed> $clean   Cleaned settings.
		 * @param array<string,mixed> $data    Raw posted settings.
		 * @param array<int,string>   $allowed Top-level keys this save may touch.
		 */
		return (array) apply_filters( 'bdrvw_sanitize_settings', $clean, $data, $allowed );
	}

	/**
	 * Sanitize a module's flat associative settings array using a small schema.
	 *
	 * @param array<string,mixed>       $data     Posted values for this module.
	 * @param array<string,mixed>       $defaults Default values to fall back to.
	 * @param array<string,string|array> $schema  Field â type spec.
	 *                                            Type may be:
	 *                                              "text", "url", "bool",
	 *                                              ["int", min, max],
	 *                                              ["float", min, max],
	 *                                              ["enum", allowed[]]
	 * @return array<string,mixed>
	 */
	protected static function module_struct( array $data, array $defaults, array $schema ): array {
		$out = $defaults;
		foreach ( $schema as $field => $type ) {
			$default = $defaults[ $field ] ?? null;

			if ( is_array( $type ) ) {
				$kind = $type[0];
			} else {
				$kind = $type;
			}

			switch ( $kind ) {
				case 'text':
					$out[ $field ] = isset( $data[ $field ] ) ? sanitize_text_field( (string) $data[ $field ] ) : (string) $default;
					break;
				case 'url':
					$out[ $field ] = isset( $data[ $field ] ) ? esc_url_raw( (string) $data[ $field ] ) : (string) $default;
					break;
				case 'bool':
					$out[ $field ] = ! empty( $data[ $field ] ) ? 1 : 0;
					break;
				case 'int':
					$min          = isset( $type[1] ) ? (int) $type[1] : PHP_INT_MIN;
					$max          = isset( $type[2] ) ? (int) $type[2] : PHP_INT_MAX;
					$val          = isset( $data[ $field ] ) ? (int) $data[ $field ] : (int) $default;
					$out[ $field ] = max( $min, min( $max, $val ) );
					break;
				case 'float':
					$min          = isset( $type[1] ) ? (float) $type[1] : -INF;
					$max          = isset( $type[2] ) ? (float) $type[2] : INF;
					$val          = isset( $data[ $field ] ) ? (float) $data[ $field ] : (float) $default;
					$out[ $field ] = max( $min, min( $max, $val ) );
					break;
				case 'enum':
					$allowed       = isset( $type[1] ) && is_array( $type[1] ) ? $type[1] : array();
					$val           = isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
					$out[ $field ] = in_array( $val, $allowed, true ) ? $val : (string) $default;
					break;
			}
		}
		return $out;
	}

	/**
	 * Sanitize an incoming review submission payload.
	 *
	 * @param array<string,mixed> $data Raw $_POST data.
	 * @return array<string,mixed>
	 */
	public static function review_payload( array $data ): array {
		return array(
			'post_id'      => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
			'author_name'  => isset( $data['author_name'] ) ? sanitize_text_field( wp_unslash( (string) $data['author_name'] ) ) : '',
			'author_email' => isset( $data['author_email'] ) ? sanitize_email( wp_unslash( (string) $data['author_email'] ) ) : '',
			'author_url'   => isset( $data['author_url'] ) ? esc_url_raw( wp_unslash( (string) $data['author_url'] ) ) : '',
			'title'        => isset( $data['title'] ) ? sanitize_text_field( wp_unslash( (string) $data['title'] ) ) : '',
			'content'      => isset( $data['content'] ) ? wp_kses_post( wp_unslash( (string) $data['content'] ) ) : '',
			'rating'       => isset( $data['rating'] ) ? max( 0, min( 5, (int) $data['rating'] ) ) : 0,
			'criteria'     => isset( $data['criteria'] ) && is_array( $data['criteria'] )
				? array_map(
					static function ( $v ) {
						return max( 0, min( 5, (int) $v ) );
					},
					$data['criteria']
				)
				: array(),
		);
	}

	/**
	 * Sanitize the criteria_groups structure (accordion repeater payload).
	 *
	 * Each group:
	 *   - id          : kebab-case slug (auto-generated if missing).
	 *   - name        : human-readable label for the group.
	 *   - criteria    : list of { key, label } items.
	 *   - visibility  : { mode: 'all'|'post_types'|'specific',
	 *                     post_types: string[],
	 *                     post_ids: int[] }
	 *
	 * @param mixed $raw Posted payload.
	 * @return array<int,array<string,mixed>>
	 */
	public static function criteria_groups( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out         = array();
		$valid_modes = array( 'all', 'post_types', 'specific' );

		foreach ( $raw as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';

			// Criteria list inside this group.
			$criteria = array();
			if ( isset( $row['criteria'] ) && is_array( $row['criteria'] ) ) {
				foreach ( $row['criteria'] as $crow ) {
					if ( ! is_array( $crow ) ) {
						continue;
					}
					$label = isset( $crow['label'] ) ? sanitize_text_field( (string) $crow['label'] ) : '';
					if ( '' === $label ) {
						continue;
					}
					$rating = isset( $crow['rating'] ) ? (int) $crow['rating'] : 0;
					$rating = max( 0, min( 5, $rating ) );
					$key_raw = isset( $crow['key'] ) && '' !== (string) $crow['key'] ? (string) $crow['key'] : $label;
					$key     = sanitize_key( $key_raw );
					if ( '' === $key ) {
						$key = 'crit_' . substr( md5( $label ), 0, 8 );
					}
					$criteria[] = array(
						'key'    => $key,
						'label'  => $label,
						'rating' => $rating,
					);
				}
			}

			// A group with no name AND no criteria is useless â drop it.
			if ( '' === $name && empty( $criteria ) ) {
				continue;
			}

			
			$mode = isset( $row['visibility']['mode'] ) ? sanitize_key( (string) $row['visibility']['mode'] ) : 'specific';
			if ( ! in_array( $mode, $valid_modes, true ) ) {
				$mode = 'specific';
			}
			if ( 'post_types' === $mode ) {
				$mode = 'specific';
			}

			$post_types = array();
			$post_ids   = array();

			if ( 'specific' === $mode ) {
			
				if ( isset( $row['visibility']['selections'] ) && is_array( $row['visibility']['selections'] ) ) {
					foreach ( $row['visibility']['selections'] as $pt => $values ) {
						$pt = sanitize_key( (string) $pt );
						if ( '' === $pt || ! is_array( $values ) ) {
							continue;
						}
						foreach ( $values as $v ) {
							if ( '__all__' === (string) $v ) {
								$post_types[] = $pt;
							} else {
								$id = absint( $v );
								if ( $id > 0 ) {
									$post_ids[] = $id;
								}
							}
						}
					}
				}

				if ( isset( $row['visibility']['post_types'] ) && is_array( $row['visibility']['post_types'] ) ) {
					foreach ( $row['visibility']['post_types'] as $pt ) {
						$pt = sanitize_key( (string) $pt );
						if ( '' !== $pt ) {
							$post_types[] = $pt;
						}
					}
				}
				if ( isset( $row['visibility']['post_ids'] ) ) {
					$ids = is_array( $row['visibility']['post_ids'] )
						? $row['visibility']['post_ids']
						: preg_split( '/[,\s]+/', (string) $row['visibility']['post_ids'] );
					foreach ( (array) $ids as $id ) {
						$id = absint( $id );
						if ( $id > 0 ) {
							$post_ids[] = $id;
						}
					}
				}

				$post_types = array_values( array_unique( array_filter( $post_types ) ) );
				$post_ids   = array_values( array_unique( $post_ids ) );
			}

			$id_seed = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id_seed ) {
				$id_seed = sanitize_key( $name ) ?: ( 'group-' . ( (int) $idx + 1 ) );
			}

			$overview = array(
				'enabled'     => ! empty( $row['overview']['enabled'] ) ? 1 : 0,
				'heading'     => isset( $row['overview']['heading'] ) ? sanitize_text_field( (string) $row['overview']['heading'] ) : '',
				'description' => isset( $row['overview']['description'] ) ? sanitize_textarea_field( (string) $row['overview']['description'] ) : '',
			);

			$valid_summary_styles = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );
			$summary_style        = isset( $row['summary_style'] ) ? sanitize_key( (string) $row['summary_style'] ) : 'hbars';
			if ( ! in_array( $summary_style, $valid_summary_styles, true ) ) {
				$summary_style = 'hbars';
			}
			$show_average = ! empty( $row['show_average'] ) ? 1 : 0;

			$out[] = array(
				'id'            => $id_seed,
				'name'          => '' !== $name ? $name : __( 'Untitled group', 'boldreview' ),
				'criteria'      => $criteria,
				'visibility'    => array(
					'mode'       => $mode,
					'post_types' => $post_types,
					'post_ids'   => $post_ids,
				),
				'overview'      => $overview,
				'summary_style' => $summary_style,
				'show_average'  => $show_average,
			);
		}

		return $out;
	}

	/**
	 * Sanitize the cached Google reviews payload â a list of review rows
	 * returned by Places Details. We don't trust the upstream blindly even
	 * though it came from the AJAX `connect` handler: a stale option could
	 * be re-saved by another tab, so re-clean on every write.
	 *
	 * @param array<int,mixed> $rows Raw review rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_review_cache( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'author_name'   => isset( $row['author_name'] ) ? sanitize_text_field( (string) $row['author_name'] ) : '',
				'profile_url'   => isset( $row['profile_url'] ) ? esc_url_raw( (string) $row['profile_url'] ) : '',
				'review_url'    => isset( $row['review_url'] ) ? esc_url_raw( (string) $row['review_url'] ) : '',
				'avatar'        => isset( $row['avatar'] ) ? esc_url_raw( (string) $row['avatar'] ) : '',
				'rating'        => isset( $row['rating'] ) ? max( 0, min( 5, (float) $row['rating'] ) ) : 0,
				'relative_time' => isset( $row['relative_time'] ) ? sanitize_text_field( (string) $row['relative_time'] ) : '',
				'time'          => isset( $row['time'] ) ? max( 0, (int) $row['time'] ) : 0,
				'content'       => isset( $row['content'] ) ? wp_kses_post( (string) $row['content'] ) : '',
				'language'      => isset( $row['language'] ) ? sanitize_text_field( (string) $row['language'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Validate a hex colour, falling back to default.
	 */
	public static function hex_color( string $color, string $fallback ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
			return $color;
		}
		return $fallback;
	}
}
