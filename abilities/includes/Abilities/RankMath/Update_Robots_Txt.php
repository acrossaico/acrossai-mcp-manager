<?php
/**
 * Feature 069 — write Rank Math's virtual robots.txt.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #6 — rank-math/update-robots-txt.
 *
 * Separate from update-general-settings even though the field lives in the same
 * option blob, because the write is conditional on state the caller cannot see:
 * a physical robots.txt on disk makes Rank Math's virtual editor inert, and a
 * non-public site suppresses the output. Silently accepting a write that will
 * never take effect is worse than refusing it.
 */
class Update_Robots_Txt extends Base_Rank_Math_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'update-robots-txt';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Rank Math robots.txt', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Replace the contents of Rank Math\'s virtual robots.txt. Refuses the write and reports why when a physical robots.txt exists on disk (which overrides the virtual one) or when the site is not public. Read the current content and state first with rank-math/get-settings-panel panel=general-robots-txt.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function sub_group(): string {
		return 'rank-math-settings';
	}

	/**
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'general';
	}

	/**
	 * @return string
	 */
	protected function required_module(): string {
		return 'robots-txt';
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'content' => array(
				'type'        => 'string',
				'description' => __( 'Full robots.txt content. Line breaks are preserved. Replaces the existing content entirely.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'content' => array( 'type' => 'string' ),
			'state'   => array( 'type' => 'object' ),
		);
	}

	/**
	 * @return string[]
	 */
	protected function required_input(): array {
		return array( 'content' );
	}

	/**
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) {
			return new WP_Error( 'invalid_input', __( 'content must be a string.', 'acrossai-abilities-manager' ) );
		}

		$physical = ABSPATH . 'robots.txt';
		if ( file_exists( $physical ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: absolute path to the physical robots.txt */
					__( 'A physical robots.txt exists at %s and overrides the virtual one, so this write would have no effect. Remove the file first.', 'acrossai-abilities-manager' ),
					$physical
				)
			);
		}

		if ( ! get_option( 'blog_public' ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'The site is set to discourage search engines, so Rank Math suppresses robots.txt output. Enable search-engine visibility in Settings → Reading first.', 'acrossai-abilities-manager' )
			);
		}

		$result = Settings_Writer::save( 'general-robots-txt', '', array( 'robots_txt_content' => $input['content'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'content' => (string) ( $result['updated']['robots_txt_content'] ?? '' ),
			'state'   => array(
				'editable'             => true,
				'physical_file_exists' => false,
				'site_not_public'      => false,
			),
			'message' => __( 'Updated the Rank Math virtual robots.txt.', 'acrossai-abilities-manager' ),
		);
	}
}
