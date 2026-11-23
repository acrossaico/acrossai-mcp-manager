<?php
/**
 * Feature 069 — Rank Math Content AI and AI Visibility access.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for the two entitlement-gated Rank Math surfaces.
 *
 * Both Content AI and AI Visibility ship in Rank Math FREE core (research F7). They
 * gate on a connected Rank Math cloud account plus a credit balance, NOT on the PRO
 * plugin — which is why the suite registers these abilities unconditionally and gates
 * them at runtime, rather than declining to register them the way the Elementor suite
 * does for Elementor Pro.
 */
final class Entitlement_Repository {

	/**
	 * Module slugs.
	 */
	public const CONTENT_AI_MODULE   = 'content-ai';
	public const AI_VISIBILITY_MODULE = 'ai-visibility';

	/**
	 * Local prompt/output actions that consume no credits.
	 */
	public const PROMPT_ACTIONS = array( 'save', 'update', 'update-recent' );
	public const OUTPUT_ACTIONS = array( 'save', 'delete' );

	/**
	 * AI Visibility mutation targets.
	 */
	public const AI_TARGETS = array( 'brand', 'query', 'generate-queries' );

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Content AI status: connection, plan, credits and reachability.
	 *
	 * The "can I even do this?" probe. Reads locally and makes no remote request, so it
	 * is safe to call before anything that would spend credits.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function content_ai_status() {
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return new WP_Error( 'rank_math_missing', __( 'Rank Math SEO is not active.', 'acrossai-abilities-manager' ) );
		}

		$registered = class_exists( '\RankMath\Admin\Admin_Helper' )
			&& ! empty( \RankMath\Admin\Admin_Helper::get_registration_data() );

		$credits = null;
		$plan    = '';
		$usage   = array();

		if ( $registered ) {
			if ( method_exists( '\RankMath\Helper', 'get_credits' ) ) {
				$credits = (int) \RankMath\Helper::get_credits();
			}
			if ( method_exists( '\RankMath\Helper', 'get_content_ai_plan' ) ) {
				$plan = (string) \RankMath\Helper::get_content_ai_plan();
			}
			if ( method_exists( '\RankMath\Helper', 'get_usage_details' ) ) {
				$details = \RankMath\Helper::get_usage_details();
				$usage   = is_array( $details ) ? $details : array();
			}
		}

