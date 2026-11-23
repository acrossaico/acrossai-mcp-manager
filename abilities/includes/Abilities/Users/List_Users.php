<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Users
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Users;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\User_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around WP_User_Query exposing every documented parameter group:
 * role / include & exclude / blog / search / pagination / order /
 * date / custom field / who / total count / has-published-posts / fields.
 *
 * See: https://developer.wordpress.org/reference/classes/wp_user_query/
 */
class List_Users extends Ability_Definition {

	/** Columns WP_User_Query::query() accepts for orderby. */
	private const ALLOWED_ORDERBY = array(
		'ID',
		'display_name',
		'name',
		'include',
		'user_login',
		'login',
		'login__in',
		'user_nicename',
		'nicename',
		'nicename__in',
		'user_email',
		'email',
		'user_url',
		'url',
		'user_registered',
		'registered',
		'post_count',
		'meta_value',
		'meta_value_num',
	);

	private const ALLOWED_SEARCH_COLUMNS = array(
		'ID',
		'user_login',
		'user_email',
		'user_url',
		'user_nicename',
		'display_name',
	);

	private const ALLOWED_META_COMPARE = array(
		'=',
		'!=',
		'>',
		'>=',
		'<',
		'<=',
		'LIKE',
		'NOT LIKE',
		'IN',
		'NOT IN',
		'BETWEEN',
		'NOT BETWEEN',
		'EXISTS',
		'NOT EXISTS',
		'REGEXP',
		'NOT REGEXP',
		'RLIKE',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'users/list-users',
			'args' => array(
				'label'               => __( 'List Users', 'acrossai-abilities-manager' ),
				'description'         => __( 'Run a WP_User_Query with full parameter support: role filters, include/exclude IDs, multisite blog scope, search, pagination (number/paged/offset), ordering, date_query, meta_query, has_published_posts, and field selection. Returns paginated results with total counts. Defaults to 50 users per page, ordered by ID ascending.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-users',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(

						// ---- Role ----------------------------------------------------
						'role'                => array(
							'type'        => 'string',
							'description' => __( 'Role slug. Matches users with this role.', 'acrossai-abilities-manager' ),
						),
						'role__in'            => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Match users in any of these roles.', 'acrossai-abilities-manager' ),
						),
						'role__not_in'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Exclude users in any of these roles.', 'acrossai-abilities-manager' ),
						),

						// ---- Include / Exclude --------------------------------------
						'include'             => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Restrict to these user IDs.', 'acrossai-abilities-manager' ),
						),
						'exclude'             => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Exclude these user IDs.', 'acrossai-abilities-manager' ),
						),

