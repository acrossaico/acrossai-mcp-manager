<?php
/**
 * Feature 112 - changes an existing WPCode snippet.
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
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * changes an existing WPCode snippet.
 *
 * @since 0.0.34
 */
final class Update_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/update-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change an existing WPCode snippet: its code, title, note, priority, tags or custom shortcode. Only the fields you supply are touched. If the snippet is currently active WPCode re-runs its activation check on save, so a change that breaks php code will leave the snippet switched off and this ability reports that rather than claiming success. Editing a php or universal snippet requires confirm: true.', 'acrossai-abilities-manager' );
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
			'id'               => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id.', 'acrossai-abilities-manager' ),
			),
			'code_type'        => array(
				'type'        => 'string',
				'enum'        => WPCode_Guard::CODE_TYPES,
				'description' => __( 'Change the code type. Omit to keep the current one.', 'acrossai-abilities-manager' ),
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
		return array( 'id' );
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
	 * Confirm when the snippet is executable code, whether that came from the input or the snippet.
	 *
	 * Reading the stored type matters: omitting code_type on an existing php snippet still edits
	 * code that runs, so keying only on the input would let the gate be skipped by leaving a field
	 * out.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		$type = (string) ( $input['code_type'] ?? '' );

		if ( '' === $type ) {
			$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );
			$type    = is_wp_error( $snippet ) ? '' : (string) $snippet->get_code_type();
		}

		return in_array( $type, WPCode_Guard::EXECUTED_TYPES, true );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This edits a snippet whose code WPCode executes. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$code_type = isset( $input['code_type'] )
			? (string) $input['code_type']
			: (string) $snippet->get_code_type();

		$allowed = WPCode_Guard::assert_code_type( $code_type );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$fields = array_diff( array_keys( $input ), array( 'id', 'confirm', 'apply_wp_slash' ) );

		if ( empty( $fields ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply at least one field to change.', 'acrossai-abilities-manager' )
			);
		}

		$was_active = (bool) $snippet->is_active();

		Snippet_Repository::apply( $snippet, $input );

		$saved = Snippet_Repository::persist( $snippet, $was_active );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'snippet'   => $saved,
			'in_cache'  => $saved['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
		);
	}
}
