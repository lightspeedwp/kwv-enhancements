<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Functions;
use KWV\Enhancements\ApplicationContext;
use KWV\Enhancements\ApplicationForm;
use KWV\Enhancements\Careers;
use Mockery;

/**
 * Server-side job context on application submissions.
 *
 * @covers \KWV\Enhancements\ApplicationContext
 */
final class ApplicationContextTest extends TestCase {

	/** @var \Mockery\MockInterface&Careers */
	private $careers;

	/** @var ApplicationContext */
	private $context;

	protected function set_up(): void {
		parent::set_up();
		$this->careers = Mockery::mock( Careers::class );
		$this->context = new ApplicationContext( $this->careers );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_post_type' )->alias(
			static function ( $id ) {
				return in_array( (int) $id, array( 41, 42 ), true ) ? 'kwv_career' : 'page';
			}
		);
	}

	/**
	 * The application form's shape: visible fields plus the job hidden fields.
	 */
	private function form(): array {
		return array(
			'id'     => 1,
			'fields' => array(
				kwv_test_gf_field(
					array(
						'id'    => 2,
						'type'  => 'text',
						'label' => 'Name',
					)
				),
				kwv_test_gf_field(
					array(
						'id'        => 17,
						'type'      => 'hidden',
						'inputName' => 'job_id',
					)
				),
				kwv_test_gf_field(
					array(
						'id'        => 15,
						'type'      => 'hidden',
						'inputName' => 'job_title',
					)
				),
				kwv_test_gf_field(
					array(
						'id'        => 16,
						'type'      => 'hidden',
						'inputName' => 'job_ref',
					)
				),
				kwv_test_gf_field(
					array(
						'id'        => 30,
						'type'      => 'hidden',
						'inputName' => 'utm_source',
					)
				),
				kwv_test_gf_field(
					array(
						'id'        => 31,
						'type'      => 'text',
						'inputName' => 'job_title',
					)
				),
			),
		);
	}

	public function test_hooks_wait_for_init(): void {
		$this->context->register();

		$this->assertNotFalse( has_action( 'init', 'KWV\Enhancements\ApplicationContext->register_hooks()' ) );
	}

	public function test_register_hooks_wires_gravity_forms_filters(): void {
		$this->context->register_hooks();

		$this->assertNotFalse( has_filter( 'gform_validation', 'KWV\Enhancements\ApplicationContext->validate()' ) );
		$this->assertNotFalse( has_filter( 'gform_validation_message', 'KWV\Enhancements\ApplicationContext->validation_message()' ) );
		$this->assertNotFalse( has_action( 'gform_pre_submission', 'KWV\Enhancements\ApplicationContext->fill()' ) );
		$this->assertNotFalse( has_filter( 'gform_pre_send_email', 'KWV\Enhancements\ApplicationContext->tidy_hr_email()' ) );
	}

	public function test_an_application_to_a_closed_job_is_refused(): void {
		$_POST['input_17'] = '41';
		$this->careers->shouldReceive( 'is_open' )->with( 41 )->andReturn( false );
		$form = $this->form();

		$result = $this->context->validate(
			array(
				'is_valid' => true,
				'form'     => $form,
			)
		);

		$this->assertFalse( $result['is_valid'] );
		$this->assertTrue( $form['fields'][1]->failed_validation );
		$this->assertSame( 'Applications for this position have closed.', $form['fields'][1]->validation_message );
		$this->assertStringContainsString( 'applications for this position have closed', $this->context->validation_message( '<p>generic</p>', $form ) );
	}

	public function test_an_application_to_an_open_job_passes(): void {
		$_POST['input_17'] = '41';
		$this->careers->shouldReceive( 'is_open' )->with( 41 )->andReturn( true );

		$result = $this->context->validate(
			array(
				'is_valid' => true,
				'form'     => $this->form(),
			)
		);

		$this->assertTrue( $result['is_valid'] );
		$this->assertSame( '<p>generic</p>', $this->context->validation_message( '<p>generic</p>', $this->form() ) );
	}

	/**
	 * @dataProvider not_a_job
	 *
	 * @param string $posted Posted Job ID.
	 */
	public function test_a_general_application_is_not_checked_against_a_job( string $posted ): void {
		$_POST['input_17'] = $posted;
		$this->careers->shouldNotReceive( 'is_open' );

		$result = $this->context->validate(
			array(
				'is_valid' => true,
				'form'     => $this->form(),
			)
		);

		$this->assertTrue( $result['is_valid'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function not_a_job(): array {
		return array(
			'empty'        => array( '' ),
			'a page id'    => array( '7' ),
			'not a number' => array( 'abc' ),
			'zero'         => array( '0' ),
		);
	}

	public function test_forms_without_a_job_field_are_ignored(): void {
		$this->careers->shouldNotReceive( 'is_open' );
		$form = array( 'fields' => array( kwv_test_gf_field( array( 'id' => 1 ) ) ) );

		$this->assertTrue(
			$this->context->validate(
				array(
					'is_valid' => true,
					'form'     => $form,
				)
			)['is_valid']
		);
	}

	public function test_fill_overwrites_browser_values_with_the_real_job_details(): void {
		$_POST = array(
			'input_17' => '42',
			'input_15' => '<script>Forged title</script>',
			'input_16' => 'FAKE',
			'input_30' => 'newsletter',
			'input_31' => 'visible field, untouched',
		);
		$this->careers->shouldReceive( 'job_details' )->with( 42 )->andReturn(
			array(
				'job_id'    => '42',
				'job_title' => 'Cellar Hand',
				'job_ref'   => 'BMMS0128',
			)
		);

		$this->context->fill( $this->form() );

		$this->assertSame( '42', $_POST['input_17'] );
		$this->assertSame( 'Cellar Hand', $_POST['input_15'] );
		$this->assertSame( 'BMMS0128', $_POST['input_16'] );
		$this->assertSame( 'newsletter', $_POST['input_30'], 'Hidden fields outside the job contract are left alone.' );
		$this->assertSame( 'visible field, untouched', $_POST['input_31'], 'Only hidden fields are filled.' );
	}

	public function test_fill_blanks_the_details_of_a_general_application(): void {
		$_POST = array(
			'input_17' => '7',
			'input_15' => 'Forged',
		);
		$this->careers->shouldReceive( 'job_details' )->with( 0 )->andReturn( array() );

		$this->context->fill( $this->form() );

		$this->assertSame( '', $_POST['input_15'] );
		$this->assertSame( '', $_POST['input_17'] );
	}

	public function test_hr_subject_drops_an_empty_reference_and_text_links_are_decoded(): void {
		$email = array(
			'subject' => 'Job application: Cellar Hand (Ref ) — Jane Doe',
			'message' => 'View entry: https://example.org/wp-admin/admin.php?page=gf_entries&#038;view=entry',
		);

		$tidied = $this->context->tidy_hr_email( $email, 'text', array( 'id' => ApplicationForm::HR_NOTIFICATION ) );

		$this->assertSame( 'Job application: Cellar Hand — Jane Doe', $tidied['subject'] );
		$this->assertStringContainsString( 'page=gf_entries&view=entry', $tidied['message'] );
	}

	public function test_hr_subject_with_no_job_drops_the_dangling_colon(): void {
		$tidied = $this->context->tidy_hr_email(
			array(
				'subject' => 'Job application:  (Ref ) — Jane Doe',
				'message' => 'x',
			),
			'html',
			array( 'id' => ApplicationForm::HR_NOTIFICATION )
		);

		$this->assertSame( 'Job application — Jane Doe', $tidied['subject'] );
	}

	public function test_html_hr_messages_keep_their_entities(): void {
		$tidied = $this->context->tidy_hr_email(
			array(
				'subject' => 'Job application: X (Ref 1) — Y',
				'message' => 'a&#038;b',
			),
			'html',
			array( 'id' => ApplicationForm::HR_NOTIFICATION )
		);

		$this->assertSame( 'a&#038;b', $tidied['message'] );
		$this->assertSame( 'Job application: X (Ref 1) — Y', $tidied['subject'] );
	}

	public function test_other_notifications_are_untouched(): void {
		$email = array(
			'subject' => 'Thanks (Ref )',
			'message' => 'a&#038;b',
		);

		$this->assertSame( $email, $this->context->tidy_hr_email( $email, 'text', array( 'id' => ApplicationForm::USER_NOTIFICATION ) ) );
	}
}
