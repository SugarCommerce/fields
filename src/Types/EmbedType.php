<?php
/**
 * Embed Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Embeds\Embeds;
use SugarCommerce\Embeds\Models\Platform;
use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * A link to a video or a track, with the player it becomes beside it.
 *
 * The link is what is stored; the player is `sugarcommerce/embeds`', which
 * knows a closed list of platforms, matches their hosts exactly and builds
 * the player's address from the id it finds rather than from what was
 * pasted. A link it cannot frame is refused on save with the platforms it
 * can, so a product's preview is never a link that draws an empty box.
 *
 * The preview follows as somebody types: the script asks the embed
 * endpoint, which makes no request of its own and answers with the iframe
 * the same class would print on the page. `platforms` limits the field
 * (`[ 'youtube', 'vimeo' ]` for video only); `preview => false` leaves the
 * player off.
 *
 * The embeds package is suggested rather than required, so a plugin with no
 * embed field does not carry it. Without it the field is a link with no
 * player, and any link is taken.
 */
final class EmbedType extends AbstractInputType {

	/**
	 * What a link may be to, when the field does not say.
	 *
	 * The platforms the embeds package can draw a player for.
	 */
	public const array PLATFORMS = [ 'youtube', 'vimeo', 'soundcloud', 'spotify', 'bandcamp' ];

	/**
	 * The type's id.
	 *
	 * @return string
	 */
	#[\Override]
	public function id(): string {
		return 'embed';
	}

	/**
	 * A url input.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'url';
	}

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [ 'preview' => true ];
	}

	/**
	 * Render the link and the player.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$platforms = self::platforms( (array) $field->get( 'platforms', [] ) );

		$attributes->add_class( 'field-kit__embed-url' );
		$attributes->set_if( '' === $field->placeholder(), 'placeholder', 'https://' );

		$input = parent::render( $field, $attributes );

		if ( ! $field->get( 'preview', true ) ) {
			return $input;
		}

		$preview = self::preview( $this->render_value( $field ), $platforms, $field->label() );

		return sprintf(
			'<div class="field-kit__embed" data-embed-platforms="%s" data-embed-title="%s">%s<div class="field-kit__embed-preview"%s>%s</div><span class="field-kit__embed-status description" aria-live="polite"></span></div>',
			esc_attr( implode( ',', $platforms ) ),
			esc_attr( $field->label() ),
			$input,
			null === $preview ? ' hidden' : '',
			null === $preview ? '' : $preview['html']
		);
	}

	/**
	 * A clean URL, or nothing.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): string {
		return esc_url_raw( trim( $this->scalar( $value ) ) );
	}

	/**
	 * Refuse a link no player can be made from.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function validate( mixed $value, Field $field ): string {
		$message = parent::validate( $value, $field );

		if ( '' !== $message || '' === $value ) {
			return $message;
		}

		// Without the embeds package there is nothing to tell a player from
		// a page, so the link is taken as a link.
		if ( ! class_exists( Embeds::class ) ) {
			return '';
		}

		$platforms = self::platforms( (array) $field->get( 'platforms', [] ) );

		return null === self::preview( (string) $value, $platforms ) ? self::refusal( $platforms ) : '';
	}

	/**
	 * The player a link becomes, or null when it cannot be framed.
	 *
	 * @param string   $url       The link.
	 * @param string[] $platforms Platform ids allowed; empty for every one.
	 * @param string   $title     What the player is of, for its title.
	 *
	 * @return array{html: string, platform: string, label: string, url: string}|null
	 */
	public static function preview( string $url, array $platforms = [], string $title = '' ): ?array {
		if ( '' === trim( $url ) || ! class_exists( Embeds::class ) ) {
			return null;
		}

		$only  = self::cases( $platforms );
		$embed = Embeds::embed( $url, $only );

		if ( null === $embed ) {
			return null;
		}

		return [
			'html'     => $embed->html( $title, [ 'class' => 'field-kit__embed-frame' ] ),
			'platform' => $embed->platform->value,
			'label'    => $embed->platform->label(),
			'url'      => $embed->url,
		];
	}

	/**
	 * Why a link was refused, naming what would be taken.
	 *
	 * @param string[] $platforms Platform ids allowed; empty for every one.
	 *
	 * @return string
	 */
	public static function refusal( array $platforms = [] ): string {
		$names = array_map( static fn( Platform $platform ): string => $platform->label(), self::cases( $platforms ) );

		return sprintf(
			/* translators: %s: platform names, such as "YouTube, Vimeo" */
			__( 'That link cannot be played here. Paste a link to %s.', 'sugarcommerce' ),
			implode( ', ', $names )
		);
	}

	/**
	 * The platform ids a field allows, of the ones it asked for.
	 *
	 * @param array<int, string> $asked The field's `platforms`; empty for every one.
	 *
	 * @return string[]
	 */
	public static function platforms( array $asked ): array {
		$asked = array_map( 'strval', $asked );

		return array_values( [] === $asked ? self::PLATFORMS : array_intersect( self::PLATFORMS, $asked ) );
	}

	/**
	 * Platform ids as the embeds package's cases, never an empty list.
	 *
	 * @param string[] $platforms Platform ids.
	 *
	 * @return Platform[]
	 */
	private static function cases( array $platforms ): array {
		$cases = [];

		if ( ! class_exists( Platform::class ) ) {
			return [];
		}

		foreach ( [] === $platforms ? self::PLATFORMS : $platforms as $id ) {
			$case = Platform::tryFrom( (string) $id );

			if ( null !== $case && $case->embeddable() ) {
				$cases[] = $case;
			}
		}

		return [] === $cases ? array_map( static fn( string $id ): Platform => Platform::from( $id ), self::PLATFORMS ) : $cases;
	}

	/**
	 * Not in a list table's row: a player does not fit one.
	 *
	 * @return bool
	 */
	#[\Override]
	public function supports_inline(): bool {
		return false;
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'platforms', 'preview' ] );
	}
}
