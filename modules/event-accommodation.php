<?php
/**
 * Event Accommodation module — the "Accommodation nearby" cards on a single
 * event.
 *
 * Larger events can list places to stay (Booking.com, Airbnb, LekkeSlaap, …) in
 * the event's "Accommodation nearby" SCF repeater (acf-json/group_kwv_events.json).
 * This module registers the dynamic `kwv/event-accommodation` block that the
 * theme places on the single-event template: it renders a heading and a grid of
 * linked cards when the event has entries, and nothing at all when it has none.
 *
 * The cards are emitted as core block markup through do_blocks(), so the theme
 * styles them with its existing tokens and its "Event Card" section style
 * (is-style-event-card) and "Image Hover Zoom" image style — no CSS here. Block
 * style variations with nested styles are generated per rendered block, which
 * is why the markup goes through the block parser rather than being echoed.
 *
 * @package KWV_Enhancements
 */

namespace KWV\Enhancements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the kwv/event-accommodation block.
 */
class EventAccommodation implements Module {

	/**
	 * The SCF repeater field name (= its meta key).
	 *
	 * SCF stores a repeater as a row count under this key and each sub-field as
	 * `{field}_{row}_{sub_field}` (e.g. kwv_event_accommodation_0_url), so the
	 * values are read as plain post meta and still render without SCF active.
	 * The per-row keys are dynamic, so they are not registered in
	 * Events::meta_fields().
	 */
	public const FIELD = 'kwv_event_accommodation';

	/**
	 * The block's directory, relative to the plugin root.
	 */
	public const BLOCK_DIR = 'blocks/event-accommodation';

	/**
	 * Known booking platforms: a host fragment => the display name.
	 *
	 * A link whose host contains the fragment is labelled "via {name}"; any
	 * other link is labelled with its bare host (e.g. "via example.co.za").
	 */
	private const PLATFORMS = array(
		'booking.com' => 'Booking.com',
		'airbnb.'     => 'Airbnb',
		'lekkeslaap.' => 'LekkeSlaap',
	);

