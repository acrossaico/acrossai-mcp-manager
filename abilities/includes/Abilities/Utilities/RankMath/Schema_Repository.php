<?php
/**
 * Feature 069 — Rank Math effective schema / publisher output.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only reader for the global publisher entity Rank Math emits.
 *
 * Distinct from reading the titles-local-seo settings panel, which returns the RAW
 * stored fields. This returns the RESOLVED output: which schema type will actually be
 * emitted, the @id it will carry, and the sameAs list assembled from the individual
 * social fields. Those are computed, not stored, so they cannot be read from settings.
 */
final class Schema_Repository {

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The effective publisher / knowledge-graph output.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$titles = array();
		if ( class_exists( '\RankMath\Helper' ) ) {
			$stored = \RankMath\Helper::get_settings( 'titles' );
			$titles = is_array( $stored ) ? $stored : array();
		}

		$type      = isset( $titles['knowledgegraph_type'] ) ? (string) $titles['knowledgegraph_type'] : 'person';
		$is_company = 'company' === $type;

		return array(
			// What Rank Math stores versus what it emits: 'company' becomes
			// schema.org/Organization, anything else becomes Person.
			'configured_type'          => $type,
			'effective_schema_type'    => $is_company ? 'Organization' : 'Person',
			'effective_id'             => home_url( $is_company ? '/#organization' : '/#person' ),
			'publisher_name'           => (string) ( $titles['knowledgegraph_name'] ?? '' ),
			'website_name'             => (string) ( $titles['website_name'] ?? '' ),
			'website_alternate_name'   => (string) ( $titles['website_alternate_name'] ?? '' ),
			'organization_description' => (string) ( $titles['organization_description'] ?? '' ),
			'url'                      => (string) ( $titles['url'] ?? '' ),
			'logo'                     => array(
				'url' => (string) ( $titles['knowledgegraph_logo'] ?? '' ),
				'id'  => (int) ( $titles['knowledgegraph_logo_id'] ?? 0 ),
			),
			'same_as'                  => self::same_as( $titles ),
			'local_seo'                => array(
				'module_active'  => class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_module_active( 'local-seo' ),
				'business_type'  => (string) ( $titles['local_business_type'] ?? '' ),
				'email'          => (string) ( $titles['email'] ?? '' ),
				'phone'          => (string) ( $titles['phone'] ?? '' ),
				'phone_numbers'  => isset( $titles['phone_numbers'] ) && is_array( $titles['phone_numbers'] ) ? array_values( $titles['phone_numbers'] ) : array(),
				'address'        => isset( $titles['local_address'] ) && is_array( $titles['local_address'] ) ? $titles['local_address'] : array(),
				'address_format' => (string) ( $titles['local_address_format'] ?? '' ),
				'opening_hours'  => isset( $titles['opening_hours'] ) && is_array( $titles['opening_hours'] ) ? array_values( $titles['opening_hours'] ) : array(),
				'price_range'    => (string) ( $titles['price_range'] ?? '' ),
				'geo'            => (string) ( $titles['geo'] ?? '' ),
			),
			'twitter_card_type'        => (string) ( $titles['twitter_card_type'] ?? '' ),
			'open_graph_image'         => (string) ( $titles['open_graph_image'] ?? '' ),
		);
	}

	/**
	 * The sameAs list Rank Math assembles from the separate social fields.
	 *
	 * social_additional_profiles is newline-separated, which is exactly why it must be
	 * stored as a textarea rather than a single-line text field — see research F2.
	 *
	 * @param array<string,mixed> $titles Titles settings blob.
	 * @return string[]
	 */
	private static function same_as( array $titles ): array {
		$profiles = array();

		$facebook = (string) ( $titles['social_url_facebook'] ?? '' );
		if ( '' !== $facebook ) {
			$profiles[] = $facebook;
		}

		$twitter = ltrim( (string) ( $titles['twitter_author_names'] ?? '' ), '@' );
		if ( '' !== $twitter ) {
			$profiles[] = 'https://twitter.com/' . $twitter;
		}

		$additional = (string) ( $titles['social_additional_profiles'] ?? '' );
		if ( '' !== $additional ) {
			foreach ( (array) preg_split( '/\r\n|\r|\n/', $additional ) as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$profiles[] = $line;
				}
			}
		}

		return array_values( array_unique( $profiles ) );
	}
}
