<?php
/**
 * Admin tab UI for the Google Reviews module.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Modules\GoogleReviews;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Three-tab admin surface (Connect / Layout / Display) mirroring the tab
 * pattern used by the Collection Review module so the overall plugin UX is
 * consistent across modules.
 */
class Bdrvw_SettingsRenderer {

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
	 * Tab definitions — order is preserved on screen.
	 *
	 * @return array<string,string>
	 */
	protected static function tabs(): array {
		return array(
			'connect' => __( 'Connect Google', 'boldreview' ),
			'layout'  => __( 'Layout', 'boldreview' ),
			'display' => __( 'Display', 'boldreview' ),
			'guide'   => __( 'Usage Guide', 'boldreview' ),
		);
	}

	/**
	 * Layouts surfaced as radio buttons. Order is significant — drives the
	 * visual order on screen and the per-layout shortcode slug.
	 *
	 * @return array<string,array{label:string,description:string,shortcode:string}>
	 */
	public static function layouts(): array {
		$layouts = array(
			'grid'    => array(
				'label'       => __( 'Grid', 'boldreview' ),
				'description' => __( 'Responsive 2–3 column card grid.', 'boldreview' ),
				'shortcode'   => 'bdrvw_google_grid',
			),
			'list'    => array(
				'label'       => __( 'List', 'boldreview' ),
				'description' => __( 'Compact vertical list, great for narrow columns.', 'boldreview' ),
				'shortcode'   => 'bdrvw_google_list',
			),
			'sidebar' => array(
				'label'       => __( 'Sidebar', 'boldreview' ),
				'description' => __( 'Sticky business card on the left, reviews on the right.', 'boldreview' ),
				'shortcode'   => 'bdrvw_google_sidebar',
			),
			'popup'   => array(
				'label'       => __( 'Popup', 'boldreview' ),
				'description' => __( 'A compact review badge that opens the reviews in a slide-in panel.', 'boldreview' ),
				'shortcode'   => 'bdrvw_google_popup',
			),
		);

		/**
		 * Filter the Google Reviews layout definitions. Add-ons hook this to
		 * register additional layouts (each rendered via `bdrvw_gr_render_layout`).
		 *
		 * @param array<string,array{label:string,description:string,shortcode:string}> $layouts Layout map.
		 */
		return (array) apply_filters( 'bdrvw_gr_layouts', $layouts );
	}

	/**
	 * Templates available per layout.
	 *
	 * @return array<string,array{label:string,description:string,style:string}>
	 */
	public static function templates(): array {
		$templates = array(
			'template_1' => array(
				'label'       => __( 'Style 1', 'boldreview' ),
				'description' => __( 'Standard Card', 'boldreview' ),
				'style'       => 'style1',
			),
			'template_2' => array(
				'label'       => __( 'Style 2', 'boldreview' ),
				'description' => __( 'Quote Style', 'boldreview' ),
				'style'       => 'style2',
			),
			'template_3' => array(
				'label'       => __( 'Style 3', 'boldreview' ),
				'description' => __( 'Profile Header', 'boldreview' ),
				'style'       => 'style3',
			),
			'template_4' => array(
				'label'       => __( 'Style 4', 'boldreview' ),
				'description' => __( 'Cards Only', 'boldreview' ),
				'style'       => 'style4',
			),
		);

		$skins = self::skin_meta();
		$n     = count( $templates ) + 1;
		foreach ( Bdrvw_Frontend::template_skins() as $tpl => $skin ) {
			$meta              = (array) ( $skins[ $skin ] ?? array() );
			$templates[ $tpl ] = array(
				'label'       => sprintf( /* translators: %d: style number. */ __( 'Style %d', 'boldreview' ), $n ),
				'description' => (string) ( $meta['label'] ?? '' ),
				'style'       => 'style' . $n,
				'skin'        => $skin,
				'swatch'      => (string) ( $meta['swatch'] ?? '' ),
			);
			$n++;
		}

		return $templates;
	}

	/**
	 * Design-skin metadata, keyed by skin slug. Each is a soft, minimal finish
	 * surfaced as a "Style 5+" card in the template chooser (see templates()).
	 * The swatch is a CSS background used for the card thumbnail. Ships the free
	 * skins only; add-ons register metadata for their own skins via the filter.
	 *
	 * @return array<string,array{label:string,description:string,swatch:string}>
	 */
	public static function skin_meta(): array {
		$meta = array(
			'drop-shadow'    => array(
				'label'       => __( 'Drop Shadow', 'boldreview' ),
				'description' => __( 'Soft floating cards with a deep, diffuse shadow.', 'boldreview' ),
				'swatch'      => 'linear-gradient(135deg,#ffffff,#e2e8f0)',
			),
			'light-contrast' => array(
				'label'       => __( 'Light Contrast', 'boldreview' ),
				'description' => __( 'Crisp high-contrast cards on a soft tinted surface.', 'boldreview' ),
				'swatch'      => 'linear-gradient(135deg,#ffffff,#dbeafe)',
			),
		);

		/**
		 * Filter the design-skin metadata. Add-ons add entries keyed by the skin
		 * slugs they register through `bdrvw_gr_template_skins`.
		 *
		 * @param array<string,array{label:string,description:string,swatch:string}> $meta Skin metadata.
		 */
		return (array) apply_filters( 'bdrvw_gr_skin_meta', $meta );
	}

	/**
	 * Templates offered for a given layout. The "list" layout renders the grid
	 * card design and ignores the template entirely (see Bdrvw_Frontend::render_list_layout),
	 * so it only exposes a single style instead of both.
	 *
	 * @param string $layout Layout slug.
	 * @return array<int,string> Allowed template slugs, in display order.
	 */
	public static function templates_for_layout( string $layout ): array {
		$result = self::filter_templates_for_layout( array_keys( self::templates() ), $layout );

		/**
		 * Filter the template slugs offered for a layout. Add-ons that register a
		 * new layout use this to declare which styles it supports (e.g. a slider
		 * layout offering the full template set).
		 *
		 * @param array<int,string> $result Allowed template slugs, in display order.
		 * @param string            $layout Layout slug.
		 */
		return (array) apply_filters( 'bdrvw_gr_templates_for_layout', $result, $layout );
	}

	/**
	 * Apply the per-layout style exclusions to an ordered list of template slugs.
	 *
	 * @param array<int,string> $base_keys Ordered template slugs to filter.
	 * @param string            $layout    Layout slug.
	 * @return array<int,string>
	 */
	protected static function filter_templates_for_layout( array $base_keys, string $layout ): array {
		$layout_excludes = array(
			'list'    => array( 'template_3', 'template_5', 'template_8', 'template_9' ),
			'sidebar' => array( 'template_4', 'template_5', 'template_8', 'template_9', 'template_10' ),
			'popup'   => array( 'template_8', 'template_9', 'template_10' ),
		);
		if ( isset( $layout_excludes[ $layout ] ) ) {
			return array_values( array_diff( $base_keys, $layout_excludes[ $layout ] ) );
		}
		if ( 'grid' === $layout ) {
			return array_values( $base_keys );
		}
		return array( 'template_1', 'template_2' );
	}

	/**
	 * The serial style number a template carries WITHIN a given layout. Because
	 * some layouts drop styles (see templates_for_layout) the remaining ones are
	 * renumbered 1..N with no gaps — this returns that position so previews and
	 * showcases label a style the same way the chooser does. 0 if not offered.
	 *
	 * @param string $layout   Layout slug.
	 * @param string $template Template slug.
	 */
	public static function style_number_for_layout( string $layout, string $template ): int {
		$idx = array_search( $template, self::templates_for_layout( $layout ), true );
		return false === $idx ? 0 : ( (int) $idx + 1 );
	}

	/**
	 * Style slugs whose shortcode is allowed to generate for a given layout —
	 * every style the layout offers.
	 *
	 * @param string $layout Layout slug.
	 * @return array<int,string> Enabled style slugs.
	 */
	public static function shortcode_enabled_templates( string $layout ): array {
		$enabled = self::templates_for_layout( $layout );

		/**
		 * Filter the styles whose shortcode generates for a layout.
		 *
		 * @param array<int,string> $enabled Enabled style slugs.
		 * @param string            $layout  Current layout slug.
		 */
		return (array) apply_filters( 'bdrvw_shortcode_enabled_templates', $enabled, $layout );
	}

	public static function shortcode_locked( string $layout, string $template ): bool {
		return ! in_array( $template, self::shortcode_enabled_templates( $layout ), true );
	}

