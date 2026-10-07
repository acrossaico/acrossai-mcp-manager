<?php
/**
 * Descriptor for a site-wide ("global") integration toggle (Feature 024).
 *
 * A global integration is one whose availability is decided once for the whole
 * site rather than per MCP server — n8n is the only member today. Contrast with
 * AbstractConnectorProfile, whose members are enabled per server through
 * `ConnectorSettings::is_slug_enabled_on_server()`. The two axes are deliberate
 * and NOT unified: an OAuth connector is gated at `/authorize`, which n8n's
 * admin-issued bearer tokens never reach. See memory D22.
 *
 * Immutable. Every public string — the option name and the filter name — is an
 * EXPLICIT constructor argument and is never derived from the slug. FR-010 makes
 * that a requirement rather than a style choice: `acrossai_n8n_enabled` predates
 * this registry and is a frozen public string (memory A3), so a registry that
 * generated `acrossai_{slug}_enabled` would silently orphan every existing
 * install's stored value.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Integrations
 * @since 0.9.14
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Integrations;

defined( 'ABSPATH' ) || exit;

final class GlobalIntegration {

	/** @var string */ private $slug               = '';
	/** @var string */ private $option             = '';
	/** @var string */ private $filter             = '';
	/** @var bool */   private $default            = false;
	/** @var int */    private $priority           = 100;
	/** @var string */ private $section_title      = '';
	/** @var bool */   private $beta               = false;
	/** @var string */ private $section_intro      = '';
	/** @var string */ private $field_label        = '';
	/** @var string */ private $toggle_label       = '';
	/** @var string */ private $toggle_description = '';

	/**
	 * Build a descriptor.
	 *
	 * Array-shaped rather than 11 positional parameters so a contributor can
	 * omit the presentational keys and still get a working toggle, and so
	 * adding a key later is not a signature break for third-party callers.
	 *
	 * The array shape is kept on ONE line deliberately: Squiz reads a
	 * multi-line `array{...}` as a missing parameter name, so collapsing it is
	 * what lets the shape coexist with phpcs without an exclude. Note the
	 * shape is documentation today, not a gate — this project runs PHPStan at
	 * level 5, which does not reject an unknown key here (verified by canary).
	 * It starts enforcing if the level is raised.
	 *
	 * @param array{slug: string, option: string, filter: string, default?: bool, priority?: int, section_title?: string, beta?: bool, section_intro?: string, field_label?: string, toggle_label?: string, toggle_description?: string} $args Descriptor fields.
	 */
	public function __construct( array $args ) {
		$this->slug               = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		$this->option             = isset( $args['option'] ) ? (string) $args['option'] : '';
		$this->filter             = isset( $args['filter'] ) ? (string) $args['filter'] : '';
		$this->default            = ! empty( $args['default'] );
		$this->priority           = isset( $args['priority'] ) ? (int) $args['priority'] : 100;
		$this->section_title      = isset( $args['section_title'] ) ? (string) $args['section_title'] : '';
		$this->beta               = ! empty( $args['beta'] );
		$this->section_intro      = isset( $args['section_intro'] ) ? (string) $args['section_intro'] : '';
		$this->field_label        = isset( $args['field_label'] ) ? (string) $args['field_label'] : '';
		$this->toggle_label       = isset( $args['toggle_label'] ) ? (string) $args['toggle_label'] : '';
		$this->toggle_description = isset( $args['toggle_description'] ) ? (string) $args['toggle_description'] : '';
	}

	/**
	 * Integration slug — the registry key. Must match /[a-z0-9-]{1,64}/.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * The `wp_option` name holding the operator's choice.
	 *
	 * Frozen public string — never rename post-release (memory A3).
	 *
	 * @return string
	 */
	public function get_option(): string {
		return $this->option;
	}

	/**
	 * The filter name wrapping the option, giving developers an override the
	 * admin checkbox cannot express. The D6 hybrid resolution recorded in
	 * `specs/013-n8n-per-server-tab/research.md` — operator UI plus developer
	 * override, not one or the other.
	 *
	 * @return string
	 */
	public function get_filter(): string {
		return $this->filter;
	}

	/**
	 * Default when the option row is absent.
	 *
	 * @return bool
	 */
	public function get_default(): bool {
		return $this->default;
	}

	/**
	 * Ordering on the Pro settings tab. Ascending, slug as tiebreak.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return $this->priority;
	}

	/**
	 * Settings-section heading, without any Beta badge — the renderer appends
	 * that from `is_beta()` so the badge markup lives in one place.
	 *
	 * @return string
	 */
	public function get_section_title(): string {
		return $this->section_title;
	}

	/**
	 * Whether to append a Beta badge to the section title.
	 *
	 * @return bool
	 */
	public function is_beta(): bool {
		return $this->beta;
	}

	/**
	 * Paragraph rendered directly under the section heading, before the field.
	 *
	 * @return string
	 */
	public function get_section_intro(): string {
		return $this->section_intro;
	}

	/**
	 * Left-column label for the settings field.
	 *
	 * @return string
	 */
	public function get_field_label(): string {
		return $this->field_label;
	}

	/**
	 * Text beside the checkbox itself.
	 *
	 * @return string
	 */
	public function get_toggle_label(): string {
		return $this->toggle_label;
	}

	/**
	 * Description under the checkbox. For n8n this carries the C8 / SEC-002
	 * revocation caveat, which is load-bearing: disabling hides the tab and
	 * blocks new issuance but does NOT revoke tokens already minted.
	 *
	 * @return string
	 */
	public function get_toggle_description(): string {
		return $this->toggle_description;
	}
}
