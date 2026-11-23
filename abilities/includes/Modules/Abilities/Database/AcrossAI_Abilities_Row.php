<?php
/**
 * BerlinDB Row class for a single ability record.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Database
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database;

use BerlinDB\Database\Kern\Row;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Sanitizer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the acrossai_abilities table.
 *
 * Tinyint → PHP bool/null casting is delegated to AcrossAI_Sanitizer::cast_tri_state()
 * and MUST NOT be duplicated here (RF-02).
 *
 * @since 0.1.0
 *
 * @property int         $id
 * @property string      $ability_slug
 * @property string|null $label
 * @property string|null $description
 * @property string|null $category
 * @property string      $status
 * @property string|null $provider
 * @property string      $source
 * @property bool|null   $site_allowed
 * @property string      $callback_type
 * @property array|null  $callback_config
 * @property array|null  $input_schema
 * @property array|null  $output_schema
 * @property bool|null   $show_in_rest
 * @property bool|null   $show_in_mcp
 * @property string|null $mcp_type
 * @property bool|null   $readonly
 * @property bool|null   $destructive
 * @property bool|null   $idempotent
 * @property string      $created_at
 * @property string|null $updated_at
 * @property int|null    $created_by
 * @property int|null    $updated_by
 */
class AcrossAI_Abilities_Row extends Row {

	/**
	 * Primary key.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Ability slug identifier.
	 *
	 * @var string
	 */
	public $ability_slug = '';

	/**
	 * Display name. NULL = no override (FR-021: nullable).
	 *
	 * @var string|null
	 */
	public $label = null;

	/**
	 * Full description.
	 *
	 * @var string|null
	 */
	public $description = null;

	/**
	 * Organizational category.
	 *
	 * @var string|null
	 */
	public $category = null;

	/**
	 * Lifecycle status. Allowed values: 'draft', 'publish'.
	 *
	 * @var string
	 */
	public $status = 'draft';

	/**
	 * Provider string.
	 *
	 * @var string|null
	 */
	public $provider = null;

	/**
	 * Source (origin of the record). Default 'db'.
	 *
	 * @var string
	 */
	public $source = 'db';

	/**
	 * Whether the ability is allowed site-wide. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $site_allowed = null;

	/**
	 * Callback type. Default 'noop'.
	 *
	 * @var string
	 */
	public $callback_type = 'noop';

	/**
	 * Callback configuration (decoded JSON array). NULL = not configured.
	 *
	 * @var array|null
	 */
	public $callback_config = null;

	/**
	 * Input JSON Schema (decoded array). NULL = no schema defined.
	 *
	 * @var array|null
	 */
	public $input_schema = null;

	/**
	 * Output JSON Schema (decoded array). NULL = no schema defined.
	 *
	 * @var array|null
	 */
	public $output_schema = null;

	/**
	 * Whether the ability is shown in the REST API. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $show_in_rest = null;

	/**
	 * Whether the ability is shown in MCP. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $show_in_mcp = null;

	/**
	 * MCP type override. NULL = use registry default.
	 *
	 * @var string|null
	 */
	public $mcp_type = null;

	/**
	 * Whether the ability is read-only. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $readonly = null;

	/**
	 * Whether the ability is destructive. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $destructive = null;

	/**
	 * Whether the ability is idempotent. NULL = use registry default.
	 *
	 * @var bool|null
	 */
	public $idempotent = null;

	/**
	 * Creation timestamp.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Last update timestamp.
	 *
	 * @var string|null
	 */
	public $updated_at = null;

	/**
	 * User ID who created the record.
	 *
	 * @var int|null
	 */
	public $created_by = null;

	/**
	 * User ID who last updated the record.
	 *
	 * @var int|null
	 */
	public $updated_by = null;

	/**
	 * Return the list of column names that store JSON-encoded values.
	 *
	 * Blocklist guard (N1 / SC-005): the base list covers the three known JSON
	 * longtext columns. Callers may extend via the filter, but any column name
	 * that appears in the scalar blocklist is silently removed from the result
	 * to prevent accidental JSON-decode of scalar columns.
	 *
	 * @since  0.1.0
	 * @return string[] JSON field names safe for json_decode/wp_json_encode.
	 */
	public static function get_json_fields(): array {
		$blocked_scalar_columns = array(
			'id',
			'ability_slug',
			'label',
			'description',
			'category',
			'status',
			'provider',
			'source',
			'site_allowed',
			'callback_type',
			'show_in_rest',
			'show_in_mcp',
			'mcp_type',
			'readonly',
			'destructive',
			'idempotent',
			'created_at',
			'updated_at',
			'created_by',
			'updated_by',
		);

		$base_json_fields = array( 'callback_config', 'input_schema', 'output_schema' );

		/**
		 * Allow plugins/themes to register additional JSON-encoded longtext columns.
		 *
		 * @since 0.1.0
		 * @param string[] $fields Base list of JSON column names.
		 */
		$json_fields = (array) \apply_filters( 'acrossai_abilities_json_fields', $base_json_fields );

		// Blocklist guard: remove any column that is a known scalar to prevent
		// accidental decode of non-JSON columns (N1 security correction).
		return array_values( array_diff( $json_fields, $blocked_scalar_columns ) );
	}

	/**
	 * Constructor — casts tinyint fields to PHP bool/null via shared utility,
	 * decodes all JSON longtext fields using the registry, and casts integer fields.
	 *
	 * @since  0.1.0
	 * @param  object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );

		// Cast tinyint columns using the shared sanitizer utility (RF-02).
		$tri_state_fields = array( 'site_allowed', 'readonly', 'destructive', 'idempotent', 'show_in_rest', 'show_in_mcp' );
		foreach ( $tri_state_fields as $field ) {
			$this->{$field} = AcrossAI_Sanitizer::cast_tri_state( $this->{$field} );
		}

		// JSON-decode all registered JSON longtext fields.
		// Registry is extensible via acrossai_abilities_json_fields filter (SC-005).
		foreach ( self::get_json_fields() as $json_field ) {
			if ( null !== $this->{$json_field} ) {
				$decoded             = json_decode( $this->{$json_field}, true );
				$this->{$json_field} = is_array( $decoded ) ? $decoded : null;
			}
		}

		// Cast integer fields.
		$this->id         = (int) $this->id;
		$this->created_by = null !== $this->created_by ? (int) $this->created_by : null;
		$this->updated_by = null !== $this->updated_by ? (int) $this->updated_by : null;
	}
}