						// ---- Blog (multisite) ---------------------------------------
						'blog_id'             => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Scope to a specific blog ID on multisite.', 'acrossai-abilities-manager' ),
						),

						// ---- Search -------------------------------------------------
						'search'              => array(
							'type'        => 'string',
							'description' => __( 'Search string. Wrap value with "*" wildcards for partial matches (e.g. "*foo*").', 'acrossai-abilities-manager' ),
						),
						'search_columns'      => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::ALLOWED_SEARCH_COLUMNS,
							),
							'description' => __( 'Columns to search. Defaults to login/email/nicename/url/display_name when "search" contains a wildcard.', 'acrossai-abilities-manager' ),
						),

						// ---- Pagination ---------------------------------------------
						'number'              => array(
							'type'        => 'integer',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 500,
							'description' => __( 'Page size (1–500). Default 50.', 'acrossai-abilities-manager' ),
						),
						'paged'               => array(
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
							'description' => __( 'Page number (1-based).', 'acrossai-abilities-manager' ),
						),
						'offset'              => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Row offset. Overrides paged when set.', 'acrossai-abilities-manager' ),
						),

						// ---- Order --------------------------------------------------
						'orderby'             => array(
							'type'        => array( 'string', 'array' ),
							'default'     => 'ID',
							'description' => __( 'Field or array of fields to order by. Allowed: ID, display_name, name, include, user_login/login/login__in, user_nicename/nicename/nicename__in, user_email/email, user_url/url, user_registered/registered, post_count, meta_value, meta_value_num.', 'acrossai-abilities-manager' ),
						),
						'order'               => array(
							'type'    => 'string',
							'enum'    => array( 'ASC', 'DESC' ),
							'default' => 'ASC',
						),

						// ---- Date ---------------------------------------------------
						'date_query'          => array(
							'type'        => array( 'array', 'object' ),
							'description' => __( 'WP_Date_Query clause(s) against user_registered.', 'acrossai-abilities-manager' ),
						),

						// ---- Custom field / meta ------------------------------------
						'meta_key'            => array( 'type' => 'string' ),
						'meta_value'          => array( 'type' => array( 'string', 'number', 'boolean' ) ),
						'meta_compare'        => array(
							'type'        => 'string',
							'enum'        => self::ALLOWED_META_COMPARE,
							'description' => __( 'Comparison operator for meta_value.', 'acrossai-abilities-manager' ),
						),
						'meta_query'          => array(
							'type'        => array( 'array', 'object' ),
							'description' => __( 'WP_Meta_Query clause(s). Pass the full nested structure.', 'acrossai-abilities-manager' ),
						),

						// ---- Who ----------------------------------------------------
						'who'                 => array(
							'type'        => 'string',
							'enum'        => array( 'authors' ),
							'description' => __( 'Deprecated since WP 5.9; "authors" restricts to users with post-creation caps.', 'acrossai-abilities-manager' ),
						),

						// ---- Total count --------------------------------------------
						'count_total'         => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether to compute SQL_CALC_FOUND_ROWS for accurate totals/pages. Disable for performance when you do not need pagination metadata.', 'acrossai-abilities-manager' ),
						),

						// ---- Has published posts ------------------------------------
						'has_published_posts' => array(
							'type'        => array( 'boolean', 'array' ),
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'true = any public post type; array of post-type slugs to restrict to specific types.', 'acrossai-abilities-manager' ),
						),

						// ---- Return fields ------------------------------------------
						'fields'              => array(
							'type'        => array( 'string', 'array' ),
							'description' => __( '"all" (default WP_User) | "all_with_meta" | a column name | array of column names. Anything other than "all"/"all_with_meta" returns raw column rows instead of formatted user objects.', 'acrossai-abilities-manager' ),
						),

						// ---- Acrossai add-on: attach user_meta to each row ----------
						'include_meta'        => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When fields = "all" (or omitted), attach a "meta" map to each user. Use meta_keys to restrict.', 'acrossai-abilities-manager' ),
						),
						'meta_keys'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Limit include_meta to these keys.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'users'       => array( 'type' => 'array' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'offset'      => array( 'type' => 'integer' ),
						'has_more'    => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'users', 'page', 'per_page' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'users',
						'sub_group'       => 'users',
						'sub_group_label' => __( 'Users', 'acrossai-abilities-manager' ),
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
	 * Feature 095 follow-up — hint that known-ID reads should skip the
	 * pagination entirely.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'users/get-user',
				'reason' => __( 'If you already know the user ID, a targeted get-user read is far cheaper than paging through every user.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~10-20x fewer tokens for known-ID reads', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$args = $this->build_query_args( $input );

		$query   = new \WP_User_Query( $args );
		$results = $query->get_results();

		$per_page = isset( $args['number'] ) ? (int) $args['number'] : 50;
		$page     = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$offset   = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$count_total = ! isset( $input['count_total'] ) || (bool) $input['count_total'];
		$total       = $count_total ? (int) $query->get_total() : 0;
		$total_pages = ( $count_total && $per_page > 0 && $total > 0 ) ? (int) ceil( $total / $per_page ) : 0;

		$fields       = $args['fields'] ?? 'all';
		$returns_user = ( 'all' === $fields || 'all_with_meta' === $fields );

		$users = array();
		if ( $returns_user ) {
			$include_meta = ( 'all_with_meta' === $fields ) || ! empty( $input['include_meta'] );
			$opts         = array(
				'include_meta' => $include_meta,
				'meta_keys'    => isset( $input['meta_keys'] ) && is_array( $input['meta_keys'] ) ? $input['meta_keys'] : array(),
			);
			foreach ( $results as $user ) {
				if ( $user instanceof \WP_User ) {
					$users[] = User_Helpers::format_user( $user, $opts );
				}
			}
		} else {
			$users = $results;
		}

		$has_more = $count_total
			? ( ( $page * $per_page + $offset ) < $total )
			: ( count( $users ) === $per_page );

		return array(
			'users'       => $users,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
			'offset'      => $offset,
			'has_more'    => $has_more,
		);
	}

	/**
	 * Maps input → WP_User_Query args, sanitising each parameter group.
	 */
	private function build_query_args( array $input ): array {
		$args = array();

		// ---- Role ----
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = sanitize_key( (string) $input['role'] );
		}
		if ( ! empty( $input['role__in'] ) && is_array( $input['role__in'] ) ) {
			$args['role__in'] = array_values( array_filter( array_map( 'sanitize_key', $input['role__in'] ) ) );
		}
		if ( ! empty( $input['role__not_in'] ) && is_array( $input['role__not_in'] ) ) {
			$args['role__not_in'] = array_values( array_filter( array_map( 'sanitize_key', $input['role__not_in'] ) ) );
		}

		// ---- Include / Exclude ----
		if ( ! empty( $input['include'] ) && is_array( $input['include'] ) ) {
			$args['include'] = array_values( array_filter( array_map( 'absint', $input['include'] ) ) );
		}
		if ( ! empty( $input['exclude'] ) && is_array( $input['exclude'] ) ) {
			$args['exclude'] = array_values( array_filter( array_map( 'absint', $input['exclude'] ) ) );
		}

		// ---- Blog ----
		if ( isset( $input['blog_id'] ) ) {
			$args['blog_id'] = absint( $input['blog_id'] );
		}

		// ---- Search ----
		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = sanitize_text_field( (string) $input['search'] );
			if ( ! empty( $input['search_columns'] ) && is_array( $input['search_columns'] ) ) {
				$args['search_columns'] = array_values(
					array_intersect(
						array_map( 'sanitize_text_field', $input['search_columns'] ),
						self::ALLOWED_SEARCH_COLUMNS
					)
				);
			} elseif ( false === strpos( $args['search'], '*' ) ) {
				// Bare search: wrap with wildcards and search the usual columns.
				$args['search']         = '*' . $args['search'] . '*';
				$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name', 'user_url' );
			}
		}

		// ---- Pagination ----
		$number         = isset( $input['number'] ) ? (int) $input['number'] : 50;
		$args['number'] = max( 1, min( 500, $number ) );
		$args['paged']  = isset( $input['paged'] ) ? max( 1, (int) $input['paged'] ) : 1;
		if ( isset( $input['offset'] ) ) {
			$args['offset'] = max( 0, (int) $input['offset'] );
		}

		// ---- Order ----
		$orderby = $input['orderby'] ?? 'ID';
		if ( is_array( $orderby ) ) {
			$orderby = array_values( array_intersect( array_map( 'strval', $orderby ), self::ALLOWED_ORDERBY ) );
			if ( empty( $orderby ) ) {
				$orderby = 'ID';
			}
		} else {
			$orderby = in_array( (string) $orderby, self::ALLOWED_ORDERBY, true ) ? (string) $orderby : 'ID';
		}
		$args['orderby'] = $orderby;

		$order         = isset( $input['order'] ) ? strtoupper( (string) $input['order'] ) : 'ASC';
		$args['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';

		// ---- Date ----
		if ( isset( $input['date_query'] ) && ! empty( $input['date_query'] ) ) {
			$args['date_query'] = (array) $input['date_query'];
		}

		// ---- Custom field ----
		if ( isset( $input['meta_key'] ) && '' !== $input['meta_key'] ) {
			$args['meta_key'] = sanitize_text_field( (string) $input['meta_key'] );
		}
		if ( isset( $input['meta_value'] ) ) {
			$args['meta_value'] = is_scalar( $input['meta_value'] ) ? (string) $input['meta_value'] : $input['meta_value'];
		}
		if ( isset( $input['meta_compare'] ) && in_array( $input['meta_compare'], self::ALLOWED_META_COMPARE, true ) ) {
			$args['meta_compare'] = $input['meta_compare'];
		}
		if ( isset( $input['meta_query'] ) && ! empty( $input['meta_query'] ) ) {
			$args['meta_query'] = (array) $input['meta_query'];
		}

		// ---- Who ----
		if ( ! empty( $input['who'] ) && 'authors' === $input['who'] ) {
			$args['who'] = 'authors';
		}

		// ---- Total count ----
		if ( isset( $input['count_total'] ) ) {
			$args['count_total'] = (bool) $input['count_total'];
		}

		// ---- Has published posts ----
		if ( isset( $input['has_published_posts'] ) ) {
			if ( is_bool( $input['has_published_posts'] ) ) {
				$args['has_published_posts'] = $input['has_published_posts'];
			} elseif ( is_array( $input['has_published_posts'] ) ) {
				$args['has_published_posts'] = array_values( array_filter( array_map( 'sanitize_key', $input['has_published_posts'] ) ) );
			}
		}

		// ---- Return fields ----
		if ( isset( $input['fields'] ) ) {
			$args['fields'] = is_array( $input['fields'] )
				? array_values( array_map( 'sanitize_text_field', $input['fields'] ) )
				: sanitize_text_field( (string) $input['fields'] );
		}

		return $args;
	}
}
