<?php
/**
 * Feature 106 — Get Configuration Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-first-time-config — Get Configuration Status.
 */
final class Get_First_Time_Config extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-first-time-config';
	}

	protected function ability_label(): string {
		return __( 'Get Configuration Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'How far through Yoast\'s first-time configuration the site has got, and whether the site identity it depends on — person or organisation, name, logo — has actually been filled in. An unfinished configuration is why schema output is often incomplete on sites where everything else looks fine.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/update-knowledge-graph',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'configuration' => array( 'type' => 'object' ),

			'identity' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$type     = (string) Settings_Repository::value( 'company_or_person' );
		$identity = array();

		foreach ( array( 'company_or_person', 'company_name', 'company_logo', 'person_logo', 'company_or_person_user_id' ) as $key ) {
			$identity[] = array(
				'key'   => $key,
				'value' => Settings_Repository::value( $key ),
				'set'   => '' !== (string) Settings_Repository::value( $key ),
			);
		}

		$missing = array_filter( $identity, static fn( array $r ): bool => ! $r['set'] );

		return array(
			'configuration' => array(
				'site_represents' => '' !== $type ? $type : 'not set',
				'complete'        => array() === $missing,
			),
			'identity'      => $identity,
			'message'       => array() === $missing
				? __( 'Site identity is configured.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of unset fields */
					__( '%d identity field(s) are unset, so Yoast\'s schema output will be incomplete.', 'acrossai-abilities-manager' ),
					count( $missing )
				),
		);
	}
}
