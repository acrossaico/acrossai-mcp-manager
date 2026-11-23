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
 * Sets the site (custom) logo — the `custom_logo` theme_mod that themes opt
 * into via `add_theme_support( 'custom-logo', ... )`. Accepts a media library
 * attachment_id (must be a registered image), or 0 to remove the current logo.
 *
 * Surfaces a warning when the active theme does not declare custom-logo
 * support (the logo is still saved, but the theme will not render it via
 * `the_custom_logo()`), and when the image dimensions diverge from the
 * theme's declared recommended height/width on non-flex axes.
 */
class Update_Site_Logo extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'settings/update-site-logo',
			'args' => array(
				'label'               => __( 'Update Site Logo', 'acrossai-abilities-manager' ),
				'description'         => __( 'Sets the site (custom) logo to a media library attachment_id. Pass 0 to remove. Stored as the `custom_logo` theme_mod; the active theme must support custom-logo for it to render.', 'acrossai-abilities-manager' ),
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
							'description' => __( 'Media library attachment ID of the image to use as the site logo. Pass 0 to remove.', 'acrossai-abilities-manager' ),
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
						'html'                   => array( 'type' => 'string' ),
						'theme_supports_logo'    => array( 'type' => 'boolean' ),
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
				'message' => __( 'An "attachment_id" is required (pass 0 to remove the site logo).', 'acrossai-abilities-manager' ),
			);
		}

		$attachment_id       = (int) $input['attachment_id'];
		$previous            = (int) get_theme_mod( 'custom_logo', 0 );
		$theme_supports_logo = (bool) current_theme_supports( 'custom-logo' );
		$warnings            = array();

		if ( ! $theme_supports_logo ) {
			$warnings[] = __( 'The active theme does not declare custom-logo support — the logo is saved but the_custom_logo() will not render it. Use this only if the theme has its own logo wiring.', 'acrossai-abilities-manager' );
		}

		if ( 0 === $attachment_id ) {
			remove_theme_mod( 'custom_logo' );
			return array(
				'success'                => true,
				'message'                => __( 'Site logo removed.', 'acrossai-abilities-manager' ),
				'attachment_id'          => 0,
				'previous_attachment_id' => $previous,
				'url'                    => '',
				'html'                   => '',
				'theme_supports_logo'    => $theme_supports_logo,
				'warnings'               => $warnings,
			);
		}

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

		$warnings = array_merge( $warnings, self::dimension_warnings( $attachment_id ) );

		set_theme_mod( 'custom_logo', $attachment_id );

		$url  = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
		$html = (string) get_custom_logo();

		return array(
			'success'                => true,
			/* translators: %d: attachment ID */
			'message'                => sprintf( __( 'Site logo set to attachment %d.', 'acrossai-abilities-manager' ), $attachment_id ),
			'attachment_id'          => $attachment_id,
			'previous_attachment_id' => $previous,
			'url'                    => $url,
			'html'                   => $html,
			'theme_supports_logo'    => $theme_supports_logo,
			'warnings'               => $warnings,
		);
	}

	/**
	 * Compare the uploaded image's dimensions against the theme's declared
	 * recommendation (only on axes that aren't `flex-*`). WP themes pass these
	 * through add_theme_support( 'custom-logo', [ width, height, flex-width,
	 * flex-height ] ).
	 *
	 * @return array<int, string>
	 */
	private static function dimension_warnings( int $attachment_id ): array {
		$out = array();

		$support = get_theme_support( 'custom-logo' );
		$args    = is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? $support[0] : array();
		if ( empty( $args ) ) {
			return $out;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || ! isset( $meta['width'], $meta['height'] ) ) {
			return $out;
		}

		$w           = (int) $meta['width'];
		$h           = (int) $meta['height'];
		$want_w      = isset( $args['width'] ) ? (int) $args['width'] : 0;
		$want_h      = isset( $args['height'] ) ? (int) $args['height'] : 0;
		$flex_width  = ! empty( $args['flex-width'] );
		$flex_height = ! empty( $args['flex-height'] );

		if ( $want_w > 0 && ! $flex_width && $w !== $want_w ) {
			/* translators: 1: actual width, 2: recommended width */
			$out[] = sprintf( __( 'Image width is %1$dpx — the active theme recommends %2$dpx (non-flexible).', 'acrossai-abilities-manager' ), $w, $want_w );
		}
		if ( $want_h > 0 && ! $flex_height && $h !== $want_h ) {
			/* translators: 1: actual height, 2: recommended height */
			$out[] = sprintf( __( 'Image height is %1$dpx — the active theme recommends %2$dpx (non-flexible).', 'acrossai-abilities-manager' ), $h, $want_h );
		}
		return $out;
	}
}
