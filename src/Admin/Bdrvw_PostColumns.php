<?php
/**
 * "BoldReview Rating" column on the post-type list screens.
 *
 * Added to every post type reviews are enabled for — Posts, Products, whatever
 * else is switched on — but only while that post type actually has a rating to
 * show. On a site where nothing has been rated yet the column never appears,
 * rather than sitting there full of dashes.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Rating column for post / product list tables.
 */
class Bdrvw_PostColumns {

	const COLUMN = 'bdrvw_rating';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'hook_post_types' ) );
	}

	/**
	 * Attach the column to each enabled post type's list table.
	 */
	public function hook_post_types(): void {
		foreach ( Bdrvw_Review::enabled_post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ), 99 );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}

		add_action( 'admin_head-edit.php', array( $this, 'styles' ) );
	}

	/**
	 * Styling for the column.
	 *
	 * The plugin's admin stylesheet is only enqueued on BoldReview's own
	 * screens, so the few rules this column needs are printed here instead of
	 * loading the whole sheet onto every post list. Colours are literal for the
	 * same reason — the stylesheet's custom properties aren't available.
	 */
	public function styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! Bdrvw_Review::post_type_has_ratings( (string) $screen->post_type ) ) {
			return;
		}
		?>
		<style id="bdrvw-rating-column">
			.column-<?php echo esc_html( self::COLUMN ); ?> { width: 110px; }
			.bdrvw-col-rating { display: inline-flex; align-items: center; white-space: nowrap; }
			.bdrvw-col-rating .bdrvw-stars { display: inline-flex; gap: 1px; }
			.bdrvw-col-rating .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 1; }
			.bdrvw-col-rating .dashicons-star-filled { color: #f59e0b; }
			.bdrvw-col-rating .dashicons-star-empty { color: #d6d8e0; }
		</style>
		<?php
	}

	/**
	 * Add the header — but only when this post type has something rated.
	 *
	 * Sits directly after the categories column. Post types without one (or
	 * with the taxonomy column hidden) fall back to the middle of the row, so
	 * it never ends up stranded past Date at the far right.
	 *
	 * @param array<string,string> $columns Registered columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen ? (string) $screen->post_type : '';

		if ( '' === $post_type || ! Bdrvw_Review::post_type_has_ratings( $post_type ) ) {
			return $columns;
		}

		$label    = __( 'BoldReview Rating', 'boldreview' );
		$position = $this->insert_position( $columns );

		if ( $position >= count( $columns ) ) {
			$columns[ self::COLUMN ] = $label;
			return $columns;
		}

		return array_slice( $columns, 0, $position, true )
			+ array( self::COLUMN => $label )
			+ array_slice( $columns, $position, null, true );
	}

	/**
	 * Index to insert at: right after the categories column when there is one.
	 *
	 * Core names it `categories` on posts and `taxonomy-{name}` on custom post
	 * types; WooCommerce uses `product_cat`. Anything else falls back to the
	 * middle of the row.
	 *
	 * @param array<string,string> $columns Registered columns.
	 */
	protected function insert_position( array $columns ): int {
		$keys      = array_keys( $columns );
		$preferred = array( 'categories', 'product_cat', 'taxonomy-category', 'taxonomy-product_cat' );

		foreach ( $preferred as $key ) {
			$index = array_search( $key, $keys, true );
			if ( false !== $index ) {
				return (int) $index + 1;
			}
		}

		foreach ( $keys as $index => $key ) {
			if ( 0 !== strpos( (string) $key, 'taxonomy-' ) ) {
				continue;
			}
			$taxonomy = get_taxonomy( substr( (string) $key, strlen( 'taxonomy-' ) ) );
			if ( $taxonomy && ! empty( $taxonomy->hierarchical ) ) {
				return (int) $index + 1;
			}
		}

		return max( 2, (int) floor( count( $columns ) / 2 ) );
	}

	/**
	 * Render one cell: just the stars.
	 *
	 * The average and review count are carried on the cell's title/aria label
	 * rather than printed, so the column stays a single glanceable row of stars
	 * without losing the exact figures for hover or a screen reader.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Row's post id.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$aggregate = Bdrvw_Review::aggregate_for_post( (int) $post_id );
		$count     = (int) $aggregate['count'];
		$average   = (float) $aggregate['average'];

		$label = $count > 0
			? sprintf(
				/* translators: 1: average rating, 2: number of reviews. */
				_n( '%1$s out of 5 from %2$s review', '%1$s out of 5 from %2$s reviews', $count, 'boldreview' ),
				number_format_i18n( $average, 1 ),
				number_format_i18n( $count )
			)
			: __( 'No rating yet', 'boldreview' );

		printf(
			'<span class="bdrvw-col-rating" title="%s" aria-label="%s">%s</span>',
			esc_attr( $label ),
			esc_attr( $label ),
			wp_kses_post( Bdrvw_ReviewPanel::stars_html( (int) round( $average ) ) )
		);
	}
}
