<?php
/**
 * Dashboard page — "Review Collection" module grid.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Modules;
use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Review Collection dashboard (module browser + on/off switches).
 */
class Bdrvw_DashboardPage {

	const VIEW_ALL    = 'all';
	const VIEW_ACTIVE = 'active';

	/**
	 * Settings store (kept for future per-module status indicators).
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
	 * Render the page.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) && self::VIEW_ACTIVE === sanitize_key( wp_unslash( $_GET['view'] ) )
			? self::VIEW_ACTIVE
			: self::VIEW_ALL;

		$defs   = Bdrvw_Modules::definitions();
		$active = Bdrvw_Modules::active();

		if ( self::VIEW_ACTIVE === $view ) {
			$defs = array_filter(
				$defs,
				static function ( $_def, $slug ) use ( $active ) {
					return in_array( $slug, $active, true );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}
		?>
		<div class="wrap bdrvw-wrap">
			<div class="bdrvw-app">
				<header class="bdrvw-app__header">
					<div class="bdrvw-app__brand">
						<span class="bdrvw-app__logo">B</span>
						<div>
							<h1><?php esc_html_e( 'Review Collection', 'boldreview' ); ?></h1>
							<p class="bdrvw-app__tag"><?php esc_html_e( 'Mix &amp; match review sources. Enable just the modules you need.', 'boldreview' ); ?></p>
						</div>
					</div>
					<div class="bdrvw-app__meta">
						<span class="bdrvw-pill bdrvw-pill--brand"><?php echo esc_html( count( $active ) . '/' . count( Bdrvw_Modules::definitions() ) . ' active' ); ?></span>
						<span class="bdrvw-pill"><?php echo esc_html( 'v' . BDRVW_VERSION ); ?></span>
					</div>
				</header>

				<div class="bdrvw-app__body">
					<aside class="bdrvw-sidebar" aria-label="<?php esc_attr_e( 'Dashboard navigation', 'boldreview' ); ?>">
						<div class="bdrvw-sidebar__section">
							<h3 class="bdrvw-sidebar__title"><?php esc_html_e( 'Dashboard', 'boldreview' ); ?></h3>
							<nav class="bdrvw-nav">
								<a class="bdrvw-nav__item<?php echo self::VIEW_ALL === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->dashboard_url( self::VIEW_ALL ) ); ?>">
									<span class="dashicons dashicons-screenoptions"></span>
									<span class="bdrvw-nav__label"><?php esc_html_e( 'All Modules', 'boldreview' ); ?></span>
								</a>
							</nav>
						</div>

						<div class="bdrvw-sidebar__section">
							<h3 class="bdrvw-sidebar__title"><?php esc_html_e( 'Modules', 'boldreview' ); ?></h3>
							<nav class="bdrvw-nav">
								<?php foreach ( Bdrvw_Modules::definitions() as $slug => $def ) :
									$is_on = in_array( $slug, $active, true );
									?>
									<a class="bdrvw-nav__item<?php echo $is_on ? ' is-on' : ''; ?>" href="<?php echo esc_url( $this->settings_url( $slug ) ); ?>">
										<span class="bdrvw-nav__module-dot" aria-hidden="true"></span>
										<span class="bdrvw-nav__label"><?php echo esc_html( $def['label'] ); ?></span>
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
						<div class="bdrvw-page-head">
							<div>
								<h2>
									<?php echo self::VIEW_ACTIVE === $view
										? esc_html__( 'Active Modules', 'boldreview' )
										: esc_html__( 'All Modules', 'boldreview' ); ?>
								</h2>
								<p>
									<?php echo self::VIEW_ACTIVE === $view
										? esc_html__( 'Modules currently powering review collection on your site.', 'boldreview' )
										: esc_html__( 'Pick the review sources and features you want. Toggle them on, then click "Configure" to fine-tune each one.', 'boldreview' ); ?>
								</p>
							</div>
						</div>

						<?php if ( empty( $defs ) ) : ?>
							<div class="bdrvw-card">
								<div class="bdrvw-empty">
									<?php esc_html_e( 'No modules are active yet. Switch one on from "All Modules" to get started.', 'boldreview' ); ?>
								</div>
							</div>
						<?php else : ?>
							<div class="bdrvw-modules-grid" id="bdrvw-modules-grid">
								<?php foreach ( $defs as $slug => $def ) :
									$is_on = in_array( $slug, $active, true );
									?>
									<article class="bdrvw-module-card bdrvw-module-card--<?php echo esc_attr( $def['color'] ); ?><?php echo $is_on ? '' : ' is-off'; ?>" data-module="<?php echo esc_attr( $slug ); ?>">
										<div class="bdrvw-module-card__hero">
										</div>
										<div class="bdrvw-module-card__body">
											<h3 class="bdrvw-module-card__title">
												<span class="bdrvw-module-card__icon"><span class="dashicons dashicons-<?php echo esc_attr( $def['icon'] ); ?>"></span></span>
												<?php echo esc_html( $def['label'] ); ?>
											</h3>
											<p class="bdrvw-module-card__desc"><?php echo esc_html( $def['tagline'] ); ?></p>
											<div class="bdrvw-module-card__actions">
												<a class="bdrvw-module-card__configure" href="<?php echo esc_url( $this->settings_url( $slug ) ); ?>">
													<?php esc_html_e( 'Configure', 'boldreview' ); ?>
													<span class="dashicons dashicons-arrow-right-alt2"></span>
												</a>
												<label class="bdrvw-switch bdrvw-switch--lg" title="<?php echo esc_attr( $is_on ? __( 'Disable module', 'boldreview' ) : __( 'Enable module', 'boldreview' ) ); ?>">
													<input type="checkbox" data-bdrvw-module-toggle="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_on ); ?> />
													<span class="bdrvw-switch__track"></span>
												</label>
											</div>
										</div>
									</article>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				</div>
			</div>
		</div>

		<div class="bdrvw-toast" id="bdrvw-toast" role="status" aria-live="polite">
			<span class="dashicons dashicons-yes"></span>
			<span class="bdrvw-toast__text"><?php esc_html_e( 'Saved', 'boldreview' ); ?></span>
		</div>
		<?php
	}

	/**
	 * Dashboard URL helper.
	 */
	protected function dashboard_url( string $view ): string {
		return add_query_arg(
			array(
				'page' => 'bdrvw',
				'view' => $view,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Per-module settings URL.
	 */
	protected function settings_url( string $module ): string {
		return add_query_arg(
			array(
				'page'   => 'bdrvw-settings',
				'module' => $module,
			),
			admin_url( 'admin.php' )
		);
	}
}
