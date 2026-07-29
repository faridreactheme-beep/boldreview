<?php
/**
 * Tools page — CSV import/export of reviews, and rolling the plugin back to an
 * earlier release.
 *
 * Every action here runs through `admin-post.php` rather than the page render:
 * an export has to stream headers before any HTML, and a rollback swaps the
 * plugin's own files out from under the request, so neither can happen halfway
 * through drawing a screen.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Renders BoldReview → Tools and handles its form submissions.
 */
class Bdrvw_ToolsPage {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'bdrvw-tools';

	/**
	 * The plugin's wordpress.org slug — https://wordpress.org/plugins/boldreview/
	 *
	 * Fixed rather than derived from the folder name: a site that installed the
	 * plugin into a renamed directory still has to reach the right listing.
	 */
	const WPORG_SLUG = 'boldreview';

	/** Columns written to, and read back from, the CSV — in this order. */
	const COLUMNS = array(
		'id',
		'parent_id',
		'post_id',
		'post_title',
		'user_id',
		'author_name',
		'author_email',
		'author_url',
		'title',
		'content',
		'rating',
		'criteria',
		'status',
		'created_at',
	);

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
	 * Register the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_bdrvw_export_reviews', array( $this, 'handle_export' ) );
		add_action( 'admin_post_bdrvw_import_reviews', array( $this, 'handle_import' ) );
		add_action( 'admin_post_bdrvw_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_post_bdrvw_rollback_refresh', array( $this, 'handle_rollback_refresh' ) );
	}

	/* ---------------------------------------------------------------------
	 * Date range
	 * ------------------------------------------------------------------ */

	/**
	 * The ranges offered on both the export and the import form.
	 *
	 * @return array<string,string>
	 */
	public static function ranges(): array {
		return array(
			'all'    => __( 'All time', 'boldreview' ),
			'30days' => __( 'Last 30 days', 'boldreview' ),
			'custom' => __( 'Custom date', 'boldreview' ),
		);
	}

	/**
	 * Resolve a submitted range into concrete `Y-m-d` bounds.
	 *
	 * An empty bound means "open ended" — that is what "All time" is, and what a
	 * custom range with only one date filled in should behave like.
	 *
	 * @param string $range Range key.
	 * @param string $start Custom start date (`Y-m-d`).
	 * @param string $end   Custom end date (`Y-m-d`).
	 * @return array{from:string,to:string}
	 */
	public static function resolve_range( string $range, string $start = '', string $end = '' ): array {
		$valid = static function ( string $date ): string {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
		};

		switch ( $range ) {
			case '30days':
				return array(
					'from' => gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) - 29 * DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-local day boundaries.
					'to'   => '',
				);
			case 'custom':
				$from = $valid( $start );
				$to   = $valid( $end );
				if ( '' !== $from && '' !== $to && $from > $to ) {
					list( $from, $to ) = array( $to, $from );
				}
				return array(
					'from' => $from,
					'to'   => $to,
				);
			case 'all':
			default:
				return array(
					'from' => '',
					'to'   => '',
				);
		}
	}

	/**
	 * The range picker: three choices, with the two date fields revealed only
	 * for "Custom date".
	 *
	 * @param string $prefix Field-name/id prefix, so export and import can both
	 *                       carry one without colliding.
	 */
	protected function render_range_fields( string $prefix ): void {
		?>
		<div class="bdrvw-tools__range" data-bdrvw-range>
			<span class="bdrvw-tools__label"><?php esc_html_e( 'Date range', 'boldreview' ); ?></span>
			<div class="bdrvw-tools__range-choices">
				<?php foreach ( self::ranges() as $key => $label ) : ?>
					<label class="bdrvw-tools__radio">
						<input
							type="radio"
							name="<?php echo esc_attr( $prefix ); ?>_range"
							value="<?php echo esc_attr( $key ); ?>"
							data-bdrvw-range-choice
							<?php checked( 'all', $key ); ?>
						/>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<div class="bdrvw-tools__dates" data-bdrvw-range-custom hidden>
				<label class="bdrvw-tools__date">
					<span><?php esc_html_e( 'Start date', 'boldreview' ); ?></span>
					<input type="date" name="<?php echo esc_attr( $prefix ); ?>_start" />
				</label>
				<span class="bdrvw-tools__date-sep" aria-hidden="true">&ndash;</span>
				<label class="bdrvw-tools__date">
					<span><?php esc_html_e( 'End date', 'boldreview' ); ?></span>
					<input type="date" name="<?php echo esc_attr( $prefix ); ?>_end" />
				</label>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs = array(
			'import-export' => __( 'Import / Export', 'boldreview' ),
			'rollback'      => __( 'Rollback', 'boldreview' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import-export';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'import-export';
		}
		?>
		<div class="wrap bdrvw-wrap">
			<?php $this->render_notice(); ?>

			<div class="bdrvw-app">
				<header class="bdrvw-app__header">
					<div class="bdrvw-app__brand">
						<span class="bdrvw-app__logo">B</span>
						<div>
							<h1><?php esc_html_e( 'Tools', 'boldreview' ); ?></h1>
							<p class="bdrvw-app__tag"><?php esc_html_e( 'Move your reviews in and out, and roll the plugin back', 'boldreview' ); ?></p>
						</div>
					</div>
					<div class="bdrvw-app__meta">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bdrvw' ) ); ?>" class="bdrvw-btn-secondary">
							<?php esc_html_e( '← Back to Dashboard', 'boldreview' ); ?>
						</a>
					</div>
				</header>

				<div class="bdrvw-app__body bdrvw-app__body--full">
					<section class="bdrvw-content">
						<nav class="bdrvw-tabs" role="tablist">
							<?php foreach ( $tabs as $key => $label ) : ?>
								<a
									class="bdrvw-tabs__tab<?php echo $current === $key ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"
									role="tab"
									aria-selected="<?php echo $current === $key ? 'true' : 'false'; ?>"
								><?php echo esc_html( $label ); ?></a>
							<?php endforeach; ?>
						</nav>

						<div class="bdrvw-tab-panels">
							<div class="bdrvw-tab-panel<?php echo 'import-export' === $current ? ' is-active' : ''; ?>" role="tabpanel">
								<?php $this->render_export_card(); ?>
								<?php $this->render_import_card(); ?>
							</div>
							<div class="bdrvw-tab-panel<?php echo 'rollback' === $current ? ' is-active' : ''; ?>" role="tabpanel">
								<?php $this->render_rollback_card(); ?>
							</div>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Show (and clear) whatever the last action left behind.
	 */
	protected function render_notice(): void {
		$key    = 'bdrvw_tools_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Remember a message for the redirect that follows an action.
	 *
	 * @param string $message Text to show.
	 * @param string $type    `success` or `error`.
	 */
	protected function set_notice( string $message, string $type = 'success' ): void {
		set_transient(
			'bdrvw_tools_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Where an action returns to.
	 */
	protected function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Export card.
	 */
	protected function render_export_card(): void {
		?>
		<div class="bdrvw-card">
			<div class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-download"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Export reviews', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Download your reviews, comments and replies as a CSV file. Pick a date range to export only what was submitted in that window.', 'boldreview' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bdrvw-tools__form">
				<input type="hidden" name="action" value="bdrvw_export_reviews" />
				<?php wp_nonce_field( 'bdrvw_export_reviews', 'bdrvw_export_nonce' ); ?>

				<?php $this->render_range_fields( 'export' ); ?>

				<div class="bdrvw-tools__actions">
					<button type="submit" class="bdrvw-btn">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export CSV', 'boldreview' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Stream the CSV. Runs on `admin_post_bdrvw_export_reviews`, before any HTML.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'boldreview' ) );
		}
		check_admin_referer( 'bdrvw_export_reviews', 'bdrvw_export_nonce' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next lines.
		$range = isset( $_POST['export_range'] ) ? sanitize_key( wp_unslash( $_POST['export_range'] ) ) : 'all';
		$start = isset( $_POST['export_start'] ) ? sanitize_text_field( wp_unslash( $_POST['export_start'] ) ) : '';
		$end   = isset( $_POST['export_end'] ) ? sanitize_text_field( wp_unslash( $_POST['export_end'] ) ) : '';
		// phpcs:enable

		$bounds = self::resolve_range( $range, $start, $end );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $this->export_filename( $bounds ) );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not start the export.', 'boldreview' ) );
		}

		// BOM, so Excel opens non-ASCII reviewer names as UTF-8 instead of mojibake.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the response, not the filesystem.
		fputcsv( $out, self::COLUMNS );

		$page = 1;
		do {
			$result = Bdrvw_Review::query(
				array(
					'per_page'         => 200,
					'page'             => $page,
					'orderby'          => 'id',
					'order'            => 'asc',
					'date_from'        => $bounds['from'],
					'date_to'          => $bounds['to'],
					'include_comments' => true,
					'include_replies'  => true,
				)
			);

			foreach ( $result['items'] as $row ) {
				fputcsv( $out, $this->row_to_csv( $row ) );
			}

			++$page;
		} while ( $page <= (int) $result['pages'] );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the response stream.
		exit;
	}

	/**
	 * Filename for the download, carrying the range so two exports don't look
	 * alike in the Downloads folder.
	 *
	 * @param array{from:string,to:string} $bounds Resolved range.
	 */
	protected function export_filename( array $bounds ): string {
		$suffix = 'all';
		if ( '' !== $bounds['from'] || '' !== $bounds['to'] ) {
			$suffix = ( '' !== $bounds['from'] ? $bounds['from'] : 'start' ) . '_to_' . ( '' !== $bounds['to'] ? $bounds['to'] : 'now' );
		}
		return 'boldreview-reviews-' . $suffix . '.csv';
	}

	/**
	 * One review row as CSV cells, in COLUMNS order.
	 *
	 * @param array<string,mixed> $row Review row.
	 * @return array<int,string>
	 */
	protected function row_to_csv( array $row ): array {
		$criteria = ! empty( $row['criteria'] ) && is_array( $row['criteria'] ) ? (string) wp_json_encode( $row['criteria'] ) : '';
		$post_id  = (int) ( $row['post_id'] ?? 0 );

		return array(
			(string) (int) ( $row['id'] ?? 0 ),
			(string) (int) ( $row['parent_id'] ?? 0 ),
			(string) $post_id,
			$post_id > 0 ? (string) get_the_title( $post_id ) : '',
			(string) (int) ( $row['user_id'] ?? 0 ),
			(string) ( $row['author_name'] ?? '' ),
			(string) ( $row['author_email'] ?? '' ),
			(string) ( $row['author_url'] ?? '' ),
			(string) ( $row['title'] ?? '' ),
			(string) ( $row['content'] ?? '' ),
			(string) (int) ( $row['rating'] ?? 0 ),
			$criteria,
			(string) ( $row['status'] ?? '' ),
			(string) ( $row['created_at'] ?? '' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Import card.
	 */
	protected function render_import_card(): void {
		?>
		<div class="bdrvw-card">
			<div class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-upload"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Import reviews', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Upload a CSV exported from BoldReview. Every row in the file is imported; a review that is already here is left alone.', 'boldreview' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bdrvw-tools__form">
				<input type="hidden" name="action" value="bdrvw_import_reviews" />
				<?php wp_nonce_field( 'bdrvw_import_reviews', 'bdrvw_import_nonce' ); ?>

				<div class="bdrvw-tools__field">
					<label class="bdrvw-tools__label" for="bdrvw-import-file"><?php esc_html_e( 'CSV file', 'boldreview' ); ?></label>
					<input type="file" id="bdrvw-import-file" name="bdrvw_import_file" accept=".csv,text/csv" required />
				</div>

				<div class="bdrvw-tools__actions">
					<button type="submit" class="bdrvw-btn">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Import CSV', 'boldreview' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Read the uploaded CSV back into reviews.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'boldreview' ) );
		}
		check_admin_referer( 'bdrvw_import_reviews', 'bdrvw_import_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES paths are handled by the upload API below.
		$file = isset( $_FILES['bdrvw_import_file'] ) ? $_FILES['bdrvw_import_file'] : null;
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->set_notice( __( 'No CSV file was uploaded.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'import-export' ) );
			exit;
		}

		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			$this->set_notice( __( 'The uploaded file could not be read.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'import-export' ) );
			exit;
		}

		$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a possibly large upload; file_get_contents would hold it all in memory.
		if ( false === $handle ) {
			$this->set_notice( __( 'The uploaded file could not be read.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'import-export' ) );
			exit;
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->set_notice( __( 'That file is empty.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'import-export' ) );
			exit;
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$index     = array();
		foreach ( $header as $pos => $name ) {
			$index[ strtolower( trim( (string) $name ) ) ] = (int) $pos;
		}

		if ( ! isset( $index['content'] ) || ! isset( $index['post_id'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->set_notice( __( 'That CSV is missing the post_id and content columns — export a file from BoldReview to see the expected format.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'import-export' ) );
			exit;
		}

		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		
		$id_map = array();

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( ! is_array( $row ) || array() === array_filter( $row, static function ( $cell ) { return '' !== trim( (string) $cell ); } ) ) {
				continue;
			}

			$get = static function ( string $key ) use ( $row, $index ): string {
				return isset( $index[ $key ], $row[ $index[ $key ] ] ) ? trim( (string) $row[ $index[ $key ] ] ) : '';
			};

			$created = $get( 'created_at' );

			$post_id = (int) $get( 'post_id' );
			if ( $post_id <= 0 || null === get_post( $post_id ) ) {
				++$failed;
				continue;
			}

			$email = sanitize_email( $get( 'author_email' ) );
			if ( Bdrvw_Review::duplicate_exists( $post_id, $email, $created, $get( 'content' ) ) ) {
				++$skipped;
				continue;
			}

			$old_id     = (int) $get( 'id' );
			$old_parent = (int) $get( 'parent_id' );
			$criteria   = json_decode( $get( 'criteria' ), true );

			$new_id = Bdrvw_Review::bdrvw_insert(
				array(
					'post_id'      => $post_id,
					'parent_id'    => $old_parent > 0 ? ( $id_map[ $old_parent ] ?? 0 ) : 0,
					'user_id'      => (int) $get( 'user_id' ),
					'author_name'  => sanitize_text_field( $get( 'author_name' ) ),
					'author_email' => $email,
					'author_url'   => esc_url_raw( $get( 'author_url' ) ),
					'title'        => sanitize_text_field( $get( 'title' ) ),
					'content'      => wp_kses_post( $get( 'content' ) ),
					'rating'       => (int) $get( 'rating' ),
					'criteria'     => is_array( $criteria ) ? array_map( 'intval', $criteria ) : array(),
					'status'       => $get( 'status' ),
					'created_at'   => $created,
				)
			);

			if ( $new_id > 0 ) {
				++$imported;
				if ( $old_id > 0 ) {
					$id_map[ $old_id ] = $new_id;
				}
			} else {
				++$failed;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->set_notice(
			sprintf(
				/* translators: 1: imported count, 2: skipped count, 3: failed count. */
				__( 'Import finished: %1$s added, %2$s skipped, %3$s could not be imported.', 'boldreview' ),
				number_format_i18n( $imported ),
				number_format_i18n( $skipped ),
				number_format_i18n( $failed )
			),
			$imported > 0 || 0 === $failed ? 'success' : 'error'
		);

		wp_safe_redirect( $this->tab_url( 'import-export' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rollback
	 * ------------------------------------------------------------------ */

	/**
	 * Releases this plugin can be switched to, newest first.
	 *
	 * Every published release is offered, not just the older ones: moving back
	 * up after a rollback, or reinstalling the running version to repair damaged
	 * files, are the same operation from here.
	 *
	 * Read from the plugin directory API and cached for half a day — the list
	 * changes only when a release is published, and the Tools page should not
	 * wait on a remote call every time it is opened.
	 *
	 * @return array<string,string> Version number → package URL.
	 */
	public static function available_versions(): array {
		$cached = get_transient( 'bdrvw_rollback_versions' );
		$versions = is_array( $cached ) ? $cached : null;

		if ( null === $versions ) {
			$versions = array();

			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			/**
			 * Filter the wordpress.org slug the release list is read from.
			 *
			 * @param string $slug Directory slug.
			 */
			$slug = (string) apply_filters( 'bdrvw_rollback_slug', self::WPORG_SLUG );

			$info = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'versions' => true ),
				)
			);

			if ( ! is_wp_error( $info ) && ! empty( $info->versions ) && is_array( $info->versions ) ) {
				foreach ( $info->versions as $version => $package ) {
					if ( 'trunk' === $version || '' === (string) $package ) {
						continue;
					}
					$versions[ (string) $version ] = (string) $package;
				}
			}

			set_transient( 'bdrvw_rollback_versions', $versions, 12 * HOUR_IN_SECONDS );
		}

		/**
		 * Filter the releases offered on the Rollback tab.
		 *
		 * Plugins distributed outside wordpress.org have no directory listing to
		 * read, so their updater can supply the list here.
		 *
		 * @param array<string,string> $versions Version number → package URL.
		 */
		$versions = (array) apply_filters( 'bdrvw_rollback_versions', $versions );

		uksort( $versions, static function ( $a, $b ) {
			return version_compare( (string) $b, (string) $a );
		} );

		return $versions;
	}

	/**
	 * Rollback card.
	 */
	protected function render_rollback_card(): void {
		$versions = self::available_versions();
		?>
		<div class="bdrvw-card">
			<div class="bdrvw-card__header bdrvw-card__header--with-icon">
				<span class="bdrvw-card__header-icon"><span class="dashicons dashicons-admin-tools"></span></span>
				<div class="bdrvw-card__header-body">
					<h2><?php esc_html_e( 'Rollback plugin', 'boldreview' ); ?></h2>
					<p><?php esc_html_e( 'Reinstall an earlier release of BoldReview. Your reviews and settings are left untouched — only the plugin files are replaced.', 'boldreview' ); ?></p>
				</div>
			</div>

			<div class="bdrvw-tools__warning">
				<?php
				printf(
					/* translators: %s: contact link. */
					esc_html__( 'If you are using this tool to fix a problem with BoldReview, please %s so that it can be fixed for everyone.', 'boldreview' ),
					'<a href="https://themewant.com/contact/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'contact us', 'boldreview' ) . '</a>'
				);
				?>
			</div>

			<p class="bdrvw-tools__current">
				<?php
				printf(
					/* translators: %s: version number. */
					esc_html__( 'You currently have version %s installed. Pick any published release below to switch to it.', 'boldreview' ),
					'<strong>' . esc_html( BDRVW_VERSION ) . '</strong>'
				);
				?>
				<a class="bdrvw-tools__refresh" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'bdrvw_rollback_refresh', admin_url( 'admin-post.php' ) ), 'bdrvw_rollback_refresh', 'bdrvw_refresh_nonce' ) ); ?>">
					<?php esc_html_e( 'Refresh list', 'boldreview' ); ?>
				</a>
			</p>

			<?php if ( empty( $versions ) ) : ?>
				<p class="bdrvw-tools__empty">
					<?php esc_html_e( 'No earlier releases are available to roll back to right now.', 'boldreview' ); ?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bdrvw-tools__form">
					<input type="hidden" name="action" value="bdrvw_rollback" />
					<?php wp_nonce_field( 'bdrvw_rollback', 'bdrvw_rollback_nonce' ); ?>

					<div class="bdrvw-tools__field">
						<label class="bdrvw-tools__label" for="bdrvw-rollback-version"><?php esc_html_e( 'Rollback version to', 'boldreview' ); ?></label>
						<select id="bdrvw-rollback-version" name="bdrvw_rollback_version">
							<?php
							// Start on the newest release that isn't the one already
							// running — the reason the tab is open, most of the time.
							$default = '';
							foreach ( array_keys( $versions ) as $candidate ) {
								if ( (string) $candidate !== BDRVW_VERSION ) {
									$default = (string) $candidate;
									break;
								}
							}
							?>
							<?php foreach ( $versions as $version => $package ) : ?>
								<option value="<?php echo esc_attr( $version ); ?>" <?php selected( $default, (string) $version ); ?>>
									<?php
									printf(
										/* translators: %s: version number. */
										esc_html__( 'BoldReview %s', 'boldreview' ),
										esc_html( $version )
									);
									echo (string) $version === BDRVW_VERSION ? ' ' . esc_html__( '(installed)', 'boldreview' ) : '';
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="bdrvw-tools__actions">
						<button
							type="submit"
							class="bdrvw-btn"
							onclick="return confirm('<?php echo esc_js( __( 'Replace the installed plugin files with the selected release?', 'boldreview' ) ); ?>')"
						>
							<?php esc_html_e( 'Rollback plugin', 'boldreview' ); ?>
						</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Drop the cached release list, so a release published minutes ago shows up
	 * without waiting for the cache to lapse.
	 */
	public function handle_rollback_refresh(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'boldreview' ) );
		}
		check_admin_referer( 'bdrvw_rollback_refresh', 'bdrvw_refresh_nonce' );

		delete_transient( 'bdrvw_rollback_versions' );
		$versions = self::available_versions();

		$this->set_notice(
			empty( $versions )
				? __( 'Could not reach wordpress.org for the release list. Try again in a moment.', 'boldreview' )
				: sprintf(
					/* translators: %s: number of releases. */
					__( 'Release list refreshed — %s versions available.', 'boldreview' ),
					number_format_i18n( count( $versions ) )
				),
			empty( $versions ) ? 'error' : 'success'
		);

		wp_safe_redirect( $this->tab_url( 'rollback' ) );
		exit;
	}

	/**
	 * Reinstall the chosen release over the running one.
	 */
	public function handle_rollback(): void {
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'boldreview' ) );
		}
		check_admin_referer( 'bdrvw_rollback', 'bdrvw_rollback_nonce' );

		$version  = isset( $_POST['bdrvw_rollback_version'] ) ? sanitize_text_field( wp_unslash( $_POST['bdrvw_rollback_version'] ) ) : '';
		$versions = self::available_versions();

		if ( '' === $version || ! isset( $versions[ $version ] ) ) {
			$this->set_notice( __( 'That version is not available to roll back to.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'rollback' ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$was_active = is_plugin_active( BDRVW_BASENAME );

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->run(
			array(
				'package'           => $versions[ $version ],
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array(
					'plugin' => BDRVW_BASENAME,
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->set_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $this->tab_url( 'rollback' ) );
			exit;
		}

		if ( false === $result || null === $result ) {
			$this->set_notice( __( 'The rollback could not be completed. Check that the plugin folder is writable.', 'boldreview' ), 'error' );
			wp_safe_redirect( $this->tab_url( 'rollback' ) );
			exit;
		}

		if ( $was_active && ! is_plugin_active( BDRVW_BASENAME ) ) {
			activate_plugin( BDRVW_BASENAME );
		}

		delete_transient( 'bdrvw_rollback_versions' );

		$this->set_notice(
			sprintf(
				/* translators: %s: version number. */
				__( 'BoldReview is now running version %s.', 'boldreview' ),
				$version
			)
		);

		wp_safe_redirect( $this->tab_url( 'rollback' ) );
		exit;
	}
}
