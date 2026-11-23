<?php
/**
 * Feature 132 — AIOSEO install state.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Status_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/get-status.
 *
 * The orientation read: what this AIOSEO install can actually do, before anything tries.
 *
 * It exists because of two things a caller cannot otherwise discover. First, several AIOSEO features
 * are ADDONS rather than core — local business schema, link assistant, video and news sitemaps,
 * image SEO, IndexNow — so "not available here" and "does not exist" are different answers and only
 * this one distinguishes them. Second, AIOSEO keeps its OWN role matrix: a WordPress administrator
 * can still be refused a settings panel if that matrix was edited, and `permitted` per panel is what
 * explains an `aioseo_insufficient_capability` without guessing.
 *
 * Deliberately not an overlap with `aioseo-audit/site-get`, which scores the site's SEO. This
 * describes the plugin, not the content.
 *
 * Read-only, idempotent.
 *
 * @since 0.0.44
 */
class Get_Status extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'get-status';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get All in One SEO Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report what this All in One SEO install can do: its version and whether it is the free or Pro edition, every addon with whether it is installed and active, which AIOSEO capabilities the calling user holds, every settings panel with the capability it needs and whether you may write it, whether the XML sitemap is on, and how many settings backups exist. Call this first: AIOSEO gates several features behind addons and keeps its own role matrix separate from WordPress roles, so this is what distinguishes "not available on this site" from "does not exist" and explains a refused panel before you hit it. For the site\'s SEO score use AIOSEO\'s own aioseo-audit/site-get, which answers a different question.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-status';
	}

	/**
	 * No AIOSEO capability: this ability's whole purpose is to report which capabilities the caller
	 * lacks, so requiring one would make it unavailable exactly when it is most useful.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return '';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'version'      => array( 'type' => 'string' ),
			'edition'      => array( 'type' => 'string' ),
			'addons'       => array( 'type' => 'array' ),
			'capabilities' => array( 'type' => 'object' ),
			'panels'       => array( 'type' => 'array' ),
			'sitemap_on'   => array( 'type' => 'boolean' ),
			'backups'      => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
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
		return Status_Repository::status();
	}
}
