<?php
/**
 * Feature 136 — shared assembly for the version-targeting abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Wp_Rollback_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * One place for everything the version abilities share.
 *
 * Modelled on {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo\Base_Aioseo_Ability},
 * with two differences the suite needs:
 *
 * - **`tab_group()` is abstract, not a constant.** This one category spans two host plugins, and
 *   each belongs to its own toolset because `is_active()` differs per plugin. The tagger assigns a
 *   group from `meta.acrossai.tab_group`, so abilities in one namespace can legitimately land in
 *   two groups.
 * - **`requires_host()` defaults to false, and nothing in the WP Rollback group overrides it.**
 *   Listing published versions is `plugins_api()` / `themes_api()`, which is WordPress core, and
 *   reporting whether the host plugin is active obviously cannot require it. The two rollback
 *   writes were expected to need WP Rollback and turned out not to: they drive core's own
 *   `Plugin_Upgrader` / `Theme_Upgrader`, which has carried backup-and-restore since 6.3. Gating
 *   any of them on a plugin they do not use would be a restriction invented by this code rather
 *   than one the problem has.
 *
 * @since 0.0.45
 */
abstract class Base_Version_Ability extends Ability_Definition {

	/**
	 * Ability name prefix for the whole suite.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	protected const NAMESPACE_PREFIX = 'versions/';

	/**
	 * Slug after the namespace prefix.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * Which toolset this ability belongs to.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function tab_group(): string;

	/**
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	abstract protected function annotations(): array;

	/**
	 * Do the work. Return a payload, or a WP_Error to refuse.
	 *
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Caller input.
	 * @return array<string,mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/* ------------------------------------------------------------------ */
	/* Overridable policy                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether this ability cannot work without its host plugin loaded.
	 *
	 * False for everything that reads WordPress.org, true for anything that drives a host plugin.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	protected function requires_host(): bool {
		return false;
	}

	/**
	 * Whether this ability's host plugin is loaded.
	 *
	 * Overridable because this one category spans two host plugins. The first version of this class
	 * hardcoded the WP Rollback guard here, so a core-pinning ability with `requires_host()` true
	 * refused with "WP Rollback is not active" — naming a plugin that has nothing to do with
	 * pinning core, and sending the caller to install the wrong thing.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	protected function host_is_active(): bool {
		return Wp_Rollback_Guard::is_active();
	}

	/**
	 * The refusal when this ability's host plugin is absent.
	 *
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function host_not_active(): array {
		return Wp_Rollback_Guard::not_active();
	}

	/**
	 * The response key reporting whether the host plugin was present.
	 *
	 * Host-specific so a pinning response does not carry a field about WP Rollback, which would be
	 * noise at best and misdirection at worst.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	protected function host_active_key(): string {
		return 'wp_rollback_active';
	}

	/**
	 * Whether a `confirm: true` flag is required.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * What the caller is being asked to confirm.
	 *
	 * Overridable because the default described replacing files on disk, which is true of a rollback
	 * and false of a core pin — the pin writes one option. A confirmation prompt that misdescribes
	 * the operation is worse than none: it trains a caller to pass confirm without reading.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This operation replaces files on disk and cannot be undone automatically. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * Whether this ability writes to the filesystem, and so must honour DISALLOW_FILE_MODS.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	protected function writes_files(): bool {
		return false;
	}

	/**
	 * The asset type this ability acts on, for the capability check.
	 *
	 * `plugin`, `theme`, or '' when it is not type-specific.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	protected function asset_type(): string {
		return '';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Operation completed.', 'acrossai-abilities-manager' );
	}

	/**
	 * Sub-group display labels, in one place.
	 *
	 * @since  0.0.45
	 * @return array<string,string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'versions-read'     => __( 'Versions', 'acrossai-abilities-manager' ),
			'versions-rollback' => __( 'Rollback', 'acrossai-abilities-manager' ),
			'versions-core-pin' => __( 'Core Pinning', 'acrossai-abilities-manager' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * One caller value coerced to a string without PHP complaining about it.
	 *
	 * Borrowed from the AIOSEO base for the same reason it exists there: `(string) $input['slug']`
	 * emits "Array to string conversion" when something calls `execute()` directly, before the
	 * ability gets to refuse politely. A non-scalar becomes '', which every caller here treats as
	 * "not given".
	 *
	 * @since  0.0.45
	 * @param  mixed $value Caller value.
	 * @return string
	 */
	final protected static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Turn a WP_Error into this suite's refusal shape.
	 *
	 * @since  0.0.45
	 * @param  \WP_Error $error Refusal.
	 * @return array<string,mixed>
	 */
	final protected static function fail( \WP_Error $error ): array {
		return array(
			'success'    => false,
			'error_code' => $error->get_error_code(),
			'message'    => $error->get_error_message(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Assembly                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => $this->tab_group(),
			'sub_group' => $sub_group,
		);

		$label = $this->sub_group_labels()[ $sub_group ] ?? '';

		if ( '' !== $label ) {
			$acrossai['sub_group_label'] = $label;
		}

		$properties = $this->input_properties();
		$required   = $this->required_input();

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this irreversible operation.', 'acrossai-abilities-manager' ),
			);

			// Never schema-required: core validates input_schema before execute() runs, so a
			// required confirm fails as a generic ability_invalid_input and the caller never sees
			// confirmation_required or the message naming the flag. Stripped so no subclass can
			// reintroduce it.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		$type = $this->asset_type();

		return array(
			'name' => self::NAMESPACE_PREFIX . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => Category_Registrar::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function () use ( $type ): bool {
					// Mirrors WP Rollback's own PluginUtility::currentUserCanRollback() rather than
					// settling for manage_options, so no ability here permits what the host
					// plugin's own route would refuse.
					return current_user_can( 'manage_options' ) && Wp_Rollback_Guard::current_user_can_rollback( $type );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'success'                => array( 'type' => 'boolean' ),
							$this->host_active_key() => array( 'type' => 'boolean' ),
						),
						$this->output_properties(),
						array(
							'message'        => array( 'type' => 'string' ),
							'error_code'     => array( 'type' => 'string' ),
							'blocked_reason' => array( 'type' => 'string' ),
							'active'         => array( 'type' => 'boolean' ),
						)
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => $acrossai,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * The guard order is mandatory, which is why this is final: the host check must precede the
	 * confirmation check, or an ability refuses for a missing `confirm` on a site where it could
	 * never have run anyway — and the caller fixes the wrong thing.
	 *
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	final public function execute( array $input = array() ): array {
		if ( $this->requires_host() && ! $this->host_is_active() ) {
			return $this->host_not_active();
		}

		if ( $this->writes_files() ) {
			$blocked = File_Mods_Guard::blocked_response();

			if ( null !== $blocked ) {
				return $blocked;
			}
		}

		if ( $this->requires_confirmation() && empty( $input['confirm'] ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'confirmation_required',
				'message'        => $this->confirmation_message(),
			);
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			$failure = self::fail( $result );
			// Always say whether the host plugin was there, so a refusal is never ambiguous about
			// whether installing it would have helped.
			$failure[ $this->host_active_key() ] = $this->host_is_active();

			return $failure;
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return array_merge(
			array(
				'success'                     => true,
				$this->host_active_key()      => $this->host_is_active(),
			),
			$result,
			array( 'message' => $message )
		);
	}
}
