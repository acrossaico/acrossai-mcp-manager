<?php
/**
 * Check_Wp_Core_Update ability (Feature 042).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Focused, WordPress-core-only update availability report.
 *
 * Reads the first upgrade offer from get_core_updates() and returns its
 * shape as flat fields. Companion to (but narrower than) the existing
 * Plugins\Check_Plugin_Updates ability, which reports across core + plugins +
 * themes; this one is scoped to WP core alone so callers can key off it
 * without having to inspect the wider report.
 */
class Check_Wp_Core_Update extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/check-wp-core-update',
			'args' => array(
				'label'               => __( 'Check WordPress Core Update', 'acrossai-abilities-manager' ),
				'description'         => __( 'Report whether a WordPress core update is available. Returns the current version, the offered new version + download URL, and the PHP / MySQL requirements of the offer. Read-only; safe to call from any admin context.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'current_version' => array( 'type' => 'string' ),
						'available'       => array( 'type' => 'boolean' ),
						'new_version'     => array( 'type' => 'string' ),
						'locale'          => array( 'type' => 'string' ),
						'response'        => array( 'type' => 'string' ),
						'partial_version' => array( 'type' => 'string' ),
						'download'        => array( 'type' => 'string' ),
						'php_version'     => array( 'type' => 'string' ),
						'mysql_version'   => array( 'type' => 'string' ),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'lifecycle',
						'sub_group_label' => __( 'Lifecycle', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload (unused; always empty).
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$current_version = (string) get_bloginfo( 'version' );
		$core_updates    = get_core_updates();

		if ( empty( $core_updates ) || ! is_array( $core_updates ) ) {
			return array(
				'success'         => true,
				'current_version' => $current_version,
				'available'       => false,
				'message'         => __( 'Could not fetch core updates.', 'acrossai-abilities-manager' ),
			);
		}

		$offer = $core_updates[0];
		if ( ! is_object( $offer ) ) {
			return array(
				'success'         => true,
				'current_version' => $current_version,
				'available'       => false,
				'message'         => __( 'No usable core update offer available.', 'acrossai-abilities-manager' ),
			);
		}

		$response = isset( $offer->response ) ? (string) $offer->response : '';
		$out      = array(
			'success'         => true,
			'current_version' => $current_version,
			'available'       => 'upgrade' === $response,
			'new_version'     => isset( $offer->version ) ? (string) $offer->version : '',
			'locale'          => isset( $offer->locale ) ? (string) $offer->locale : '',
			'response'        => $response,
			'partial_version' => isset( $offer->partial_version ) ? (string) $offer->partial_version : '',
			'download'        => isset( $offer->download ) ? (string) $offer->download : '',
			'php_version'     => isset( $offer->php_version ) ? (string) $offer->php_version : '',
			'mysql_version'   => isset( $offer->mysql_version ) ? (string) $offer->mysql_version : '',
		);

		$out['message'] = $out['available']
			? sprintf(
				/* translators: 1: current version, 2: new version */
				__( 'WordPress %2$s is available (currently on %1$s).', 'acrossai-abilities-manager' ),
				$current_version,
				$out['new_version']
			)
			: sprintf(
				/* translators: %s: current WordPress version */
				__( 'WordPress core is up to date (running %s).', 'acrossai-abilities-manager' ),
				$current_version
			);

		return $out;
	}
}
