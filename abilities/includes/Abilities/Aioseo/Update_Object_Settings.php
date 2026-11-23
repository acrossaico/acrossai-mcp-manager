<?php
/**
 * Feature 132 — write AIOSEO's per-object search appearance settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Object_Settings_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/update-object-settings.
 *
 * Writes the template layer: change `title` here and every post of that type whose own SEO title is
 * empty follows it. That reach is the reason the description says so plainly — a caller reaching for
 * "set the SEO title" usually means one post, which is AIOSEO's own
 * `aioseo-posts/seo-data-update`.
 *
 * Goes through {@see Settings_Writer::write_object()}, which merges into the full dynamic tree before
 * saving — the same deletion trap as the site-wide store.
 *
 * @since 0.0.44
 */
class Update_Object_Settings extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'update-object-settings';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update All in One SEO Object Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write the global All in One SEO search-appearance settings for one post type, taxonomy or archive: the title and meta description templates, default schema and article type, and robots directives. This is the TEMPLATE layer and it applies to every object of that kind whose own fields are empty — to change one post use AIOSEO\'s own aioseo-posts/seo-data-update instead. Read the object with aioseo/get-object-settings first to discover its field ids; a field that does not belong to it rejects the whole write. Templates accept AIOSEO\'s smart tags, such as #post_title and #separator_sa.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-settings';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_search_appearance_settings';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'scope'    => array(
				'type'        => 'string',
				'enum'        => Object_Settings_Registry::scopes(),
				'description' => __( 'Which kind of object.', 'acrossai-abilities-manager' ),
			),
			'object'   => array(
				'type'        => 'string',
				'description' => __( 'Post type, taxonomy or archive name, e.g. post, page or category.', 'acrossai-abilities-manager' ),
			),
			'settings' => array(
				'type'        => 'object',
				'description' => __( 'Field id => value, as reported by aioseo/get-object-settings. Dotted for nested fields such as advanced.robotsMeta.noindex.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'scope'     => array( 'type' => 'string' ),
			'updated'   => array( 'type' => 'object' ),
			'unchanged' => array( 'type' => 'object' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'scope', 'object', 'settings' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$settings = $input['settings'] ?? array();

		if ( ! is_array( $settings ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'settings must be an object of field id => value.', 'acrossai-abilities-manager' )
			);
		}

		return Settings_Writer::write_object(
			self::scalar( $input['scope'] ?? '' ),
			self::scalar( $input['object'] ?? '' ),
			$settings
		);
	}
}
