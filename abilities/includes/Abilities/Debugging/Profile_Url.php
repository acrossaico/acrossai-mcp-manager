<?php
/**
 * Profile_Url ability (Feature 129).
 *
 * Slug: debugging/profile-url
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Query_Monitor_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Url_Profiler;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Profile one URL on this site with Query Monitor and return what happened on it.
 *
 * This is the reason the suite exists: the generic abilities can run a query you
 * name, but none can tell you which queries a given page runs, which are slow or
 * duplicated, what outbound HTTP it makes, which template renders it, or what PHP
 * errors it raises. It fires one SSRF-safe, same-host loopback request with Query
 * Monitor capturing, and returns the structured profile.
 *
 * The loopback authenticates as the administrator running the ability (a
 * single-use, short-lived session), so Query Monitor's own dispatcher activates
 * and runs its full collector pipeline — the only way the database, HTTP and
 * template panels populate. The profile therefore reflects the logged-in
 * administrator view of the page.
 */
class Profile_Url extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'debugging/profile-url',
			'args' => array(
				'label'               => __( 'Profile URL (Query Monitor)', 'acrossai-abilities-manager' ),
				'description'         => __( 'Profiles one URL on this site with Query Monitor and returns its generation time and peak memory, its database query count and total time with the slowest and duplicated queries, its outbound HTTP calls, PHP errors and doing-it-wrong notices, the template that rendered it, and its enqueued-asset counts. Fires a single same-host loopback request authenticated as the administrator running it (a single-use session), so the profile reflects the logged-in administrator view. Requires the Query Monitor plugin to be active.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'            => array(
							'type'        => 'string',
							'description' => __( 'Absolute http(s) URL on this site to profile.', 'acrossai-abilities-manager' ),
						),
						'slowest_limit'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => Url_Profiler::MAX_SLOWEST,
							'default'     => Url_Profiler::DEFAULT_SLOWEST,
							'description' => __( 'How many of the slowest queries to include (1–50, default 10).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'active'       => array( 'type' => 'boolean' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
						'profiled_url' => array( 'type' => 'string' ),
						'http_status'  => array( 'type' => 'integer' ),
						'viewed_as'    => array( 'type' => 'string' ),
						'source'       => array( 'type' => 'string' ),
						'note'         => array( 'type' => 'string' ),
						'profile'      => array( 'type' => 'object', 'additionalProperties' => true ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'query-monitor',
						'sub_group'       => 'query-monitor',
						'sub_group_label' => __( 'Query Monitor', 'acrossai-abilities-manager' ),
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
	 * Execute — profile the requested URL.
	 *
	 * @param array $input Ability input: { url: string, slowest_limit?: int }.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		if ( ! Query_Monitor_Guard::is_active() ) {
			return array( 'success' => false ) + Query_Monitor_Guard::not_active();
		}

		$url = isset( $input['url'] ) ? (string) $input['url'] : '';
		if ( '' === trim( $url ) ) {
			return array(
				'success'    => false,
				'error_code' => 'invalid_url',
				'message'    => __( 'url is required.', 'acrossai-abilities-manager' ),
			);
		}

		$slowest = isset( $input['slowest_limit'] ) ? (int) $input['slowest_limit'] : Url_Profiler::DEFAULT_SLOWEST;

		$result = Url_Profiler::run( $url, $slowest );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'    => false,
				'error_code' => (string) $result->get_error_code(),
				'message'    => $result->get_error_message(),
			);
		}

		return array( 'success' => true ) + $result;
	}
}
