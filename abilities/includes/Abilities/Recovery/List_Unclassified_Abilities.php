<?php
/**
 * Issue #255 — reports abilities no toolset claims, and ones nobody has classified.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Recovery
 * @since      0.0.42
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Recovery;

use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Ability_Collision_Recorder;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group_Tagger;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Names the gap that issues #184 and #255 both sat in undetected.
 *
 * Both were the same failure: a plugin we integrate with shipped abilities of its own, nothing
 * claimed their name prefix, and every one of them fell into the catch-all. Rank Math's tab read 61
 * of 87 for months; Elementor's read 81 of 106. Neither site reported anything, because nothing
 * looked.
 *
 * A PHPUnit test cannot close this: the abilities belong to plugins that are not installed in the
 * test environment, and the trigger is a third party shipping a release. So the guard has to run on
 * the site, and this is it.
 *
 * Three questions, because the ways this goes wrong are different:
 *
 * 1. Which abilities no toolset claims. Expected to be non-empty — a plugin with no toolset of its
 *    own is deliberately left in the catch-all — so the row that matters is one whose namespace an
 *    integration *should* have claimed.
 * 2. Which abilities an integration placed by its default rather than by a rule. Empty is healthy.
 *    A name here is a host plugin having shipped something nobody has classified yet.
 * 3. Whether anything is being shadowed by a name clash, which no prefix claim can fix. Elementor is
 *    the live example — with its MCP exposure enabled it loses `elementor/create-page` and
 *    `elementor/update-page-settings` to this plugin on every request. `Ability_Collision_Recorder`
 *    could not see either until #260; it names the losing plugin now.
 *
 * Lives with the Recovery abilities for the reason `List_Ability_Collisions` gives: it shares their
 * category and their job, and a folder of its own would need its own category registrar.
 *
 * @since 0.0.42
 */
class List_Unclassified_Abilities extends Ability_Definition {

