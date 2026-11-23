<?php
/**
 * Publishing this plugin's Toolsets onto an MCP server somebody else owns.
 *
 * Three transports are in scope. AcrossAI MCP Manager and the WordPress MCP Adapter already serve
 * these abilities, because both build their servers from the ability registry and an operator
 * curates which tools go on. Elementor's server does not: it is built from a fixed list of slugs
 * its own package collected, so a client connected to `/wp-json/elementor/mcp` sees Elementor's
 * twenty tools and no sign that eight hundred other abilities exist on the same site.
 *
 * This is the shared half of fixing that. A subclass names six things — the integration row that
 * governs it, how to tell the host is here, the filter that collects the host's tool slugs, the
 * server id, which Toolsets to contribute, and what to tell a client on connect — and inherits the
 * rest. Adding a fourth host is one subclass, not another round of this.
 *
 * **Two tools, not seventeen.** A host's server is not ours to fill. Contributing every Toolset
 * would add fifteen-odd entries to somebody else's tool list and bury the twenty they shipped.
 * The subclass contributes a gateway spanning every group plus a guide explaining how to call it,
 * and everything else is reached *through* the gateway. Measured on this site, that menu is ~456
 * tokens against ~6,752 for the same catalogue listed flat.
 *
 * **Nothing here registers an ability.** The Toolsets register themselves the ordinary way, via
 * {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Toolset\Base_Toolset_Ability}. This class
 * only tells a host server that those slugs exist and alters what the host says on connect.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Mcp
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Mcp;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Contributes Toolsets and connect-time instructions to a third-party MCP server.
 */
abstract class Host_Mcp_Server {

	/**
	 * The Integrations row that governs this host.
	 *
	 * Switching it off at `admin.php?page=acrossai-integrations` must stop every contribution, not
	 * just the abilities behind them.
	 *
	 * @since  0.0.42
	 * @return string Integration group slug, e.g. `elementor`.
	 */
	abstract protected function integration_slug(): string;

	/**
	 * Whether the host plugin is actually on this site.
	 *
	 * Asked on every contribution rather than once at construction: a subclass is built during
	 * `plugins_loaded`, and the filters below run on a REST request, by which point the answer can
	 * have changed.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	abstract protected function host_available(): bool;

	/**
	 * The host's filter for appending tool slugs to its server.
	 *
	 * @since  0.0.42
	 * @return string Filter hook name, e.g. `elementor/mcp/server/tools`.
	 */
	abstract protected function tools_filter(): string;

	/**
	 * The host server's id, as it passes to `create_server()`.
	 *
	 * Load-bearing for the instructions half. `mcp_adapter_initialize_response` fires for EVERY
	 * server the adapter holds — ours included — so the id is the only thing distinguishing the
	 * host's `initialize` from an AcrossAI server's.
	 *
	 * @since  0.0.42
	 * @return string Server id, e.g. `elementor-mcp-server`.
	 */
	abstract protected function server_id(): string;

	/**
	 * The Toolset slugs to contribute, in the order a client should meet them.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	abstract protected function toolsets(): array;

	/**
	 * What to tell a client on connect, appended to the host's own instructions.
	 *
	 * The one channel an assistant reads without first choosing to call a tool. Without it the two
	 * contributed tools arrive with no explanation of what they reach or why they are sitting among
	 * the host's own.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	abstract protected function instructions(): string;

	/**
	 * Hook the host's two extension points.
	 *
	 * Three arguments on the instructions filter, which is one more than the oldest adapter passes.
	 * WordPress pads a short argument list with nulls, so asking for the 0.7.x `$schema` costs
	 * nothing on 0.6.x and asking for only two would make the newer contract unreachable.
	 *
	 * Both filters belong to somebody else and may never fire — the host can be absent, its MCP
	 * module switched off, or its adapter copy too old for the instructions filter. Filtering a
	 * hook nobody fires costs nothing, so neither is guarded on presence here; the callbacks
	 * decide. That keeps the live decision in one place and out of the construction order.
	 *
	 * @since 0.0.42
	 */
	public function __construct() {
		add_filter( $this->tools_filter(), array( $this, 'contribute_tools' ) );
		add_filter( 'mcp_adapter_initialize_response', array( $this, 'contribute_instructions' ), 10, 3 );
	}

	/**
	 * Append this plugin's Toolsets to the host's tool list.
	 *
	 * Appends only, and never reorders or removes: the host's own tools are the reason a client
	 * connected there.
	 *
	 * @since  0.0.42
	 * @param  mixed $tools Slugs collected so far.
	 * @return mixed The list with this plugin's Toolsets appended, or untouched.
	 */
	public function contribute_tools( $tools ) {
		if ( ! is_array( $tools ) || ! $this->contributing() ) {
			return $tools;
		}

		foreach ( $this->toolsets() as $slug ) {
			$slug = (string) $slug;

			/*
			 * Only a slug that resolved. The host passes these straight to `create_server()`, which
			 * logs a miss and carries on — leaving a client with a tool list that is quietly short
			 * and no error anywhere a site owner would look. Checking here means a Toolset that
			 * declined to register (slug taken by another plugin, most likely) is simply absent
			 * rather than advertised and broken.
			 *
			 * Safe to ask at this point: the host collects this list while creating its server, and
			 * the adapter resolves every slug in the same pass, so the registry is initialised
			 * either way by the time it matters.
			 */
			if ( '' === $slug || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $slug ) ) {
				continue;
			}

			if ( ! in_array( $slug, $tools, true ) ) {
				$tools[] = $slug;
			}
		}

