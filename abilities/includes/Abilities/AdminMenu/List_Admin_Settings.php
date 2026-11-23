<?php
/**
 * Feature 055 — enumerate Settings API sections + fields.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AdminMenu
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AdminMenu;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List every Settings API section + field per settings page. Reads the
 * `$wp_settings_sections` / `$wp_settings_fields` globals populated by
 * `add_settings_section()` / `add_settings_field()` calls.
 */
class List_Admin_Settings extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'admin-menu/list-admin-settings',
			'args' => array(
				'label'               => __( 'List Admin Settings', 'acrossai-abilities-manager' ),
				'description'         => __( 'List every Settings API section + field per settings page. Reads the WP core $wp_settings_sections / $wp_settings_fields globals. Filter with the optional `page` input.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-admin-menu',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'page' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'items'   => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'admin-menu',
						'sub_group_label' => __( 'Admin Menu', 'acrossai-abilities-manager' ),
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		global $wp_settings_sections, $wp_settings_fields;

		$page_filter = isset( $input['page'] ) ? sanitize_key( (string) $input['page'] ) : '';

		// Feature 055 hardening — hard-cap title lengths on emitted fields.
		$max = 200;
		$cap = static function ( string $raw ) use ( $max ): string {
			$t = sanitize_text_field( wp_strip_all_tags( $raw ) );
			return strlen( $t ) > $max ? rtrim( substr( $t, 0, $max ) ) . '...' : $t;
		};

		$items = array();

		if ( is_array( $wp_settings_sections ) ) {
			foreach ( $wp_settings_sections as $page => $sections ) {
				if ( '' !== $page_filter && (string) $page !== $page_filter ) {
					continue;
				}
				if ( ! is_array( $sections ) ) {
					continue;
				}
				foreach ( $sections as $section_id => $section ) {
					$section_title = is_array( $section ) ? $cap( (string) ( $section['title'] ?? '' ) ) : '';
					$fields        = array();
					if ( isset( $wp_settings_fields[ $page ][ $section_id ] ) && is_array( $wp_settings_fields[ $page ][ $section_id ] ) ) {
						foreach ( $wp_settings_fields[ $page ][ $section_id ] as $field_id => $field ) {
							$fields[] = array(
								'id'    => sanitize_text_field( (string) $field_id ),
								'label' => is_array( $field ) ? $cap( (string) ( $field['title'] ?? '' ) ) : '',
							);
						}
					}
					$items[] = array(
						'page'    => sanitize_text_field( (string) $page ),
						'section' => sanitize_text_field( (string) $section_id ),
						'title'   => $section_title,
						'fields'  => $fields,
					);
				}
			}
		}

		return array(
			'success' => true,
			'items'   => $items,
			/* translators: %d: section count */
			'message' => sprintf( __( '%d settings section(s) found.', 'acrossai-abilities-manager' ), count( $items ) ),
		);
	}
}
