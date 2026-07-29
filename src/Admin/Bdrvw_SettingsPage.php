<?php
/**
 * Settings page renderer — per-module settings with the Review Collection sidebar.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Modules;
use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders BoldReview module settings.
 */
class Bdrvw_SettingsPage {

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
	 * Top-level setting keys a given module is responsible for.
	 * Used to scope per-module save so we don't blow away other modules' settings.
	 *
	 * @return array<int,string>
	 */
	public static function module_keys( string $module ): array {
		$def = Bdrvw_Modules::get( $module );
		if ( $def && ! empty( $def['section_keys'] ) ) {
			return (array) $def['section_keys'];
		}
		return array();
	}

	/**
	 * Resolve the active module from query string. Falls back to first active.
	 */
	protected function current_module(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		$defs   = Bdrvw_Modules::definitions();
		if ( $module && isset( $defs[ $module ] ) ) {
			return $module;
		}
		$active = Bdrvw_Modules::active();
		if ( ! empty( $active ) ) {
			return $active[0];
		}
		
		$first = array_keys( $defs );
		return $first[0] ?? 'custom_reviews';
	}

	/**
	 * Render the page.
	 */
	public function render(): void {

		$s        = $this->settings->all();
		$current  = $this->current_module();
		$def      = Bdrvw_Modules::get( $current );
		$is_on    = Bdrvw_Modules::is_active( $current );
		$active   = Bdrvw_Modules::active();
		?>
		<div class="wrap bdrvw-wrap">
			<?php
			$notice_key = 'bdrvw_admin_notice_' . get_current_user_id();
			$notice     = get_transient( $notice_key );
			if ( $notice ) {
				delete_transient( $notice_key );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'boldreview' ) . '</p></div>';
			}
			?>
			<div class="bdrvw-app">
				<header class="bdrvw-app__header">
					<div class="bdrvw-app__brand">
						<span class="bdrvw-app__logo">B</span>
						<div>
							<h1><?php esc_html_e( 'Review Collection', 'boldreview' ); ?></h1>
							<p class="bdrvw-app__tag"><?php esc_html_e( 'Module settings', 'boldreview' ); ?></p>
						</div>
					</div>
					<div class="bdrvw-app__meta">
						<a href="https://themewant.com/" target="_blank" rel="noopener noreferrer" class="bdrvw-btn-upgrade">
							<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
							<?php esc_html_e( 'Upgrade to Pro', 'boldreview' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bdrvw' ) ); ?>" class="bdrvw-btn-secondary">
							<?php esc_html_e( '← Back to Dashboard', 'boldreview' ); ?>
						</a>
					</div>
				</header>

				<form method="post" action="" class="bdrvw-app__body" id="bdrvw-settings-form">
					<?php wp_nonce_field( 'bdrvw_save_settings', 'bdrvw_settings_nonce' ); ?>
					<input type="hidden" name="bdrvw_settings_module" value="<?php echo esc_attr( $current ); ?>" />

					<aside class="bdrvw-sidebar" aria-label="<?php esc_attr_e( 'Modules', 'boldreview' ); ?>">
						<div class="bdrvw-sidebar__section">
							<h3 class="bdrvw-sidebar__title"><?php esc_html_e( 'Dashboard', 'boldreview' ); ?></h3>
							<nav class="bdrvw-nav">
								<a class="bdrvw-nav__item" href="<?php echo esc_url( admin_url( 'admin.php?page=bdrvw&view=all' ) ); ?>">
									<span class="dashicons dashicons-screenoptions"></span>
									<span class="bdrvw-nav__label"><?php esc_html_e( 'All Modules', 'boldreview' ); ?></span>
								</a>
							</nav>
						</div>

						<div class="bdrvw-sidebar__section">
							<h3 class="bdrvw-sidebar__title"><?php esc_html_e( 'Modules', 'boldreview' ); ?></h3>
							<nav class="bdrvw-nav">
								<?php foreach ( Bdrvw_Modules::definitions() as $slug => $mdef ) :
									$mod_on = in_array( $slug, $active, true );
									?>
									<a class="bdrvw-nav__item<?php echo $slug === $current ? ' is-active' : ''; ?><?php echo $mod_on ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'bdrvw-settings', 'module' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
										<span class="bdrvw-nav__module-dot" aria-hidden="true"></span>
										<span class="bdrvw-nav__label"><?php echo esc_html( $mdef['label'] ); ?></span>
									</a>
								<?php endforeach; ?>
							</nav>
						</div>

						<div class="bdrvw-sidebar__section">
							<h3 class="bdrvw-sidebar__title"><?php esc_html_e( 'Manage', 'boldreview' ); ?></h3>
							<nav class="bdrvw-nav">
								<a class="bdrvw-nav__item" href="<?php echo esc_url( admin_url( 'admin.php?page=bdrvw-reviews' ) ); ?>">
									<span class="dashicons dashicons-format-status"></span>
									<span class="bdrvw-nav__label"><?php esc_html_e( 'All Reviews', 'boldreview' ); ?></span>
								</a>
							</nav>
						</div>
					</aside>

					<section class="bdrvw-content">
						<?php if ( $def ) : ?>
							<div class="bdrvw-page-head">
								<div>
									<h2><?php echo esc_html( $def['label'] ); ?> <?php esc_html_e( 'Settings', 'boldreview' ); ?></h2>
									<p><?php echo esc_html( $def['description'] ); ?></p>
								</div>
							</div>

							<div class="bdrvw-module-status<?php echo $is_on ? '' : ' is-off'; ?>">
								<strong class="bdrvw-module-status__label">
									<?php
									printf(
										/* translators: %s: module display name (e.g. "Google Reviews"). */
										esc_html__( 'Enable %s', 'boldreview' ),
										esc_html( (string) ( $def['label'] ?? __( 'module', 'boldreview' ) ) )
									);
									?>
								</strong>
								<label class="bdrvw-switch bdrvw-switch--lg" title="<?php echo esc_attr( $is_on ? __( 'Disable module', 'boldreview' ) : __( 'Enable module', 'boldreview' ) ); ?>">
									<input type="checkbox" data-bdrvw-module-toggle="<?php echo esc_attr( $current ); ?>" <?php checked( $is_on ); ?> />
									<span class="bdrvw-switch__track"></span>
								</label>
							</div>

							<?php $this->render_module( $current, $s ); ?>

							<div class="bdrvw-actions">
								<button type="button" class="bdrvw-btn-secondary bdrvw-reset" data-bdrvw-reset="<?php echo esc_attr( $current ); ?>">
									<?php esc_html_e( 'Reset', 'boldreview' ); ?>
								</button>
								<button type="submit" name="bdrvw_settings_submit" value="1" class="button button-primary bdrvw-btn">
									<?php esc_html_e( 'Save', 'boldreview' ); ?>
								</button>
							</div>
						<?php else : ?>
							<div class="bdrvw-card">
								<div class="bdrvw-empty"><?php esc_html_e( 'Unknown module.', 'boldreview' ); ?></div>
							</div>
						<?php endif; ?>
					</section>
				</form>
			</div>
		</div>

		<div class="bdrvw-toast" id="bdrvw-toast" role="status" aria-live="polite">
			<span class="dashicons dashicons-yes"></span>
			<span class="bdrvw-toast__text"><?php esc_html_e( 'Saved', 'boldreview' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Dispatch to the module's renderer.
	 */
	protected function render_module( string $module, array $s ): void {
		switch ( $module ) {
			case 'google_reviews':
				$this->module_google_reviews( $s );
				break;
			case 'collection_review':
				$this->module_collection_review( $s );
				break;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Module: Google Reviews                                                 */
	/* --------------------------------------------------------------------- */

	protected function module_google_reviews( array $s ): void {
		
		$plugin = \BoldReview\Plugin\Bdrvw_Plugin::instance();
		$module = $plugin->get( 'module_google_reviews' );
		if ( $module instanceof \BoldReview\Plugin\Modules\GoogleReviews\Bdrvw_Module ) {
			$module->render_settings( $s );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Module: Collection Review (general + form + criteria + display tabs)   */
	/* --------------------------------------------------------------------- */

	protected function module_collection_review( array $s ): void {
		$tabs = array(
			'general' => __( 'General Setting', 'boldreview' ),
			'fields'  => __( 'Form Fields', 'boldreview' ),
			'summary'  => __( 'Rating Summary', 'boldreview' ),
			'display'  => __( 'Display', 'boldreview' ),
			'recaptcha' => __( 'reCAPTCHA', 'boldreview' ),
			'advanced' => __( 'Advanced Settings', 'boldreview' ),
			'uses'     => __( 'Uses', 'boldreview' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $current_tab ] ) ) {
			$current_tab = 'general';
		}
		?>
		<nav class="bdrvw-tabs" role="tablist">
			<?php foreach ( $tabs as $tab_key => $tab_label ) :
				$tab_url = add_query_arg(
					array(
						'page'   => 'bdrvw-settings',
						'module' => 'collection_review',
						'tab'    => $tab_key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a
					class="bdrvw-tabs__tab<?php echo $current_tab === $tab_key ? ' is-active' : ''; ?><?php echo 'uses' === $tab_key ? ' bdrvw-tabs__tab--end bdrvw-tabs__tab--guide' : ''; ?>"
					href="<?php echo esc_url( $tab_url ); ?>"
					role="tab"
					data-tab="<?php echo esc_attr( $tab_key ); ?>"
					aria-selected="<?php echo $current_tab === $tab_key ? 'true' : 'false'; ?>"
				><?php if ( 'uses' === $tab_key ) : ?><span class="dashicons dashicons-book-alt" aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="bdrvw-tab-panels">
			<div class="bdrvw-tab-panel<?php echo 'general' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="general" role="tabpanel">
				<?php $this->cr_tab_general( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'fields' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="fields" role="tabpanel">
				<?php $this->render_form_fields_card( $s ); ?>
				<?php $this->render_criteria_card( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'summary' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="summary" role="tabpanel">
				<?php $this->render_rating_summary_card( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'display' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="display" role="tabpanel">
				<?php $this->cr_tab_display( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'recaptcha' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="recaptcha" role="tabpanel">
				<?php $this->cr_tab_recaptcha( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'advanced' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="advanced" role="tabpanel">
				<?php $this->cr_tab_advanced( $s ); ?>
			</div>

			<div class="bdrvw-tab-panel<?php echo 'uses' === $current_tab ? ' is-active' : ''; ?>" data-tab-panel="uses" role="tabpanel">
				<?php $this->cr_tab_uses(); ?>
			</div>
		</div>

		<input type="hidden" name="bdrvw_settings_tab" value="<?php echo esc_attr( $current_tab ); ?>" />
		<?php
	}

	/**
	 * Collection Review → "General" tab content.
	 */
	protected function cr_tab_general( array $s ): void {
		
		// Offer every public post type (including custom ones like WooCommerce
		// products), minus WordPress media attachments.
		$excluded_pts = array( 'attachment' );
		/**
		 * Filter the post types excluded from the "Enabled post types" choices.
		 *
		 * @param array<int,string> $excluded_pts Post-type slugs to hide.
		 */
		$excluded_pts = (array) apply_filters( 'bdrvw_excluded_post_types', $excluded_pts );
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true, 'show_in_nav_menus' => true ), 'objects' ) as $pt_name => $pt ) {
			if ( in_array( $pt_name, $excluded_pts, true ) || ! is_post_type_viewable( $pt ) ) {
				continue;
			}
			$post_types[ $pt_name ] = $pt;
		}
		?>
		<div class="bdrvw-card">
			<?php
			$cpt_default = 'post';
			?>
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-admin-generic"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'General', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Where the review form appears, who can submit, and how submissions are moderated.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-cpt-filter bdrvw-cpt-filter--header">
					<label for="bdrvw-cpt-filter"><?php esc_html_e( 'Settings based on CPT', 'boldreview' ); ?></label>
					<select id="bdrvw-cpt-filter" class="bdrvw-input" data-bdrvw-cpt-filter>
						<?php
						foreach ( array( 'post', 'product' ) as $cpt_val ) :
							$cpt_obj = get_post_type_object( $cpt_val );
							$cpt_lbl = $cpt_obj ? $cpt_obj->labels->singular_name : ucfirst( $cpt_val );
							?>
							<option value="<?php echo esc_attr( $cpt_val ); ?>" <?php selected( $cpt_default, $cpt_val ); ?>><?php echo esc_html( $cpt_lbl ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</header>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-enabled-post-types"><?php esc_html_e( 'Enabled post types', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Allow reviews on these post types. Posts are enabled by default — add or remove any others.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<div class="bdrvw-cgroup__pt-row bdrvw-cgroup__pt-row--solo">
						<select
							id="bdrvw-enabled-post-types"
							class="bdrvw-input bdrvw-input--block"
							name="bdrvw_settings[enabled_post_types][]"
							multiple
							data-bdrvw-pt-select
							data-placeholder="<?php esc_attr_e( 'Select post types…', 'boldreview' ); ?>"
						>
							<?php foreach ( $post_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( in_array( $pt->name, (array) $s['enabled_post_types'], true ) ); ?>>
									<?php echo esc_html( $pt->labels->singular_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<?php
			$wc_who_can   = (string) ( $s['wc_review_who_can'] ?? 'anyone' );
			$wc_ver_label = (int) ( $s['wc_review_verified_label'] ?? 1 );
			$wc_order_st  = (string) ( $s['wc_review_order_status'] ?? 'completed' );
			$wc_statuses  = array(
				'pending'    => __( 'Pending payment', 'boldreview' ),
				'processing' => __( 'Processing', 'boldreview' ),
				'on-hold'    => __( 'On hold', 'boldreview' ),
				'completed'  => __( 'Completed', 'boldreview' ),
				'cancelled'  => __( 'Cancelled', 'boldreview' ),
			);
			?>
			<div class="bdrvw-wc-only" data-bdrvw-wc-only hidden>
				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Who can send reviews on your shop?', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Restrict who may review WooCommerce products.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control bdrvw-radios bdrvw-radios--stack">
						<label class="bdrvw-radio">
							<input type="radio" name="bdrvw_settings[wc_review_who_can]" value="anyone" <?php checked( $wc_who_can, 'anyone' ); ?> />
							<span><?php esc_html_e( 'Anyone', 'boldreview' ); ?></span>
						</label>
						<label class="bdrvw-radio">
							<input type="radio" name="bdrvw_settings[wc_review_who_can]" value="verified" <?php checked( $wc_who_can, 'verified' ); ?> />
							<span><?php esc_html_e( 'Reviews can only be left by "verified owners"', 'boldreview' ); ?></span>
						</label>
					</div>
				</div>

				<?php $this->toggle_row( 'wc_review_verified_label', __( 'Show "Verified owner" label on customer reviews', 'boldreview' ), __( 'Display a "Verified owner" badge on reviews left by customers who purchased the product.', 'boldreview' ), $wc_ver_label ); ?>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label for="bdrvw-wc-order-status"><?php esc_html_e( 'Eligibility for send reviews', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'In which order status your customer will be able to send reviews?', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<select id="bdrvw-wc-order-status" class="bdrvw-input bdrvw-input--block" name="bdrvw_settings[wc_review_order_status]">
							<?php foreach ( $wc_statuses as $st_key => $st_label ) :
								$st_slug = preg_replace( '/^wc-/', '', (string) $st_key ); ?>
								<option value="<?php echo esc_attr( $st_slug ); ?>" <?php selected( $wc_order_st, $st_slug ); ?>><?php echo esc_html( $st_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<?php
			$excluded_ids = isset( $s['excluded_post_ids'] ) && is_array( $s['excluded_post_ids'] )
				? array_map( 'intval', $s['excluded_post_ids'] )
				: array();
			$excluded_preseed = array();
			if ( ! empty( $excluded_ids ) ) {
				$preseed_posts = get_posts(
					array(
						'post__in'         => $excluded_ids,
						'post_type'        => 'any',
						'post_status'      => 'any',
						'numberposts'      => count( $excluded_ids ),
						'orderby'          => 'post__in',
						'suppress_filters' => false,
					)
				);
				foreach ( (array) $preseed_posts as $p ) {
					$excluded_preseed[] = array(
						'id'    => (int) $p->ID,
						'label' => '' !== $p->post_title ? $p->post_title : ( '#' . (int) $p->ID ),
					);
				}
			}
			?>
			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-excluded-posts"><?php esc_html_e( 'Exclude pages', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Pick specific WordPress pages where the review form should NOT auto-show.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<div class="bdrvw-cgroup__pt-row bdrvw-cgroup__pt-row--solo">
						<select
							id="bdrvw-excluded-posts"
							name="bdrvw_settings[excluded_post_ids][]"
							multiple
							data-bdrvw-exclude-select
							data-post-types="page"
						>
							<?php foreach ( $excluded_preseed as $seed ) : ?>
								<option value="<?php echo (int) $seed['id']; ?>" selected><?php echo esc_html( $seed['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>

			<?php
			$current_pos = isset( $s['auto_inject_position'] ) ? (string) $s['auto_inject_position'] : 'after_content';
			$pos_options = array(
				'after_content'  => __( 'After the content (default)', 'boldreview' ),
				'before_content' => __( 'Before the content', 'boldreview' ),
				'disabled'       => __( 'Disabled — use shortcode manually', 'boldreview' ),
			);
			if ( ! isset( $pos_options[ $current_pos ] ) ) {
				$current_pos = 'after_content';
			}
			?>
			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-auto-inject"><?php esc_html_e( 'Display Position', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint">
						<?php
						printf(
							/* translators: %s: shortcode tag wrapped in <code>. */
							esc_html__( 'Choose where the review form auto-appears on single posts/pages. Pick “Disabled” if you only want to place it manually via the %s shortcode.', 'boldreview' ),
							'<code class="bdrvw-code-inline">[bold_review_form]</code>'
						);
						?>
					</p>
				</div>
				<div class="bdrvw-row__control">
					<select
						id="bdrvw-auto-inject"
						class="bdrvw-input bdrvw-input--block"
						name="bdrvw_settings[auto_inject_position]"
					>
						<?php foreach ( $pos_options as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_pos, $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<?php $this->toggle_row( 'notify_admin_on_submit', __( 'Email admin on new review', 'boldreview' ), __( 'Send a notification email to the site admin every time a visitor submits a review.', 'boldreview' ), (int) ( $s['notify_admin_on_submit'] ?? 1 ) ); ?>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-max-reviews-per-user"><?php esc_html_e( 'Max reviews per user per post', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'How many reviews a single visitor (or logged-in user) can submit on the same post. Set 0 for unlimited.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<input
						id="bdrvw-max-reviews-per-user"
						class="bdrvw-input bdrvw-input--block"
						type="number"
						min="0"
						max="50"
						name="bdrvw_settings[max_reviews_per_user]"
						value="<?php echo esc_attr( (int) ( $s['max_reviews_per_user'] ?? 1 ) ); ?>"
					/>
				</div>
			</div>
			<?php $this->toggle_row( 'require_login', __( 'Restrict to registered users', 'boldreview' ), __( 'Only logged-in users can submit reviews.', 'boldreview' ), (int) $s['require_login'] ); ?>
			<?php $this->toggle_row( 'auto_approve', __( 'Auto-approve reviews', 'boldreview' ), __( 'New reviews go live without moderation.', 'boldreview' ), (int) $s['auto_approve'] ); ?>
			<?php $this->toggle_row( 'enable_user_review', __( 'Enable user review', 'boldreview' ), __( 'Allow visitors and registered users to submit reviews.', 'boldreview' ), (int) ( $s['enable_user_review'] ?? 1 ) ); ?>
			<?php $this->toggle_row( 'enable_author_review', __( 'Enable author review', 'boldreview' ), __( 'Show the post author’s aggregated rating alongside reviews.', 'boldreview' ), (int) ( $s['enable_author_review'] ?? 0 ) ); ?>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-per-page"><?php esc_html_e( 'Reviews per page', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Pagination limit for the frontend reviews list.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<input id="bdrvw-per-page" class="bdrvw-input bdrvw-input--block" type="number" min="1" max="100" name="bdrvw_settings[reviews_per_page]" value="<?php echo esc_attr( (int) $s['reviews_per_page'] ); ?>" />
				</div>
			</div>
		</div>

		<?php $this->cr_blacklist_card( $s ); ?>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-chart-bar"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Rating Summary Style', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'The global rating-summary layout used on every post and page. Author-review groups in the Rating Summary tab override this for the specific posts or pages they target.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php $this->toggle_row( 'show_rating_summary', __( 'Show rating summary', 'boldreview' ), __( 'Display the aggregated rating summary above the reviews on the frontend.', 'boldreview' ), (int) ( $s['show_rating_summary'] ?? 1 ) ); ?>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label><?php esc_html_e( 'Summary style', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Visual layout for the global rating summary. Each preview matches the frontend output.', 'boldreview' ); ?></p>
				</div>
				<?php $this->render_summary_style_grid( 'bdrvw_settings[summary_style]', (string) ( $s['summary_style'] ?? 'bars' ) ); ?>
			</div>

			<?php $this->toggle_row( 'show_average', __( 'Show average rating', 'boldreview' ), __( 'Display the overall average score in the global summary.', 'boldreview' ), (int) ( $s['show_average'] ?? 1 ) ); ?>
		</div>
		<?php
	}

	/**
	 * Collection Review → General tab: the "Blacklist" moderation card.
	 *
	 * Lets the site owner filter incoming reviews against either BoldReview's own
	 * word list or WordPress' Disallowed Comment Keys, then choose whether a match
	 * is held for approval or rejected outright.
	 */
	protected function cr_blacklist_card( array $s ): void {
		$bl          = isset( $s['blacklist'] ) && is_array( $s['blacklist'] ) ? $s['blacklist'] : array();
		$integration = isset( $bl['integration'] ) ? (string) $bl['integration'] : 'comments';
		$action      = isset( $bl['action'] ) ? (string) $bl['action'] : 'unapprove';
		$words       = isset( $bl['words'] ) ? (string) $bl['words'] : '';

		$integrations = array(
			'comments'   => __( 'Use the WordPress Disallowed Comment Keys', 'boldreview' ),
			'boldreview' => __( 'Use the BoldReview Blacklist', 'boldreview' ),
		);
		if ( ! isset( $integrations[ $integration ] ) ) {
			$integration = 'comments';
		}

		$actions = array(
			'unapprove' => __( 'Require approval', 'boldreview' ),
			'reject'    => __( 'Reject submission', 'boldreview' ),
		);
		if ( ! isset( $actions[ $action ] ) ) {
			$action = 'unapprove';
		}
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-shield"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Blacklist', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Screen incoming reviews for banned words. When the reviewer name, email, website, IP or review text matches, the chosen action is taken automatically.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-blacklist-integration"><?php esc_html_e( 'Blacklist', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint">
						<?php
						printf(
							wp_kses(
								/* translators: %s: link to the WordPress Discussion Settings page. */
								__( 'Choose which blacklist to use for reviews. The <a href="%s">Disallowed Comment Keys</a> option can be found on the WordPress Discussion Settings page.', 'boldreview' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'options-discussion.php' ) )
						);
						?>
					</p>
				</div>
				<div class="bdrvw-row__control">
					<select id="bdrvw-blacklist-integration" class="bdrvw-input bdrvw-input--block" name="bdrvw_settings[blacklist][integration]" data-bdrvw-blacklist-integration>
						<?php foreach ( $integrations as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $integration, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="bdrvw-row bdrvw-blacklist-words<?php echo 'comments' === $integration ? ' is-hidden' : ''; ?>" data-bdrvw-blacklist-words>
				<div class="bdrvw-row__label">
					<label for="bdrvw-blacklist-words"><?php esc_html_e( 'Blacklist words', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Enter one word or phrase per line. A review is flagged when any of these appears in the reviewer name, email, website, IP or review text. Applies when the BoldReview Blacklist is selected above.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<textarea id="bdrvw-blacklist-words" class="bdrvw-input bdrvw-input--block" rows="6" name="bdrvw_settings[blacklist][words]" placeholder="<?php esc_attr_e( "badword&#10;another phrase&#10;spammy@example.com", 'boldreview' ); ?>"><?php echo esc_textarea( $words ); ?></textarea>
				</div>
			</div>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-blacklist-action"><?php esc_html_e( 'Blacklist Action', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Choose what happens when a review is blacklisted. “Require approval” holds it as pending; “Reject submission” discards it without saving.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<select id="bdrvw-blacklist-action" class="bdrvw-input bdrvw-input--block" name="bdrvw_settings[blacklist][action]">
						<?php foreach ( $actions as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $action, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Collection Review → "Display" tab content.
	 *
	 * The Display tab is now scoped to: template choice, the per-comment field
	 * toggles, and the rating input style. The summary-style + show_X aggregate
	 * toggles moved to the Rating Summary tab where they conceptually belong.
	 */
	protected function cr_tab_display( array $s ): void {
		$comment_fields = (array) ( $s['comment_fields'] ?? array() );
		$cf             = static function ( string $key, int $fallback = 1 ) use ( $comment_fields ): int {
			return isset( $comment_fields[ $key ] ) ? (int) $comment_fields[ $key ] : $fallback;
		};
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-visibility"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Template', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Choose how each review is laid out on the frontend.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label><?php esc_html_e( 'Template', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Comment-style layout: avatar, author meta, stars, title and content.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control bdrvw-cards-grid">
					<?php foreach ( array(
						'comment' => __( 'Comment', 'boldreview' ),
					) as $val => $lbl ) : ?>
						<label class="bdrvw-template <?php echo ( $s['display_template'] ?? 'comment' ) === $val ? 'is-selected' : ''; ?>">
							<input type="radio" name="bdrvw_settings[display_template]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['display_template'] ?? 'comment', $val ); ?> />
							<span class="bdrvw-template__preview bdrvw-template__preview--<?php echo esc_attr( $val ); ?>"></span>
							<span class="bdrvw-template__label"><?php echo esc_html( $lbl ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-list-view"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Comment Box Fields', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Toggle which parts of the comment template appear on the frontend.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php $this->toggle_row( 'comment_fields[avatar]', __( 'Avatar', 'boldreview' ), __( 'Reviewer’s avatar image.', 'boldreview' ), $cf( 'avatar' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[stars]', __( 'Stars rating', 'boldreview' ), __( 'The 1–5 star rating shown at the top of the review.', 'boldreview' ), $cf( 'stars' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[author]', __( 'Author name', 'boldreview' ), __( 'The reviewer’s name.', 'boldreview' ), $cf( 'author' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[date]', __( 'Date', 'boldreview' ), __( 'The publication date of the review.', 'boldreview' ), $cf( 'date' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[title]', __( 'Review title', 'boldreview' ), __( 'The title text of the review.', 'boldreview' ), $cf( 'title' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[content]', __( 'Review content', 'boldreview' ), __( 'The body of the review.', 'boldreview' ), $cf( 'content' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[criteria]', __( 'Criteria ratings', 'boldreview' ), __( 'Show this reviewer’s per-criterion scores under their review. The overall criteria averages still show in the summary at the top.', 'boldreview' ), $cf( 'criteria' ) ); ?>
			<?php $this->toggle_row( 'comment_fields[url]', __( 'Website URL', 'boldreview' ), __( 'Show the reviewer’s submitted website as a clickable link below the review title. Requires the “Website URL” form field to be enabled too.', 'boldreview' ), $cf( 'url', 0 ) ); ?>
			<?php $this->toggle_row( 'comment_fields[email]', __( 'Reviewer email', 'boldreview' ), __( 'Show the reviewer’s email next to the author name in the meta line. Requires the “Reviewer email” form field to be enabled too.', 'boldreview' ), $cf( 'email', 0 ) ); ?>
			<?php $this->toggle_row( 'form_product_header', __( 'Product header on the form', 'boldreview' ), __( 'Show the product’s image and title at the top of the review form, so the shopper can see what they are reviewing. Products only — it never appears on posts.', 'boldreview' ), (int) ( $s['form_product_header'] ?? 0 ) ); ?>
		</div>

		<?php
	}

	/**
	 * Collection Review → "reCAPTCHA" tab content.
	 *
	 * The free plugin only exposes the tab shell; the actual reCAPTCHA
	 * settings UI is a Pro feature. BoldReview Pro hooks into
	 * `bdrvw_cr_tab_recaptcha` to render its fields. When no add-on is
	 * hooked in (Pro inactive) we fall back to an upsell so the tab is
	 * never empty.
	 */
	protected function cr_tab_recaptcha( array $s ): void {
		/**
		 * Renders the reCAPTCHA tab.
		 *
		 * The free plugin always hooks its own callback here (see
		 * Bdrvw_AdminMenu::register), so the tab is drawn the same way on every
		 * install. BoldReview Pro removes that callback and renders the working
		 * v2/v3 configuration in its place.
		 *
		 * @param array<string,mixed> $s Current settings.
		 */
		do_action( 'bdrvw_cr_tab_recaptcha', $s );
	}

	/**
	 * Default reCAPTCHA tab body. Registered by the free plugin on the
	 * `bdrvw_cr_tab_recaptcha` action; BoldReview Pro removes this callback and
	 * renders the real fields instead.
	 *
	 * Same shape as the Advanced Settings rows: the feature is named and
	 * explained, with the crown and padlock where its controls would be.
	 *
	 * @param array<string,mixed> $s Current settings (unused here).
	 */
	public static function render_recaptcha_rows( $s = array() ): void {
		unset( $s );
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-shield-alt"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'reCAPTCHA', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Protect the review submission form from spam and bot entries with Google reCAPTCHA.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php
			self::pro_row(
				__( 'Google reCAPTCHA', 'boldreview' ),
				__( 'Verify every submission with reCAPTCHA v2 or v3 — site key, secret key and the score threshold that decides what gets through.', 'boldreview' )
			);
			?>
		</div>
		<?php
	}

	/**
	 * Collection Review → "Advanced Settings" tab content.
	 *
	 * The tab is a shell: every control in it is rendered by whoever hooks the
	 * action for that row. With only the free plugin installed nothing hooks, so
	 * each row falls back to a description of what the feature does — the setting
	 * is named and explained here, and BoldReview Pro replaces the placeholder
	 * with the working control in the same spot.
	 *
	 * @param array<string,mixed> $s Current settings.
	 */
	protected function cr_tab_advanced( array $s ): void {
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-admin-generic"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Advanced Settings', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Fine-tune how BoldReview behaves behind the scenes.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php
			$limit_by      = isset( $s['review_limit_by'] ) ? (string) $s['review_limit_by'] : '';
			$limit_options = array(
				''           => __( 'No Limit', 'boldreview' ),
				'email'      => __( 'By Email Address', 'boldreview' ),
				'ip_address' => __( 'By IP Address', 'boldreview' ),
			);
			if ( ! isset( $limit_options[ $limit_by ] ) ) {
				$limit_by = '';
			}
			?>
			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-review-limit-by"><?php esc_html_e( 'Limit reviews by', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Stop the same visitor reviewing a post twice while logged out, matching them on the email address they gave or the IP they submitted from. “No Limit” leaves this to the per-user limit in General Settings.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<select id="bdrvw-review-limit-by" class="bdrvw-input bdrvw-input--block" name="bdrvw_settings[review_limit_by]" data-bdrvw-limit-by>
						<?php foreach ( $limit_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $limit_by, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="bdrvw-row" data-bdrvw-limit-field="email" <?php echo 'email' === $limit_by ? '' : 'hidden'; ?>>
				<div class="bdrvw-row__label">
					<label for="bdrvw-email-whitelist"><?php esc_html_e( 'Email whitelist', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Addresses that may review the same item as often as they like — your own team, a testing account. One per line.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<textarea
						id="bdrvw-email-whitelist"
						class="bdrvw-input bdrvw-input--block"
						name="bdrvw_settings[email_whitelist]"
						rows="4"
						placeholder="team@example.com"
					><?php echo esc_textarea( (string) ( $s['email_whitelist'] ?? '' ) ); ?></textarea>
				</div>
			</div>

			<div class="bdrvw-row" data-bdrvw-limit-field="ip_address" <?php echo 'ip_address' === $limit_by ? '' : 'hidden'; ?>>
				<div class="bdrvw-row__label">
					<label for="bdrvw-blocked-ips"><?php esc_html_e( 'Blocked IP addresses', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Submissions from these addresses are refused outright, whether or not they have reviewed before. One per line; a trailing * matches a range, e.g. 203.0.113.*', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<textarea
						id="bdrvw-blocked-ips"
						class="bdrvw-input bdrvw-input--block"
						name="bdrvw_settings[blocked_ips]"
						rows="4"
						placeholder="203.0.113.42&#10;203.0.113.*"
					><?php echo esc_textarea( (string) ( $s['blocked_ips'] ?? '' ) ); ?></textarea>
				</div>
			</div>

			<?php
			/**
			 * Fires where the notification email template editor belongs.
			 *
			 * BoldReview Pro hooks here to render the template editor — the
			 * placeholder buttons ({review_title}, {review_content}, …) and the
			 * textarea whose contents replace the default admin notification email.
			 *
			 * @param array<string,mixed> $s Current settings.
			 */
			do_action( 'bdrvw_cr_advanced_notification_template', $s );

			if ( ! has_action( 'bdrvw_cr_advanced_notification_template' ) ) {
				self::pro_row(
					__( 'Notification email template', 'boldreview' ),
					__( 'Write your own admin notification email instead of the built-in one, using placeholders such as {review_title}, {review_content}, {review_author} and {review_rating}.', 'boldreview' )
				);
			}

			$this->toggle_row(
				'allow_photo_review',
				__( 'Allow photo review', 'boldreview' ),
				__( 'Allow customers to upload images with their review.', 'boldreview' ),
				(int) ( $s['allow_photo_review'] ?? 0 )
			);

			/**
			 * Renders the "Allow video review" row.
			 *
			 * The free plugin always hooks its own callback here (see
			 * Bdrvw_AdminMenu::register) — the row is part of this screen, not
			 * something conditioned on what else is installed. BoldReview Pro
			 * removes that callback and prints the working control instead.
			 *
			 * @param array<string,mixed> $s Current settings.
			 */
			do_action( 'bdrvw_cr_advanced_video_review', $s );

			/**
			 * Renders the Discord notification row.
			 *
			 * Registered by the free plugin (see Bdrvw_AdminMenu::register);
			 * BoldReview Pro removes that callback and renders the enable
			 * checkbox plus its webhook field.
			 *
			 * @param array<string,mixed> $s Current settings.
			 */
			do_action( 'bdrvw_cr_advanced_discord', $s );

			/**
			 * Renders the Slack notification row. Same arrangement as Discord.
			 *
			 * @param array<string,mixed> $s Current settings.
			 */
			do_action( 'bdrvw_cr_advanced_slack', $s );

			/**
			 * Fires at the end of the Advanced Settings card, after the rows the
			 * free plugin defines. Anything hooked here renders as a further row.
			 *
			 * @param array<string,mixed> $s Current settings.
			 */
			do_action( 'bdrvw_cr_tab_advanced', $s );
			?>
		</div>
		<?php
	}

	/**
	 * Default "Allow video review" row. Registered by the free plugin on the
	 * `bdrvw_cr_advanced_video_review` action; BoldReview Pro removes this
	 * callback and renders the working toggle in its place.
	 *
	 * @param array<string,mixed> $s Current settings (unused here).
	 */
	public static function render_video_review_row( $s = array() ): void {
		unset( $s );
		self::pro_row(
			__( 'Allow video review', 'boldreview' ),
			__( 'Let customers attach a short video to their review, alongside the photos the free plugin already accepts.', 'boldreview' )
		);
	}

	/**
	 * Default Discord notification row. Registered by the free plugin on
	 * `bdrvw_cr_advanced_discord`; BoldReview Pro removes it and renders the
	 * working control.
	 *
	 * @param array<string,mixed> $s Current settings (unused here).
	 */
	public static function render_discord_row( $s = array() ): void {
		unset( $s );
		self::pro_row(
			__( 'Discord notifications', 'boldreview' ),
			__( 'Post every new review straight into a Discord channel through an incoming webhook.', 'boldreview' )
		);
	}

	/**
	 * Default Slack notification row. Registered by the free plugin on
	 * `bdrvw_cr_advanced_slack`; BoldReview Pro removes it and renders the
	 * working control.
	 *
	 * @param array<string,mixed> $s Current settings (unused here).
	 */
	public static function render_slack_row( $s = array() ): void {
		unset( $s );
		self::pro_row(
			__( 'Slack notifications', 'boldreview' ),
			__( 'Send new reviews to a Slack channel through an incoming webhook, so the team sees them without opening the admin.', 'boldreview' )
		);
	}

	/**
	 * A settings row for a feature this plan does not include: the name, the
	 * crown badge and the explanation, with no control at all.
	 *
	 * Deliberately inert — no disabled input, nothing that looks half-usable.
	 *
	 * @param string $label Feature name.
	 * @param string $hint  What the feature does.
	 */
	protected static function pro_row( string $label, string $hint ): void {
		?>
		<div class="bdrvw-row bdrvw-row--pro">
			<div class="bdrvw-row__label">
				<span class="bdrvw-pro-row__title">
					<?php echo esc_html( $label ); ?>
					<span class="bdrvw-pro-row__badge">
						<?php echo Bdrvw_ReviewPanel::crown_svg( 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
						<?php esc_html_e( 'Available on Higher Plans', 'boldreview' ); ?>
					</span>
				</span>
				<p class="bdrvw-row__hint"><?php echo esc_html( $hint ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Collection Review → "Uses" tab content.
	 *
	 * A quick reference of the shortcodes this module registers and how/where to
	 * place them, so users can output the review form and list anywhere manually.
	 */
	protected function cr_tab_uses(): void {
		$shortcodes = array(
			array(
				'tag'     => '[bold_review_form]',
				'title'   => __( 'Review form + reviews list', 'boldreview' ),
				'desc'    => __( 'Outputs the existing reviews for the current post followed by the submission form. This is the same block that auto-appears on your enabled post types — use the shortcode to place it manually anywhere (e.g. when Display Position is set to “Disabled”).', 'boldreview' ),
			),
			array(
				'tag'     => '[bold_reviews]',
				'title'   => __( 'Reviews list only', 'boldreview' ),
				'desc'    => __( 'Outputs only the approved reviews list (with the rating summary) for the current post — without the submission form. Handy when you want to show reviews in one place and the form in another.', 'boldreview' ),
			),
		);

		$atts = array(
			array( 'post_id', __( 'ID of the post/page to show reviews for. Defaults to the current post — set this when placing the shortcode on a different page.', 'boldreview' ), '[bold_review_form post_id="42"]' ),
			array( 'limit', __( 'How many reviews to load per page. Defaults to your “Reviews per page” setting.', 'boldreview' ), '[bold_reviews limit="5"]' ),
			array( 'paged', __( 'Which page of reviews to start on. Defaults to 1.', 'boldreview' ), '[bold_reviews paged="2"]' ),
			array( 'summary_style', __( 'Override the rating-summary design for this shortcode only. Accepts: bars, point, pie, hbars, stripes, gauge, tiles, overview. Leave it off to use your Rating Summary setting.', 'boldreview' ), '[bold_review_form summary_style="pie"]' ),
			array( 'input_style', __( 'Override the rating-input control shown in the form for this shortcode only. Accepts: stars, slider, bar, square, pill. Leave it off to use your Rating Input setting.', 'boldreview' ), '[bold_review_form input_style="pill"]' ),
		);
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-shortcode"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Shortcodes', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Copy a shortcode and paste it into any post, page, or widget to place the review form or list exactly where you want it.', 'boldreview' ); ?></p>
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
		</div>

		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-admin-settings"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Optional attributes', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Both shortcodes accept these optional attributes. Layout and template always follow your Display settings, so they are not shortcode attributes.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-uses__table" role="table">
				<div class="bdrvw-uses__trow bdrvw-uses__trow--head" role="row">
					<span role="columnheader"><?php esc_html_e( 'Attribute', 'boldreview' ); ?></span>
					<span role="columnheader"><?php esc_html_e( 'What it does', 'boldreview' ); ?></span>
					<span role="columnheader"><?php esc_html_e( 'Example', 'boldreview' ); ?></span>
				</div>
				<?php foreach ( $atts as $row ) : ?>
					<div class="bdrvw-uses__trow" role="row">
						<span role="cell"><code><?php echo esc_html( $row[0] ); ?></code></span>
						<span role="cell"><?php echo esc_html( $row[1] ); ?></span>
						<span role="cell"><code><?php echo esc_html( $row[2] ); ?></code></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="bdrvw-uses__note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<p>
					<?php
					printf(
						/* translators: %s: shortcode tag wrapped in <code>. */
						esc_html__( 'On your enabled post types the %s block already appears automatically based on the Display Position setting in the General tab — you only need the shortcode for manual placement.', 'boldreview' ),
						'<code>[bold_review_form]</code>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Form fields editor card (extracted so the Custom Reviews module reuses it).
	 */
	protected function render_form_fields_card( array $s ): void {
		$fields = array(
			'author_name'  => array( __( 'Reviewer name', 'boldreview' ), 'name_label' ),
			'author_email' => array( __( 'Reviewer email', 'boldreview' ), 'email_label' ),
			'author_url'   => array( __( 'Website URL', 'boldreview' ), 'url_label' ),
			'title'        => array( __( 'Review title', 'boldreview' ), 'title_label' ),
			'content'      => array( __( 'Review summary', 'boldreview' ), 'summary_label' ),
		);
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-forms"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Form Fields', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Enable a field to show it on the review form. The label text only applies while the field is enabled.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php
			$style_enabled = (int) ( $s['rating_input_style_enabled'] ?? 1 );
			?>
			<?php // The master switch for the whole rating field — the picker below follows it. ?>
			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-rating-input-style-enabled"><?php esc_html_e( 'Rating input style', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Show the overall rating field on the review form and pick its style below. Turn off to hide the rating field entirely — visitors can then submit a review without giving an overall rating.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<?php $this->toggle_inline( 'rating_input_style_enabled', $style_enabled, array( 'data-bdrvw-style-toggle' => '1' ) ); ?>
				</div>
			</div>

			<div class="bdrvw-row bdrvw-style-options<?php echo $style_enabled ? '' : ' is-disabled'; ?>" data-bdrvw-style-options>
				<div class="bdrvw-row__label">
					<label><?php esc_html_e( 'Choose input style', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'How the main rating and each criterion are picked on the review form.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control bdrvw-radios">
					<?php foreach ( array(
						'stars'  => __( 'Stars', 'boldreview' ),
						'slider' => __( 'Slider', 'boldreview' ),
						'bar'    => __( 'Bar', 'boldreview' ),
						'square' => __( 'Squares', 'boldreview' ),
						'pill'   => __( 'Pills', 'boldreview' ),
					) as $val => $lbl ) : ?>
						<label class="bdrvw-radio">
							<input type="radio" name="bdrvw_settings[rating_input_style]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['rating_input_style'] ?? 'stars', $val ); ?> />
							<span><?php echo esc_html( $lbl ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="bdrvw-fields">
				<?php
				foreach ( $fields as $key => $info ) :
					$enabled  = (int) ( $s['fields'][ $key ] ?? 0 );
					$required = (int) ( $s['required_fields'][ $key ] ?? 0 );
					$string_k = (string) $info[1];
					$label_v  = (string) ( $s['strings'][ $string_k ] ?? '' );
					?>
					<div class="bdrvw-fields__row<?php echo $enabled ? ' is-enabled' : ''; ?>" data-field="<?php echo esc_attr( $key ); ?>">
						<div class="bdrvw-fields__head">
							<div class="bdrvw-fields__name"><?php echo esc_html( $info[0] ); ?></div>
							<div class="bdrvw-fields__toggles">
								<label class="bdrvw-fields__toggle">
									<span><?php esc_html_e( 'Enabled', 'boldreview' ); ?></span>
									<?php $this->toggle_inline( "fields[{$key}]", $enabled, array( 'data-toggle-field' => $key ) ); ?>
								</label>
								<label class="bdrvw-fields__toggle">
									<span><?php esc_html_e( 'Required', 'boldreview' ); ?></span>
									<?php $this->toggle_inline( "required_fields[{$key}]", $required ); ?>
								</label>
							</div>
						</div>
						<div class="bdrvw-fields__label" data-field-label="<?php echo esc_attr( $key ); ?>">
							<label for="bdrvw-string-<?php echo esc_attr( $string_k ); ?>"><?php esc_html_e( 'Label text', 'boldreview' ); ?></label>
							<input id="bdrvw-string-<?php echo esc_attr( $string_k ); ?>" class="bdrvw-input" type="text" name="bdrvw_settings[strings][<?php echo esc_attr( $string_k ); ?>]" value="<?php echo esc_attr( $label_v ); ?>" />
						</div>
					</div>
				<?php endforeach; ?>

				<?php
				$rating_required = (int) ( $s['required_fields']['rating'] ?? 1 );
				$rating_label    = (string) ( $s['strings']['rating_label'] ?? '' );
				?>
				<div class="bdrvw-fields__row<?php echo $style_enabled ? ' is-enabled' : ''; ?>" data-field="rating">
					<div class="bdrvw-fields__head">
						<div class="bdrvw-fields__name"><?php esc_html_e( 'Star rating', 'boldreview' ); ?></div>
						<div class="bdrvw-fields__toggles">
							<?php // "Enabled" for this field is the Rating input style switch above; a second copy of the same setting could only contradict it. ?>
							<label class="bdrvw-fields__toggle">
								<span><?php esc_html_e( 'Required', 'boldreview' ); ?></span>
								<?php $this->toggle_inline( 'required_fields[rating]', $rating_required ); ?>
							</label>
						</div>
					</div>
					<div class="bdrvw-fields__label" data-field-label="rating">
						<label for="bdrvw-string-rating_label"><?php esc_html_e( 'Label text', 'boldreview' ); ?></label>
						<input id="bdrvw-string-rating_label" class="bdrvw-input" type="text" name="bdrvw_settings[strings][rating_label]" value="<?php echo esc_attr( $rating_label ); ?>" />
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Review criteria card.
	 */
	protected function render_criteria_card( array $s ): void {
		$form_criteria_enabled = (int) ( $s['form_criteria_enabled'] ?? 1 );
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-chart-bar"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Review Criteria', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Add up to 3 custom criteria (e.g. Quality, Value, Service). Each criterion gets its own 1–5 star rating on the form.', 'boldreview' ); ?></p>
				</div>
			</header>

			<div class="bdrvw-row">
				<div class="bdrvw-row__label">
					<label for="bdrvw-form-criteria-enabled"><?php esc_html_e( 'Review criteria on form', 'boldreview' ); ?></label>
					<p class="bdrvw-row__hint"><?php esc_html_e( 'Let visitors rate each criterion on the review form so they can score different criteria separately. Define the criteria below.', 'boldreview' ); ?></p>
				</div>
				<div class="bdrvw-row__control">
					<?php $this->toggle_inline( 'form_criteria_enabled', $form_criteria_enabled ); ?>
				</div>
			</div>

			<div class="bdrvw-criteria" id="bdrvw-criteria">
				<?php
				$criteria     = (array) $s['criteria'];
				$placeholders = array(
					__( 'e.g. Quality', 'boldreview' ),
					__( 'e.g. Value for money', 'boldreview' ),
					__( 'e.g. Customer service', 'boldreview' ),
				);
				for ( $i = 0; $i < 3; $i++ ) :
					$row    = $criteria[ $i ] ?? array( 'key' => '', 'label' => '' );
					$label  = (string) $row['label'];
					$key    = (string) $row['key'];
					$filled = '' !== $label;
					?>
					<div class="bdrvw-criterion<?php echo $filled ? ' is-filled' : ''; ?>">
						<div class="bdrvw-criterion__index"><?php echo (int) ( $i + 1 ); ?></div>
						<div class="bdrvw-criterion__body">
							<div class="bdrvw-criterion__row">
								<input
									type="text"
									class="bdrvw-input bdrvw-criterion__input"
									placeholder="<?php echo esc_attr( $placeholders[ $i ] ); ?>"
									name="bdrvw_settings[criteria][<?php echo (int) $i; ?>][label]"
									value="<?php echo esc_attr( $label ); ?>"
									maxlength="60"
								/>
								<span class="bdrvw-criterion__preview" aria-hidden="true">
									<?php for ( $star = 0; $star < 5; $star++ ) : ?>
										<span class="bdrvw-criterion__star">&#9733;</span>
									<?php endfor; ?>
								</span>
							</div>
						</div>
						<input type="hidden" name="bdrvw_settings[criteria][<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $key ); ?>" />
					</div>
				<?php endfor; ?>
			</div>

			<?php
			/**
			 * Fires at the bottom of the Review Criteria card, after the built-in
			 * (free) criteria rows. BoldReview Pro hooks here to render an
			 * "Add more" button plus extra criteria rows so multiple/unlimited
			 * criteria can be added. Rows must post to
			 * bdrvw_settings[criteria][<i>][label] / [key] to be saved.
			 *
			 * @param array<string,mixed>             $s        Current settings.
			 * @param array<int,array<string,mixed>>  $criteria Saved criteria rows.
			 */
			do_action( 'bdrvw_criteria_card_footer', $s, (array) $s['criteria'] );
			?>

			<?php if ( ! has_action( 'bdrvw_criteria_card_footer' ) ) : ?>
				<div class="bdrvw-upsell">
					<span class="bdrvw-upsell__icon">★</span>
					<div>
						<strong><?php esc_html_e( 'Need more than 3 criteria?', 'boldreview' ); ?></strong>
						<a class="bdrvw-upsell__link" href="https://themewant.com/" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Upgrade to Pro', 'boldreview' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Rating Summary tab — accordion repeater of criteria groups.
	 * Each group carries its own list of criteria and is targeted by picking
	 * posts (or "All <PT>") from one Select2 per post type that the user
	 * enabled in the General tab. No separate "all / specific" toggle: if
	 * nothing is picked the group simply doesn't apply.
	 */
	protected function render_rating_summary_card( array $s ): void {
		
		$groups = isset( $s['criteria_groups'] ) && is_array( $s['criteria_groups'] ) ? $s['criteria_groups'] : array();
		if ( empty( $groups ) && ! empty( $s['criteria'] ) ) {
			$groups = array(
				array(
					'id'         => 'default',
					'name'       => __( 'Default', 'boldreview' ),
					'criteria'   => (array) $s['criteria'],
					'visibility' => array(
						'mode'       => 'all',
						'post_types' => array(),
						'post_ids'   => array(),
					),
				),
			);
		}

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		
		$enabled_pt = array();
		foreach ( (array) ( $s['enabled_post_types'] ?? array() ) as $pt_slug ) {
			$pt_slug = sanitize_key( (string) $pt_slug );
			if ( 'product' === $pt_slug ) {
				continue;
			}
			if ( '' !== $pt_slug && isset( $post_types[ $pt_slug ] ) ) {
				$enabled_pt[] = $pt_slug;
			}
		}
		$general_url = add_query_arg(
			array(
				'page'   => 'bdrvw-settings',
				'module' => 'collection_review',
				'tab'    => 'general',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="bdrvw-card">
			<header class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-chart-bar"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Criteria Groups', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Create one or more criteria groups (e.g. Quality, Price, Service). Each group is targeted by picking posts — or "All" of a post type — from the dropdowns below. The dropdowns mirror the post types you enabled in the General tab.', 'boldreview' ); ?></p>
				</div>
			</header>

			<?php if ( empty( $enabled_pt ) ) : ?>
				<div class="bdrvw-empty">
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the General Settings tab. */
							__( 'Enable at least one post type under <a href="%s">General Settings</a> to target criteria groups.', 'boldreview' ),
							array(
								'a' => array(
									'href' => array(),
								),
							)
						),
						esc_url( $general_url )
					);
					?>
				</div>
			<?php endif; ?>

			<div class="bdrvw-cgroups" id="bdrvw-cgroups" data-next-index="<?php echo (int) count( $groups ); ?>">
				<?php
				$i = 0;
				foreach ( $groups as $group ) :
					$this->render_criteria_group_row( $i, $group, $enabled_pt, $post_types );
					$i++;
				endforeach;
				?>
			</div>

			<button type="button" class="bdrvw-btn-secondary bdrvw-cgroups__add" id="bdrvw-cgroups-add"<?php echo empty( $enabled_pt ) ? ' disabled' : ''; ?>>
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add criteria group', 'boldreview' ); ?>
			</button>

			<template id="bdrvw-cgroup-template">
				<?php
				$this->render_criteria_group_row(
					'__INDEX__',
					array(
						'id'         => '',
						'name'       => '',
						'criteria'   => array(),
						'visibility' => array( 'mode' => 'specific', 'post_types' => array(), 'post_ids' => array() ),
					),
					$enabled_pt,
					$post_types
				);
				?>
			</template>

			<template id="bdrvw-crit-row-template">
				<div class="bdrvw-cgroup__crit-row" data-crit-row>
					<input type="text" class="bdrvw-input" placeholder="<?php esc_attr_e( 'Criterion label (e.g. Quality)', 'boldreview' ); ?>" name="bdrvw_settings[criteria_groups][__GROUP__][criteria][__INDEX__][label]" value="" maxlength="60" />
					<input type="hidden" name="bdrvw_settings[criteria_groups][__GROUP__][criteria][__INDEX__][key]" value="" />
					<?php $this->render_stars_picker( 'bdrvw_settings[criteria_groups][__GROUP__][criteria][__INDEX__][rating]', 0 ); ?>
					<button type="button" class="bdrvw-cgroup__crit-remove" aria-label="<?php esc_attr_e( 'Remove criterion', 'boldreview' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * Render a single accordion row for one criteria group.
	 *
	 * @param int|string                                            $index      Numeric index (or "__INDEX__" placeholder for the JS template).
	 * @param array<string,mixed>                                   $group      Group data.
	 * @param array<int,string>                                     $allowed_pt Post-type slugs to render dropdowns for (the user's "enabled post types" from General settings).
	 * @param array<string,\WP_Post_Type>                           $post_types All public post type objects (for labels).
	 */
	protected function render_criteria_group_row( $index, array $group, array $allowed_pt, array $post_types ): void {

		$legacy_mode = (string) ( $group['visibility']['mode'] ?? '' );
		$sel_pt      = (array) ( $group['visibility']['post_types'] ?? array() );
		$sel_ids     = (array) ( $group['visibility']['post_ids'] ?? array() );
		$criteria    = (array) ( $group['criteria'] ?? array() );
		$is_open     = '__INDEX__' === $index || 0 === $index;
		$summary     = (string) ( $group['name'] ?? '' );
		if ( '' === $summary ) {
			$summary = __( 'New group', 'boldreview' );
		}

		$overview          = (array) ( $group['overview'] ?? array() );
		$overview_enabled  = ! empty( $overview['enabled'] ) ? 1 : 0;
		$overview_heading  = (string) ( $overview['heading'] ?? '' );
		$overview_desc     = (string) ( $overview['description'] ?? '' );

		$summary_styles    = array(
			'bars'    => array( 'label' => __( 'Bars', 'boldreview' ),     'hint' => __( 'Overall average + count', 'boldreview' ) ),
			'point'   => array( 'label' => __( 'Point', 'boldreview' ),    'hint' => __( 'Big score card', 'boldreview' ) ),
			'pie'     => array( 'label' => __( 'Pie', 'boldreview' ),      'hint' => __( 'Donut progress arc', 'boldreview' ) ),
			'hbars'   => array( 'label' => __( 'H-bars', 'boldreview' ),   'hint' => __( 'Criteria fill bars', 'boldreview' ) ),
			'stripes' => array( 'label' => __( 'Stripes', 'boldreview' ),  'hint' => __( 'Segmented meter', 'boldreview' ) ),
			'gauge'   => array( 'label' => __( 'Gauges', 'boldreview' ),   'hint' => __( 'Half-circle dials', 'boldreview' ) ),
			'tiles'   => array( 'label' => __( 'Tiles', 'boldreview' ),    'hint' => __( 'Score cards', 'boldreview' ) ),
			'overview' => array( 'label' => __( 'Overview', 'boldreview' ), 'hint' => __( 'Criteria list + total card', 'boldreview' ) ),
		);
		$group_style       = (string) ( $group['summary_style'] ?? 'hbars' );
		if ( ! isset( $summary_styles[ $group_style ] ) ) {
			$group_style = 'hbars';
		}
		
		$group_show_avg    = array_key_exists( 'show_average', $group ) ? ( ! empty( $group['show_average'] ) ? 1 : 0 ) : 1;

		$legacy_all = ( 'all' === $legacy_mode );

		$preseeded_by_pt = array();
		if ( ! empty( $sel_ids ) && '__INDEX__' !== $index ) {
			$preseeded_posts = get_posts(
				array(
					'post__in'         => array_map( 'intval', $sel_ids ),
					'post_type'        => $allowed_pt,
					'post_status'      => 'any',
					'numberposts'      => count( $sel_ids ),
					'orderby'          => 'post__in',
					'suppress_filters' => false,
				)
			);
			foreach ( (array) $preseeded_posts as $p ) {
				$preseeded_by_pt[ $p->post_type ][] = array(
					'id'    => (int) $p->ID,
					'label' => (string) $p->post_title,
				);
			}
		}
		?>
		<div class="bdrvw-cgroup<?php echo $is_open ? ' is-open' : ''; ?><?php echo $overview_enabled ? ' has-overview' : ''; ?>" data-cgroup data-index="<?php echo esc_attr( (string) $index ); ?>">
			<div class="bdrvw-cgroup__head">
				<button type="button" class="bdrvw-cgroup__toggle" data-cgroup-toggle aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>">
					<span class="dashicons dashicons-arrow-down-alt2 bdrvw-cgroup__chevron" aria-hidden="true"></span>
					<span class="bdrvw-cgroup__title" data-cgroup-title><?php echo esc_html( $summary ); ?></span>
					<span class="bdrvw-cgroup__count">
						<?php
						printf(
							esc_html(
								/* translators: %d: criterion count. */
								_n( '%d criterion', '%d criteria', max( 1, count( $criteria ) ), 'boldreview' )
							),
							(int) count( $criteria )
						);
						?>
					</span>
				</button>
				<button type="button" class="bdrvw-cgroup__remove" data-cgroup-remove aria-label="<?php esc_attr_e( 'Remove group', 'boldreview' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</div>

			<div class="bdrvw-cgroup__body">
				<input type="hidden" name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( (string) ( $group['id'] ?? '' ) ); ?>" />

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Group name', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Internal label so you can tell groups apart.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<input
							type="text"
							class="bdrvw-input"
							name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][name]"
							value="<?php echo esc_attr( (string) ( $group['name'] ?? '' ) ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. Restaurant', 'boldreview' ); ?>"
							data-cgroup-name
							maxlength="80"
						/>
					</div>
				</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Rating summary enable', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Show a heading and description above this group on the frontend.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<label class="bdrvw-switch">
							<input
								type="checkbox"
								name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][overview][enabled]"
								value="1"
								data-overview-toggle
								<?php checked( $overview_enabled, 1 ); ?>
							/>
							<span class="bdrvw-switch__track"></span>
						</label>
					</div>
				</div>

				<div class="bdrvw-row" data-overview-field>
					<div class="bdrvw-row__label">
						<label for="bdrvw-overview-heading-<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Heading', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Short title shown above the criteria.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<input
							type="text"
							id="bdrvw-overview-heading-<?php echo esc_attr( (string) $index ); ?>"
							class="bdrvw-input bdrvw-input--block"
							name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][overview][heading]"
							value="<?php echo esc_attr( $overview_heading ); ?>"
							placeholder="<?php esc_attr_e( 'e.g. What our customers say', 'boldreview' ); ?>"
							maxlength="120"
						/>
					</div>
				</div>

				<div class="bdrvw-row" data-overview-field>
					<div class="bdrvw-row__label">
						<label for="bdrvw-overview-desc-<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Description', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'A short summary shown under the heading.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<textarea
							id="bdrvw-overview-desc-<?php echo esc_attr( (string) $index ); ?>"
							class="bdrvw-input bdrvw-input--block"
							name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][overview][description]"
							rows="3"
							placeholder="<?php esc_attr_e( 'A short summary that frames how visitors should read these ratings.', 'boldreview' ); ?>"
							maxlength="500"
						><?php echo esc_textarea( $overview_desc ); ?></textarea>
					</div>
				</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Summary style', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Visual layout used for this group’s aggregate. Each preview matches the frontend output.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control bdrvw-cards-grid bdrvw-sumstyles">
						<?php foreach ( $summary_styles as $val => $info ) : ?>
							<label class="bdrvw-template <?php echo $group_style === $val ? 'is-selected' : ''; ?>">
								<input
									type="radio"
									name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][summary_style]"
									value="<?php echo esc_attr( $val ); ?>"
									<?php checked( $group_style, $val ); ?>
								/>
								<span class="bdrvw-template__preview bdrvw-sumstyle-preview bdrvw-sumstyle-preview--<?php echo esc_attr( $val ); ?>" aria-hidden="true"></span>
								<span class="bdrvw-template__label"><?php echo esc_html( $info['label'] ); ?></span>
								<span class="bdrvw-sumstyle-hint"><?php echo esc_html( $info['hint'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label for="bdrvw-grp-show-avg-<?php echo esc_attr( (string) $index ); ?>"><?php esc_html_e( 'Show average rating', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Display the overall average score above this group’s reviews.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<label class="bdrvw-switch" for="bdrvw-grp-show-avg-<?php echo esc_attr( (string) $index ); ?>">
							<input
								type="checkbox"
								id="bdrvw-grp-show-avg-<?php echo esc_attr( (string) $index ); ?>"
								name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][show_average]"
								value="1"
								<?php checked( $group_show_avg, 1 ); ?>
							/>
							<span class="bdrvw-switch__track"></span>
						</label>
					</div>
				</div>

				<div class="bdrvw-row">
					<div class="bdrvw-row__label">
						<label><?php esc_html_e( 'Criteria', 'boldreview' ); ?></label>
						<p class="bdrvw-row__hint"><?php esc_html_e( 'Each criterion gets its own 1–5 rating on the form.', 'boldreview' ); ?></p>
					</div>
					<div class="bdrvw-row__control">
						<div class="bdrvw-cgroup__crit-list" data-crit-list data-group-index="<?php echo esc_attr( (string) $index ); ?>" data-next-crit="<?php echo (int) count( $criteria ); ?>">
							<?php
							$cidx = 0;
							foreach ( $criteria as $crit ) :
								$lbl    = (string) ( $crit['label'] ?? '' );
								$key    = (string) ( $crit['key'] ?? '' );
								$rating = isset( $crit['rating'] ) ? (int) $crit['rating'] : 0;
								$rating = max( 0, min( 5, $rating ) );
								?>
								<div class="bdrvw-cgroup__crit-row" data-crit-row>
									<input type="text" class="bdrvw-input" placeholder="<?php esc_attr_e( 'Criterion label (e.g. Quality)', 'boldreview' ); ?>" name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][criteria][<?php echo (int) $cidx; ?>][label]" value="<?php echo esc_attr( $lbl ); ?>" maxlength="60" />
									<input type="hidden" name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][criteria][<?php echo (int) $cidx; ?>][key]" value="<?php echo esc_attr( $key ); ?>" />
									<?php $this->render_stars_picker( 'bdrvw_settings[criteria_groups][' . (string) $index . '][criteria][' . (int) $cidx . '][rating]', $rating ); ?>
									<button type="button" class="bdrvw-cgroup__crit-remove" aria-label="<?php esc_attr_e( 'Remove criterion', 'boldreview' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
								</div>
								<?php
								$cidx++;
							endforeach;
							?>
						</div>
						<button type="button" class="bdrvw-cgroup__crit-add" data-crit-add>
							<span class="dashicons dashicons-plus" aria-hidden="true"></span>
							<?php esc_html_e( 'Add criterion', 'boldreview' ); ?>
						</button>
					</div>
				</div>

				<?php if ( ! empty( $allowed_pt ) ) : ?>
					<div class="bdrvw-row">
						<div class="bdrvw-row__label">
							<label><?php esc_html_e( 'Show on', 'boldreview' ); ?></label>
							<p class="bdrvw-row__hint"><?php esc_html_e( 'Pick specific items, or choose "All …" to target every item of that post type. One dropdown per post type enabled in General settings.', 'boldreview' ); ?></p>
						</div>
						<div class="bdrvw-row__control bdrvw-cgroup__visibility">
							<div class="bdrvw-cgroup__pt-controls">
								<?php foreach ( $allowed_pt as $pt_name ) :
									$pt = $post_types[ $pt_name ] ?? null;
									if ( ! $pt ) {
										continue;
									}
									$all_sel = $legacy_all || in_array( $pt_name, $sel_pt, true );
									$picked  = (array) ( $preseeded_by_pt[ $pt_name ] ?? array() );
									$plural  = (string) ( $pt->labels->name ?? $pt->labels->singular_name );
									$all_lbl = sprintf( /* translators: %s: plural post-type label, e.g. "Posts". */ __( 'All %s', 'boldreview' ), $plural );
									?>
									<div class="bdrvw-cgroup__pt-row" data-cgroup-pt-row="<?php echo esc_attr( $pt_name ); ?>">
										<label class="bdrvw-cgroup__pt-label"><?php echo esc_html( $pt->labels->singular_name ); ?></label>
										<select
											class="bdrvw-cgroup__pt-select"
											multiple
											data-cgroup-pt-select="<?php echo esc_attr( $pt_name ); ?>"
											data-cgroup-index="<?php echo esc_attr( (string) $index ); ?>"
											data-all-label="<?php echo esc_attr( $all_lbl ); ?>"
											name="bdrvw_settings[criteria_groups][<?php echo esc_attr( (string) $index ); ?>][visibility][selections][<?php echo esc_attr( $pt_name ); ?>][]"
										>
											<option value="__all__"<?php selected( $all_sel ); ?>><?php echo esc_html( $all_lbl ); ?></option>
											<?php foreach ( $picked as $p ) : ?>
												<option value="<?php echo (int) $p['id']; ?>" selected><?php echo esc_html( $p['label'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Render a labelled toggle row. `$key` accepts dotted-style names like
	 * "google_reviews[show_avatar]".
	 */
	protected function toggle_row( string $key, string $label, string $hint, int $value ): void {
		?>
		<div class="bdrvw-row">
			<div class="bdrvw-row__label">
				<label for="bdrvw-<?php echo esc_attr( $this->id( $key ) ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="bdrvw-row__hint"><?php echo esc_html( $hint ); ?></p>
			</div>
			<div class="bdrvw-row__control">
				<?php $this->toggle_inline( $key, $value ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a labelled text input row.
	 */
	protected function text_row( string $key, string $label, string $hint, string $value, string $placeholder = '' ): void {
		?>
		<div class="bdrvw-row">
			<div class="bdrvw-row__label">
				<label for="bdrvw-<?php echo esc_attr( $this->id( $key ) ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="bdrvw-row__hint"><?php echo esc_html( $hint ); ?></p>
			</div>
			<div class="bdrvw-row__control">
				<input id="bdrvw-<?php echo esc_attr( $this->id( $key ) ); ?>" class="bdrvw-input" type="text" name="<?php echo esc_attr( $this->name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * Render a bare toggle switch. Supports bracketed names like fields[author_name].
	 *
	 * @param array<string,string> $extra Extra attributes for the input element.
	 */
	protected function toggle_inline( string $key, int $value, array $extra = array() ): void {
		$id    = 'bdrvw-' . $this->id( $key );
		$attrs = '';
		foreach ( $extra as $a_name => $a_val ) {
			$attrs .= ' ' . esc_attr( $a_name ) . '="' . esc_attr( $a_val ) . '"';
		}
		?>
		<label class="bdrvw-switch" for="<?php echo esc_attr( $id ); ?>">
			<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $this->name( $key ) ); ?>" value="1" <?php checked( $value, 1 ); ?><?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-attribute above. ?> />
			<span class="bdrvw-switch__track"></span>
		</label>
		<?php
	}

	/**
	 * Summary-style definitions (label + one-line hint), shared by the global
	 * picker (General tab) and the per-group picker (Rating Summary tab).
	 *
	 * @return array<string,array{label:string,hint:string}>
	 */
	protected function summary_style_defs(): array {
		return array(
			'bars'     => array( 'label' => __( 'Bars', 'boldreview' ),     'hint' => __( 'Overall average + count', 'boldreview' ) ),
			'point'    => array( 'label' => __( 'Point', 'boldreview' ),    'hint' => __( 'Big score card', 'boldreview' ) ),
			'pie'      => array( 'label' => __( 'Pie', 'boldreview' ),      'hint' => __( 'Donut progress arc', 'boldreview' ) ),
			'hbars'    => array( 'label' => __( 'H-bars', 'boldreview' ),   'hint' => __( 'Criteria fill bars', 'boldreview' ) ),
			'stripes'  => array( 'label' => __( 'Stripes', 'boldreview' ),  'hint' => __( 'Segmented meter', 'boldreview' ) ),
			'gauge'    => array( 'label' => __( 'Gauges', 'boldreview' ),   'hint' => __( 'Half-circle dials', 'boldreview' ) ),
			'tiles'    => array( 'label' => __( 'Tiles', 'boldreview' ),    'hint' => __( 'Score cards', 'boldreview' ) ),
			'overview' => array( 'label' => __( 'Overview', 'boldreview' ), 'hint' => __( 'Criteria list + total card', 'boldreview' ) ),
		);
	}

	/**
	 * Render the summary-style radio-card grid for a given form field name.
	 *
	 * @param string $name    Full bracketed form name (e.g. bdrvw_settings[summary_style]).
	 * @param string $current Currently selected style key.
	 */
	protected function render_summary_style_grid( string $name, string $current ): void {
		$defs = $this->summary_style_defs();
		if ( ! isset( $defs[ $current ] ) ) {
			$current = 'bars';
		}
		?>
		<div class="bdrvw-row__control bdrvw-cards-grid bdrvw-sumstyles">
			<?php foreach ( $defs as $val => $info ) : ?>
				<label class="bdrvw-template <?php echo $current === $val ? 'is-selected' : ''; ?>">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( $current, $val ); ?> />
					<span class="bdrvw-template__preview bdrvw-sumstyle-preview bdrvw-sumstyle-preview--<?php echo esc_attr( $val ); ?>" aria-hidden="true"></span>
					<span class="bdrvw-template__label"><?php echo esc_html( $info['label'] ); ?></span>
					<span class="bdrvw-sumstyle-hint"><?php echo esc_html( $info['hint'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render an inline 0–5 star picker. Persists to $name via a hidden input;
	 * the buttons are presentational and toggled by JS. Click the same star
	 * twice to clear back to 0.
	 *
	 * @param string $name  Full bracketed form name (e.g. bdrvw_settings[criteria_groups][0][criteria][0][rating]).
	 * @param int    $value Currently selected value, 0–5.
	 */
	protected function render_stars_picker( string $name, int $value ): void {
		$value = max( 0, min( 5, $value ) );
		?>
		<div class="bdrvw-stars-picker" data-stars-picker data-rating="<?php echo (int) $value; ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $value; ?>" data-stars-input />
			<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
				<button
					type="button"
					class="bdrvw-stars-picker__star<?php echo $i <= $value ? ' is-on' : ''; ?>"
					data-star="<?php echo (int) $i; ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating value 1-5. */ __( 'Rate %d out of 5', 'boldreview' ), $i ) ); ?>"
				>&#9733;</button>
			<?php endfor; ?>
		</div>
		<?php
	}

	/**
	 * Resolve a posted-name for a given key.
	 *   "foo"           → bdrvw_settings[foo]
	 *   "foo[bar]"      → bdrvw_settings[foo][bar]
	 *   "foo[bar][baz]" → bdrvw_settings[foo][bar][baz]
	 *   "bdrvw_settings[…]" → returned 
	 */
	protected function name( string $key ): string {
		if ( 0 === strpos( $key, 'bdrvw_settings[' ) ) {
			return $key;
		}
		if ( false !== strpos( $key, '[' ) ) {
			list( $top, $rest ) = explode( '[', $key, 2 );
			return 'bdrvw_settings[' . $top . '][' . $rest;
		}
		return 'bdrvw_settings[' . $key . ']';
	}

	/**
	 * Build a stable DOM id from a posted-name.
	 */
	protected function id( string $key ): string {
		return preg_replace( '/[^a-z0-9]+/i', '-', $key );
	}
}