	/**
	 * Register the block on init.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register kwv/event-accommodation from its block.json, rendered by
	 * {@see render()}.
	 */
	public function register_block(): void {
		register_block_type(
			KWV_ENH_DIR . self::BLOCK_DIR,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * The event's accommodation entries, in the order the editor arranged them.
	 *
	 * Rows without a valid http(s) link or a name are skipped, and an image that
	 * is no longer an image attachment is dropped (the card falls back to the
	 * placeholder panel).
	 *
	 * @param int $post_id The event post ID.
	 * @return array<int,array{url:string,title:string,image_id:int,platform:string}>
	 */
	public function items( int $post_id ): array {

		$count = (int) get_post_meta( $post_id, self::FIELD, true );
		$items = array();

		for ( $row = 0; $row < $count; $row++ ) {
			$prefix = self::FIELD . '_' . $row . '_';

			$url      = esc_url_raw( trim( (string) get_post_meta( $post_id, $prefix . 'url', true ) ), array( 'http', 'https' ) );
			$title    = trim( (string) get_post_meta( $post_id, $prefix . 'title', true ) );
			$image_id = (int) get_post_meta( $post_id, $prefix . 'image', true );

			if ( '' === $url || '' === $title ) {
				continue;
			}

			$items[] = array(
				'url'      => $url,
				'title'    => $title,
				'image_id' => ( $image_id && wp_attachment_is_image( $image_id ) ) ? $image_id : 0,
				'platform' => $this->platform_label( $url ),
			);
		}

		return $items;
	}

	/**
	 * The platform name for a listing link: a known platform's display name, or
	 * the link's host without a leading "www.".
	 *
	 * @param string $url The listing URL.
	 * @return string
	 */
	public function platform_label( string $url ): string {

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		foreach ( self::PLATFORMS as $fragment => $name ) {
			if ( false !== strpos( $host, $fragment ) ) {
				return $name;
			}
		}

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Render the block: the "Accommodation nearby" section for the current
	 * event, or nothing when it has no entries.
	 *
	 * In the editor (the ServerSideRender REST request) an empty event — or a
	 * template with no event in context — shows a short note instead, so the
	 * block stays visible and selectable.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Inner content (unused; the block is dynamic).
	 * @param \WP_Block $block      The block instance, for post context.
	 * @return string
	 */
	public function render( $attributes, $content, $block ): string {

		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$items   = $post_id ? $this->items( $post_id ) : array();

		if ( ! $items ) {
			return wp_is_serving_rest_request() ? $this->editor_placeholder() : '';
		}

		$heading_id = wp_unique_id( 'kwv-event-accommodation-' );

		$cards = '';
		foreach ( $items as $item ) {
			$cards .= $this->card_markup( $item );
		}

		// The grid's blockGap must stay a single value: with minimumColumnWidth,
		// core folds the gap into its column calc(), and a {top,left} pair yields
		// invalid CSS (one stacked column).
		$markup = '<!-- wp:group {"metadata":{"name":"Accommodation Nearby"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"default"}} -->'
			. '<div class="wp-block-group">'
			. '<!-- wp:heading {"textColor":"contrast"} -->'
			. '<h2 class="wp-block-heading has-contrast-color has-text-color" id="' . esc_attr( $heading_id ) . '">' . esc_html__( 'Accommodation nearby', 'kwv-enhancements' ) . '</h2>'
			. '<!-- /wp:heading -->'
			. '<!-- wp:group {"metadata":{"name":"Accommodation Grid"},"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":"14rem"}} -->'
			. '<div class="wp-block-group">' . $cards . '</div>'
			. '<!-- /wp:group -->'
			. '</div>'
			. '<!-- /wp:group -->';

		return sprintf(
			'<section %1$s>%2$s</section>',
			get_block_wrapper_attributes(
				array(
					'class'           => 'kwv-event-accommodation',
					'aria-labelledby' => $heading_id,
				)
			),
			do_blocks( $markup )
		);
	}

	/**
	 * One accommodation card as block markup: the image (or a placeholder
	 * panel), the linked name and a "via {platform}" line.
	 *
	 * Links open in a new tab (announced to screen readers) and carry
	 * rel="nofollow" — they are third-party listings, not endorsements. The image
	 * link repeats the title link, so it is hidden from assistive tech and the
	 * tab order; the title is the one accessible link per card.
	 *
	 * @param array{url:string,title:string,image_id:int,platform:string} $item An entry from {@see items()}.
	 * @return string
	 */
	private function card_markup( array $item ): string {

		$url = esc_url( $item['url'] );
		$rel = 'noopener noreferrer nofollow';

		if ( $item['image_id'] ) {
			$image = wp_get_attachment_image(
				$item['image_id'],
				'large',
				false,
				array(
					'class'   => 'wp-image-' . $item['image_id'],
					'alt'     => '',
					'style'   => 'aspect-ratio:4/5;object-fit:cover',
					// Always at the foot of the page — never the LCP image.
					'loading' => 'lazy',
				)
			);

			$media = '<!-- wp:image ' . serialize_block_attributes(
				array(
					'id'              => $item['image_id'],
					'sizeSlug'        => 'large',
					'linkDestination' => 'custom',
					'aspectRatio'     => '4/5',
					'scale'           => 'cover',
					'className'       => 'is-style-image-hover-zoom',
				)
			) . ' -->'
				. '<figure class="wp-block-image size-large is-style-image-hover-zoom">'
				. '<a href="' . $url . '" target="_blank" rel="' . esc_attr( $rel ) . '" tabindex="-1" aria-hidden="true" style="display:block">' . $image . '</a>'
				. '</figure>'
				. '<!-- /wp:image -->';
		} else {
			// No image: a tinted brand panel naming the platform. Decorative —
			// the "via …" line below already says the same thing.
			$media = '<!-- wp:group {"className":"kwv-event-accommodation__placeholder","backgroundColor":"brand-100","layout":{"type":"flex","orientation":"vertical","justifyContent":"center","verticalAlignment":"center"}} -->'
				. '<div class="wp-block-group kwv-event-accommodation__placeholder has-brand-100-background-color has-background" style="aspect-ratio:4/5" aria-hidden="true">'
				. '<!-- wp:paragraph {"textColor":"brand-800","fontSize":"400","fontFamily":"accent"} -->'
				. '<p class="has-brand-800-color has-text-color has-accent-font-family has-400-font-size">' . esc_html( $item['platform'] ) . '</p>'
				. '<!-- /wp:paragraph -->'
				. '</div>'
				. '<!-- /wp:group -->';
		}

		return '<!-- wp:group {"metadata":{"name":"Accommodation Card"},"className":"is-style-event-card","style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","flexWrap":"nowrap"}} -->'
			. '<div class="wp-block-group is-style-event-card">'
			. '<!-- wp:group {"metadata":{"name":"Media"},"className":"event-card__media","style":{"spacing":{"blockGap":"0","padding":{"top":"0","right":"0","bottom":"0","left":"0"}}},"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group event-card__media" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0">' . $media . '</div>'
			. '<!-- /wp:group -->'
			. '<!-- wp:heading {"level":3,"textColor":"contrast","fontSize":"200","fontFamily":"heading","style":{"typography":{"fontWeight":"var:custom|font-weight|regular","lineHeight":"var:custom|line-height|heading","textTransform":"none"},"spacing":{"margin":{"top":"0","bottom":"0"}}}} -->'
			. '<h3 class="wp-block-heading has-contrast-color has-text-color has-heading-font-family has-200-font-size" style="margin-top:0;margin-bottom:0;font-weight:var(--wp--custom--font-weight--regular);line-height:var(--wp--custom--line-height--heading);text-transform:none">'
			. '<a href="' . $url . '" target="_blank" rel="' . esc_attr( $rel ) . '">' . esc_html( $item['title'] )
			. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'kwv-enhancements' ) . '</span></a>'
			. '</h3>'
			. '<!-- /wp:heading -->'
			. '<!-- wp:paragraph {"textColor":"neutral-700","fontSize":"100","style":{"spacing":{"margin":{"top":"0","bottom":"0"}}}} -->'
			. '<p class="has-neutral-700-color has-text-color has-100-font-size" style="margin-top:0;margin-bottom:0">'
			/* translators: %s: booking platform or website, e.g. "Airbnb". */
			. esc_html( sprintf( __( 'via %s', 'kwv-enhancements' ), $item['platform'] ) )
			. '</p>'
			. '<!-- /wp:paragraph -->'
			. '</div>'
			. '<!-- /wp:group -->';
	}

	/**
	 * The editor-only note shown when there is nothing to render.
	 *
	 * @return string
	 */
	private function editor_placeholder(): string {
		return sprintf(
			'<div %1$s><p><strong>%2$s</strong><br>%3$s</p></div>',
			get_block_wrapper_attributes( array( 'class' => 'kwv-event-accommodation is-placeholder' ) ),
			esc_html__( 'Accommodation nearby', 'kwv-enhancements' ),
			esc_html__( 'Shows on events with entries in their "Accommodation nearby" fields. Hidden when an event has none.', 'kwv-enhancements' )
		);
	}
}