		return $tools;
	}

	/**
	 * Add this plugin's briefing to the host server's connect message.
	 *
	 * Scoped to the host's own server id. Every server the adapter holds passes through this
	 * filter, so an unscoped callback would describe Elementor's gateway to a client connected to
	 * an AcrossAI server, which has its own guide and does not have that tool.
	 *
	 * **Two adapter contracts, because ten copies of the adapter are installed on this machine and
	 * the one that wins class resolution is whichever loaded first — versions 0.1.0 through 0.7.1
	 * are all present.** How a result is taken apart and put back together changed between them:
	 *
	 * - 0.5.x–0.6.x: a DTO that hydrates itself. `$result->toArray()`, then the class's own static
	 *   `fromArray()`.
	 * - 0.7.x: an immutable record hydrated THROUGH the schema. `jsonSerialize()` to read, and
	 *   `$schema->fromArray( <class>, $data )` to rebuild — the record's constructor is `protected`
	 *   and there is no static `fromArray()` at all.
	 *
	 * Both are handled, chosen by what the object in hand actually answers rather than by a version
	 * string, so a copy this was never run against degrades instead of guessing. Getting this wrong
	 * is quiet rather than loud: the first draft handled only the older contract, and on 0.7.1 it
	 * returned the result untouched — tools landed, the briefing silently did not, and nothing
	 * anywhere said so.
	 *
	 * Never fatal. A failure here costs the briefing; the tools are contributed by a separate
	 * filter and are unaffected.
	 *
	 * @since  0.0.42
	 * @param  mixed $result The host's initialize result.
	 * @param  mixed $server The server being initialised.
	 * @param  mixed $schema The schema that built the result, on adapters that have one.
	 * @return mixed The result, with instructions appended when it was safe to do so.
	 */
	public function contribute_instructions( $result, $server = null, $schema = null ) {
		if ( ! $this->contributing() || ! is_object( $result ) ) {
			return $result;
		}

		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return $result;
		}

		if ( $this->server_id() !== (string) $server->get_server_id() ) {
			return $result;
		}

		if ( '' === trim( $this->instructions() ) ) {
			return $result;
		}

		// 0.7.x — the schema owns hydration.
		if ( is_object( $schema ) && method_exists( $schema, 'fromArray' ) && method_exists( $result, 'jsonSerialize' ) ) {
			return $this->amend_via_schema( $result, $schema );
		}

		// 0.5.x–0.6.x — the DTO owns hydration.
		if ( method_exists( $result, 'toArray' ) && method_exists( get_class( $result ), 'fromArray' ) ) {
			return $this->amend_via_dto( $result );
		}

		return $result;
	}

	/**
	 * Rebuild a 0.7.x record through the schema that produced it.
	 *
	 * @since  0.0.42
	 * @param  object $result The initialize record.
	 * @param  object $schema The schema to hydrate through.
	 * @return mixed The amended record, or the original if anything refused.
	 */
	private function amend_via_schema( object $result, object $schema ) {
		$data = $this->as_array( $result->jsonSerialize() );

		if ( null === $data ) {
			return $result;
		}

		$data['instructions'] = $this->merged_instructions( $data );

		try {
			$amended = $schema->fromArray( get_class( $result ), $data );
		} catch ( \Throwable $e ) {
			// Hydration validates, so a schema stricter than this one expects takes the briefing
			// and nothing else. The client still gets a working server and the host's own message.
			return $result;
		}

		return is_object( $amended ) ? $amended : $result;
	}

	/**
	 * Rebuild a 0.5.x–0.6.x DTO through its own static factory.
	 *
	 * @since  0.0.42
	 * @param  object $result The initialize DTO.
	 * @return mixed The amended DTO, or the original if anything refused.
	 */
	private function amend_via_dto( object $result ) {
		$data = $this->as_array( $result->toArray() );

		if ( null === $data ) {
			return $result;
		}

		$data['instructions'] = $this->merged_instructions( $data );
		$dto                  = get_class( $result );

		try {
			$amended = $dto::fromArray( $data );
		} catch ( \Throwable $e ) {
			return $result;
		}

		return is_object( $amended ) ? $amended : $result;
	}

	/**
	 * The host's instructions with ours appended.
	 *
	 * The host's sentence stays first: it is what the operator or the host plugin wrote, and it
	 * names the server the client believes it connected to.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $data The result, as an array.
	 * @return string
	 */
	private function merged_instructions( array $data ): string {
		$existing = isset( $data['instructions'] ) ? trim( (string) $data['instructions'] ) : '';
		$ours     = trim( $this->instructions() );

		return '' === $existing ? $ours : $existing . "\n\n" . $ours;
	}

	/**
	 * A serialised result as a plain nested array.
	 *
	 * Round-tripped through JSON rather than cast. Both contracts hand back a structure whose
	 * nested members are themselves objects — capabilities, serverInfo — and hydration expects
	 * arrays all the way down, so a shallow `(array)` cast rebuilds into a validation failure.
	 *
	 * @since  0.0.42
	 * @param  mixed $serialised Whatever the result serialised to.
	 * @return array<string, mixed>|null Null when it did not survive the round trip.
	 */
	private function as_array( $serialised ): ?array {
		if ( is_array( $serialised ) && array() === $serialised ) {
			return null;
		}

		$json = wp_json_encode( $serialised );

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Whether to contribute anything at all right now.
	 *
	 * Asks `is_switched_off()` rather than `is_enabled()`, the same question the registration gate
	 * asks and for the same reason: an unseeded key is not a decision, and reading it as "off"
	 * would make a fresh site contribute nothing while the Integrations screen showed the row on.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function contributing(): bool {
		if ( ! $this->host_available() ) {
			return false;
		}

		return ! AcrossAI_Integration_Settings::is_switched_off( $this->integration_slug() );
	}
}
