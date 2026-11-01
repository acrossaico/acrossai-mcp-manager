<?php
/**
 * One troubleshooter finding (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a single check.
 *
 * Immutable, and built through named constructors rather than a status
 * string, so a typo produces a fatal rather than a row the UI renders in the
 * neutral colour and nobody notices.
 *
 * `remedy` is what to DO; `copy` is text the operator can paste somewhere —
 * a hosting ticket, a firewall allowlist. They are separate because a red row
 * that only says "blocked" moves nobody forward, and a wall of paste-able
 * text with no explanation moves them backwards.
 *
 * Constructor property promotion is deliberately not used: phpcs is pinned to
 * `testVersion 7.4-` repo-wide and rejects it.
 */
final class Result {

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const FAIL    = 'fail';
	public const INFO    = 'info';
	public const SKIPPED = 'skipped';

	/**
	 * Stable identifier, referenced by dependent checks.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * One of the class constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Human-readable check name.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * What was observed.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * What to do about it.
	 *
	 * @var string
	 */
	private $remedy;

	/**
	 * Paste-able text, or ''.
	 *
	 * @var string
	 */
	private $copy;

	/**
	 * @param string $id      Stable identifier.
	 * @param string $status  One of the class constants.
	 * @param string $label   Human-readable check name.
	 * @param string $message What was observed.
	 * @param string $remedy  What to do about it.
	 * @param string $copy    Paste-able text, or ''.
	 */
	private function __construct( string $id, string $status, string $label, string $message, string $remedy = '', string $copy = '' ) {
		$this->id      = $id;
		$this->status  = $status;
		$this->label   = $label;
		$this->message = $message;
		$this->remedy  = $remedy;
		$this->copy    = $copy;
	}

	/**
	 * Nothing wrong here.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Check label.
	 * @param string $message What was observed.
	 * @return self
	 */
	public static function ok( string $id, string $label, string $message ): self {
		return new self( $id, self::OK, $label, $message );
	}

	/**
	 * Something worth knowing, which this probe cannot prove either way.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Check label.
	 * @param string $message What was observed.
	 * @param string $remedy  What to do about it.
	 * @param string $copy    Paste-able text.
	 * @return self
	 */
	public static function warning( string $id, string $label, string $message, string $remedy = '', string $copy = '' ): self {
		return new self( $id, self::WARNING, $label, $message, $remedy, $copy );
	}

	/**
	 * Demonstrably broken.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Check label.
	 * @param string $message What was observed.
	 * @param string $remedy  What to do about it.
	 * @param string $copy    Paste-able text.
	 * @return self
	 */
	public static function fail( string $id, string $label, string $message, string $remedy = '', string $copy = '' ): self {
		return new self( $id, self::FAIL, $label, $message, $remedy, $copy );
	}

	/**
	 * Context, asserting nothing.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Check label.
	 * @param string $message What was observed.
	 * @param string $remedy  Optional follow-up.
	 * @return self
	 */
	public static function info( string $id, string $label, string $message, string $remedy = '' ): self {
		return new self( $id, self::INFO, $label, $message, $remedy );
	}

	/**
	 * A check that did not run, naming what has to be fixed first.
	 *
	 * Reported rather than omitted: a silently shorter list reads as "nothing
	 * else to check", which is the opposite of the truth.
	 *
	 * @param string             $id       Identifier of the skipped check.
	 * @param string             $label    Its label.
	 * @param array<int, string> $blockers Labels of the failed prerequisites.
	 * @return self
	 */
	public static function skipped( string $id, string $label, array $blockers ): self {
		return new self(
			$id,
			self::SKIPPED,
			$label,
			sprintf(
				/* translators: %s: comma-separated list of check names that failed */
				__( 'Not run — fix %s first.', 'acrossai-mcp-manager' ),
				implode( ', ', $blockers )
			)
		);
	}

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * One of the class constants.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Whether this result blocks dependent checks.
	 *
	 * @return bool
	 */
	public function is_fail(): bool {
		return self::FAIL === $this->status;
	}

	/**
	 * Flat shape for the REST response.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'status'  => $this->status,
			'label'   => $this->label,
			'message' => $this->message,
			'remedy'  => $this->remedy,
			'copy'    => $this->copy,
		);
	}
}
