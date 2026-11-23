<?php
/**
 * Feature 069 — read a Rank Math settings panel with its field specification.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Rank_Math_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #1 — rank-math/get-settings-panel.
 *
 * NOT `rank-math/get-settings`. Rank Math itself claimed that name in 1.0.277 for a
 * whole-site snapshot taking a `sections` array. Both registered it, the first registrant
 * won by hook order alone, and a caller following Rank Math's documented input hit this
 * ability's `panel` schema instead. The host plugin owns its own namespace, so this moved
 * (PATTERN-NEVER-SHADOW-A-HOST-ABILITY) and both now coexist: theirs for the snapshot,
 * this one for a single panel WITH its field specification, which is the part their
 * snapshot does not carry and which every writer in this suite depends on.
 *
 * One reader for all 20 panels. Reads carry no validation risk, and returning the
 * field specification alongside the values is what makes each writer's accepted
 * keys, types, enums and bounds discoverable at runtime — which is how we buy back
 * the schema autocomplete lost by putting `settings` behind a plain object on the
 * write side.
 *
 * Materially different from a raw option read: that returns an untyped blob with
 * no defaults, no panel structure, and no indication of which keys are writable.
 *
 * CAPABILITY NOTE — this is the only ability in the suite with a runtime
 * capability check, and it is deliberate. permission_callback receives no input in
 * this plugin's registration shape, so it cannot branch on `panel`. It is therefore
 * gated at the LEAST privileged combination the ability can require
 * (manage_options AND rank_math_general) and the panel's own capability is
 * re-checked inside run(). A reviewer seeing only the callback would otherwise
 * read this as a hole.
 *
 * Read-only, idempotent.
 */
class Get_Settings_Panel extends Base_Rank_Math_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'get-settings-panel';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Rank Math Settings Panel', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one Rank Math settings panel: every field with its id, type, allowed values, bounds, whether it is read-only, and its current value. Call this before any of the update-*-settings abilities to discover exactly which field ids that panel accepts — submitting a field that does not belong to the panel rejects the whole write. For a whole-site settings snapshot with no field specification, use Rank Math\'s own rank-math/get-settings instead.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function sub_group(): string {
		return 'rank-math-settings';
	}

	/**
	 * The least-privileged combination this ability can require. The panel's own
	 * capability is re-checked in run() — see the class docblock.
	 *
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'general';
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'panel'  => array(
				'type'        => 'string',
				'enum'        => Settings_Registry::panel_slugs(),
				'description' => __( 'Which settings panel to read.', 'acrossai-abilities-manager' ),
			),
			'object' => array(
				'type'        => 'string',
				'description' => __( 'Post type or taxonomy name. Required for the post-type and taxonomy panels, whose field ids are patterned per object.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'panel'       => array( 'type' => 'string' ),
			'object'      => array( 'type' => 'string' ),
			'option_type' => array( 'type' => 'string' ),
			'source'      => array( 'type' => 'string' ),
			'fields'      => array( 'type' => 'array' ),
			'state'       => array( 'type' => 'object' ),
		);
	}

	/**
	 * @return string[]
	 */
	protected function required_input(): array {
		return array( 'panel' );
	}

	/**
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$panel = isset( $input['panel'] ) ? sanitize_key( (string) $input['panel'] ) : '';

		$definition = Settings_Registry::panel( $panel );
		if ( null === $definition ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: submitted panel slug, 2: comma-separated list of valid panels */
					__( 'Unknown panel "%1$s". Valid panels: %2$s.', 'acrossai-abilities-manager' ),
					$panel,
					implode( ', ', Settings_Registry::panel_slugs() )
				)
			);
		}

		// Runtime capability re-check — see the class docblock.
		$cap = (string) $definition['cap'];
		if ( ! Rank_Math_Guard::has_cap( $cap ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: 1: panel slug, 2: WordPress capability name */
					__( 'Reading panel "%1$s" requires the %2$s capability.', 'acrossai-abilities-manager' ),
					$panel,
					'rank_math_' . str_replace( '-', '_', $cap )
				)
			);
		}

		$object = isset( $input['object'] ) ? sanitize_key( (string) $input['object'] ) : '';

		$result = Settings_Writer::read( $panel, $object );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'general-robots-txt' === $panel ) {
			$result['state'] = self::robots_txt_state();
		}

		$result['message'] = sprintf(
			/* translators: 1: number of fields, 2: panel slug */
			_n( 'Returned %1$d field from the Rank Math "%2$s" panel.', 'Returned %1$d fields from the Rank Math "%2$s" panel.', count( $result['fields'] ), 'acrossai-abilities-manager' ),
			count( $result['fields'] ),
			$panel
		);

		return $result;
	}

	/**
	 * Read-only state that determines whether robots.txt is editable at all.
	 *
	 * A physical robots.txt on disk makes Rank Math's virtual editor inert, and a
	 * non-public site suppresses the output entirely. Reporting this is the
	 * difference between "your write did nothing" and knowing why up front.
	 *
	 * @return array<string,mixed>
	 */
	private static function robots_txt_state(): array {
		$physical = ABSPATH . 'robots.txt';
		$exists   = file_exists( $physical );

		return array(
			'editable'              => ! $exists && (bool) get_option( 'blog_public' ),
			'physical_file_exists'  => $exists,
			'physical_file_path'    => $exists ? $physical : '',
			'site_not_public'       => ! get_option( 'blog_public' ),
		);
	}
}
