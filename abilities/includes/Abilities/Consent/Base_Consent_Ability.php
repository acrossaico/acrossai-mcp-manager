<?php
/**
 * Feature 118 — the sole ability assembler for the consent suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Consent;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * The base owns what must not vary: the category, the tab group, the capability floor, the guard
 * order and the envelope — plus one thing specific to this suite, the account gate.
 *
 * **The account gate.** Some of what the consent plugin offers is produced by the vendor's service
 * rather than by the plugin, and is empty until the site is linked to an account. An ability that
 * needs it declares {@see self::requires_account()} and the base refuses BEFORE `run()`, with a
 * message naming the screen, the button and the request to report back. Nothing here performs the
 * linking: it authorises an external account, which is a person's decision to make. There is no
 * "is it connected" ability either — that question is only ever asked after something has already
 * failed for want of it, so the failing call answers it directly (the Feature 112 decision).
 *
 * `Slash_Input` is deliberately ABSENT. The consent plugin's own setters sanitise every field on the
 * way in — `sanitize_text_field()` for names, `wp_filter_post_kses()` for the multilingual fields,
 * `absint()` for the numeric ones — and none of them unslash. Adding a level here would add one
 * nothing removes, the mistake Feature 116 measured twice.
 */
abstract class Base_Consent_Ability extends Ability_Definition {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-consent';

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'cookieyes';

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
	 * Administrator, and not overridable by a subclass.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * Whether this ability needs the site linked to a consent account.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_account(): bool {
		return false;
	}

	/**
	 * What to name in the refusal when the account is missing.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function account_subject(): string {
		return $this->ability_label();
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
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
			'cookies'    => __( 'Cookie list', 'acrossai-abilities-manager' ),
			'categories' => __( 'Consent categories', 'acrossai-abilities-manager' ),
			'banner'     => __( 'Banner', 'acrossai-abilities-manager' ),
			'settings'   => __( 'Settings', 'acrossai-abilities-manager' ),
			'reporting'  => __( 'Reporting', 'acrossai-abilities-manager' ),
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
				'permission_callback' => Consent_Guard::can( $this->permission_floor() ),
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
							'message'     => array( 'type' => 'string' ),
							'error_code'  => array( 'type' => 'string' ),
							'connect_url' => array( 'type' => 'string' ),
							'connected'   => array( 'type' => 'boolean' ),
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
	 * The account gate sits BEFORE the confirmation gate on purpose: being asked to confirm an
	 * operation that cannot run either way wastes a round trip and reads as though confirming would
	 * help.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Consent_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Consent_Guard::fail( $available );
		}

		if ( $this->requires_account() ) {
			$connected = Consent_Guard::assert_connected( $this->account_subject() );

			if ( is_wp_error( $connected ) ) {
				return Consent_Guard::fail( $connected );
			}
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = Consent_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Consent_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Consent_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Consent_Guard::ok( $result, $message );
	}
}
