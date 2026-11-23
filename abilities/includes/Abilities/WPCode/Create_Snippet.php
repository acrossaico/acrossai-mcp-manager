<?php
/**
 * Feature 112 - creates a WPCode snippet through WPCode own save pipeline.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WPCode_Snippet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * creates a WPCode snippet through WPCode own save pipeline.
 *
 * @since 0.0.34
 */
final class Create_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/create-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Create a WPCode snippet of any supported type: php, js, css, html, text or universal. The snippet is created INACTIVE; call activate-snippet separately once you have reviewed it. Goes through WPCode own save routine so the loader cache, the code-type term and the error state all stay in step, which a raw custom-post-type write does not do. Creating a php or universal snippet requires confirm: true because that code will execute on the site once activated.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'snippets';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'code_type'        => array(
				'type'        => 'string',
				'enum'        => WPCode_Guard::CODE_TYPES,
				'description' => __( 'Which kind of snippet this is.', 'acrossai-abilities-manager' ),
			),
			'title'            => array(
				'type'        => 'string',
				'description' => __( 'Snippet title.', 'acrossai-abilities-manager' ),
			),
			'code'             => array(
				'type'        => 'string',
				'description' => __( 'The snippet body. For the php type do not include opening PHP tags; for html and universal you may.', 'acrossai-abilities-manager' ),
			),
			'note'             => array(
				'type'        => 'string',
				'description' => __( 'Internal note. Never output anywhere.', 'acrossai-abilities-manager' ),
			),
			'priority'         => array(
				'type'        => 'integer',
				'description' => __( 'Execution order; lower runs earlier. WPCode default is 10.', 'acrossai-abilities-manager' ),
			),
			'custom_shortcode' => array(
				'type'        => 'string',
				'description' => __( 'A custom shortcode tag for rendering this snippet manually.', 'acrossai-abilities-manager' ),
			),
			'tags'             => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Tags, as a list of strings.', 'acrossai-abilities-manager' ),
			),
			'apply_wp_slash'   => Slash_Input::schema_fragment()['apply_wp_slash'],
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'title', 'code', 'code_type' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'snippet'   => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => __( 'The snippet as it now stands, read back after the write.', 'acrossai-abilities-manager' ),
			),
			'in_cache'  => array(
				'type'        => 'boolean',
				'description' => __( 'Whether WPCode loader cache now holds this snippet. This, not the database row, is what decides whether it actually runs.', 'acrossai-abilities-manager' ),
			),
			'safe_mode' => array(
				'type'        => 'boolean',
				'description' => __( 'True when safe mode is suppressing every snippet on this site, in which case nothing runs regardless of this change.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * Only code that WPCode actually executes needs confirming.
	 *
	 * A css or text snippet cannot run anything, so gating it would train a caller to pass
	 * confirm: true reflexively and the flag would stop meaning anything on the calls where it
	 * matters (the Feature 107 lesson).
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		return in_array( (string) ( $input['code_type'] ?? '' ), WPCode_Guard::EXECUTED_TYPES, true );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This creates a snippet whose code WPCode executes. Pass confirm: true to proceed. The snippet is created inactive, so nothing runs until you activate it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$code_type = (string) ( $input['code_type'] ?? '' );
		$allowed   = WPCode_Guard::assert_code_type( $code_type );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$snippet = new WPCode_Snippet( array() );

		Snippet_Repository::apply( $snippet, $input );

		// Created inactive, always. Activation is a separate, deliberate call.
		$snippet->active = false;

		$saved = Snippet_Repository::persist( $snippet, false );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'snippet'   => $saved,
			'in_cache'  => $saved['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => __( 'Snippet created, and left inactive. Call activate-snippet when you are ready for it to run.', 'acrossai-abilities-manager' ),
		);
	}
}
