<?php
/**
 * Photo reviews — upload, storage and retrieval.
 *
 * Reviewers can attach images to a review when "Allow photo review" is on. The
 * files become ordinary WordPress attachments parented to the reviewed post,
 * with their ids kept in comment meta. Because this endpoint is reachable by
 * logged-out visitors, every upload is checked three ways before it is stored:
 * how many, how big, and what the bytes actually are — the browser-supplied
 * MIME type is never trusted.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Review photo attachments.
 */
class Bdrvw_Photos {

	/** Comment meta key holding the attachment ids. */
	const META_KEY = 'bdrvw_photos';

	/** Field name the form posts under. */
	const FIELD = 'bdrvw_photos';

	/** Most photos one review may carry. */
	const MAX_PHOTOS = 3;

	/** Largest single file accepted, in bytes. */
	const MAX_BYTES = 5242880; // 5 MB.

	/**
	 * Image types accepted, as extension pattern => MIME type.
	 *
	 * Deliberately narrow. SVG is excluded on purpose: it is a document that can
	 * carry script, not a picture, and nothing about a product review needs it.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_mimes(): array {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);
	}

	/**
	 * Is the feature switched on?
	 */
	public static function enabled( Bdrvw_Settings $settings ): bool {
		return ! empty( $settings->get( 'allow_photo_review', 0 ) );
	}

