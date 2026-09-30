<?php
/**
 * Frontend HTML renderer.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Frontend;

use BoldReview\Plugin\Core\Bdrvw_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Produces escaped HTML for the form and the reviews list.
 */
class Bdrvw_ReviewRenderer {

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
	 * Apply per-render style overrides (e.g. from shortcode attributes) on top of
	 * the resolved settings array. Unknown/empty values are ignored so the admin
	 * setting stays in effect.
	 *
	 * Recognised keys:
	 *   'summary_style' → bars|point|pie|hbars|stripes|gauge|tiles|overview
	 *   'input_style'   → stars|slider|bar|square|pill (also force-enables the
	 *                     rating input so an explicit style always shows).
	 *
	 * @param array<string,mixed> $s         Resolved settings.
	 * @param array<string,mixed> $overrides Overrides to apply.
	 * @return array<string,mixed>
	 */
	protected function apply_style_overrides( array $s, array $overrides ): array {
		if ( empty( $overrides ) ) {
			return $s;
		}
		$summary_styles = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );
		$input_styles   = array( 'stars', 'slider', 'bar', 'square', 'pill' );

		$summary = isset( $overrides['summary_style'] ) ? (string) $overrides['summary_style'] : '';
		if ( '' !== $summary && in_array( $summary, $summary_styles, true ) ) {
			$s['summary_style'] = $summary;
		}

		$input = isset( $overrides['input_style'] ) ? (string) $overrides['input_style'] : '';
		if ( '' !== $input && in_array( $input, $input_styles, true ) ) {
			$s['rating_input_style']         = $input;
			$s['rating_input_style_enabled'] = 1;
		}

