<?php
/**
 * BerlinDB Query class for the unified abilities table (Abilities module view).
 *
 * Self-contained query class for the Abilities module. Owns all DB interactions
 * with the acrossai_abilities table. Supersedes the Spec 009 design decision that
 * reused the Sitewide module Schema and Row classes (superseded by Feature 012 — Q3).
 *
 * Architecture contract:
 *   $table_name   = 'acrossai_abilities'
 *   $table_schema = AcrossAI_Abilities_Schema::class
 *   $item_shape   = AcrossAI_Abilities_Row::class
 *
 * JSON encode/decode uses AcrossAI_Abilities_Row::get_json_fields() as the shared field registry.
 *
 * AUTHORIZATION CONTRACT (DEC-BY-SOURCE-AUTHZ):
 * All public methods here are authorization-free DB helpers.
 * Every caller that surfaces results to a HTTP response MUST enforce
 * current_user_can( 'manage_options' ) before invoking these methods.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Database
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database;

use BerlinDB\Database\Kern\Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Schema;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Sanitizer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * CRUD, browse, and runtime-publication query helpers for the unified abilities table.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Query extends Query {

	/**
	 * Schema class for this query.
	 *
	 * @var string
	 */
	protected $table_schema = AcrossAI_Abilities_Schema::class;

	/**
	 * Row class for query results.
	 *
	 * @var string
	 */
	protected $item_shape = AcrossAI_Abilities_Row::class;

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table_name = 'acrossai_abilities';

	/**
	 * SQL alias used in JOIN expressions (first letter of each table-name segment).
	 *
	 * @var string
	 */
	protected $table_alias = 'aa';

	/**
	 * Singular item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name = 'ability';

	/**
	 * Plural item name — used for BerlinDB hook name generation.
	 *
	 * @var string
	 */
	protected $item_name_plural = 'abilities';

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	protected $cache_group = 'acrossai-abilities';

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Query|null
	 */
	protected static $instance = null;

	/**
	 * Maximum JSON field size in bytes (64 KB — consistent with original Query guard (Feature 009)).
	 *
	 * @var int
	 */
	const MAX_JSON_BYTES = 65536;

	/**
	 * Protected fields that must not be overwritten for source≠db rows.
	 *
	 * @var string[]
	 */
	const PROTECTED_FIELDS = array(
		'label',
		'description',
		'category',
		'callback_type',
		'callback_config',
		'input_schema',
		'output_schema',
		'source',
		'ability_slug',
	);

	/**
	 * Private constructor — enforces singleton pattern.
	 *
	 * @since  0.1.0
	 */
	private function __construct() {
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Query
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// CRUD helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a new ability row.
	 *
	 * @since  0.1.0
	 * @param  array $fields Column values. `ability_slug`, `source`, `created_at`, `created_by` are set here.
	 * @return int|false  New row ID on success, false on failure.
	 */
	public function insert_ability( array $fields ) {
		$fields = $this->prepare_fields_for_write( $fields );

		$now = current_time( 'mysql', true );
		$uid = get_current_user_id();

		$fields['created_at'] = $now;
		$fields['updated_at'] = $now;
		$fields['created_by'] = $uid;

		$result = $this->add_item( $fields );
		if ( false === $result || (int) $result <= 0 ) {
			return false;
		}
		return (int) $result;
	}

	/**
	 * Retrieve a single ability row by integer ID.
	 *
	 * @internal Used internally by save_override() to bypass the BerlinDB slug cache (Bug 4 fix).
	 *           Also used in create_ability().
	 * @since    0.1.0
	 * @param    int $id Row primary key.
	 * @return   AcrossAI_Abilities_Row|null
	 */
	public function get_ability_by_id( int $id ): ?AcrossAI_Abilities_Row {
		$results = $this->query(
			array(
				'id'     => $id,
				'number' => 1,
			)
		);

		if ( empty( $results ) || ! $results[0] instanceof AcrossAI_Abilities_Row ) {
			return null;
		}
		return $results[0];
	}

	/**
	 * Retrieve a single ability row by slug.
	 *
	 * @since  0.1.0
	 * @param  string $slug Full ability slug (e.g. 'acrossai/my-ability').
	 * @return AcrossAI_Abilities_Row|null
	 */
	public function get_ability_by_slug( string $slug ): ?AcrossAI_Abilities_Row {
		$results = $this->query(
			array(
				'ability_slug' => $slug,
				'number'       => 1,
			)
		);

		if ( empty( $results ) || ! $results[0] instanceof AcrossAI_Abilities_Row ) {
			return null;
		}
		return $results[0];
	}

	/**
	 * Sparse-update an existing ability row.
	 *
	 * Only the columns present in $fields are written. Protected fields are
	 * automatically stripped for source≠db rows by the caller (Write controller).
	 *
	 * @since  0.1.0
	 * @param  int   $id     Row primary key.
	 * @param  array $fields Column values to update.
	 * @return bool
	 */
	public function update_ability( int $id, array $fields ): bool {
		$fields = $this->prepare_fields_for_write( $fields );

		$fields['updated_at'] = current_time( 'mysql', true );
		$fields['updated_by'] = get_current_user_id();

		// Ensure created_at/created_by are never overwritten on update (A1/A2).
		unset( $fields['created_at'], $fields['created_by'] );

		$result = $this->update_item( $id, $fields );
		return false !== $result;
	}

	/**
	 * Delete an ability row by integer ID.
	 *
	 * @since  0.1.0
	 * @param  int $id Row primary key.
	 * @return bool
	 */
	public function delete_ability( int $id ): bool {
		$result = $this->delete_item( $id );
		return false !== $result;
	}

	/**
	 * Check whether a given slug already exists in the table.
	 *
	 * @since  0.1.0
	 * @param  string $slug Full ability slug.
	 * @return bool
	 */
	public function slug_exists( string $slug ): bool {
		return null !== $this->get_ability_by_slug( $slug );
	}


	// -------------------------------------------------------------------------
	// Override CRUD helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve an override row by ability slug.
	 *
	 * AUTHORIZATION CONTRACT (DEC-BY-SOURCE-AUTHZ):
	 * This is a raw DB-layer helper. It performs no capability check.
	 * Every caller that surfaces results to a HTTP response or admin screen
	 * MUST call current_user_can( 'manage_options' ) before invoking this method.
	 * REST controller permission_callback is the canonical gate.
	 *
	 * @since  0.1.0
	 * @param  string $slug Ability slug.
	 * @return AcrossAI_Abilities_Row|null
	 */
	public function get_override_by_slug( string $slug ): ?AcrossAI_Abilities_Row {
		$slug    = AcrossAI_Sanitizer::sanitize_ability_slug( $slug );
		$results = $this->query(
			array(
				'ability_slug' => $slug,
				'number'       => 1,
			)
		);

		if ( empty( $results ) || ! $results[0] instanceof AcrossAI_Abilities_Row ) {
			return null;
		}

		return $results[0];
	}

	/**
	 * Insert or update an override record for the given slug.
	 *
	 * On INSERT: sets created_at and created_by.
	 * On UPDATE: sets updated_at and updated_by only — does NOT overwrite created_at/created_by (A1/A2).
	 * On success, returns the freshly re-read row (bypasses BerlinDB slug cache — Bug 4 fix).
	 *
	 * AUTHORIZATION CONTRACT (DEC-BY-SOURCE-AUTHZ):
	 * This is a raw DB-layer helper. It performs no capability check.
	 * Every caller that surfaces results to a HTTP response or admin screen
	 * MUST call current_user_can( 'manage_options' ) before invoking this method.
	 * REST controller permission_callback is the canonical gate.
	 *
	 * @since  0.1.0
	 * @param  string $slug   Ability slug.
	 * @param  array  $fields Field values to save.
	 * @return AcrossAI_Abilities_Row|false The saved row on success, false on failure.
	 */
	public function save_override( string $slug, array $fields ) {
		$slug = AcrossAI_Sanitizer::sanitize_ability_slug( $slug );
		// SEC-002: save_override is exclusively for non-db (registry) abilities.
		// Strip source='db' to prevent source-injection via call-site ordering errors
		// (e.g., if strip_protected_fields_for_non_db is ever skipped upstream).
		if ( array_key_exists( 'source', $fields ) && 'db' === $fields['source'] ) {
			unset( $fields['source'] );
		}
		$fields = $this->prepare_fields_for_write( $fields );

		$existing = $this->get_override_by_slug( $slug );
		$now      = current_time( 'mysql', true );
		$user_id  = get_current_user_id();

		if ( null === $existing ) {
			// INSERT path — add_item() returns the new integer ID on success or false.
			$fields['ability_slug'] = $slug;
			$fields['created_at']   = $now;
			$fields['created_by']   = $user_id;
			$fields['updated_at']   = $now;

			// Bug 5: explicitly null out fields not meaningful for override rows so
			// MySQL does not substitute the former NOT NULL DEFAULT values.
			if ( ! array_key_exists( 'callback_type', $fields ) ) {
				$fields['callback_type'] = null;
			}
			if ( ! array_key_exists( 'status', $fields ) ) {
				$fields['status'] = null;
			}

			$result = $this->add_item( $fields );
			if ( false === $result || (int) $result <= 0 ) {
				return false;
			}
			// Bug 4: re-read via ID to bypass BerlinDB's slug-keyed internal cache.
			return $this->get_ability_by_id( (int) $result ) ?? false;
		}

		// UPDATE path — update_item() returns the updated item object or false.
		$fields['updated_at'] = $now;
		$fields['updated_by'] = $user_id;

		// Explicitly do NOT set created_at or created_by on update (A1/A2).
		unset( $fields['created_at'], $fields['created_by'] );

		$result = $this->update_item( $existing->id, $fields );
		if ( false === $result ) {
			return false;
		}
		// Bug 4: re-read via ID to bypass BerlinDB's slug-keyed internal cache.
		return $this->get_ability_by_id( $existing->id ) ?? false;
	}

	/**
	 * Delete an override record by ability slug.
	 *
	 * AUTHORIZATION CONTRACT (DEC-BY-SOURCE-AUTHZ):
	 * This is a raw DB-layer helper. It performs no capability check.
	 * Every caller that surfaces results to a HTTP response or admin screen
	 * MUST call current_user_can( 'manage_options' ) before invoking this method.
	 * REST controller permission_callback is the canonical gate.
	 *
	 * @since  0.1.0
	 * @param  string $slug Ability slug.
	 * @return bool True if a record was deleted, false otherwise.
	 */
	public function delete_override_by_slug( string $slug ): bool {
		$slug     = AcrossAI_Sanitizer::sanitize_ability_slug( $slug );
		$existing = $this->get_override_by_slug( $slug );
		if ( null === $existing ) {
			return false;
		}
		$result = $this->delete_item( $existing->id );
		return false !== $result;
	}

	/**
	 * Retrieve all override rows indexed by ability_slug.
	 *
	 * Passes number => 0 to BerlinDB which signals no LIMIT clause (unlimited rows).
	 * Returns an associative array keyed by ability_slug.
	 *
	 * AUTHORIZATION CONTRACT (DEC-BY-SOURCE-AUTHZ):
	 * This is a raw DB-layer helper. It performs no capability check.
	 * Every caller that surfaces results to a HTTP response or admin screen
	 * MUST call current_user_can( 'manage_options' ) before invoking this method.
	 * REST controller permission_callback is the canonical gate.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Row[]  Indexed by ability_slug string.
	 */
	public function get_all_overrides(): array {
		$results = $this->query( array( 'number' => 0 ) );
		$indexed = array();
		foreach ( $results as $row ) {
			if ( $row instanceof AcrossAI_Abilities_Row ) {
				$indexed[ $row->ability_slug ] = $row;
			}
		}
		return $indexed;
	}

	// -------------------------------------------------------------------------
	// Filter / browse helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve all rows from a given source.
	 *
	 * @since  0.1.0
	 * @param  string $source Source value (e.g. 'db', 'plugin', 'core', 'theme').
	 * @return AcrossAI_Abilities_Row[]
	 */
	public function by_source( string $source ): array {
		if ( '' === $source ) {
			return array();
		}
		return $this->collect(
			array(
				'source' => $source,
				'number' => 0,
			)
		);
	}

	/**
	 * Retrieve all published, source=db rows for runtime registration.
	 *
	 * Used by AcrossAI_Abilities_Processor at wp_abilities_api_init.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Row[]
	 */
	public function published_db_abilities(): array {
		return $this->collect(
			array(
				'source' => 'db',
				'status' => 'publish',
				'number' => 0,
			)
		);
	}

	/**
	 * Retrieve all published rows of a given mcp_type for exposure collections.
	 *
	 * @since  0.1.0
	 * @param  string $mcp_type  One of 'tool', 'resource', 'prompt'.
	 * @param  bool   $mcp_only  When true, restrict to show_in_mcp = 1 rows.
	 * @return AcrossAI_Abilities_Row[]
	 */
	public function by_mcp_type( string $mcp_type, bool $mcp_only = true ): array {
		$args = array(
			'status'   => 'publish',
			'mcp_type' => $mcp_type,
			'number'   => 0,
		);
		if ( $mcp_only ) {
			$args['show_in_mcp'] = 1;
		}
		return $this->collect( $args );
	}

	/**
	 * Return a paginated result set with total/pages metadata.
	 *
	 * All filtering, searching, and sorting stays in this method — not in REST controllers.
	 *
	 * @since  0.1.0
	 * @param  array $params Query parameters: page, per_page (1-100), search, orderby, order (ASC|DESC), source, status, category, editable.
	 * @return array{ items: AcrossAI_Abilities_Row[], total: int, pages: int }
	 */
	public function get_paginated( array $params ): array {
		$page     = max( 1, (int) ( $params['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $params['per_page'] ?? 20 ) ) );
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$orderby  = isset( $params['orderby'] ) ? sanitize_key( (string) $params['orderby'] ) : 'ability_slug';
		$order    = strtoupper( (string) ( $params['order'] ?? 'ASC' ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';

		$query_args = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => $orderby,
			'order'   => $order,
		);

		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}
		if ( ! empty( $params['source'] ) ) {
			$query_args['source'] = sanitize_text_field( (string) $params['source'] );
		}
		if ( ! empty( $params['status'] ) ) {
			$query_args['status'] = sanitize_text_field( (string) $params['status'] );
		}
		if ( ! empty( $params['category'] ) ) {
			$query_args['category'] = sanitize_text_field( (string) $params['category'] );
		}
		// editable=true → source=db rows only; editable=false → source≠db rows only.
		if ( isset( $params['editable'] ) && '' !== $params['editable'] ) {
			$editable = filter_var( $params['editable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( true === $editable ) {
				$query_args['source'] = 'db';
			} elseif ( false === $editable ) {
				// BerlinDB does not natively support "source != db"; apply post-collect filter.
				$query_args['__editable_false'] = true;
			}
		}

		$items = $this->collect( $query_args );
		// Post-collect filter for editable=false (source≠db) — BerlinDB has no "!=" query arg.
		if ( ! empty( $query_args['__editable_false'] ) ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( AcrossAI_Abilities_Row $row ) {
						return 'db' !== $row->source;
					}
				)
			);
		}

		$total = $this->count( $query_args );
		// Recalculate total for post-collect-filtered queries.
		if ( ! empty( $query_args['__editable_false'] ) ) {
			$all_args           = $query_args;
			$all_args['number'] = 0;
			$all_args['offset'] = 0;
			unset( $all_args['__editable_false'] );
			$all_rows = $this->collect( $all_args );
			$total    = count(
				array_filter(
					$all_rows,
					static function ( AcrossAI_Abilities_Row $row ) {
						return 'db' !== $row->source;
					}
				)
			);
		}

		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, $pages ),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Run a query and return only AcrossAI_Abilities_Row instances.
	 *
	 * @since  0.1.0
	 * @param  array $args BerlinDB query args.
	 * @return AcrossAI_Abilities_Row[]
	 */
	private function collect( array $args ): array {
		$results = $this->query( $args );
		$rows    = array();
		foreach ( $results as $row ) {
			if ( $row instanceof AcrossAI_Abilities_Row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Return the total count for the given (non-paginated) query args.
	 *
	 * @since  0.1.0
	 * @param  array $args BerlinDB query args (number/offset are stripped).
	 * @return int
	 */
	private function count( array $args ): int {
		$count_args          = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$count_args['number'] = 0; // unlimited for count pass.
		$result               = $this->query( $count_args );
		return is_numeric( $result ) ? (int) $result : count( (array) $result );
	}

	/**
	 * Prepare fields for a BerlinDB write (insert or update).
	 *
	 * - Casts PHP booleans → int for tri-state tinyint columns.
	 * - Validates and strips invalid enum values (callback_type, mcp_type, status).
	 * - JSON-encodes array values for registered JSON longtext fields with 64 KB guard.
	 *
	 * @since  0.1.0
	 * @param  array $fields Input field array.
	 * @return array
	 */
	private function prepare_fields_for_write( array $fields ): array {
		// 1. Tri-state bool → int cast (must come first — FR-018 block order).
		$tri_state = array( 'site_allowed', 'readonly', 'destructive', 'idempotent', 'show_in_rest', 'show_in_mcp' );
		foreach ( $tri_state as $col ) {
			if ( array_key_exists( $col, $fields ) && is_bool( $fields[ $col ] ) ) {
				$fields[ $col ] = (int) $fields[ $col ]; // true→1, false→0.
			}
		}

		// 2. Enum guards.
		if ( array_key_exists( 'status', $fields ) && null !== $fields['status'] ) {
			if ( ! in_array( $fields['status'], array( 'draft', 'publish' ), true ) ) {
				unset( $fields['status'] );
			}
		}
		if ( array_key_exists( 'callback_type', $fields ) && null !== $fields['callback_type'] ) {
			if ( ! in_array( $fields['callback_type'], array( 'noop', 'filter_hook', 'wp_remote_post', 'registered_callback' ), true ) ) {
				unset( $fields['callback_type'] );
			}
		}
		if ( array_key_exists( 'mcp_type', $fields ) && null !== $fields['mcp_type'] ) {
			if ( ! in_array( $fields['mcp_type'], array( 'tool', 'resource', 'prompt' ), true ) ) {
				unset( $fields['mcp_type'] );
			}
		}

		// 3. JSON encode registry fields with 64 KB size guard.
		foreach ( AcrossAI_Abilities_Row::get_json_fields() as $json_field ) {
			if ( isset( $fields[ $json_field ] ) && is_array( $fields[ $json_field ] ) ) {
				$encoded = wp_json_encode( $fields[ $json_field ] );
				if ( false === $encoded || strlen( $encoded ) > self::MAX_JSON_BYTES ) {
					$fields[ $json_field ] = null;
				} else {
					$fields[ $json_field ] = $encoded;
				}
			}
		}

		return $fields;
	}
}
