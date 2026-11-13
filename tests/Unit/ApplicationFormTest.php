<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\ApplicationForm;
use KWV\Enhancements\Careers;
use KWV\Enhancements\EmailTemplate;
use KWV\Enhancements\Tests\Support\Snapshots;

/**
 * Provisioning and schema migration of the shared job-application form,
 * against the fake GFAPI (tests/Unit/Stubs/gravityforms.php).
 *
 * @covers \KWV\Enhancements\ApplicationForm
 */
final class ApplicationFormTest extends TestCase {

	use Snapshots;

	/** @var ApplicationForm */
	private $module;

	protected function set_up(): void {
		parent::set_up();
		$this->stubOptions( array( 'admin_email' => 'admin@example.org' ) );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.org' . $path;
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		$this->module = new ApplicationForm( new EmailTemplate() );
	}

	private function installedForm(): array {
		return \GFAPI::get_form( (int) $this->options[ Careers::FORM_OPTION ] );
	}

	/**
	 * @return array<int,object> Fields keyed by id.
	 */
	private function fieldsById( array $form ): array {
		$fields = array();
		foreach ( $form['fields'] as $field ) {
			$fields[ $field['id'] ] = $field;
		}
		return $fields;
	}

	/**
	 * The whole provisioned form — every field, both notification emails (full
	 * HTML) and the confirmation — so any change to what applicants fill in or
	 * what HR and applicants receive shows up as a reviewable diff.
	 */
	public function test_provisioned_form_matches_snapshot(): void {
		$this->options['kwv_careers_hr_email'] = 'careers@example.org';
		$this->module->maybe_install_form();

		$form = $this->installedForm();

		// Confirmation IDs come from uniqid(); pin them so the snapshot is stable.
		$this->assertCount( 1, $form['confirmations'] );
		$confirmation          = reset( $form['confirmations'] );
		$confirmation['id']    = 'CONFIRMATION_ID';
		$form['confirmations'] = array( 'CONFIRMATION_ID' => $confirmation );

		$this->assertMatchesSnapshot( $form, 'careers-application-form.json' );
	}

	public function test_register_installs_after_gravity_forms_loads(): void {
		$this->module->register();

		$this->assertSame( 20, has_action( 'init', 'KWV\Enhancements\ApplicationForm->maybe_install_form()' ) );
	}

	public function test_fresh_install_creates_the_form_and_records_its_id_and_schema(): void {
		$this->module->maybe_install_form();

		$this->assertSame( 1, $this->options[ Careers::FORM_OPTION ] );
		$this->assertSame( ApplicationForm::SCHEMA_VERSION, $this->options[ ApplicationForm::SCHEMA_OPTION ] );

		$form = $this->installedForm();
		$this->assertSame( 'KWV Job Application', $form['title'] );
		$this->assertSame( 'kwv-career-form', $form['cssClass'] );
	}

	public function test_form_keeps_schema_one_field_ids_for_existing_entries(): void {
		$this->module->maybe_install_form();
		$fields = $this->fieldsById( $this->installedForm() );

		$this->assertSame( 'email', $fields[1]['type'] );
		$this->assertSame( 'Name', $fields[2]['label'] );
		$this->assertSame( 'Surname', $fields[3]['label'] );
		$this->assertSame( 'phone', $fields[10]['type'] );
		$this->assertSame( 'fileupload', $fields[11]['type'] );
		$this->assertSame( 'pdf,doc,docx', $fields[11]['allowedExtensions'] );
		$this->assertTrue( $fields[11]['isRequired'] );
	}

	public function test_job_details_are_hidden_fields_named_after_job_details_keys(): void {
		$this->module->maybe_install_form();
		$hidden = array();
		foreach ( $this->installedForm()['fields'] as $field ) {
			if ( 'hidden' === $field['type'] ) {
				$hidden[ $field['id'] ] = $field['inputName'];
				$this->assertTrue( $field['allowsPrepopulate'] );
			}
		}

		$this->assertSame(
			array(
				17 => 'job_id',
				15 => 'job_title',
				16 => 'job_ref',
				18 => 'job_url',
				19 => 'job_location',
				20 => 'job_department',
				21 => 'job_closing',
			),
			$hidden
		);
		$this->assertSame( '{embed_post:ID}', $this->fieldsById( $this->installedForm() )[17]['defaultValue'] );
	}

