<?php
/**
 * Site introspection read — maintenance-mode marker status (Feature 063).
 *
 * Reports whether the WordPress .maintenance marker file exists at
 * ABSPATH, when it was created, and whether that timestamp exceeds
 * WordPress core's 10-minute staleness threshold.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteHealth
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteHealth;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Maintenance_Mode_Status ability class.
 */
class Get_Maintenance_Mode_Status extends Ability_Definition {

	/**
	 * Matches WordPress core's wp_maintenance() staleness threshold in seconds.
	 */
	private const STALE_AFTER_SECONDS = 600;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'site-health/get-maintenance-mode-status',
			'args' => array(
				'label'               => __( 'Get Maintenance Mode Status', 'acrossai-abilities-manager' ),
				'description'         => __( 'Report whether the .maintenance marker file exists and, when active, its creation timestamp plus a stale flag matching WordPress core\'s 10-minute threshold.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-site-health',
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
						'success'  => array( 'type' => 'boolean' ),
						'active'   => array( 'type' => 'boolean' ),
						'since'    => array( 'type' => 'integer' ),
						'is_stale' => array( 'type' => 'boolean' ),
						'message'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'maintenance',
						'sub_group_label' => __( 'Maintenance', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$marker = ABSPATH . '.maintenance';

		if ( ! file_exists( $marker ) ) {
			return array(
				'success' => true,
				'active'  => false,
				'message' => __( 'Maintenance mode is not active.', 'acrossai-abilities-manager' ),
			);
		}

		$upgrading = self::read_upgrading_timestamp( $marker );

		if ( null === $upgrading ) {
			return array(
				'success' => true,
				'active'  => true,
				'message' => __( 'Maintenance mode is active but the .maintenance timestamp could not be read.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'  => true,
			'active'   => true,
			'since'    => (int) $upgrading,
			'is_stale' => ( time() - (int) $upgrading ) > self::STALE_AFTER_SECONDS,
			'message'  => __( 'Maintenance mode is active.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Include the .maintenance file in a scoped function and return the
	 * $upgrading timestamp it assigns. Returns null when the marker is
	 * unreadable or does not assign the expected variable.
	 *
	 * @param string $marker Absolute path to the .maintenance file.
	 * @return int|null
	 */
	private static function read_upgrading_timestamp( string $marker ): ?int {
		$upgrading = 0;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_reporting -- suppression scoped to the include so a corrupt file cannot spew warnings into a REST response.
		$errored = false;
		set_error_handler(
			static function () use ( &$errored ): bool {
				$errored = true;
				return true;
			}
		);
		try {
			include $marker;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$errored = true;
		} finally {
			restore_error_handler();
		}

		if ( $errored ) {
			return null;
		}

		return is_numeric( $upgrading ) && (int) $upgrading > 0 ? (int) $upgrading : null;
	}
}