	/**
	 * The upload control: drop area on the left, rules and an Upload button in
	 * the middle, picked photos on the right.
	 *
	 * Shared by the public review form and the admin "Edit review" panel so the
	 * two can't drift apart. Both the drop area and the Upload button are
	 * `<label>`s pointing at the real file input, so clicking either opens the
	 * picker with no JavaScript; the input is clipped rather than hidden, which
	 * keeps it in the tab order.
	 *
	 * @param array<string,mixed> $args id: field id (required). title: heading.
	 *                                  existing: attachment ids already attached,
	 *                                  rendered with a hidden `photos[]` input so
	 *                                  a save can tell kept from removed.
	 *                                  compact: drop the heading and the file
	 *                                  rules, and stack the photos underneath —
	 *                                  for the admin panel, where the row already
	 *                                  has its own label and little width.
	 */
	public static function uploader_html( array $args = array() ): string {
		$field_id = (string) ( $args['id'] ?? 'bdrvw-photos' );
		$title    = (string) ( $args['title'] ?? __( 'Attachment', 'boldreview' ) );
		$existing = isset( $args['existing'] ) ? (array) $args['existing'] : array();
		$compact  = ! empty( $args['compact'] );

		$extensions = array();
		foreach ( array_keys( self::allowed_mimes() ) as $pattern ) {
			foreach ( explode( '|', (string) $pattern ) as $ext ) {
				$extensions[] = '.' . $ext;
			}
		}

		ob_start();
		?>
		<div class="bdrvw-uploader<?php echo $compact ? ' bdrvw-uploader--compact' : ''; ?>" data-bdrvw-uploader>
			<label class="bdrvw-uploader__drop" for="<?php echo esc_attr( $field_id ); ?>" data-bdrvw-photo-drop>
				<span class="bdrvw-uploader__placeholder">
					<span class="bdrvw-uploader__icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
							<rect x="3" y="3" width="18" height="18" rx="3"></rect>
							<circle cx="8.5" cy="8.5" r="1.5"></circle>
							<path d="M21 15l-5-5L5 21"></path>
						</svg>
					</span>
					<span class="bdrvw-uploader__drop-text"><?php esc_html_e( 'Upload Image', 'boldreview' ); ?></span>
				</span>
			</label>

			<div class="bdrvw-uploader__info">
				<?php if ( ! $compact ) : ?>
					<span class="bdrvw-uploader__title"><?php echo esc_html( $title ); ?></span>
					<span class="bdrvw-uploader__meta">
						<?php
						printf(
							/* translators: %s: comma-separated list of file extensions, e.g. ".jpg, .png". */
							esc_html__( 'File Support: %s', 'boldreview' ),
							esc_html( implode( ', ', $extensions ) )
						);
						?>
					</span>
					<span class="bdrvw-uploader__meta">
						<?php
						printf(
							/* translators: 1: number of photos allowed, 2: size limit, e.g. "5 MB". */
							esc_html__( 'Up to %1$s photos, %2$s each', 'boldreview' ),
							esc_html( number_format_i18n( self::MAX_PHOTOS ) ),
							esc_html( size_format( self::MAX_BYTES ) )
						);
						?>
					</span>
				<?php endif; ?>

				<label class="bdrvw-uploader__btn" for="<?php echo esc_attr( $field_id ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M12 16V6"></path>
						<path d="M8.5 9.5L12 6l3.5 3.5"></path>
						<path d="M20 16.5A3.5 3.5 0 0 0 18 10a6 6 0 0 0-11.6-1.5A4 4 0 0 0 6 16.5"></path>
					</svg>
					<?php esc_html_e( 'Upload', 'boldreview' ); ?>
				</label>

				<span class="bdrvw-photos__rejected" data-bdrvw-photo-error hidden></span>
			</div>

			<?php // Outside the drop label on purpose — inside it, clicking a remove button would also reopen the file picker. ?>
			<div class="bdrvw-uploader__preview" data-bdrvw-photo-preview data-max="<?php echo esc_attr( (string) self::MAX_PHOTOS ); ?>">
				<?php foreach ( $existing as $photo_id ) : ?>
					<?php
					$thumb = wp_get_attachment_image(
						(int) $photo_id,
						'thumbnail',
						false,
						array(
							'class' => 'bdrvw-photos__thumb',
							'alt'   => '',
						)
					);
					if ( '' === (string) $thumb ) {
						continue; // Removed from the Media Library since.
					}
					?>
					<span class="bdrvw-photos__item" data-bdrvw-edit-photo>
						<?php echo wp_kses_post( $thumb ); ?>
						<?php // Kept, not deleted, until the form is saved — so Cancel really cancels. ?>
						<input type="hidden" name="photos[]" value="<?php echo esc_attr( (string) $photo_id ); ?>" />
						<button
							type="button"
							class="bdrvw-photos__remove"
							data-bdrvw-remove-photo
							aria-label="<?php esc_attr_e( 'Remove photo', 'boldreview' ); ?>"
						>
							<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"></path></svg>
						</button>
					</span>
				<?php endforeach; ?>
			</div>

			<input
				id="<?php echo esc_attr( $field_id ); ?>"
				class="bdrvw-photos__input"
				type="file"
				name="<?php echo esc_attr( self::FIELD ); ?>[]"
				accept="<?php echo esc_attr( implode( ',', array_values( self::allowed_mimes() ) ) ); ?>"
				multiple
			/>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Take the uploaded files off `$_FILES` and turn them into attachments,
	 * adding them to whatever the review already carries.
	 *
	 * Adds rather than replaces, because this runs for two callers: a new
	 * submission (nothing to keep) and an admin edit (everything to keep unless
	 * it was explicitly removed). Returns the attachment ids that made it —
	 * anything rejected is skipped rather than failing the whole save, so a
	 * good review isn't lost to one unsupported file.
	 *
	 * The files come in as an argument rather than being read off `$_FILES`
	 * here, so the read stays in the same scope as the nonce check that guards
	 * it — in a helper this far down, neither a reader nor a static analyser can
	 * tell whether the request was ever verified.
	 *
	 * @param int                 $review_id Comment id the photos belong to.
	 * @param int                 $post_id   Post being reviewed; the attachments hang off it.
	 * @param array<string,mixed> $uploads   The upload field's `$_FILES` entry, sanitised
	 *                                       by the caller.
	 * @return array<int,int> Ids of the newly stored photos, in upload order.
	 */
	public static function attach_uploads( int $review_id, int $post_id, array $uploads = array() ): array {
		$files = self::normalize_files( $uploads );
		if ( empty( $files ) ) {
			return array();
		}

		$existing = self::for_review( $review_id );
		$room     = self::MAX_PHOTOS - count( $existing );
		if ( $room <= 0 ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ids = array();

		foreach ( $files as $file ) {
			if ( count( $ids ) >= $room ) {
				break;
			}
			// static:: rather than self:: so the per-file step stays overridable.
			$id = static::store_one( $file, $post_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( ! empty( $ids ) ) {
			update_comment_meta( $review_id, self::META_KEY, array_merge( $existing, $ids ) );
		}

		return $ids;
	}

	/**
	 * Why an upload can't be accepted, or '' when it can.
	 *
	 * The MIME check reads the file itself rather than the `type` the browser
	 * sent, which is just another request header and can say anything. A script
	 * renamed to `.jpg` passes an extension check and fails this one, because
	 * it has no image header to read.
	 *
	 * @param array<string,mixed> $file One entry in PHP's single-file shape.
	 */
	public static function rejection_reason( array $file ): string {
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return 'upload-error';
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 ) {
			return 'empty';
		}
		if ( $size > self::MAX_BYTES ) {
			return 'too-large';
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return 'unreadable';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image legitimately warns; the false return is the answer.
		$sniffed = @getimagesize( $tmp );
		if ( ! is_array( $sniffed ) || empty( $sniffed['mime'] ) ) {
			return 'not-an-image';
		}
		if ( ! in_array( (string) $sniffed['mime'], array_values( self::allowed_mimes() ), true ) ) {
			return 'unsupported-type';
		}

		return '';
	}

	/**
	 * Validate and store a single upload.
	 *
	 * @param array<string,mixed> $file One entry in PHP's single-file shape.
	 * @return int Attachment id, or 0 when the file was rejected.
	 */
	protected static function store_one( array $file, int $post_id ): int {
		if ( '' !== self::rejection_reason( $file ) ) {
			return 0;
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( ! is_uploaded_file( $tmp ) ) {
			return 0;
		}

		$overrides = array(
			'test_form' => false, // No form field to compare against on an AJAX post.
			'mimes'     => self::allowed_mimes(),
		);

		$moved = wp_handle_upload( $file, $overrides );
		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['file'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $moved['type'],
				'post_title'     => sanitize_file_name( pathinfo( (string) $moved['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			(string) $moved['file'],
			$post_id,
			true
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			// Nothing references the file now, so don't leave it in uploads.
			wp_delete_file( (string) $moved['file'] );
			return 0;
		}

		$attachment_id = (int) $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, (string) $moved['file'] ) );
		update_post_meta( $attachment_id, '_bdrvw_review_photo', 1 );

		return $attachment_id;
	}

	/**
	 * Reshape `$_FILES` into one entry per file.
	 *
	 * A `name="x[]"` upload arrives as parallel arrays (`name` is an array,
	 * `tmp_name` is an array, …) rather than a list of files, so it has to be
	 * transposed before anything can be done with it one file at a time.
	 *
	 * @param array<string,mixed> $raw The upload field's `$_FILES` entry.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function normalize_files( array $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		$keys = array( 'name', 'type', 'tmp_name', 'error', 'size' );

		// Single file: already in the right shape.
		if ( ! isset( $raw['name'] ) || ! is_array( $raw['name'] ) ) {
			return array( $raw );
		}

		$out   = array();
		$count = count( $raw['name'] );
		for ( $i = 0; $i < $count && $i < self::MAX_PHOTOS; $i++ ) {
			$entry = array();
			foreach ( $keys as $key ) {
				$entry[ $key ] = $raw[ $key ][ $i ] ?? null;
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Attachment ids stored against a review.
	 *
	 * @return array<int,int>
	 */
	public static function for_review( int $review_id ): array {
		if ( $review_id <= 0 ) {
			return array();
		}
		$ids = get_comment_meta( $review_id, self::META_KEY, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Narrow a review's photos down to the ones still wanted.
	 *
	 * Only ever removes: ids that aren't already on the review are ignored, so
	 * a tampered-with edit form can't attach someone else's media to a review.
	 * Whatever drops out is deleted, since nothing else references it.
	 *
	 * @param int             $review_id Review id.
	 * @param array<int,mixed> $keep     Attachment ids to retain.
	 */
	public static function sync_for_review( int $review_id, array $keep ): void {
		$current = self::for_review( $review_id );
		if ( empty( $current ) ) {
			return;
		}

		$keep    = array_values( array_filter( array_map( 'absint', $keep ) ) );
		$keep    = array_values( array_intersect( $current, $keep ) );
		$dropped = array_diff( $current, $keep );

		foreach ( $dropped as $id ) {
			if ( get_post_meta( (int) $id, '_bdrvw_review_photo', true ) ) {
				wp_delete_attachment( (int) $id, true );
			}
		}

		if ( empty( $keep ) ) {
			delete_comment_meta( $review_id, self::META_KEY );
			return;
		}

		update_comment_meta( $review_id, self::META_KEY, $keep );
	}

	/**
	 * Delete a review's photos. Called when the review itself is deleted, so
	 * the uploads folder doesn't accumulate files nothing points at.
	 */
	public static function delete_for_review( int $review_id ): void {
		foreach ( self::for_review( $review_id ) as $id ) {
			
			if ( get_post_meta( $id, '_bdrvw_review_photo', true ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		delete_comment_meta( $review_id, self::META_KEY );
	}
}
