<?php
/**
 * Feature 069 — export redirections as server configuration.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Redirections_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #15 — rank-math/export-redirections.
 *
 * Moving redirections into the web server removes a PHP round-trip per redirect, so
 * this is a real performance lever, not just a backup.
 *
 * The apache and nginx output comes from our PORT of Rank Math's private formatters
 * — its own exporter reads $_GET, calls check_admin_referer(), echoes and exits, and
 * every formatter is private. The payload therefore carries format_parity: 'ported'
 * so a caller knows the output was not produced by Rank Math itself, plus a
 * warnings list for sources that could not be serialized cleanly.
 *
 * Read-only, idempotent: this generates text and changes nothing.
 */
class Export_Redirections extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'export-redirections';
	}

	protected function ability_label(): string {
		return __( 'Export Rank Math Redirections', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Export active redirections as JSON, Apache .htaccess rules, or an Nginx server block. Handling redirects in the web server avoids a PHP round-trip each time. Sources with an invalid regular expression are reported in warnings and, matching Rank Math, are commented out in Apache output and omitted from Nginx output. This ability only generates text — it never writes to a server config file.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	/**
	 * Rank Math gates its own export on the general capability, not redirections.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:46
	 */
	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function required_module(): string {
		return Redirections_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'format' => array(
				'type'        => 'string',
				'enum'        => array( 'json', 'apache', 'nginx' ),
				'default'     => 'json',
				'description' => __( 'Output format. json returns structured rows; apache and nginx return server configuration text.', 'acrossai-abilities-manager' ),
			),
			'limit'  => array(
				'type'        => 'integer',
				'default'     => 500,
				'minimum'     => 1,
				'maximum'     => 5000,
				'description' => __( 'Maximum active redirections to include.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'format'        => array( 'type' => 'string' ),
			'content'       => array( 'type' => 'string' ),
			'redirections'  => array( 'type' => 'array' ),
			'rule_count'    => array( 'type' => 'integer' ),
			'warnings'      => array( 'type' => 'array' ),
			'format_parity' => array( 'type' => 'string' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$format = isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : 'json';
		$limit  = isset( $input['limit'] ) ? max( 1, min( 5000, (int) $input['limit'] ) ) : 500;

		if ( 'json' === $format ) {
			$listing = Redirections_Repository::listing( 'active', $limit, 1, '', 'id', 'DESC' );
			if ( is_wp_error( $listing ) ) {
				return $listing;
			}
			return array(
				'format'        => 'json',
				'content'       => '',
				'redirections'  => $listing['redirections'],
				'rule_count'    => $listing['count'],
				'warnings'      => array(),
				'format_parity' => 'native',
				'message'       => sprintf(
					/* translators: %d: number of redirections exported */
					_n( 'Exported %d active redirection as JSON.', 'Exported %d active redirections as JSON.', $listing['count'], 'acrossai-abilities-manager' ),
					$listing['count']
				),
			);
		}

		$result = Redirections_Repository::export( $format, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['redirections'] = array();
		$result['message']      = array() === $result['warnings']
			? sprintf(
				/* translators: 1: number of redirections, 2: format name */
				_n( 'Exported %1$d active redirection as %2$s configuration.', 'Exported %1$d active redirections as %2$s configuration.', $result['rule_count'], 'acrossai-abilities-manager' ),
				$result['rule_count'],
				$format
			)
			: sprintf(
				/* translators: 1: number of redirections, 2: format name, 3: number of warnings */
				__( 'Exported %1$d active redirections as %2$s configuration with %3$d warnings — review them before applying.', 'acrossai-abilities-manager' ),
				$result['rule_count'],
				$format,
				count( $result['warnings'] )
			);

		return $result;
	}
}
