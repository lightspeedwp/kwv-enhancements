<?php
/**
 * Careers — shared Gravity Forms application form: installer and migrations.
 *
 * Creates the "KWV Job Application" form (when Gravity Forms is active) and
 * stores its id in the {@see Careers::FORM_OPTION} option. The theme's
 * single-job template and the jobs modal embed this form by default;
 * individual jobs can override it with the kwv_career_form_id field (see
 * Careers::get_form_id()).
 *
 * The form is versioned ({@see SCHEMA_VERSION}, recorded in
 * {@see SCHEMA_OPTION}). A fresh install creates the current shape; an install
 * on an older version is migrated in place once — fields, confirmation and
 * notifications are rewritten, and the option bumped so it never runs again.
 * Bump the version to re-provision. That run overwrites edits made to those
 * parts of the form in the Gravity Forms admin.
 *
 * Schema 2 (the simplified application):
 *
 * - Visible: Name, Surname, Email, Phone, CV upload. Field ids are kept from
 *   schema 1 (Email 1, Name 2, Surname 3, Phone 10, CV 11) so existing entries
 *   still display those values; the dropped fields' data stays in the entries.
 * - Hidden, recording the job: Job ID 17 (the job's post ID — set by the jobs
 *   modal, or {embed_post:ID} on the single-job page), and Job Title 15,
 *   Job Reference 16, Job Link 18, Location 19, Department 20, Closing Date 21,
 *   which {@see ApplicationContext} fills server-side from the Job ID.
 * - Notifications: a plain-text email to HR (CV attached) and a branded
 *   confirmation to the applicant.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and migrates the shared job-application Gravity Form.
 */
class ApplicationForm implements Module {

	/**
	 * Option recording which schema version the installed form is on.
	 */
	public const SCHEMA_OPTION = 'kwv_careers_form_schema';

	/**
	 * The current schema version.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Stable notification ids, so a re-provision overwrites rather than adds.
	 */
	public const HR_NOTIFICATION   = 'kwv_hr_career_application';
	public const USER_NOTIFICATION = 'kwv_user_career_application';

	/**
	 * Field ids, for merge tags.
	 */
	private const FIELD_EMAIL      = 1;
	private const FIELD_NAME       = 2;
	private const FIELD_SURNAME    = 3;
	private const FIELD_PHONE      = 10;
	private const FIELD_CV         = 11;
	private const FIELD_TITLE      = 15;
	private const FIELD_REF        = 16;
	private const FIELD_JOB_ID     = 17;
	private const FIELD_URL        = 18;
	private const FIELD_LOCATION   = 19;
	private const FIELD_DEPARTMENT = 20;
	private const FIELD_CLOSING    = 21;

	/**
	 * Branded email builder for the applicant confirmation.
	 *
	 * @var EmailTemplate
	 */
	private $email;

	/**
	 * @param EmailTemplate $email Branded email builder.
	 */
	public function __construct( EmailTemplate $email ) {
		$this->email = $email;
	}

	/**
	 * Install or migrate on init (after Gravity Forms has loaded).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install_form' ), 20 );
	}

	/**
	 * Create the form if none is installed, otherwise migrate it to the current
	 * schema if it is behind. Inert once the form is current.
	 */
	public function maybe_install_form(): void {

		if ( ! class_exists( 'GFAPI' ) ) {
			return;
		}

		$form_id = (int) get_option( Careers::FORM_OPTION );

		if ( $form_id < 1 ) {
			$this->install();
			return;
		}

		if ( (int) get_option( self::SCHEMA_OPTION, 1 ) < self::SCHEMA_VERSION ) {
			$this->migrate( $form_id );
		}
	}

