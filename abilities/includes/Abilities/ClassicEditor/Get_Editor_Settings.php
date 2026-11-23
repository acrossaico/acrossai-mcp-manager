<?php
/**
 * Feature 107 — Get Editor Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ClassicEditor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor\Editor_Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * editor/get-editor-settings — the effective configuration and the layer that decided it.
 */
final class Get_Editor_Settings extends Base_Classic_Editor_Ability {

	protected function slug(): string {
		return 'editor/get-editor-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Editor Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The editor configuration in effect right now, and which layer decided it: the site default, whether users may choose for themselves, the raw stored values, the current user\'s preference, and on multisite whether the network has locked per-site settings. Read this rather than the options directly — the stored value and the effective value are different things here, and a site that has never saved these settings has no stored value at all while still behaving as classic.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'editor-settings';
	}

	protected function input_properties(): array {
		return array(
			'user_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Resolve the per-user preference for this user instead of the current one. Only consulted when users are allowed to choose.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'editor/get-post-editor',
				'reason' => __( 'The site default is not the answer for any particular post — a remembered choice or the post\'s own content can override it.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'users/get-user',
				'reason' => __( 'The per-user preference is user meta under the blog-prefixed key {prefix}classic-editor-settings, values classic or block. Pass it in meta_keys.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'users/update-user',
				'reason' => __( 'To set one user\'s preference, write that same blog-prefixed key via meta. It only takes effect while users are allowed to choose.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function run( array $input ) {
		$user_id  = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
		$settings = Editor_Settings_Repository::describe( $user_id );

		return array(
			'settings' => $settings,
			'message'  => sprintf(
				/* translators: 1: classic or block, 2: the deciding layer */
				__( 'Editor: %1$s (decided by the %2$s setting).', 'acrossai-abilities-manager' ),
				$settings['editor'],
				$settings['decided_by']
			),
		);
	}
}