	/**
	 * For each template, the list of layouts that offer it. Used to emit a
	 * data-layouts attribute so the admin JS can show/hide template options as
	 * the layout radio changes.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function template_layout_map(): array {
		$map = array();
		foreach ( array_keys( self::templates() ) as $tpl ) {
			$map[ $tpl ] = array();
			foreach ( array_keys( self::layouts() ) as $layout ) {
				if ( in_array( $tpl, self::templates_for_layout( $layout ), true ) ) {
					$map[ $tpl ][] = $layout;
				}
			}
		}
		return $map;
	}

	/**
	 * Display tab toggles. Settings key + label + hint.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function display_toggles(): array {
		return array(
			array(
				'key'   => 'hide_no_comment',
				'label' => __( 'Hide reviews without text', 'boldreview' ),
				'hint'  => __( 'Only display reviews that include written feedback.', 'boldreview' ),
			),
			array(
				'key'   => 'hide_rating_text',
				'label' => __( 'Hide rating label', 'boldreview' ),
				'hint'  => __( 'Remove the "X out of 5" text shown beside star ratings.', 'boldreview' ),
			),
			array(
				'key'   => 'show_arrows',
				'label' => __( 'Show navigation arrows', 'boldreview' ),
				'hint'  => __( 'Enable previous and next controls for sliders.', 'boldreview' ),
			),
			array(
				'key'   => 'show_reviewer_pic',
				'label' => __( 'Show reviewer photos', 'boldreview' ),
				'hint'  => __( 'Display profile pictures for reviewers when available.', 'boldreview' ),
			),
			array(
				'key'   => 'show_platform_logo',
				'label' => __( 'Show platform logo', 'boldreview' ),
				'hint'  => __( 'Display the Google logo on each review card.', 'boldreview' ),
			),
			array(
				'key'   => 'show_platform_stars',
				'label' => __( 'Show star ratings', 'boldreview' ),
				'hint'  => __( 'Display the star rating for each review.', 'boldreview' ),
			),
		);
	}

	/**
	 * Render the tab.
	 *
	 * @param array<string,mixed> $s Full settings array (passed from SettingsPage).
	 */
	public function render( array $s ): void {
		$gr = (array) ( $s['google_reviews'] ?? array() );
		$gr += array(
			'place_id'           => '',
			'api_key'            => '',
			'cache_ttl'          => 10080,
			'cached_at'          => 0,
			'limit'              => 3,
			'min_rating'         => 4,
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
			'is_demo'            => false,
			'accumulate'         => 0,
		);

		$layout            = Bdrvw_Frontend::normalize_layout( (string) $gr['layout'] );
		$template          = Bdrvw_Frontend::normalize_template( (string) $gr['template'] );
		$initial_shortcode = self::build_shortcode( $layout, $template, max( 1, min( 50, (int) ( $gr['limit'] ?? 3 ) ) ), max( 1, min( 4, (int) ( $gr['columns'] ?? 3 ) ) ) );
		$tabs              = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect';
		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'connect';
		}
		?>
		<div class="bdrvw-gr">
			<nav class="bdrvw-tabs" role="tablist">
				<?php foreach ( $tabs as $tab_key => $tab_label ) :
					$tab_url = add_query_arg(
						array(
							'page'   => 'bdrvw-settings',
							'module' => 'google_reviews',
							'tab'    => $tab_key,
						),
						admin_url( 'admin.php' )
					);
					?>
					<a
						class="bdrvw-tabs__tab<?php echo $current_tab === $tab_key ? ' is-active' : ''; ?><?php echo 'guide' === $tab_key ? ' bdrvw-tabs__tab--end bdrvw-tabs__tab--guide' : ''; ?>"
						href="<?php echo esc_url( $tab_url ); ?>"
						role="tab"
						data-tab="<?php echo esc_attr( $tab_key ); ?>"
						aria-selected="<?php echo $current_tab === $tab_key ? 'true' : 'false'; ?>"
					><?php if ( 'guide' === $tab_key ) : ?><span class="dashicons dashicons-book-alt" aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="bdrvw-tab-panels">
				<div class="bdrvw-tab-panel<?php echo 'connect' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="connect" role="tabpanel">
					<?php $this->render_connect_tab( $gr ); ?>
				</div>
				<div class="bdrvw-tab-panel<?php echo 'layout' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="layout" role="tabpanel">
					<?php $this->render_layout_tab( $gr, $layout, $template, $initial_shortcode ); ?>
				</div>
				<div class="bdrvw-tab-panel<?php echo 'display' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="display" role="tabpanel">
					<?php $this->render_display_tab( $gr ); ?>
				</div>
				<div class="bdrvw-tab-panel<?php echo 'guide' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="guide" role="tabpanel">
					<?php $this->render_guide_tab(); ?>
				</div>
			</div>

