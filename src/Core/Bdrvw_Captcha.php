<?php
/**
 * Spam protection for the review form.
 *
 * The built-in provider is a math question generated and verified here. Other
 * providers can be registered on `bdrvw_captcha_providers` and render / verify
 * on the same two hooks this class uses, so only one provider is ever on the
 * form at a time.
 *
 * @package BoldReview
 */

namespace BoldReview\Plugin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Math captcha: question generator, form field and submission check.
 */
class Bdrvw_Captcha {

	/**
	 * Top-level settings key this feature saves under.
	 */
	const SETTING_KEY = 'captcha';

	/**
	 * Slug of the built-in provider.
	 */
	const PROVIDER = 'math';

	/**
	 * Salt scope for the answer signature. Bump the suffix if the signed
	 * payload ever changes shape, so old forms can't validate against it.
	 */
	const HASH_SCOPE = 'bdrvw-captcha-v1';

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
		add_action( 'bdrvw_form_before_actions', array( $this, 'render_field' ), 10, 2 );
		add_filter( 'bdrvw_submit_field_errors', array( $this, 'validate' ), 10, 3 );
	}

	/* --------------------------------------------------------------------- */
	/* Configuration                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Default configuration. Mirrored in Bdrvw_Settings::defaults().
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => 0,
			'provider'        => self::PROVIDER,
			'difficulty'      => 'easy',
			'skip_logged_in'  => 0,
			'label'           => '',
			'error'           => '',
		);
	}

	/**
	 * Providers this install can actually use.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function providers(): array {
		$providers = array(
			self::PROVIDER => __( 'Math question', 'boldreview' ),
		);

		/**
		 * Filter the usable captcha providers.
		 *
		 * An add-on registers its own providers here.
		 *
		 * @param array<string,string> $providers Slug => label.
		 */
		return (array) apply_filters( 'bdrvw_captcha_providers', $providers );
	}

	/**
	 * Difficulty choices for the math question.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function difficulties(): array {
		return array(
			'easy'   => __( 'Easy — addition only (3 + 4)', 'boldreview' ),
			'medium' => __( 'Medium — addition and subtraction (17 − 8)', 'boldreview' ),
			'hard'   => __( 'Hard — addition, subtraction and multiplication (7 × 6)', 'boldreview' ),
		);
	}

	/**
	 * Resolved configuration, defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function config(): array {
		$stored = $this->settings->get( self::SETTING_KEY, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Whether the math question should be on the form for this visitor.
	 *
	 * A different provider means another captcha is handling the form, so the
	 * math field stays off — two captchas on one form helps nobody.
	 */
	public function active(): bool {
		$c = $this->config();

		if ( empty( $c['enabled'] ) ) {
			return false;
		}
		if ( self::PROVIDER !== (string) $c['provider'] ) {
			return false;
		}
		if ( ! empty( $c['skip_logged_in'] ) && is_user_logged_in() ) {
			return false;
		}

		return true;
	}

	/**
	 * The label shown above the question.
	 */
	protected function label(): string {
		$c     = $this->config();
		$label = trim( (string) $c['label'] );
		return '' !== $label ? $label : __( 'Anti-spam question', 'boldreview' );
	}

	/**
	 * The message shown when the answer is wrong or missing.
	 */
	protected function error_message(): string {
		$c   = $this->config();
		$msg = trim( (string) $c['error'] );
		return '' !== $msg ? $msg : __( 'That answer is not right. Please try again.', 'boldreview' );
	}

	/* --------------------------------------------------------------------- */
	/* Frontend                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Render the question inside the review form.
	 *
	 * Hooked to `bdrvw_form_before_actions`, so the fields sit inside the
	 * <form> and are collected with the rest of the submission.
	 *
	 * @param int                 $post_id Post being reviewed.
	 * @param array<string,mixed> $s       Resolved settings (unused).
	 */
	public function render_field( $post_id = 0, $s = array() ): void {
		unset( $s );

		if ( ! $this->active() ) {
			return;
		}

		$c        = $this->config();
		$question = self::make_question( (string) $c['difficulty'] );
		$token    = wp_generate_password( 20, false, false );
		$field_id = 'bdrvw-captcha-' . (int) $post_id;
		?>
		<div class="bdrvw-form__row bdrvw-form__row--captcha">
			<label class="bdrvw-form__label" for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $this->label() ); ?><span class="bdrvw-req">*</span>
			</label>
			<div class="bdrvw-captcha">
				<span class="bdrvw-captcha__question" aria-hidden="true"><?php echo esc_html( $question['text'] ); ?> =</span>
				<input
					id="<?php echo esc_attr( $field_id ); ?>"
					class="bdrvw-input bdrvw-captcha__input"
					type="text"
					name="captcha_answer"
					inputmode="numeric"
					autocomplete="off"
					maxlength="7"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the math question, e.g. "7 + 3". */ __( 'What is %s?', 'boldreview' ), $question['text'] ) ); ?>"
				/>
				<input type="hidden" name="captcha_token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="captcha_check" value="<?php echo esc_attr( self::sign( $token, (int) $question['answer'] ) ); ?>" />
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Validation                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Reject a submission whose answer doesn't match the question it was
	 * given. Hooked to `bdrvw_submit_field_errors`.
	 *
	 * @param array<string,string> $errors  Field errors collected so far.
	 * @param array<string,mixed>  $payload Sanitized review payload (unused).
	 * @param array<string,mixed>  $raw     Raw, unslashed posted data.
	 * @return array<string,string>
	 */
	public function validate( $errors, $payload = array(), $raw = array() ): array {
		unset( $payload );
		$errors = (array) $errors;

		if ( ! $this->active() ) {
			return $errors;
		}

		$raw    = is_array( $raw ) ? $raw : array();
		$answer = isset( $raw['captcha_answer'] ) ? trim( sanitize_text_field( (string) $raw['captcha_answer'] ) ) : '';
		$token  = isset( $raw['captcha_token'] ) ? sanitize_text_field( (string) $raw['captcha_token'] ) : '';
		$check  = isset( $raw['captcha_check'] ) ? sanitize_text_field( (string) $raw['captcha_check'] ) : '';

		if ( '' === $answer ) {
			$errors['captcha_answer'] = __( 'Please answer the anti-spam question.', 'boldreview' );
			return $errors;
		}

		
		if ( ! preg_match( '/^-?\d{1,6}$/', $answer ) ) {
			$errors['captcha_answer'] = $this->error_message();
			return $errors;
		}

		if ( '' === $token || '' === $check || ! hash_equals( self::sign( $token, (int) $answer ), $check ) ) {
			$errors['captcha_answer'] = $this->error_message();
		}

		return $errors;
	}

	/* --------------------------------------------------------------------- */
	/* Question generation                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Build a question and its answer for the given difficulty.
	 *
	 * Subtraction is always ordered so the result stays positive — a negative
	 * answer reads like a trick question rather than an anti-spam check.
	 *
	 * @param string $difficulty easy|medium|hard.
	 * @return array{text:string,answer:int}
	 */
	protected static function make_question( string $difficulty ): array {
		if ( ! array_key_exists( $difficulty, self::difficulties() ) ) {
			$difficulty = 'easy';
		}

		switch ( $difficulty ) {
			case 'hard':
				$ops = array( '+', '-', '*' );
				break;
			case 'medium':
				$ops = array( '+', '-' );
				break;
			default:
				$ops = array( '+' );
		}

		$op = $ops[ wp_rand( 0, count( $ops ) - 1 ) ];

		if ( '*' === $op ) {
			$a = wp_rand( 2, 9 );
			$b = wp_rand( 2, 9 );

			return array(
				'text'   => $a . ' × ' . $b,
				'answer' => $a * $b,
			);
		}

		$max = ( 'easy' === $difficulty ) ? 9 : 20;
		$a   = wp_rand( 1, $max );
		$b   = wp_rand( 1, $max );

		if ( '-' === $op ) {
			if ( $b > $a ) {
				list( $a, $b ) = array( $b, $a );
			}
			return array(
				'text'   => $a . ' − ' . $b,
				'answer' => $a - $b,
			);
		}

		return array(
			'text'   => $a . ' + ' . $b,
			'answer' => $a + $b,
		);
	}

	/**
	 * Sign an answer against the per-render token.
	 *
	 * The expected answer is never sent to the browser, and the token makes
	 * every render sign differently — so a signature harvested from one page
	 * view says nothing about the next one. It is stateless on purpose: a
	 * transient would expire behind a full-page cache and break every form on
	 * the site until the cache cleared.
	 *
	 * @param string $token  Per-render random token.
	 * @param int    $answer Expected answer.
	 */
	protected static function sign( string $token, int $answer ): string {
		return wp_hash( self::HASH_SCOPE . '|' . $token . '|' . $answer );
	}
}
