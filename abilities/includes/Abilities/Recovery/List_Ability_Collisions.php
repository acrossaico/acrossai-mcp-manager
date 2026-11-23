<?php
/**
 * Issue #202 — reports ability names claimed by more than one plugin.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Ability_Collision_Recorder;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a silent registration race into a fact an operator can read.
 *
 * Lives with the Recovery abilities rather than in a folder of its own: it shares their category and
 * their job — explaining why a site is behaving in a way its configuration does not account for. A
 * separate folder would have needed its own category registrar, and an ability whose category is
 * never registered is dropped by WP without a word (BUG-WP-CORE-ABILITY-CATEGORY-PRE-REGISTRATION).
 *
 * @since 0.0.34
 */
class List_Ability_Collisions extends Ability_Definition {

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'diagnostics/list-ability-collisions',
			'args' => array(
				'label'               => __( 'List Ability Name Collisions', 'acrossai-abilities-manager' ),
				'description'         => __(
					'Report ability names that more than one plugin tried to register. WordPress refuses a duplicate name: the first registration wins and the second is discarded with no runtime signal, so a plugin can silently lose an ability to another plugin, or a placeholder can displace a real implementation. Which side wins depends only on hook order, so two sites with the same plugins can resolve differently. Each row names the ability, how many registrations were attempted, the label that is actually live, and the labels that lost. An empty result means no clash was seen on this request.',
					'acrossai-abilities-manager'
				),
				'category'            => 'acrossai-recovery',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'collisions' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
							'description' => __( 'One row per contested ability name: the ability, how many registrations were attempted, how many WordPress refused outright, the label now live, the labels discarded, and in "lost_by" the plugin directories whose registration was refused. Read lost_by first — two plugins implementing the same ability usually label it identically, so the labels often cannot tell them apart while lost_by always can. A refused count above zero means a plugin is losing this ability right now, decided by load order alone.', 'acrossai-abilities-manager' ),
						),
						'count'      => array( 'type' => 'integer' ),
						'watched'    => array(
							'type'        => 'integer',
							'description' => __( 'How many distinct ability names were seen registering on this request.', 'acrossai-abilities-manager' ),
						),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'recovery',
						'sub_group_label' => __( 'Recovery Mode', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Unused.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		/*
		 * A zero here has two very different meanings, and reporting them the same way would make the
		 * ability useless precisely when something is wrong: "nothing collided" versus "nothing was
		 * watched, because the recorder was never attached".
		 */
		if ( ! Ability_Collision_Recorder::is_listening() ) {
			return array(
				'success'    => false,
				'error_code' => 'recorder_inactive',
				'message'    => __( 'The collision recorder was not attached for this request, so no conclusion can be drawn. It hooks wp_register_ability_args at priority 1; something has prevented that.', 'acrossai-abilities-manager' ),
			);
		}

		$collisions = Ability_Collision_Recorder::collisions();
		$watched    = Ability_Collision_Recorder::attempted_count();

		if ( empty( $collisions ) ) {
			return array(
				'success'    => true,
				'collisions' => array(),
				'count'      => 0,
				'watched'    => $watched,
				'message'    => sprintf(
					/* translators: %d: number of ability names seen. */
					__( 'No name clashes. %d ability names registered, each claimed once.', 'acrossai-abilities-manager' ),
					$watched
				),
			);
		}

		return array(
			'success'    => true,
			'collisions' => $collisions,
			'count'      => count( $collisions ),
			'watched'    => $watched,
			'message'    => sprintf(
				/* translators: 1: number of contested names, 2: number seen. */
				_n(
					'%1$d ability name is claimed by more than one registrant, out of %2$d seen. The label shown as the winner is the one actually live; the losers were discarded.',
					'%1$d ability names are claimed by more than one registrant, out of %2$d seen. The label shown as the winner is the one actually live; the losers were discarded.',
					count( $collisions ),
					'acrossai-abilities-manager'
				),
				count( $collisions ),
				$watched
			),
		);
	}
}