		return array(
			'module_active' => \RankMath\Helper::is_module_active( self::CONTENT_AI_MODULE ),
			'connected'     => $registered,
			'credits'       => $credits,
			'plan'          => $plan,
			'usage'         => $usage,
		);
	}

	/**
	 * Manage the local prompt library.
	 *
	 * Purely local option writes: no credits, no remote request.
	 *
	 * @param string              $action One of self::PROMPT_ACTIONS.
	 * @param array<string,mixed> $data   Payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function manage_prompts( string $action, array $data ) {
		$rest = self::content_ai_rest();
		if ( is_wp_error( $rest ) ) {
			return $rest;
		}
		if ( ! in_array( $action, self::PROMPT_ACTIONS, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma-separated valid actions */
					__( 'action must be one of: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', self::PROMPT_ACTIONS )
				)
			);
		}

		$method = array(
			'save'          => 'save_prompts',
			'update'        => 'update_prompt',
			'update-recent' => 'update_recent_prompt',
		)[ $action ];

		if ( ! method_exists( $rest, $method ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose the Content AI prompt library.', 'acrossai-abilities-manager' ) );
		}

		$rest->$method( self::request( $data ) );

		return array( 'action' => $action );
	}

	/**
	 * Manage stored Content AI outputs.
	 *
	 * @param string              $action One of self::OUTPUT_ACTIONS.
	 * @param array<string,mixed> $data   Payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function manage_output( string $action, array $data ) {
		$rest = self::content_ai_rest();
		if ( is_wp_error( $rest ) ) {
			return $rest;
		}
		if ( ! in_array( $action, self::OUTPUT_ACTIONS, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma-separated valid actions */
					__( 'action must be one of: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', self::OUTPUT_ACTIONS )
				)
			);
		}

		$method = 'save' === $action ? 'save_output' : 'delete_output';
		if ( ! method_exists( $rest, $method ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose Content AI output storage.', 'acrossai-abilities-manager' ) );
		}

		$rest->$method( self::request( $data ) );

		return array( 'action' => $action );
	}

	/**
	 * Run Content AI keyword research.
	 *
	 * CREDIT-METERED. The balance is checked BEFORE the remote call so a zero balance
	 * costs nothing, and the before/after figures are returned so spend is visible.
	 *
	 * @param string $keyword Keyword to research.
	 * @param string $country Country code.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function research_keyword( string $keyword, string $country ) {
		$rest = self::content_ai_rest();
		if ( is_wp_error( $rest ) ) {
			return $rest;
		}

		$credits = Rank_Math_Guard::assert_credits( 1 );
		if ( is_wp_error( $credits ) ) {
			return $credits;
		}

		$before = method_exists( '\RankMath\Helper', 'get_credits' ) ? (int) \RankMath\Helper::get_credits() : null;

		if ( ! method_exists( $rest, 'research_keyword' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose Content AI keyword research.', 'acrossai-abilities-manager' ) );
		}

		$response = $rest->research_keyword(
			self::request(
				array(
					'keyword' => $keyword,
					'country' => '' !== $country ? $country : 'all',
				)
			)
		);

		$after = method_exists( '\RankMath\Helper', 'get_credits' ) ? (int) \RankMath\Helper::get_credits( true ) : null;

		return array(
			'keyword'        => $keyword,
			'country'        => $country,
			'research'       => self::unwrap( $response ),
			'credits_before' => $before,
			'credits_after'  => $after,
		);
	}

	/**
	 * Read one AI Visibility brand.
	 *
	 * @param int $brand_id Brand id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_brand( int $brand_id ) {
		$controller = self::brands_controller();
		if ( is_wp_error( $controller ) ) {
			return $controller;
		}
		if ( $brand_id < 1 ) {
			return new WP_Error( 'invalid_input', __( 'brand_id must be a positive integer.', 'acrossai-abilities-manager' ) );
		}
		if ( ! method_exists( $controller, 'get_brand' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose single-brand reads.', 'acrossai-abilities-manager' ) );
		}

		$response = $controller->get_brand( self::request( array( 'id' => $brand_id ) ) );

		return array(
			'brand_id' => $brand_id,
			'brand'    => self::unwrap( $response ),
		);
	}

	/**
	 * Update an AI Visibility brand or query, or generate queries.
	 *
	 * @param string              $target   One of self::AI_TARGETS.
	 * @param int                 $brand_id Brand id.
	 * @param int                 $query_id Query id, for target=query.
	 * @param array<string,mixed> $data     Fields to write.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update_ai_object( string $target, int $brand_id, int $query_id, array $data ) {
		$controller = self::brands_controller();
		if ( is_wp_error( $controller ) ) {
			return $controller;
		}
		if ( ! in_array( $target, self::AI_TARGETS, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma-separated valid targets */
					__( 'target must be one of: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', self::AI_TARGETS )
				)
			);
		}
		if ( $brand_id < 1 ) {
			return new WP_Error( 'invalid_input', __( 'brand_id must be a positive integer.', 'acrossai-abilities-manager' ) );
		}

		$params = array_merge( $data, array( 'id' => $brand_id ) );
		$method = 'brand';

		if ( 'query' === $target ) {
			if ( $query_id < 1 ) {
				return new WP_Error( 'invalid_input', __( 'target=query requires a query_id.', 'acrossai-abilities-manager' ) );
			}
			$params['query_id'] = $query_id;
			$params['queryId']  = $query_id;
			$method             = 'update_query';
		} elseif ( 'generate-queries' === $target ) {
			// Generating queries calls Rank Math's cloud and consumes credits.
			$credits = Rank_Math_Guard::assert_credits( 1 );
			if ( is_wp_error( $credits ) ) {
				return $credits;
			}
			$method = 'generate_queries';
		} else {
			$method = 'update_brand';
		}

		if ( ! method_exists( $controller, $method ) ) {
			return new WP_Error(
				'rank_math_module_inactive',
				sprintf(
					/* translators: %s: target name */
					__( 'This Rank Math build does not expose the "%s" operation.', 'acrossai-abilities-manager' ),
					$target
				)
			);
		}

		$response = $controller->$method( self::request( $params ) );

		return array(
			'target'   => $target,
			'brand_id' => $brand_id,
			'query_id' => $query_id > 0 ? $query_id : null,
			'result'   => self::unwrap( $response ),
		);
	}

	/**
	 * The Content AI REST handler, gated on module and account.
	 *
	 * @return object|WP_Error
	 */
	private static function content_ai_rest() {
		$module = Rank_Math_Guard::assert_module( self::CONTENT_AI_MODULE );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		$account = Rank_Math_Guard::assert_account();
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		if ( ! class_exists( '\RankMath\ContentAI\Rest' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Content AI module is not available.', 'acrossai-abilities-manager' ) );
		}
		return new \RankMath\ContentAI\Rest();
	}

	/**
	 * The AI Visibility brands controller, gated on module and account.
	 *
	 * @return object|WP_Error
	 */
	private static function brands_controller() {
		$module = Rank_Math_Guard::assert_module( self::AI_VISIBILITY_MODULE );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		$account = Rank_Math_Guard::assert_account();
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		if ( ! class_exists( '\RankMath\AI_Visibility\Api\Brands_Controller' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math AI Visibility module is not available.', 'acrossai-abilities-manager' ) );
		}
		return new \RankMath\AI_Visibility\Api\Brands_Controller();
	}

	/**
	 * Build a WP_REST_Request from a parameter map.
	 *
	 * @param array<string,mixed> $params Parameters.
	 * @return WP_REST_Request
	 */
	private static function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_query_params( $params );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Unwrap a WP_REST_Response, or pass through a plain value.
	 *
	 * @param mixed $response Handler return value.
	 * @return mixed
	 */
	private static function unwrap( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			return $response->get_data();
		}
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		return $response;
	}
}