	/**
	 * @since  0.0.42
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'diagnostics/list-unclassified-abilities',
			'args' => array(
				'label'               => __( 'List Unclassified Abilities', 'acrossai-abilities-manager' ),
				'description'         => __(
					'Report abilities that no Toolset claims, and abilities an integration placed by its fallback rather than by a rule. An ability with no toolset is unreachable through every Toolset dispatcher, and that is how an integrated plugin\'s own abilities go missing: it ships Abilities API support, nothing claims its name prefix, and all of them land in Other with no runtime signal. Some rows are expected — a plugin with no Toolset of its own is deliberately left in Other — so read the "claimed_by_an_integration" flag, which marks a namespace an integration already owns and is therefore a fault rather than a default. The fallback list should be empty: a name in it is an ability a host plugin has shipped that no rule recognises. Also reports how many abilities are being shadowed by a name clash, which claiming a prefix cannot fix.',
					'acrossai-abilities-manager'
				),
				'category'            => 'acrossai-recovery',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_names' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include every ability name per namespace. Off by default: the counts answer the question and the names are long.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'unclassified'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
							'description' => __( 'One row per ability-name namespace with no toolset, carrying the count, whether an integration already claims that namespace, and whether the abilities carry no tab_group at all rather than the catch-all.', 'acrossai-abilities-manager' ),
						),
						'unclassified_total' => array( 'type' => 'integer' ),
						'needs_attention'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Namespaces an integration claims that nonetheless have unclassified abilities. Should be empty; anything here is a live fault.', 'acrossai-abilities-manager' ),
						),
						'placed_by_fallback' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
							'description' => __( 'Abilities an integration placed with default_sub_group() because no rule matched. Should be empty.', 'acrossai-abilities-manager' ),
						),
						'shadowed'       => array(
							'type'        => 'integer',
							'description' => __( 'Registrations WordPress refused because another plugin already held the name. Each one is a plugin that lost an ability, and which plugin wins is decided by load order alone, so another site with the same plugins can resolve the other way. -1 means the collision recorder was not attached, so no conclusion can be drawn. Call diagnostics/list-ability-collisions for the contested names and which plugin lost each one.', 'acrossai-abilities-manager' ),
						),
						'total_abilities' => array( 'type' => 'integer' ),
						'message'        => array( 'type' => 'string' ),
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
	 * @since  0.0.42
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$include_names = ! empty( $input['include_names'] );

		$abilities = function_exists( 'wp_get_abilities' ) ? (array) wp_get_abilities() : array();
		$claimed   = AcrossAI_Toolset_Integrations::prefix_map();

		$namespaces = array();
		$total      = 0;

		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			++$total;

			$meta     = $ability->get_meta();
			$acrossai = is_array( $meta['acrossai'] ?? null ) ? $meta['acrossai'] : array();

			// A dispatcher carries no group on purpose — it must not sit inside the group it serves —
			// so counting it here would report the design as a fault.
			if ( ! empty( $acrossai['toolset'] ) ) {
				continue;
			}

			$group = (string) ( $acrossai['tab_group'] ?? '' );

			if ( '' !== $group && AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP !== $group ) {
				continue;
			}

			$name      = $ability->get_name();
			$slash     = strpos( $name, '/' );
			$namespace = false !== $slash ? substr( $name, 0, $slash ) : $name;

			if ( ! isset( $namespaces[ $namespace ] ) ) {
				$namespaces[ $namespace ] = array(
					'namespace'                 => $namespace,
					'abilities'                 => 0,
					'no_tab_group_at_all'       => 0,
					'claimed_by_an_integration' => isset( $claimed[ $namespace ] ) ? $claimed[ $namespace ] : false,
					'names'                     => array(),
				);
			}

			++$namespaces[ $namespace ]['abilities'];

			if ( '' === $group ) {
				++$namespaces[ $namespace ]['no_tab_group_at_all'];
			}

			if ( $include_names ) {
				$namespaces[ $namespace ]['names'][] = $name;
			}
		}

		ksort( $namespaces );

		$needs_attention = array();

		foreach ( $namespaces as $namespace => $row ) {
			if ( false !== $row['claimed_by_an_integration'] ) {
				$needs_attention[] = $namespace;
			}

			if ( ! $include_names ) {
				unset( $namespaces[ $namespace ]['names'] );
			}
		}

		$unclassified_total = 0;

		foreach ( $namespaces as $row ) {
			$unclassified_total += (int) $row['abilities'];
		}

		$fallback = array();

		foreach ( AcrossAI_Ability_Group_Tagger::defaulted() as $name => $group ) {
			$fallback[] = array(
				'ability' => $name,
				'toolset' => $group,
			);
		}

		return array(
			'success'            => true,
			'unclassified'       => array_values( $namespaces ),
			'unclassified_total' => $unclassified_total,
			'needs_attention'    => $needs_attention,
			'placed_by_fallback' => $fallback,
			'shadowed'           => Ability_Collision_Recorder::is_listening() ? Ability_Collision_Recorder::refused_count() : -1,
			'total_abilities'    => $total,
			'message'            => $this->summarise( $unclassified_total, $total, $needs_attention, $fallback ),
		);
	}

	/**
	 * One sentence an operator can act on.
	 *
	 * The faults lead, because the plain count is expected to be non-zero and would otherwise read
	 * as alarming on a healthy site.
	 *
	 * @since  0.0.42
	 * @param  int                              $unclassified Abilities with no toolset.
	 * @param  int                              $total        Abilities registered.
	 * @param  string[]                         $attention    Namespaces an integration claims.
	 * @param  array<int, array<string, string>> $fallback    Abilities placed by a default.
	 * @return string
	 */
	private function summarise( int $unclassified, int $total, array $attention, array $fallback ): string {
		$parts = array();

		if ( ! empty( $attention ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated ability namespaces. */
				__( 'Fault: %s have abilities with no toolset despite an integration claiming that namespace.', 'acrossai-abilities-manager' ),
				implode( ', ', $attention )
			);
		}

		if ( ! empty( $fallback ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of abilities. */
				_n(
					'%d ability was placed by an integration fallback, so no rule recognises it — likely a host plugin release nobody has classified.',
					'%d abilities were placed by an integration fallback, so no rule recognises them — likely a host plugin release nobody has classified.',
					count( $fallback ),
					'acrossai-abilities-manager'
				),
				count( $fallback )
			);
		}

		$parts[] = sprintf(
			/* translators: 1: abilities with no toolset, 2: abilities registered. */
			__( '%1$d of %2$d registered abilities have no toolset. Some is expected: a plugin with no Toolset of its own is left in Other deliberately.', 'acrossai-abilities-manager' ),
			$unclassified,
			$total
		);

		return implode( ' ', $parts );
	}
}
