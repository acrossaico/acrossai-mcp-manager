<?php
/**
 * The contract every troubleshooter check implements (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

defined( 'ABSPATH' ) || exit;

/**
 * A single diagnostic.
 *
 * Checks MUST NOT write state and MUST be safe to run repeatedly — the
 * operator will press the button more than once, and a diagnostic that
 * consumes a rate-limit budget or mints a credential makes the problem it is
 * describing worse.
 */
interface Check {

	/** Stable identifier. Referenced by `requires()` on other checks. */
	public function id(): string;

	/** Human-readable name, also used in skip messages. */
	public function label(): string;

	/**
	 * Ids that must not have failed for this check to be meaningful.
	 *
	 * When any has failed, the runner records a `skipped` result naming them
	 * instead of calling `run()`. Five red rows with one root cause tell the
	 * operator less than one red row and four honest "not run"s.
	 *
	 * @return array<int, string>
	 */
	public function requires(): array;

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result;
}
