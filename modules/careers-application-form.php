<?php
/**
 * Careers — shared Gravity Forms application form installer.
 *
 * Creates the "KWV Job Application" form once (when Gravity Forms is active)
 * and stores its id in the {@see Careers::FORM_OPTION} option. The theme's
 * single-job template embeds this form by default; individual jobs can
 * override it with the kwv_career_form_id field (see Careers::get_form_id()).
 *
 * Hidden fields 15/16 record which job an application is for: they default to
 * {embed_post:post_title} / {custom_field:kwv_career_reference} resolved from
 * the job post the form renders on, and also accept the job_title / job_ref
 * query-string parameters as an override.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time installer for the shared job-application Gravity Form.
 */
class ApplicationForm implements Module {

	/**
	 * Attempt the install on init (after Gravity Forms has loaded).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_install_form' ), 20 );
	}

	/**
	 * Install the shared form once, if Gravity Forms is active and no form has
	 * been installed yet.
	 */
	public function maybe_install_form(): void {

		if ( get_option( Careers::FORM_OPTION ) || ! class_exists( 'GFAPI' ) ) {
			return;
		}

		$ctc_ranges = array(
			'R0 – R150 000',
			'R150 000 – R250 000',
			'R250 000 – R400 000',
			'R400 000 – R600 000',
			'R600 000 – R850 000',
			'R850 000 +',
		);

		$provinces = array(
			'Eastern Cape',
			'Free State',
			'Gauteng',
			'KwaZulu-Natal',
			'Limpopo',
			'Mpumalanga',
			'Northern Cape',
			'North West',
			'Western Cape',
			'Outside South Africa',
		);

		$form = array(
			'title'          => 'KWV Job Application',
			'description'    => '',
			'labelPlacement' => 'top_label',
			'button'         => array(
				'type' => 'text',
				'text' => 'Submit',
			),
			'fields'         => array(
				array(
					'id'         => 1,
					'type'       => 'email',
					'label'      => 'Email',
					'isRequired' => true,
				),
				array(
					'id'         => 2,
					'type'       => 'text',
					'label'      => 'Name',
					'isRequired' => true,
				),
				array(
					'id'         => 3,
					'type'       => 'text',
					'label'      => 'Surname',
					'isRequired' => true,
				),
				array(
					'id'         => 4,
					'type'       => 'select',
					'label'      => 'Gender',
					'isRequired' => true,
					'choices'    => $this->choices( array( 'Male', 'Female', 'Prefer not to say' ) ),
				),
				array(
					'id'         => 5,
					'type'       => 'select',
					'label'      => 'Race',
					'isRequired' => true,
					'choices'    => $this->choices( array( 'Black/African', 'Coloured', 'Indian', 'White', 'Prefer not to say' ) ),
				),
				array(
					'id'      => 6,
					'type'    => 'select',
					'label'   => 'What is your current Cost to Company?',
					'choices' => $this->choices( $ctc_ranges ),
				),
				array(
					'id'      => 7,
					'type'    => 'select',
					'label'   => 'What is your expected remuneration (Cost to Company)?',
					'choices' => $this->choices( $ctc_ranges ),
				),
				array(
					'id'      => 8,
					'type'    => 'select',
					'label'   => 'Where are you based (location)?',
					'choices' => $this->choices( $provinces ),
				),
				array(
					'id'      => 9,
					'type'    => 'select',
					'label'   => 'Notice period?',
					'choices' => $this->choices( array( '1 week', '2 weeks', '1 month', '2 months', '3 months or more' ) ),
				),
				array(
					'id'          => 10,
					'type'        => 'phone',
					'label'       => 'Contact Number',
					'isRequired'  => true,
					'phoneFormat' => 'international',
				),
				array(
					'id'                => 11,
					'type'              => 'fileupload',
					'label'             => 'CV File Upload',
					'isRequired'        => true,
					'allowedExtensions' => 'pdf,doc,docx',
					'maxFileSize'       => 5,
					'description'       => 'Upload your CV (PDF or Word, max 5MB).',
				),
				array(
					'id'                => 12,
					'type'              => 'fileupload',
					'label'             => 'Highest qualification? Proof of qualification to be submitted',
					'allowedExtensions' => 'pdf,doc,docx,jpg,png',
					'maxFileSize'       => 5,
					'description'       => 'Upload proof of your highest qualification (PDF, Word or image, max 5MB).',
				),
				array(
					'id'      => 13,
					'type'    => 'select',
					'label'   => 'Are you willing to relocate?',
					'choices' => $this->choices( array( 'Yes', 'No' ) ),
				),
				array(
					'id'         => 14,
					'type'       => 'textarea',
					'label'      => 'Message',
					'isRequired' => true,
				),
				array(
					'id'                => 15,
					'type'              => 'hidden',
					'label'             => 'Job Title',
					'defaultValue'      => '{embed_post:post_title}',
					'allowsPrepopulate' => true,
					'inputName'         => 'job_title',
				),
				array(
					'id'                => 16,
					'type'              => 'hidden',
					'label'             => 'Job Reference',
					'defaultValue'      => '{custom_field:kwv_career_reference}',
					'allowsPrepopulate' => true,
					'inputName'         => 'job_ref',
				),
			),
		);

		$confirmation_id       = uniqid();
		$form['confirmations'] = array(
			$confirmation_id => array(
				'id'        => $confirmation_id,
				'name'      => 'Default Confirmation',
				'isDefault' => true,
				'type'      => 'message',
				'message'   => 'Thank you for your application. Should you not hear from us within one month of the closing date of the advert, kindly regard your application as unsuccessful.',
			),
		);
		$notification_id       = uniqid();
		$form['notifications'] = array(
			$notification_id => array(
				'id'      => $notification_id,
				'name'    => 'Admin Notification',
				'event'   => 'form_submission',
				'to'      => '{admin_email}',
				'toType'  => 'email',
				'subject' => 'New job application: {Job Title:15} (Ref: {Job Reference:16})',
				'message' => '{all_fields}',
			),
		);

		$result = \GFAPI::add_form( $form );

		if ( ! is_wp_error( $result ) ) {
			update_option( Careers::FORM_OPTION, (int) $result );
		}
	}

	/**
	 * Build a Gravity Forms choices array from a flat list of labels.
	 *
	 * @param string[] $labels Choice labels.
	 * @return array[] Gravity Forms choice arrays.
	 */
	private function choices( $labels ) {
		$choices = array();

		foreach ( $labels as $label ) {
			$choices[] = array(
				'text'  => $label,
				'value' => $label,
			);
		}

		return $choices;
	}
}
