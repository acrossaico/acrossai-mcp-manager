<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Mime_Types_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Read the current Media Library upload allowlist and identify which
 * entries were added by this plugin's Core settings tab.
 */
class List_Upload_Mime_Types extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/list-upload-mime-types',
			'args' => array(
				'label'               => __( 'List Allowed Upload MIME Types', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the effective allowlist for the upload-media ability — the site\'s standard allowlist (from get_allowed_mime_types()) merged with the extras persisted by this plugin. Each row is annotated by source: WordPress core default, this plugin, or another plugin/filter. The plugin\'s extras only apply during upload-media calls; regular Media Library uploads via wp-admin are not affected.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_extras_only' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'If true, "allowed" only includes rows this plugin added; core defaults and other filters are omitted.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'allowed' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'ext'    => array( 'type' => 'string' ),
									'mime'   => array( 'type' => 'string' ),
									'source' => array(
										'type' => 'string',
										'enum' => array( 'core', 'this-plugin', 'other-filter' ),
									),
								),
							),
						),
						'extras'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'ext'  => array( 'type' => 'string' ),
									'mime' => array( 'type' => 'string' ),
								),
							),
						),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'allowed', 'extras' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Manage', 'acrossai-abilities-manager' ),
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
		$extras_only = ! empty( $input['include_extras_only'] );
		$core        = wp_get_mime_types();
		$extras      = Mime_Types_Store::get();
		// The extras are NOT globally applied to get_allowed_mime_types() —
		// they only fire during upload-media itself. Compute the effective
		// allowlist for the ability by merging explicitly.
		$rows = Mime_Types_Store::filter_upload_mimes( get_allowed_mime_types() );
		ksort( $rows );

		$allowed = array();
		foreach ( $rows as $ext_key => $mime ) {
			$source = Mime_Types_Store::attribute( (string) $ext_key, (string) $mime, $core, $extras );
			if ( $extras_only && 'this-plugin' !== $source ) {
				continue;
			}
			$allowed[] = array(
				'ext'    => (string) $ext_key,
				'mime'   => (string) $mime,
				'source' => $source,
			);
		}

		$extras_out = array();
		foreach ( $extras as $ext => $mime ) {
			$extras_out[] = array(
				'ext'  => (string) $ext,
				'mime' => (string) $mime,
			);
		}

		return array(
			'success' => true,
			'allowed' => $allowed,
			'extras'  => $extras_out,
		);
	}
}
