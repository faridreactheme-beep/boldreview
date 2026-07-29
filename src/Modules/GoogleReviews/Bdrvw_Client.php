<?php
/**
 * Google Places API client.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Modules\GoogleReviews;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around Google Places API (Autocomplete + Place Details).
 *
 * Falls back to a small demo dataset when no API key is configured so the
 * search/connect UI keeps working for evaluation.
 */
class Bdrvw_Client {

	const PLACES_AUTOCOMPLETE = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
	const PLACE_DETAILS       = 'https://maps.googleapis.com/maps/api/place/details/json';
	const PLACE_PHOTO         = 'https://maps.googleapis.com/maps/api/place/photo';

	const PLACES_SEARCH_NEW   = 'https://places.googleapis.com/v1/places:searchText';
	const PLACE_DETAILS_NEW   = 'https://places.googleapis.com/v1/places/';
	const PLACE_MEDIA_NEW     = 'https://places.googleapis.com/v1/';

	/**
	 * Upper bound on how many reviews we keep when "accumulate" is on, so the
	 * option row can't grow without limit as Google rotates its returned set.
	 */
	const MAX_STORED_REVIEWS = 50;

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Last error from a live API call — surfaced by AJAX so admins see why
	 * connecting failed (key missing, billing off, referrer blocked, etc.).
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Runtime API-key override. Lets the admin's Connect form use a key that has
	 * been typed but not yet saved (passed through the search/connect AJAX), so
	 * connecting works on the first try without a Save + reload.
	 *
	 * @var string
	 */
	private $key_override = '';

	/** Last API error from a live call, or empty string. */
	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Supply an API key for this request only (e.g. an unsaved key from the
	 * Connect form). A saved/constant key always wins; the override is used only
	 * when none is configured yet.
	 */
	public function use_api_key( string $key ): void {
		$this->key_override = trim( $key );
	}

	/**
	 * Resolve the effective API key: a saved or wp-config constant key wins;
	 * otherwise fall back to the runtime override from the Connect form.
	 */
	protected function api_key(): string {
		$resolved = Bdrvw_Module::resolve_api_key( $this->settings );
		return '' !== $resolved ? $resolved : $this->key_override;
	}