	public function test_hr_notification_goes_to_the_careers_option_with_the_cv_attached(): void {
		$this->options[ Careers::HR_EMAIL_OPTION ] = 'hr@kwv.example';
		$this->module->maybe_install_form();

		$hr = $this->installedForm()['notifications'][ ApplicationForm::HR_NOTIFICATION ];
		$this->assertSame( 'hr@kwv.example', $hr['to'] );
		$this->assertSame( '{Email:1}', $hr['replyTo'] );
		$this->assertSame( 'text', $hr['message_format'] );
		$this->assertTrue( $hr['enableAttachments'] );
		$this->assertStringContainsString( '{Job Title:15}', $hr['subject'] );
		$this->assertStringContainsString( '{entry_url}', $hr['message'] );
	}

	public function test_hr_email_falls_back_to_the_admin_email_when_the_option_is_invalid(): void {
		$this->options[ Careers::HR_EMAIL_OPTION ] = 'not-an-email';
		$this->module->maybe_install_form();

		$notifications = $this->installedForm()['notifications'];
		$this->assertSame( 'admin@example.org', $notifications[ ApplicationForm::HR_NOTIFICATION ]['to'] );
		$this->assertSame( 'admin@example.org', $notifications[ ApplicationForm::USER_NOTIFICATION ]['replyTo'] );
	}

