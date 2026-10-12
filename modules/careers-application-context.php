<?php
/**
 * Careers — records which job an application is for.
 *
 * The application form carries a hidden Job ID field (inputName `job_id`): the
 * jobs modal sets it to the job being viewed, and on the single-job page it
 * defaults to {embed_post:ID}. Everything else about the job — title,
 * reference, link, location, department, closing date — is filled in here,
 * server-side, from that ID. Nothing the browser posts for those fields is
 * trusted, so the entry and both notifications always carry the real job
 * details whichever page the form was submitted from.
 *
 * It also refuses applications for a job that has closed (past its closing
 * date, or no longer published) — the modal and single page hide the form
 * then, but a page held open across midnight, or a cached one, could still
 * post it.
 *
 * Works for any form whose hidden fields use these inputNames (keys of
 * {@see Careers::job_details()}), so another form could opt in too.
 * Inert when Gravity Forms is not active.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fills and guards the job context of a job application.
 */
class ApplicationContext implements Module {

	/**
	 * The Careers module, for job lookups.
	 *
	 * @var Careers
	 */
	private $careers;

	/**
	 * Whether the current submission was refused because the job has closed.
	 *
	 * @var bool
	 */
	private $closed = false;

	/**
	 * @param Careers $careers The Careers module.
	 */
	public function __construct( Careers $careers ) {
		$this->careers = $careers;
	}

	/**
	 * Defer hook registration to init so Gravity Forms has loaded.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_hooks' ) );
	}

	/**
	 * Hook validation, submission and email filters. Guarded so the module is
	 * inert without Gravity Forms.
	 */
	public function register_hooks(): void {

		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}

		add_filter( 'gform_validation', array( $this, 'validate' ) );
		add_filter( 'gform_validation_message', array( $this, 'validation_message' ), 10, 2 );
		add_action( 'gform_pre_submission', array( $this, 'fill' ) );
		add_filter( 'gform_pre_send_email', array( $this, 'tidy_hr_email' ), 10, 3 );
	}

	/**
	 * The form's job-context hidden fields, keyed by inputName.
	 *
	 * @param array $form Gravity Forms form.
	 * @return array<string,\GF_Field>
	 */
	private function job_fields( $form ): array {

		$keys   = array( 'job_id', 'job_title', 'job_ref', 'job_url', 'job_location', 'job_department', 'job_closing' );
		$fields = array();

		foreach ( (array) rgar( $form, 'fields' ) as $field ) {
			if ( 'hidden' === $field->type && in_array( $field->inputName, $keys, true ) ) {
				$fields[ $field->inputName ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * The job ID posted with a form, or 0 when absent or not a job (e.g. the
	 * Careers page's own ID, if the modal script never ran).
	 *
	 * @param array<string,\GF_Field> $fields Job-context fields.
	 * @return int
	 */
	private function posted_job_id( array $fields ): int {

		if ( ! isset( $fields['job_id'] ) ) {
			return 0;
		}

		$job_id = absint( rgpost( 'input_' . $fields['job_id']->id ) );

		return $job_id && 'kwv_career' === get_post_type( $job_id ) ? $job_id : 0;
	}

	/**
	 * Refuse an application for a job that has closed.
	 *
	 * @param array $result Gravity Forms validation result.
	 * @return array
	 */
	public function validate( $result ) {

		$fields = $this->job_fields( $result['form'] );
		$job_id = $this->posted_job_id( $fields );

		if ( $job_id && ! $this->careers->is_open( $job_id ) ) {

			$this->closed       = true;
			$result['is_valid'] = false;

			$field                     = $fields['job_id'];
			$field->failed_validation  = true;
			$field->validation_message = __( 'Applications for this position have closed.', 'kwv-enhancements' );
		}

		return $result;
	}

	/**
	 * Replace the generic "there was a problem" banner when the job has closed —
	 * the failing field is hidden, so the banner is the only place to say why.
	 *
	 * @param string $message Validation message markup.
	 * @param array  $form    Gravity Forms form.
	 * @return string
	 */
	public function validation_message( $message, $form ) {

		if ( ! $this->closed ) {
			return $message;
		}

		// Gravity Forms wraps this in its own validation container.
		return sprintf(
			'<h2 class="gform_submission_error"><span class="gform-icon gform-icon--circle-error"></span>%s</h2>',
			esc_html__( 'Sorry — applications for this position have closed.', 'kwv-enhancements' )
		);
	}

	/**
	 * Fill the job-context fields from the posted job ID before the entry is
	 * saved, overwriting anything the browser sent. A submission with no
	 * valid job (a general application) records the details as empty.
	 *
	 * @param array $form Gravity Forms form.
	 */
	public function fill( $form ): void {

		$fields  = $this->job_fields( $form );
		$details = $this->careers->job_details( $this->posted_job_id( $fields ) );

		foreach ( $fields as $input_name => $field ) {
			$_POST[ 'input_' . $field->id ] = $details[ $input_name ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms validates the submission; this only overwrites values.
		}
	}

	/**
	 * Tidy the plain-text HR email before it is sent:
	 *
	 * - drop the empty "(Ref )" from the subject when a job has no reference
	 *   (subjects are not run through shortcodes, so this cannot be conditional
	 *   in the notification itself);
	 * - decode the `&#038;` Gravity Forms leaves in {entry_url}, which would
	 *   break the link in a plain-text message.
	 *
	 * @param array  $email          The email: to, subject, message, headers, attachments, abort_email.
	 * @param string $message_format The message format.
	 * @param array  $notification   The notification being sent.
	 * @return array
	 */
	public function tidy_hr_email( $email, $message_format, $notification ) {

		if ( ApplicationForm::HR_NOTIFICATION !== rgar( $notification, 'id' ) ) {
			return $email;
		}

		$email['subject'] = trim( preg_replace( array( '/\s*\(Ref\s*\)/', '/:\s+—/' ), array( '', ' —' ), $email['subject'] ) );

		if ( 'text' === $message_format ) {
			$email['message'] = str_replace( '&#038;', '&', $email['message'] );
		}

		return $email;
	}
}
