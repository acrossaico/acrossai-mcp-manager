<?php
/**
 * Feature 116 — the sole ability assembler for the Loco Translate suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * Deliberately small. The suite is four abilities over a plugin with two options, so the base owns
 * only what must not vary: the category, the tab group, the capability floor, the guard order, and
 * the envelope.
 *
 * `Slash_Input` is deliberately ABSENT from this suite, which is the opposite of the WPCode one and
 * needs saying because the instinct is wrong. The rule is about who unslashes, not about the
 * payload: `wp_insert_post()` and `update_post_meta()` unslash internally so their input must arrive
 * slashed, while Loco writes gettext files through its own writer, ending in a raw `putContents()`
 * with no unslashing anywhere in its gettext or fs layers. Slashing here adds a level nothing
 * removes — measured, a single backslash in a translation came back as two.
 */
abstract class Base_Loco_Ability extends Ability_Definition {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-loco-translate';

	/**
	 * Must equal Integrations\Loco_Translate::TAB_GROUP, or the abilities land in one group and the
	 * dispatcher serves another.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'loco-translate';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * The floor, and not overridable.
	 *
	 * Loco's own capability is `loco_admin`, and it creates a `translator` role that a site owner may
	 * hand to a non-administrator. Neither is used as the floor here: a translator editing strings in
	 * wp-admin is a different risk from an AI client writing files that render on every page, and
	 * issue #200 now puts `manage_options` under every ability regardless.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Whether THIS call needs confirmation, as opposed to whether the ability ever does.
	 *
	 * `requires_confirmation()` governs the input schema — it is what puts `confirm` in the
	 * properties, and without it `additionalProperties: false` would reject the key. This governs
	 * the runtime gate. They are separate because an ability can have one input that warrants a
	 * confirmation and another that does not, and gating the harmless one is friction with no risk
	 * behind it.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		unset( $input );

		return $this->requires_confirmation();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'discovery'   => __( 'Discovery', 'acrossai-abilities-manager' ),
			'strings'     => __( 'Strings', 'acrossai-abilities-manager' ),
			'files'       => __( 'Translation Files', 'acrossai-abilities-manager' ),
			'maintenance' => __( 'Maintenance', 'acrossai-abilities-manager' ),
			'wordpress'   => __( 'WordPress.org', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Done.', 'acrossai-abilities-manager' );
	}

	/**
	 * Resolve the project (text domain) an input addresses.
	 *
	 * Needed separately from the file because the compiler will not write the JSON fragments without
	 * it — omit the project and the block editor keeps the old strings while everything else updates.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Ability input.
	 * @return object|\WP_Error Loco_package_Project.
	 */
	protected function resolve_project( array $input ) {
		$bundle = Bundle_Repository::find( (string) ( $input['bundle'] ?? '' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		return Bundle_Repository::project( $bundle, (string) ( $input['domain'] ?? '' ) );
	}

	/**
	 * Resolve a bundle + domain + locale triple to a file on disk.
	 *
	 * With no locale this is the POT template; with one it is that locale's PO. Shared because every
	 * string-level ability addresses a file the same way, and because getting it wrong is silent:
	 * Loco builds a PO path from the project slug, the text domain and whether the directory sits
	 * under the theme, so a hand-built path lands somewhere nothing ever loads.
	 *
	 * `initLocaleFiles()` returns the CANDIDATE paths in preference order. The first that exists is
	 * the file in use; when none exists the first candidate is where a new one belongs, which is what
	 * create-translation-file needs.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input          Ability input.
	 * @param  bool                 $must_exist     Refuse when no file is there yet.
	 * @return string|\WP_Error Absolute path.
	 */
	protected function resolve_file( array $input, bool $must_exist = true ) {
		$bundle = Bundle_Repository::find( (string) ( $input['bundle'] ?? '' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$project = Bundle_Repository::project( $bundle, (string) ( $input['domain'] ?? '' ) );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$tag = trim( (string) ( $input['locale'] ?? '' ) );

		if ( '' === $tag ) {
			$pot = $project->getPot();

			if ( ! $pot || ! $pot->exists() ) {
				return new \WP_Error(
					'no_pot',
					sprintf(
						/* translators: %s: bundle name. */
						__( '"%s" has no POT template, so there is nothing to read without a locale. Either pass a locale to address a translation, or run translations/extract-strings to build a template from the source.', 'acrossai-abilities-manager' ),
						(string) $bundle->getName()
					)
				);
			}

			return (string) $pot->getPath();
		}

		$locale = Loco_Guard::parse_locale( $tag );

		if ( is_wp_error( $locale ) ) {
			return $locale;
		}

		$candidates = $project->initLocaleFiles( $locale );
		$first      = '';

		foreach ( $candidates as $candidate ) {
			$path = (string) $candidate->getPath();

			if ( '' === $first ) {
				$first = $path;
			}

			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		if ( $must_exist ) {
			return new \WP_Error(
				'unknown_translation_file',
				sprintf(
					/* translators: 1: locale, 2: bundle name. */
					__( 'No %1$s translation exists for "%2$s" yet. Create one with translations/create-translation-file.', 'acrossai-abilities-manager' ),
					$tag,
					(string) $bundle->getName()
				)
			);
		}

		if ( '' === $first ) {
			return new \WP_Error(
				'no_target_directory',
				sprintf(
					/* translators: %s: bundle name. */
					__( 'Loco has no configured location to save a translation for "%s".', 'acrossai-abilities-manager' ),
					(string) $bundle->getName()
				)
			);
		}

		return $first;
	}

	/**
	 * Assemble the ability definition.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
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
				'description' => __( 'Must be true to perform this operation.', 'acrossai-abilities-manager' ),
			);

			// Never schema-required: core validates input_schema before execute() runs, so a
			// required confirm yields a generic ability_invalid_input and the gate never fires.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Loco_Guard::can( $this->permission_floor() ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'success' => array( 'type' => 'boolean' ) ),
						$this->output_properties(),
						array(
							'message'    => array( 'type' => 'string' ),
							'error_code' => array( 'type' => 'string' ),
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
	 * Guards, then the ability, then the envelope.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Loco_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Loco_Guard::fail( $available );
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = Loco_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Loco_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Loco_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Loco_Guard::ok( $result, $message );
	}
}
