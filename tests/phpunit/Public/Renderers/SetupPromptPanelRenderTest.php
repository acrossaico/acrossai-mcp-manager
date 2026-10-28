<?php
/**
 * Connect-tab setup-prompt panel render tests (Feature 099, T027 + T030).
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\Utilities\CredentialNaming;
use AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock;
use WP_UnitTestCase;

final class SetupPromptPanelRenderTest extends WP_UnitTestCase {

	/** @var int */
	private $server_id = 0;

	/** @var int */
	private $admin_id = 0;

	public function setUp(): void {
		parent::setUp();

		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Prompt Panel Fixture',
				'server_slug'            => 'prompt-panel-fixture',
				'description'            => 'Fixture for the F099 prompt panel.',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'prompt-panel-fixture',
				'server_version'         => 'v1.0.0',
			)
		);

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Render one client's panel and return the markup.
	 *
	 * @param string $client_slug Client to select.
	 * @param int    $server_id   Server id, defaults to the fixture.
	 * @return string
	 */
	private function render( string $client_slug, int $server_id = 0, string $route = '' ): string {
		if ( '' !== $route ) {
			$_GET['setup'] = $route;
		}

		ob_start();
		MCPClientsBlock::instance()->render(
			0 === $server_id ? $this->server_id : $server_id,
			array(
				'context'    => 'admin',
				'sub_client' => $client_slug,
			)
		);
		$output = (string) ob_get_clean();

		unset( $_GET['setup'] );

		return $output;
	}

	/**
	 * FR-001 / FR-011 — step 2 offers BOTH routes. The prompt is an addition,
	 * never a replacement: an operator part-way through the manual steps must
	 * not find them gone after an update.
	 */
	public function test_step_two_offers_both_routes_as_a_choice(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'Choose how to add it to', $output, 'Step 2 is the choice itself.' );
		$this->assertStringContainsString( 'Let your AI client do it', $output );
		$this->assertStringContainsString( 'Set it up yourself', $output );
		$this->assertStringContainsString( 'setup=prompt', $output );
		$this->assertStringContainsString( 'setup=manual', $output );
	}

	/**
	 * The point of the redesign: ONLY the chosen route renders.
	 *
	 * Previously the prompt and the manual steps stacked, so the two read as
	 * one long sequence and an operator could not tell where the first ended
	 * or that they were alternatives at all.
	 */
	public function test_only_the_chosen_route_is_visible(): void {
		$prompt = $this->render( 'claude-code', 0, 'prompt' );

		$this->assertStringContainsString( 'data-acrossai-route="prompt"', $prompt );
		$this->assertStringContainsString(
			'data-acrossai-route="manual" hidden',
			$prompt,
			'With the prompt selected, the manual route must render hidden.'
		);
		$this->assertStringNotContainsString(
			'data-acrossai-route="prompt" hidden',
			$prompt,
			'The selected route must not be hidden.'
		);

		$manual = $this->render( 'claude-code', 0, 'manual' );

		$this->assertStringContainsString( 'data-acrossai-route="manual"', $manual );
		$this->assertStringContainsString(
			'data-acrossai-route="prompt" hidden',
			$manual,
			'With manual selected, the prompt route must render hidden.'
		);
	}

	/**
	 * Both routes are in the DOM so switching needs no reload.
	 *
	 * A reload would be actively harmful here: the Application Password is
	 * shown exactly once and cannot be re-fetched, so reloading mid-setup
	 * discards a credential the operator may not have copied yet.
	 */
	public function test_both_routes_are_present_so_switching_needs_no_reload(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'Paste this prompt into', $output );
		$this->assertStringContainsString( 'Find the top-level key', $output );
		$this->assertSame(
			2,
			substr_count( $output, 'data-acrossai-route=' ),
			'Both route panels must be rendered for the client-side toggle to switch between them.'
		);
	}

	/**
	 * The `hidden` attribute must actually hide the inactive route.
	 *
	 * This is a CSS fact that no markup assertion can reach, and it shipped
	 * broken: `hidden` works only through the UA stylesheet's
	 * `[hidden] { display: none }`, which any author rule setting `display`
	 * overrides — and `.acrossai-mcp-route-panel` sets `display: flex`. The
	 * PHP emitted a correct `hidden` attribute and both routes rendered
	 * visible anyway, so every existing test passed while the screen was
	 * wrong.
	 *
	 * Asserting against the BUILT stylesheet is the only place this is
	 * catchable without a browser.
	 */
	public function test_built_stylesheet_actually_hides_the_inactive_route(): void {
		$css = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/css/backend.css';

		$this->assertFileExists( $css, 'Run `npm run build` before this suite.' );

		$this->assertStringContainsString(
			'.acrossai-mcp-route-panel[hidden]',
			(string) file_get_contents( $css ),
			'Without this rule the panel\'s own display:flex beats the UA [hidden] rule '
			. 'and BOTH routes render at once.'
		);
	}

	/**
	 * FR-009 — the password splice must reach BOTH payloads.
	 *
	 * Targeting one element id meant generating a password updated the config
	 * and silently left the prompt holding the placeholder, so anyone copying
	 * the prompt pasted "(paste generated password here)" into their AI client.
	 */
	public function test_both_payloads_are_marked_for_the_password_splice(): void {
		$output = $this->render( 'claude-code' );

		$this->assertSame(
			2,
			substr_count( $output, 'data-acrossai-password-target' ),
			'The prompt and the config must both be reachable by the splice.'
		);
	}

	/**
	 * The prompt is the default. It is the shorter route and the recommended
	 * one, so an operator who changes nothing gets it.
	 */
	public function test_prompt_is_the_default_route(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'Paste this prompt into', $output );
		$this->assertStringContainsString( 'Recommended', $output );
		$this->assertStringContainsString( 'aria-current="true"', $output );
	}

	/**
	 * An unrecognised route falls back to the prompt rather than rendering an
	 * empty Step 2 — a hand-edited or stale URL must not produce a dead screen.
	 */
	public function test_unknown_route_falls_back_to_the_prompt(): void {
		$output = $this->render( 'claude-code', 0, 'nonsense' );

		$this->assertStringContainsString( 'Paste this prompt into', $output );
	}

	/**
	 * FR-003 — the prompt embeds the same configuration the manual block
	 * shows. Both are rendered from one builder, so this asserts the page
	 * cannot show two different configs for one client.
	 */
	public function test_prompt_and_manual_route_describe_the_same_config(): void {
		$prompt = $this->render( 'claude-code', 0, 'prompt' );
		$manual = $this->render( 'claude-code', 0, 'manual' );

		foreach ( array( $prompt, $manual ) as $output ) {
			$this->assertStringContainsString( '@automattic/mcp-wordpress-remote@latest', $output );
			$this->assertStringContainsString( 'WP_API_USERNAME', $output );
		}
	}

	/**
	 * FR-007 / FR-010 / SC-C1 — a freshly rendered panel never contains a
	 * credential, only the placeholder.
	 *
	 * On this surface switching client is a full page reload (the sub-nav is
	 * anchor links), so a stale credential from a previously selected client
	 * is structurally impossible here — every render starts from the
	 * placeholder. The React wizard switches without reloading and carries its
	 * own reset; that is covered on its own surface.
	 */
	public function test_fresh_render_carries_the_placeholder_not_a_credential(): void {
		foreach ( array( 'claude-code', 'cursor', 'codex' ) as $slug ) {
			$output = $this->render( $slug );

			$this->assertStringContainsString(
				AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER,
				$output,
				"{$slug}: the placeholder should be present on a fresh render."
			);
		}
	}

	/**
	 * FR-020 / SC-C13 — the disclosure must say what the credential can DO.
	 * "Contains a live password" is true and useless; an operator can read
	 * that as a password scoped to this connection, which it is not.
	 */
	public function test_disclosure_states_the_credentials_reach_not_merely_its_existence(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'working password for your site', $output );
		$this->assertStringContainsString(
			'not just this MCP server',
			$output,
			'The disclosure must correct the natural misreading that the credential is scoped to this server.'
		);
		$this->assertStringContainsString(
			'conversation history',
			$output,
			'The operator must be told where the credential ends up.'
		);
	}

	/**
	 * FR-020 / SC-C14 — revocation is reachable from the same panel.
	 */
	public function test_revocation_is_one_step_away(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'profile.php#application-passwords', $output );
		$this->assertStringContainsString( 'Revoke a password', $output );
	}

	/**
	 * FR-024 / SC-C10 / SC-014 — administrator-only, checked at the panel.
	 * A lower-capability user still gets the manual route and a page that
	 * renders without error; they simply get no prompt and no mint control.
	 */
	public function test_non_administrator_sees_the_manual_route_but_no_prompt(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		ob_start();
		MCPClientsBlock::instance()->render(
			$this->server_id,
			array(
				'context'    => 'admin',
				'cap'        => 'edit_posts',
				'sub_client' => 'claude-code',
			)
		);
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			'Paste this prompt into',
			$output,
			'The prompt must not render below administrator.'
		);
		$this->assertStringNotContainsString(
			'Choose how to add it to',
			$output,
			'Nor the picker — there is nothing to choose between when one route is unavailable.'
		);
		$this->assertStringContainsString(
			'Find the top-level key',
			$output,
			'The manual route must still render — the page does not become an error.'
		);
	}

	/**
	 * A22 — this renderer is exposed to the front end, where wp-admin CSS does
	 * not load. The new panel must not depend on admin-only class names; an
	 * admin-side test cannot otherwise catch a silently unstyled surface.
	 */
	public function test_prompt_panel_uses_plugin_owned_class_names(): void {
		$output = $this->render( 'claude-code' );

		$start = strpos( $output, 'acrossai-mcp-route-panel' );
		$this->assertNotFalse( $start );

		$panel = substr( $output, $start, 2000 );

		foreach ( array( 'nav-tab', 'wp-list-table' ) as $admin_class ) {
			$this->assertStringNotContainsString(
				$admin_class,
				$panel,
				"The panel leans on the admin-only class '{$admin_class}'."
			);
		}
	}

	/**
	 * SC-C18 — the prompt is escaped at the render boundary. A third-party
	 * client class is untrusted input: anything it returns reaches this page.
	 */
	public function test_prompt_is_escaped_at_the_render_boundary(): void {
		$output = $this->render( 'claude-code' );

		$this->assertStringContainsString( '<textarea', $output );
		$this->assertStringNotContainsString(
			'<script',
			$output,
			'No raw script tag may reach the page through the prompt.'
		);
	}

	/**
	 * FR-009 / SC-004 — the placeholder is a CONTRACT between PHP and
	 * JavaScript, and nothing else enforces it.
	 *
	 * `AbstractMCPClient::safe_token()` writes the placeholder; `backend.js`
	 * and `Step11_ClientDetail.jsx` both find-and-replace that exact literal.
	 * If the PHP constant is ever changed without changing both JS files,
	 * substitution silently stops working for EVERY client at once — password
	 * shown beside the button, placeholder still sitting in the config below,
	 * and no error anywhere.
	 *
	 * A JavaScript unit test would not catch this: `tests/jest/` has no `test`
	 * script in package.json and no CI job, so those files are linted and
	 * never executed. This assertion runs on every PHPUnit run instead.
	 */
	public function test_javascript_placeholder_matches_the_php_constant(): void {
		$php_placeholder = AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER;

		$sources = array(
			'src/js/backend.js',
			'src/js/quick-connect/steps/Step11_ClientDetail.jsx',
		);

		foreach ( $sources as $relative ) {
			$path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . $relative;

			$this->assertFileExists( $path );

			$source = (string) file_get_contents( $path );

			$this->assertStringContainsString(
				$php_placeholder,
				$source,
				"{$relative} no longer contains the placeholder literal that PHP emits. "
				. 'Credential substitution is now broken for every client on that surface.'
			);
		}
	}

	/**
	 * FR-009 — every client's rendered config carries the placeholder, so the
	 * substitution above has something to find regardless of which client the
	 * operator picked. OpenCode is called out by name: its credential lives
	 * under `environment`, not `env`, which is what the old JSON-walking
	 * splice missed entirely.
	 */
	public function test_every_client_renders_a_substitutable_placeholder(): void {
		foreach ( AbstractMCPClient::get_all_registered_clients() as $client ) {
			$slug   = $client->get_client_slug();
			$output = $this->render( $slug );

			$this->assertStringContainsString(
				AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER,
				$output,
				"{$slug}: nothing for the substitution to replace."
			);
		}
	}

	/**
	 * FR-025 / SC-015 — accumulation must be VISIBLE. Generating again leaves
	 * earlier credentials working, which is the right behaviour (silently
	 * revoking would break a connection the operator already has running), but
	 * it is only acceptable while the operator can see it happening.
	 */
	public function test_existing_credential_count_is_shown_once_any_exist(): void {
		$before = $this->render( 'claude-code' );

		$this->assertStringNotContainsString(
			'already exist',
			$before,
			'A first-time operator should not be told they have no credentials yet.'
		);
		$this->assertStringContainsString(
			'Revoke a password',
			$before,
			'Revocation stays reachable even before the first credential exists (SC-C14).'
		);

		\WP_Application_Passwords::create_new_application_password(
			$this->admin_id,
			array( 'name' => CredentialNaming::PREFIX . ' — Prompt Panel Fixture — claude-code — 2026-10-28' )
		);

		$after = $this->render( 'claude-code' );

		$this->assertStringContainsString( 'acrossai-mcp-route-panel__footnote', $after );
		$this->assertStringContainsString( 'already exists for this server', $after );
	}

	/**
	 * The count is scoped to THIS server. A credential issued for a different
	 * server must not inflate it, or the number means nothing.
	 */
	public function test_count_ignores_credentials_for_other_servers(): void {
		\WP_Application_Passwords::create_new_application_password(
			$this->admin_id,
			array( 'name' => CredentialNaming::PREFIX . ' — Some Other Server — cursor — 2026-10-28' )
		);

		$output = $this->render( 'claude-code' );

		$this->assertStringNotContainsString(
			'already exist',
			$output,
			"Another server's credential must not be counted against this one."
		);
	}

	/**
	 * FR-012 — with no resolvable server there is no prompt to render, because
	 * there is no server URL to name. The screen degrades to a notice rather
	 * than rendering instructions that point nowhere.
	 */
	public function test_no_prompt_without_a_resolvable_server(): void {
		$output = $this->render( 'claude-code', 999999 );

		$this->assertStringNotContainsString( 'acrossai-mcp-route-panel', $output );
	}
}
