<?php
/**
 * Feature 069 — effective Rank Math publisher / schema output.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Schema_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #62 — rank-math/get-schema-status.
 *
 * Distinct from rank-math/get-settings-panel panel=titles-local-seo, which returns
 * the RAW stored fields. This returns the RESOLVED output: which schema type will
 * actually be emitted, the @id it carries, and the sameAs list assembled from the
 * separate social fields — all computed at render time and therefore unreadable from
 * settings.
 *
 * Read-only, idempotent.
 */
class Get_Schema_Status extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-schema-status';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Schema Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return the publisher entity Rank Math will actually emit: the resolved schema type (Organization or Person), the @id it carries, the logo, the assembled sameAs profile list, and the Local SEO contact and opening-hours data. These are computed at render time rather than stored, so they cannot be read from the settings panel. Use rank-math/update-title-settings with scope=local-seo or social to change them.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-schema';
	}

	protected function rank_math_cap(): string {
		return 'titles';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'configured_type'          => array( 'type' => 'string' ),
			'effective_schema_type'    => array( 'type' => 'string' ),
			'effective_id'             => array( 'type' => 'string' ),
			'publisher_name'           => array( 'type' => 'string' ),
			'website_name'             => array( 'type' => 'string' ),
			'website_alternate_name'   => array( 'type' => 'string' ),
			'organization_description' => array( 'type' => 'string' ),
			'url'                      => array( 'type' => 'string' ),
			'logo'                     => array( 'type' => 'object' ),
			'same_as'                  => array( 'type' => 'array' ),
			'local_seo'                => array( 'type' => 'object' ),
			'twitter_card_type'        => array( 'type' => 'string' ),
			'open_graph_image'         => array( 'type' => 'string' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$status = Schema_Repository::status();

		$status['message'] = '' === $status['publisher_name']
			? sprintf(
				/* translators: %s: resolved schema type */
				__( 'Rank Math will emit a %s publisher entity, but no publisher name is set — search engines will have an incomplete knowledge-graph entry.', 'acrossai-abilities-manager' ),
				$status['effective_schema_type']
			)
			: sprintf(
				/* translators: 1: resolved schema type, 2: publisher name */
				__( 'Rank Math will emit a %1$s publisher entity for "%2$s".', 'acrossai-abilities-manager' ),
				$status['effective_schema_type'],
				$status['publisher_name']
			);

		return $status;
	}
}
