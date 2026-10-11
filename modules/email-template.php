<?php
/**
 * Branded HTML email builder.
 *
 * Reproduces the KWV email shell the Contact and Booking form notifications use
 * (dark header with the white KWV logo, Georgia heading, label/value details
 * table, gold CTA, quiet footer) so plugin-provisioned notifications match
 * them. Those notifications were provisioned once from a now-retired installer
 * and are owned by the database; this class is the code copy of that look.
 *
 * Everything is inline-styled table markup — email clients ignore <style>
 * blocks and modern layout. Strings passed in are escaped here; Gravity Forms
 * merge tags ({Name:2}) survive escaping and are resolved at send time.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the pieces of a branded KWV email.
 */
class EmailTemplate {

	/**
	 * Attachment ID of the white KWV logo PNG (884×584). PNG rather than the
	 * site's SVG logo because most email clients do not render SVG.
	 */
	public const LOGO_ATTACHMENT_ID = 41202;

	private const FONT_SANS  = 'Helvetica,Arial,sans-serif';
	private const FONT_SERIF = "Georgia,'Times New Roman',serif";

	/**
	 * Wrap body markup in the full branded document.
	 *
	 * @param string $preheader   Inbox preview text (hidden in the email body).
	 * @param string $body        Body markup, built from the other methods.
	 * @param string $footer_note Small print at the foot of the email.
	 * @return string
	 */
	public function document( string $preheader, string $body, string $footer_note ): string {

		$sans  = self::FONT_SANS;
		$serif = self::FONT_SERIF;

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="X-UA-Compatible" content="IE=edge"><title>KWV</title></head>'
			. '<body style="margin:0;padding:0;background-color:#f4f1ea;">'
			. '<span style="display:none!important;visibility:hidden;opacity:0;height:0;width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f1ea;">' . esc_html( $preheader ) . '</span>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border:1px solid #eae4d6;border-radius:4px;">'
			. '<tr><td align="center" style="background-color:#1c1c1c;padding:32px 32px 32px;border-radius:4px 4px 0 0;"><a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;">' . $this->logo() . '</a></td></tr>'
			. '<tr><td style="padding:36px 34px 12px;">' . $body . '</td></tr>'
			. '<tr><td style="padding:26px 34px 34px;">'
			. '<div style="height:1px;background-color:#eae4d6;line-height:1px;font-size:1px;margin:0 0 22px;">&nbsp;</div>'
			. '<p style="margin:0 0 8px;font-family:' . $serif . ';font-size:14px;letter-spacing:4px;color:#1c1c1c;">KWV</p>'
			. '<p style="margin:0 0 10px;font-family:' . $sans . ';font-size:12px;line-height:18px;color:#8a8a8a;">Paarl, South Africa &middot; Crafting fine wine &amp; spirits since 1918</p>'
			. '<p style="margin:0;font-family:' . $sans . ';font-size:11px;line-height:16px;color:#b3ac9b;">' . esc_html( $footer_note ) . '</p>'
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * The serif heading at the top of the body.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	public function heading( string $text ): string {
		return '<h1 style="margin:0 0 18px;font-family:' . self::FONT_SERIF . ';font-size:25px;line-height:32px;font-weight:400;color:#1c1c1c;">' . esc_html( $text ) . '</h1>';
	}

	/**
	 * A body paragraph.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	public function paragraph( string $text ): string {
		return '<p style="margin:0 0 16px;font-family:' . self::FONT_SANS . ';font-size:15px;line-height:24px;color:#3d3d3d;">' . esc_html( $text ) . '</p>';
	}

	/**
	 * A label/value details table.
	 *
	 * Each row's value may be wrapped in a Gravity Forms conditional shortcode
	 * by passing it in $optional (label => merge tag), so an empty optional value
	 * drops the whole row instead of leaving a blank labelled line.
	 *
	 * @param array<string,string> $rows     Label => value (merge tags allowed).
	 * @param array<string,string> $optional Label => merge tag; the row shows only when that tag is non-empty.
	 * @return string
	 */
	public function details( array $rows, array $optional = array() ): string {

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:22px 0 12px;">';

		foreach ( $rows as $label => $value ) {

			$row = '<tr><td style="padding:11px 14px 11px 0;border-bottom:1px solid #eae4d6;font-family:' . self::FONT_SANS . ';font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8a7a4f;width:42%;vertical-align:top;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:11px 0;border-bottom:1px solid #eae4d6;font-family:' . self::FONT_SANS . ';font-size:15px;line-height:22px;color:#1c1c1c;vertical-align:top;">' . esc_html( $value ) . '</td></tr>';

			if ( isset( $optional[ $label ] ) ) {
				$row = sprintf( '[gravityforms action="conditional" merge_tag="%s" condition="isnot" value=""]%s[/gravityforms]', $optional[ $label ], $row );
			}

			$html .= $row;
		}

		return $html . '</table>';
	}

	/**
	 * The gold call-to-action button.
	 *
	 * @param string $label Button text.
	 * @param string $url   Button link.
	 * @return string
	 */
	public function button( string $label, string $url ): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 12px;"><tr><td align="center" bgcolor="#B29143" style="border-radius:2px;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 32px;font-family:' . self::FONT_SANS . ';font-size:12px;letter-spacing:2px;text-transform:uppercase;font-weight:700;color:#1c1c1c;text-decoration:none;">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * The header logo — the white KWV PNG, or a text wordmark when the
	 * attachment is missing in this environment.
	 *
	 * @return string
	 */
	private function logo(): string {

		$logo_id = (int) apply_filters( 'kwv_enhancements_email_logo_id', self::LOGO_ATTACHMENT_ID );
		$url     = $logo_id > 0 ? wp_get_attachment_url( $logo_id ) : false;

		if ( ! $url ) {
			return '<span style="font-family:' . self::FONT_SERIF . ';font-size:28px;letter-spacing:8px;color:#ffffff;">KWV</span>';
		}

		return '<img src="' . esc_url( $url ) . '" width="160" height="106" alt="KWV" style="display:block;width:160px;max-width:160px;height:auto;margin:0 auto;border:0;outline:none;text-decoration:none;" />';
	}
}