	public function test_applicant_confirmation_is_branded_and_sent_to_the_email_field(): void {
		$this->module->maybe_install_form();

		$user = $this->installedForm()['notifications'][ ApplicationForm::USER_NOTIFICATION ];
		$this->assertSame( 'field', $user['toType'] );
		$this->assertSame( '1', $user['to'] );
		$this->assertFalse( $user['enableAttachments'] );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $user['message'] );
		$this->assertStringContainsString( 'https://example.org/careers/', $user['message'] );
		// Optional job rows are wrapped so an empty value drops the row.
		$this->assertStringContainsString( '[gravityforms action="conditional" merge_tag="{Job Reference:16}"', $user['message'] );
	}

	public function test_careers_link_uses_the_careers_page_when_it_exists(): void {
		Functions\when( 'get_page_by_path' )->justReturn( (object) array( 'ID' => 5 ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.org/work-with-us/' );

		$this->module->maybe_install_form();

		$this->assertStringContainsString(
			'https://example.org/work-with-us/',
			$this->installedForm()['notifications'][ ApplicationForm::USER_NOTIFICATION ]['message']
		);
	}

	public function test_failed_install_records_nothing(): void {
		\GFAPI::$fail_with = new \WP_Error( 'nope' );

		$this->module->maybe_install_form();

		$this->assertArrayNotHasKey( Careers::FORM_OPTION, $this->options );
		$this->assertArrayNotHasKey( ApplicationForm::SCHEMA_OPTION, $this->options );
	}

	public function test_an_up_to_date_form_is_left_alone(): void {
		\GFAPI::$forms[7]                                = array(
			'id'     => 7,
			'title'  => 'Edited in the admin',
			'fields' => array(),
		);
		$this->options[ Careers::FORM_OPTION ]           = 7;
		$this->options[ ApplicationForm::SCHEMA_OPTION ] = ApplicationForm::SCHEMA_VERSION;

		$this->module->maybe_install_form();

		$this->assertSame( array(), \GFAPI::$forms[7]['fields'] );
		$this->assertSame( 1, \GFAPI::$next_id, 'No new form was added.' );
	}

	public function test_bumping_the_schema_migrates_the_existing_form_in_place(): void {
		\GFAPI::$forms[7]                      = array(
			'id'            => 7,
			'title'         => 'KWV Job Application',
			'fields'        => array(
				array(
					'id'   => 4,
					'type' => 'textarea',
				),
			),
			'confirmations' => array(
				'abc' => array(
					'id'        => 'abc',
					'isDefault' => true,
					'message'   => 'old',
				),
				'def' => array(
					'id'      => 'def',
					'message' => 'redirect',
				),
			),
			'notifications' => array(
				'admin'  => array(
					'name'    => 'Admin Notification',
					'message' => ' {all_fields} ',
				),
				'custom' => array(
					'name'    => 'Someone added this',
					'message' => 'Hi',
				),
			),
		);
		$this->options[ Careers::FORM_OPTION ] = 7;
		// No schema option recorded = schema 1.

		$this->module->maybe_install_form();

		$form = \GFAPI::$forms[7];
		$this->assertSame( ApplicationForm::SCHEMA_VERSION, $this->options[ ApplicationForm::SCHEMA_OPTION ] );
		$this->assertSame( 7, $this->options[ Careers::FORM_OPTION ], 'The form id never changes.' );
		$this->assertArrayHasKey( 17, $this->fieldsById( $form ) );
		$this->assertArrayNotHasKey( 4, $this->fieldsById( $form ) );
		$this->assertSame( 'kwv-career-form', $form['cssClass'] );

		$this->assertSame( array( 'abc', 'def' ), array_keys( $form['confirmations'] ) );
		$this->assertSame( 'abc', $form['confirmations']['abc']['id'] );
		$this->assertStringContainsString( 'kwv-career-confirmation', $form['confirmations']['abc']['message'] );
		$this->assertSame( 'redirect', $form['confirmations']['def']['message'] );

		$this->assertArrayNotHasKey( 'admin', $form['notifications'], 'The bare schema-1 admin notification is removed.' );
		$this->assertArrayHasKey( 'custom', $form['notifications'], 'Notifications added in the admin survive.' );
		$this->assertArrayHasKey( ApplicationForm::HR_NOTIFICATION, $form['notifications'] );
		$this->assertArrayHasKey( ApplicationForm::USER_NOTIFICATION, $form['notifications'] );
	}

	public function test_migration_adds_a_default_confirmation_when_none_exists(): void {
		\GFAPI::$forms[3]                      = array(
			'id'     => 3,
			'fields' => array(),
		);
		$this->options[ Careers::FORM_OPTION ] = 3;

		$this->module->maybe_install_form();

		$confirmations = \GFAPI::$forms[3]['confirmations'];
		$this->assertCount( 1, $confirmations );
		$this->assertTrue( reset( $confirmations )['isDefault'] );
	}

	public function test_migration_of_a_deleted_form_does_nothing(): void {
		$this->options[ Careers::FORM_OPTION ] = 99;

		$this->module->maybe_install_form();

		$this->assertArrayNotHasKey( ApplicationForm::SCHEMA_OPTION, $this->options );
		$this->assertSame( array(), \GFAPI::$forms );
	}

	public function test_failed_migration_keeps_the_old_schema_so_it_retries(): void {
		\GFAPI::$forms[7]                      = array(
			'id'     => 7,
			'fields' => array(),
		);
		$this->options[ Careers::FORM_OPTION ] = 7;
		\GFAPI::$fail_with                     = new \WP_Error( 'nope' );

		$this->module->maybe_install_form();

		$this->assertArrayNotHasKey( ApplicationForm::SCHEMA_OPTION, $this->options );
	}

	public function test_a_form_with_id_one_is_not_reinstalled(): void {
		\GFAPI::$forms[1]                                = array(
			'id'     => 1,
			'title'  => 'First form on the site',
			'fields' => array(),
		);
		\GFAPI::$next_id                                 = 2;
		$this->options[ Careers::FORM_OPTION ]           = 1;
		$this->options[ ApplicationForm::SCHEMA_OPTION ] = ApplicationForm::SCHEMA_VERSION;

		$this->module->maybe_install_form();

		$this->assertSame( 2, \GFAPI::$next_id, 'No new form was added.' );
		$this->assertSame( 1, $this->options[ Careers::FORM_OPTION ] );
	}

	public function test_an_installed_form_id_is_stored_as_an_integer(): void {
		\GFAPI::$next_id = 9;

		$this->module->maybe_install_form();

		$this->assertSame( 9, $this->options[ Careers::FORM_OPTION ] );
	}

	/**
	 * Only the bare schema-1 notification ("Admin Notification" sending
	 * {all_fields}) is replaced. One an editor customised, or any other
	 * notification that happens to send {all_fields}, is kept.
	 */
	public function test_migration_only_removes_the_untouched_schema_one_admin_notification(): void {
		\GFAPI::$forms[7]                      = array(
			'id'            => 7,
			'fields'        => array(),
			'notifications' => array(
				'customised' => array(
					'name'    => 'Admin Notification',
					'message' => 'Edited by HR: {all_fields}',
				),
				'other'      => array(
					'name'    => 'Copy to manager',
					'message' => '{all_fields}',
				),
			),
		);
		$this->options[ Careers::FORM_OPTION ] = 7;

		$this->module->maybe_install_form();

		$this->assertArrayHasKey( 'customised', \GFAPI::$forms[7]['notifications'] );
		$this->assertArrayHasKey( 'other', \GFAPI::$forms[7]['notifications'] );
	}
}
