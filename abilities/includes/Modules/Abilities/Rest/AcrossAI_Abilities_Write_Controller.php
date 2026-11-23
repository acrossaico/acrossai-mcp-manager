<?php
/**
 * REST sub-controller: create, update, and delete abilities.
 *
 * Handles:
 *   POST /abilities          — create a new db-managed ability (slug prefix injected)
 *   POST /abilities/{id}     — sparse update
 *   DELETE /abilities/{id}   — delete (db source only)
 *   DELETE /abilities/{slug}/override — delete the override row for a non-db ability
 *
 * Security contract:
 *   - All endpoints gate on manage_options via the shared orchestrator check_permission().
 *   - before_save hook receives sanitized fields only (SEC-02).
 *   - after_save hook receives the full re-read persisted row (BUG-PARTIAL-HOOK-FIELDS).
 *   - source field is server-controlled and never accepted from the request body.
 *   - For source≠db rows, identity/execution fields are stripped before write.
 *   - Audit fields (created_at, created_by) are never overwritten on update.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Ability_Override_Processor;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Validator;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Sanitizer;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Formatter;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Merger;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Source_Detector;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Protected_Abilities;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Sanitizer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles write operations for database-managed abilities.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Write_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Write_Controller|null
	 */
	protected static $instance = null;

	/**
	 * DB query instance.
	 *
	 * @var AcrossAI_Abilities_Query
	 */
	private $db_query;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Write_Controller
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->db_query = AcrossAI_Abilities_Query::instance();
	}

	/**
	 * Register REST routes owned by this controller.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		// Create.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ability' ),
					'permission_callback' => $permission,
				),
			)
		);

		// Delete override row for non-db ability.
		// Three-segment path /abilities/{slug}/override is naturally distinct from two-segment /abilities/{slug}.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/(?P<slug>[^/]+)/override',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_override' ),
					'permission_callback' => $permission,
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $slug ) {
								// SEC-003: rawurldecode runs before sanitize_ability_slug so %2F-encoded
								// slashes in namespaced slugs are handled correctly. The allowlist regex
								// in sanitize_ability_slug strips all non-whitelisted chars post-decode.
								return AcrossAI_Sanitizer::sanitize_ability_slug( rawurldecode( (string) $slug ) );
							},
							'validate_callback' => function ( $slug ) {
								return is_string( $slug ) && '' !== trim( $slug );
							},
						),
					),
				),
			)
		);

		// Update + Delete by slug.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ability' ),
					'permission_callback' => $permission,
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $slug ) {
								// SEC-003: rawurldecode runs before sanitize_ability_slug so %2F-encoded
								// slashes in namespaced slugs are handled correctly. The allowlist regex
								// in sanitize_ability_slug strips all non-whitelisted chars post-decode.
								return AcrossAI_Sanitizer::sanitize_ability_slug( rawurldecode( (string) $slug ) );
							},
							'validate_callback' => function ( $slug ) {
								return is_string( $slug ) && '' !== trim( $slug );
							},
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ability' ),
					'permission_callback' => $permission,
					'args'                => array(
						'slug' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $slug ) {
								// SEC-003: rawurldecode runs before sanitize_ability_slug so %2F-encoded
								// slashes in namespaced slugs are handled correctly. The allowlist regex
								// in sanitize_ability_slug strips all non-whitelisted chars post-decode.
								return AcrossAI_Sanitizer::sanitize_ability_slug( rawurldecode( (string) $slug ) );
							},
							'validate_callback' => function ( $slug ) {
								return is_string( $slug ) && '' !== trim( $slug );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Handle POST /abilities — create a new database-managed ability.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_ability( \WP_REST_Request $request ) {
		// Sanitize inputs.
		$fields = AcrossAI_Abilities_Sanitizer::sanitize_create_request( $request );

		// Presence guards — required fields must be non-empty on create (SEC-02, SEC-04).
		// These fire on sanitized $fields, before the shared validator, to produce
		// specific error codes for missing required identity fields (FR-013).
		if ( '' === trim( (string) ( $fields['label'] ?? '' ) ) ) {
			return new \WP_Error( 'missing_label', __( 'Ability label is required.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( (string) ( $fields['description'] ?? '' ) ) ) {
			return new \WP_Error( 'missing_description', __( 'Ability description is required.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( (string) ( $fields['category'] ?? '' ) ) ) {
			return new \WP_Error( 'missing_category', __( 'Ability category is required.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}

		// Validate.
		$valid = AcrossAI_Abilities_Validator::validate_ability( $fields, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Build full ability_slug from suffix + prefix injection.
		$suffix = (string) ( $fields['slug_suffix'] ?? '' );
		unset( $fields['slug_suffix'] );
		$fields['ability_slug'] = 'acrossai/' . $suffix;

		// Duplicate slug guard.
		if ( $this->db_query->slug_exists( $fields['ability_slug'] ) ) {
			return new \WP_Error(
				'rest_duplicate_slug',
				__( 'An ability with this slug already exists.', 'acrossai-abilities-manager' ),
				array( 'status' => 409 )
			);
		}

		// Server-controlled fields.
		$fields['source'] = 'db';
		if ( empty( $fields['status'] ) ) {
			$fields['status'] = 'draft';
		}
		if ( empty( $fields['callback_type'] ) ) {
			$fields['callback_type'] = 'noop';
		}

		/**
		 * Fires before creating an ability.
		 *
		 * @since 0.1.0
		 * @param array $fields Sanitized fields to insert.
		 */
		do_action( 'acrossai_abilities_before_create', $fields );

		$new_id = $this->db_query->insert_ability( $fields );
		if ( false === $new_id ) {
			return new \WP_Error( 'rest_create_failed', __( 'Failed to create ability.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
		}

		$saved_row = $this->db_query->get_ability_by_id( $new_id );
		if ( null === $saved_row ) {
			return new \WP_Error( 'rest_create_failed', __( 'Ability was created but could not be retrieved.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after creating an ability — receives the full persisted row (BUG-PARTIAL-HOOK-FIELDS).
		 *
		 * @since 0.1.0
		 * @param \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row $saved_row Full persisted row.
		 */
		do_action( 'acrossai_abilities_after_create', $saved_row );

		$response = rest_ensure_response( AcrossAI_Abilities_Formatter::format_for_response( $saved_row ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * Handle POST /abilities/{slug} — sparse update (db) or override upsert (non-db).
	 *
	 * For source=db abilities, performs a sparse column update.
	 * For registry abilities (source≠db), saves overridable fields only via save_override().
	 * SEC-ADVISORY-02: sequence is sanitize → strip_protected → save_override.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_ability( \WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' ); // Pre-sanitized via route arg sanitize_callback.

		// RT-5 / V8: Reject modifications to protected abilities.
		if ( in_array( $slug, AcrossAI_Protected_Abilities::get_protected_slugs(), true ) ) {
			return new \WP_Error( 'rest_protected_ability', __( 'This ability cannot be modified.', 'acrossai-abilities-manager' ), array( 'status' => 403 ) );
		}

		// SEC-ADVISORY-02: sanitize first, before any branching.
		$fields = AcrossAI_Abilities_Sanitizer::sanitize_update_request( $request );

		// Try DB-managed ability first.
		$existing = $this->db_query->get_ability_by_slug( $slug );

		if ( null !== $existing && 'db' === $existing->source ) {
			// ── DB update path ───────────────────────────────────────────────
			if ( empty( $fields ) ) {
				return rest_ensure_response( AcrossAI_Abilities_Formatter::format_for_response( $existing ) );
			}

			$validate_context                  = $fields;
			$validate_context['callback_type'] = $fields['callback_type'] ?? $existing->callback_type;
			$valid                             = AcrossAI_Abilities_Validator::validate_ability( $validate_context, false );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			do_action( 'acrossai_abilities_before_update', $existing->id, $fields );

			$updated = $this->db_query->update_ability( $existing->id, $fields );
			if ( ! $updated ) {
				return new \WP_Error( 'rest_update_failed', __( 'Failed to update ability.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
			}

			$saved_row = $this->db_query->get_ability_by_slug( $slug );
			if ( null === $saved_row ) {
				return new \WP_Error( 'rest_update_failed', __( 'Ability was updated but could not be retrieved.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
			}

			do_action( 'acrossai_abilities_after_update', $saved_row );
			AcrossAI_Ability_Override_Processor::bust_cache(); // SEC-GUARDRAIL-01: bust_cache after DB update.

			return rest_ensure_response( AcrossAI_Abilities_Formatter::format_for_response( $saved_row ) );
		}

		// ── Non-db (registry) override upsert path ───────────────────────
		// wp_get_ability() returns null if the slug is not registered in WP 6.9+.
		$registry_raw = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( null === $registry_raw ) {
			return new \WP_Error( 'rest_not_found', __( 'Ability not found.', 'acrossai-abilities-manager' ), array( 'status' => 404 ) );
		}

		// SEC-ADVISORY-02: strip_protected after sanitize, before save.
		$fields = AcrossAI_Abilities_Sanitizer::strip_protected_fields_for_non_db( $fields );

		if ( empty( $fields ) ) {
			$override_row = $this->db_query->get_override_by_slug( $slug );
			$registry     = AcrossAI_Ability_Merger::normalize_registry( $registry_raw );
			$merged       = AcrossAI_Ability_Merger::merge( $registry, $override_row );
			do_action( 'acrossai_abilities_after_update', $merged );
			return rest_ensure_response( AcrossAI_Abilities_Formatter::format_merged_ability( $merged ) );
		}

		// RF-04: source is server-controlled — detect from registry, never from request body.
		$registry_arr     = AcrossAI_Ability_Merger::normalize_registry( $registry_raw );
		$fields['source'] = AcrossAI_Ability_Source_Detector::detect( $registry_arr );

		$override_row = $this->db_query->save_override( $slug, $fields );
		if ( false === $override_row ) {
			return new \WP_Error( 'save_override_failed', __( 'Failed to confirm saved override.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
		}

		// SEC-GUARDRAIL-01: bust_cache() only — do not call other methods on Override Processor.
		AcrossAI_Ability_Override_Processor::bust_cache();

		$registry = AcrossAI_Ability_Merger::normalize_registry( $registry_raw );
		$merged   = AcrossAI_Ability_Merger::merge( $registry, $override_row );
		return rest_ensure_response( AcrossAI_Abilities_Formatter::format_merged_ability( $merged ) );
	}


	/**
	 * Handle DELETE /abilities/{slug}.
	 *
	 * Only source=db rows may be deleted (FR-006).
	 * SEC-ADVISORY-01: authorization check uses $existing->source from DB row,
	 * never from registry — the DB row is authoritative.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_ability( \WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' ); // Pre-sanitized via route arg sanitize_callback.

		// SEC-ADVISORY-01: look up from DB row — $existing->source is authoritative.
		$existing = $this->db_query->get_ability_by_slug( $slug );
		if ( null === $existing ) {
			return new \WP_Error( 'rest_not_found', __( 'Ability not found.', 'acrossai-abilities-manager' ), array( 'status' => 404 ) );
		}

		// Reject delete for non-db rows (FR-006); SEC-ADVISORY-01: use $existing->source.
		if ( 'db' !== $existing->source ) {
			return new \WP_Error(
				'rest_delete_forbidden',
				__( 'Only database-managed abilities may be deleted.', 'acrossai-abilities-manager' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Fires before deleting an ability.
		 *
		 * @since 0.1.0
		 * @param \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row $existing Row about to be deleted.
		 */
		do_action( 'acrossai_abilities_before_delete', $existing );

		$deleted = $this->db_query->delete_ability( $existing->id );
		if ( ! $deleted ) {
			return new \WP_Error( 'rest_delete_failed', __( 'Failed to delete ability.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after deleting an ability.
		 *
		 * @since 0.1.0
		 * @param int    $id   Deleted ability row ID.
		 * @param string $slug Deleted ability slug.
		 */
		do_action( 'acrossai_abilities_after_delete', $existing->ability_slug ); // T011/SEC-GUARDRAIL-01: single slug arg.
		AcrossAI_Ability_Override_Processor::bust_cache(); // SEC-GUARDRAIL-01: bust_cache after delete.

		// V14/RT-10: return 200 with body instead of 204 for client confirmation.
		return new \WP_REST_Response(
			array(
				'deleted' => true,
				'slug'    => $slug,
			),
			200
		);
	}
	/**
	 * Handle DELETE /abilities/{slug}/override — delete the override DB row for a non-db ability.
	 *
	 * Removes the stored override record, restoring the ability to its registry defaults.
	 * Only valid for non-db (plugin/core/theme) abilities that have a saved override row.
	 * db-source abilities ARE their row and cannot use this endpoint.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_override( \WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' ); // Pre-sanitized via route arg sanitize_callback.

		// Reject modifications to protected abilities.
		if ( in_array( $slug, AcrossAI_Protected_Abilities::get_protected_slugs(), true ) ) {
			return new \WP_Error( 'rest_protected_ability', __( 'This ability cannot be modified.', 'acrossai-abilities-manager' ), array( 'status' => 403 ) );
		}

		$override_row = $this->db_query->get_override_by_slug( $slug );
		if ( null === $override_row ) {
			return new \WP_Error( 'rest_no_override', __( 'No override record found for this ability.', 'acrossai-abilities-manager' ), array( 'status' => 404 ) );
		}

		// db-source rows ARE the ability — they have no "override" to clear.
		if ( 'db' === $override_row->source ) {
			return new \WP_Error(
				'rest_delete_forbidden',
				__( 'Custom abilities do not have an override record to clear.', 'acrossai-abilities-manager' ),
				array( 'status' => 400 )
			);
		}

		$deleted = $this->db_query->delete_ability( $override_row->id );
		if ( ! $deleted ) {
			return new \WP_Error( 'rest_delete_failed', __( 'Failed to delete override record.', 'acrossai-abilities-manager' ), array( 'status' => 500 ) );
		}

		// SEC-GUARDRAIL-01: bust_cache() only.
		AcrossAI_Ability_Override_Processor::bust_cache();

		// Return the fresh merged registry view (no override row now).
		$registry_raw = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
		if ( null === $registry_raw ) {
			// Ability was removed from registry after the override was saved — return minimal payload.
			return new \WP_REST_Response(
				array(
					'deleted' => true,
					'slug'    => $slug,
				),
				200
			);
		}

		$registry = AcrossAI_Ability_Merger::normalize_registry( $registry_raw );
		$merged   = AcrossAI_Ability_Merger::merge( $registry, null );

		/**
		 * Fires after clearing all overrides for a non-db ability.
		 *
		 * @since 0.1.0
		 * @param array $merged Merged ability data after override removal.
		 */
		do_action( 'acrossai_abilities_after_update', $merged );

		return rest_ensure_response( AcrossAI_Abilities_Formatter::format_merged_ability( $merged ) );
	}
}
