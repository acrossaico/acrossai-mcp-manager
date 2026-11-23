<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Get_Site_Icon ability class (absorbed).
 */
class Get_Site_Icon extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/get-site-icon',
			'args' => array(
				'label'               => __( 'Get Site Icon', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns the current site icon (favicon) — attachment ID plus URLs at the standard sizes (32, 192, 270, 512).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'has_icon'      => array( 'type' => 'boolean' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'urls'          => array( 'type' => 'object' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-identity',
						'sub_group_label' => __( 'Site Identity', 'acrossai-abilities-manager' ),
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
		$attachment_id = (int) get_option( 'site_icon', 0 );
		$has_icon      = $attachment_id > 0 && wp_attachment_is_image( $attachment_id );

		$urls = array();
		foreach ( array( 32, 192, 270, 512 ) as $size ) {
			$url = get_site_icon_url( $size );
			if ( $url ) {
				$urls[ (string) $size ] = $url;
			}
		}

		return array(
			'success'       => true,
			'has_icon'      => $has_icon,
			'attachment_id' => $attachment_id,
			'url'           => $has_icon ? (string) get_site_icon_url() : '',
			'urls'          => (object) $urls,
		);
	}
}
