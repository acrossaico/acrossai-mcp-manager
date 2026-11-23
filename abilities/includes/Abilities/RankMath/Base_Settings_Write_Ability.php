<?php
/**
 * Feature 069 — abstract base for the per-option-blob settings writers.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Drives abilities #2, #3 and #4 — one writer per Rank Math option blob.
 *
 * Rank Math stores its settings in three blobs (general, titles, sitemap), each
 * gated on its own capability and each written through the same
 * Option_Center::save_settings() call. A get/update pair per settings panel would
 * be ~20 near-identical classes differing only in a field list, so instead each
 * blob gets one writer taking a scope enum plus a settings object, and the
 * submitted keys are validated against that scope's field spec.
 *
 * Subclasses supply only their enum, their labels, and the scope-to-panel
 * mapping. Same economics as Elementor's Base_Audit_Ability driving 27 audits.
 *
 * The cost of putting `settings` behind a plain object is lost schema
 * autocomplete; ability #1 get-settings buys it back by making each scope's
 * accepted keys, types, enums and bounds discoverable at runtime.
 */
abstract class Base_Settings_Write_Ability extends Base_Rank_Math_Ability {

	/**
	 * Rank Math option blob this ability writes: general | titles | sitemap.
	 *
	 * @return string
	 */
	abstract protected function option_type(): string;

	/**
	 * The scope enum members, in a stable order.
	 *
	 * @return string[]
	 */
	abstract protected function scope_enum(): array;

	/**
	 * Input property name carrying the scope — 'section' or 'scope'.
	 *
	 * @return string
	 */
	abstract protected function scope_key(): string;

	/**
	 * Map a scope value to a Settings_Registry panel slug.
	 *
	 * @param string $scope Scope value.
	 * @return string
	 */
	abstract protected function panel_for( string $scope ): string;

	/**
	 * @return string
	 */
	protected function sub_group(): string {
		return 'rank-math-settings';
	}

	/**
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @return string[]
	 */
	protected function required_input(): array {
		return array( $this->scope_key(), 'settings' );
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			$this->scope_key() => array(
				'type'        => 'string',
				'enum'        => $this->scope_enum(),
				'description' => $this->scope_description(),
			),
			'object'           => array(
				'type'        => 'string',
				'description' => __( 'Post type or taxonomy name. Required only for the post-type and taxonomy scopes.', 'acrossai-abilities-manager' ),
			),
			'settings'         => array(
				'type'                 => 'object',
				'description'          => __( 'Field id => value. Read the matching panel with rank-math/get-settings-panel first: it returns each field id, its type, allowed values and bounds. Any field not belonging to the scope rejects the whole write.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			$this->scope_key() => array( 'type' => 'string' ),
			'object'           => array( 'type' => 'string' ),
			'panel'            => array( 'type' => 'string' ),
			'option_type'      => array( 'type' => 'string' ),
			'updated'          => array( 'type' => 'object' ),
			'notifications'    => array( 'type' => 'array' ),
		);
	}

	/**
	 * Human-readable description of the scope parameter.
	 *
	 * @return string
	 */
	protected function scope_description(): string {
		return __( 'Which group of settings to write.', 'acrossai-abilities-manager' );
	}

	/**
	 * Resolve scope + object to a panel and delegate to the single write path.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$key   = $this->scope_key();
		$scope = isset( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';

		if ( ! in_array( $scope, $this->scope_enum(), true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: input property name, 2: submitted value, 3: comma-separated allowed values */
					__( 'Unknown %1$s "%2$s". Expected one of: %3$s.', 'acrossai-abilities-manager' ),
					$key,
					$scope,
					implode( ', ', $this->scope_enum() )
				)
			);
		}

		$panel  = $this->panel_for( $scope );
		$object = isset( $input['object'] ) ? sanitize_key( (string) $input['object'] ) : '';

		$definition = Settings_Registry::panel( $panel );
		if ( null === $definition ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: panel slug */
					__( 'No field specification is registered for panel "%s".', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		$object_error = $this->assert_object( $definition, $object, $scope );
		if ( is_wp_error( $object_error ) ) {
			return $object_error;
		}

		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		if ( array() === $settings ) {
			return new WP_Error( 'invalid_input', __( 'The settings object is empty. Nothing to write.', 'acrossai-abilities-manager' ) );
		}

		$result = Settings_Writer::save( $panel, $object, $settings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$count            = count( $result['updated'] );
		$result[ $key ]   = $scope;
		$result['message'] = sprintf(
			/* translators: 1: number of fields written, 2: panel slug */
			_n( 'Wrote %1$d setting to the Rank Math "%2$s" panel.', 'Wrote %1$d settings to the Rank Math "%2$s" panel.', $count, 'acrossai-abilities-manager' ),
			$count,
			$panel
		);

		return $result;
	}

	/**
	 * Validate the object argument against the panel's dynamic requirement.
	 *
	 * @param array<string,mixed> $definition Panel definition.
	 * @param string              $object     Submitted object name.
	 * @param string              $scope      Scope value, for the message.
	 * @return true|WP_Error
	 */
	private function assert_object( array $definition, string $object, string $scope ) {
		$dynamic = $definition['dynamic'];

		if ( null === $dynamic ) {
			return true;
		}
		if ( '' === $object ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: scope value, 2: 'post_type' or 'taxonomy' */
					__( 'Scope "%1$s" requires an object naming the %2$s.', 'acrossai-abilities-manager' ),
					$scope,
					$dynamic
				)
			);
		}
		if ( 'post_type' === $dynamic && ! post_type_exists( $object ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: post type name */
					__( 'The post type "%s" is not registered.', 'acrossai-abilities-manager' ),
					$object
				)
			);
		}
		if ( 'taxonomy' === $dynamic && ! taxonomy_exists( $object ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: taxonomy name */
					__( 'The taxonomy "%s" is not registered.', 'acrossai-abilities-manager' ),
					$object
				)
			);
		}
		return true;
	}
}
