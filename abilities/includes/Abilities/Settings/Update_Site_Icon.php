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
 * Sets the site icon (favicon). Accepts a media library attachment_id (must
 * be a registered image attachment), or 0/null to remove the current icon.
 *
 * WordPress recommends a 512×512 image but does not enforce it; this ability
 * surfaces a warning when the attachment is smaller than that on either axis.
 */
class Update_Site_Icon extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/update-site-icon',
			'args' => array(
				'label'               => __( 'Update Site Icon', 'acrossai-abilities-manager' ),
				'description'         => __( 'Sets the site icon to a media library attachment_id. Pass 0 to remove. WordPress recommends a 512×512 image.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-settings',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'Media library attachment ID of the image to use as the site icon. Pass 0 to remove.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'attachment_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'                => array( 'type' => 'boolean' ),
						'message'                => array( 'type' => 'string' ),
						'attachment_id'          => array( 'type' => 'integer' ),
						'previous_attachment_id' => array( 'type' => 'integer' ),
						'url'                    => array( 'type' => 'string' ),
						'urls'                   => array( 'type' => 'object' ),
						'warnings'               => array( 'type' => 'array' ),
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
						'readonly'    => false,
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
		if ( ! array_key_exists( 'attachment_id', $input ) ) {
			return array(
				'success' => false,
				'message' => __( 'An "attachment_id" is required (pass 0 to remove the site icon).', 'acrossai-abilities-manager' ),
			);
		}

		$attachment_id = (int) $input['attachment_id'];
		$previous      = (int) get_option( 'site_icon', 0 );
		$warnings      = array();

		if ( 0 === $attachment_id ) {
			delete_option( 'site_icon' );
			return array(
				'success'                => true,
				'message'                => __( 'Site icon removed.', 'acrossai-abilities-manager' ),
				'attachment_id'          => 0,
				'previous_attachment_id' => $previous,
				'url'                    => '',
				'urls'                   => (object) array(),
				'warnings'               => $warnings,
			);
		}

		// Validate it's an image attachment.
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array(
				'success' => false,
				/* translators: %d: attachment ID */
				'message' => sprintf( __( 'Attachment %d not found.', 'acrossai-abilities-manager' ), $attachment_id ),
			);
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return array(
				'success' => false,
				/* translators: %d: attachment ID */
				'message' => sprintf( __( 'Attachment %d is not an image.', 'acrossai-abilities-manager' ), $attachment_id ),
			);
		}

		// Surface a recommendation warning if the source is smaller than 512×512.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			$w = (int) $meta['width'];
			$h = (int) $meta['height'];
			if ( $w < 512 || $h < 512 ) {
				/* translators: 1: width, 2: height */
				$warnings[] = sprintf( __( 'Image is %1$d×%2$d — WordPress recommends 512×512 or larger for the site icon.', 'acrossai-abilities-manager' ), $w, $h );
			}
			if ( $w !== $h ) {
				$warnings[] = __( 'Image is not square — the site icon will appear letter-boxed on some platforms.', 'acrossai-abilities-manager' );
			}
		}

		update_option( 'site_icon', $attachment_id );

		$urls = array();
		foreach ( array( 32, 192, 270, 512 ) as $size ) {
			$url = get_site_icon_url( $size );
			if ( $url ) {
				$urls[ (string) $size ] = $url;
			}
		}

		return array(
			'success'                => true,
			/* translators: %d: attachment ID */
			'message'                => sprintf( __( 'Site icon set to attachment %d.', 'acrossai-abilities-manager' ), $attachment_id ),
			'attachment_id'          => $attachment_id,
			'previous_attachment_id' => $previous,
			'url'                    => (string) get_site_icon_url(),
			'urls'                   => (object) $urls,
			'warnings'               => $warnings,
		);
	}
}
