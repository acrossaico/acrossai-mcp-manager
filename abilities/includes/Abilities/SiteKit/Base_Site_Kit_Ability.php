<?php
/**
 * Feature 120 — abstract base for every Site Kit ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Site_Kit_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the ability spec and enforces the execute() ordering for the whole suite.
 *
 * Sole assembler of ability(), for the same reason the Rank Math base is: it is what
 * guarantees every ability carries the right category and meta.acrossai.tab_group,
 * rather than that being something code review has to catch each time.
 *
 * It is also the sole enforcer of the guard order:
 *
 *   1 availability   Site Kit loaded
 *   2 setup          somebody completed setup for this site
 *   3 authenticated  THIS user holds a Google token
 *   4 module         the named module is active and connected
 *   5 confirmation   confirm:true where the operation changes the live site
 *   6 run()          per-field validation and domain work
 *   7 envelope       ok() / fail()
 *
 * Steps 2-4 are opt-in per ability, and the opting matters: site-kit/get-status must
 * run when none of them hold, because reporting that is its entire job.
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override
 * ability() or execute(), and MUST NOT name a \Google\Site_Kit\* symbol — all
 * third-party access belongs in Includes\Abilities\Utilities\SiteKit.
 */
abstract class Base_Site_Kit_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 */
	protected const CATEGORY = 'acrossai-site-kit';

	/**
	 * Admin Integrations tab.
	 */
	protected const TAB_GROUP = 'site-kit';

	/**
	 * Slug suffix, appended to 'site-kit/'.
	 *
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Translated ability label.
	 *
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * Translated ability description.
	 *
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * Library sub-group slug.
	 *
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * Site Kit capability composed onto the floor, or '' for floor only.
	 *
	 * @return string
	 */
	abstract protected function site_kit_cap(): string;

	/**
	 * JSON-Schema properties for the ability input, excluding 'confirm'.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * JSON-Schema properties for the ability output payload, excluding the envelope
	 * keys success / message / error_code.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * Required input property names.
	 *
	 * @return string[]
	 */
	abstract protected function required_input(): array;

	/**
	 * Annotation triple: readonly, destructive, idempotent.
	 *
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	abstract protected function annotations(): array;

	/**
	 * Domain work. Returns the payload WITHOUT the 'success' key, or a WP_Error.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * WordPress capability floor for the entire suite.
	 *
	 * DELIBERATELY final, and deliberately manage_options. Site Kit's own model is
	 * looser in places — it grants googlesitekit_view_dashboard to non-administrators
	 * through dashboard sharing — but this suite reaches an OAuth connection to a
	 * Google account that can span Search Console, Analytics, AdSense and Tag Manager.
	 * A capability that a site owner granted for a read-only shared dashboard is not
	 * consent to reach that connection over MCP.
	 *
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * Whether this ability requires confirm:true.
	 *
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Whether Site Kit setup must be complete before run() is reached.
	 *
	 * @return bool
	 */
	protected function requires_setup(): bool {
		return true;
	}

	/**
	 * Whether the CURRENT user must hold a Google token before run() is reached.
	 *
	 * @return bool
	 */
	protected function requires_authentication(): bool {
		return false;
	}

	/**
	 * Module slug that must be active and connected, or '' when none is required.
	 *
	 * @return string
	 */
	protected function required_module(): string {
		return '';
	}

	/**
	 * Optional per-ability sub-group label override.
	 *
	 * @return string
	 */
	protected function sub_group_label(): string {
		return '';
	}

	/**
	 * Display label for every Site Kit sub-group, in one place.
	 *
	 * @return array<string,string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'site-kit-status'  => __( 'Connection & Status', 'acrossai-abilities-manager' ),
			'site-kit-modules' => __( 'Modules & Sharing', 'acrossai-abilities-manager' ),
			'site-kit-data'    => __( 'Search, Traffic & Speed', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Success message. Subclasses may override; a payload 'message' wins over both.
	 *
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Operation completed.', 'acrossai-abilities-manager' );
	}

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
			'sub_group' => $sub_group,
		);

		$label = $this->sub_group_label();
		if ( '' === $label ) {
			$label = $this->sub_group_labels()[ $sub_group ] ?? '';
		}
		if ( '' !== $label ) {
			$acrossai['sub_group_label'] = $label;
		}

		$properties = $this->input_properties();
		$required   = $this->required_input();

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this operation.', 'acrossai-abilities-manager' ),
			);

			// 'confirm' must NOT be schema-required — WP core validates input_schema
			// before execute() runs, so a required confirm fails with a generic
			// ability_invalid_input and assert_confirmed() never fires, which is the
			// whole point of the gate. Stripped defensively so no subclass can
			// reintroduce it.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => 'site-kit/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Site_Kit_Guard::can( $this->site_kit_cap(), $this->permission_floor() ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'success' => array( 'type' => 'boolean' ) ),
						$this->output_properties(),
						array(
							'message'    => array( 'type' => 'string' ),
							'error_code' => array( 'type' => 'string' ),
						)
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => $acrossai,
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * The guard order below is mandatory and is the reason subclasses must not
	 * override this method.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Site_Kit_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return Site_Kit_Guard::fail( $available );
		}

		if ( $this->requires_setup() ) {
			$setup = Site_Kit_Guard::assert_setup();
			if ( is_wp_error( $setup ) ) {
				return Site_Kit_Guard::fail( $setup );
			}
		}

		if ( $this->requires_authentication() ) {
			$authenticated = Site_Kit_Guard::assert_authenticated();
			if ( is_wp_error( $authenticated ) ) {
				return Site_Kit_Guard::fail( $authenticated );
			}
		}

		$module = $this->required_module();
		if ( '' !== $module ) {
			$connected = Site_Kit_Guard::assert_module_connected( $module );
			if ( is_wp_error( $connected ) ) {
				return Site_Kit_Guard::fail( $connected );
			}
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Site_Kit_Guard::assert_confirmed( $input );
			if ( is_wp_error( $confirmed ) ) {
				return Site_Kit_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );
		if ( is_wp_error( $result ) ) {
			return Site_Kit_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Site_Kit_Guard::ok( $result, $message );
	}
}
