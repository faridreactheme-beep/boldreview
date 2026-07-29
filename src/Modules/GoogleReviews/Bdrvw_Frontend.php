<?php
/**
 * Frontend renderer for the Google Reviews module.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Modules\GoogleReviews;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Google Reviews showcase on the frontend via shortcode, and
 * provides the shared layout/template renderer used by the admin preview pane.
 */
class Bdrvw_Frontend {

	const SHORTCODE = 'bdrvw_google';

	/**
	 * Per-layout shortcode slugs. Each one renders that specific layout so
	 * users get the layout/template combo they configured even when they
	 * paste the shortcode by hand. Style attribute selects template_1 / 2.
	 *
	 * @return array<string,string> layout-slug => shortcode-slug
	 */
	public static function layout_shortcodes(): array {
		$shortcodes = array(
			'grid'    => 'bdrvw_google_grid',
			'list'    => 'bdrvw_google_list',
			'sidebar' => 'bdrvw_google_sidebar',
			'popup'   => 'bdrvw_google_popup',
		);

		/**
		 * Filter the per-layout shortcode slugs. Add-ons can register a shortcode
		 * for a layout they introduce via the `bdrvw_gr_allowed_layouts` filter.
		 *
		 * @param array<string,string> $shortcodes layout-slug => shortcode-slug.
		 */
		return (array) apply_filters( 'bdrvw_gr_layout_shortcodes', $shortcodes );
	}

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
	 * Register hooks. Registers the generic `[bdrvw_google]` shortcode
	 * (kept for back-compat with the layout/template attrs) plus a
	 * per-layout shortcode for each layout in `layout_shortcodes()` so the
	 * admin's "shortcode" card can hand out a layout-specific tag.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_shortcodes' ) );
	}

	/**
	 * Register the generic `[bdrvw_google]` shortcode plus a per-layout shortcode
	 * for each layout in `layout_shortcodes()`.
	 */
	public function register_shortcodes(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		foreach ( self::layout_shortcodes() as $layout => $slug ) {
			add_shortcode(
				$slug,
				function ( $atts ) use ( $layout ) {
					return $this->layout_shortcode( $layout, $atts );
				}
			);
		}
	}

	/**
	 * Generic `[bdrvw_google]` — honours `layout` + `template` attrs.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$raw  = (array) $atts;
		$gr   = (array) $this->settings->get( 'google_reviews', array() );
		$atts = shortcode_atts(
			array(
				'layout'      => $gr['layout'] ?? 'grid',
				'template'    => $gr['template'] ?? 'template_1',
				'limit'       => (int) ( $gr['limit'] ?? 3 ),
				'max_reviews' => '',
				'columns'     => (int) ( $gr['columns'] ?? 3 ),
				'order'       => '',
				'min_rating'  => '',
				'rating'      => '',
			),
			$raw,
			self::SHORTCODE
		);

		$layout = (string) $atts['layout'];

		$locked = self::premium_unavailable_template( (string) $atts['template'] );
		if ( '' !== $locked ) {
			return self::premium_template_notice( $locked );
		}

		return $this->render_with_atts( $layout, (string) $atts['template'], self::resolve_limit( $atts ), self::clamp_columns( $atts['columns'] ), self::extra_opts_from_atts( $layout, $raw ), (string) $atts['order'], self::parse_rating_att( $atts['min_rating'] ), self::parse_rating_att( $atts['rating'] ) );
	}

	/**
	 * Per-layout shortcode body — accepts a `style` attribute (style1/style2)
	 * that maps to template_1 / template_2.
	 *
	 * @param string                     $layout Forced layout for this shortcode.
	 * @param array<string,mixed>|string $atts   Attributes.
	 */
	protected function layout_shortcode( string $layout, $atts ): string {
		$raw  = (array) $atts;
		$gr   = (array) $this->settings->get( 'google_reviews', array() );
		$atts = shortcode_atts(
			array(
				'style'       => $this->style_from_template( (string) ( $gr['template'] ?? 'template_1' ), $layout ),
				'limit'       => (int) ( $gr['limit'] ?? 3 ),
				'max_reviews' => '',
				'columns'     => (int) ( $gr['columns'] ?? 3 ),
				'order'       => '',
				'min_rating'  => '',
				'rating'      => '',
			),
			$raw,
			'bdrvw_google_' . $layout
		);

		// Resolve against the full canonical style list first: if this style points
		// at a premium design whose add-on is inactive, show a notice rather than
		// falling back to style 1.
		$locked = self::premium_unavailable_template( $this->template_from_style_full( (string) $atts['style'], $layout ) );
		if ( '' !== $locked ) {
			return self::premium_template_notice( $locked );
		}

		$template = $this->template_from_style( (string) $atts['style'], $layout );
		return $this->render_with_atts( $layout, $template, self::resolve_limit( $atts ), self::clamp_columns( $atts['columns'] ), self::extra_opts_from_atts( $layout, $raw ), (string) $atts['order'], self::parse_rating_att( $atts['min_rating'] ), self::parse_rating_att( $atts['rating'] ) );
	}

	/**
	 * Resolve the effective review count from shortcode attributes. `max_reviews`
	 * is the user-facing alias; when it's omitted (or non-numeric) we fall back to
	 * the legacy `limit` attribute / saved default. The final value is clamped to
	 * a sane range inside render_with_atts().
	 *
	 * @param array<string,mixed> $atts Parsed shortcode attributes.
	 */
	protected static function resolve_limit( array $atts ): int {
		$max = $atts['max_reviews'] ?? '';
		if ( '' !== (string) $max && is_numeric( $max ) ) {
			return (int) $max;
		}
		return (int) ( $atts['limit'] ?? 3 );
	}

	protected static function clamp_columns( $columns ): int {
		return max( 1, min( 4, (int) $columns ) );
	}

	/**
	 * Collect extra, layout-specific options from the raw (unparsed) shortcode
	 * attributes. The core shortcode only knows about layout/template/limit/columns;
	 * add-ons that register their own layout (e.g. the Pro Slider, with `autoplay`,
	 * `speed`, `space_between`, `navigation`, `pagination`) read their own attributes
	 * off `$raw` here. The result is threaded through to `bdrvw_gr_render_layout` as
	 * its `$extra` argument. Layouts with no extras just get an empty array.
	 *
	 * @param string              $layout Resolved layout slug.
	 * @param array<string,mixed> $raw    Raw shortcode attributes as authored by the user.
	 * @return array<string,mixed>
	 */
	protected static function extra_opts_from_atts( string $layout, array $raw ): array {
		/**
		 * Filter the extra per-layout options parsed from the raw shortcode atts.
		 *
		 * @param array<string,mixed> $extra  Extra options (empty by default).
		 * @param string              $layout Resolved layout slug.
		 * @param array<string,mixed> $raw    Raw shortcode attributes.
		 */
		return (array) apply_filters( 'bdrvw_gr_shortcode_extra_opts', array(), self::normalize_layout( $layout ), $raw );
	}

	/**
	 * Shared body used by both shortcode forms.
	 */
	protected function render_with_atts( string $layout, string $template, int $limit, int $columns = 3, array $extra = array(), string $order = '', ?int $min_rating = null, ?int $exact_rating = null ): string {
		
		Bdrvw_Module::ensure_frontend_assets();

		$layout   = self::normalize_layout( $layout );
		$template = self::normalize_template( $template );

		$limit    = max( 1, min( Bdrvw_Client::MAX_STORED_REVIEWS, $limit ) );
		$columns  = self::clamp_columns( $columns );

		if ( 'popup' === $layout ) {
			$limit = max( $limit, 10 );
		}

		( new Bdrvw_Client( $this->settings ) )->refresh_if_stale();

		$business = self::business_payload( $this->settings );
		$reviews  = self::reviews_payload( $this->settings, $limit, $order, $min_rating, $exact_rating );

		return self::render( $layout, $template, $business, $reviews, false, $columns, $extra );
	}

	
	/**
	 * Resolve a `style="styleN"` attribute to a template slug. N is the per-layout
	 * serial position (1..N) in the chooser, not the global template number:
	 * layouts that drop some styles renumber the rest with no gaps, so e.g.
	 * "style3" on the List layout (which skips template_3) means the 3rd style
	 * List offers — template_4 — and NOT template_3. This keeps the shortcode, the
	 * chooser label and the rendered design in lock-step.
	 *
	 * @param string $style  The `style` attribute value (e.g. "style3").
	 * @param string $layout Layout the shortcode renders in.
	 */
	protected function template_from_style( string $style, string $layout = 'grid' ): string {
		$style = strtolower( trim( $style ) );

		if ( preg_match( '/(\d+)/', $style, $m ) ) {
			$offered = Bdrvw_SettingsRenderer::templates_for_layout( self::normalize_layout( $layout ) );
			$n       = (int) $m[1];
			if ( $n >= 1 && $n <= count( $offered ) ) {
				return self::normalize_template( (string) $offered[ $n - 1 ] );
			}
		}
		return 'template_1';
	}

	/**
	 * Resolve a `style="styleN"` attribute against the *full* canonical style list
	 * (including premium add-on styles), so a saved shortcode still points at the
	 * right premium slug after the add-on is deactivated. Used only to detect a
	 * premium-locked request; the actual render uses template_from_style().
	 *
	 * @param string $style  The `style` attribute value (e.g. "style7").
	 * @param string $layout Layout the shortcode renders in.
	 */
	protected function template_from_style_full( string $style, string $layout ): string {
		if ( preg_match( '/(\d+)/', $style, $m ) ) {
			$offered = Bdrvw_SettingsRenderer::all_templates_for_layout( self::normalize_layout( $layout ) );
			$n       = (int) $m[1];
			if ( $n >= 1 && $n <= count( $offered ) ) {
				return (string) $offered[ $n - 1 ];
			}
		}
		return 'template_1';
	}

	/**
	 * Inverse of template_from_style — the per-layout serial `style` number for a
	 * saved template, used to seed the default `style` attribute. Falls back to
	 * style1 when the saved template isn't offered by the layout.
	 *
	 * @param string $template Saved template slug.
	 * @param string $layout   Layout the shortcode renders in.
	 */
	protected function style_from_template( string $template, string $layout = 'grid' ): string {
		$num = Bdrvw_SettingsRenderer::style_number_for_layout( self::normalize_layout( $layout ), self::normalize_template( $template ) );
		return 'style' . ( $num > 0 ? $num : 1 );
	}

	
	const PREVIEW_LIMIT = 6;

	const PREVIEW_SHOW = 3;

	/**
	 * Render the preview block for the admin pane. Always uses connected data
	 * when available, otherwise falls back to a demo dataset so admins can see
	 * what each layout/template looks like before connecting.
	 */
	public static function render_preview( Bdrvw_Settings $settings, string $layout, string $template, ?int $columns = null ): string {
		$layout   = self::normalize_layout( $layout );
		$template = self::normalize_template( $template );

		$gr      = (array) $settings->get( 'google_reviews', array() );
		$columns = self::clamp_columns( null === $columns ? (int) ( $gr['columns'] ?? 3 ) : $columns );
		$has_data = ! empty( $gr['reviews_cache'] ) && '' !== (string) ( $gr['place_id'] ?? '' );

		if ( $has_data ) {
			$business = self::business_payload( $settings );
			$reviews  = self::reviews_payload( $settings, self::PREVIEW_LIMIT );
		} else {
			$client   = new Bdrvw_Client( $settings );
			$demo     = $client->demo_details( 'preview-demo' );
			$business = array(
				'name'     => (string) $demo['name'],
				'address'  => (string) $demo['address'],
				'rating'   => (float) $demo['rating'],
				'total'    => (int) $demo['total'],
				'url'      => (string) $demo['url'],
				'icon'     => (string) ( $demo['icon'] ?? '' ),
				'place_id' => (string) ( $demo['place_id'] ?? '' ),
				'is_demo'  => true,
			);
			$reviews = array_slice( (array) $demo['reviews'], 0, self::PREVIEW_LIMIT );
		}

		return self::render( $layout, $template, $business, $reviews, true, $columns );
	}

	/**
	 * Layout + template dispatcher. Keeps each template tiny by sharing the
	 * card markup; only the wrapper / arrangement differs.
	 *
	 * @param array<string,mixed>              $business Business info.
	 * @param array<int,array<string,mixed>>   $reviews  Review rows.
	 * @param bool                             $static   Admin-preview mode: render
	 *                                                   the slider as a non-Swiper strip.
	 */
	public static function render( string $layout, string $template, array $business, array $reviews, bool $static = false, int $columns = 3, array $extra = array() ): string {
		$layout   = self::normalize_layout( $layout );
		$template = self::normalize_template( $template );
		$columns  = self::clamp_columns( $columns );

		if ( empty( $reviews ) ) {
			/**
			 * Filter the message shown when there are no Google reviews to display.
			 *
			 * @param string $message Empty-state message.
			 */
			$empty = (string) apply_filters( 'bdrvw_gr_empty_message', __( 'No Google reviews to display yet.', 'boldreview' ) );

			return '<div class="bdrvw-google bdrvw-google--empty">' . esc_html( $empty ) . '</div>';
		}

		if ( $static ) {
			$preview_opts = self::display_opts();
			if ( ! empty( $preview_opts['hide_no_comment'] ) ) {
				$reviews = array_values(
					array_filter(
						$reviews,
						static function ( $r ) {
							return is_array( $r ) && '' !== trim( (string) ( $r['content'] ?? '' ) );
						}
					)
				);
			}

			$reviews = array_slice( $reviews, 0, self::PREVIEW_SHOW );
		}

		$wrapper_class = sprintf(
			'bdrvw-google bdrvw-google--%s bdrvw-google--%s bdrvw-google--%s-%s',
			esc_attr( $layout ),
			esc_attr( $template ),
			esc_attr( $layout ),
			esc_attr( $template )
		);


		$skin       = self::template_skin( $template );
		$skin_class = '' !== $skin ? 'bdrvw-google--skin-' . $skin : '';

		if ( 'popup' !== $layout && '' !== $skin_class ) {
			$wrapper_class .= ' ' . $skin_class;
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>" data-layout="<?php echo esc_attr( $layout ); ?>" data-template="<?php echo esc_attr( $template ); ?>">
			<?php
			
			switch ( $layout ) {
				case 'list':
					self::render_list_layout( $reviews, $template );
					break;
				case 'sidebar':
					self::render_sidebar( $reviews, $template, $business, $static );
					break;
				case 'popup':
					self::render_popup( $reviews, $template, $business, $skin_class );
					break;
				case 'grid':
					self::render_grid( $reviews, $template, $columns );
					break;
				default:
					/**
					 * Render a layout this build doesn't ship itself. Add-ons that
					 * register a new layout (via `bdrvw_gr_allowed_layouts`) return its
					 * markup here; an empty string falls back to the grid layout.
					 *
					 * @param string                         $html     Markup to output ('' to fall back).
					 * @param string                         $layout   Layout slug.
					 * @param array<int,array<string,mixed>> $reviews  Review rows.
					 * @param string                         $template Template slug.
					 * @param int                            $columns  Cards per row / slides per view.
					 * @param array<string,mixed>            $business Business payload.
					 * @param bool                           $static   Admin-preview mode.
					 * @param array<string,int>              $extra    Extra options parsed from
					 *                                                 shortcode atts (e.g. slider
					 *                                                 `autoplay`/`speed`).
					 */
					$custom = (string) apply_filters( 'bdrvw_gr_render_layout', '', $layout, $reviews, $template, $columns, $business, $static, $extra );
					if ( '' !== $custom ) {
						echo wp_kses( $custom, bdrvw_allowed_html() );
					} else {
						self::render_grid( $reviews, $template, $columns );
					}
					break;
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Layout: "list" — light vertical feed with simple cards (avatar + name +
	 * stars + relative date + body + Read more). Matches the "list reference"
	 * the admin briefed: clean, no card border, just hairline divider.
	 */
	protected static function render_list_layout( array $reviews, string $template ): void {
		?>
		<div class="bdrvw-google__grid-wrap bdrvw-google__list-wrap">
			<div class="bdrvw-google__grid bdrvw-google__list">
				<?php foreach ( $reviews as $r ) : ?>
					<?php echo self::grid_card( $r, $template ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Layout: "grid" — responsive cream-card grid matching the reference
	 * mockup: soft `#f5f5f5` cards, Google "G" pinned top-right, avatar +
	 * name + relative date, stars + verified check, body text with optional
	 * "Read more" toggle when content is long.
	 *
	 * DOM mirrors the Trustindex-style structure (inner wrapper + dedicated
	 * profile-img / profile-details blocks) so the same CSS hooks line up.
	 */
	protected static function render_grid( array $reviews, string $template, int $columns = 3 ): void {
		$cols = self::clamp_columns( $columns );
		
		$grid_style = sprintf( '--brg-cols:%d;--brg-cols-md:%d', $cols, min( $cols, 2 ) );
		?>
		<div class="bdrvw-google__grid-wrap">
			<div class="bdrvw-google__grid" style="<?php echo esc_attr( $grid_style ); ?>">
				<?php foreach ( $reviews as $r ) : ?>
					<?php echo self::grid_card( $r, $template ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Single review card for the "grid" layout — white card, verified check,
	 * Google "G" pinned top-right.
	 *
	 * @param array<string,mixed> $r        Review row.
	 * @param string              $template Template slug (template_1 / template_2).
	 *                                      Style 2 reorders the same card into a
	 *                                      quote-first layout via a modifier class.
	 *
	 * Public so an add-on that registers a new layout (via `bdrvw_gr_render_layout`)
	 * can build its slides from the exact same card markup without duplicating it.
	 */
	public static function grid_card( array $r, string $template = 'template_1' ): string {
		$opts = self::display_opts();
		if ( ! empty( $opts['hide_no_comment'] ) && '' === trim( (string) ( $r['content'] ?? '' ) ) ) {
			return '';
		}
		$template = self::normalize_template( $template );
		$is_t2    = 'template_2' === $template;
		$author = (string) ( $r['author_name'] ?? '' );
		$avatar = (string) ( $r['avatar'] ?? '' );
		$rating = (float) ( $r['rating'] ?? 0 );
		$time   = self::review_date( $r );
		$body   = (string) ( $r['content'] ?? '' );
		$reply  = (string) ( $r['reply'] ?? '' );

		$is_long   = mb_strlen( $body ) > 180;
		$text_mode = self::text_display_mode();

		$rid       = substr( md5( $author . '|' . $body ), 0, 12 );
		$base_like = 11 + ( hexdec( substr( md5( $rid ), 0, 4 ) ) % 230 );

		$card_class = 'bdrvw-google__grid-card' . ( $is_t2 ? ' bdrvw-google__grid-card--template-2' : '' );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $card_class ); ?>" data-empty="<?php echo '' === trim( $body ) ? '1' : '0'; ?>" data-template="<?php echo esc_attr( $template ); ?>">
			<div class="bdrvw-google__grid-inner">
				<?php if ( $is_t2 ) : ?>
					<span class="bdrvw-google__grid-quote" aria-hidden="true">&ldquo;</span>
				<?php endif; ?>
				<div class="bdrvw-google__grid-head">
					
					<?php if ( ! empty( $opts['show_reviewer_pic'] ) ) : ?>
						<div class="bdrvw-google__grid-profile-img">
							<?php if ( '' !== $avatar ) : ?>
								<img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: reviewer name. */ __( '%s profile picture', 'boldreview' ), $author ) ); ?>" loading="lazy" width="40" height="40" referrerpolicy="no-referrer" />
							<?php elseif ( '' !== $author ) : ?>
								<span class="bdrvw-google__avatar bdrvw-google__avatar--initial" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $author, 0, 1 ) ) ); ?></span>
							<?php else : ?>
								<span class="bdrvw-google__avatar bdrvw-google__avatar--placeholder" aria-hidden="true"><?php echo wp_kses( self::avatar_placeholder_svg(), bdrvw_allowed_html() ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div class="bdrvw-google__grid-profile-details">
						<div class="bdrvw-google__grid-name"><?php echo esc_html( $author ); ?></div>
						<?php if ( '' !== $time ) : ?>
							<div class="bdrvw-google__grid-date"><?php echo esc_html( $time ); ?></div>
						<?php endif; ?>
					</div>

					<?php /* Google "G" badge — gated by the "Show platform logos" toggle. */ ?>
					<?php if ( ! empty( $opts['show_platform_logo'] ) ) : ?>
						<div class="bdrvw-google__grid-platform" aria-label="<?php esc_attr_e( 'Posted on Google', 'boldreview' ); ?>">
							<?php echo wp_kses( self::google_g_svg(), bdrvw_allowed_html() ); ?>
						</div>
					<?php endif; ?>
				</div>

				<?php
				$show_stars    = ! empty( $opts['show_platform_stars'] );
				$show_verified = ! empty( $opts['show_verified'] );
				?>
				<?php if ( $show_stars || $show_verified ) : ?>
					<span class="bdrvw-google__grid-stars">
						<?php if ( $show_stars ) : ?>
							<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
							<?php if ( empty( $opts['hide_rating_text'] ) ) : ?>
								<span class="bdrvw-google__grid-rating-text"><?php echo esc_html( sprintf( /* translators: %s: rating value, e.g. 4.6. */ __( '%s out of 5', 'boldreview' ), number_format_i18n( $rating, 1 ) ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( $show_verified ) : ?>
							<span class="bdrvw-google__grid-verified" aria-label="<?php esc_attr_e( 'Verified Google review', 'boldreview' ); ?>"><?php echo wp_kses( self::verified_svg(), bdrvw_allowed_html() ); ?></span>
						<?php endif; ?>
					</span>
				<?php endif; ?>

				<?php
				if ( '' !== $body ) :
					$truncatable = ( 'truncate' === $text_mode && $is_long );
					$tc          = 'bdrvw-google__grid-text-container';
					if ( $truncatable ) { $tc .= ' is-truncatable'; }
					if ( 'scroll' === $text_mode ) { $tc .= ' is-scroll'; }
					?>
					<div class="<?php echo esc_attr( $tc ); ?>"<?php echo $truncatable ? ' data-bdrvw-truncate' : ''; ?>><?php echo esc_html( $body ); ?></div>
					<?php if ( $truncatable ) : ?>
						<span class="bdrvw-google__grid-read-more" data-bdrvw-toggle role="button" tabindex="0" aria-expanded="false">
							<span data-bdrvw-label-more><?php echo esc_html( (string) apply_filters( 'bdrvw_gr_read_more_label', __( 'Read more', 'boldreview' ) ) ); ?></span>
							<span data-bdrvw-label-less hidden><?php echo esc_html( (string) apply_filters( 'bdrvw_gr_read_less_label', __( 'Hide', 'boldreview' ) ) ); ?></span>
						</span>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( ! empty( $opts['show_reply'] ) && '' !== $reply ) : ?>
					<div class="bdrvw-google__grid-reply">
						<strong><?php echo esc_html( (string) apply_filters( 'bdrvw_gr_owner_reply_label', __( 'Owner reply', 'boldreview' ) ) ); ?></strong>
						<p><?php echo esc_html( $reply ); ?></p>
					</div>
				<?php endif; ?>

				<?php
				$profile   = (string) ( $r['profile_url'] ?? '' );
				$share_url = (string) ( $r['review_url'] ?? '' );
				if ( '' === $share_url ) {
					$pid = self::gr_setting( 'place_id', '' );
					if ( '' !== $pid && 0 !== strpos( $pid, 'demo-' ) && 0 !== strpos( $pid, 'preview' ) ) {
						$share_url = 'https://search.google.com/local/reviews?placeid=' . rawurlencode( $pid );
					}
				}
				if ( '' === $share_url ) {
					$share_url = $profile;
				}
				/* translators: 1: reviewer name, 2: review text. */
				$share_txt = '' !== $author ? sprintf( __( '%1$s reviewed on Google: %2$s', 'boldreview' ), $author, $body ) : $body;
				?>
				<div class="bdrvw-google__grid-actions">
					<div class="bdrvw-google__grid-actions-group">
						<button type="button" class="bdrvw-google__grid-like" data-bdrvw-like data-review-id="<?php echo esc_attr( $rid ); ?>" data-base="<?php echo esc_attr( (string) $base_like ); ?>" aria-pressed="false" aria-label="<?php esc_attr_e( 'Like this review', 'boldreview' ); ?>">
							<?php echo wp_kses( self::heart_svg(), bdrvw_allowed_html() ); ?>
							<span class="bdrvw-google__grid-like-count"><?php echo esc_html( number_format_i18n( $base_like ) ); ?></span>
						</button>
						<button type="button" class="bdrvw-google__grid-share" data-bdrvw-share data-share-url="<?php echo esc_url( $share_url ); ?>" data-share-text="<?php echo esc_attr( $share_txt ); ?>" aria-label="<?php esc_attr_e( 'Share this review', 'boldreview' ); ?>">
							<?php echo wp_kses( self::share_svg(), bdrvw_allowed_html() ); ?>
						</button>
					</div>
					<?php if ( '' !== $share_url ) : ?>
						<a class="bdrvw-google__grid-google" href="<?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo wp_kses( self::google_g_svg(), bdrvw_allowed_html() ); ?><span><?php esc_html_e( 'Google', 'boldreview' ); ?></span>
						</a>
					<?php else : ?>
						<span class="bdrvw-google__grid-google">
							<?php echo wp_kses( self::google_g_svg(), bdrvw_allowed_html() ); ?><span><?php esc_html_e( 'Google', 'boldreview' ); ?></span>
						</span>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Resolve display-tab toggles + sensible defaults. Used by every card.
	 *
	 * Public so an add-on that registers a new layout can read the same toggles.
	 *
	 * @return array<string,int>
	 */
	public static function display_opts(): array {
		$all = get_option( Bdrvw_Settings::OPTION_KEY, array() );
		$gr  = is_array( $all ) && isset( $all['google_reviews'] ) && is_array( $all['google_reviews'] )
			? $all['google_reviews']
			: array();
		$defaults = array(
			'hide_no_comment'    => 0,
			'hide_rating_text'   => 0,
			'show_reply'         => 1,
			'show_verified'      => 1,
			'show_arrows'        => 1,
			'show_reviewer_pic'  => 1,
			'show_platform_logo' => 1,
			'show_platform_stars'=> 1,
		);
		$out = array();
		foreach ( $defaults as $k => $v ) {
			$out[ $k ] = (int) ( $gr[ $k ] ?? $v );
		}
		return $out;
	}

	/** Read a single google_reviews string setting with a fallback. */
	protected static function gr_setting( string $key, string $default ): string {
		$all = get_option( Bdrvw_Settings::OPTION_KEY, array() );
		$gr  = is_array( $all ) && isset( $all['google_reviews'] ) && is_array( $all['google_reviews'] )
			? $all['google_reviews']
			: array();
		$val = isset( $gr[ $key ] ) ? (string) $gr[ $key ] : '';
		return '' !== $val ? $val : $default;
	}

	/** Review-text display mode: 'truncate' | 'full' | 'scroll'. */
	protected static function text_display_mode(): string {
		$mode = self::gr_setting( 'review_text', 'truncate' );
		return in_array( $mode, array( 'truncate', 'full', 'scroll' ), true ) ? $mode : 'truncate';
	}

	/**
	 * Resolve a review's display date per the "Select date format" setting.
	 * 'relative' keeps Google's "x ago" string; any other value formats the
	 * review's posted timestamp (falling back to the relative string if the
	 * timestamp is missing). 'wp' uses the site's configured date format.
	 *
	 * @param array<string,mixed> $r Review row.
	 */
	protected static function review_date( array $r ): string {
		$relative = (string) ( $r['relative_time'] ?? '' );
		$fmt      = self::gr_setting( 'date_format', 'relative' );
		if ( 'relative' === $fmt ) {
			return $relative;
		}
		$ts = (int) ( $r['time'] ?? 0 );
		if ( $ts <= 0 ) {
			return $relative;
		}
		$format = ( 'wp' === $fmt ) ? (string) get_option( 'date_format', 'F j, Y' ) : $fmt;
		return (string) wp_date( $format, $ts );
	}

	protected static function google_g_svg(): string {
		return '<svg viewBox="0 0 24 24" width="22" height="22" xmlns="http://www.w3.org/2000/svg">'
			. '<path fill="#4285F4" d="M22.5 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.22-4.74 3.22-8.32z"/>'
			. '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/>'
			. '<path fill="#FBBC05" d="M5.84 14.1A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.06H2.18A11 11 0 0 0 1 12c0 1.77.43 3.45 1.18 4.94l3.66-2.84z"/>'
			. '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.65l3.15-3.15C17.46 2.15 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>'
			. '</svg>';
	}

	protected static function verified_svg(): string {
		return '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">'
			. '<circle cx="12" cy="12" r="10" fill="#1d9bf0"/>'
			. '<path d="M7.5 12.5l3 3 6-6" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	protected static function heart_svg(): string {
		return '<span class="dashicons dashicons-heart" aria-hidden="true"></span>';
	}

	
	protected static function share_svg(): string {
		return '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>'
			. '<line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/>'
			. '</svg>';
	}

	/** Generic person silhouette used when a reviewer has no avatar AND no name. */
	protected static function avatar_placeholder_svg(): string {
		return '<svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true">'
			. '<circle cx="20" cy="20" r="20" fill="#e5e7eb"/>'
			. '<circle cx="20" cy="16" r="6" fill="#9ca3af"/>'
			. '<path d="M8 34c0-6.6 5.4-12 12-12s12 5.4 12 12" fill="#9ca3af"/>'
			. '</svg>';
	}

	
	protected static function render_sidebar( array $reviews, string $template, array $business, bool $static = false ): void {
		$template = self::normalize_template( $template );
		
		if ( 'template_3' === $template || '' !== self::template_skin( $template ) ) {
			self::render_sidebar_profile( $reviews, $business, $static, true );
			return;
		}
		if ( 'template_2' === $template ) {
			self::render_sidebar_slider( $reviews, $business, $static );
			return;
		}
		?>
		<div class="bdrvw-google__grid-wrap bdrvw-google__sidebar-wrap">
			<div class="bdrvw-google__grid bdrvw-google__sidebar">
				<?php foreach ( $reviews as $r ) : ?>
					<?php echo self::grid_card( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		unset( $business );
	}

	/**
	 * Sidebar + Style 3 — a Google-style business profile header (logo, name,
	 * average stars, "<n> Google reviews", and a "Write a review" button) sitting
	 * above a stacked list of review cards. The button is a direct deep link to
	 * Google's own review page for the connected place (opens in a new tab) —
	 * the same approach review widgets like Trustindex use, since a third-party
	 * site can't submit a Google review on the visitor's behalf.
	 *
	 * @param array<int,array<string,mixed>> $reviews     Review rows.
	 * @param array<string,mixed>            $business    Business info (name + rating + total + icon).
	 * @param bool                           $static      Admin-preview mode (no behavioural change here).
	 * @param bool                           $show_header Whether to render the profile header + "Write a
	 *                                                    review" button (Style 3) or the cards only (Style 4).
	 */
	protected static function render_sidebar_profile( array $reviews, array $business, bool $static = false, bool $show_header = true ): void {
		$name      = (string) ( $business['name'] ?? '' );
		$rating    = max( 0.0, min( 5.0, (float) ( $business['rating'] ?? 0 ) ) );
		$total     = (int) ( $business['total'] ?? 0 );
		$icon      = (string) ( $business['icon'] ?? '' );
		$write_url = self::google_write_review_url( $business );

		if ( '' === $name ) {
			/**
			 * Filter the fallback business name used when none is connected/saved.
			 *
			 * @param string $name Fallback business name.
			 */
			$name = (string) apply_filters( 'bdrvw_gr_business_fallback_name', __( 'Our business', 'boldreview' ) );
		}
		unset( $static );

		$wrap_class = 'bdrvw-google__sbprofile' . ( $show_header ? '' : ' bdrvw-google__sbprofile--cards-only' );
		?>
		<div class="<?php echo esc_attr( $wrap_class ); ?>">
			<?php if ( $show_header ) : ?>
				<header class="bdrvw-google__sbprofile-head">
					<div class="bdrvw-google__sbprofile-logo" aria-hidden="true">
						<?php if ( '' !== $icon ) : ?>
							<img src="<?php echo esc_url( $icon ); ?>" alt="" referrerpolicy="no-referrer" width="56" height="56" />
						<?php else : ?>
							<span class="bdrvw-google__sbprofile-logo-initial"><?php echo esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="bdrvw-google__sbprofile-meta">
						<strong class="bdrvw-google__sbprofile-name"><?php echo esc_html( $name ); ?></strong>
						<div class="bdrvw-google__sbprofile-rate">
							<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
						</div>
						<div class="bdrvw-google__sbprofile-count">
							<span class="bdrvw-google__sbprofile-g" aria-hidden="true">
								<span style="color:#4285F4">G</span><span style="color:#EA4335">o</span><span style="color:#FBBC04">o</span><span style="color:#4285F4">g</span><span style="color:#34A853">l</span><span style="color:#EA4335">e</span>
							</span>
							<?php
							printf(
								/* translators: %s: total number of reviews. */
								esc_html__( '%s Google reviews', 'boldreview' ),
								'<span>' . esc_html( number_format_i18n( $total ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							);
							?>
						</div>
					</div>
				</header>

				<a class="bdrvw-google__sbprofile-write" href="<?php echo esc_url( $write_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Write a review on Google', 'boldreview' ); ?>">
					<?php echo esc_html( (string) apply_filters( 'bdrvw_gr_write_review_label', __( 'Write a review', 'boldreview' ) ) ); ?>
				</a>
			<?php endif; ?>

			<div class="bdrvw-google__sbprofile-list">
				<?php foreach ( $reviews as $r ) : ?>
					<?php echo self::grid_card( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Layout: "popup".
	 *
	 * Renders a compact, clickable review badge (business logo + name + stars +
	 * "<n> Google reviews"). Clicking it slides a right-hand offcanvas panel into
	 * view that lists the reviews. The selected style/skin is scoped to the badge
	 * only ($skin_class lives on the trigger), so the offcanvas keeps a single
	 * universal design across every style.
	 *
	 * @param array<int,array<string,mixed>> $reviews    Review rows.
	 * @param string                         $template   Selected style — picks the badge layout variant.
	 * @param array<string,mixed>            $business   Business info.
	 * @param string                         $skin_class Skin class applied to the badge ('' when none/locked).
	 */
	protected static function render_popup( array $reviews, string $template, array $business, string $skin_class = '' ): void {
		$name   = (string) ( $business['name'] ?? '' );
		$rating = max( 0.0, min( 5.0, (float) ( $business['rating'] ?? 0 ) ) );
		$total  = (int) ( $business['total'] ?? 0 );
		$icon   = (string) ( $business['icon'] ?? '' );
		if ( '' === $name ) {
			/**
			 * Filter the fallback business name used when none is connected/saved.
			 *
			 * @param string $name Fallback business name.
			 */
			$name = (string) apply_filters( 'bdrvw_gr_business_fallback_name', __( 'Our business', 'boldreview' ) );
		}

		$variants = array(
			'template_1' => 'classic',
			'template_2' => 'centered',
			'template_3' => 'compact',
			'template_4' => 'rating',
		);
		$variant = $variants[ $template ] ?? 'classic';

		$logo = '' !== $icon
			? '<img src="' . esc_url( $icon ) . '" alt="" referrerpolicy="no-referrer" width="48" height="48" />'
			: '<span class="bdrvw-google__popup-logo-initial">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>';

		$count_html = sprintf(
			/* translators: %s: total number of reviews. */
			esc_html__( '%s Google reviews', 'boldreview' ),
			'<span>' . esc_html( number_format_i18n( $total ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		$badge_class = 'bdrvw-google__popup-badge' . ( '' !== $skin_class ? ' ' . $skin_class : '' );
		$card_class  = 'bdrvw-google__grid-card bdrvw-google__popup-card bdrvw-google__popup-card--' . $variant;
		?>
		<div class="bdrvw-google__popup">
			<button type="button" class="<?php echo esc_attr( $badge_class ); ?>" data-bdrvw-gr-popup-open aria-haspopup="dialog">
				<span class="<?php echo esc_attr( $card_class ); ?>">
					<span class="bdrvw-google__popup-card-inner">
						<span class="bdrvw-google__popup-logo" aria-hidden="true"><?php echo wp_kses( $logo, bdrvw_allowed_html() ); ?></span>
						<span class="bdrvw-google__popup-meta">
							<strong class="bdrvw-google__grid-name"><?php echo esc_html( $name ); ?></strong>
							<span class="bdrvw-google__popup-rate-row">
								<span class="bdrvw-google__popup-rating-num"><?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?></span>
								<span class="bdrvw-google__popup-rate">
									<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
								</span>
							</span>
							<span class="bdrvw-google__popup-count"><?php echo wp_kses( $count_html, bdrvw_allowed_html() ); ?></span>
						</span>
					</span>
				</span>
			</button>

			<div class="bdrvw-google__offcanvas" data-bdrvw-gr-offcanvas>
				<div class="bdrvw-google__offcanvas-overlay" data-bdrvw-gr-popup-close></div>
				<aside class="bdrvw-google__offcanvas-panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: business name. */ __( '%s reviews', 'boldreview' ), $name ) ); ?>">
					<header class="bdrvw-google__offcanvas-head">
						<span class="bdrvw-google__offcanvas-logo" aria-hidden="true"><?php echo wp_kses( $logo, bdrvw_allowed_html() ); ?></span>
						<div class="bdrvw-google__offcanvas-meta">
							<strong class="bdrvw-google__offcanvas-name"><?php echo esc_html( $name ); ?></strong>
							<span class="bdrvw-google__offcanvas-rate">
								<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
							</span>
							<span class="bdrvw-google__offcanvas-count">
								<?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?> &middot; <?php echo wp_kses( $count_html, bdrvw_allowed_html() ); ?>
							</span>
						</div>
						<button type="button" class="bdrvw-google__offcanvas-close" data-bdrvw-gr-popup-close aria-label="<?php esc_attr_e( 'Close reviews', 'boldreview' ); ?>">
							<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
						</button>
					</header>
					<div class="bdrvw-google__offcanvas-list">
						<?php foreach ( $reviews as $r ) : ?>
							<?php echo self::grid_card( (array) $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Build the public Google "write a review" deep link for the connected
	 * place. Falls back to the business's Maps URL (or Google Maps itself) when
	 * there's no usable place_id — e.g. the demo dataset used in the preview.
	 *
	 * @param array<string,mixed> $business Business payload.
	 */
	protected static function google_write_review_url( array $business ): string {
		$place_id = (string) ( $business['place_id'] ?? '' );
		$is_demo  = ! empty( $business['is_demo'] )
			|| '' === $place_id
			|| 0 === strpos( $place_id, 'demo-' )
			|| 0 === strpos( $place_id, 'preview' );

		if ( ! $is_demo ) {
			return 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id );
		}

		$url = (string) ( $business['url'] ?? '' );
		return '' !== $url ? $url : 'https://maps.google.com/';
	}

	/**
	 * Sidebar + Style 2 — single-column review slider with a business-rating
	 * header. On the frontend it's a real Swiper (one review per view, prev/next
	 * arrows); in the admin preview ($static) it shows one review with decorative
	 * arrows so the pane never auto-slides.
	 *
	 * @param array<int,array<string,mixed>> $reviews  Review rows.
	 * @param array<string,mixed>            $business Business info (rating + total).
	 */
	protected static function render_sidebar_slider( array $reviews, array $business, bool $static = false ): void {
		$rating = max( 0.0, min( 5.0, (float) ( $business['rating'] ?? 0 ) ) );
		$total  = (int) ( $business['total'] ?? 0 );
		
		$with_text = array_values(
			array_filter(
				$reviews,
				static function ( $r ) {
					return is_array( $r ) && '' !== trim( (string) ( $r['content'] ?? '' ) );
				}
			)
		);
		$rows = $static ? array_slice( $with_text, 0, 1 ) : $with_text;
		?>
		<div class="bdrvw-google__sbslider">
			<div class="bdrvw-google__sbslider-head">
				<strong class="bdrvw-google__sbslider-label"><?php echo esc_html( self::rating_label( $rating ) ); ?></strong>
				<span class="bdrvw-google__sbslider-sub">
					<?php
					printf(
						/* translators: %s: total number of reviews. */
						esc_html__( 'Based on %s reviews', 'boldreview' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<div class="bdrvw-google__sbslider-rate">
					<span class="bdrvw-google__sbslider-google" aria-label="Google">
						<span style="color:#4285F4">G</span><span style="color:#EA4335">o</span><span style="color:#FBBC04">o</span><span style="color:#4285F4">g</span><span style="color:#34A853">l</span><span style="color:#EA4335">e</span>
					</span>
					<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
				</div>
			</div>
			<div class="bdrvw-google__sbslider-divider"></div>

			<?php if ( $static ) : ?>
				<div class="bdrvw-google__sbslider-static">
					<?php foreach ( $rows as $r ) : ?>
						<?php self::sbslider_slide( (array) $r ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="swiper bdrvw-google__sbslider-swiper" data-bdrvw-gr-slider data-bdrvw-gr-single>
					<div class="swiper-wrapper">
						<?php foreach ( $rows as $r ) : ?>
							<div class="swiper-slide"><?php self::sbslider_slide( (array) $r ); ?></div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="bdrvw-google__sbslider-nav">
				<button type="button" class="swiper-button-prev" aria-label="<?php esc_attr_e( 'Previous review', 'boldreview' ); ?>"<?php echo $static ? ' aria-hidden="true" tabindex="-1"' : ''; ?>>
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="#5f6368" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
				<button type="button" class="swiper-button-next" aria-label="<?php esc_attr_e( 'Next review', 'boldreview' ); ?>"<?php echo $static ? ' aria-hidden="true" tabindex="-1"' : ''; ?>>
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="#5f6368" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
			</div>
		</div>
		<?php
	}

	/** One slide body for the sidebar slider — review text + reviewer foot. */
	protected static function sbslider_slide( array $r ): void {
		$author  = (string) ( $r['author_name'] ?? '' );
		$avatar  = (string) ( $r['avatar'] ?? '' );
		$time    = self::review_date( $r );
		$body    = (string) ( $r['content'] ?? '' );
		
		$is_long     = mb_strlen( $body ) > 150;
		$text_mode   = self::text_display_mode();
		$truncatable = ( 'truncate' === $text_mode && $is_long );
		$tc          = 'bdrvw-google__sbslide-text';
		if ( $truncatable ) { $tc .= ' is-truncatable'; }
		if ( 'scroll' === $text_mode ) { $tc .= ' is-scroll'; }
		?>
		<div class="bdrvw-google__sbslide">
			<div class="<?php echo esc_attr( $tc ); ?>"<?php echo $truncatable ? ' data-bdrvw-truncate' : ''; ?>><?php echo esc_html( $body ); ?></div>
			<?php if ( $truncatable ) : ?>
				<span class="bdrvw-google__sbslide-more" data-bdrvw-toggle role="button" tabindex="0" aria-expanded="false">
					<span data-bdrvw-label-more><?php echo esc_html( (string) apply_filters( 'bdrvw_gr_read_more_label', __( 'Read more', 'boldreview' ) ) ); ?></span>
					<span data-bdrvw-label-less hidden><?php echo esc_html( (string) apply_filters( 'bdrvw_gr_read_less_label', __( 'Hide', 'boldreview' ) ) ); ?></span>
				</span>
			<?php endif; ?>
			<div class="bdrvw-google__sbslide-foot">
				<?php echo self::avatar_markup( $avatar, $author, 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="bdrvw-google__sbslide-meta">
					<strong><?php echo esc_html( $author ); ?></strong>
					<?php if ( '' !== $time ) : ?>
						<span><?php echo esc_html( $time ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/** Human label for an average rating, e.g. 4.6 → "Excellent rating". */
	protected static function rating_label( float $rating ): string {
		if ( $rating >= 4.5 ) {
			$label = __( 'Excellent rating', 'boldreview' );
		} elseif ( $rating >= 3.5 ) {
			$label = __( 'Very good rating', 'boldreview' );
		} elseif ( $rating >= 2.5 ) {
			$label = __( 'Good rating', 'boldreview' );
		} elseif ( $rating > 0 ) {
			$label = __( 'Fair rating', 'boldreview' );
		} else {
			$label = __( 'Rating', 'boldreview' );
		}

		/**
		 * Filter the human label shown for an average Google rating
		 * (e.g. "Excellent rating").
		 *
		 * @param string $label  Rating label.
		 * @param float  $rating The 0–5 average being described.
		 */
		return (string) apply_filters( 'bdrvw_gr_rating_label', $label, $rating );
	}

	/**
	 * Single review card. Template controls the visual treatment; the data
	 * surface is the same for both templates so admins can switch freely.
	 *
	 * Template 1 — "Card": white card with avatar pill, stars below name.
	 * Template 2 — "Quote": quote-style bubble with avatar bottom-right.
	 */
	public static function card( array $r, string $template ): string {
		$template = self::normalize_template( $template );
		$author   = (string) ( $r['author_name'] ?? '' );
		$avatar   = (string) ( $r['avatar'] ?? '' );
		$rating   = (float) ( $r['rating'] ?? 0 );
		$time     = (string) ( $r['relative_time'] ?? '' );
		$content  = (string) ( $r['content'] ?? '' );

	
		$avatar_html = self::avatar_markup( $avatar, $author, 40 );
		$g_html      = '<span class="bdrvw-google__card-platform" aria-label="' . esc_attr__( 'Posted on Google', 'boldreview' ) . '">' . self::google_g_svg() . '</span>';

		ob_start();
		if ( 'template_2' === $template ) :
			?>
			<article class="bdrvw-google__card bdrvw-google__card--template-2">
				<?php echo wp_kses( $g_html, bdrvw_allowed_html() ); ?>
				<span class="bdrvw-google__quote" aria-hidden="true">&ldquo;</span>
				<div class="bdrvw-google__card-body">
					<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
					<p class="bdrvw-google__content"><?php echo esc_html( $content ); ?></p>
				</div>
				<footer class="bdrvw-google__card-foot">
					<?php echo wp_kses( $avatar_html, bdrvw_allowed_html() ); ?>
					<div>
						<strong class="bdrvw-google__author"><?php echo esc_html( $author ); ?></strong>
						<?php if ( '' !== $time ) : ?>
							<span class="bdrvw-google__time"><?php echo esc_html( $time ); ?></span>
						<?php endif; ?>
					</div>
				</footer>
			</article>
			<?php
		else :
			?>
			<article class="bdrvw-google__card bdrvw-google__card--template-1">
				<?php echo wp_kses( $g_html, bdrvw_allowed_html() ); ?>
				<header class="bdrvw-google__card-head">
					<?php echo wp_kses( $avatar_html, bdrvw_allowed_html() ); ?>
					<div class="bdrvw-google__author-block">
						<strong class="bdrvw-google__author"><?php echo esc_html( $author ); ?></strong>
						<?php echo wp_kses( self::stars_html( $rating ), bdrvw_allowed_html() ); ?>
						<?php if ( '' !== $time ) : ?>
							<span class="bdrvw-google__time"><?php echo esc_html( $time ); ?></span>
						<?php endif; ?>
					</div>
				</header>
				<p class="bdrvw-google__content"><?php echo esc_html( $content ); ?></p>
			</article>
			<?php
		endif;
		return (string) ob_get_clean();
	}

	/**
	 * Build the avatar element with the same 3-tier fallback used by the grid card:
	 *   1. Real image when a URL is set
	 *   2. Coloured initial badge from the author's name
	 *   3. Generic person silhouette SVG when neither is available
	 *
	 * Returned markup is a single inline element ready to drop into card markup.
	 */
	protected static function avatar_markup( string $avatar, string $author, int $size = 40 ): string {
		if ( '' !== $avatar ) {
			return sprintf(
				'<img class="bdrvw-google__avatar" src="%1$s" alt="%2$s" loading="lazy" width="%3$d" height="%3$d" referrerpolicy="no-referrer" />',
				esc_url( $avatar ),
				esc_attr( sprintf( /* translators: %s: reviewer name. */ __( '%s profile picture', 'boldreview' ), $author ) ),
				(int) $size
			);
		}
		if ( '' !== $author ) {
			return '<span class="bdrvw-google__avatar bdrvw-google__avatar--initial" aria-hidden="true">'
				. esc_html( mb_strtoupper( mb_substr( $author, 0, 1 ) ) )
				. '</span>';
		}
		return '<span class="bdrvw-google__avatar bdrvw-google__avatar--placeholder" aria-hidden="true">'
			. self::avatar_placeholder_svg()
			. '</span>';
	}

	/**
	 * Star block. Filled / half / empty unicode glyphs styled via CSS class.
	 */
	public static function stars_html( float $value ): string {
		$value = max( 0.0, min( 5.0, $value ) );
		$full  = (int) floor( $value );
		$half  = ( $value - $full ) >= 0.5;
		$out   = '<span class="bdrvw-google__stars" aria-label="' . esc_attr( sprintf( /* translators: %s: rating value. */ __( '%s out of 5', 'boldreview' ), number_format_i18n( $value, 1 ) ) ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $i <= $full ) {
				$out .= '<span class="bdrvw-google__star is-full">&#9733;</span>';
			} elseif ( $half && $i === $full + 1 ) {
				$out .= '<span class="bdrvw-google__star is-half">&#9733;</span>';
			} else {
				$out .= '<span class="bdrvw-google__star">&#9734;</span>';
			}
		}
		$out .= '</span>';
		return $out;
	}

	/**
	 * Normalise a posted layout slug to a known value.
	 */
	public static function normalize_layout( string $layout ): string {
		/**
		 * Filter the layout slugs this build accepts. Add-ons that introduce a new
		 * layout (and render it through `bdrvw_gr_render_layout`) add their slug here.
		 *
		 * @param array<int,string> $allowed Allowed layout slugs.
		 */
		$allowed = (array) apply_filters( 'bdrvw_gr_allowed_layouts', array( 'grid', 'list', 'sidebar', 'popup' ) );
		return in_array( $layout, $allowed, true ) ? $layout : 'grid';
	}

	/**
	 * Normalise a posted template slug to a known value.
	 */
	public static function normalize_template( string $template ): string {
		if ( in_array( $template, self::valid_templates(), true ) ) {
			return $template;
		}
		return 'template_1';
	}

	/**
	 * Every template slug this build understands — the four base card styles plus
	 * any design-skin templates registered through `template_skins()`.
	 *
	 * @return array<int,string>
	 */
	public static function valid_templates(): array {
		return array_merge(
			array( 'template_1', 'template_2', 'template_3', 'template_4' ),
			array_keys( self::template_skins() )
		);
	}

	/**
	 * Every template slug this design family *knows about*, in canonical order —
	 * the currently-registered ones plus the premium add-on styles (7–11) that an
	 * add-on (BoldReview Pro) registers when it's active.
	 *
	 * When Pro is deactivated its skins drop out of valid_templates(), so a saved
	 * shortcode that points at a premium style would otherwise normalise to
	 * template_1 and silently render the wrong design. Keeping the premium slugs
	 * listed here lets the shortcode recognise them and show an "available in Pro"
	 * notice instead. The union with valid_templates() preserves the canonical
	 * 1→11 order whether or not Pro is loaded.
	 *
	 * @return array<int,string>
	 */
	public static function all_known_templates(): array {
		$premium = (array) apply_filters(
			'bdrvw_gr_premium_templates',
			array( 'template_7', 'template_8', 'template_9', 'template_10', 'template_11' )
		);
		return array_values( array_unique( array_merge( self::valid_templates(), $premium ) ) );
	}

	/**
	 * When a shortcode asks for a template that isn't currently registered but is
	 * a known premium (add-on) style, return that slug — the caller renders an
	 * "available in Pro" notice. Returns '' when the template is available (render
	 * normally) or is unknown/garbage (let normalize_template() fall back).
	 */
	public static function premium_unavailable_template( string $template ): string {
		if ( '' === $template || in_array( $template, self::valid_templates(), true ) ) {
			return '';
		}
		return in_array( $template, self::all_known_templates(), true ) ? $template : '';
	}

	/**
	 * The notice shown in place of the reviews when a shortcode requests a premium
	 * style whose add-on isn't active.
	 */
	public static function premium_template_notice( string $template ): string {
		$msg = (string) apply_filters(
			'bdrvw_gr_premium_template_message',
			__( 'This review template is available in BoldReview Pro. Activate the Pro add-on to display it.', 'boldreview' ),
			$template
		);
		return '<div class="bdrvw-google bdrvw-google--empty bdrvw-google--premium-locked">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * Map of design-skin template slugs → skin slug. Ships the free skins only;
	 * add-ons register additional skinned templates by hooking the filter below.
	 *
	 * @return array<string,string> template slug => skin slug.
	 */
	public static function template_skins(): array {
		$skins = array(
			'template_5' => 'drop-shadow',
			'template_6' => 'light-contrast',
		);

		/**
		 * Filter the design-skin template map. Add-ons append their own
		 * `template_N => skin-slug` entries to introduce new skinned styles.
		 *
		 * @param array<string,string> $skins template slug => skin slug.
		 */
		return (array) apply_filters( 'bdrvw_gr_template_skins', $skins );
	}

	/**
	 * Resolve the design-skin slug a template carries ('' when it's a base style).
	 */
	public static function template_skin( string $template ): string {
		$map = self::template_skins();
		return (string) ( $map[ $template ] ?? '' );
	}

	/**
	 * Build the business header payload from saved settings.
	 *
	 * @return array<string,mixed>
	 */
	protected static function business_payload( Bdrvw_Settings $settings ): array {
		$gr = (array) $settings->get( 'google_reviews', array() );
		return array(
			'name'     => (string) ( $gr['business_name'] ?? '' ),
			'address'  => (string) ( $gr['business_address'] ?? '' ),
			'rating'   => (float) ( $gr['business_rating'] ?? 0 ),
			'total'    => (int) ( $gr['business_total'] ?? 0 ),
			'url'      => (string) ( $gr['business_url'] ?? '' ),
			'icon'     => (string) ( $gr['business_icon'] ?? '' ),
			'place_id' => (string) ( $gr['place_id'] ?? '' ),
			'is_demo'  => ! empty( $gr['is_demo'] ),
		);
	}

	/**
	 * Build the review list payload from cached reviews, applying the
	 * minimum-rating filter + limit.
	 *
	 * The limit is applied AFTER the "hide reviews without a comment" filter so
	 * that `max_reviews`/`limit` counts reviews that will actually render. Without
	 * this, the cap was spent on comment-less rows that the card renderer later
	 * drops, so e.g. `max_reviews="3"` could surface only 1 visible card when two
	 * of the first three cached reviews had no text.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected static function reviews_payload( Bdrvw_Settings $settings, int $limit, string $order = '', ?int $min_rating = null, ?int $exact_rating = null ): array {
		$gr         = (array) $settings->get( 'google_reviews', array() );
		$cache      = (array) ( $gr['reviews_cache'] ?? array() );
		// Shortcode `min_rating` overrides the saved minimum-rating setting.
		$min        = ( null !== $min_rating ) ? $min_rating : (int) ( $gr['min_rating'] ?? 1 );
		$hide_empty = ! empty( $gr['hide_no_comment'] );
		$filtered   = array();
		foreach ( $cache as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$stars = (float) ( $row['rating'] ?? 0 );
			// `rating="N"` keeps only exactly-N-star reviews; otherwise apply the
			// minimum-rating floor (shortcode override or saved setting).
			if ( null !== $exact_rating ) {
				if ( (int) round( $stars ) !== $exact_rating ) {
					continue;
				}
			} elseif ( $stars < $min ) {
				continue;
			}
			if ( $hide_empty && '' === trim( (string) ( $row['content'] ?? '' ) ) ) {
				continue;
			}
			$filtered[] = $row;
		}

		/**
		 * Filter the review list before it's ordered and sliced to the limit.
		 * Add-ons use this to drop reviews — the Pro word filter removes any whose
		 * text contains a blocked word. The free build ships no listener.
		 *
		 * @param array<int,array<string,mixed>> $filtered Surviving review rows.
		 * @param array<string,mixed>            $gr       Google Reviews settings.
		 */
		$filtered = array_values( (array) apply_filters( 'bdrvw_gr_filter_reviews', $filtered, $gr ) );

		$order = self::normalize_order( $order );
		if ( '' !== $order ) {
			usort(
				$filtered,
				static function ( $a, $b ) use ( $order ) {
					$ta = (int) ( $a['time'] ?? 0 );
					$tb = (int) ( $b['time'] ?? 0 );
					return ( 'asc' === $order ) ? $ta <=> $tb : $tb <=> $ta;
				}
			);
		}

		return array_slice( $filtered, 0, $limit );
	}

	/**
	 * Normalize the shortcode `order` attribute to 'asc' | 'desc', or '' when it's
	 * missing/invalid (meaning: keep the API's default cache order).
	 *
	 * @param mixed $order Raw attribute value.
	 */
	protected static function normalize_order( $order ): string {
		$order = strtolower( trim( (string) $order ) );
		return in_array( $order, array( 'asc', 'desc' ), true ) ? $order : '';
	}

	/**
	 * Parse a shortcode rating attribute (`min_rating` / `rating`) to an int in
	 * the 1–5 range, or null when it's missing/non-numeric (meaning: no filter /
	 * fall back to the saved minimum-rating setting).
	 *
	 * @param mixed $value Raw attribute value.
	 */
	protected static function parse_rating_att( $value ): ?int {
		if ( '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		return max( 1, min( 5, (int) $value ) );
	}
}