	/**
	 * Whether a real API key is configured. UI uses this to decide between
	 * live search and the demo dataset.
	 */
	public function has_api_key(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * Re-fetch reviews from Google when the saved cache is older than the
	 * configured TTL. Called by the frontend before rendering so visitors get
	 * fresh data without the admin having to click "Pull reviews" manually,
	 * and by WP-Cron in the background.
	 *
	 * Guarded against runaway calls by `last_refresh_attempt` — even if the
	 * upstream call fails, we won't retry more than once per 5 minutes so a
	 * misconfigured key (or Places API outage) can't hammer Google.
	 *
	 * @param bool $force Skip the TTL check and refresh immediately.
	 * @return bool True when a successful refresh updated the cache.
	 */
	public function refresh_if_stale( bool $force = false ): bool {
		$gr       = (array) $this->settings->get( 'google_reviews', array() );
		$place_id = (string) ( $gr['place_id'] ?? '' );

		if ( '' === $place_id || 0 === strpos( $place_id, 'demo-' ) ) {
			return false;
		}
		if ( ! $this->has_api_key() ) {
			return false;
		}

		$ttl_minutes = max( 5, (int) ( $gr['cache_ttl'] ?? 10080 ) );
		$cached_at   = (int) ( $gr['cached_at'] ?? 0 );
		$now         = time();

		if ( ! $force && $cached_at > 0 && ( $now - $cached_at ) < ( $ttl_minutes * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		$last_attempt = (int) ( $gr['last_refresh_attempt'] ?? 0 );
		if ( ! $force && $last_attempt > 0 && ( $now - $last_attempt ) < ( 5 * MINUTE_IN_SECONDS ) ) {
			return false;
		}

		$gr['last_refresh_attempt'] = $now;
		$this->settings->save( array( 'google_reviews' => $gr ), array( 'google_reviews' ) );

		$details = $this->details( $place_id );
		if ( ! is_array( $details ) ) {
			return false;
		}

		$fresh_reviews = (array) $details['reviews'];
		if ( ! empty( $gr['accumulate'] ) ) {
			$fresh_reviews = self::merge_reviews( (array) ( $gr['reviews_cache'] ?? array() ), $fresh_reviews );
		}

		$gr['business_name']    = (string) $details['name'];
		$gr['business_address'] = (string) $details['address'];
		$gr['business_rating']  = (float) $details['rating'];
		$gr['business_total']   = (int) $details['total'];
		$gr['business_url']     = (string) $details['url'];
		$gr['business_icon']    = (string) ( $details['icon'] ?? '' );
		$gr['business_types']   = (array) ( $details['types'] ?? array() );
		$gr['reviews_cache']    = $fresh_reviews;
		$gr['cached_at']        = $now;
		$gr['is_demo']          = false;
		$this->settings->save( array( 'google_reviews' => $gr ), array( 'google_reviews' ) );
		return true;
	}

	/**
	 * Merge freshly-fetched reviews into the existing cache, de-duplicating by a
	 * stable per-review key so reviews that drop out of Google's rotating set of
	 * 5 are preserved. Fresh copies win on collision (so relative-time strings
	 * stay current), the result is sorted newest-first, and the list is capped at
	 * MAX_STORED_REVIEWS.
	 *
	 * @param array<int,mixed> $existing Currently cached review rows.
	 * @param array<int,mixed> $fresh    Newly fetched review rows.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function merge_reviews( array $existing, array $fresh ): array {
		$by_key = array();
		
		foreach ( array_merge( array_values( $existing ), array_values( $fresh ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$by_key[ self::review_key( $row ) ] = $row;
		}

		$merged = array_values( $by_key );
		usort(
			$merged,
			static function ( $a, $b ) {
				return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
			}
		);

		return array_slice( $merged, 0, self::MAX_STORED_REVIEWS );
	}

	/**
	 * Stable identity for a review row. Google doesn't expose a review id via the
	 * Places API, so we key on author + post time (unique in practice), falling
	 * back to a content hash when the timestamp is missing.
	 *
	 * @param array<string,mixed> $row Review row.
	 */
	protected static function review_key( array $row ): string {
		$author = (string) ( $row['author_name'] ?? '' );
		$time   = (int) ( $row['time'] ?? 0 );
		if ( $time > 0 ) {
			return $author . '|' . $time;
		}
		return $author . '|' . md5( (string) ( $row['content'] ?? '' ) );
	}

	/**
	 * Run an autocomplete search against Places API. Returns a list of
	 * suggestions ready for the admin UI: [{ place_id, name, address }].
	 *
	 * If a query looks like a place_id or a Google Maps URL, we short-circuit
	 * and return a single synthetic suggestion with the extracted id — that
	 * lets admins paste a URL/id directly without needing an API key.
	 *
	 * @return array<int,array{place_id:string,name:string,address:string,types:array<int,string>}>
	 */
	public function search( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		if ( preg_match( '/^ChIJ[A-Za-z0-9_-]{20,}$/', $query ) ) {
			return array(
				array(
					'place_id' => $query,
					'name'     => __( 'Use this Place ID', 'boldreview' ),
					'address'  => $query,
					'types'    => array( 'place_id' ),
				),
			);
		}

		// 2. Google Maps URL — try to extract place_id from common URL shapes.
		$extracted = $this->extract_place_id_from_url( $query );
		if ( '' !== $extracted ) {
			return array(
				array(
					'place_id' => $extracted,
					'name'     => __( 'Place from Google Maps URL', 'boldreview' ),
					'address'  => $query,
					'types'    => array( 'maps_url' ),
				),
			);
		}

		$key = $this->api_key();
		if ( '' !== $key ) {
			return $this->places_autocomplete( $query, $key );
		}

		// 4. No key configured → demo fallback so the UI still demonstrates the flow.
		return $this->demo_search( $query );
	}

	/**
	 * Fetch place details + reviews for a place_id.
	 *
	 * @return array{
	 *     place_id:string,
	 *     name:string,
	 *     address:string,
	 *     rating:float,
	 *     total:int,
	 *     url:string,
	 *     reviews:array<int,array<string,mixed>>
	 * }|null
	 */
	public function details( string $place_id ): ?array {
		$place_id = trim( $place_id );
		if ( '' === $place_id ) {
			return null;
		}

		$key = $this->api_key();
		if ( '' !== $key ) {
			
			if ( 0 === strpos( $place_id, 'demo-' ) ) {
				return null;
			}
			return $this->places_details( $place_id, $key );
		}

		return $this->demo_details( $place_id );
	}

	/**
	 * Live autocomplete/search. Tries Places API (New) Text Search first, then
	 * falls back to the legacy Autocomplete endpoint for projects that still
	 * only have the old "Places API" enabled.
	 *
	 * @return array<int,array{place_id:string,name:string,address:string,types:array<int,string>}>
	 */
	protected function places_autocomplete( string $query, string $key ): array {
		$new = $this->places_search_new( $query, $key );
		if ( null !== $new ) {
			return $new;
		}
		return $this->places_autocomplete_legacy( $query, $key );
	}

	/**
	 * Live place details + reviews. Tries Places API (New) first, then the
	 * legacy Place Details endpoint. last_error is cleared on the path that
	 * succeeds so a fallback success doesn't surface the first call's error.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function places_details( string $place_id, string $key ): ?array {
		$new = $this->places_details_new( $place_id, $key );
		if ( null !== $new ) {
			$this->last_error = '';
			
			$extra = $this->legacy_reviews( $place_id, $key );
			if ( ! empty( $extra ) ) {
				$new['reviews'] = self::merge_reviews( (array) $new['reviews'], $extra );
			}
			return $new;
		}
		$legacy = $this->places_details_legacy( $place_id, $key );
		if ( null !== $legacy ) {
			$this->last_error = '';
			return $legacy;
		}
		return null;
	}

	/**
	 * Best-effort fetch of the legacy "newest" reviews for a place, used only to
	 * enrich the New API's relevance-sorted set so the displayed total can exceed
	 * 5. Returns an empty array (never an error) when the legacy endpoint is
	 * unavailable for this key — callers must treat it as optional.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function legacy_reviews( string $place_id, string $key ): array {
		$url = add_query_arg(
			array(
				'place_id'     => $place_id,
				'fields'       => 'reviews',
				'reviews_sort' => 'newest',
				'key'          => $key,
			),
			self::PLACE_DETAILS
		);

		$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['result']['reviews'] ) || ! is_array( $body['result']['reviews'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $body['result']['reviews'] as $rv ) {
			if ( ! is_array( $rv ) ) {
				continue;
			}
			$out[] = array(
				'author_name'   => (string) ( $rv['author_name'] ?? '' ),
				'profile_url'   => (string) ( $rv['author_url'] ?? '' ),
				'avatar'        => (string) ( $rv['profile_photo_url'] ?? '' ),
				'rating'        => (float) ( $rv['rating'] ?? 0 ),
				'relative_time' => (string) ( $rv['relative_time_description'] ?? '' ),
				'time'          => (int) ( $rv['time'] ?? 0 ),
				'content'       => (string) ( $rv['text'] ?? '' ),
				'language'      => (string) ( $rv['language'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Places API (New) Text Search. Returns a list of suggestions, an empty
	 * list when the query genuinely matched nothing, or null when the call
	 * itself failed (transport error / API not enabled) so the caller can fall
	 * back to the legacy endpoint.
	 *
	 * @return array<int,array{place_id:string,name:string,address:string,types:array<int,string>}>|null
	 */
	protected function places_search_new( string $query, string $key ): ?array {
		$resp = wp_remote_post(
			self::PLACES_SEARCH_NEW,
			array(
				'timeout' => 6,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.types',
				),
				'body'    => (string) wp_json_encode( array( 'textQuery' => $query ) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) || isset( $body['error'] ) ) {
			return null; // Signal "call failed" so the legacy fallback runs.
		}

		$out = array();
		foreach ( (array) ( $body['places'] ?? array() ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) ) {
				continue;
			}
			$types = isset( $p['types'] ) && is_array( $p['types'] ) ? array_values( array_map( 'sanitize_text_field', $p['types'] ) ) : array();
			$out[] = array(
				'place_id' => (string) $p['id'],
				'name'     => (string) ( $p['displayName']['text'] ?? '' ),
				'address'  => (string) ( $p['formattedAddress'] ?? '' ),
				'types'    => $types,
			);
		}
		return $out;
	}

	/**
	 * Places API (New) Place Details — returns business info + up to 5 reviews.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function places_details_new( string $place_id, string $key ): ?array {
		$resp = wp_remote_get(
			self::PLACE_DETAILS_NEW . rawurlencode( $place_id ),
			array(
				'timeout' => 8,
				'headers' => array(
					'X-Goog-Api-Key'   => $key,
					'X-Goog-FieldMask' => 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri,reviews,iconMaskBaseUri,types,photos',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			$this->last_error = $resp->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['id'] ) ) {
			$msg              = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			$status           = is_array( $body ) && isset( $body['error']['status'] ) ? (string) $body['error']['status'] : '';
			$this->last_error = '' !== $msg ? trim( sprintf( '%s (%s)', $msg, $status ) ) : 'Empty response from Google Places API (New).';
			return null;
		}

		$reviews = array();
		foreach ( (array) ( $body['reviews'] ?? array() ) as $rv ) {
			if ( ! is_array( $rv ) ) {
				continue;
			}
			$author = isset( $rv['authorAttribution'] ) && is_array( $rv['authorAttribution'] ) ? $rv['authorAttribution'] : array();
			$publish_time = (string) ( $rv['publishTime'] ?? '' );
			$reviews[] = array(
				'author_name'   => (string) ( $author['displayName'] ?? '' ),
				'profile_url'   => (string) ( $author['uri'] ?? '' ),
				
				'review_url'    => (string) ( $rv['googleMapsUri'] ?? '' ),
				'avatar'        => (string) ( $author['photoUri'] ?? '' ),
				'rating'        => (float) ( $rv['rating'] ?? 0 ),
				'relative_time' => (string) ( $rv['relativePublishTimeDescription'] ?? '' ),
				'time'          => '' !== $publish_time ? (int) strtotime( $publish_time ) : 0,
				'content'       => (string) ( $rv['text']['text'] ?? $rv['originalText']['text'] ?? '' ),
				'language'      => (string) ( $rv['text']['languageCode'] ?? '' ),
			);
		}

		$photo_url = '';
		if ( isset( $body['photos'][0]['name'] ) ) {
			$photo_url = self::PLACE_MEDIA_NEW . (string) $body['photos'][0]['name'] . '/media?maxWidthPx=200&key=' . rawurlencode( $key );
		}

		return array(
			'place_id' => (string) ( $body['id'] ?? $place_id ),
			'name'     => (string) ( $body['displayName']['text'] ?? '' ),
			'address'  => (string) ( $body['formattedAddress'] ?? '' ),
			'rating'   => (float) ( $body['rating'] ?? 0 ),
			'total'    => (int) ( $body['userRatingCount'] ?? 0 ),
			'url'      => (string) ( $body['googleMapsUri'] ?? '' ),
			'icon'     => '' !== $photo_url ? $photo_url : (string) ( $body['iconMaskBaseUri'] ?? '' ),
			'types'    => isset( $body['types'] ) && is_array( $body['types'] ) ? array_values( array_map( 'sanitize_text_field', $body['types'] ) ) : array(),
			'reviews'  => $reviews,
		);
	}

	/**
	 * Legacy Places Autocomplete call (fallback).
	 *
	 * @return array<int,array{place_id:string,name:string,address:string}>
	 */
	protected function places_autocomplete_legacy( string $query, string $key ): array {
		$url = add_query_arg(
			array(
				'input' => $query,
				'key'   => $key,
			),
			self::PLACES_AUTOCOMPLETE
		);

		$resp = wp_remote_get( $url, array( 'timeout' => 6 ) );
		if ( is_wp_error( $resp ) ) {
			return array();
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! isset( $body['predictions'] ) || ! is_array( $body['predictions'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $body['predictions'] as $p ) {
			if ( empty( $p['place_id'] ) ) {
				continue;
			}
			$main      = (string) ( $p['structured_formatting']['main_text'] ?? $p['description'] ?? '' );
			$secondary = (string) ( $p['structured_formatting']['secondary_text'] ?? '' );
			$types     = isset( $p['types'] ) && is_array( $p['types'] ) ? array_values( array_map( 'sanitize_text_field', $p['types'] ) ) : array();
			$out[]     = array(
				'place_id' => (string) $p['place_id'],
				'name'     => $main,
				'address'  => $secondary,
				'types'    => $types,
			);
		}
		return $out;
	}

	/**
	 * Legacy Places Details call (fallback — returns business info + reviews).
	 *
	 * @return array<string,mixed>|null
	 */
	protected function places_details_legacy( string $place_id, string $key ): ?array {
		$url = add_query_arg(
			array(
				'place_id' => $place_id,
				'fields'   => 'name,formatted_address,rating,user_ratings_total,url,reviews,icon,types,photos',
				'key'      => $key,
			),
			self::PLACE_DETAILS
		);

		$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) ) {
			$this->last_error = $resp->get_error_message();
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['result'] ) ) {
			$status            = is_array( $body ) ? (string) ( $body['status'] ?? '' ) : '';
			$msg               = is_array( $body ) ? (string) ( $body['error_message'] ?? '' ) : '';
			$this->last_error  = '' !== $msg ? sprintf( '%s (%s)', $msg, $status ) : ( '' !== $status ? $status : 'Empty response from Google Places API.' );
			return null;
		}
		$r = (array) $body['result'];

		$reviews = array();
		foreach ( (array) ( $r['reviews'] ?? array() ) as $rv ) {
			$reviews[] = array(
				'author_name'  => (string) ( $rv['author_name'] ?? '' ),
				'profile_url'  => (string) ( $rv['author_url'] ?? '' ),
				'avatar'       => (string) ( $rv['profile_photo_url'] ?? '' ),
				'rating'       => (float) ( $rv['rating'] ?? 0 ),
				'relative_time' => (string) ( $rv['relative_time_description'] ?? '' ),
				'time'         => (int) ( $rv['time'] ?? 0 ),
				'content'      => (string) ( $rv['text'] ?? '' ),
				'language'     => (string) ( $rv['language'] ?? '' ),
			);
		}

		$photo_ref = '';
		if ( isset( $r['photos'][0]['photo_reference'] ) ) {
			$photo_ref = (string) $r['photos'][0]['photo_reference'];
		}
		$photo_url = '' !== $photo_ref
			? add_query_arg(
				array(
					'maxwidth'        => 200,
					'photo_reference' => $photo_ref,
					'key'             => $key,
				),
				self::PLACE_PHOTO
			)
			: '';

		return array(
			'place_id' => $place_id,
			'name'     => (string) ( $r['name'] ?? '' ),
			'address'  => (string) ( $r['formatted_address'] ?? '' ),
			'rating'   => (float) ( $r['rating'] ?? 0 ),
			'total'    => (int) ( $r['user_ratings_total'] ?? 0 ),
			'url'      => (string) ( $r['url'] ?? '' ),
			'icon'     => '' !== $photo_url ? $photo_url : (string) ( $r['icon'] ?? '' ),
			'types'    => isset( $r['types'] ) && is_array( $r['types'] ) ? array_values( array_map( 'sanitize_text_field', $r['types'] ) ) : array(),
			'reviews'  => $reviews,
		);
	}

	/**
	 * Extract a place_id from a Google Maps URL where possible. Returns ''
	 * if the URL doesn't carry one we can parse statically.
	 */
	protected function extract_place_id_from_url( string $maybe_url ): string {

		if ( false === strpos( $maybe_url, 'google.' ) && false === strpos( $maybe_url, 'maps.app.goo.gl' ) ) {
			return '';
		}
		
		if ( preg_match( '#[?&]place_?id=([A-Za-z0-9_-]+)#', $maybe_url, $m ) ) {
			return $m[1];
		}
		
		return '';
	}

	/**
	 * Demo search dataset — surfaces a couple of fake suggestions so the
	 * search UI can be demoed before an API key is wired up.
	 *
	 * @return array<int,array{place_id:string,name:string,address:string}>
	 */
	protected function demo_search( string $query ): array {
		$search_term = sanitize_text_field( $query );
		$business    = ucwords( $search_term );

		$demo_results = array(
			array(
				'name'    => $business,
				'address' => 'Dhaka, Bangladesh',
				'types'   => array( 'establishment', 'point_of_interest' ),
			),
			array(
				'name'    => "{$business} Market",
				'address' => 'West 26th Street, New York, NY, USA',
				'types'   => array( 'store', 'establishment' ),
			),
			array(
				'name'    => "{$business} Mart | Online Shop",
				'address' => 'Dhaka, Bangladesh',
				'types'   => array( 'store', 'establishment' ),
			),
			array(
				'name'    => "{$business}-{$business}",
				'address' => 'Perkins Road, Baton Rouge, LA, USA',
				'types'   => array( 'restaurant', 'establishment' ),
			),
			array(
				'name'    => "{$business} {$business} Cafe",
				'address' => 'California Avenue, Irvine, CA, USA',
				'types'   => array( 'cafe', 'establishment' ),
			),
		);

		$results = array();

		foreach ( $demo_results as $index => $result ) {
			$results[] = array(
				'place_id' => 'demo-' . md5( $search_term . '-' . $index ),
				'name'     => $result['name'],
				'address'  => $result['address'],
				'types'    => $result['types'],
			);
		}

		return $results;
	}

	/**
	 * Demo details payload returned when no API key is set or the live call
	 * failed. Returns four representative reviews so layouts/templates have
	 * something to render in preview/frontend.
	 *
	 * @return array<string,mixed>
	 */
	public function demo_details( string $place_id ): array {
		return array(
			'place_id' => $place_id,
			'name'     => __( 'Acme Coffee Co.', 'boldreview' ),
			'address'  => __( '123 Demo Street, Sample City', 'boldreview' ),
			'rating'   => 4.6,
			'total'    => 312,
			'url'      => 'https://maps.google.com/',
			'icon'     => '',
			'types'    => array( 'cafe', 'establishment' ),
			'reviews'  => array(
				array(
					'author_name'   => 'Sarah Johnson',
					'profile_url'   => '',
					'avatar'        => '',
					'rating'        => 5,
					'relative_time' => __( '2 weeks ago', 'boldreview' ),
					'time'          => time() - WEEK_IN_SECONDS * 2,
					'content'       => __( 'Best espresso in town! The baristas know their craft and the pastries are exceptional.', 'boldreview' ),
				),
				array(
					'author_name'   => 'Michael Chen',
					'profile_url'   => '',
					'avatar'        => '',
					'rating'        => 4,
					'relative_time' => __( 'a month ago', 'boldreview' ),
					'time'          => time() - MONTH_IN_SECONDS,
					'content'       => __( 'Lovely atmosphere, great Wi-Fi for working. Coffee is solid; could use a few more pastry choices.', 'boldreview' ),
				),
				array(
					'author_name'   => 'Priya Sharma',
					'profile_url'   => '',
					'avatar'        => '',
					'rating'        => 5,
					'relative_time' => __( '3 weeks ago', 'boldreview' ),
					'time'          => time() - WEEK_IN_SECONDS * 3,
					'content'       => __( 'Friendly staff, latte art on point, the seasonal drinks are creative. Becoming a regular.', 'boldreview' ),
				),
				array(
					'author_name'   => 'David Müller',
					'profile_url'   => '',
					'avatar'        => '',
					'rating'        => 5,
					'relative_time' => __( '5 days ago', 'boldreview' ),
					'time'          => time() - DAY_IN_SECONDS * 5,
					'content'       => __( 'Stumbled in on a rainy afternoon and stayed for hours. Cozy, well-lit, and the cortado was perfect.', 'boldreview' ),
				),
			),
		);
	}
}
