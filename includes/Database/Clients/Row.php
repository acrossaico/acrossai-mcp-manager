<?php
/**
 * One row of the Clients registry.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor for a client row.
 *
 * @since 0.4.9
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */
	public $id = 0;

	/** @var int */
	public $server_id = 0;

	/** @var string */
	public $identity_hash = '';

	/** @var string */
	public $identity = '';

	/** @var string */
	public $connection_type = '';

	/** @var string */
	public $route = '';

	/** @var string */
	public $client_name = '';

	/** @var string */
	public $client_version = '';

	/** @var string */
	public $connector_slug = '';

	/** @var string */
	public $client_id = '';

	/** @var string */
	public $credential_id = '';

	/** @var string */
	public $credential_hint = '';

	/** @var int */
	public $user_id = 0;

	/** @var string */
	public $last_ip = '';

	/** @var string */
	public $last_user_agent = '';

	/** @var int */
	public $request_count = 0;

	/** @var string */
	public $first_seen_at = '';

	/**
	 * Null until the client actually calls — never an empty string, because
	 * "never used" and "used at an unknown time" are different answers.
	 *
	 * @var string|null
	 */
	public $last_seen_at = null;

	/** @var string|null */
	public $revoked_at = null;

	/**
	 * @since 0.4.9
	 * @param mixed $item Row data from BerlinDB.
	 */
	public function __construct( $item ) {
		parent::__construct( is_object( $item ) ? (array) $item : (array) $item );

		$this->id            = (int) $this->id;
		$this->server_id     = (int) $this->server_id;
		$this->user_id       = (int) $this->user_id;
		$this->request_count = (int) $this->request_count;

		// Keep null distinct from '' — the UI renders one as "Never" and the
		// other would be an empty cell meaning nothing.
		$this->last_seen_at = ( null === $this->last_seen_at || '' === $this->last_seen_at ) ? null : (string) $this->last_seen_at;
		$this->revoked_at   = ( null === $this->revoked_at || '' === $this->revoked_at ) ? null : (string) $this->revoked_at;
	}

	/**
	 * Whether this client has ever actually called.
	 *
	 * @since  0.4.9
	 * @return bool
	 */
	public function has_been_used(): bool {
		return null !== $this->last_seen_at;
	}

	/**
	 * Whether access was withdrawn.
	 *
	 * @since  0.4.9
	 * @return bool
	 */
	public function is_revoked(): bool {
		return null !== $this->revoked_at;
	}

	/**
	 * Status as the list table shows it.
	 *
	 * Three states rather than two: a client that registered and never called
	 * is not the same as one that is active, and saying so is what tells an
	 * operator "set up wrong" from "not set up yet".
	 *
	 * @since  0.4.9
	 * @return string One of: revoked, never-used, active.
	 */
	public function status(): string {
		if ( $this->is_revoked() ) {
			return 'revoked';
		}

		return $this->has_been_used() ? 'active' : 'never-used';
	}

	/**
	 * @since  0.4.9
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}
}