		return $s;
	}

	/**
	 * The photo upload control, wrapped in a form row.
	 *
	 * The control itself is shared with the admin "Edit review" panel, so the
	 * two always look and behave the same.
	 *
	 * @param int $post_id Post being reviewed; scopes the field id.
	 */
	protected function photo_uploader_html( int $post_id ): string {
		return "<div class=\"bdrvw-form__row bdrvw-photos\">"
			. \BoldReview\Plugin\Core\Bdrvw_Photos::uploader_html(
				array( "id" => "bdrvw-field-photos-" . $post_id )
			)
			. "</div>";
	}

	/**
	 * Photos a reviewer attached, as a row of thumbnails linking to the full
	 * size image.
	 *
	 * Rendered whenever a review has photos, regardless of the current setting:
	 * turning the feature off should stop new uploads, not retroactively hide
	 * pictures visitors have already been shown.
	 *
	 * @param int $review_id Review id.
	 */
	protected function review_photos_html( int $review_id ): string {
		$ids = \BoldReview\Plugin\Core\Bdrvw_Photos::for_review( $review_id );
		if ( empty( $ids ) ) {
			return '';
		}

		$items = '';
		foreach ( $ids as $id ) {
			$thumb = wp_get_attachment_image(
				$id,
				'thumbnail',
				false,
				array(
					'class'   => 'bdrvw-review-photos__img',
					'alt'     => '',
					'loading' => 'lazy',
				)
			);
			if ( '' === (string) $thumb ) {
				continue; // Attachment deleted from the Media Library since.
			}
			$full   = wp_get_attachment_image_url( $id, 'large' );
			$items .= $full
				? '<a class="bdrvw-review-photos__link" href="' . esc_url( $full ) . '" target="_blank" rel="noopener nofollow">' . $thumb . '</a>'
				: $thumb;
		}

		if ( '' === $items ) {
			return '';
		}

		return '<div class="bdrvw-review-photos">' . $items . '</div>';
	}

	/**
	 * Product image + title strip shown above the form.
	 *
	 * Products only. On an ordinary post the page title already sits a few
	 * inches above the form and there is rarely a thumbnail worth repeating,
	 * whereas a shopper reaching a product's Reviews tab has usually scrolled
	 * past the gallery — this confirms what they are about to review.
	 *
	 * @param int                 $post_id Post being reviewed.
	 * @param array<string,mixed> $s       Resolved settings.
	 */
	protected function render_form_product_header( int $post_id, array $s ): string {
		if ( empty( $s['form_product_header'] ) || 'product' !== get_post_type( $post_id ) ) {
			return '';
		}

		$thumb = (string) get_the_post_thumbnail(
			$post_id,
			'thumbnail',
			array(
				'class'   => 'bdrvw-form-product__image',
				'alt'     => '',
				'loading' => 'lazy',
			)
		);

		if ( '' === $thumb && function_exists( 'wc_placeholder_img' ) ) {
			$thumb = (string) wc_placeholder_img( 'thumbnail', array( 'class' => 'bdrvw-form-product__image' ) );
		}

		return '<div class="bdrvw-form-product">'
			. $thumb
			. '<span class="bdrvw-form-product__title">' . esc_html( get_the_title( $post_id ) ) . '</span>'
			. '</div>';
	}

	/**
	 * Render the review submission form.
	 *
	 * @param int  $post_id        Post being reviewed.
	 * @param bool $with_aggregate Whether to include the aggregate summary block
	 *                             at the top. Set false when the caller is also
	 *                             rendering the reviews list (which already shows
	 *                             the aggregate) — avoids a duplicate.
	 */
	public function render_form( int $post_id, bool $with_aggregate = true, array $overrides = array() ): string {
		$s        = $this->apply_style_overrides( $this->settings->all(), $overrides );
		$strings  = $s['strings'];
		$fields   = $s['fields'];
		$required = $s['required_fields'];

		$aggregate = \BoldReview\Plugin\Models\Bdrvw_Review::aggregate_for_post( $post_id );

		$user_reviews_on = ! array_key_exists( 'enable_user_review', $s ) || ! empty( $s['enable_user_review'] );
		if ( ! $user_reviews_on ) {
			if ( ! $with_aggregate ) {
				return '';
			}
			return '<div class="bdrvw-form-wrap" data-post-id="' . esc_attr( (string) $post_id ) . '">'
				. $this->render_aggregate( $aggregate, $post_id, $overrides )
				. '</div>';
		}

		ob_start();
		?>
		<div class="bdrvw-form-wrap" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php if ( $with_aggregate ) :
				echo $this->render_aggregate( $aggregate, $post_id, $overrides ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			endif; ?>

			<h3 class="bdrvw-form__heading"><?php echo esc_html( $strings['form_heading'] ); ?></h3>

			<form class="bdrvw-form" method="post">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
				<?php // Per-post token: proves the form was legitimately rendered here (e.g. via a `post_type` shortcode override), so submission is accepted even when the post type is not globally enabled. ?>
				<input type="hidden" name="post_token" value="<?php echo esc_attr( wp_create_nonce( 'bdrvw_review_post_' . $post_id ) ); ?>" />
				<?php wp_nonce_field( 'bdrvw_submit_review', 'bdrvw_form_nonce' ); ?>

				<?php echo $this->render_form_product_header( $post_id, $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

				<?php
				$style_enabled = ! array_key_exists( 'rating_input_style_enabled', $s ) || ! empty( $s['rating_input_style_enabled'] );
				$input_style   = ( $style_enabled && isset( $s['rating_input_style'] ) ) ? (string) $s['rating_input_style'] : 'stars';
				?>
				<?php if ( $style_enabled ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" id="bdrvw-rating-label-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['rating_label'] ); ?>
							<?php if ( ! empty( $required['rating'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<?php echo $this->rating_input_html( $input_style, 'rating', 'bdrvw-rating-label-' . (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					</div>
				<?php endif; ?>

				<?php
				$form_criteria = $this->form_criteria( $s );
				if ( ! empty( $form_criteria ) ) : ?>
					<div class="bdrvw-form__criteria bdrvw-criteria-input<?php echo $style_enabled ? '' : ' bdrvw-form__criteria--flush'; ?>">
						<?php foreach ( $form_criteria as $crit ) :
							$c_key   = (string) ( $crit['key'] ?? '' );
							$c_label = (string) ( $crit['label'] ?? '' );
							if ( '' === $c_key || '' === $c_label ) {
								continue;
							}
							$c_label_id = 'bdrvw-crit-label-' . (int) $post_id . '-' . sanitize_html_class( $c_key );
							?>
							<div class="bdrvw-form__row bdrvw-form__row--criterion">
								<label class="bdrvw-form__label" id="<?php echo esc_attr( $c_label_id ); ?>">
									<?php echo esc_html( $c_label ); ?><span class="bdrvw-req">*</span>
								</label>
								<?php echo $this->rating_input_html( $input_style, 'criteria[' . $c_key . ']', $c_label_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $fields['author_name'] ) ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" for="bdrvw-field-name-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['name_label'] ); ?>
							<?php if ( ! empty( $required['author_name'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<input id="bdrvw-field-name-<?php echo (int) $post_id; ?>" class="bdrvw-input" type="text" name="author_name" maxlength="150" />
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $fields['author_email'] ) ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" for="bdrvw-field-email-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['email_label'] ); ?>
							<?php if ( ! empty( $required['author_email'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<input id="bdrvw-field-email-<?php echo (int) $post_id; ?>" class="bdrvw-input" type="email" name="author_email" maxlength="150" />
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $fields['author_url'] ) ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" for="bdrvw-field-url-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['url_label'] ); ?>
							<?php if ( ! empty( $required['author_url'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<input id="bdrvw-field-url-<?php echo (int) $post_id; ?>" class="bdrvw-input" type="url" name="author_url" maxlength="255" />
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $fields['title'] ) ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" for="bdrvw-field-title-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['title_label'] ); ?>
							<?php if ( ! empty( $required['title'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<input id="bdrvw-field-title-<?php echo (int) $post_id; ?>" class="bdrvw-input" type="text" name="title" maxlength="255" />
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $fields['content'] ) ) : ?>
					<div class="bdrvw-form__row">
						<label class="bdrvw-form__label" for="bdrvw-field-content-<?php echo (int) $post_id; ?>">
							<?php echo esc_html( $strings['summary_label'] ); ?>
							<?php if ( ! empty( $required['content'] ) ) : ?><span class="bdrvw-req">*</span><?php endif; ?>
						</label>
						<textarea id="bdrvw-field-content-<?php echo (int) $post_id; ?>" class="bdrvw-input" name="content" rows="5"></textarea>
					</div>
				<?php endif; ?>

				<?php
				if ( \BoldReview\Plugin\Core\Bdrvw_Photos::enabled( $this->settings ) ) {
					echo $this->photo_uploader_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>

				<?php
				/**
				 * Fires just above the submit button, inside the form element.
				 *
				 * Anything a submission has to carry belongs here — a captcha add-on
				 * can put its widget in this spot, and because it is inside
				 * the form, whatever field it renders is collected with the rest.
				 *
				 * @param int                 $post_id Post being reviewed.
				 * @param array<string,mixed> $s       Resolved settings.
				 */
				do_action( 'bdrvw_form_before_actions', $post_id, $s );
				?>

				<div class="bdrvw-form__row bdrvw-form__actions">
					<button type="submit" class="bdrvw-btn bdrvw-btn--primary"><?php echo esc_html( $strings['submit_label'] ); ?></button>
				</div>

				<div class="bdrvw-form__messages" role="status" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render login-required notice.
	 */
	public function render_login_required(): string {
		$msg = $this->settings->get( 'strings.login_required', __( 'Please log in to submit a review.', 'boldreview' ) );

		/**
		 * Filter the "login required" notice shown in place of the review form.
		 *
		 * @param string $msg Notice text.
		 */
		$msg = (string) apply_filters( 'bdrvw_login_required_message', (string) $msg );

		return '<div class="bdrvw-notice bdrvw-notice--info">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * Render reviews list with aggregate header.
	 *
	 * @param array{items:array<int,array<string,mixed>>,total:int,pages:int} $result    Query result.
	 * @param array{average:float,count:int,breakdown:array<int,int>}         $aggregate Aggregate.
	 * @param int                                                             $post_id   Post id.
	 * @param string                                                          $layout    list|grid.
	 * @param string                                                          $template  classic|modern|minimal.
	 * @param int                                                             $paged     Current page.
	 */
	public function render_list( array $result, array $aggregate, int $post_id, string $layout, string $template, int $paged, array $overrides = array() ): string {
		$s       = $this->apply_style_overrides( $this->settings->all(), $overrides );
		$layout  = in_array( $layout, array( 'list', 'grid' ), true ) ? $layout : 'list';
		$tpl     = in_array( $template, array( 'classic', 'modern', 'minimal', 'comment' ), true ) ? $template : 'classic';
		$strings = $s['strings'];

		$user_reviews_on = ! array_key_exists( 'enable_user_review', $s ) || ! empty( $s['enable_user_review'] );
		$cf              = (array) ( $s['comment_fields'] ?? array() );
		$show_criteria   = ! array_key_exists( 'criteria', $cf ) || ! empty( $cf['criteria'] );
		$rating_input_on = ! array_key_exists( 'rating_input_style_enabled', $s ) || ! empty( $s['rating_input_style_enabled'] );

		$aggregate_html = $this->render_aggregate( $aggregate, $post_id, $overrides );

		if ( ! $rating_input_on ) {
			$aggregate = $this->criteria_derived_aggregate( $post_id, $aggregate, \BoldReview\Plugin\Models\Bdrvw_Review::criteria_averages_for_post( $post_id ) );
		}

		$has_items      = $user_reviews_on && ! empty( $result['items'] );
		if ( '' === trim( $aggregate_html ) && ! $has_items ) {
			return '';
		}

		$replies = $has_items
			? \BoldReview\Plugin\Models\Bdrvw_Review::replies_for( wp_list_pluck( $result['items'], 'id' ) )
			: array();

		ob_start();
		?>
		<div class="bdrvw-list-wrap bdrvw-list-wrap--<?php echo esc_attr( $tpl ); ?>" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">

			<?php echo wp_kses( $aggregate_html, bdrvw_allowed_html() ); ?>

			<?php if ( ! $user_reviews_on ) : ?>
			
			<?php elseif ( empty( $result['items'] ) ) : ?>
				
				<?php $empty_title = $post_id ? get_the_title( $post_id ) : ''; ?>
				<div class="bdrvw-reviews-empty">
					<p class="bdrvw-reviews-empty__title"><?php echo esc_html( $strings['no_reviews'] ); ?></p>
					<?php if ( '' !== $empty_title ) : ?>
						<p class="bdrvw-reviews-empty__cta">
							<?php
							printf(
								/* translators: %s: post/product title. */
								esc_html__( 'Be the first to review “%s”', 'boldreview' ),
								esc_html( $empty_title )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php elseif ( 'comment' === $tpl ) : ?>
				<?php echo $this->render_comment_list( $result['items'], $post_id, (int) $aggregate['count'], (float) $aggregate['average'], $overrides, $replies ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				<?php $this->render_pagination( (int) $result['pages'], $paged ); ?>
			<?php else : ?>
				<div class="bdrvw-list bdrvw-list--<?php echo esc_attr( $layout ); ?>">
					<?php foreach ( $result['items'] as $row ) : ?>
						<article class="bdrvw-item">
							<header class="bdrvw-item__header">
								<div class="bdrvw-item__author">
									<strong><?php echo esc_html( (string) $row['author_name'] ); ?></strong>
									<?php echo apply_filters( 'bdrvw_review_author_badge', '', $row, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the hook. ?>
									<?php if ( ! empty( $s['show_user_average'] ) && (int) $row['user_id'] > 0 ) :
										$user_avg = $this->user_average( (int) $row['user_id'] ); ?>
										<small class="bdrvw-user-avg"><?php echo esc_html( sprintf( /* translators: %s: avg rating. */ __( 'Avg %s', 'boldreview' ), number_format_i18n( $user_avg['average'], 1 ) ) ); ?></small>
									<?php endif; ?>
								</div>
								<?php if ( $rating_input_on && (float) $row['rating'] > 0 ) {
									echo wp_kses( $this->stars_html( (float) $row['rating'] ), bdrvw_allowed_html() );
								} ?>
							</header>
							<?php if ( ! empty( $row['title'] ) ) : ?>
								<h4 class="bdrvw-item__title"><?php echo esc_html( (string) $row['title'] ); ?></h4>
							<?php endif; ?>
							<div class="bdrvw-item__content"><?php echo wp_kses_post( (string) $row['content'] ); ?></div>
							<?php if ( $show_criteria ) {
								echo $this->per_review_criteria_html( $row, 'bdrvw-item__criteria' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} ?>
							<footer class="bdrvw-item__footer">
								<time datetime="<?php echo esc_attr( (string) $row['created_at'] ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></time>
							</footer>
							<?php echo $this->render_replies_html( $replies[ (int) $row['id'] ] ?? array(), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						</article>
					<?php endforeach; ?>
				</div>

				<?php $this->render_pagination( (int) $result['pages'], $paged ); ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the WooCommerce-style comment list template: heading "N reviews for X",
	 * avatar tile on the left, author meta + content in the middle, stars in the top-right.
	 *
	 * @param array<int,array<string,mixed>>             $rows    Review rows.
	 * @param int                                        $post_id Post id (for the heading).
	 * @param int                                        $total   Total review count (for the heading).
	 * @param array<int,array<int,array<string,mixed>>>  $replies Replies keyed by the review they answer.
	 */
	protected function render_comment_list( array $rows, int $post_id, int $total, float $average = 0.0, array $overrides = array(), array $replies = array() ): string {
		$post_title = $post_id ? get_the_title( $post_id ) : '';

		$s            = $this->apply_style_overrides( $this->settings->all(), $overrides );
		$cf           = (array) ( $s['comment_fields'] ?? array() );
		
		$rating_input_on = ! array_key_exists( 'rating_input_style_enabled', $s ) || ! empty( $s['rating_input_style_enabled'] );
		$show_avatar  = ! array_key_exists( 'avatar', $cf )  || ! empty( $cf['avatar'] );
		$show_stars   = ( ! array_key_exists( 'stars', $cf ) || ! empty( $cf['stars'] ) ) && $rating_input_on;
		$show_author  = ! array_key_exists( 'author', $cf )  || ! empty( $cf['author'] );
		$show_date    = ! array_key_exists( 'date', $cf )    || ! empty( $cf['date'] );
		$show_title   = ! array_key_exists( 'title', $cf )   || ! empty( $cf['title'] );
		$show_content = ! array_key_exists( 'content', $cf ) || ! empty( $cf['content'] );
		$show_url     = ! empty( $cf['url'] );
		$show_email   = ! empty( $cf['email'] );
		$show_criteria = ! array_key_exists( 'criteria', $cf ) || ! empty( $cf['criteria'] );

		ob_start();
		?>
		<?php if ( $total > 0 ) : ?>
			<h2 class="bdrvw-comment-heading">
				<?php
				printf(
					esc_html(
						/* translators: 1: review count, 2: average rating. */
						_n( '%1$s Review ( %2$s out of 5 )', '%1$s Reviews ( %2$s out of 5 )', (int) $total, 'boldreview' )
					),
					esc_html( number_format_i18n( $total ) ),
					esc_html( number_format_i18n( $average, 1 ) )
				);
				?>
			</h2>
		<?php endif; ?>
		<ol class="bdrvw-list bdrvw-list--comment commentlist">
			<?php foreach ( $rows as $row ) :
				$user_id  = (int) $row['user_id'];
				$avatar   = $user_id > 0
					? get_avatar( $user_id, 60, '', $row['author_name'], array( 'class' => 'bdrvw-comment__avatar avatar' ) )
					: get_avatar( (string) $row['author_email'], 60, '', $row['author_name'], array( 'class' => 'bdrvw-comment__avatar avatar' ) );
				$has_meta = $show_author || $show_date;
				?>
				<?php
				$author_url   = $show_url ? trim( (string) ( $row['author_url'] ?? '' ) ) : '';
				$has_url_line = '' !== $author_url;
				$author_email = $show_email ? trim( (string) ( $row['author_email'] ?? '' ) ) : '';
				$has_email    = '' !== $author_email;

					
					$row_crit_avg = 0.0;
					if ( ! $rating_input_on && ! empty( $row['criteria'] ) && is_array( $row['criteria'] ) ) {
						$c_sum = 0;
						$c_n   = 0;
						foreach ( $row['criteria'] as $cv ) {
							$v = (int) $cv;
							if ( $v > 0 ) {
								$c_sum += $v;
								$c_n++;
							}
						}
						$row_crit_avg = $c_n > 0 ? round( $c_sum / $c_n, 1 ) : 0.0;
					}
				?>
				<li class="bdrvw-comment review">
					<div class="bdrvw-comment__container comment_container">
						<?php if ( $show_avatar ) {
							echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core.
						} ?>
						<div class="bdrvw-comment__text comment-text">
							<?php if ( $has_meta || $has_email ) : ?>
								<p class="bdrvw-comment__meta meta">
									<?php if ( $show_author || $show_date ) : ?>
										<span class="bdrvw-comment__identity">
										<?php if ( $show_author ) : ?>
										<strong class="bdrvw-comment__author"><?php echo esc_html( (string) $row['author_name'] ); ?></strong>
										<?php
										/**
										 * Filter badge HTML shown next to a review author's name
										 * (e.g. the WooCommerce "Verified owner" label). Hook
										 * output must be safe HTML.
										 *
										 * @param string              $badge   Badge HTML (default '').
										 * @param array<string,mixed> $row     Review row.
										 * @param int                 $post_id Post/product id.
										 */
										echo apply_filters( 'bdrvw_review_author_badge', '', $row, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the hook.
										?>
										<?php endif; ?>
										<?php if ( $show_date && $show_author ) : ?>
											<span class="bdrvw-comment__dash">&ndash;</span>
										<?php endif; ?>
										<?php if ( $show_date ) : ?>
											<time class="bdrvw-comment__date" datetime="<?php echo esc_attr( (string) $row['created_at'] ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $row['created_at'] ) ); ?></time>
										<?php endif; ?>
										</span>
									<?php endif; ?>
									<?php if ( $has_email ) : ?>
										<?php // Second line: name, badge and date share the top row; the email sits under them. ?>
										<span class="bdrvw-comment__details">
											<span class="bdrvw-comment__email"><?php echo esc_html( $author_email ); ?></span>
										</span>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<?php if ( ! $rating_input_on && $row_crit_avg > 0 ) : ?>
								<?php // Stars only: the numeric score repeated what they already say. ?>
								<div class="bdrvw-comment__avg-rating" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: rating value. */ __( 'Rated %s out of 5', 'boldreview' ), number_format_i18n( $row_crit_avg, 1 ) ) ); ?>">
									<?php echo wp_kses( $this->stars_html( $row_crit_avg ), bdrvw_allowed_html() ); ?>
								</div>
							<?php endif; ?>
							<?php if ( $has_url_line ) : ?>
								<p class="bdrvw-comment__url-line">
									<a class="bdrvw-comment__url" href="<?php echo esc_url( $author_url ); ?>" target="_blank" rel="nofollow noopener ugc">
										<?php echo esc_html( preg_replace( '#^https?://#i', '', $author_url ) ); ?>
									</a>
								</p>
							<?php endif; ?>
							<?php // Last thing before the review text, so the title always heads it. ?>
							<?php if ( $show_title && ! empty( $row['title'] ) ) : ?>
								<p class="bdrvw-comment__title"><strong><?php echo esc_html( (string) $row['title'] ); ?></strong></p>
							<?php endif; ?>
							<?php if ( $show_content ) : ?>
								<div class="bdrvw-comment__content description"><?php echo wp_kses_post( wpautop( (string) $row['content'] ) ); ?></div>
							<?php endif; ?>
							<?php if ( $show_criteria ) {
								echo $this->per_review_criteria_html( $row, 'bdrvw-comment__criteria' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} ?>
							<?php
							
							$row_has_criteria = $show_criteria && ! empty( $row['criteria'] ) && is_array( $row['criteria'] )
								&& count( array_filter( array_map( 'intval', $row['criteria'] ) ) ) > 0;
							?>
							<?php if ( $show_stars && ! $row_has_criteria && (float) $row['rating'] > 0 ) : ?>
								<div class="bdrvw-comment__stars" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: rating value. */ __( 'Rated %s out of 5', 'boldreview' ), number_format_i18n( (float) $row['rating'], 1 ) ) ); ?>">
									<?php echo wp_kses( $this->stars_html( (float) $row['rating'] ), bdrvw_allowed_html() ); ?>
								</div>
							<?php endif; ?>
							<?php
							echo $this->review_photos_html( (int) $row['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
							?>
							<?php
							echo $this->render_replies_html( $replies[ (int) $row['id'] ] ?? array(), $show_avatar ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
							?>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Answers written under a review, nested beneath it.
	 *
	 * Shared by every template: a reply carries no rating, title or criteria, so
	 * there is nothing template-specific left to vary — just who wrote it, when,
	 * and what they said.
	 *
	 * @param array<int,array<string,mixed>> $replies     Reply rows for one review.
	 * @param bool                           $show_avatar Whether the template shows avatars.
	 */
	protected function render_replies_html( array $replies, bool $show_avatar = true ): string {
		if ( empty( $replies ) ) {
			return '';
		}

		/**
		 * Filter the line that introduces a reply.
		 *
		 * Every reply is written from the admin, so on a shop this is always the
		 * owner answering a customer. A blog or a directory would word it
		 * differently, hence the filter.
		 *
		 * @param string $label Introductory line.
		 */
		$label = (string) apply_filters( 'bdrvw_reply_label', __( 'Shop owner replied', 'boldreview' ) );

		ob_start();
		?>
		<?php // No `children` class: themes style that inside a comment list and their margins would fight ours. ?>
		<ul class="bdrvw-replies">
			<?php foreach ( $replies as $reply ) :
				$name    = (string) ( $reply['author_name'] ?? '' );
				$name    = '' !== $name ? $name : __( 'Anonymous', 'boldreview' );
				$user_id = (int) ( $reply['user_id'] ?? 0 );
				$avatar  = $user_id > 0
					? get_avatar( $user_id, 44, '', $name, array( 'class' => 'bdrvw-reply__avatar avatar' ) )
					: get_avatar( (string) ( $reply['author_email'] ?? '' ), 44, '', $name, array( 'class' => 'bdrvw-reply__avatar avatar' ) );
				?>
				<li class="bdrvw-reply">
					<div class="bdrvw-reply__container">
						<?php if ( $show_avatar ) {
							echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core.
						} ?>
						<div class="bdrvw-reply__text">
							<?php if ( '' !== $label ) : ?>
								<p class="bdrvw-reply__label">
									<span class="bdrvw-reply__label-icon" aria-hidden="true" focusable="false">
										<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
											<polyline points="9 10 4 15 9 20"></polyline>
											<path d="M20 4v7a4 4 0 0 1-4 4H4"></path>
										</svg>
									</span>
									<?php echo esc_html( $label ); ?>
								</p>
							<?php endif; ?>
							<p class="bdrvw-reply__meta">
								<strong class="bdrvw-reply__author"><?php echo esc_html( $name ); ?></strong>
								<span class="bdrvw-reply__dash">&ndash;</span>
								<time class="bdrvw-reply__date" datetime="<?php echo esc_attr( (string) ( $reply['created_at'] ?? '' ) ); ?>">
									<?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) ( $reply['created_at'] ?? '' ) ) ); ?>
								</time>
							</p>
							<div class="bdrvw-reply__content"><?php echo wp_kses_post( wpautop( (string) ( $reply['content'] ?? '' ) ) ); ?></div>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the aggregate header (average + summary bars + author average).
	 * Returns empty string when nothing is enabled or no reviews exist.
	 *
	 * @param array{average:float,count:int,breakdown:array<int,int>} $aggregate Aggregate.
	 * @param int                                                     $post_id   Post id.
	 */
	protected function render_aggregate( array $aggregate, int $post_id, array $overrides = array() ): string {
		$s                 = $this->apply_style_overrides( $this->settings->all(), $overrides );
		$author_review_on  = ! empty( $s['enable_author_review'] );
		$user_review_on    = ! array_key_exists( 'enable_user_review', $s ) || ! empty( $s['enable_user_review'] );
		$summary_global_on = ! array_key_exists( 'show_rating_summary', $s ) || ! empty( $s['show_rating_summary'] );
		$rating_input_on   = ! array_key_exists( 'rating_input_style_enabled', $s ) || ! empty( $s['rating_input_style_enabled'] );

		if ( ! $summary_global_on && ! $author_review_on ) {
			return '';
		}

		$allowed_styles  = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );
		$global_style    = ( isset( $s['summary_style'] ) && in_array( $s['summary_style'], $allowed_styles, true ) ) ? (string) $s['summary_style'] : 'bars';
		$global_show_avg = ! empty( $s['show_average'] );

		$group = ( $author_review_on && $post_id ) ? $this->resolve_group_for_post( $post_id ) : null;

		$user_html   = '';
		$author_html = '';

		// ---- User review summary: visitor aggregate + form criteria. ----
		if ( $summary_global_on && $user_review_on ) {
			$user_criteria = ( ! empty( $s['criteria'] ) && is_array( $s['criteria'] ) ) ? $this->backfill_criteria_keys( $s['criteria'] ) : array();
			$user_avgs     = ! empty( $user_criteria ) ? \BoldReview\Plugin\Models\Bdrvw_Review::criteria_averages_for_post( $post_id ) : array();
			$user_agg      = $rating_input_on ? $aggregate : $this->criteria_derived_aggregate( $post_id, $aggregate, $user_avgs );
			$user_html     = $this->compose_summary_block(
				$user_agg,
				$post_id,
				$user_criteria,
				$user_avgs,
				$global_style,
				$global_show_avg,
				null,
				''
			);
		}

		// ---- Author review summary: the author's own criteria assessment. ----
		if ( $author_review_on && is_array( $group ) ) {
			$author_style    = ( isset( $group['summary_style'] ) && in_array( (string) $group['summary_style'], $allowed_styles, true ) ) ? (string) $group['summary_style'] : $global_style;
			$author_show_avg = array_key_exists( 'show_average', $group ) ? ! empty( $group['show_average'] ) : $global_show_avg;
			$author_criteria = $this->backfill_criteria_keys( (array) ( $group['criteria'] ?? array() ) );

			$author_avgs = ! empty( $author_criteria ) ? \BoldReview\Plugin\Models\Bdrvw_Review::criteria_averages_for_post( $post_id ) : array();
			$admin_total = 0;
			$admin_count = 0;
			foreach ( $author_criteria as $crit ) {
				$ck = (string) ( $crit['key'] ?? '' );
				$rt = isset( $crit['rating'] ) ? (int) $crit['rating'] : 0;
				if ( '' === $ck || $rt <= 0 ) {
					continue;
				}
				$existing            = isset( $author_avgs[ $ck ]['count'] ) ? (int) $author_avgs[ $ck ]['count'] : 0;
				$author_avgs[ $ck ]  = array( 'average' => (float) $rt, 'count' => max( $existing, 1 ) );
				$admin_total        += $rt;
				$admin_count++;
			}

			$author_agg = $aggregate;
			if ( $admin_count > 0 ) {
				$author_agg['average'] = round( $admin_total / $admin_count, 2 );
				if ( (int) $author_agg['count'] <= 0 ) {
					$author_agg['count'] = 1;
				}
			} elseif ( ! $rating_input_on ) {
				$author_agg = $this->criteria_derived_aggregate( $post_id, $aggregate, $author_avgs );
			}

			$author_html = $this->compose_summary_block(
				$author_agg,
				$post_id,
				$author_criteria,
				$author_avgs,
				$author_style,
				$author_show_avg,
				$group,
				''
			);
		}

		// Author review first, user review below.
		return $author_html . $user_html;
	}

	/**
	 * Derive an overall aggregate from per-criterion averages. Used when the
	 * overall rating input is disabled: the "average" becomes the mean of every
	 * criterion score and the "count" the number of approved reviews.
	 *
	 * @param int                                          $post_id       Post id.
	 * @param array<string,mixed>                          $aggregate     Base aggregate to clone.
	 * @param array<string,array{average:float,count:int}> $criteria_avgs Per-criterion averages.
	 * @return array<string,mixed>
	 */
	protected function criteria_derived_aggregate( int $post_id, array $aggregate, array $criteria_avgs ): array {
		$total = 0.0;
		$cnt   = 0;
		foreach ( $criteria_avgs as $a ) {
			$total += (float) ( $a['average'] ?? 0 ) * (int) ( $a['count'] ?? 0 );
			$cnt   += (int) ( $a['count'] ?? 0 );
		}
		$aggregate['average'] = $cnt > 0 ? round( $total / $cnt, 2 ) : 0.0;
		$aggregate['count']   = \BoldReview\Plugin\Models\Bdrvw_Review::approved_count_for_post( $post_id );
		return $aggregate;
	}

	/**
	 * Compose a single rating-summary block (criteria box + chosen summary style
	 * + optional overview meta). Returns '' when there is nothing to show.
	 *
	 * @param array{average:float,count:int,breakdown?:array<int,int>} $aggregate     Overall average/count for this block.
	 * @param int                                                      $post_id       Post id.
	 * @param array<int,array<string,mixed>>                           $criteria      Resolved criteria list (key,label[,rating]).
	 * @param array<string,array{average:float,count:int}>             $criteria_avgs Per-criterion averages keyed by key.
	 * @param string                                                   $summary_style Chosen style key.
	 * @param bool                                                     $show_average  Whether to show the overall average.
	 * @param array<string,mixed>|null                                 $group         Criteria group (for overview meta) or null.
	 * @param string                                                   $heading       Optional block heading (user vs author).
	 */
	protected function compose_summary_block( array $aggregate, int $post_id, array $criteria, array $criteria_avgs, string $summary_style, bool $show_average, ?array $group, string $heading = '' ): string {
		$allowed_styles = array( 'bars', 'point', 'pie', 'hbars', 'stripes', 'gauge', 'tiles', 'overview' );
		if ( ! in_array( $summary_style, $allowed_styles, true ) ) {
			$summary_style = 'bars';
		}
		$show_count        = true;
		$show_summary      = true;
		$is_criteria_style = in_array( $summary_style, array( 'hbars', 'stripes', 'gauge', 'tiles', 'overview' ), true );

		$has_criteria_box        = false;
		$has_configured_criteria = false;
		foreach ( $criteria as $crit ) {
			if ( empty( $crit['key'] ) || empty( $crit['label'] ) ) {
				continue;
			}
			$has_configured_criteria = true;
			if ( isset( $criteria_avgs[ (string) $crit['key'] ] ) ) {
				$has_criteria_box = true;
				break;
			}
		}

		if ( $is_criteria_style && ! $has_criteria_box && ! $has_configured_criteria ) {
			$summary_style     = 'bars';
			$is_criteria_style = false;
		}

		if ( ! $show_average && ! $has_criteria_box && ! $has_configured_criteria ) {
			return '';
		}
		unset( $has_configured_criteria );

		/**
		 * Filter the heading shown above the criteria/summary block.
		 *
		 * @param string $heading Heading text.
		 * @param int    $post_id Post being rendered.
		 */
		$criteria_heading = (string) apply_filters( 'bdrvw_summary_heading', __( 'Summary', 'boldreview' ), $post_id );

		ob_start();
		if ( '' !== $heading ) {
			echo '<h3 class="bdrvw-aggregate__block-heading">' . esc_html( $heading ) . '</h3>';
		}
		if ( $is_criteria_style ) {
			switch ( $summary_style ) {
				case 'hbars':
					$this->render_summary_hbars( $aggregate, $criteria, $criteria_avgs, $show_average, $show_count );
					break;
				case 'stripes':
					$this->render_summary_stripes( $aggregate, $criteria, $criteria_avgs, $show_average, $show_count );
					break;
				case 'gauge':
					$this->render_summary_gauge( $aggregate, $criteria, $criteria_avgs, $show_average, $show_count );
					break;
				case 'tiles':
					$this->render_summary_tiles( $aggregate, $criteria, $criteria_avgs, $show_average, $show_count );
					break;
				case 'overview':
					$this->render_summary_overview( $aggregate, $criteria, $criteria_avgs, $show_average, $show_count, $group );
					break;
			}
		} else {
			ob_start();
			if ( $has_criteria_box ) :
				?>
				<div class="bdrvw-aggregate__criteria">
					<h4 class="bdrvw-aggregate__criteria-title"><?php echo esc_html( $criteria_heading ); ?></h4>
					<?php foreach ( $criteria as $crit ) :
						if ( empty( $crit['key'] ) || empty( $crit['label'] ) ) {
							continue;
						}
						$ck = (string) $crit['key'];
						if ( ! isset( $criteria_avgs[ $ck ] ) ) {
							continue;
						}
						$avg = (float) $criteria_avgs[ $ck ]['average'];
						?>
						<div class="bdrvw-criterion-bar">
							<span class="bdrvw-criterion-bar__label"><?php echo esc_html( (string) $crit['label'] ); ?></span>
							<span class="bdrvw-criterion-bar__rating">
								<?php echo wp_kses( $this->stars_html( $avg ), bdrvw_allowed_html() ); ?>
								<span class="bdrvw-criterion-bar__score"><?php echo esc_html( number_format_i18n( round( $avg, 1 ), 1 ) ); ?> / 5</span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php
			endif;

			ob_start();
			switch ( $summary_style ) {
				case 'point':
					$this->render_summary_point( $aggregate, $show_average, $show_count );
					break;
				case 'pie':
					$this->render_summary_pie( $aggregate, $show_average, $show_count, $show_summary );
					break;
				case 'bars':
				default:
					$this->render_summary_bars( $aggregate, $show_average, $show_count, $show_summary );
					break;
			}
			$summary_inner = (string) ob_get_clean();
			if ( '' !== trim( $summary_inner ) ) {
				echo '<div class="bdrvw-aggregate__summary">' . $summary_inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside.
			}

			$body_inner = (string) ob_get_clean();
			if ( '' !== trim( $body_inner ) ) {
				echo '<div class="bdrvw-aggregate__body">' . $body_inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside.
			}
		}

		$ovr_data    = is_array( $group ) ? (array) ( $group['overview'] ?? array() ) : array();
		$ovr_enabled = ! empty( $ovr_data['enabled'] );
		$ovr_heading = (string) ( $ovr_data['heading'] ?? '' );
		$ovr_desc    = (string) ( $ovr_data['description'] ?? '' );
		$skip_ovr    = ( 'overview' === $summary_style );
		if ( ! $skip_ovr && $ovr_enabled && ( '' !== $ovr_desc || '' !== $ovr_heading ) ) :
			?>
			<div class="bdrvw-aggregate__overview">
				<?php if ( '' !== $ovr_heading ) : ?>
					<h4 class="bdrvw-aggregate__overview-title"><?php echo esc_html( $ovr_heading ); ?></h4>
				<?php endif; ?>
				<?php if ( '' !== $ovr_desc ) : ?>
					<p class="bdrvw-aggregate__overview-desc"><?php echo esc_html( $ovr_desc ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		endif;

		$inner = (string) ob_get_clean();
		if ( '' === trim( $inner ) ) {
			return '';
		}

		$wrapper_class = 'bdrvw-aggregate bdrvw-aggregate--' . $summary_style;
		if ( $is_criteria_style ) {
			$wrapper_class .= ' bdrvw-aggregate--criteria-style';
		}
		return '<div class="' . esc_attr( $wrapper_class ) . '">' . $inner . '</div>';
	}

	/**
	 * Bars summary — overall average + reviewer count.
	 *
	 * The per-star (1–5) breakdown has been retired: per-criterion ratings are
	 * shown in the `bdrvw-aggregate__criteria` block above the summary,
	 * which made the duplicate distribution bars redundant.
	 *
	 * @param array{average:float,count:int,breakdown:array<int,int>} $aggregate Aggregate.
	 */
	protected function render_summary_bars( array $aggregate, bool $show_average, bool $show_count, bool $show_summary ): void {
		if ( ! $show_average ) {
			return;
		}
		$count     = (int) $aggregate['count'];
		$breakdown = isset( $aggregate['breakdown'] ) && is_array( $aggregate['breakdown'] ) ? $aggregate['breakdown'] : array();
		?>
		<div class="bdrvw-aggregate__dist">
			<div class="bdrvw-aggregate__score">
				<span class="bdrvw-aggregate__score-line">
					<span class="bdrvw-aggregate__number"><?php echo esc_html( number_format_i18n( $aggregate['average'], 1 ) ); ?></span>
					<span class="bdrvw-aggregate__max">/ 5</span>
				</span>
				<span class="bdrvw-aggregate__total">
					<?php
					printf(
						esc_html(
							/* translators: %s: number of reviews. */
							_n( 'Total %s review', 'Total %s reviews', $count, 'boldreview' )
						),
						esc_html( number_format_i18n( $count ) )
					);
					?>
				</span>
			</div>
			<div class="bdrvw-aggregate__bars">
				<?php for ( $star = 5; $star >= 1; $star-- ) :
					$c   = isset( $breakdown[ $star ] ) ? (int) $breakdown[ $star ] : 0;
					$pct = $count > 0 ? (int) round( ( $c / $count ) * 100 ) : 0;
					?>
					<div class="bdrvw-bar">
						<span class="bdrvw-bar__label"><?php echo (int) $star; ?> <span class="bdrvw-bar__star" aria-hidden="true">&#9733;</span></span>
						<span class="bdrvw-bar__track"><span class="bdrvw-bar__fill" style="width:<?php echo (int) $pct; ?>%"></span></span>
						<span class="bdrvw-bar__count"><?php echo esc_html( number_format_i18n( $c ) ); ?> (<?php echo (int) $pct; ?>%)</span>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
		unset( $show_count, $show_summary );
	}

	/**
	 * Point summary — big score card with stars + "Based on N reviews".
	 */
	protected function render_summary_point( array $aggregate, bool $show_average, bool $show_count ): void {
		if ( ! $show_average ) {
			return;
		}
		$verdict = $this->rating_verdict( (float) $aggregate['average'] );
		?>
		<div class="bdrvw-point">
			<div class="bdrvw-point__card">
				<span class="bdrvw-point__score"><?php echo esc_html( number_format_i18n( $aggregate['average'], 1 ) ); ?></span>
				<span class="bdrvw-point__max">/ 5</span>
			</div>
			<?php if ( $verdict ) : ?>
				<div class="bdrvw-point__meta">
					<strong class="bdrvw-point__verdict"><?php echo esc_html( strtoupper( $verdict ) ); ?>!</strong>
				</div>
			<?php endif; ?>
		</div>
		<?php
		unset( $show_count ); 
	}

	/**
	 * Pie summary — donut showing the overall average as a progress arc.
	 *
	 * The per-star (1–5) legend has been retired: per-criterion ratings are
	 * shown in the `bdrvw-aggregate__criteria` block above the summary,
	 * which made the duplicate star-count list redundant.
	 */
	protected function render_summary_pie( array $aggregate, bool $show_average, bool $show_count, bool $show_summary ): void {
		if ( ! $show_average ) {
			return;
		}
		$avg = max( 0.0, min( 5.0, (float) $aggregate['average'] ) );

		$percent  = ( $avg / 5 ) * 100;
		$color    = $this->score_color( $avg );
		$pct_str  = number_format( $percent, 2, '.', '' );
		$gradient = sprintf( '%s 0%% %s%%, rgb(217 217 217) %s%% 100%%', $color, $pct_str, $pct_str );
		$verdict  = $this->rating_verdict( $avg );
		?>
		<div class="bdrvw-pie">
			<div class="bdrvw-pie__chart" style="background: conic-gradient(<?php echo esc_attr( $gradient ); ?>);" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: average rating. */ __( '%s out of 5', 'boldreview' ), number_format_i18n( $avg, 1 ) ) ); ?>">
				<div class="bdrvw-pie__inner">
					<span class="bdrvw-pie__avg"><?php echo esc_html( number_format_i18n( $avg, 1 ) ); ?></span>
					<span class="bdrvw-pie__max">/ 5</span>
				</div>
			</div>
			<?php if ( $verdict ) : ?>
				<strong class="bdrvw-pie__verdict"><?php echo esc_html( strtoupper( $verdict ) ); ?>!</strong>
			<?php endif; ?>
		</div>
		<?php
		unset( $show_count, $show_summary ); // intentionally unused — count + legend removed.
	}

	/**
	 * Shared header used by the four criteria-focused summary styles
	 * (hbars / stripes / gauge / tiles). Renders the overall average + total
	 * count once, so each criterion below can focus purely on its own score.
	 *
	 * @param array{average:float,count:int,breakdown:array<int,int>} $aggregate Aggregate.
	 */
	protected function render_criteria_summary_header( array $aggregate, bool $show_average, bool $show_count ): void {
		if ( ! $show_average ) {
			return;
		}
		$verdict = $this->rating_verdict( (float) $aggregate['average'] );
		?>
		<div class="bdrvw-csum__header">
			<span class="bdrvw-csum__avg">
				<span class="bdrvw-csum__avg-num"><?php echo esc_html( number_format_i18n( $aggregate['average'], 1 ) ); ?></span>
				<span class="bdrvw-csum__avg-max">/ 5</span>
			</span>
			<?php if ( $verdict ) : ?>
				<strong class="bdrvw-csum__verdict"><?php echo esc_html( strtoupper( $verdict ) ); ?>!</strong>
			<?php endif; ?>
		</div>
		<?php
		unset( $show_count ); 
	}

	/**
	 * Filter the resolved criteria down to those that actually have a saved
	 * average. Anything else is hidden by the criteria-focused styles.
	 *
	 * @param array<int,array{key:string,label:string}>       $criteria Resolved criteria.
	 * @param array<string,array{average:float,count:int}>    $avgs     Averages keyed by criterion key.
	 * @return array<int,array{key:string,label:string,avg:float,score:float,percent:int}>
	 */
	protected function prepare_criteria_for_summary( array $criteria, array $avgs ): array {
		$out     = array();
		$has_any = ! empty( $avgs );
		foreach ( $criteria as $crit ) {
			$key   = (string) ( $crit['key'] ?? '' );
			$label = (string) ( $crit['label'] ?? '' );
			if ( '' === $key || '' === $label ) {
				continue;
			}
			
			if ( $has_any && ! isset( $avgs[ $key ] ) ) {
				continue;
			}
			$avg     = isset( $avgs[ $key ] ) ? (float) $avgs[ $key ]['average'] : 0.0;
			$percent = (int) max( 0, min( 100, round( ( $avg / 5 ) * 100 ) ) );
			$out[]   = array(
				'key'     => $key,
				'label'   => $label,
				'avg'     => $avg,
				'score'   => round( $avg, 1 ),
				'percent' => $percent,
			);
		}
		return $out;
	}

	/**
	 * Criteria summary — horizontal fill bars per criterion with x/5 score.
	 */
	protected function render_summary_hbars( array $aggregate, array $criteria, array $avgs, bool $show_average, bool $show_count ): void {
		$rows = $this->prepare_criteria_for_summary( $criteria, $avgs );
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="bdrvw-csum bdrvw-csum--hbars">
			<?php $this->render_criteria_summary_header( $aggregate, $show_average, $show_count ); ?>
			<div class="bdrvw-csum__body">
				<?php foreach ( $rows as $r ) : ?>
					<div class="bdrvw-csum-row">
						<span class="bdrvw-csum-row__label"><?php echo esc_html( $r['label'] ); ?></span>
						<span class="bdrvw-csum-row__track">
							<span class="bdrvw-csum-row__fill" style="width:<?php echo (int) $r['percent']; ?>%"></span>
						</span>
						<span class="bdrvw-csum-row__score">
							<?php echo esc_html( number_format_i18n( $r['score'], 1 ) ); ?><span class="bdrvw-csum-row__score-max">/5</span>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Criteria summary — 5-segment stripe meter per criterion.
	 * Each criterion shows five vertical cells; filled cells reflect the score.
	 */
	protected function render_summary_stripes( array $aggregate, array $criteria, array $avgs, bool $show_average, bool $show_count ): void {
		$rows = $this->prepare_criteria_for_summary( $criteria, $avgs );
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="bdrvw-csum bdrvw-csum--stripes">
			<?php $this->render_criteria_summary_header( $aggregate, $show_average, $show_count ); ?>
			<div class="bdrvw-csum__body">
				<?php foreach ( $rows as $r ) :
					$filled = max( 0, min( 5, (int) round( $r['avg'] ) ) );
					?>
					<div class="bdrvw-csum-stripe">
						<span class="bdrvw-csum-stripe__label"><?php echo esc_html( $r['label'] ); ?></span>
						<span class="bdrvw-csum-stripe__cells" aria-hidden="true">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<span class="bdrvw-csum-stripe__cell<?php echo $i <= $filled ? ' is-on' : ''; ?>"></span>
							<?php endfor; ?>
						</span>
						<span class="bdrvw-csum-stripe__score"><?php echo esc_html( number_format_i18n( $r['score'], 1 ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Criteria summary — half-circle gauges per criterion.
	 * Uses CSS conic-gradient to draw a 180° arc filled to `percent`.
	 */
	protected function render_summary_gauge( array $aggregate, array $criteria, array $avgs, bool $show_average, bool $show_count ): void {
		$rows = $this->prepare_criteria_for_summary( $criteria, $avgs );
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="bdrvw-csum bdrvw-csum--gauge">
			<?php $this->render_criteria_summary_header( $aggregate, $show_average, $show_count ); ?>
			<div class="bdrvw-csum__body">
				<?php foreach ( $rows as $r ) :
					
					$deg     = (int) round( ( $r['percent'] / 100 ) * 180 );
					$color   = $this->score_color( $r['avg'] );
					?>
					<div class="bdrvw-csum-gauge">
						<div class="bdrvw-csum-gauge__ring" style="--br-gauge-deg:<?php echo (int) $deg; ?>deg; --br-gauge-color:<?php echo esc_attr( $color ); ?>;">
							<div class="bdrvw-csum-gauge__inner">
								<span class="bdrvw-csum-gauge__score"><?php echo esc_html( number_format_i18n( $r['score'], 1 ) ); ?></span>
								<span class="bdrvw-csum-gauge__max">/ 5</span>
							</div>
						</div>
						<span class="bdrvw-csum-gauge__label"><?php echo esc_html( $r['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Criteria summary — score tiles per criterion (KPI-card style).
	 */
	protected function render_summary_tiles( array $aggregate, array $criteria, array $avgs, bool $show_average, bool $show_count ): void {
		$rows = $this->prepare_criteria_for_summary( $criteria, $avgs );
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="bdrvw-csum bdrvw-csum--tiles">
			<?php $this->render_criteria_summary_header( $aggregate, $show_average, $show_count ); ?>
			<div class="bdrvw-csum__body">
				<?php foreach ( $rows as $r ) :
					$color = $this->score_color( $r['avg'] );
					?>
					<div class="bdrvw-csum-tile" style="--br-tile-color:<?php echo esc_attr( $color ); ?>;">
						<span class="bdrvw-csum-tile__score"><?php echo esc_html( number_format_i18n( $r['score'], 1 ) ); ?></span>
						<span class="bdrvw-csum-tile__label"><?php echo esc_html( $r['label'] ); ?></span>
						<span class="bdrvw-csum-tile__bar"><span class="bdrvw-csum-tile__bar-fill" style="width:<?php echo (int) $r['percent']; ?>%"></span></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Criteria summary — "Overview" panel: criteria list on the left with label,
	 * "x / 5" score and filled stars; a total-score card with a verdict word on
	 * the right; optional Summary heading + description block beneath.
	 *
	 * @param array<int,array{key:string,label:string}>    $criteria Resolved criteria list.
	 * @param array<string,array{average:float,count:int}> $avgs     Per-criterion averages.
	 * @param array<string,mixed>|null                     $group    Resolved criteria group (for overview meta).
	 */
	protected function render_summary_overview( array $aggregate, array $criteria, array $avgs, bool $show_average, bool $show_count, ?array $group ): void {
		$rows = $this->prepare_criteria_for_summary( $criteria, $avgs );
		if ( empty( $rows ) ) {
			return;
		}

		$avg     = (float) $aggregate['average'];
		$count   = (int) $aggregate['count'];
		$verdict = $this->rating_verdict( $avg );

		$ovr_data    = is_array( $group ) ? (array) ( $group['overview'] ?? array() ) : array();
		$ovr_on      = ! empty( $ovr_data['enabled'] );
		$ovr_heading = (string) ( $ovr_data['heading'] ?? '' );
		$ovr_desc    = (string) ( $ovr_data['description'] ?? '' );
		?>
		<div class="bdrvw-overview">
			<h3 class="bdrvw-overview__heading"><?php echo esc_html( (string) apply_filters( 'bdrvw_summary_heading', __( 'Summary', 'boldreview' ), 0 ) ); ?></h3>

			<div class="bdrvw-overview__body<?php echo $show_average ? '' : ' bdrvw-overview__body--no-total'; ?>">
				<ul class="bdrvw-overview__list">
					<?php foreach ( $rows as $r ) :
						$filled = max( 0, min( 5, (int) round( $r['avg'] ) ) );
						?>
						<li class="bdrvw-overview__row">
							<span class="bdrvw-overview__label"><?php echo esc_html( $r['label'] ); ?></span>
							<span class="bdrvw-overview__score">
								<?php echo esc_html( number_format_i18n( $r['score'], 1 ) ); ?> / 5
							</span>
							<span class="bdrvw-overview__stars" aria-hidden="true">
								<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
									<span class="bdrvw-overview__star<?php echo $i <= $filled ? ' is-on' : ''; ?>">&#9733;</span>
								<?php endfor; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $show_average ) : ?>
					<div class="bdrvw-overview__total">
						<span class="bdrvw-overview__total-score"><?php echo esc_html( number_format_i18n( $avg, 1 ) ); ?></span>
						<span class="bdrvw-overview__total-max">/ 5</span>
						<?php if ( $verdict ) : ?>
							<strong class="bdrvw-overview__verdict"><?php echo esc_html( strtoupper( $verdict ) ); ?>!</strong>
						<?php endif; ?>
						<?php if ( $count > 0 ) : ?>
							<span class="bdrvw-overview__count">
								<?php
								printf(
									esc_html(
										/* translators: %s: number of reviews. */
										_n( '%s review', '%s reviews', $count, 'boldreview' )
									),
									esc_html( number_format_i18n( $count ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $ovr_on && ( '' !== $ovr_heading || '' !== $ovr_desc ) ) : ?>
				<div class="bdrvw-overview__summary">
					<?php if ( '' !== $ovr_heading ) : ?>
						<h4 class="bdrvw-overview__summary-heading"><?php echo esc_html( $ovr_heading ); ?></h4>
					<?php endif; ?>
					<?php if ( '' !== $ovr_desc ) : ?>
						<p class="bdrvw-overview__summary-text"><?php echo esc_html( $ovr_desc ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Map a 0–5 score to an indicator colour used by the new summary styles.
	 */
	protected function score_color( float $avg ): string {
		if ( $avg >= 4.5 ) { return '#16a34a'; }
		if ( $avg >= 4.0 ) { return '#22c55e'; }
		if ( $avg >= 3.0 ) { return '#f59e0b'; }
		if ( $avg >= 2.0 ) { return '#f97316'; }
		return '#ef4444';
	}

	/**
	 * Friendly verdict word for the Point card (5 → Excellent, 4 → Great, …).
	 */
	protected function rating_verdict( float $avg ): string {
		if ( $avg >= 4.5 ) {
			$verdict = (string) __( 'Excellent', 'boldreview' );
		} elseif ( $avg >= 4.0 ) {
			$verdict = (string) __( 'Great', 'boldreview' );
		} elseif ( $avg >= 3.0 ) {
			$verdict = (string) __( 'Good', 'boldreview' );
		} elseif ( $avg >= 2.0 ) {
			$verdict = (string) __( 'Fair', 'boldreview' );
		} elseif ( $avg > 0 ) {
			$verdict = (string) __( 'Poor', 'boldreview' );
		} else {
			$verdict = '';
		}

		/**
		 * Filter the verdict word shown beside an average rating
		 * (e.g. "Excellent", "Good"). Return an empty string to hide it.
		 *
		 * @param string $verdict Verdict word ('' when there's no rating).
		 * @param float  $avg     The 0–5 average being described.
		 */
		return (string) apply_filters( 'bdrvw_rating_verdict', $verdict, $avg );
	}

	/**
	 * Resolve which criteria list applies to a given post.
	 *
	 * Lookup order — most-specific to least:
	 *   1. A "specific" group whose post_ids includes this post id.
	 *   2. A "post_types" group whose post_types includes this post type.
	 *   3. An "all" catchall group.
	 *   4. Legacy flat $settings['criteria'] (back-compat).
	 *   5. Empty array.
	 *
	 * @param int $post_id Post id we're rendering for.
	 * @return array<int,array{key:string,label:string}>
	 */
	public function resolve_criteria_for_post( int $post_id ): array {
		$group = $this->resolve_group_for_post( $post_id );
		if ( null !== $group ) {
			return $this->backfill_criteria_keys( (array) ( $group['criteria'] ?? array() ) );
		}

		
		$s = $this->settings->all();
		if ( ! empty( $s['criteria'] ) && is_array( $s['criteria'] ) ) {
			return $this->backfill_criteria_keys( $s['criteria'] );
		}
		return array();
	}

	/**
	 * Ensure every criterion has a non-empty key. Older saves (or sanitizer bugs)
	 * could leave the key blank, which breaks the criteria_avgs lookup and forces
	 * the renderer to fall back to a non-criteria summary style. Derive a stable
	 * key from the label so existing data renders correctly without a re-save.
	 *
	 * @param array<int,array<string,mixed>> $criteria Raw criteria list.
	 * @return array<int,array<string,mixed>>
	 */
	protected function backfill_criteria_keys( array $criteria ): array {
		foreach ( $criteria as $i => $crit ) {
			if ( ! is_array( $crit ) ) {
				continue;
			}
			$key   = isset( $crit['key'] ) ? (string) $crit['key'] : '';
			$label = isset( $crit['label'] ) ? (string) $crit['label'] : '';
			if ( '' !== $key || '' === $label ) {
				continue;
			}
			$derived = sanitize_key( $label );
			if ( '' === $derived ) {
				$derived = 'crit_' . substr( md5( $label ), 0, 8 );
			}
			$criteria[ $i ]['key'] = $derived;
		}
		return $criteria;
	}

	/**
	 * The criteria the submission form actually shows for the given resolved
	 * settings — the flat `criteria` list, gated by the "form criteria" toggle.
	 * Shared by render_form() and the AJAX submit validator so the required-
	 * criteria check matches exactly the fields that were rendered (an empty
	 * result means the form has no criteria inputs, so nothing to validate).
	 *
	 * @param array<string,mixed> $s Resolved settings.
	 * @return array<int,array{key:string,label:string}>
	 */
	public function form_criteria( array $s ): array {
		$on = ! array_key_exists( 'form_criteria_enabled', $s ) || ! empty( $s['form_criteria_enabled'] );
		if ( ! $on || empty( $s['criteria'] ) || ! is_array( $s['criteria'] ) ) {
			return array();
		}
		return $this->backfill_criteria_keys( $s['criteria'] );
	}

	/**
	 * Resolve the full criteria group that applies to a given post.
	 * Matches by post id → post type → catchall, then returns the group's
	 * full data (criteria + overview + summary_style + show_average) so the
	 * aggregate renderer can read per-group settings.
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolve_group_for_post( int $post_id ): ?array {
		$s      = $this->settings->all();
		$groups = isset( $s['criteria_groups'] ) && is_array( $s['criteria_groups'] ) ? $s['criteria_groups'] : array();

		if ( empty( $groups ) ) {
			return null;
		}

		$post_type = $post_id ? (string) get_post_type( $post_id ) : '';

		foreach ( $groups as $g ) {
			$mode = (string) ( $g['visibility']['mode'] ?? 'all' );
			if ( 'specific' === $mode || 'post_types' === $mode ) {
				$ids = (array) ( $g['visibility']['post_ids'] ?? array() );
				if ( $post_id && in_array( $post_id, array_map( 'intval', $ids ), true ) ) {
					return $g;
				}
			}
		}
		foreach ( $groups as $g ) {
			$mode = (string) ( $g['visibility']['mode'] ?? 'all' );
			if ( 'specific' === $mode || 'post_types' === $mode ) {
				$pts = (array) ( $g['visibility']['post_types'] ?? array() );
				if ( $post_type && in_array( $post_type, $pts, true ) ) {
					return $g;
				}
			}
		}
		foreach ( $groups as $g ) {
			if ( 'all' === (string) ( $g['visibility']['mode'] ?? 'all' ) ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * Build a [criterion_key => label] map from the criteria that apply to
	 * the given post (or all configured ones if $post_id is 0).
	 *
	 * @return array<string,string>
	 */
	protected function criteria_label_map( int $post_id = 0 ): array {
		$criteria = $post_id > 0 ? $this->resolve_criteria_for_post( $post_id ) : array();

		if ( $post_id <= 0 ) {
			$s = $this->settings->all();
			foreach ( (array) ( $s['criteria_groups'] ?? array() ) as $g ) {
				foreach ( (array) ( $g['criteria'] ?? array() ) as $c ) {
					$criteria[] = $c;
				}
			}
			foreach ( (array) ( $s['criteria'] ?? array() ) as $c ) {
				$criteria[] = $c;
			}
		}

		$map = array();
		foreach ( $criteria as $c ) {
			if ( empty( $c['key'] ) || empty( $c['label'] ) ) {
				continue;
			}
			$map[ (string) $c['key'] ] = (string) $c['label'];
		}
		return $map;
	}

	/**
	 * Render the per-review criteria ratings list (criterion label + stars).
	 *
	 * Reviews store criteria scores keyed by the criterion `key` only;
	 * we look the label up from settings. If a saved criterion no longer
	 * exists in settings, we still render it but fall back to the raw key.
	 *
	 * @param array<string,mixed> $row       Review row.
	 * @param string              $class     CSS class to apply to the wrapping <ul>.
	 */
	protected function per_review_criteria_html( array $row, string $class = 'bdrvw-item__criteria' ): string {
		
		if ( empty( $row['criteria'] ) || ! is_array( $row['criteria'] ) ) {
			return '';
		}
		$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$labels  = $this->criteria_label_map( $post_id );

		ob_start();
		?>
		<ul class="<?php echo esc_attr( $class ); ?> bdrvw-criteria-list">
			<?php foreach ( $row['criteria'] as $ck => $cv ) :
				$value = (int) $cv;
				if ( $value <= 0 ) {
					continue;
				}
				$label = $labels[ (string) $ck ] ?? (string) $ck;
				?>
				<li class="bdrvw-criteria-list__row">
					<span class="bdrvw-criterion-label"><?php echo esc_html( $label ); ?></span>
					<span class="bdrvw-criterion-meta">
						<?php echo wp_kses( $this->stars_html( (float) $value ), bdrvw_allowed_html() ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a rating input control in the configured style.
	 *
	 * @param string $style    stars|slider|bar|square|pill
	 * @param string $name     Form field name (e.g. "rating" or "criteria[quality]").
	 * @param string $label_id Element id of the visible label (for aria-labelledby).
	 */
	protected function rating_input_html( string $style, string $name, string $label_id ): string {
		$style   = in_array( $style, array( 'stars', 'slider', 'bar', 'square', 'pill' ), true ) ? $style : 'stars';
		$wrap_cl = 'bdrvw-rating-input bdrvw-rating-input--' . $style . ( 'stars' === $style ? ' stars' : '' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrap_cl ); ?>" data-rating="0" data-input-style="<?php echo esc_attr( $style ); ?>">
			<?php
			switch ( $style ) {
				case 'slider':
					?>
					<input type="range" min="0" max="5" step="1" value="0" class="bdrvw-rating-slider"
						aria-labelledby="<?php echo esc_attr( $label_id ); ?>" />
					<output class="bdrvw-rating-output" aria-live="polite">0 / 5</output>
					<?php
					break;

				case 'bar':
				case 'square':
				case 'pill':
					?>
					<div role="radiogroup" aria-labelledby="<?php echo esc_attr( $label_id ); ?>" class="bdrvw-rating-input__group">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<button
								type="button"
								role="radio"
								tabindex="<?php echo 1 === $i ? '0' : '-1'; ?>"
								aria-checked="false"
								data-value="<?php echo (int) $i; ?>"
								class="bdrvw-rating-input__chip bdrvw-rating-input__chip--<?php echo esc_attr( $style ); ?>"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating value 1-5. */ __( '%d out of 5', 'boldreview' ), $i ) ); ?>"
							><span class="bdrvw-rating-input__chip-num"><?php echo (int) $i; ?></span></button>
						<?php endfor; ?>
					</div>
					<?php
					break;

				case 'stars':
				default:
					?>
					<span role="radiogroup" aria-labelledby="<?php echo esc_attr( $label_id ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<a
								href="#"
								role="radio"
								tabindex="<?php echo 1 === $i ? '0' : '-1'; ?>"
								aria-checked="false"
								class="bdrvw-rating-input__star star-<?php echo (int) $i; ?>"
								data-value="<?php echo (int) $i; ?>"
							><?php
								/* translators: %d: rating value 1-5. */
								echo esc_html( sprintf( __( '%d of 5 stars', 'boldreview' ), $i ) );
							?></a>
						<?php endfor; ?>
					</span>
					<?php
					break;
			}
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Star HTML (escaped, safe).
	 */
	protected function stars_html( float $value ): string {
		$value = max( 0.0, min( 5.0, $value ) );
		$full  = (int) floor( $value );
		$half  = ( $value - $full ) >= 0.5;
		$out   = '<span class="bdrvw-stars" aria-label="' . esc_attr( sprintf( /* translators: %s: rating value. */ __( '%s out of 5 stars', 'boldreview' ), number_format_i18n( $value, 1 ) ) ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $i <= $full ) {
				$out .= '<span class="bdrvw-stars__star is-full">&#9733;</span>';
			} elseif ( $half && $i === $full + 1 ) {
				$out .= '<span class="bdrvw-stars__star is-half">&#9733;</span>';
			} else {
				$out .= '<span class="bdrvw-stars__star">&#9734;</span>';
			}
		}
		$out .= '</span>';
		return $out;
	}

	/**
	 * Render pagination.
	 */
	protected function render_pagination( int $pages, int $current ): void {
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="bdrvw-pagination" aria-label="' . esc_attr__( 'Reviews pagination', 'boldreview' ) . '">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg( 'bdrvw_paged', $i );
			printf(
				'<a class="bdrvw-pagination__link%s" href="%s">%d</a>',
				esc_attr( $i === $current ? ' is-current' : '' ),
				esc_url( $url ),
				(int) $i
			);
		}
		echo '</nav>';
	}

	/**
	 * Author average rating across all their posts.
	 *
	 * @return array{average:float,count:int}
	 */
	protected function author_average( int $author_id ): array {
		return Bdrvw_Review::author_average( $author_id );
	}

	/**
	 * Reviewer (user) average rating across all reviews they wrote.
	 *
	 * @return array{average:float,count:int}
	 */
	protected function user_average( int $user_id ): array {
		return Bdrvw_Review::user_average( $user_id );
	}
}