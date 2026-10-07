<?php
/**
 * Feature 024 — every gate that must refuse a disabled connector.
 *
 * Source-level assertions, matching the convention the sibling controller tests
 * in this directory already use: these classes terminate the request via
 * `wp_send_json` / `respond_error` and reach BerlinDB repositories, so
 * behavioural end-to-end coverage needs a WP-boot integration harness this repo
 * does not have. What is asserted here is that each guard EXISTS and sits at the
 * right point in its flow — which is precisely the property that was missing.
 *
 * Before Feature 024, `is_slug_enabled_on_server()` had exactly one call site in
 * the entire plugin. Disabling a connector revoked its tokens once and blocked
 * `/authorize`, and that was all: an admin could still mint credentials for it,
 * a held refresh token still rotated into a fresh pair indefinitely, and a live
 * bearer token still authenticated. Each test below pins one of those holes shut.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
}

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuth {

	use PHPUnit\Framework\TestCase;

	final class ConnectorDisabledEnforcementTest extends TestCase {

		/**
		 * Read a plugin source file.
		 *
		 * @param string $relative Path relative to the plugin root.
		 * @return string
		 */
		private function source( string $relative ): string {
			$path = dirname( __DIR__, 3 ) . '/' . $relative;
			$src  = file_get_contents( $path );
			self::assertIsString( $src, $relative . ' must be readable.' );

			return (string) $src;
		}

		/**
		 * FR-004 — admin credential generation.
		 *
		 * The check must sit BEFORE the DCR-only bail and before any client row
		 * is created, or a disabled connector still gets a row written and the
		 * refusal becomes cosmetic.
		 */
		public function test_credential_generation_refuses_a_disabled_connector(): void {
			$src = $this->source( 'includes/OAuth/ClientRegistrationController.php' );

			self::assertMatchesRegularExpression(
				'/if \(\s*! ConnectorSettings::is_slug_enabled_on_server\(\s*\$server_id,\s*\$connector_slug\s*\)\s*\)/',
				$src,
				'handle_generate() must consult per-server enablement.'
			);
			self::assertStringContainsString(
				'acrossai_mcp_oauth_connector_disabled',
				$src,
				'The refusal must carry its own error code so callers can distinguish it.'
			);

			$guard_pos = strpos( $src, 'is_slug_enabled_on_server' );
			$dcr_pos   = strpos( $src, 'acrossai_mcp_oauth_dcr_only_connector' );
			$create    = strpos( $src, 'ClientRepository::create' );
			self::assertIsInt( $guard_pos );
			self::assertIsInt( $dcr_pos );
			self::assertIsInt( $create );
			self::assertLessThan( $dcr_pos, $guard_pos, 'Enablement must be checked before the DCR-only bail.' );
			self::assertLessThan( $create, $guard_pos, 'Enablement must be checked before any client row is created.' );
		}

		/**
		 * DCR registration is deliberately NOT gated — registering is not using.
		 * Feature 008 settled this and the Settings panel still tells operators
		 * so. A future reader "fixing the inconsistency" would break discovery
		 * for every client, before any user has consented to anything, so the
		 * reasoning is pinned here rather than left to the commit log.
		 */
		public function test_dcr_registration_stays_open_by_design(): void {
			$src = $this->source( 'includes/OAuth/ClientRegistrationController.php' );

			self::assertStringContainsString(
				'Deliberately NOT gated on `is_slug_enabled_on_server()`',
				$src,
				'The DCR path must document why it does not gate on enablement.'
			);
			// Counts the qualified CALL, not the bare name — the DCR path's
			// explanatory comment mentions `is_slug_enabled_on_server()`
			// unqualified, and matching that would make the comment itself
			// look like a second gate.
			self::assertSame(
				1,
				substr_count( $src, 'ConnectorSettings::is_slug_enabled_on_server(' ),
				'Only the admin-generate path may gate on enablement; DCR must stay open.'
			);
		}

		/**
		 * FR-006 / mcp-manager FR-024-017 — the defensive layer that spec
		 * required and nothing implemented until Feature 024.
		 *
		 * Must run AFTER the audience check: a token for another server is
		 * anonymous regardless of connector state, and checking enablement
		 * first would consult the wrong server's settings.
		 */
		public function test_bearer_validation_refuses_a_disabled_connector(): void {
			$src = $this->source( 'includes/OAuth/TokenValidator.php' );

			self::assertMatchesRegularExpression(
				'/if \(\s*! self::connector_enabled_for_token\(\s*\$row\s*\)\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*return \$user_id;/',
				$src,
				'authenticate() must return $user_id unchanged for a disabled connector.'
			);
			self::assertStringContainsString(
				'private static function connector_enabled_for_token( TokenRow $row ): bool',
				$src,
				'The helper must exist with the documented signature.'
			);

			$audience = strpos( $src, 'audience_matches_request( $row )' );
			$enabled  = strpos( $src, 'connector_enabled_for_token( $row )' );
			self::assertIsInt( $audience );
			self::assertIsInt( $enabled );
			self::assertLessThan( $enabled, $audience, 'Audience binding must be checked before connector enablement.' );
		}

		/**
		 * The helper resolves the connector through the token's CLIENT row,
		 * because `connector_slug` lives on the client, not the token — and
		 * buckets it, so unattributed DCR clients ride on `allow_other`.
		 */
		public function test_bearer_guard_resolves_slug_through_the_client_row(): void {
			$src = $this->source( 'includes/OAuth/TokenValidator.php' );

			self::assertStringContainsString( 'ClientRepository::find_by_id( (string) $row->client_id )', $src );
			self::assertStringContainsString( 'ConnectorSlugDisplay::bucket( (string) $client->connector_slug )', $src );
		}

		/**
		 * FR-007 — refresh is re-authorization. Without this a connector
		 * disabled mid-session renews itself forever: the mass-revoke kills
		 * existing tokens, but a client holding a refresh token issued moments
		 * earlier simply rotates into a new pair, and refresh never passes
		 * through `/authorize` where the only other gate lives.
		 */
		public function test_refresh_grant_refuses_a_disabled_connector(): void {
			$src = $this->source( 'includes/OAuth/TokenController.php' );

			self::assertMatchesRegularExpression(
				'/if \(\s*! ConnectorSettings::is_slug_enabled_on_server\(\s*\$refresh_server_id,\s*\$refresh_slug\s*\)\s*\)/',
				$src,
				'handle_refresh_token() must consult per-server enablement.'
			);

			$guard  = strpos( $src, 'is_slug_enabled_on_server' );
			$rotate = strpos( $src, 'RefreshTokenRepository::revoke_by_hash' );
			self::assertIsInt( $guard );
			self::assertIsInt( $rotate );
			self::assertLessThan(
				$rotate,
				$guard,
				'Enablement must be checked before the presented refresh token is consumed — otherwise a refused refresh still burns the client\'s only credential.'
			);
		}

		/**
		 * Both runtime guards abstain when the row carries no server binding.
		 * That is a pre-F032 legacy row, where there is no per-server setting to
		 * consult; refusing would deny legacy connections on exactly the installs
		 * least able to diagnose it. Asserted so the abstention reads as a
		 * decision rather than an oversight.
		 */
		public function test_guards_abstain_without_a_server_binding(): void {
			$validator = $this->source( 'includes/OAuth/TokenValidator.php' );
			$token     = $this->source( 'includes/OAuth/TokenController.php' );

			self::assertMatchesRegularExpression(
				'/\$server_id = \(int\) \$row->server_id;\s*if \(\s*\$server_id <= 0\s*\)\s*\{\s*return true;/',
				$validator
			);
			self::assertMatchesRegularExpression(
				'/\$refresh_server_id = \(int\) \$row->server_id;\s*if \(\s*\$refresh_server_id > 0\s*\)/',
				$token
			);
		}

		/**
		 * FR-005 — the UI must not offer a control the endpoint will refuse.
		 *
		 * Deliberately placed after the existing-client branch: an operator who
		 * disables a connector that already holds credentials must still see
		 * them and the Regenerate/Revoke controls, or they are locked out of
		 * cleaning up their own leftovers.
		 */
		public function test_generate_button_is_withheld_for_a_disabled_connector(): void {
			$src = $this->source( 'includes/Connectors/AbstractConnectorProfile.php' );

			self::assertMatchesRegularExpression(
				'/if \(\s*\$server_id > 0 && ! ConnectorSettings::is_slug_enabled_on_server\(\s*\$server_id,\s*\$this->get_slug\(\)\s*\)\s*\)/',
				$src,
				'render_credentials_area() must withhold the Generate button when disabled.'
			);

			$existing = strpos( $src, 'render_regenerate_area( $client_id, $can_manage )' );
			$guard    = strpos( $src, 'is_slug_enabled_on_server' );
			self::assertIsInt( $existing );
			self::assertIsInt( $guard );
			self::assertLessThan(
				$guard,
				$existing,
				'The existing-credentials branch must come first so disabling does not hide existing credentials.'
			);
		}

		/**
		 * FR-001 / FR-002 / FR-003 — the notice the operator actually sees.
		 *
		 * DEC-CONNECTOR-TAB-VISIBILITY settles that the tab stays; this asserts
		 * the notice is emitted before the profile renders, so it appears above
		 * the setup instructions rather than buried under them.
		 */
		public function test_disabled_connector_tab_shows_a_notice_above_its_instructions(): void {
			$src = $this->source( 'admin/Partials/ServerTabs/AIConnectorsTab.php' );

			self::assertMatchesRegularExpression(
				'/\$this->maybe_render_disabled_notice\( \$server, \$profile \);\s*\$profile->render_tab_section\( \$server \);/',
				$src,
				'The notice must be emitted before the profile renders its panel.'
			);
			self::assertStringContainsString( 'notice notice-warning inline', $src );
			self::assertStringContainsString(
				'is disabled on this server.',
				$src,
				'The notice must name the state in plain words.'
			);
		}

		/**
		 * FR-002 — the tab strip itself must stay registry-driven. Filtering it
		 * by enablement would also strip the Settings-panel checkbox that is the
		 * only way back, and contradicts DEC-CONNECTOR-TAB-VISIBILITY.
		 */
		public function test_tab_strip_is_not_filtered_by_enablement(): void {
			$src = $this->source( 'admin/Partials/ServerTabs/AIConnectorsTab.php' );

			self::assertMatchesRegularExpression(
				'/private function render_top_tabs\([^)]*\): void \{\s*echo \'<nav/',
				$src,
				'render_top_tabs() must not gain an enablement filter.'
			);
			self::assertStringNotContainsString(
				'is_slug_enabled_on_server( $server_id, $profile->get_slug() ) ) {
				continue;',
				$src,
				'Tabs must never be skipped for being disabled — keep the tab (DEC-CONNECTOR-TAB-VISIBILITY).'
			);
		}
	}
}
