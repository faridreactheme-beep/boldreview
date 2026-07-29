<?php
/**
 * Per-post "Rating Style" meta box.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Core\Bdrvw_PostStyle;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Rating Style" meta box to the post/page editor for every post type
 * where reviews are enabled. Editors can override the global rating-summary and
 * rating-input style for a single post — or leave it off to keep the global one.
 */
class Bdrvw_PostStyleMetaBox {

	/**
	 * Settings store.
	 *
	 * @var Bdrvw_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Bdrvw_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Post types the review form can appear on (from Display settings).
	 *
	 * @return string[]
	 */
	private function enabled_post_types(): array {
		$types = (array) $this->settings->get( 'enabled_post_types', array() );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * Add the meta box to every enabled post type.
	 */
	public function add_box(): void {
		foreach ( $this->enabled_post_types() as $type ) {
			add_meta_box(
				'bdrvw_post_style',
				__( 'Rating Style', 'boldreview' ),
				array( $this, 'render' ),
				$type,
				'normal',
				$this->priority_for( $type )
			);
		}
	}

	/**
	 * Where the box should sit within the normal column.
	 *
	 * On a product it goes last, below the short description. WooCommerce seeds
	 * `meta-box-order_product` with its own boxes, which WordPress then renders
	 * at the `sorted` priority — ahead of everything but `high`. Sitting at
	 * `high` put an optional styling choice above the Product data panel, which
	 * is the one thing on that screen nobody should have to scroll past.
	 * Elsewhere the box stays where it has always been, under the editor.
	 *
	 * @param string $post_type Post type the box is being added to.
	 */
	private function priority_for( string $post_type ): string {
		return 'product' === $post_type ? 'low' : 'high';
	}

	/**
	 * Load the BoldReview admin CSS on the editor so the summary-style previews
	 * render, exactly as they do on the settings screen. Scoped to enabled post
	 * types only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		$css_path = BDRVW_DIR . 'assets/css/admin.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : BDRVW_VERSION;
		wp_enqueue_style( 'bdrvw-admin', BDRVW_URL . 'assets/css/admin.css', array( 'dashicons' ), $css_ver );

		$s   = $this->settings->all();
		$css = sprintf(
			':root{--bdrvw-brand:%s;--bdrvw-star:%s;}',
			sanitize_hex_color( (string) ( $s['brand_color'] ?? '#6366f1' ) ),
			sanitize_hex_color( (string) ( $s['star_color'] ?? '#fbbf24' ) )
		);
		wp_add_inline_style( 'bdrvw-admin', $css );
	}

	/**
	 * Summary-style previews: value => [label, hint]. Mirrors the Display screen.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function summary_defs(): array {
		return array(
			'bars'     => array( __( 'Bars', 'boldreview' ),     __( 'Overall average + count', 'boldreview' ) ),
			'point'    => array( __( 'Point', 'boldreview' ),    __( 'Big score card', 'boldreview' ) ),
			'pie'      => array( __( 'Pie', 'boldreview' ),      __( 'Donut progress arc', 'boldreview' ) ),
			'hbars'    => array( __( 'H-bars', 'boldreview' ),   __( 'Criteria fill bars', 'boldreview' ) ),
			'stripes'  => array( __( 'Stripes', 'boldreview' ),  __( 'Segmented meter', 'boldreview' ) ),
			'gauge'    => array( __( 'Gauges', 'boldreview' ),   __( 'Half-circle dials', 'boldreview' ) ),
			'tiles'    => array( __( 'Tiles', 'boldreview' ),    __( 'Score cards', 'boldreview' ) ),
			'overview' => array( __( 'Overview', 'boldreview' ), __( 'Criteria list + total card', 'boldreview' ) ),
		);
	}

	/**
	 * Input-style options: value => label.
	 *
	 * @return array<string,string>
	 */
	private function input_defs(): array {
		return array(
			'stars'  => __( 'Stars', 'boldreview' ),
			'slider' => __( 'Slider', 'boldreview' ),
			'bar'    => __( 'Bar', 'boldreview' ),
			'square' => __( 'Squares', 'boldreview' ),
			'pill'   => __( 'Pills', 'boldreview' ),
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render( $post ): void {
		wp_nonce_field( 'bdrvw_post_style', 'bdrvw_post_style_nonce' );

		$s        = $this->settings->all();
		$enabled  = (int) get_post_meta( $post->ID, Bdrvw_PostStyle::META_ENABLED, true );
		$summary  = (string) get_post_meta( $post->ID, Bdrvw_PostStyle::META_SUMMARY, true );
		$input    = (string) get_post_meta( $post->ID, Bdrvw_PostStyle::META_INPUT, true );

		// Pre-select the global values when the post has no saved choice yet.
		if ( ! in_array( $summary, Bdrvw_PostStyle::SUMMARY_STYLES, true ) ) {
			$summary = (string) ( $s['summary_style'] ?? 'bars' );
		}
		if ( ! in_array( $input, Bdrvw_PostStyle::INPUT_STYLES, true ) ) {
			$input = (string) ( $s['rating_input_style'] ?? 'stars' );
		}

		$defs   = $this->summary_defs();
		$inputs = $this->input_defs();

		$object = get_post_type_object( $post->post_type );
		$noun   = ( $object && ! empty( $object->labels->singular_name ) )
			? mb_strtolower( (string) $object->labels->singular_name )
			: __( 'post', 'boldreview' );
		?>
		<div class="bdrvw-metabox">
			<p class="bdrvw-metabox__toggle">
				<label>
					<input type="checkbox" name="bdrvw_post_style_enabled" value="1" <?php checked( $enabled, 1 ); ?> data-bdrvw-override-toggle />
					<strong><?php
						printf(
							/* translators: %s: singular name of the post type being edited, e.g. "product". */
							esc_html__( 'Override style for this %s', 'boldreview' ),
							esc_html( $noun )
						);
					?></strong>
				</label>
				<span class="bdrvw-metabox__hint"><?php
					printf(
						/* translators: %s: singular name of the post type being edited, e.g. "product". */
						esc_html__( 'Off = use the global Display style. On = use the styles picked below on this %s only.', 'boldreview' ),
						esc_html( $noun )
					);
				?></span>
			</p>

			<div class="bdrvw-metabox__options<?php echo $enabled ? '' : ' is-off'; ?>" data-bdrvw-override-options>
				<p class="bdrvw-metabox__label"><strong><?php esc_html_e( 'Rating summary style', 'boldreview' ); ?></strong></p>
				<div class="bdrvw-cards-grid bdrvw-sumstyles bdrvw-metabox__grid">
					<?php foreach ( $defs as $val => $info ) : ?>
						<label class="bdrvw-template <?php echo $summary === $val ? 'is-selected' : ''; ?>">
							<input type="radio" name="bdrvw_post_summary_style" value="<?php echo esc_attr( $val ); ?>" <?php checked( $summary, $val ); ?> />
							<span class="bdrvw-template__preview bdrvw-sumstyle-preview bdrvw-sumstyle-preview--<?php echo esc_attr( $val ); ?>" aria-hidden="true"></span>
							<span class="bdrvw-template__label"><?php echo esc_html( $info[0] ); ?></span>
							<span class="bdrvw-sumstyle-hint"><?php echo esc_html( $info[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="bdrvw-metabox__label"><strong><?php esc_html_e( 'Rating input style', 'boldreview' ); ?></strong></p>
				<select name="bdrvw_post_input_style" class="widefat">
					<?php foreach ( $inputs as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $input, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<style>
			.bdrvw-metabox__hint{display:block;margin-top:4px;color:#6b7280;font-size:12px}
			.bdrvw-metabox__label{margin:18px 0 8px;font-size:13px}
			.bdrvw-metabox__grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
			.bdrvw-metabox__grid .bdrvw-template{margin:0}
			.bdrvw-metabox__options.is-off{opacity:.45;pointer-events:none}
			.bdrvw-metabox select[name="bdrvw_post_input_style"]{max-width:280px}
		</style>
		<script>
			( function () {
				var box = document.getElementById( 'bdrvw_post_style' );
				if ( ! box ) { return; }
				var toggle = box.querySelector( '[data-bdrvw-override-toggle]' );
				var opts   = box.querySelector( '[data-bdrvw-override-options]' );
				if ( ! toggle || ! opts ) { return; }
				toggle.addEventListener( 'change', function () {
					opts.classList.toggle( 'is-off', ! toggle.checked );
				} );
				// Keep the summary card highlight in sync without the settings JS.
				box.addEventListener( 'change', function ( e ) {
					if ( e.target && e.target.name === 'bdrvw_post_summary_style' ) {
						box.querySelectorAll( '.bdrvw-template' ).forEach( function ( t ) { t.classList.remove( 'is-selected' ); } );
						var card = e.target.closest( '.bdrvw-template' );
						if ( card ) { card.classList.add( 'is-selected' ); }
					}
				} );
			} )();
		</script>
		<?php
	}

	/**
	 * Persist the meta box on save.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST['bdrvw_post_style_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bdrvw_post_style_nonce'] ) ), 'bdrvw_post_style' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = ! empty( $_POST['bdrvw_post_style_enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, Bdrvw_PostStyle::META_ENABLED, $enabled );

		$summary = isset( $_POST['bdrvw_post_summary_style'] ) ? sanitize_key( wp_unslash( $_POST['bdrvw_post_summary_style'] ) ) : '';
		if ( in_array( $summary, Bdrvw_PostStyle::SUMMARY_STYLES, true ) ) {
			update_post_meta( $post_id, Bdrvw_PostStyle::META_SUMMARY, $summary );
		}

		$input = isset( $_POST['bdrvw_post_input_style'] ) ? sanitize_key( wp_unslash( $_POST['bdrvw_post_input_style'] ) ) : '';
		if ( in_array( $input, Bdrvw_PostStyle::INPUT_STYLES, true ) ) {
			update_post_meta( $post_id, Bdrvw_PostStyle::META_INPUT, $input );
		}
	}
}
