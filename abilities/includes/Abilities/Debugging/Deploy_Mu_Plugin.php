<?php
/**
 * Deploy_Mu_Plugin ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-deploy-mu-plugin
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Install the mu-plugin that filters option_active_plugins on every request.
 *
 * Hash-compares the on-disk mu-plugin against the bundled reference — a
 * re-deploy against an already-current mechanism is a zero-write no-op.
 * Gated by File_Mods_Guard so a site with DISALLOW_FILE_MODS=true cannot
 * be forced to deploy.
 */
class Deploy_Mu_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-deploy-mu-plugin',
			'args' => array(
				'label'               => __( 'Deploy Conflict-Test Mu-Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Installs the mu-plugin that reads the conflict-test override map and filters WordPress\'s effective active plugin list on every request. Idempotent — a redeploy against an already-current mechanism performs no on-disk write.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'deployed'        => array( 'type' => 'boolean' ),
						'already_current' => array( 'type' => 'boolean' ),
						'path'            => array( 'type' => 'string' ),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'conflict-testing',
						'sub_group_label' => __( 'Conflict Testing', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute — hash-compare + atomic-write the mu-plugin file.
	 *
	 * @param array $input Ignored.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$store         = Overrides_Store::instance();
		$deployed_path = $store->mu_plugin_path();
		$bundled_path  = $store->bundled_mu_source_path();

		if ( ! file_exists( $bundled_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'Bundled mu-plugin source is missing from the plugin installation.', 'acrossai-abilities-manager' ),
			);
		}

		if ( 'deployed' === $store->mu_plugin_status() ) {
			return array(
				'success'         => true,
				'deployed'        => false,
				'already_current' => true,
				'path'            => $deployed_path,
				'message'         => __( 'Mu-plugin is already up-to-date; no on-disk write performed.', 'acrossai-abilities-manager' ),
			);
		}

		$mu_dir = dirname( $deployed_path );
		if ( ! is_dir( $mu_dir ) && ! wp_mkdir_p( $mu_dir ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: absolute directory path */
					__( 'Could not create mu-plugins directory at %s.', 'acrossai-abilities-manager' ),
					$mu_dir
				),
			);
		}

		$source_bytes = file_get_contents( $bundled_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed plugin-owned asset path
		if ( false === $source_bytes ) {
			return array(
				'success' => false,
				'message' => __( 'Could not read bundled mu-plugin source.', 'acrossai-abilities-manager' ),
			);
		}

		$tmp_path = tempnam( $mu_dir, '.wctester-' );
		if ( false === $tmp_path ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create temporary file for atomic write.', 'acrossai-abilities-manager' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixed system-owned temp path
		$written = file_put_contents( $tmp_path, $source_bytes );
		if ( false === $written ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success' => false,
				'message' => __( 'Could not write mu-plugin bytes to temporary file.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! rename( $tmp_path, $deployed_path ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'success' => false,
				'message' => __( 'Could not rename temporary file into mu-plugin location.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'success'         => true,
			'deployed'        => true,
			'already_current' => false,
			'path'            => $deployed_path,
		);
	}
}
