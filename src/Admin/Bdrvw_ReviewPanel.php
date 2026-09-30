<?php
/**
 * Markup for the two review side-panels used by the Reviews list table:
 * the read-only "Review details" drawer and the "Edit review" form.
 *
 * Both are rendered server-side and shipped to the browser over AJAX so the
 * criteria labels, status list and product data stay in one place.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Admin;

use BoldReview\Plugin\Core\Bdrvw_Photos;
use BoldReview\Plugin\Core\Bdrvw_Settings;
use BoldReview\Plugin\Frontend\Bdrvw_ReviewRenderer;
use BoldReview\Plugin\Models\Bdrvw_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the review details drawer + edit form.
 */
class Bdrvw_ReviewPanel {

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
	 * Human labels for the review statuses, used by the status <select>.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'pending'  => __( 'Pending', 'boldreview' ),
			'approved' => __( 'Approved', 'boldreview' ),
			'rejected' => __( 'Rejected', 'boldreview' ),
			'spam'     => __( 'Spam', 'boldreview' ),
			'trash'    => __( 'Trash', 'boldreview' ),
		);
	}

	/**
	 * Label for a single status (falls back to the raw slug).
	 */
	public static function status_label( string $status ): string {
		$labels = self::status_labels();
		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Whether the reviewed item is a WooCommerce product. Only products carry a
	 * product image, so reviews left on ordinary posts get no thumbnail at all.
	 */
	public static function is_product( int $post_id ): bool {
		return $post_id > 0
			&& function_exists( 'wc_get_product' )
			&& 'product' === get_post_type( $post_id );
	}

	/**
	 * Product image for the reviewed item. Empty string when the review is not
	 * on a product; products without a featured image get a neutral inline
	 * placeholder so the layout stays aligned.
	 */
	public static function thumb_url( int $post_id ): string {
		if ( ! self::is_product( $post_id ) ) {
			return '';
		}
		$url = (string) get_the_post_thumbnail_url( $post_id, 'thumbnail' );
		if ( '' !== $url ) {
			return $url;
		}
		return 'data:image/svg+xml;charset=utf8,' . rawurlencode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="6" fill="#f1f5f9"/><path d="M13 32l7-8 5 6 4-4 6 6H13z" fill="#cbd5e1"/><circle cx="18" cy="18" r="3" fill="#cbd5e1"/></svg>'
		);
	}

	/**
	 * Price markup for the reviewed item when WooCommerce is active and the
	 * item is a product. Empty string otherwise.
	 */
	public static function price_html( int $post_id ): string {
		if ( ! self::is_product( $post_id ) ) {
			return '';
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return '';
		}
		return (string) $product->get_price_html();
	}

	/**
	 * Criterion key => label map for the post the review belongs to, so the
	 * stored `criteria` keys can be shown with their configured names.
	 *
	 * @return array<string,string>
	 */
	public function criteria_labels( int $post_id ): array {
		$out      = array();
		$criteria = ( new Bdrvw_ReviewRenderer( $this->settings ) )->resolve_criteria_for_post( $post_id );
		foreach ( $criteria as $crit ) {
			$key = (string) ( $crit['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = (string) ( $crit['label'] ?? $key );
		}
		return $out;
	}

	/**
	 * Formatted "submitted on" string.
	 */
	protected function submitted_on( string $ts ): string {
		if ( '' === $ts ) {
			return '';
		}
		return sprintf(
			'%s %s',
			mysql2date( get_option( 'date_format' ), $ts ),
			mysql2date( get_option( 'time_format' ), $ts )
		);
	}

	/**
	 * Read-only "Review details" drawer body.
	 *
	 * @param array<string,mixed> $review Review row.
	 */
	public function details_html( array $review ): string {
		$id      = (int) ( $review['id'] ?? 0 );
		$post_id = (int) ( $review['post_id'] ?? 0 );
		$status  = (string) ( $review['status'] ?? '' );
		// Criteria-only reviews have no overall star; score them by their criteria.
		$score   = Bdrvw_Review::effective_rating( $review );
		$rating  = (int) round( $score );
		$name    = (string) ( $review['author_name'] ?? '' );
		$email   = (string) ( $review['author_email'] ?? '' );
		$title   = (string) ( $review['title'] ?? '' );
		$content = (string) ( $review['content'] ?? '' );
		$crits   = is_array( $review['criteria'] ?? null ) ? $review['criteria'] : array();
		$labels  = $this->criteria_labels( $post_id );

		$post_title = $post_id > 0 ? get_the_title( $post_id ) : '';
		$post_title = '' !== $post_title ? $post_title : __( '(item removed)', 'boldreview' );
		$edit_link  = $post_id > 0 ? get_edit_post_link( $post_id ) : '';
		$price      = self::price_html( $post_id );
		$thumb      = self::thumb_url( $post_id );

		ob_start();
		?>
		<div class="bdrvw-rd">
			<div class="bdrvw-rd__product">
				<?php if ( '' !== $thumb ) : ?>
					<img class="bdrvw-rd__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" />
				<?php endif; ?>
				<div class="bdrvw-rd__product-meta">
					<h3 class="bdrvw-rd__product-title">
						<?php if ( $edit_link ) : ?>
							<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $post_title ); ?>
						<?php endif; ?>
					</h3>
					<?php if ( '' !== $price ) : ?>
						<p class="bdrvw-rd__price">
							<?php esc_html_e( 'Price:', 'boldreview' ); ?>
							<?php echo wp_kses_post( $price ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="bdrvw-rd__section">
				<span class="bdrvw-rd__section-title"><?php esc_html_e( 'Review', 'boldreview' ); ?></span>
				<button type="button" class="bdrvw-rd__edit" data-bdrvw-edit="<?php echo esc_attr( (string) $id ); ?>">
					<span class="dashicons dashicons-edit"></span>
					<?php
					echo self::is_reply( $review )
						? esc_html__( 'Edit reply', 'boldreview' )
						: esc_html__( 'Edit review', 'boldreview' );
					?>
				</button>
			</div>

			<div class="bdrvw-rd__rating">
				<?php if ( $score > 0 ) : ?>
					<strong><?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></strong>
					<span class="bdrvw-rd__of">/5</span>
				<?php endif; ?>
				<?php echo wp_kses_post( self::stars_html( $rating ) ); ?>
				<span class="bdrvw-status bdrvw-status--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( self::status_label( $status ) ); ?>
				</span>
			</div>

			<?php if ( ! empty( $crits ) ) : ?>
				<div class="bdrvw-rd__criteria">
					<?php foreach ( $crits as $key => $val ) : ?>
						<?php
						$val   = max( 0, min( 5, (int) $val ) );
						$label = $labels[ (string) $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) );
						?>
						<div class="bdrvw-rd__crit">
							<span class="bdrvw-rd__crit-label"><?php echo esc_html( $label ); ?></span>
							<span class="bdrvw-rd__crit-bar">
								<span style="width:<?php echo esc_attr( (string) ( $val * 20 ) ); ?>%"></span>
							</span>
							<span class="bdrvw-rd__crit-val">
								<?php echo esc_html( (string) $val ); ?>
								<span class="dashicons dashicons-star-filled"></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="bdrvw-rd__author">
				<span class="bdrvw-rd__avatar"><?php echo esc_html( strtoupper( substr( '' !== $name ? $name : '?', 0, 1 ) ) ); ?></span>
				<div class="bdrvw-rd__author-meta">
					<strong><?php echo esc_html( '' !== $name ? $name : __( 'Anonymous', 'boldreview' ) ); ?></strong>
					<?php if ( '' !== $email ) : ?>
						<a href="mailto:<?php echo esc_attr( antispambot( $email ) ); ?>"><?php echo esc_html( antispambot( $email ) ); ?></a>
					<?php endif; ?>
				</div>
				<span class="bdrvw-rd__account<?php echo (int) ( $review['user_id'] ?? 0 ) > 0 ? ' is-registered' : ''; ?>">
					<?php echo (int) ( $review['user_id'] ?? 0 ) > 0 ? esc_html__( 'Registered user', 'boldreview' ) : esc_html__( 'Guest', 'boldreview' ); ?>
				</span>
			</div>

			<div class="bdrvw-rd__date"><?php echo esc_html( $this->submitted_on( (string) ( $review['created_at'] ?? '' ) ) ); ?></div>

			<?php if ( '' !== $title ) : ?>
				<h4 class="bdrvw-rd__title"><?php echo esc_html( $title ); ?></h4>
			<?php endif; ?>
			<?php
			echo self::in_reply_to_html( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<div class="bdrvw-rd__content"><?php echo wp_kses_post( wpautop( $content ) ); ?></div>

			<?php
			$photos = Bdrvw_Photos::for_review( $id );
			?>
			<?php if ( ! empty( $photos ) ) : ?>
				<div class="bdrvw-rd__photos">
					<span class="bdrvw-rd__section-title"><?php esc_html_e( 'Photos', 'boldreview' ); ?></span>
					<div class="bdrvw-rd__photos-grid">
						<?php foreach ( $photos as $photo_id ) : ?>
							<?php
							$full  = wp_get_attachment_image_url( (int) $photo_id, 'large' );
							$thumb_img = wp_get_attachment_image( (int) $photo_id, 'thumbnail', false, array( 'alt' => '' ) );
							if ( '' === (string) $thumb_img ) {
								continue;
							}
							?>
							<?php if ( $full ) : ?>
								<a href="<?php echo esc_url( $full ); ?>" target="_blank" rel="noopener"><?php echo wp_kses_post( $thumb_img ); ?></a>
							<?php else : ?>
								<?php echo wp_kses_post( $thumb_img ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="bdrvw-rd__foot">
				<?php if ( 'trash' === $status ) : ?>
					<a class="bdrvw-btn-secondary" href="<?php echo esc_url( Bdrvw_ReviewsPage::action_url( $id, 'approve' ) ); ?>">
						<?php esc_html_e( 'Restore', 'boldreview' ); ?>
					</a>
					<a
						class="bdrvw-btn bdrvw-btn--danger"
						href="<?php echo esc_url( Bdrvw_ReviewsPage::action_url( $id, 'delete' ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Delete this review permanently?', 'boldreview' ) ); ?>')"
					>
						<?php esc_html_e( 'Delete permanently', 'boldreview' ); ?>
					</a>
				<?php else : ?>
					<a class="bdrvw-btn-secondary" href="<?php echo esc_url( Bdrvw_ReviewsPage::action_url( $id, 'spam' ) ); ?>">
						<?php esc_html_e( 'Move to spam', 'boldreview' ); ?>
					</a>
					<a class="bdrvw-btn bdrvw-btn--danger" href="<?php echo esc_url( Bdrvw_ReviewsPage::action_url( $id, 'trash' ) ); ?>">
						<?php esc_html_e( 'Move to Trash', 'boldreview' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Editable form body. Posted back through the `bdrvw_save_review` endpoint.
	 *
	 * @param array<string,mixed> $review Review row.
	 */
	public function edit_form_html( array $review ): string {
		$id      = (int) ( $review['id'] ?? 0 );
		$post_id = (int) ( $review['post_id'] ?? 0 );
		$status  = Bdrvw_Review::valid_status( (string) ( $review['status'] ?? '' ) );
		$crits   = is_array( $review['criteria'] ?? null ) ? $review['criteria'] : array();
		$labels  = $this->criteria_labels( $post_id );

		$is_reply = self::is_reply( $review );
		$writable = Bdrvw_Review::updatable_fields();
		$can      = static function ( string $field ) use ( $writable, $is_reply ): bool {
			if ( ! isset( $writable[ $field ] ) ) {
				return false;
			}
			return ! ( $is_reply && in_array( $field, array( 'rating', 'title', 'criteria_ratings' ), true ) );
		};

		$rows = array();
		foreach ( $labels as $key => $label ) {
			$rows[ $key ] = array( $label, (int) ( $crits[ $key ] ?? 0 ) );
		}
		foreach ( $crits as $key => $val ) {
			$key = (string) $key;
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( ucwords( str_replace( array( '_', '-' ), ' ', $key ) ), (int) $val );
			}
		}

		ob_start();
		?>
		<form class="bdrvw-editform" data-bdrvw-edit-form<?php echo $is_reply ? ' data-bdrvw-reply="1"' : ''; ?>>
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />

			<?php if ( $can( 'criteria_ratings' ) ) : ?>
				<?php foreach ( $rows as $key => $row ) : ?>
					<div class="bdrvw-editform__row">
						<label class="bdrvw-editform__label"><?php echo esc_html( $row[0] ); ?></label>
						<?php echo wp_kses( self::stars_picker_html( 'criteria[' . $key . ']', (int) $row[1] ), self::picker_kses() ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( $can( 'rating' ) ) : ?>
				<div class="bdrvw-editform__row">
					<label class="bdrvw-editform__label"><?php esc_html_e( 'Overall rating', 'boldreview' ); ?></label>
					<?php echo wp_kses( self::stars_picker_html( 'rating', (int) ( $review['rating'] ?? 0 ) ), self::picker_kses() ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $can( 'title' ) ) : ?>
			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label" for="bdrvw-edit-title"><?php esc_html_e( 'Review Title', 'boldreview' ); ?></label>
				<input
					type="text"
					id="bdrvw-edit-title"
					name="title"
					class="bdrvw-input bdrvw-input--block"
					value="<?php echo esc_attr( (string) ( $review['title'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Write Review Title', 'boldreview' ); ?>"
				/>
			</div>
			<?php endif; ?>

			<?php if ( $can( 'content' ) ) : ?>
			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label" for="bdrvw-edit-content"><?php esc_html_e( 'Description', 'boldreview' ); ?></label>
				<textarea
					id="bdrvw-edit-content"
					name="content"
					rows="5"
					class="bdrvw-input bdrvw-input--block"
				><?php echo esc_textarea( (string) ( $review['content'] ?? '' ) ); ?></textarea>
			</div>
			<?php endif; ?>

			<?php if ( $can( 'author_name' ) ) : ?>
			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label" for="bdrvw-edit-name"><?php esc_html_e( 'Full name', 'boldreview' ); ?></label>
				<input
					type="text"
					id="bdrvw-edit-name"
					name="author_name"
					class="bdrvw-input bdrvw-input--block"
					value="<?php echo esc_attr( (string) ( $review['author_name'] ?? '' ) ); ?>"
				/>
			</div>
			<?php endif; ?>

			<?php if ( $can( 'author_email' ) ) : ?>
			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label" for="bdrvw-edit-email"><?php esc_html_e( 'Email address', 'boldreview' ); ?></label>
				<input
					type="email"
					id="bdrvw-edit-email"
					name="author_email"
					class="bdrvw-input bdrvw-input--block"
					value="<?php echo esc_attr( (string) ( $review['author_email'] ?? '' ) ); ?>"
				/>
			</div>
			<?php endif; ?>

			<?php if ( ! $is_reply ) : ?>
			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label"><?php esc_html_e( 'Attachment', 'boldreview' ); ?></label>
				<?php // Marks that this form owns the photo list. Sits outside the items so it survives removing every one of them — otherwise clearing the last photo would post no `photos` key at all and read as "leave them alone". ?>
				<input type="hidden" name="photos_present" value="1" />
				<?php
				$uploader_html = Bdrvw_Photos::uploader_html(
					array(
						'id'       => 'bdrvw-edit-photos',
						'existing' => Bdrvw_Photos::for_review( $id ),
						'compact'  => true,
					)
				);
				echo $uploader_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				?>
			</div>
			<?php endif; ?>

			<div class="bdrvw-editform__row">
				<label class="bdrvw-editform__label" for="bdrvw-edit-status"><?php esc_html_e( 'Review status', 'boldreview' ); ?></label>
				<select id="bdrvw-edit-status" name="status" class="bdrvw-input bdrvw-input--block">
					<?php foreach ( self::status_labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="bdrvw-editform__foot">
				<button type="button" class="bdrvw-btn-secondary" data-bdrvw-panel-close>
					<?php esc_html_e( 'Cancel', 'boldreview' ); ?>
				</button>
				<?php
				/**
				 * Renders the edit form's submit control. Add-ons that implement
				 * the save behaviour replace the default callback with their own.
				 *
				 * @param array<string,mixed> $review Review row being edited.
				 */
				do_action( 'bdrvw_review_edit_save_control', $review );
				?>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Submit control for the edit form. Registered on
	 * `bdrvw_review_edit_save_control` so an add-on can still replace it.
	 *
	 * @param array<string,mixed> $review Review row being edited.
	 */
	public static function render_save_control( $review = array() ): void {
		$review = is_array( $review ) ? $review : array();

		printf(
			'<button type="submit" class="bdrvw-btn" data-bdrvw-save>%s</button>',
			self::is_reply( $review )
				? esc_html__( 'Save Reply', 'boldreview' )
				: esc_html__( 'Save Review', 'boldreview' )
		);
	}

	/**
	 * The "Reply" panel: a summary of what is being answered, then the box to
	 * answer it in.
	 *
	 * The summary is not decoration — a reply written without the review in
	 * front of you is how shops end up answering the wrong customer.
	 *
	 * @param array<string,mixed> $review Review being replied to.
	 */
	public function reply_form_html( array $review ): string {
		$id      = (int) ( $review['id'] ?? 0 );
		$post_id = (int) ( $review['post_id'] ?? 0 );
		$score   = Bdrvw_Review::effective_rating( $review );
		$name    = (string) ( $review['author_name'] ?? '' );
		$title   = (string) ( $review['title'] ?? '' );
		$content = (string) ( $review['content'] ?? '' );
		$thumb   = self::thumb_url( $post_id );

		$post_title = $post_id > 0 ? get_the_title( $post_id ) : '';
		$post_title = '' !== $post_title ? $post_title : __( '(item removed)', 'boldreview' );

		ob_start();
		?>
		<div class="bdrvw-rd">
			<div class="bdrvw-rd__product">
				<?php if ( '' !== $thumb ) : ?>
					<img class="bdrvw-rd__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" />
				<?php endif; ?>
				<div class="bdrvw-rd__product-meta">
					<h3 class="bdrvw-rd__product-title"><?php echo esc_html( $post_title ); ?></h3>
				</div>
			</div>

			<div class="bdrvw-rd__rating">
				<?php if ( $score > 0 ) : ?>
					<strong><?php echo esc_html( number_format_i18n( $score, 1 ) ); ?></strong>
					<span class="bdrvw-rd__of">/5</span>
				<?php endif; ?>
				<?php echo wp_kses_post( self::stars_html( (int) round( $score ) ) ); ?>
			</div>

			<div class="bdrvw-rd__author">
				<span class="bdrvw-rd__avatar"><?php echo esc_html( strtoupper( substr( '' !== $name ? $name : '?', 0, 1 ) ) ); ?></span>
				<div class="bdrvw-rd__author-meta">
					<strong><?php echo esc_html( '' !== $name ? $name : __( 'Anonymous', 'boldreview' ) ); ?></strong>
					<span class="bdrvw-rd__date"><?php echo esc_html( $this->submitted_on( (string) ( $review['created_at'] ?? '' ) ) ); ?></span>
				</div>
			</div>

			<?php if ( '' !== $title ) : ?>
				<h4 class="bdrvw-rd__title"><?php echo esc_html( $title ); ?></h4>
			<?php endif; ?>
			<div class="bdrvw-rd__content"><?php echo wp_kses_post( wpautop( $content ) ); ?></div>

			<form class="bdrvw-editform bdrvw-replyform" data-bdrvw-reply-form>
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />

				<div class="bdrvw-editform__row">
					<label class="bdrvw-editform__label" for="bdrvw-reply-content"><?php esc_html_e( 'Your reply', 'boldreview' ); ?></label>
					<textarea
						id="bdrvw-reply-content"
						name="content"
						rows="5"
						class="bdrvw-input bdrvw-input--block"
						placeholder="<?php esc_attr_e( 'Write your reply…', 'boldreview' ); ?>"
						required
					></textarea>
				</div>

				<div class="bdrvw-editform__foot">
					<button type="button" class="bdrvw-btn-secondary" data-bdrvw-panel-close>
						<?php esc_html_e( 'Cancel', 'boldreview' ); ?>
					</button>
					<button type="submit" class="bdrvw-btn" data-bdrvw-send-reply>
						<?php esc_html_e( 'Send Reply', 'boldreview' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Is this row a reply rather than a review?
	 *
	 * @param array<string,mixed> $review Review row.
	 */
	public static function is_reply( array $review ): bool {
		return (int) ( $review['parent_id'] ?? 0 ) > 0;
	}

	/**
	 * "In reply to <author>." line, the way core's comments list marks a
	 * threaded answer. The name opens the parent's detail panel, so whatever is
	 * being answered stays one click away.
	 *
	 * Empty string when the row is not a reply, or when the parent is gone and
	 * there is no name left to point at.
	 *
	 * @param array<string,mixed> $review Review row.
	 */
	public static function in_reply_to_html( array $review ): string {
		$parent_id = (int) ( $review['parent_id'] ?? 0 );
		if ( $parent_id <= 0 ) {
			return '';
		}

		static $names = array();

		if ( ! array_key_exists( $parent_id, $names ) ) {
			$parent              = Bdrvw_Review::find( $parent_id );
			$names[ $parent_id ] = null !== $parent ? (string) ( $parent['author_name'] ?? '' ) : '';
		}

		$name = $names[ $parent_id ];
		if ( '' === $name ) {
			return '';
		}

		$link = '<button type="button" class="bdrvw-inreply__author" data-bdrvw-view="'
			. esc_attr( (string) $parent_id ) . '">' . esc_html( $name ) . '</button>';

		return '<span class="bdrvw-inreply">' . sprintf(
			/* translators: %s: name of the person being replied to, rendered as a link. */
			esc_html__( 'In reply to %s.', 'boldreview' ),
			$link
		) . '</span>';
	}

	/**
	 * Whether the edit form for this row can be submitted.
	 *
	 * Editing reviews and replies is part of the base plugin — moderating what
	 * appears on your own product pages is not an upsell, and WordPress already
	 * lets any moderator edit a comment. The filter stays so a site can lock
	 * editing down.
	 *
	 * @param array<string,mixed> $review Row being edited.
	 */
	public static function edit_enabled( array $review = array() ): bool {
		/**
		 * Filters whether editing is available for this row.
		 *
		 * @param bool                $enabled Default true.
		 * @param array<string,mixed> $review  Row being edited.
		 */
		return (bool) apply_filters( 'bdrvw_review_edit_enabled', true, $review );
	}

	/**
	 * Static five-star display.
	 */
	public static function stars_html( int $rating ): string {
		$rating = max( 0, min( 5, $rating ) );
		$out    = '<span class="bdrvw-stars">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$cls  = $i <= $rating ? 'dashicons-star-filled' : 'dashicons-star-empty';
			$out .= '<span class="dashicons ' . esc_attr( $cls ) . '"></span>';
		}
		return $out . '</span>';
	}

	/**
	 * Clickable 0–5 star picker. Mirrors the settings-page markup so it is
	 * driven by the existing `[data-stars-picker]` handler in admin.js.
	 *
	 * @param string $name  Input name posted with the edit form.
	 * @param int    $value Current value, 0–5.
	 */
	public static function stars_picker_html( string $name, int $value ): string {
		$value = max( 0, min( 5, $value ) );
		$out   = '<div class="bdrvw-stars-picker" data-stars-picker data-rating="' . esc_attr( (string) $value ) . '">';
		$out  .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" data-stars-input />';
		for ( $i = 1; $i <= 5; $i++ ) {
			$out .= '<button type="button" class="bdrvw-stars-picker__star' . ( $i <= $value ? ' is-on' : '' ) . '"'
				. ' data-star="' . esc_attr( (string) $i ) . '"'
				. ' aria-label="' . esc_attr( sprintf( /* translators: %d: rating value 1-5. */ __( 'Rate %d out of 5', 'boldreview' ), $i ) ) . '">&#9733;</button>';
		}
		return $out . '</div>';
	}

	/**
	 * Allowed tags for the star-picker markup passed through wp_kses().
	 *
	 * @return array<string,array<string,bool>>
	 */
	protected static function picker_kses(): array {
		return array(
			'div'    => array(
				'class'             => true,
				'data-stars-picker' => true,
				'data-rating'       => true,
			),
			'input'  => array(
				'type'             => true,
				'name'             => true,
				'value'            => true,
				'data-stars-input' => true,
			),
			'button' => array(
				'type'       => true,
				'class'      => true,
				'data-star'  => true,
				'aria-label' => true,
			),
		);
	}
}
