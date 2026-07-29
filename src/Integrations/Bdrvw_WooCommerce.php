<?php
/**
 * WooCommerce integration.
 *
 * When the `product` post type is enabled under Settings → Enabled post types,
 * this replaces the default WooCommerce "Reviews" tab with BoldReview's own
 * summary + criteria + list + form, and makes the product's rating (shown in the
 * shop loop, under the product title and in structured data) reflect BoldReview's
 * aggregate instead of WooCommerce's native comment ratings.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Integrations;

use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Frontend\Bdrvw_Shortcodes;
use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges BoldReview's rating system onto WooCommerce products.
 */
class Bdrvw_WooCommerce {

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Shortcodes service — reused to render the reviews list + form in the tab.
	 *
	 * @var Bdrvw_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Per-request aggregate cache keyed by product id, so the WooCommerce rating
	 * getters (which fire repeatedly in a shop loop) hit the database only once
	 * per product.
	 *
	 * @var array<int,array{average:float,count:int,breakdown:array<int,int>}>
	 */
	private $agg_cache = array();

	/**
	 * Per-request cache for verified-purchase lookups, keyed by
	 * "productId|identity|status". Avoids repeat order queries when the gate and
	 * the per-review badges ask about the same customer.
	 *
	 * @var array<string,bool>
	 */
	private $bought_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings, Bdrvw_Shortcodes $shortcodes ) {
		$this->settings   = $settings;
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Register hooks. No-op unless the `product` post type is enabled.
	 */
	public function register(): void {
		$enabled = (array) $this->settings->get( 'enabled_post_types', array() );
		if ( ! in_array( 'product', $enabled, true ) ) {
			return;
		}

		add_filter( 'woocommerce_product_tabs', array( $this, 'product_tabs' ), 98 );

		add_filter( 'woocommerce_product_get_average_rating', array( $this, 'filter_average_rating' ), 10, 2 );
		add_filter( 'woocommerce_product_get_review_count', array( $this, 'filter_review_count' ), 10, 2 );
		add_filter( 'woocommerce_product_get_rating_counts', array( $this, 'filter_rating_counts' ), 10, 2 );

		add_action( 'bdrvw_review_submitted', array( $this, 'on_review_submitted' ), 10, 2 );
		add_action( 'bdrvw_review_status_changed', array( $this, 'on_review_status_changed' ), 10, 1 );
		add_action( 'bdrvw_review_deleted', array( $this, 'on_review_deleted' ), 10, 1 );

		add_filter( 'bdrvw_review_author_badge', array( $this, 'verified_badge' ), 10, 3 );

		add_filter( 'bdrvw_submit_gate_error', array( $this, 'submit_gate' ), 10, 3 );
	}

	/**
	 * Swap the reviews tab callback (and refresh its title count) for products.
	 *
	 * @param array<string,array<string,mixed>> $tabs Registered product tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function product_tabs( $tabs ) {
		$product_id = $this->current_product_id();
		if ( $product_id <= 0 ) {
			return $tabs;
		}

		$count = (int) $this->product_aggregate( $product_id )['count'];

		$tabs['reviews'] = array(
			'title'    => sprintf(
				/* translators: %s: number of reviews. */
				_n( 'Review (%s)', 'Reviews (%s)', $count, 'boldreview' ),
				number_format_i18n( $count )
			),
			'priority' => isset( $tabs['reviews']['priority'] ) ? $tabs['reviews']['priority'] : 30,
			'callback' => array( $this, 'render_reviews_tab' ),
		);

		return $tabs;
	}

	/**
	 * Render BoldReview's reviews list + submission form inside the product tab.
	 */
	public function render_reviews_tab(): void {
		$product_id = $this->current_product_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$verified_only = 'verified' === (string) $this->settings->get( 'wc_review_who_can', 'anyone' );
		if ( $verified_only && ! $this->is_verified_owner( $product_id ) ) {
			echo $this->shortcodes->reviews( array( 'post_id' => $product_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
			echo '<div class="bdrvw-wc-verify-notice">'
				. esc_html__( 'Only logged in customers who have purchased this product may leave a review.', 'boldreview' )
				. '</div>';
			return;
		}

		$form_html = $this->shortcodes->form(
			array(
				'post_id'   => $product_id,
				'post_type' => 'product',
			)
		);
		echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the renderer.
	}

	/**
	 * Is the current visitor a verified owner of the product — i.e. logged in and
	 * has an order containing it in the configured eligible status?
	 */
	protected function is_verified_owner( int $product_id ): bool {
	
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user   = wp_get_current_user();
		$status = (string) $this->settings->get( 'wc_review_order_status', 'completed' );
		return $this->bought_product_in_status( $product_id, (int) $user->ID, (string) $user->user_email, $status );
	}

	/**
	 * "Verified owner" badge for a review left by a verified buyer. Hooked to
	 * `bdrvw_review_author_badge` and only active while the label toggle is on.
	 *
	 * @param string              $html    Existing badge HTML.
	 * @param array<string,mixed> $row     Review row.
	 * @param int                 $post_id Product id the review belongs to.
	 * @return string
	 */
	public function verified_badge( $html, $row, $post_id ) {
		$post_id = (int) $post_id;
		if ( empty( $this->settings->get( 'wc_review_verified_label', 1 ) ) || 'product' !== get_post_type( $post_id ) ) {
			return (string) $html;
		}
		$status = (string) $this->settings->get( 'wc_review_order_status', 'completed' );
		$bought = $this->bought_product_in_status(
			$post_id,
			(int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['author_email'] ?? '' ),
			$status
		);
		if ( $bought ) {
			
			$html .= ' <em class="bdrvw-verified-badge woocommerce-review__verified verified">'
				. esc_html__( '(verified owner)', 'boldreview' ) . '</em>';
		}
		return (string) $html;
	}

	/**
	 * Server-side submission gate for verified-only products. Hooked to
	 * `bdrvw_submit_gate_error`; returns a non-empty message to reject.
	 *
	 * @param string              $error     Existing error (short-circuits when set).
	 * @param array<string,mixed> $payload   Sanitized review payload.
	 * @param string              $post_type Target post type.
	 * @return string
	 */
	public function submit_gate( $error, $payload, $post_type ) {
		if ( '' !== (string) $error || 'product' !== (string) $post_type ) {
			return (string) $error;
		}
		if ( 'verified' !== (string) $this->settings->get( 'wc_review_who_can', 'anyone' ) ) {
			return (string) $error;
		}
		if ( ! is_user_logged_in() ) {
			return __( 'Only logged in customers who have purchased this product may leave a review.', 'boldreview' );
		}
		$user   = wp_get_current_user();
		$status = (string) $this->settings->get( 'wc_review_order_status', 'completed' );
		if ( ! $this->bought_product_in_status( (int) ( $payload['post_id'] ?? 0 ), (int) $user->ID, (string) $user->user_email, $status ) ) {
			return __( 'Only logged in customers who have purchased this product may leave a review.', 'boldreview' );
		}
		return (string) $error;
	}

	/**
	 * Did the given customer (by id or email) buy the product in an order that is
	 * currently in the configured eligible status? Cached per request.
	 *
	 * @param int    $product_id Product id (matches simple or variation purchases).
	 * @param int    $user_id    Customer user id (0 for guests).
	 * @param string $email      Customer billing email (used when no user id).
	 * @param string $status     Eligible order status slug, e.g. "completed".
	 */
	protected function bought_product_in_status( int $product_id, int $user_id, string $email, string $status ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$identity = $user_id > 0 ? 'u' . $user_id : 'e' . strtolower( trim( $email ) );
		if ( 'e' === $identity ) {
			return false; // No usable identity.
		}
		$cache_key = $product_id . '|' . $identity . '|' . $status;
		if ( isset( $this->bought_cache[ $cache_key ] ) ) {
			return $this->bought_cache[ $cache_key ];
		}

		$args = array(
			'status' => array( 'wc-' . preg_replace( '/^wc-/', '', $status ) ),
			'limit'  => -1,
			'return' => 'ids',
		);
		if ( $user_id > 0 ) {
			$args['customer_id'] = $user_id;
		} else {
			$args['customer'] = $email;
		}

		$found = false;
		foreach ( (array) wc_get_orders( $args ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->bought_cache[ $cache_key ] = $found;
		return $found;
	}

	/**
	 * Override the product's average rating with BoldReview's aggregate.
	 *
	 * @param mixed $value   Original average.
	 * @param mixed $product WC_Product.
	 * @return float
	 */
	public function filter_average_rating( $value, $product ) {
		$id = $this->product_id_from( $product );
		if ( $id <= 0 ) {
			return (float) $value;
		}
		return (float) $this->product_aggregate( $id )['average'];
	}

	/**
	 * Override the product's review count with BoldReview's approved count.
	 *
	 * @param mixed $value   Original count.
	 * @param mixed $product WC_Product.
	 * @return int
	 */
	public function filter_review_count( $value, $product ) {
		$id = $this->product_id_from( $product );
		if ( $id <= 0 ) {
			return (int) $value;
		}
		return (int) $this->product_aggregate( $id )['count'];
	}

	/**
	 * Override the per-star rating distribution with BoldReview's breakdown.
	 *
	 * @param mixed $value   Original counts.
	 * @param mixed $product WC_Product.
	 * @return array<int,int>
	 */
	public function filter_rating_counts( $value, $product ) {
		$id = $this->product_id_from( $product );
		if ( $id <= 0 ) {
			return (array) $value;
		}
		
		$breakdown = $this->product_aggregate( $id )['breakdown'];
		
		return array_filter( $breakdown );
	}

	/**
	 * Sync WooCommerce meta when a review is submitted.
	 *
	 * @param int                 $id   Review id.
	 * @param array<string,mixed> $data Review data (contains post_id).
	 */
	public function on_review_submitted( $id, $data ): void {
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		$this->sync_product_meta( $post_id );
	}

	/**
	 * Sync WooCommerce meta when a review's status changes (approve/unapprove…).
	 *
	 * @param int $id Review id.
	 */
	public function on_review_status_changed( $id ): void {
		$review = Bdrvw_Review::find( (int) $id );
		if ( is_array( $review ) ) {
			$this->sync_product_meta( (int) $review['post_id'] );
		}
	}

	/**
	 * A deleted review's post id is no longer resolvable, so refresh every product
	 * that currently has BoldReview data would be overkill — instead we rely on the
	 * live rating getters (which are always accurate). This hook is kept as an
	 * extension point and intentionally does nothing to WooCommerce meta.
	 *
	 * @param int $id Deleted review id.
	 */
	public function on_review_deleted( $id ): void {
		unset( $id );
	}

	/**
	 * Write BoldReview's aggregate into WooCommerce's own product-rating meta.
	 *
	 * @param int $post_id Product id.
	 */
	protected function sync_product_meta( int $post_id ): void {
		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Recompute fresh (bypass the per-request cache which may predate the write).
		unset( $this->agg_cache[ $post_id ] );
		$agg = $this->product_aggregate( $post_id );

		update_post_meta( $post_id, '_wc_average_rating', (float) $agg['average'] );
		update_post_meta( $post_id, '_wc_review_count', (int) $agg['count'] );
		update_post_meta( $post_id, '_wc_rating_count', array_filter( $agg['breakdown'] ) );
	}

	/**
	 * BoldReview aggregate for a product, mirroring the renderer's logic: use the
	 * overall star rating when the rating input is enabled, otherwise derive the
	 * average from the per-criterion scores. Cached per request.
	 *
	 * @param int $post_id Product id.
	 * @return array{average:float,count:int,breakdown:array<int,int>}
	 */
	protected function product_aggregate( int $post_id ): array {
		if ( isset( $this->agg_cache[ $post_id ] ) ) {
			return $this->agg_cache[ $post_id ];
		}

		$rating_input_on = ! array_key_exists( 'rating_input_style_enabled', $this->settings->all() )
			|| ! empty( $this->settings->get( 'rating_input_style_enabled', 1 ) );

		if ( $rating_input_on ) {
			$agg = Bdrvw_Review::aggregate_for_post( $post_id );
		} else {
			// Criteria-only: overall average = mean of every criterion score.
			$avgs  = Bdrvw_Review::criteria_averages_for_post( $post_id );
			$total = 0.0;
			$cnt   = 0;
			foreach ( $avgs as $a ) {
				$total += (float) ( $a['average'] ?? 0 ) * (int) ( $a['count'] ?? 0 );
				$cnt   += (int) ( $a['count'] ?? 0 );
			}
			$average = $cnt > 0 ? round( $total / $cnt, 2 ) : 0.0;
			$count   = Bdrvw_Review::approved_count_for_post( $post_id );

			$breakdown = array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 );
			if ( $count > 0 && $average > 0 ) {
				$bucket               = max( 1, min( 5, (int) round( $average ) ) );
				$breakdown[ $bucket ] = $count;
			}

			$agg = array(
				'average'   => $average,
				'count'     => $count,
				'breakdown' => $breakdown,
			);
		}

		$this->agg_cache[ $post_id ] = $agg;
		return $agg;
	}

	/**
	 * Resolve the product id currently being rendered (tab context).
	 */
	protected function current_product_id(): int {
		global $product;
		if ( $product instanceof \WC_Product ) {
			return (int) $product->get_id();
		}
		$id = (int) get_the_ID();
		return $id > 0 ? $id : 0;
	}

	/**
	 * Resolve a product id from a value passed to the rating getters.
	 *
	 * @param mixed $product WC_Product|int|mixed.
	 */
	protected function product_id_from( $product ): int {
		if ( $product instanceof \WC_Product ) {
			return (int) $product->get_id();
		}
		if ( is_numeric( $product ) ) {
			return (int) $product;
		}
		return 0;
	}
}