	/**
	 * Create the form in its current shape.
	 */
	private function install(): void {

		$confirmation_id = uniqid();

		$form = array_merge(
			$this->form_settings(),
			array(
				'title'         => 'KWV Job Application',
				'description'   => '',
				'fields'        => $this->fields(),
				'confirmations' => array(
					$confirmation_id => $this->confirmation( $confirmation_id ),
				),
				'notifications' => $this->notifications(),
			)
		);

		$result = \GFAPI::add_form( $form );

		if ( ! is_wp_error( $result ) ) {
			update_option( Careers::FORM_OPTION, (int) $result );
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * Bring an existing form up to the current schema.
	 *
	 * Fields and form settings are replaced outright. The default confirmation
	 * keeps its id and gets the new message. Notifications: ours are written by
	 * stable id, and the bare `{all_fields}` admin notification the schema-1
	 * installer created is removed (HR now gets the dedicated one); any other
	 * notification someone added in the admin is left alone.
	 *
	 * @param int $form_id Installed form ID.
	 */
	private function migrate( int $form_id ): void {

		$form = \GFAPI::get_form( $form_id );

		if ( ! $form ) {
			return;
		}

		$form           = array_merge( $form, $this->form_settings() );
		$form['fields'] = $this->fields();

		$confirmations = is_array( rgar( $form, 'confirmations' ) ) ? $form['confirmations'] : array();
		$default_id    = '';

		foreach ( $confirmations as $id => $confirmation ) {
			if ( ! empty( $confirmation['isDefault'] ) ) {
				$default_id = (string) $id;
			}
		}

		if ( '' === $default_id ) {
			$default_id = uniqid();
		}

		$confirmations[ $default_id ] = $this->confirmation( $default_id );
		$form['confirmations']        = $confirmations;

		$notifications = is_array( rgar( $form, 'notifications' ) ) ? $form['notifications'] : array();

		foreach ( $notifications as $id => $notification ) {
			if ( 'Admin Notification' === rgar( $notification, 'name' ) && '{all_fields}' === trim( (string) rgar( $notification, 'message' ) ) ) {
				unset( $notifications[ $id ] );
			}
		}

		$form['notifications'] = array_merge( $notifications, $this->notifications() );

		$result = \GFAPI::update_form( $form, $form_id );

		if ( ! is_wp_error( $result ) ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * Form-level settings shared by install and migrate.
	 *
	 * The `kwv-career-form` class is what styling and scripts key off — never
	 * the form id, which differs per environment.
	 *
	 * @return array
	 */
	private function form_settings(): array {
		return array(
			'labelPlacement'       => 'top_label',
			'descriptionPlacement' => 'below',
			'cssClass'             => 'kwv-career-form',
			'button'               => array(
				'type' => 'text',
				'text' => 'Submit application',
			),
		);
	}

	/**
	 * The field set, in display order. Name/Surname and Email/Phone share a
	 * row each (Gravity Forms stacks them on narrow screens).
	 *
	 * @return array[]
	 */
	private function fields(): array {
		return array(
			array(
				'id'                    => self::FIELD_NAME,
				'type'                  => 'text',
				'label'                 => 'Name',
				'isRequired'            => true,
				'autocompleteAttribute' => 'given-name',
				'enableAutocomplete'    => true,
				'layoutGridColumnSpan'  => 6,
				'layoutGroupId'         => 'kwvname',
			),
			array(
				'id'                    => self::FIELD_SURNAME,
				'type'                  => 'text',
				'label'                 => 'Surname',
				'isRequired'            => true,
				'autocompleteAttribute' => 'family-name',
				'enableAutocomplete'    => true,
				'layoutGridColumnSpan'  => 6,
				'layoutGroupId'         => 'kwvname',
			),
			array(
				'id'                    => self::FIELD_EMAIL,
				'type'                  => 'email',
				'label'                 => 'Email',
				'isRequired'            => true,
				'autocompleteAttribute' => 'email',
				'enableAutocomplete'    => true,
				'layoutGridColumnSpan'  => 6,
				'layoutGroupId'         => 'kwvcontact',
			),
			array(
				'id'                    => self::FIELD_PHONE,
				'type'                  => 'phone',
				'label'                 => 'Phone',
				'isRequired'            => true,
				'phoneFormat'           => 'international',
				'autocompleteAttribute' => 'tel',
				'enableAutocomplete'    => true,
				'layoutGridColumnSpan'  => 6,
				'layoutGroupId'         => 'kwvcontact',
			),
			array(
				'id'                => self::FIELD_CV,
				'type'              => 'fileupload',
				'label'             => 'CV',
				'isRequired'        => true,
				'allowedExtensions' => 'pdf,doc,docx',
				'maxFileSize'       => 5,
				'multipleFiles'     => false,
			),
			$this->hidden_field( self::FIELD_JOB_ID, 'Job ID', 'job_id', '{embed_post:ID}' ),
			$this->hidden_field( self::FIELD_TITLE, 'Job Title', 'job_title' ),
			$this->hidden_field( self::FIELD_REF, 'Job Reference', 'job_ref' ),
			$this->hidden_field( self::FIELD_URL, 'Job Link', 'job_url' ),
			$this->hidden_field( self::FIELD_LOCATION, 'Location', 'job_location' ),
			$this->hidden_field( self::FIELD_DEPARTMENT, 'Department', 'job_department' ),
			$this->hidden_field( self::FIELD_CLOSING, 'Closing Date', 'job_closing' ),
		);
	}

	/**
	 * A hidden field that records one job detail.
	 *
	 * The inputName is the contract {@see ApplicationContext} fills by (keys of
	 * Careers::job_details()), so any form with these inputNames — a per-job
	 * override form included — gets the job details recorded.
	 *
	 * @param int    $id            Field ID.
	 * @param string $label         Field label (also the merge-tag label).
	 * @param string $input_name    Parameter name.
	 * @param string $default_value Default value.
	 * @return array
	 */
	private function hidden_field( int $id, string $label, string $input_name, string $default_value = '' ): array {
		return array(
			'id'                => $id,
			'type'              => 'hidden',
			'label'             => $label,
			'defaultValue'      => $default_value,
			'allowsPrepopulate' => true,
			'inputName'         => $input_name,
		);
	}

	/**
	 * The default (on-page) confirmation. Rendered in place of the form — in
	 * the modal too, since the modal submits over AJAX.
	 *
	 * @param string $id Confirmation ID.
	 * @return array
	 */
	private function confirmation( string $id ): array {

		$for = sprintf(
			'[gravityforms action="conditional" merge_tag="{Job Title:%1$d}" condition="isnot" value=""] for <strong>{Job Title:%1$d}</strong>[/gravityforms][gravityforms action="conditional" merge_tag="{Job Reference:%2$d}" condition="isnot" value=""] (Ref {Job Reference:%2$d})[/gravityforms]',
			self::FIELD_TITLE,
			self::FIELD_REF
		);

		return array(
			'id'        => $id,
			'name'      => 'Default Confirmation',
			'isDefault' => true,
			'type'      => 'message',
			'message'   => sprintf(
				'<div class="kwv-career-confirmation"><p class="kwv-career-confirmation__title">Thank you, {Name:%1$d}.</p><p>Your application%2$s has been received, and a copy is on its way to {Email:%3$d}.</p><p>Should you not hear from us within one month of the closing date of the advert, kindly regard your application as unsuccessful.</p></div>',
				self::FIELD_NAME,
				$for,
				self::FIELD_EMAIL
			),
		);
	}

	/**
	 * The two notifications, keyed by their stable ids.
	 *
	 * @return array<string,array>
	 */
	private function notifications(): array {
		return array(
			self::HR_NOTIFICATION   => $this->hr_notification(),
			self::USER_NOTIFICATION => $this->user_notification(),
		);
	}

	/**
	 * The HR inbox applications go to: the Careers HR option, else the site
	 * admin email. Resolved when the notification is provisioned; HR can change
	 * it afterwards in the form's notification settings.
	 *
	 * @return string
	 */
	private function hr_email(): string {
		$email = sanitize_email( (string) get_option( Careers::HR_EMAIL_OPTION, '' ) );

		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Plain-text notification to HR: who applied, for which job, and where to
	 * find it. The CV is attached; Reply-To is the applicant.
	 *
	 * @return array
	 */
	private function hr_notification(): array {

		$lines = array(
			'A new job application has been submitted through the KWV website.',
			'',
			'POSITION',
			'Job title:      {Job Title:' . self::FIELD_TITLE . '}',
			'Reference:      {Job Reference:' . self::FIELD_REF . '}',
			'Department:     {Department:' . self::FIELD_DEPARTMENT . '}',
			'Location:       {Location:' . self::FIELD_LOCATION . '}',
			'Closing date:   {Closing Date:' . self::FIELD_CLOSING . '}',
			'Job posting:    {Job Link:' . self::FIELD_URL . '}',
			'',
			'APPLICANT',
			'Name:           {Name:' . self::FIELD_NAME . '} {Surname:' . self::FIELD_SURNAME . '}',
			'Email:          {Email:' . self::FIELD_EMAIL . '}',
			'Phone:          {Phone:' . self::FIELD_PHONE . '}',
			'CV:             {CV:' . self::FIELD_CV . '}',
			'                (also attached to this email)',
			'',
			'SUBMISSION',
			'Received:       {date_dmy}',
			'Submitted from: {embed_url}',
			'Entry ID:       {entry_id}',
			'View entry:     {entry_url}',
			'',
			'Reply to this email to respond to the applicant directly.',
		);

		return array(
			'id'                => self::HR_NOTIFICATION,
			'isActive'          => true,
			'name'              => 'HR — New Application',
			'event'             => 'form_submission',
			'toType'            => 'email',
			'to'                => $this->hr_email(),
			'from'              => '{admin_email}',
			'fromName'          => 'KWV Careers',
			'replyTo'           => '{Email:' . self::FIELD_EMAIL . '}',
			'bcc'               => '',
			'subject'           => 'Job application: {Job Title:' . self::FIELD_TITLE . '} (Ref {Job Reference:' . self::FIELD_REF . '}) — {Name:' . self::FIELD_NAME . '} {Surname:' . self::FIELD_SURNAME . '}',
			'message'           => implode( "\n", $lines ),
			'message_format'    => 'text',
			'disableAutoformat' => true,
			'enableAttachments' => true,
			'conditionalLogic'  => array(),
		);
	}

	/**
	 * Branded confirmation to the applicant, with the same job + applicant
	 * details HR receives. Reply-To is HR.
	 *
	 * @return array
	 */
	private function user_notification(): array {

		$title      = '{Job Title:' . self::FIELD_TITLE . '}';
		$ref        = '{Job Reference:' . self::FIELD_REF . '}';
		$department = '{Department:' . self::FIELD_DEPARTMENT . '}';
		$location   = '{Location:' . self::FIELD_LOCATION . '}';
		$closing    = '{Closing Date:' . self::FIELD_CLOSING . '}';

		$body = $this->email->heading( 'Thank you, {Name:' . self::FIELD_NAME . '}' )
			. $this->email->paragraph( 'Thank you for your interest in joining KWV. We’ve received your application and our HR team will review it against the requirements of the position.' )
			. $this->email->paragraph( 'For your records, here’s a summary of your application:' )
			. $this->email->details(
				array(
					'Position'     => $title,
					'Reference'    => $ref,
					'Department'   => $department,
					'Location'     => $location,
					'Closing date' => $closing,
					'Name'         => '{Name:' . self::FIELD_NAME . '} {Surname:' . self::FIELD_SURNAME . '}',
					'Email'        => '{Email:' . self::FIELD_EMAIL . '}',
					'Phone'        => '{Phone:' . self::FIELD_PHONE . '}',
					'CV'           => 'Received',
					'Submitted'    => '{date_dmy}',
				),
				array(
					'Position'     => $title,
					'Reference'    => $ref,
					'Department'   => $department,
					'Location'     => $location,
					'Closing date' => $closing,
				)
			)
			. $this->email->paragraph( 'Should you not hear from us within one month of the closing date of the advert, kindly regard your application as unsuccessful.' )
			. $this->email->button( 'View current vacancies', $this->careers_url() );

		return array(
			'id'                => self::USER_NOTIFICATION,
			'isActive'          => true,
			'name'              => 'Applicant Confirmation',
			'event'             => 'form_submission',
			'toType'            => 'field',
			'to'                => (string) self::FIELD_EMAIL,
			'from'              => '{admin_email}',
			'fromName'          => 'KWV Careers',
			'replyTo'           => $this->hr_email(),
			'bcc'               => '',
			'subject'           => 'We’ve received your application — KWV',
			'message'           => $this->email->document(
				'Thank you for applying to KWV — here’s a copy of your application.',
				$body,
				'You’re receiving this because you applied for a position at KWV via kwv.co.za. Reply to this email with any questions about your application.'
			),
			'disableAutoformat' => true,
			'enableAttachments' => false,
			'conditionalLogic'  => array(),
		);
	}

	/**
	 * The Careers page URL (the jobs board), falling back to /careers/.
	 *
	 * @return string
	 */
	private function careers_url(): string {
		$page = get_page_by_path( 'careers' );

		return $page ? (string) get_permalink( $page ) : home_url( '/careers/' );
	}
}