			<input type="hidden" name="bdrvw_settings_tab" value="<?php echo esc_attr( $current_tab ); ?>" />
			<input type="hidden" name="bdrvw_settings[google_reviews][place_id]" value="<?php echo esc_attr( (string) $gr['place_id'] ); ?>" data-bdrvw-gr-place />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_name]" value="<?php echo esc_attr( (string) $gr['business_name'] ); ?>" data-bdrvw-gr-bizname-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_address]" value="<?php echo esc_attr( (string) $gr['business_address'] ); ?>" data-bdrvw-gr-bizaddress-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_rating]" value="<?php echo esc_attr( (string) $gr['business_rating'] ); ?>" data-bdrvw-gr-bizrating-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_total]" value="<?php echo esc_attr( (string) $gr['business_total'] ); ?>" data-bdrvw-gr-biztotal-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_url]" value="<?php echo esc_attr( (string) $gr['business_url'] ); ?>" data-bdrvw-gr-bizurl-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][business_icon]" value="<?php echo esc_attr( (string) $gr['business_icon'] ); ?>" data-bdrvw-gr-bizicon-input />
			<input type="hidden" name="bdrvw_settings[google_reviews][is_demo]" value="<?php echo $gr['is_demo'] ? '1' : '0'; ?>" data-bdrvw-gr-isdemo-input />
		</div>
		<?php
	}

	/**
	 * Tab 1 — Connect Google.
	 */
	protected function render_connect_tab( array $gr ): void {
		$is_connected     = '' !== (string) $gr['place_id'];
		$api_key          = (string) $gr['api_key'];
		$has_constant_key = defined( 'BDRVW_GOOGLE_API_KEY' ) && '' !== BDRVW_GOOGLE_API_KEY;
		$cache_ttl        = (int) ( $gr['cache_ttl'] ?? 10080 );
		$cached_at        = (int) ( $gr['cached_at'] ?? 0 );
		
		$ttl_choices      = array(
			5     => __( 'Every 5 minutes', 'boldreview' ),
			60    => __( 'Every 1 hour', 'boldreview' ),
			360   => __( 'Every 6 hours', 'boldreview' ),
			720   => __( 'Every 12 hours', 'boldreview' ),
			1440  => __( 'Every 24 hours', 'boldreview' ),
			4320  => __( 'Every 3 days', 'boldreview' ),
			10080 => __( 'Every 7 days (recommended)', 'boldreview' ),
			20160 => __( 'Every two weeks', 'boldreview' ),
			43200 => __( 'Every month', 'boldreview' ),
		);
		if ( ! isset( $ttl_choices[ $cache_ttl ] ) ) {
			$ttl_choices[ $cache_ttl ] = sprintf( /* translators: %d: number of minutes. */ __( 'Every %d minutes', 'boldreview' ), $cache_ttl );
		}
		?>
		<div class="bdrvw-card bdrvw-gr__apikey">
			<header class="bdrvw-card__header">
				<h2><?php esc_html_e( 'API Key & Refresh Interval', 'boldreview' ); ?></h2>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: setup guide link, 2: Google Cloud Console link. */
							__( 'Paste your Google Places API key to load real reviews from your Business Profile. Need one? Follow our %1$s or open the %2$s.', 'boldreview' ),
							'<a href="https://developers.google.com/maps/documentation/places/web-service/get-api-key" target="_blank" rel="noopener noreferrer">' . esc_html__( 'step-by-step setup guide', 'boldreview' ) . '</a>',
							'<a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'boldreview' ) . '</a>'
						),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
						)
					);
					?>
				</p>
			</header>

			<div class="bdrvw-gr__form-row">
				<div class="bdrvw-gr__form-row-label">
					<label for="bdrvw-gr-api-key"><?php esc_html_e( 'API key', 'boldreview' ); ?></label>
				</div>
				<div class="bdrvw-gr__form-row-control">
					<?php if ( $has_constant_key ) : ?>
						<input
							id="bdrvw-gr-api-key"
							type="text"
							class="bdrvw-input bdrvw-input--block"
							value="<?php esc_attr_e( 'Locked by BDRVW_GOOGLE_API_KEY constant in wp-config.php', 'boldreview' ); ?>"
							disabled
							readonly
						/>
						<p class="bdrvw-gr__hint">
							<?php esc_html_e( 'A site-wide key is defined in wp-config.php. Remove that constant if you want to manage the key from this screen.', 'boldreview' ); ?>
						</p>
					<?php else : ?>
						<input
							id="bdrvw-gr-api-key"
							type="password"
							class="bdrvw-input bdrvw-input--block"
							name="bdrvw_settings[google_reviews][api_key]"
							value="<?php echo esc_attr( $api_key ); ?>"
							placeholder="AIza..."
							autocomplete="off"
							spellcheck="false"
						/>
						<p class="bdrvw-gr__hint">
							<?php esc_html_e( 'Required APIs to enable in Google Cloud: Places API + Maps JavaScript API. Restrict the key to your site domain for safety.', 'boldreview' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="bdrvw-gr__notice" role="note">
				<span class="bdrvw-gr__notice-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="7.6" r="1.2" fill="currentColor"/></svg>
				</span>
				<div class="bdrvw-gr__notice-body">
					<strong><?php esc_html_e( 'How reviews are fetched (old vs. new Places API)', 'boldreview' ); ?></strong>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: Google Cloud Console link. */
								__( 'This plugin uses both the new and the legacy &#8220;old&#8221; Google Places API automatically. If your key has the <strong>old Places API</strong> enabled, reviews are sorted <strong>newest first</strong> and the plugin can collect <strong>more than 5</strong> reviews over time. Keys created after March&nbsp;2025 often support only the <em>new</em> Places API, which returns up to 5 reviews sorted by relevance (not by date). To get more, newer reviews: enable &#8220;Places API&#8221; on your project in the %s, then keep <strong>Accumulate reviews</strong> turned on below.', 'boldreview' ),
								'<a href="https://console.cloud.google.com/google/maps-apis/api-list" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'boldreview' ) . '</a>'
							),
							array(
								'a'      => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
								'strong' => array(),
								'em'     => array(),
							)
						);
						?>
					</p>
				</div>
			</div>

			<div class="bdrvw-gr__form-row">
				<div class="bdrvw-gr__form-row-label">
					<label for="bdrvw-gr-cache-ttl"><?php esc_html_e( 'Update Interval', 'boldreview' ); ?></label>
				</div>
				<div class="bdrvw-gr__form-row-control">
					<select id="bdrvw-gr-cache-ttl" name="bdrvw_settings[google_reviews][cache_ttl]" class="bdrvw-input bdrvw-input--block">
						<?php foreach ( $ttl_choices as $minutes => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( $cache_ttl, (int) $minutes ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="bdrvw-gr__hint">
						<?php
						if ( $cached_at > 0 ) {
							printf(
								/* translators: %s: human-readable time difference. */
								esc_html__( 'Last refreshed: %s ago.', 'boldreview' ),
								esc_html( human_time_diff( $cached_at, time() ) )
							);
						} else {
							esc_html_e( 'Cache is empty — connect a business below to populate it.', 'boldreview' );
						}
						?>
					</p>
				</div>
			</div>

			<div class="bdrvw-gr__form-row">
				<div class="bdrvw-gr__form-row-label">
					<label for="bdrvw-gr-accumulate"><?php esc_html_e( 'Accumulate reviews', 'boldreview' ); ?></label>
				</div>
				<div class="bdrvw-gr__form-row-control">
					<label class="bdrvw-switch">
						<input
							type="checkbox"
							id="bdrvw-gr-accumulate"
							name="bdrvw_settings[google_reviews][accumulate]"
							value="1"
							<?php checked( (int) ( $gr['accumulate'] ?? 0 ), 1 ); ?>
						/>
						<span class="bdrvw-switch__track"></span>
					</label>
					<p class="bdrvw-gr__hint">
						<?php esc_html_e( 'Google returns at most 5 reviews per request. With this on, each refresh keeps previously-fetched reviews instead of replacing them, so your displayed set can grow beyond 5 over time as Google rotates which reviews it returns (up to 50 stored). A shorter Update Interval fills it faster.', 'boldreview' ); ?>
					</p>
				</div>
			</div>

			<div class="bdrvw-gr__platform-body<?php echo $is_connected ? ' is-hidden' : ''; ?>" data-bdrvw-gr-search>
				<div class="bdrvw-gr__form-row">
					<div class="bdrvw-gr__form-row-label">
						<label for="bdrvw-gr-query"><?php esc_html_e( 'Google Business Profile name or location', 'boldreview' ); ?></label>
					</div>
					<div class="bdrvw-gr__form-row-control">
						<div class="bdrvw-gr__search-input">
							<input
								id="bdrvw-gr-query"
								type="text"
								class="bdrvw-input bdrvw-gr__query"
								placeholder="<?php esc_attr_e( 'Type your google business profile name,location, place id, google map ', 'boldreview' ); ?>"
								autocomplete="off"
								data-bdrvw-gr-query
							/>
							<span class="bdrvw-gr__spinner" aria-hidden="true"></span>
						</div>
						<p class="bdrvw-gr__hint">
							<?php esc_html_e( 'Start typing your Google Business Profile name or your location, then select your business from the drop-down list.', 'boldreview' ); ?><br />
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: Google Maps URL link label. */
									__( 'Alternatively, you can enter either your Place ID or your %s.', 'boldreview' ),
									'<a href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Maps URL', 'boldreview' ) . '</a>'
								),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							);
							?>
						</p>
						<ul class="bdrvw-gr__results" data-bdrvw-gr-results role="listbox" hidden></ul>
					</div>
				</div>
			</div>

			<div class="bdrvw-gr__connected<?php echo $is_connected ? '' : ' is-hidden'; ?>" data-bdrvw-gr-connected>
				<div class="bdrvw-source-box">
					<span class="bdrvw-source-box__icon" data-bdrvw-gr-connected-icon>
						<?php if ( '' !== (string) $gr['business_icon'] ) : ?>
							<img src="<?php echo esc_url( (string) $gr['business_icon'] ); ?>" alt="" referrerpolicy="no-referrer" />
						<?php else : ?>
							<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
								<path fill="#4285F4" d="M22.5 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.22-4.74 3.22-8.32z"/>
								<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/>
								<path fill="#FBBC05" d="M5.84 14.1A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.06H2.18A11 11 0 0 0 1 12c0 1.77.43 3.45 1.18 4.94l3.66-2.84z"/>
								<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.65l3.15-3.15C17.46 2.15 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>
							</svg>
						<?php endif; ?>
					</span>
					<div class="bdrvw-source-box__info">
						<strong class="bdrvw-source-box__name" data-bdrvw-gr-bizname><?php echo esc_html( (string) $gr['business_name'] ); ?></strong>
						<span class="bdrvw-source-box__address" data-bdrvw-gr-bizaddress><?php echo esc_html( (string) $gr['business_address'] ); ?></span>
						<a class="bdrvw-source-box__url" target="_blank" rel="noopener noreferrer" data-bdrvw-gr-bizurl href="<?php echo esc_url( (string) $gr['business_url'] ); ?>"><?php echo esc_html( (string) $gr['business_url'] ); ?></a>
					</div>
					<button type="button" class="bdrvw-btn bdrvw-source-box__action" data-bdrvw-gr-disconnect>
						<?php esc_html_e( 'Disconnect', 'boldreview' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 2 — Layout + Template + Shortcode + Preview.
	 */
	protected function render_layout_tab( array $gr, string $layout, string $template, string $initial_shortcode ): void {

		$layouts       = self::layouts();
		$templates     = self::templates();
		$tpl_layouts   = self::template_layout_map();
		$allowed_tpls  = self::templates_for_layout( $layout );
		
		if ( ! in_array( $template, $allowed_tpls, true ) ) {
			$template = $allowed_tpls[0];
		}

		$columns = max( 1, min( 4, (int) ( $gr['columns'] ?? 3 ) ) );

		$shortcode_locked = self::shortcode_locked( $layout, $template );
		?>
		<div class="bdrvw-gr__layout-tab" data-bdrvw-gr-layout-tab>
			<div class="bdrvw-card bdrvw-gr__layout-card">
				<header class="bdrvw-card__header">
					<h2><?php esc_html_e( 'Layout', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Choose how the reviews block is arranged.', 'boldreview' ); ?></p>
				</header>
				<div class="bdrvw-gr__layouts" role="radiogroup" data-bdrvw-gr-layouts>
					<?php
					foreach ( $layouts as $slug => $def ) :
						$layout_class = 'bdrvw-gr__layout' . ( $layout === $slug ? ' is-active' : '' );
						?>
						<label class="<?php echo esc_attr( $layout_class ); ?>">
							<input
								type="radio"
								name="bdrvw_settings[google_reviews][layout]"
								value="<?php echo esc_attr( $slug ); ?>"
								data-shortcode="<?php echo esc_attr( $def['shortcode'] ); ?>"
								<?php checked( $layout, $slug ); ?>
								data-bdrvw-gr-layout
							/>
							<span class="bdrvw-gr__layout-icon" aria-hidden="true">
								<?php echo wp_kses( self::layout_icon_svg( $slug ), bdrvw_allowed_html() ); ?>
							</span>
							<span class="bdrvw-gr__layout-text">
								<strong><?php echo esc_html( $def['label'] ); ?></strong>
								<span><?php echo esc_html( $def['description'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="bdrvw-gr__layout-config" data-bdrvw-gr-layout-config>
				<div class="bdrvw-gr__section-divider"></div>

				<header class="bdrvw-card__header bdrvw-card__header--sub">
					<h3><?php esc_html_e( 'Template', 'boldreview' ); ?></h3>
				</header>
				<div class="bdrvw-gr__templates" data-bdrvw-gr-templates>
					<?php
					foreach ( $templates as $slug => $def ) :
						$tpl_for      = (array) ( $tpl_layouts[ $slug ] ?? array() );
						$is_hidden    = ! in_array( $layout, $tpl_for, true );
						$is_skinned   = ! empty( $def['skin'] );
						$popup_thumb   = self::popup_thumb_svg( $slug );    
						$popup_desc    = self::popup_description( $slug );    
						$list_thumb    = self::list_thumb_svg( $slug );      
						$grid_thumb    = self::grid_thumb_svg( $slug );     
						$grid_desc     = self::grid_description( $slug ); 
						$has_popup      = ( '' !== $popup_thumb );
						$has_popup_desc = ( '' !== $popup_desc );
						$has_list       = ( '' !== $list_thumb );
						$has_grid       = ( '' !== $grid_thumb );
						$has_grid_desc  = ( '' !== $grid_desc );
						$label_class   = 'bdrvw-gr__template'
							. ( $template === $slug ? ' is-active' : '' )
							. ( $is_hidden ? ' is-hidden' : '' )
							. ( $is_skinned ? ' bdrvw-gr__template--premium' : '' )
							. ( $has_popup ? ' bdrvw-gr__template--has-popup' : '' )
							. ( $has_popup_desc ? ' bdrvw-gr__template--has-popup-desc' : '' )
							. ( $has_list ? ' bdrvw-gr__template--has-list' : '' )
							. ( $has_grid ? ' bdrvw-gr__template--has-grid' : '' )
							. ( $has_grid_desc ? ' bdrvw-gr__template--has-grid-desc' : '' )
							
						?>
						<label class="<?php echo esc_attr( $label_class ); ?>">
							<input
								type="radio"
								name="bdrvw_settings[google_reviews][template]"
								value="<?php echo esc_attr( $slug ); ?>"
								data-style="<?php echo esc_attr( $def['style'] ); ?>"
								data-layouts="<?php echo esc_attr( implode( ' ', $tpl_for ) ); ?>"
								
								<?php checked( $template, $slug ); ?>
								data-bdrvw-gr-template
							/>
							<span class="bdrvw-gr__template-thumb bdrvw-gr__template-thumb--default<?php echo $is_skinned ? ' bdrvw-gr__template-thumb--premium' : ''; ?>" aria-hidden="true">
								<?php echo wp_kses( self::template_thumb_svg( $slug ), bdrvw_allowed_html() ); ?>
								
							</span>
							<?php if ( $has_popup ) : ?>
								<span class="bdrvw-gr__template-thumb bdrvw-gr__template-thumb--popup" aria-hidden="true">
									<?php echo wp_kses( $popup_thumb, bdrvw_allowed_html() ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $has_list ) : ?>
								<span class="bdrvw-gr__template-thumb bdrvw-gr__template-thumb--list" aria-hidden="true">
									<?php echo wp_kses( $list_thumb, bdrvw_allowed_html() ); ?>
								</span>
							<?php endif; ?>
							<?php if ( $has_grid ) : ?>
								<span class="bdrvw-gr__template-thumb bdrvw-gr__template-thumb--grid" aria-hidden="true">
									<?php echo wp_kses( $grid_thumb, bdrvw_allowed_html() ); ?>
								</span>
							<?php endif; ?>
							<span class="bdrvw-gr__template-text">
								<strong>
									<span class="bdrvw-gr__template-no"><?php echo esc_html( $def['label'] ); ?></span>
									
								</strong>
								<span class="bdrvw-gr__template-desc bdrvw-gr__template-desc--default"><?php echo esc_html( $def['description'] ); ?></span>
								<?php if ( $has_popup_desc ) : ?>
									<span class="bdrvw-gr__template-desc bdrvw-gr__template-desc--popup"><?php echo esc_html( $popup_desc ); ?></span>
								<?php endif; ?>
								<?php if ( $has_grid_desc ) : ?>
									<span class="bdrvw-gr__template-desc bdrvw-gr__template-desc--grid"><?php echo esc_html( $grid_desc ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="bdrvw-gr__section-divider"></div>

				<header class="bdrvw-card__header bdrvw-card__header--sub">
					<h3><?php esc_html_e( 'Cards per row', 'boldreview' ); ?></h3>
				</header>
				<div class="bdrvw-gr__columns-row">
					<select id="bdrvw-gr-columns" name="bdrvw_settings[google_reviews][columns]" class="bdrvw-input bdrvw-input--block" data-bdrvw-gr-columns>
						<?php for ( $c = 1; $c <= 4; $c++ ) : ?>
							<option value="<?php echo esc_attr( (string) $c ); ?>" <?php selected( $columns, $c ); ?>>
								<?php
								/* translators: %d: number of cards per row. */
								printf( esc_html( _n( '%d card per row', '%d cards per row', $c, 'boldreview' ) ), (int) $c );
								?>
							</option>
						<?php endfor; ?>
					</select>
					<p class="bdrvw-gr__hint"><?php esc_html_e( 'Applies to Grid (cards per row). List and Sidebar always use a single column. Tablet caps at 2, mobile at 1.', 'boldreview' ); ?></p>
				</div>
				</div>
			</div>

			<div class="bdrvw-card bdrvw-gr__preview-card">
				<header class="bdrvw-card__header">
					<h2><?php esc_html_e( 'Live preview', 'boldreview' ); ?></h2>
					<p data-bdrvw-gr-preview-state>
						<?php echo '' !== (string) $gr['place_id']
							? esc_html__( 'Showing connected business data.', 'boldreview' )
							: esc_html__( 'Showing demo data. Connect a Google Business Profile in the Connect Google tab to see real reviews.', 'boldreview' ); ?>
					</p>
				</header>
				<div class="bdrvw-gr__preview" data-bdrvw-gr-preview>
					<?php echo wp_kses( Bdrvw_Frontend::render_preview( $this->settings, $layout, $template ), bdrvw_allowed_html() ); ?>
				</div>
			</div>

			<div class="bdrvw-card bdrvw-gr__shortcode-card" data-bdrvw-gr-shortcode-card<?php echo $shortcode_locked ? ' hidden' : ''; ?>>
				<header class="bdrvw-card__header">
					<h2><?php esc_html_e( 'Shortcode', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Drop this anywhere on your site. It updates as you change the layout or template.', 'boldreview' ); ?></p>
				</header>

				<div class="bdrvw-gr__shortcode-row" data-bdrvw-gr-shortcode-row>
					<code class="bdrvw-code bdrvw-gr__shortcode" data-bdrvw-gr-shortcode ><?php echo  esc_html( $initial_shortcode ); ?></code>
					<button type="button" class="bdrvw-btn bdrvw-gr__shortcode-copy" data-bdrvw-gr-copy data-label-copy="<?php esc_attr_e( 'Copy', 'boldreview' ); ?>" data-label-copied="<?php esc_attr_e( 'Copied!', 'boldreview' ); ?>">
						<?php esc_html_e( 'Copy', 'boldreview' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 4 — Usage Guide.
	 *
	 * A plain-language reference for the shortcodes this module registers: one
	 * copyable tag per layout, the optional attributes they accept, and the
	 * styles available. Mirrors the Collection Review module's "Uses" tab so the
	 * two modules read the same, and relies entirely on the shared `bdrvw-uses`
	 * markup + the global `[data-bdrvw-copy-btn]` copy handler.
	 */
	protected function render_guide_tab(): void {
		$shortcodes = array(
			array(
				'tag'   => '[bdrvw_google_grid style="style1" columns="3"]',
				'title' => __( 'Grid layout', 'boldreview' ),
				'desc'  => __( 'A responsive card grid (1–4 columns). Best for a dedicated reviews section on a page.', 'boldreview' ),
			),
			array(
				'tag'   => '[bdrvw_google_list style="style1"]',
				'title' => __( 'List layout', 'boldreview' ),
				'desc'  => __( 'A compact, single-column feed of reviews. Great for narrow columns and sidebars.', 'boldreview' ),
			),
			array(
				'tag'   => '[bdrvw_google_sidebar style="style3"]',
				'title' => __( 'Sidebar layout', 'boldreview' ),
				'desc'  => __( 'A business profile card with the average rating above the reviews. Style 3 adds a “Write a review” button that links to your Google page.', 'boldreview' ),
			),
			array(
				'tag'   => '[bdrvw_google_popup style="style1"]',
				'title' => __( 'Popup layout', 'boldreview' ),
				'desc'  => __( 'A small review badge that opens the full reviews in a slide-in panel — perfect for tucking into a corner of any page.', 'boldreview' ),
			),
			array(
				'tag'   => '[bdrvw_google]',
				'title' => __( 'All-in-one', 'boldreview' ),
				'desc'  => __( 'Renders using whatever you saved on the Layout tab. Add layout and template attributes to override it without opening the settings.', 'boldreview' ),
			),
		);

		$atts = array(
			array( 'style', __( 'The visual card style, style1 through style6 (see the styles below). Falls back to your saved style.', 'boldreview' ), '[bdrvw_google_grid style="style2"]' ),
			array( 'max_reviews', __( 'How many reviews to show. Falls back to your saved “Maximum reviews” limit.', 'boldreview' ), '[bdrvw_google_grid max_reviews="6"]' ),
			array( 'order', __( 'Sort reviews by date: “desc” shows newest first, “asc” shows oldest first. Leave out to keep Google’s default order. Works with every shortcode.', 'boldreview' ), '[bdrvw_google_grid order="desc"]' ),
			array( 'min_rating', __( 'Only show reviews rated this many stars or higher (1–5). Overrides the saved “Minimum rating” setting for this shortcode.', 'boldreview' ), '[bdrvw_google_grid min_rating="4"]' ),
			array( 'rating', __( 'Only show reviews with exactly this star rating (1–5). Takes priority over min_rating.', 'boldreview' ), '[bdrvw_google_grid rating="5"]' ),
			array( 'columns', __( 'Cards per row for the Grid layout only, 1 to 4. Defaults to 3.', 'boldreview' ), '[bdrvw_google_grid columns="4"]' ),
			array( 'layout', __( 'For the all-in-one [bdrvw_google] tag only: grid, list, sidebar, or popup.', 'boldreview' ), '[bdrvw_google layout="list"]' ),
			array( 'template', __( 'For [bdrvw_google] only: template_1 to template_6 (same as style1 to style6).', 'boldreview' ), '[bdrvw_google template="template_2"]' ),
		);

		$styles = array(
			array( 'style1', __( 'Standard Card', 'boldreview' ), __( 'Default — works in every layout', 'boldreview' ) ),
			array( 'style2', __( 'Quote Style', 'boldreview' ), __( 'Testimonial-style bubbles', 'boldreview' ) ),
			array( 'style3', __( 'Profile Header', 'boldreview' ), __( 'Sidebar with “Write a review”', 'boldreview' ) ),
			array( 'style4', __( 'Cards Only', 'boldreview' ), __( 'Clean stacked cards, no header', 'boldreview' ) ),
			array( 'style5', __( 'Drop Shadow', 'boldreview' ), __( 'Soft floating cards', 'boldreview' ) ),
			array( 'style6', __( 'Light Contrast', 'boldreview' ), __( 'Crisp cards on a tinted surface', 'boldreview' ) ),
		);
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-shortcode"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Shortcodes', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Copy a shortcode and paste it into any post, page, or widget to show your Google reviews exactly where you want them.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php foreach ( $shortcodes as $sc ) : ?>
				<div class="bdrvw-uses__item">
					<div class="bdrvw-uses__copy">
						<code class="bdrvw-uses__code" data-bdrvw-copy><?php echo esc_html( $sc['tag'] ); ?></code>
						<button type="button" class="bdrvw-btn-secondary bdrvw-uses__copy-btn" data-bdrvw-copy-btn="<?php echo esc_attr( $sc['tag'] ); ?>">
							<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy', 'boldreview' ); ?>
						</button>
					</div>
					<h4 class="bdrvw-uses__title"><?php echo esc_html( $sc['title'] ); ?></h4>
					<p class="bdrvw-uses__desc"><?php echo esc_html( $sc['desc'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<div class="bdrvw-uses__note">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				<p><?php esc_html_e( 'You don’t have to type any of these by hand. Open the Layout tab, pick a layout and style, and copy the ready-made shortcode from the box at the bottom.', 'boldreview' ); ?></p>
			</div>
		</div>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-admin-settings"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Optional attributes', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Every attribute is optional — leave one out and it uses your saved default.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-uses__table">
				<div class="bdrvw-uses__trow bdrvw-uses__trow--head">
					<span><?php esc_html_e( 'Attribute', 'boldreview' ); ?></span>
					<span><?php esc_html_e( 'What it does', 'boldreview' ); ?></span>
					<span><?php esc_html_e( 'Example', 'boldreview' ); ?></span>
				</div>
				<?php foreach ( $atts as $att ) : ?>
					<div class="bdrvw-uses__trow">
						<span><code><?php echo esc_html( $att[0] ); ?></code></span>
						<span><?php echo esc_html( $att[1] ); ?></span>
						<span><code><?php echo esc_html( $att[2] ); ?></code></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-art"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Styles', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'The style attribute picks the card design. Not every layout offers every style — the Layout tab only shows the ones that fit.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-uses__table">
				<div class="bdrvw-uses__trow bdrvw-uses__trow--head">
					<span><?php esc_html_e( 'Value', 'boldreview' ); ?></span>
					<span><?php esc_html_e( 'Design', 'boldreview' ); ?></span>
					<span><?php esc_html_e( 'Best for', 'boldreview' ); ?></span>
				</div>
				<?php foreach ( $styles as $st ) : ?>
					<div class="bdrvw-uses__trow">
						<span><code><?php echo esc_html( $st[0] ); ?></code></span>
						<span><?php echo esc_html( $st[1] ); ?></span>
						<span><?php echo esc_html( $st[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="bdrvw-uses__note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Reviewer photos, star ratings, the Google logo, the minimum rating and date format are set once on the Display tab — they apply to every shortcode automatically.', 'boldreview' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab 3 — Display options (card-element toggles + limits).
	 */
	protected function render_display_tab( array $gr ): void {
		$toggles = self::display_toggles();
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header">
				<h2><?php esc_html_e( 'Card Display', 'boldreview' ); ?></h2>
				<p><?php esc_html_e( 'Toggle which elements appear on each review card.', 'boldreview' ); ?></p>
			</header>

			<?php foreach ( $toggles as $t ) :
				$key   = (string) $t['key'];
				$label = (string) $t['label'];
				$hint  = (string) ( $t['hint'] ?? '' );
				$val   = (int) ( $gr[ $key ] ?? 0 );
				?>
				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php echo esc_html( $label ); ?></label>
						<?php if ( '' !== $hint ) : ?>
							<p class="bdrvw-row__hint"><?php echo esc_html( $hint ); ?></p>
						<?php endif; ?>
					</div>
					<div class="bdrvw-row__control">
						<label class="bdrvw-switch">
							<input type="checkbox" name="bdrvw_settings[google_reviews][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $val, 1 ); ?> />
							<span class="bdrvw-switch__track"></span>
						</label>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header">
				<h2><?php esc_html_e( 'Review Limits', 'boldreview' ); ?></h2>
				<p><?php esc_html_e( 'Control how many reviews show, the minimum rating, the review date format, and how the review text is displayed.', 'boldreview' ); ?></p>
			</header>

			<?php
			$limit       = max( 1, min( 50, (int) $gr['limit'] ) );
			$min_rating  = max( 1, min( 5, (int) $gr['min_rating'] ) );
			$date_format = (string) ( $gr['date_format'] ?? 'relative' );
			$review_text = (string) ( $gr['review_text'] ?? 'truncate' );
			$now         = time();
			$date_choices = array(
				'relative' => __( 'Relative — e.g. “2 weeks ago”', 'boldreview' ),
				'M j, Y'   => wp_date( 'M j, Y', $now ),
				'F j, Y'   => wp_date( 'F j, Y', $now ),
				'd/m/Y'    => wp_date( 'd/m/Y', $now ),
				'm/d/Y'    => wp_date( 'm/d/Y', $now ),
				'Y-m-d'    => wp_date( 'Y-m-d', $now ),
				'wp'       => sprintf( /* translators: %s: example date in the site's configured format. */ __( 'Site default (%s)', 'boldreview' ), wp_date( (string) get_option( 'date_format', 'F j, Y' ), $now ) ),
			);
			$text_choices = array(
				'truncate' => __( 'Truncate with “Read more”', 'boldreview' ),
				'full'     => __( 'Show full review', 'boldreview' ),
				'scroll'   => __( 'Scrollable box (fixed height)', 'boldreview' ),
			);
			?>
			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-gr-limit"><?php esc_html_e( 'Maximum reviews to display', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Google returns up to 5 reviews per request. To display more than 5, turn on “Accumulate reviews” in the Connect Google tab (stores up to 50 over time).', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<input
						id="bdrvw-gr-limit"
						type="number"
						class="bdrvw-input bdrvw-input--block"
						name="bdrvw_settings[google_reviews][limit]"
						value="<?php echo esc_attr( (string) $limit ); ?>"
						min="1"
						max="50"
						step="1"
					/>
				</div>
			</div>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-gr-min-rating"><?php esc_html_e( 'Minimum rating to show', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Hide reviews rated below this value.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<select id="bdrvw-gr-min-rating" name="bdrvw_settings[google_reviews][min_rating]" class="bdrvw-input bdrvw-input--block">
						<?php
						$rating_labels = array(
							1 => __( '1 star &amp; up (all reviews)', 'boldreview' ),
							2 => __( '2 stars &amp; up', 'boldreview' ),
							3 => __( '3 stars &amp; up', 'boldreview' ),
							4 => __( '4 stars &amp; up', 'boldreview' ),
							5 => __( '5 stars only', 'boldreview' ),
						);
						foreach ( $rating_labels as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $min_rating, $value ); ?>>
								<?php echo wp_kses( $label, array() ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label for="bdrvw-gr-date-format"><?php esc_html_e( 'Select date format', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'How each review’s date is shown. “Relative” needs no exact date; the others use the review’s posted date.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<select id="bdrvw-gr-date-format" name="bdrvw_settings[google_reviews][date_format]" class="bdrvw-input bdrvw-input--block">
							<?php foreach ( $date_choices as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $date_format, (string) $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label for="bdrvw-gr-review-text"><?php esc_html_e( 'Review text', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'How long review text is handled: truncate behind a “Read more” toggle, show in full, or keep a fixed-height scrollable box.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<select id="bdrvw-gr-review-text" name="bdrvw_settings[google_reviews][review_text]" class="bdrvw-input bdrvw-input--block">
							<?php foreach ( $text_choices as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $review_text, (string) $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<?php if ( has_action( 'bdrvw_gr_blocked_words_control' ) ) : ?>
				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Blocked words', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Hide any review whose text contains a blocked word (comma- or line-separated). Matching is case-insensitive and whole-word.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<?php
						/**
						 * Renders the "Blocked words" control. The row only appears
						 * when an add-on hooks here.
						 *
						 * @param array<string,mixed> $gr Saved Google Reviews settings.
						 */
						do_action( 'bdrvw_gr_blocked_words_control', $gr );
						?>
					</div>
				</div>
				<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compose the per-layout shortcode string for the current selection.
	 */
	public static function build_shortcode( string $layout, string $template, int $limit = 0, int $columns = 0 ): string {
		$layouts = self::layouts();
		$ld      = $layouts[ $layout ] ?? $layouts['grid'];
		$num = self::style_number_for_layout( $layout, $template );
		if ( $num < 1 ) {
			$num = 1;
		}
		$sc = '[' . $ld['shortcode'] . ' style="style' . $num . '"';
		if ( $columns > 0 ) {
			$sc .= ' columns="' . (int) $columns . '"';
		}
		if ( $limit > 0 ) {
			$sc .= ' max_reviews="' . (int) $limit . '"';
		}
		return $sc . ']';
	}

	/** Layout-card SVG icon. */
	protected static function layout_icon_svg( string $layout ): string {
		switch ( $layout ) {
			case 'list':
				return '<svg viewBox="0 0 32 24" width="32" height="24"><rect x="2" y="3" width="28" height="4" rx="1" fill="currentColor"/><rect x="2" y="10" width="28" height="4" rx="1" fill="currentColor" opacity=".7"/><rect x="2" y="17" width="28" height="4" rx="1" fill="currentColor" opacity=".4"/></svg>';
			case 'sidebar':
				return '<svg viewBox="0 0 32 24" width="32" height="24"><rect x="2" y="3" width="10" height="18" rx="2" fill="currentColor"/><rect x="14" y="3" width="16" height="5" rx="1" fill="currentColor" opacity=".55"/><rect x="14" y="10" width="16" height="5" rx="1" fill="currentColor" opacity=".55"/><rect x="14" y="17" width="16" height="4" rx="1" fill="currentColor" opacity=".55"/></svg>';
			case 'popup':
				return '<svg viewBox="0 0 32 24" width="32" height="24"><rect x="3" y="7" width="15" height="10" rx="2" fill="currentColor" opacity=".55"/><rect x="22" y="2" width="9" height="20" rx="2" fill="currentColor"/></svg>';
			case 'grid':
			default:
				return '<svg viewBox="0 0 32 24" width="32" height="24"><rect x="2" y="3" width="12" height="8" rx="1" fill="currentColor"/><rect x="18" y="3" width="12" height="8" rx="1" fill="currentColor" opacity=".7"/><rect x="2" y="13" width="12" height="8" rx="1" fill="currentColor" opacity=".7"/><rect x="18" y="13" width="12" height="8" rx="1" fill="currentColor"/></svg>';
		}
	}

	/**
	 * Popup-layout badge thumbnail for a style. The Popup layout renders the
	 * style as a clickable review *badge*, not a review card, so styles 1–4 map
	 * to distinct badge layouts (classic / centered / compact pill / rating).
	 * Returns '' for styles without a dedicated popup variant (5+ reuse their
	 * skin thumbnail).
	 */
	protected static function popup_thumb_svg( string $template ): string {
		$skin   = Bdrvw_Frontend::template_skin( $template );
		$custom = (string) apply_filters( 'bdrvw_gr_template_thumb', '', $template, 'popup', $skin );
		if ( '' !== $custom ) {
			return $custom;
		}

		$open  = '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">';
		$close = '</svg>';

		if ( '' !== $skin ) {
			
			$body = static function ( string $name, string $line, string $avatar = '#bfdbfe' ): string {
				return '<circle cx="26" cy="40" r="10" fill="' . $avatar . '"/>'
					. '<rect x="42" y="30" width="44" height="5" rx="2" fill="' . $name . '"/>'
					. '<g fill="#f59e0b"><rect x="42" y="39" width="5" height="5" rx="1"/><rect x="49" y="39" width="5" height="5" rx="1"/><rect x="56" y="39" width="5" height="5" rx="1"/><rect x="63" y="39" width="5" height="5" rx="1"/></g>'
					. '<rect x="42" y="49" width="50" height="4" rx="2" fill="' . $line . '"/>';
			};

			switch ( $skin ) {
				case 'drop-shadow':
					return $open
						. '<rect x="11" y="26" width="104" height="36" rx="9" fill="#9aa6b8" opacity=".35"/>'
						. '<rect x="8" y="22" width="104" height="36" rx="9" fill="#ffffff"/>'
						. $body( '#475569', '#cbd5e1' )
						. $close;
				case 'light-contrast':
					return $open
						. '<rect x="8" y="22" width="104" height="36" rx="8" fill="#ffffff" stroke="#94a3b8" stroke-width="1.5"/>'
						. $body( '#334155', '#cbd5e1' )
						. $close;
			}
		}

		switch ( $template ) {
			case 'template_1': 
				return $open
					. '<rect x="8" y="22" width="104" height="36" rx="8" fill="#fff" stroke="#e5e7eb"/>'
					. '<circle cx="26" cy="40" r="10" fill="#bfdbfe"/>'
					. '<rect x="42" y="30" width="44" height="5" rx="2" fill="#475569"/>'
					. '<g fill="#f59e0b"><rect x="42" y="39" width="5" height="5" rx="1"/><rect x="49" y="39" width="5" height="5" rx="1"/><rect x="56" y="39" width="5" height="5" rx="1"/><rect x="63" y="39" width="5" height="5" rx="1"/></g>'
					. '<rect x="42" y="49" width="50" height="4" rx="2" fill="#cbd5e1"/>'
					. $close;
			case 'template_2': 
				return $open
					. '<rect x="24" y="12" width="72" height="56" rx="8" fill="#fff" stroke="#e5e7eb"/>'
					. '<circle cx="60" cy="27" r="10" fill="#bfdbfe"/>'
					. '<rect x="40" y="41" width="40" height="5" rx="2" fill="#475569"/>'
					. '<g fill="#f59e0b"><rect x="45" y="49" width="5" height="5" rx="1"/><rect x="52" y="49" width="5" height="5" rx="1"/><rect x="59" y="49" width="5" height="5" rx="1"/><rect x="66" y="49" width="5" height="5" rx="1"/></g>'
					. '<rect x="44" y="58" width="32" height="4" rx="2" fill="#cbd5e1"/>'
					. $close;
			case 'template_3': 
				return $open
					. '<rect x="16" y="29" width="88" height="22" rx="11" fill="#fff" stroke="#e5e7eb"/>'
					. '<circle cx="29" cy="40" r="8" fill="#bfdbfe"/>'
					. '<rect x="42" y="34" width="32" height="4" rx="2" fill="#475569"/>'
					. '<g fill="#f59e0b"><rect x="42" y="42" width="4" height="4" rx="1"/><rect x="48" y="42" width="4" height="4" rx="1"/><rect x="54" y="42" width="4" height="4" rx="1"/></g>'
					. $close;
			case 'template_4': 
				return $open
					. '<rect x="8" y="22" width="104" height="36" rx="8" fill="#fff" stroke="#e5e7eb"/>'
					. '<circle cx="24" cy="40" r="9" fill="#bfdbfe"/>'
					. '<rect x="40" y="29" width="38" height="4" rx="2" fill="#475569"/>'
					. '<text x="40" y="47" font-family="Arial, sans-serif" font-size="12" font-weight="700" fill="#111827">4.6</text>'
					. '<g fill="#f59e0b"><rect x="58" y="39" width="4" height="4" rx="1"/><rect x="64" y="39" width="4" height="4" rx="1"/><rect x="70" y="39" width="4" height="4" rx="1"/></g>'
					. '<rect x="40" y="51" width="46" height="3" rx="1.5" fill="#cbd5e1"/>'
					. $close;
		}
		return '';
	}

	/** Popup-layout description for a style (styles 1–4 only). */
	protected static function popup_description( string $template ): string {

		$custom = (string) apply_filters( 'bdrvw_gr_template_description', '', $template, 'popup' );
		if ( '' !== $custom ) {
			return $custom;
		}

		switch ( $template ) {
			case 'template_1':
				return __( 'Classic — logo, name, stars and review count in a row.', 'boldreview' );
			case 'template_2':
				return __( 'Centered — logo on top, details centred below.', 'boldreview' );
			case 'template_3':
				return __( 'Compact pill — a small rounded review badge.', 'boldreview' );
			case 'template_4':
				return __( 'Rating focus — a large rating number beside the stars.', 'boldreview' );
		}

		return '';
	}

	/**
	 * List-layout thumbnail for a style. On the List layout, Style 3 and Style 4
	 * render differently from their sidebar designs (blue-accent rows / borderless
	 * divider rows), so the chooser shows matching thumbnails. Returns '' for
	 * styles whose default thumbnail already represents the list look.
	 */
	protected static function list_thumb_svg( string $template ): string {
		$custom = (string) apply_filters( 'bdrvw_gr_template_thumb', '', $template, 'list', Bdrvw_Frontend::template_skin( $template ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$open  = '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">';
		$close = '</svg>';
		$frame = '<rect x="2" y="2" width="116" height="76" rx="6" fill="#fff" stroke="#e5e7eb"/>';
		
		$row = static function ( int $y ): string {
			return '<circle cx="20" cy="' . ( $y + 10 ) . '" r="6" fill="#bfdbfe"/>'
				. '<rect x="32" y="' . ( $y + 5 ) . '" width="42" height="4" rx="2" fill="#475569"/>'
				. '<g fill="#f59e0b"><rect x="32" y="' . ( $y + 13 ) . '" width="4" height="4" rx="1"/><rect x="38" y="' . ( $y + 13 ) . '" width="4" height="4" rx="1"/><rect x="44" y="' . ( $y + 13 ) . '" width="4" height="4" rx="1"/></g>'
				. '<rect x="32" y="' . ( $y + 21 ) . '" width="64" height="3" rx="1.5" fill="#cbd5e1"/>';
		};
		switch ( $template ) {
			case 'template_4': 
				return $open . $frame
					. $row( 8 )
					. '<rect x="10" y="40" width="100" height="1.4" fill="#ececf0"/>'
					. $row( 44 )
					. $close;
		}
		return '';
	}

	/**
	 * Grid-layout thumbnail for a style. On the Grid layout, Styles 3–6 carry
	 * their own free card treatments (Spotlight / Editorial / Banner / Frosted
	 * Pastel) instead of the sidebar-structural or plain-skin designs their
	 * default thumbnail shows, so the chooser previews the grid look. Returns ''
	 * for styles whose default thumbnail already represents the grid look.
	 */
	protected static function grid_thumb_svg( string $template ): string {
		$custom = (string) apply_filters( 'bdrvw_gr_template_thumb', '', $template, 'grid', Bdrvw_Frontend::template_skin( $template ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$open  = '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">';
		$close = '</svg>';
		
		$body = static function ( string $avatar, string $name, string $text, string $star = '#f59e0b' ): string {
			return '<circle cx="26" cy="30" r="7" fill="' . $avatar . '"/>'
				. '<rect x="38" y="25" width="38" height="4" rx="2" fill="' . $name . '"/>'
				. '<g fill="' . $star . '"><rect x="38" y="33" width="4" height="4" rx="1"/><rect x="44" y="33" width="4" height="4" rx="1"/><rect x="50" y="33" width="4" height="4" rx="1"/></g>'
				. '<rect x="20" y="47" width="80" height="4" rx="2" fill="' . $text . '"/>'
				. '<rect x="20" y="55" width="60" height="4" rx="2" fill="' . $text . '"/>';
		};
		switch ( $template ) {
			case 'template_3':
				return $open
					. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#ffffff"/>'
					. '<rect x="12" y="9" width="96" height="62" rx="15" fill="#ffffff" stroke="#eef1f6"/>'
					. '<circle cx="60" cy="25" r="10" fill="#e8eefb"/><circle cx="60" cy="25" r="7.5" fill="#bfdbfe"/>'
					. '<rect x="44" y="39" width="32" height="4" rx="2" fill="#475569"/>'
					. '<g fill="#f59e0b"><rect x="44" y="47" width="4" height="4" rx="1"/><rect x="50" y="47" width="4" height="4" rx="1"/><rect x="56" y="47" width="4" height="4" rx="1"/><rect x="62" y="47" width="4" height="4" rx="1"/><rect x="68" y="47" width="4" height="4" rx="1"/></g>'
					. '<rect x="30" y="57" width="60" height="3.5" rx="1.75" fill="#cbd5e1"/>'
					. '<rect x="38" y="63.5" width="44" height="3.5" rx="1.75" fill="#cbd5e1"/>'
					. $close;
			case 'template_4': 
				return $open
					. '<defs><linearGradient id="brgribbon" x1="0" y1="0" x2="0" y2="1">'
					. '<stop offset="0" stop-color="#4285F4"/><stop offset="0.45" stop-color="#34A853"/><stop offset="0.72" stop-color="#FBBC05"/><stop offset="1" stop-color="#EA4335"/>'
					. '</linearGradient></defs>'
					. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#ffffff"/>'
					. '<rect x="12" y="11" width="96" height="58" rx="11" fill="#ffffff" stroke="#e9edf3"/>'
					. '<rect x="12" y="11" width="4" height="58" rx="2" fill="url(#brgribbon)"/>'
					. '<text x="86" y="40" font-family="Georgia, serif" font-size="40" fill="#4285F4" opacity="0.10">&#8221;</text>'
					. '<circle cx="32" cy="30" r="7" fill="#bfdbfe"/>'
					. '<rect x="44" y="25" width="36" height="4" rx="2" fill="#475569"/>'
					. '<g fill="#f59e0b"><rect x="44" y="33" width="4" height="4" rx="1"/><rect x="50" y="33" width="4" height="4" rx="1"/><rect x="56" y="33" width="4" height="4" rx="1"/></g>'
					. '<rect x="26" y="47" width="76" height="4" rx="2" fill="#cbd5e1"/>'
					. '<rect x="26" y="55" width="58" height="4" rx="2" fill="#cbd5e1"/>'
					. $close;
			case 'template_5': 
				return $open
					. '<defs><linearGradient id="brgbanner" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4285F4"/><stop offset="1" stop-color="#6366f1"/></linearGradient><clipPath id="brgb5"><rect x="12" y="10" width="96" height="60" rx="11"/></clipPath></defs>'
					. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#ffffff"/>'
					. '<rect x="12" y="10" width="96" height="60" rx="11" fill="#ffffff" stroke="#eceef3"/>'
					. '<g clip-path="url(#brgb5)"><rect x="12" y="10" width="96" height="26" fill="url(#brgbanner)"/></g>'
					. '<circle cx="28" cy="23" r="7.5" fill="#ffffff"/><circle cx="28" cy="23" r="6" fill="#bfdbfe"/>'
					. '<rect x="40" y="19" width="40" height="4" rx="2" fill="#ffffff"/>'
					. '<rect x="40" y="26" width="26" height="3" rx="1.5" fill="#ffffff" opacity="0.7"/>'
					. '<g fill="#f59e0b"><rect x="20" y="44" width="4" height="4" rx="1"/><rect x="26" y="44" width="4" height="4" rx="1"/><rect x="32" y="44" width="4" height="4" rx="1"/></g>'
					. '<rect x="20" y="54" width="80" height="3.5" rx="1.75" fill="#cbd5e1"/>'
					. '<rect x="20" y="61" width="60" height="3.5" rx="1.75" fill="#cbd5e1"/>'
					. $close;
			case 'template_6': 
				return $open
					. '<defs><linearGradient id="brgpastel" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f3f6ff"/><stop offset="1" stop-color="#fdf3ff"/></linearGradient></defs>'
					. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#ffffff"/>'
					. '<rect x="12" y="10" width="96" height="60" rx="16" fill="url(#brgpastel)" stroke="#ffffff"/>'
					. '<circle cx="26" cy="24" r="8" fill="#c7d2fe"/>'
					. '<rect x="18" y="38" width="42" height="4" rx="2" fill="#312e81"/>'
					. '<g fill="#f59e0b"><rect x="18" y="46" width="4" height="4" rx="1"/><rect x="24" y="46" width="4" height="4" rx="1"/><rect x="30" y="46" width="4" height="4" rx="1"/><rect x="36" y="46" width="4" height="4" rx="1"/></g>'
					. '<rect x="18" y="56" width="82" height="4" rx="2" fill="#a5b4fc"/>'
					. '<rect x="18" y="63" width="60" height="4" rx="2" fill="#a5b4fc"/>'
					. $close;
		}
		return '';
	}

	/** Grid-layout description for a style (Styles 3–6 carry grid-specific designs). */
	protected static function grid_description( string $template ): string {
		$custom = (string) apply_filters( 'bdrvw_gr_template_description', '', $template, 'grid' );
		if ( '' !== $custom ) {
			return $custom;
		}
		switch ( $template ) {
			case 'template_3':
				return __( 'Spotlight — centred testimonial cards with a haloed avatar and a deep, lifted shadow.', 'boldreview' );
			case 'template_4':
				return __( 'Editorial — crisp cards with a Google-colour side ribbon and a quote watermark.', 'boldreview' );
			case 'template_5':
				return __( 'Banner — a gradient profile header over a clean white body.', 'boldreview' );
			case 'template_6':
				return __( 'Frosted Pastel — left-aligned soft tinted glass cards.', 'boldreview' );
		}
		return '';
	}

	/** Template-card SVG thumbnail. */
	protected static function template_thumb_svg( string $template ): string {

		$skin   = Bdrvw_Frontend::template_skin( $template );
		$custom = (string) apply_filters( 'bdrvw_gr_template_thumb', '', $template, 'default', $skin );
		if ( '' !== $custom ) {
			return $custom;
		}
		if ( '' !== $skin ) {
			
			$body = static function ( string $avatar, string $name, string $text, string $star = '#f59e0b' ): string {
				return '<circle cx="26" cy="30" r="7" fill="' . $avatar . '"/>'
					. '<rect x="38" y="25" width="38" height="4" rx="2" fill="' . $name . '"/>'
					. '<g fill="' . $star . '"><rect x="38" y="33" width="4" height="4" rx="1"/><rect x="44" y="33" width="4" height="4" rx="1"/><rect x="50" y="33" width="4" height="4" rx="1"/></g>'
					. '<rect x="20" y="47" width="80" height="4" rx="2" fill="' . $text . '"/>'
					. '<rect x="20" y="55" width="60" height="4" rx="2" fill="' . $text . '"/>';
			};
			$open  = '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">';
			$close = '</svg>';

			switch ( $skin ) {
				case 'drop-shadow':
					return $open
						. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#f4f6f9"/>'
						. '<rect x="15" y="16" width="94" height="58" rx="11" fill="#9aa6b8" opacity=".4"/>'
						. '<rect x="12" y="10" width="96" height="60" rx="11" fill="#ffffff"/>'
						. $body( '#bfdbfe', '#475569', '#cbd5e1' )
						. $close;
				case 'light-contrast':
					return $open
						. '<rect x="0" y="0" width="120" height="80" rx="6" fill="#ffffff"/>'
						. '<rect x="12" y="10" width="96" height="60" rx="9" fill="#ffffff" stroke="#94a3b8" stroke-width="2"/>'
						. $body( '#bfdbfe', '#334155', '#cbd5e1' )
						. $close;
			}
		}
		if ( 'template_4' === $template ) {
			
			$card = static function ( int $y ) {
				return '<rect x="10" y="' . $y . '" width="100" height="26" rx="4" fill="#fff" stroke="#e8eaed"/>'
					. '<rect x="10" y="' . $y . '" width="3" height="26" fill="#4285F4"/>'
					. '<circle cx="22" cy="' . ( $y + 9 ) . '" r="5" fill="#bfdbfe"/>'
					. '<rect x="31" y="' . ( $y + 5 ) . '" width="34" height="4" rx="2" fill="#94a3b8"/>'
					. '<g fill="#f59e0b"><rect x="31" y="' . ( $y + 12 ) . '" width="4" height="4" rx="1"/><rect x="37" y="' . ( $y + 12 ) . '" width="4" height="4" rx="1"/><rect x="43" y="' . ( $y + 12 ) . '" width="4" height="4" rx="1"/></g>'
					. '<rect x="31" y="' . ( $y + 19 ) . '" width="62" height="3" rx="1.5" fill="#cbd5e1"/>';
			};
			return '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">'
				. '<rect x="2" y="2" width="116" height="76" rx="6" fill="#fff" stroke="#e5e7eb"/>'
				. $card( 9 )
				. $card( 45 )
				. '</svg>';
		}
		if ( 'template_3' === $template ) {
			
			return '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">'
				. '<rect x="2" y="2" width="116" height="76" rx="6" fill="#fff" stroke="#e5e7eb"/>'
				. '<rect x="10" y="9" width="14" height="14" rx="3" fill="#bfdbfe"/>'
				. '<rect x="28" y="10" width="34" height="5" rx="2" fill="#475569"/>'
				. '<g fill="#f59e0b">'
				. '<rect x="28" y="18" width="5" height="5" rx="1"/><rect x="35" y="18" width="5" height="5" rx="1"/><rect x="42" y="18" width="5" height="5" rx="1"/><rect x="49" y="18" width="5" height="5" rx="1"/><rect x="56" y="18" width="5" height="5" rx="1" opacity=".4"/>'
				. '</g>'
				. '<rect x="34" y="29" width="52" height="11" rx="5.5" fill="none" stroke="#f5a623"/>'
				. '<rect x="45" y="33" width="30" height="3" rx="1.5" fill="#f5a623"/>'
				. '<rect x="10" y="46" width="100" height="26" rx="4" fill="#fff" stroke="#e8eaed"/>'
				. '<rect x="10" y="46" width="3" height="26" fill="#4285F4"/>'
				. '<circle cx="22" cy="55" r="5" fill="#bfdbfe"/>'
				. '<rect x="31" y="51" width="34" height="4" rx="2" fill="#94a3b8"/>'
				. '<g fill="#f59e0b"><rect x="31" y="58" width="4" height="4" rx="1"/><rect x="37" y="58" width="4" height="4" rx="1"/><rect x="43" y="58" width="4" height="4" rx="1"/></g>'
				. '<rect x="31" y="65" width="62" height="3" rx="1.5" fill="#cbd5e1"/>'
				. '</svg>';
		}
		if ( 'template_2' === $template ) {
			return '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">'
				. '<rect x="2" y="2" width="116" height="76" rx="6" fill="#fff" stroke="#e5e7eb"/>'
				. '<text x="10" y="22" font-family="Georgia, serif" font-size="22" fill="#f59e0b">&#8220;</text>'
				. '<rect x="12" y="28" width="80" height="4" rx="2" fill="#cbd5e1"/>'
				. '<rect x="12" y="36" width="96" height="4" rx="2" fill="#cbd5e1"/>'
				. '<rect x="12" y="44" width="64" height="4" rx="2" fill="#cbd5e1"/>'
				. '<circle cx="94" cy="62" r="9" fill="#fde68a"/>'
				. '<rect x="12" y="58" width="48" height="4" rx="2" fill="#94a3b8"/>'
				. '<rect x="12" y="65" width="36" height="3" rx="1.5" fill="#cbd5e1"/>'
				. '</svg>';
		}
		return '<svg viewBox="0 0 120 80" width="120" height="80" role="img" aria-hidden="true">'
			. '<rect x="2" y="2" width="116" height="76" rx="6" fill="#fff" stroke="#e5e7eb"/>'
			. '<circle cx="16" cy="20" r="8" fill="#bfdbfe"/>'
			. '<rect x="30" y="14" width="50" height="5" rx="2" fill="#475569"/>'
			. '<g fill="#f59e0b">'
			. '<rect x="30" y="22" width="6" height="6" rx="1"/><rect x="38" y="22" width="6" height="6" rx="1"/><rect x="46" y="22" width="6" height="6" rx="1"/><rect x="54" y="22" width="6" height="6" rx="1"/><rect x="62" y="22" width="6" height="6" rx="1" opacity=".4"/>'
			. '</g>'
			. '<rect x="10" y="40" width="100" height="4" rx="2" fill="#cbd5e1"/>'
			. '<rect x="10" y="48" width="96" height="4" rx="2" fill="#cbd5e1"/>'
			. '<rect x="10" y="56" width="72" height="4" rx="2" fill="#cbd5e1"/>'
			. '</svg>';
	}
}
