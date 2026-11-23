<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Secret_Redactor;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Read_Debug_Log ability class (absorbed).
 */
class Read_Debug_Log extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/read-debug-log',
			'args' => array(
				'label'               => __( 'Read Debug Log', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the contents of wp-content/debug.log. Use the lines parameter to limit output to the last N lines.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'lines' => array(
							'type'        => 'integer',
							'default'     => 0,
							'minimum'     => 0,
							'maximum'     => 10000,
							'description' => __( 'Return only the last N lines. 0 returns the full file.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'content'         => array( 'type' => 'string' ),
						'size'            => array( 'type' => 'integer' ),
						'redacted'        => array( 'type' => 'boolean' ),
						'redaction_count' => array( 'type' => 'integer' ),
						'message'         => array( 'type' => 'string' ),
						'blocked_reason'  => array( 'type' => 'string' ),
						'allowed_roots'   => array( 'type' => 'array' ),
						'path'            => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'debug',
						'sub_group_label' => __( 'Debug', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
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
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$log_path = WP_CONTENT_DIR . '/debug.log';

		// Feature 092: admin-controlled read allowlist gate.
		$blocked = Path_Allowlist_Guard::blocked_read_response( $log_path );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! $fs->is_file( $log_path ) ) {
			$logging_on = ( defined( 'WP_DEBUG_LOG' ) && \WP_DEBUG_LOG ) && ( defined( 'WP_DEBUG' ) && \WP_DEBUG );
			$reason     = $logging_on
				? __( 'No entries have been written yet.', 'acrossai-abilities-manager' )
				: __( 'WP_DEBUG and/or WP_DEBUG_LOG are not enabled in wp-config.php, so nothing is being written.', 'acrossai-abilities-manager' );
			return array(
				'success' => false,
				/* translators: %s: reason the log file is missing */
				'message' => sprintf( __( 'debug.log does not exist. %s', 'acrossai-abilities-manager' ), $reason ),
			);
		}

		$content = $fs->get_contents( $log_path );

		if ( false === $content ) {
			return array(
				'success' => false,
				'message' => __( 'Could not read debug.log.', 'acrossai-abilities-manager' ),
			);
		}

		$lines = isset( $input['lines'] ) ? (int) $input['lines'] : 0;

		if ( $lines > 0 ) {
			$all_lines = explode( "\n", $content );
			$content   = implode( "\n", array_slice( $all_lines, -$lines ) );
		}

		// Feature 092: scrub secrets from the returned log content.
		$scrubbed = Secret_Redactor::scrub( $content );

		return array(
			'success'         => true,
			'content'         => $scrubbed['text'],
			'size'            => strlen( $scrubbed['text'] ),
			'redacted'        => $scrubbed['redacted'],
			'redaction_count' => $scrubbed['redaction_count'],
		);
	}
}
