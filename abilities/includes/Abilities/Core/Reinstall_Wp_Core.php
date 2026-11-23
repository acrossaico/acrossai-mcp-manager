<?php
/**
 * Reinstall_Wp_Core ability (Feature 057).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade\Wp_Downgrade_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Reinstall the currently installed WordPress core version via WP core's
 * Core_Upgrader by forcing `$update->response = 'reinstall'`.
 *
 * Equivalent to the WP admin action at
 * `/wp-admin/update-core.php?action=do-core-reinstall`: re-downloads and
 * re-applies the current WP version even when no upgrade offer is pending.
 * Honours DISALLOW_FILE_MODS. Idempotent — safe to re-run.
 */
class Reinstall_Wp_Core extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/reinstall-wp-core',
			'args' => array(
				'label'               => __( 'Reinstall WordPress Core', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reinstall the currently installed WordPress version by re-downloading and re-applying the same release via WP core\'s Core_Upgrader with response="reinstall". Equivalent to the wp-admin "Re-install version X" action at /wp-admin/update-core.php?action=do-core-reinstall. Honours DISALLOW_FILE_MODS. Idempotent — safe to re-run.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_core' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'locale' => array(
							'type'        => 'string',
							'description' => __( 'Optional locale of the reinstall offer to pin to (e.g. "en_US"). When omitted, defaults to the site\'s active locale from get_locale().', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'reinstalled'    => array( 'type' => 'boolean' ),
						'version'        => array( 'type' => 'string' ),
						'pinned_version' => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
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
						'readonly'    => false,
						'destructive' => true,
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( is_multisite() && ! current_user_can( 'update_core' ) ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'message'     => __( 'Core reinstall on multisite requires the update_core capability at the network level.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( '\Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$current_version = (string) get_bloginfo( 'version' );

		$locale = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';
		if ( '' === $locale ) {
			$locale = get_locale();
		}

		// A WP Downgrade pin makes this ability dangerous and then makes it fail, in that order.
		// Its filter rewrites the offer's `current` to the pinned version and its package URLs to
		// that release, so "reinstall the running version" would in fact install the pinned one —
		// and because find_core_update() matches on `current`, looking up the running version
		// returns nothing at all. Measured: with a 7.0.8 pin on a 7.1.4 site, reinstall failed
		// with "refresh the update_core transient", which is not the reason and refreshing does
		// not help. Refuse with the actual cause instead.
		$pin = Wp_Downgrade_Guard::pinned_version();

		if ( '' !== $pin && $pin !== $current_version ) {
			return array(
				'success'        => false,
				'reinstalled'    => false,
				'error_code'     => 'core_version_pinned',
				'version'        => $current_version,
				'pinned_version' => $pin,
				'message'        => sprintf(
					/* translators: 1: pinned version, 2: running version */
					__( 'A WP Downgrade pin on %1$s is active, so reinstalling would install %1$s rather than the running %2$s. Clear it with versions/clear-core-pin to reinstall %2$s, or run core/update-wp-core to apply the pin deliberately.', 'acrossai-abilities-manager' ),
					$pin,
					$current_version
				),
			);
		}

		$update = find_core_update( $current_version, $locale );

		if ( null === $update || false === $update ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => 'no_reinstall_offer',
				'version'     => $current_version,
				'message'     => __( 'No core update offer is available for reinstall. Run core/check-wp-core-update to refresh the update_core transient; if that does not help, the WP.org API is not currently offering this exact version and locale.', 'acrossai-abilities-manager' ),
			);
		}

		// Force the reinstall response so Core_Upgrader downloads the full
		// (or no-content) package instead of a partial diff. Mirrors WP core's
		// do_core_upgrade( $reinstall = true ) at wp-admin/update-core.php.
		$update->response = 'reinstall';

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade(
			$update,
			array(
				'allow_relaxed_file_ownership' => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => $result->get_error_code(),
				'version'     => $current_version,
				'message'     => $result->get_error_message(),
			);
		}

		if ( null === $result || false === $result ) {
			$skin_errors = $skin->get_errors();
			$msg         = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
				? $skin_errors->get_error_message()
				: __( 'Core reinstall did not report success.', 'acrossai-abilities-manager' );
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => 'reinstall_failed',
				'version'     => $current_version,
				'message'     => $msg,
			);
		}

		return array(
			'success'     => true,
			'reinstalled' => true,
			'version'     => $current_version,
			'message'     => sprintf(
				/* translators: %s: WordPress version that was reinstalled */
				__( 'WordPress %s reinstalled.', 'acrossai-abilities-manager' ),
				$current_version
			),
		);
	}
}
