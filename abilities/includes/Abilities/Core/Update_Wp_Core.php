<?php
/**
 * Update_Wp_Core ability (Feature 042).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Core_Php_Compat;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade\Wp_Downgrade_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Apply a WordPress core update via WP core's Core_Upgrader::upgrade().
 *
 * Same upgrader class the built-in WP dashboard uses; no custom download or
 * integrity checks. When called with no arguments, upgrades to the first
 * `response=upgrade` offer from get_core_updates() (the default WP admin
 * behaviour). Callers can pin to a specific `version` (+ optional `locale`)
 * to constrain the offer picked.
 */
class Update_Wp_Core extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/update-wp-core',
			'args' => array(
				'label'               => __( 'Update WordPress Core', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply a WordPress core update via WP core\'s Core_Upgrader. With no arguments, installs the offer WordPress is advertising. Pass "version" (+ optional "locale") to install a specific offered release, and this is also the ability that applies a WP Downgrade pin set by versions/set-core-pin. If the resolved target is older than what is running — which a pin or an explicit version can make it — the move is treated as a downgrade: it requires confirm: true and refuses a release that predates support for the PHP this site runs. Re-running with nothing on offer is a clean no-op. Honours DISALLOW_FILE_MODS.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_core' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'version' => array(
							'type'        => 'string',
							'description' => __( 'Optional WordPress version to pin the upgrade to (e.g. "6.9.1"). When omitted, upgrades to the latest available offer.', 'acrossai-abilities-manager' ),
						),
						'locale'  => array(
							'type'        => 'string',
							'description' => __( 'Optional locale of the update offer to pin to (e.g. "en_US"). When omitted, defaults to the site\'s active locale from get_locale().', 'acrossai-abilities-manager' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Required only when the resolved target is older than the running version, which makes this a downgrade. Not needed for a normal upgrade.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'updated'        => array( 'type' => 'boolean' ),
						'from_version'   => array( 'type' => 'string' ),
						'to_version'     => array( 'type' => 'string' ),
						'target_version' => array( 'type' => 'string' ),
						'php_version'    => array( 'type' => 'string' ),
						'compatibility'  => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'warnings'       => array( 'type' => 'array' ),
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
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * The pinning ability covers what this one cannot: making the version stick.
	 *
	 * Feature 136. `only_if_registered` keeps the hint out of the payload on sites where the
	 * WP Downgrade suite is not present, so a caller is never pointed at an ability that does not
	 * exist. Held back from the suggestion PR for exactly that reason — the target had to exist
	 * first.
	 *
	 * @since  0.0.45
	 * @return array<int,array<string,string|bool>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'               => 'versions/set-core-pin',
				'reason'             => __( 'To hold core on a specific version across future update checks rather than for this one run, pin it first.', 'acrossai-abilities-manager' ),
				'only_if_registered' => true,
			),
		);
	}

	/**
	 * WP Downgrade pins a core version; this ability does not.
	 *
	 * Feature 136. This ability pins one upgrade to a specific offer, but only to a version the Core API offers, and only for that one run.
	 *
	 * WP Downgrade does not itself move core. It filters `site_transient_update_core` so WordPress
	 * offers the pinned version, and the move happens on the next core update run — which is why
	 * the suggestion is about persistence, not about downgrading.
	 *
	 * `plugin_provides_abilities` is false: it is a settings-page plugin and defines no classes at
	 * all, so installing it gives an agent nothing until this plugin's own pinning abilities exist.
	 *
	 * @since  0.0.45
	 * @return array<int,array<string,scalar>>
	 */
	protected function suggested_plugins(): array {
		return array(
			array(
				'slug'                          => 'wp-downgrade',
				'name'                          => 'WP Downgrade | Specific Core Version',
				'reason'                        => __( 'This can pin a single upgrade to a specific offer, but only to a version the Core API offers and only for that run. WP Downgrade holds core on a chosen version across update checks.', 'acrossai-abilities-manager' ),
				'covers'                        => __( 'Pinning WordPress core to a chosen version so update checks keep offering it. Core only; it does not touch plugins or themes.', 'acrossai-abilities-manager' ),
				'plugin_provides_abilities'     => false,
				'acrossai_provides_integration' => false,
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
				'success' => false,
				'updated' => false,
				'message' => __( 'Core updates on multisite require the update_core capability at the network level.', 'acrossai-abilities-manager' ),
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

		$from_version = (string) get_bloginfo( 'version' );

		$version = isset( $input['version'] ) ? sanitize_text_field( (string) $input['version'] ) : '';
		$locale  = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';

		if ( '' !== $version ) {
			$update = find_core_update( $version, '' !== $locale ? $locale : get_locale() );
		} else {
			$updates = get_core_updates();
			$update  = ( is_array( $updates ) && ! empty( $updates ) && is_object( $updates[0] ) ) ? $updates[0] : null;
		}

		if ( null === $update || false === $update ) {
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => 'no_offer_found',
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'message'      => '' !== $version
					? sprintf(
						/* translators: %s: requested WordPress version */
						__( 'No core update offer found for version "%s".', 'acrossai-abilities-manager' ),
						$version
					)
					: __( 'No core update available.', 'acrossai-abilities-manager' ),
			);
		}

		$response = isset( $update->response ) ? (string) $update->response : '';

		// What this offer actually installs. Normally `current` equals `version`; WP Downgrade's
		// filter rewrites `current` and the package URLs to the pinned release while leaving
		// `version` and `response` untouched — measured on a pinned site: version 7.1.4,
		// response latest, current 7.0.8, packages->full wordpress-7.0.8.zip. So `current` is the
		// field that says where the site ends up, and it is also the field `find_core_update()`
		// matches on.
		$target = isset( $update->current ) && '' !== (string) $update->current
			? (string) $update->current
			: ( isset( $update->version ) ? (string) $update->version : '' );

		$pin        = Wp_Downgrade_Guard::pinned_version();
		$pin_active = '' !== $pin && $pin !== $from_version;

		// The response check belongs to the "is there an update?" question only. Refusing whenever
		// response is not `upgrade` made the WP Downgrade workflow impossible: the pinned package
		// sits in this very offer, and response stays `latest` forever because WP Downgrade never
		// changes it — so set-core-pin's own instruction to run this ability could not work. When
		// the caller names a version, or a pin is active, the intent is explicit and the offer is
		// real; the check would only be second-guessing a request that resolved.
		$explicit = '' !== $version || $pin_active;

		if ( ! $explicit && 'upgrade' !== $response ) {
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => 'no_update_offered',
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'message'      => sprintf(
					/* translators: %s: WP update offer response value (e.g. "latest") */
					__( 'No core update is being offered (response=%s). Pass a version to install a specific release, or use core/rollback-wp-core to move backwards.', 'acrossai-abilities-manager' ),
					'' !== $response ? $response : 'unknown'
				),
			);
		}

		// Naming the version already running used to be refused as "not upgradable". Honouring an
		// explicit version turned that into a silent core reinstall, which is a different
		// ability's job. Report the no-op and name it, exactly as versions/rollback-plugin does
		// for the same request — a caller wanting to re-apply the current release would otherwise
		// get it from here by accident and never find the ability built for it.
		if ( '' !== $version && $target === $from_version && ! $pin_active ) {
			return array(
				'success'        => true,
				'updated'        => false,
				'from_version'   => $from_version,
				'to_version'     => $from_version,
				'target_version' => $target,
				'message'        => sprintf(
					/* translators: %s: running WordPress version */
					__( 'WordPress is already on %s — nothing was changed. To re-apply that release\'s files, use core/reinstall-wp-core.', 'acrossai-abilities-manager' ),
					$from_version
				),
			);
		}

		$warnings = array();

		if ( $pin_active ) {
			$warnings[] = sprintf(
				/* translators: %s: pinned version */
				__( 'A WP Downgrade pin on %s is active, so this offer installs the pinned release rather than the latest.', 'acrossai-abilities-manager' ),
				$pin
			);
		}

		// A pinned or explicitly named target can be older than what is running, which makes this
		// a downgrade wearing an update's name. Same guard and same confirmation as
		// core/rollback-wp-core, because it is the same operation and the same hazard.
		$going_back = '' !== $target && version_compare( $target, $from_version, '<' );
		$compat     = array( 'verdict' => 'ok', 'message' => '' );

		if ( $going_back ) {
			$compat = Core_Php_Compat::assess( $target, PHP_VERSION );

			if ( 'unsupported' === $compat['verdict'] ) {
				return array(
					'success'        => false,
					'updated'        => false,
					'error_code'     => 'php_incompatible',
					'from_version'   => $from_version,
					'to_version'     => $from_version,
					'target_version' => $target,
					'php_version'    => PHP_VERSION,
					'compatibility'  => $compat['verdict'],
					'message'        => $compat['message'],
				);
			}

			if ( empty( $input['confirm'] ) ) {
				return array(
					'success'        => false,
					'updated'        => false,
					'blocked_reason' => 'confirmation_required',
					'from_version'   => $from_version,
					'to_version'     => $from_version,
					'target_version' => $target,
					'php_version'    => PHP_VERSION,
					'compatibility'  => $compat['verdict'],
					'message'        => sprintf(
						/* translators: 1: running version, 2: target version, 3: compatibility note */
						__( 'This would move WordPress backwards, from %1$s to %2$s, replacing every core file. The database is not downgraded with it. %3$s Pass confirm: true to proceed.', 'acrossai-abilities-manager' ),
						$from_version,
						$target,
						$compat['message']
					),
				);
			}

			if ( 'unknown' === $compat['verdict'] ) {
				$warnings[] = $compat['message'];
			}

			$warnings[] = __( 'Core moved backwards but the database did not. WordPress does not reverse schema changes, so the stored db_version stays ahead of the installed release.', 'acrossai-abilities-manager' );
		}

		// Core_Upgrader has its own gate: `if ( 'latest' === $current->response ) return up_to_date`
		// at class-core-upgrader.php:85. So deciding to honour this offer is not enough — the
		// response has to be forced, exactly as WordPress does at update-core.php:897 for the
		// same situation. Found by running the pinned workflow and watching it stop here with
		// "WordPress is at the latest version" before the upgrader was ever reached.
		//
		// `reinstall` and not `upgrade`, for a reason worth recording: at
		// class-core-upgrader.php:111 the partial package is chosen unless the response is
		// `reinstall`. WP Downgrade rewrites `packages->full` and blanks `no_content` and
		// `new_bundled`, but leaves `partial` populated — so `upgrade` could pick a diff built
		// against a different release and corrupt the install. `reinstall` excludes partials and
		// forces the full package.
		if ( $explicit && 'upgrade' !== $response ) {
			$update->response = 'reinstall';
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $update );

		$to_version = (string) get_bloginfo( 'version' );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'        => false,
				'updated'        => false,
				'error_code'     => $result->get_error_code(),
				'from_version'   => $from_version,
				'to_version'     => $to_version,
				'target_version' => $target,
				'message'        => $result->get_error_message(),
			);
		}

		if ( null === $result || false === $result ) {
			$skin_errors = $skin->get_errors();
			$msg         = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
				? $skin_errors->get_error_message()
				: __( 'Core upgrade did not report success.', 'acrossai-abilities-manager' );
			return array(
				'success'        => false,
				'updated'        => false,
				'error_code'     => 'update_failed',
				'from_version'   => $from_version,
				'to_version'     => $to_version,
				'target_version' => $target,
				'message'        => $msg,
			);
		}

		$moved_back = version_compare( $to_version, $from_version, '<' );

		return array(
			'success'        => true,
			'updated'        => true,
			'from_version'   => $from_version,
			'to_version'     => $to_version,
			'target_version' => $target,
			'php_version'    => PHP_VERSION,
			'compatibility'  => (string) $compat['verdict'],
			'warnings'       => $warnings,
			'message'        => $moved_back
				? sprintf(
					/* translators: 1: from version, 2: to version */
					__( 'WordPress moved from %1$s down to %2$s.', 'acrossai-abilities-manager' ),
					$from_version,
					$to_version
				)
				: sprintf(
					/* translators: 1: from version, 2: to version */
					__( 'WordPress upgraded from %1$s to %2$s.', 'acrossai-abilities-manager' ),
					$from_version,
					$to_version
				),
		);
	}
}
