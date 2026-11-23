<?php
/**
 * Feature 112 — the sole ability assembler for the WPCode suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * Deliberately small. The suite is four abilities over a plugin with two options, so the base owns
 * only what must not vary: the category, the tab group, the capability floor, the guard order, and
 * the envelope.
 *
 * `Slash_Input` is mandatory on every writer here, which is the opposite of the Classic Editor
 * suite this pattern came from. Snippet code is the archetypal backslash payload — PHP namespaces
 * (\WP_Query), escape sequences ("\n"), regex (/\d+/) — and `WPCode_Snippet::save()` passes the
 * code straight into wp_update_post() WITHOUT slashing (class-wpcode-snippet.php:498-527). Core then
 * unslashes it, stripping one level. WPCode's admin form survives this only because $_POST arrives
 * pre-slashed; an ability receives unslashed JSON and would silently corrupt every snippet
 * containing a backslash. WPCode knows the hazard — its own duplicate() calls wp_slash() first,
 * commented "Let's make sure the slashes don't get removed from the code" — and handled it in that
 * one place only.
 */
abstract class Base_WPCode_Ability extends Ability_Definition {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-wpcode';

	/**
	 * Must equal Integrations\WPCode::TAB_GROUP, or the abilities land in one group and the
	 * dispatcher serves another.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'wpcode';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * The floor, and not overridable.
	 *
	 * WPCode gates its own snippet screens on `wpcode_edit_snippets`, which it grants on activation
	 * only to roles that already hold `manage_options` (class-wpcode-capabilities.php:19-23) — so on
	 * a default site this floor matches theirs. Naming `manage_options` directly rather than
	 * inheriting their capability keeps the floor stable if a role editor later hands
	 * `wpcode_edit_snippets` to an editor: these abilities write executable code and are reachable
	 * by an AI client, which wp-admin is not.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Whether THIS call needs confirmation, as opposed to whether the ability ever does.
	 *
	 * `requires_confirmation()` governs the input schema — it is what puts `confirm` in the
	 * properties, and without it `additionalProperties: false` would reject the key. This governs
	 * the runtime gate. They are separate because an ability can have one input that warrants a
	 * confirmation and another that does not, and gating the harmless one is friction with no risk
	 * behind it.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		unset( $input );

		return $this->requires_confirmation();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'snippets'       => __( 'Snippets', 'acrossai-abilities-manager' ),
			'placement'      => __( 'Placement', 'acrossai-abilities-manager' ),
			'global-scripts' => __( 'Global Scripts', 'acrossai-abilities-manager' ),
			'diagnostics'    => __( 'Diagnostics', 'acrossai-abilities-manager' ),
			'library'        => __( 'Library & Packs', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Done.', 'acrossai-abilities-manager' );
	}

	/**
	 * Assemble the ability definition.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
			'sub_group' => $sub_group,
		);

		$label = $this->sub_group_labels()[ $sub_group ] ?? '';

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

			// Never schema-required: core validates input_schema before execute() runs, so a
			// required confirm yields a generic ability_invalid_input and the gate never fires.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => WPCode_Guard::can( $this->permission_floor() ),
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
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Guards, then the ability, then the envelope.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = WPCode_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return WPCode_Guard::fail( $available );
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = WPCode_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return WPCode_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return WPCode_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return WPCode_Guard::ok( $result, $message );
	}
}
