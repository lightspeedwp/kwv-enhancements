<?php
/**
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use KWV\Enhancements\EmailTemplate;
use KWV\Enhancements\Tests\Support\Snapshots;

/**
 * @covers \KWV\Enhancements\EmailTemplate
 */
final class EmailTemplateTest extends TestCase {

	use Snapshots;

	/** @var EmailTemplate */
	private $email;

	protected function set_up(): void {
		parent::set_up();
		$this->email = new EmailTemplate();
		Functions\when( 'home_url' )->justReturn( 'https://example.org/' );
	}

	public function test_document_escapes_the_preheader_and_footer_but_not_the_body(): void {
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.org/logo.png' );

		$html = $this->email->document( 'Hi <b>there</b>', '<p>Body</p>', 'Sent by <KWV>' );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Hi &lt;b&gt;there&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<p>Body</p>', $html );
		$this->assertStringContainsString( 'Sent by &lt;KWV&gt;', $html );
		$this->assertStringContainsString( '<img src="https://example.org/logo.png"', $html );
		$this->assertStringContainsString( 'href="https://example.org/"', $html );
	}

	public function test_logo_falls_back_to_a_wordmark_when_the_attachment_is_missing(): void {
		Functions\expect( 'wp_get_attachment_url' )->once()->with( EmailTemplate::LOGO_ATTACHMENT_ID )->andReturn( false );

		$html = $this->email->document( '', '', '' );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'letter-spacing:8px;color:#ffffff;">KWV</span>', $html );
	}

	public function test_logo_attachment_is_filterable_and_zero_skips_the_lookup(): void {
		Filters\expectApplied( 'kwv_enhancements_email_logo_id' )->once()->andReturn( 0 );
		Functions\expect( 'wp_get_attachment_url' )->never();

		$this->assertStringNotContainsString( '<img', $this->email->document( '', '', '' ) );
	}

	public function test_heading_and_paragraph_escape_their_text(): void {
		$this->assertStringContainsString( '>A &amp; B</h1>', $this->email->heading( 'A & B' ) );
		$this->assertStringContainsString( '>&lt;script&gt;</p>', $this->email->paragraph( '<script>' ) );
	}

	public function test_details_renders_a_row_per_item_and_wraps_optional_rows_in_a_conditional(): void {
		$html = $this->email->details(
			array(
				'Name'  => '{Name:1}',
				'Phone' => '{Phone:3}',
			),
			array( 'Phone' => '{Phone:3}' )
		);

		$this->assertSame( 2, substr_count( $html, '<tr>' ) );
		$this->assertStringContainsString( '>Name</td>', $html );
		$this->assertStringContainsString( '[gravityforms action="conditional" merge_tag="{Phone:3}" condition="isnot" value=""]<tr>', $html );
		$this->assertStringNotContainsString( 'merge_tag="{Name:1}"', $html );
		$this->assertStringEndsWith( '</table>', $html );
	}

	public function test_button_links_to_the_url_and_escapes_its_label(): void {
		$html = $this->email->button( 'View <entry>', 'https://example.org/?a=1&b=2' );

		$this->assertStringContainsString( 'href="https://example.org/?a=1&b=2"', $html );
		$this->assertStringContainsString( '>View &lt;entry&gt;</a>', $html );
	}

	/**
	 * A complete email built from every part, with and without the logo
	 * image, so any change to the branded shell shows up as a diff.
	 *
	 * @dataProvider logos
	 *
	 * @param string|false $logo_url The logo attachment's URL.
	 * @param string       $name     Snapshot name.
	 */
	public function test_full_email_matches_snapshot( $logo_url, string $name ): void {
		Functions\when( 'wp_get_attachment_url' )->justReturn( $logo_url );

		$body = $this->email->heading( 'Thank you for applying' )
			. $this->email->paragraph( 'We have received your application.' )
			. $this->email->details(
				array(
					'Position' => '{Job Title:15}',
					'Location' => '{Location:19}',
				),
				array( 'Location' => '{Location:19}' )
			)
			. $this->email->button( 'View current vacancies', 'https://example.org/careers/' );

		$this->assertMatchesSnapshot( $this->email->document( 'Your application', $body, 'You are receiving this because you applied.' ), $name );
	}

	public static function logos(): array {
		return array(
			'with logo'    => array( 'https://example.org/logo.png', 'email-with-logo.html' ),
			'without logo' => array( false, 'email-without-logo.html' ),
		);
	}
}
