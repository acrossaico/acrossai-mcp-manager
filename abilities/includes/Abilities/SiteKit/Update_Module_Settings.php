<?php
/**
 * Feature 120 — write one Site Kit module's settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #12 — site-kit/update-module-settings.
 *
 * The write half of get-module-settings, and the ability that turns this suite from
 * one that reports into one that can act.
 *
 * Confirm-gated, because several of these keys change what happens on the live site
 * the moment they are saved: useSnippet stops or starts the measurement tag rendering,
 * and trackingDisabled decides whose visits are counted. Not marked destructive —
 * nothing is deleted and every change is reversible by writing the old value back,
 * which the response reports so a caller can.
 */
class Update_Module_Settings extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'update-module-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Site Kit Module Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Change the stored settings of one Site Kit module: which Analytics 4 property and measurement ID the site reports to, which Search Console property it reads, which Tag Manager container it uses, whether each module places its snippet on the page, and who is excluded from tracking. Pass only the keys you want changed — everything else is left alone. Read the current values first with site-kit/get-module-settings; a key that does not already exist on the module is ignored and named back to you rather than silently dropped. Changing a connection setting such as propertyID makes Site Kit reassign that module\'s owner to the WordPress user running this ability, which decides whose Google credentials serve the module\'s data to anyone reading a shared dashboard — the response reports when that happened. ownerID itself and any credential keys are refused.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'module'   => array(
				'type'        => 'string',
				'description' => __( 'Module slug, e.g. "analytics-4", "search-console", "tagmanager". List them with site-kit/list-modules.', 'acrossai-abilities-manager' ),
			),
			'settings' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description' => __( 'The keys to change and their new values. Only keys the module already stores are written; the rest are reported as ignored. Read the writable set with site-kit/get-module-settings.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'slug'          => array( 'type' => 'string' ),
			'changed'       => array(
				'type'        => 'array',
				'description' => __( 'One entry per setting that actually moved, each with its name and its old and new value read back from Site Kit after the write.', 'acrossai-abilities-manager' ),
			),
			'unchanged'     => array(
				'type'        => 'array',
				'description' => __( 'Keys that were written but held the same value, or whose value Site Kit\'s own sanitiser rejected.', 'acrossai-abilities-manager' ),
			),
			'ignored'       => array(
				'type'        => 'array',
				'description' => __( 'Keys that do not exist on this module and were not written. Usually a typo.', 'acrossai-abilities-manager' ),
			),
			'owner_changed' => array( 'type' => 'boolean' ),
			'owner_id'      => array( 'type' => array( 'integer', 'null' ) ),
			'settings'      => array( 'type' => 'object' ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Site_Kit_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'module', 'settings' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$slug = isset( $input['module'] ) ? sanitize_key( (string) $input['module'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', __( 'module is required. List the available slugs with site-kit/list-modules.', 'acrossai-abilities-manager' ) );
		}

		if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) ) {
			return new WP_Error( 'invalid_input', __( 'settings must be an object of keys to change.', 'acrossai-abilities-manager' ) );
		}

		$result = Settings_Writer::update( $slug, $input['settings'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parts = array();

		$parts[] = array() === $result['changed']
			? sprintf(
				/* translators: %s: module slug */
				__( 'Nothing changed on the Site Kit "%s" module — every key already held the value given.', 'acrossai-abilities-manager' ),
				$slug
			)
			: sprintf(
				/* translators: 1: number of keys changed, 2: module slug, 3: comma-separated key names */
				__( 'Changed %1$d setting(s) on the Site Kit "%2$s" module: %3$s.', 'acrossai-abilities-manager' ),
				count( $result['changed'] ),
				$slug,
				implode( ', ', array_column( $result['changed'], 'setting' ) )
			);

		if ( array() !== $result['ignored'] ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of ignored keys */
				__( 'Ignored %s — no such setting on this module.', 'acrossai-abilities-manager' ),
				implode( ', ', $result['ignored'] )
			);
		}

		if ( $result['owner_changed'] ) {
			$parts[] = __( 'Site Kit moved this module\'s owner to the current user, because a connection setting changed. Anyone reading this module through a shared dashboard now does so with that user\'s Google credentials.', 'acrossai-abilities-manager' );
		}

		$result['message'] = implode( ' ', $parts );

		return $result;
	}
}
