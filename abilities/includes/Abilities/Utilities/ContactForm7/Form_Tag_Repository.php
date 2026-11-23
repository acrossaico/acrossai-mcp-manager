<?php
/**
 * Feature 103 — reading and rewriting a Contact Form 7 form template.
 *
 * CF7 stores a form as one string of markup with `[type name options]` tags embedded in it. Reading
 * is delegated to CF7's own parser; writing is ours, because CF7 offers no API for "change this one
 * field" — its admin screen edits the raw textarea.
 *
 * Rewrites are therefore textual and deliberately conservative: they locate a tag by its name and
 * replace exactly that tag, leaving every other byte of the template alone. That matters more than
 * elegance here — an author's hand-written layout, comments and inline HTML must survive a field
 * edit untouched.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7;

use WP_Error;
use WPCF7_ContactForm;
use WPCF7_FormTagsManager;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over CF7's form-tag layer.
 */
final class Form_Tag_Repository {

	/**
	 * Mail tags CF7 provides regardless of a form's fields.
	 *
	 * Hardcoded because CF7 resolves them inside `WPCF7_MailTag` at send time rather than exposing a
	 * list. Kept here so `list-mail-tags` can answer completely instead of only naming the form's own
	 * fields, which is the half a caller can already infer.
	 *
	 * @since 0.0.34
	 * @var   array<string,string>
	 */
	public const SPECIAL_MAIL_TAGS = array(
		'_site_title'        => 'Site title',
		'_site_description'  => 'Site tagline',
		'_site_url'          => 'Site URL',
		'_site_admin_email'  => 'Site admin email',
		'_post_id'           => 'ID of the post the form appears on',
		'_post_name'         => 'Slug of that post',
		'_post_title'        => 'Title of that post',
		'_post_url'          => 'URL of that post',
		'_post_author'       => 'Author of that post',
		'_post_author_email' => 'Author email for that post',
		'_date'              => 'Submission date',
		'_time'              => 'Submission time',
		'_invalid_fields'    => 'Count of invalid fields',
		'_serial_number'     => 'Submission serial number (requires Flamingo)',
		'_remote_ip'         => 'Submitter IP address',
		'_user_agent'        => 'Submitter user agent',
		'_url'               => 'URL the form was submitted from',
		'_user_login'        => 'Login of the submitting user, if any',
		'_user_email'        => 'Email of the submitting user, if any',
		'_user_display_name' => 'Display name of the submitting user, if any',
	);

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Every field in a form, as structured data.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<int, array<string,mixed>>
	 */
	public static function tags( WPCF7_ContactForm $form ): array {
		$fields = array();

		foreach ( (array) $form->scan_form_tags() as $tag ) {
			$name = isset( $tag->name ) ? (string) $tag->name : '';

			// Submit buttons and other control tags carry no name and are not fields a caller can
			// address, but they are still part of the template, so they are reported with name ''.
			$fields[] = array(
				'name'     => $name,
				'type'     => isset( $tag->type ) ? (string) $tag->type : '',
				'basetype' => isset( $tag->basetype ) ? (string) $tag->basetype : '',
				'required' => method_exists( $tag, 'is_required' ) ? (bool) $tag->is_required() : false,
				'options'  => isset( $tag->options ) ? array_values( (array) $tag->options ) : array(),
				'values'   => isset( $tag->values ) ? array_values( (array) $tag->values ) : array(),
				'labels'   => isset( $tag->labels ) ? array_values( (array) $tag->labels ) : array(),
			);
		}

		return $fields;
	}

	/**
	 * Whether a form has a field with this name.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @param  string            $name Field name.
	 * @return bool
	 */
	public static function has_field( WPCF7_ContactForm $form, string $name ): bool {
		foreach ( self::tags( $form ) as $tag ) {
			if ( $name === $tag['name'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every form-tag type registered on this install.
	 *
	 * Install-specific: CF7 modules and add-ons register types, so this cannot be a static list.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string,mixed>>
	 */
	public static function types(): array {
		if ( ! class_exists( 'WPCF7_FormTagsManager' ) ) {
			return array();
		}

		$manager   = WPCF7_FormTagsManager::get_instance();
		$collected = array_map( 'strval', (array) $manager->collect_tag_types() );

		// CF7 registers `text` and `text*` as separate tag types, so appending an
		// asterisk to whatever came back produced both a duplicate row and the
		// string `text**`, which is not CF7 syntax at all. Report one row per base
		// type, and claim a required form only where CF7 actually has one: `submit`
		// and the captcha types have none.
		$starred = array_flip( array_filter(
			$collected,
			static function ( string $type ): bool {
				return str_ends_with( $type, '*' );
			}
		) );

		$types = array();
		$seen  = array();

		foreach ( $collected as $type ) {
			$base = rtrim( $type, '*' );

			if ( '' === $base || isset( $seen[ $base ] ) ) {
				continue;
			}

			$seen[ $base ] = true;

			$types[] = array(
				'type'          => $base,
				'required_form' => isset( $starred[ $base . '*' ] ) ? $base . '*' : null,
				'accepts_name'  => (bool) $manager->tag_type_supports( $base, 'name-attr' ),
				'in_mail'       => ! $manager->tag_type_supports( $base, 'not-for-mail' ),
			);
		}

		usort(
			$types,
			static function ( array $a, array $b ): int {
				return strcmp( $a['type'], $b['type'] );
			}
		);

		return $types;
	}

	/**
	 * Whether a form-tag type exists on this install.
	 *
	 * @since  0.0.34
	 * @param  string $type Type, with or without the trailing `*`.
	 * @return bool
	 */
	public static function type_exists( string $type ): bool {
		if ( ! class_exists( 'WPCF7_FormTagsManager' ) ) {
			return false;
		}

		return (bool) WPCF7_FormTagsManager::get_instance()->tag_type_exists( rtrim( $type, '*' ) );
	}

	/**
	 * Mail tags a form makes available.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<string, array<int, array<string,string>>>
	 */
	public static function mail_tags( WPCF7_ContactForm $form ): array {
		$fields = array();

		foreach ( self::tags( $form ) as $tag ) {
			if ( '' === $tag['name'] ) {
				continue;
			}

			$fields[] = array(
				'tag'   => '[' . $tag['name'] . ']',
				'field' => $tag['name'],
				'type'  => $tag['type'],
			);
		}

		$special = array();

		foreach ( self::SPECIAL_MAIL_TAGS as $tag => $description ) {
			$special[] = array(
				'tag'         => '[' . $tag . ']',
				'description' => $description,
			);
		}

		return array(
			'from_fields' => $fields,
			'special'     => $special,
		);
	}

	/**
	 * Mail tags used in a string that no field or special tag provides.
	 *
	 * The commonest silent CF7 breakage: a field is renamed and the notification keeps sending with
	 * an empty line where the value used to be. Nothing warns — the mail still arrives.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @param  string            $body Text to check.
	 * @return array<int, string> Unresolvable tag names.
	 */
	public static function unresolvable_tags( WPCF7_ContactForm $form, string $body ): array {
		$known = array_keys( self::SPECIAL_MAIL_TAGS );

		foreach ( self::tags( $form ) as $tag ) {
			if ( '' !== $tag['name'] ) {
				$known[] = $tag['name'];
			}
		}

		$unresolvable = array();

		if ( preg_match_all( '/\[([a-zA-Z_][0-9a-zA-Z:._-]*)\]/', $body, $matches ) ) {
			foreach ( $matches[1] as $used ) {
				// CF7 allows [field] and the output-modifying [field.something] forms.
				$base = strtok( (string) $used, ':' );
				$base = is_string( $base ) ? $base : (string) $used;

				if ( ! in_array( $base, $known, true ) && ! in_array( $base, $unresolvable, true ) ) {
					$unresolvable[] = $base;
				}
			}
		}

		return $unresolvable;
	}

	/**
	 * Compose one form tag from its parts.
	 *
	 * @since  0.0.34
	 * @param  string               $type     Tag type without `*`.
	 * @param  string               $name     Field name.
	 * @param  bool                 $required Whether the field is required.
	 * @param  array<int, string>   $options  Raw options, e.g. `placeholder`.
	 * @param  array<int, string>   $values   Quoted values, e.g. select choices.
	 * @return string
	 */
	public static function compose_tag( string $type, string $name, bool $required, array $options = array(), array $values = array() ): string {
		$parts = array( rtrim( $type, '*' ) . ( $required ? '*' : '' ), $name );

		foreach ( $options as $option ) {
			$option = self::normalise_option( (string) $option );

			if ( '' !== $option ) {
				$parts[] = $option;
			}
		}

		foreach ( $values as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$parts[] = '"' . str_replace( '"', '', $value ) . '"';
			}
		}

		return '[' . implode( ' ', $parts ) . ']';
	}

	/**
	 * Put a tag option into a form CF7 will read back as ONE option.
	 *
	 * CF7 splits a form tag on whitespace, so `placeholder:+44 7700 900000`
	 * arrives as three options and the field ends up with a placeholder of
	 * `+44`. Values were already quoted here; options were passed through raw,
	 * which made one ability speak two conventions.
	 *
	 * Quoting the value half is what CF7's own syntax asks for, so
	 * `key:some words` becomes `key:"some words"`. An option with no `key:`
	 * prefix has no value half to quote and cannot be repaired here — see
	 * {@see self::invalid_options()}, which the write abilities call first so
	 * the caller is told rather than handed a silently mangled tag.
	 *
	 * @since  0.0.38
	 * @param  string $option Raw option.
	 * @return string Normalised option, or '' when empty.
	 */
	public static function normalise_option( string $option ): string {
		$option = trim( $option );

		if ( '' === $option || 1 !== preg_match( '/\s/', $option ) ) {
			return $option;
		}

		if ( 1 === preg_match( '/^([A-Za-z0-9_-]+):(.*)$/s', $option, $match ) ) {
			return $match[1] . ':"' . str_replace( '"', '', $match[2] ) . '"';
		}

		return $option;
	}

	/**
	 * Options that cannot survive being written into a tag.
	 *
	 * Only one shape qualifies: whitespace with no `key:` prefix, so there is no
	 * value half to quote. Returning them lets the ability refuse with the
	 * offending text in hand, which is worth more than a tag that looks saved
	 * and reads back as several options.
	 *
	 * @since  0.0.38
	 * @param  array<int,mixed> $options Raw options.
	 * @return array<int,string> Offending options, in the order given.
	 */
	public static function invalid_options( array $options ): array {
		$invalid = array();

		foreach ( $options as $option ) {
			$option = trim( (string) $option );

			if ( '' === $option ) {
				continue;
			}

			if ( 1 === preg_match( '/\s/', $option )
				&& 1 !== preg_match( '/^[A-Za-z0-9_-]+:/', $option ) ) {
				$invalid[] = $option;
			}
		}

		return $invalid;
	}

	/**
	 * Append a labelled field to a template.
	 *
	 * Appended rather than inserted at an arbitrary offset: CF7 templates are freeform markup, and
	 * guessing where a caller means "after the email field" in HTML that may wrap it in a table cell
	 * produces broken layout more often than it helps. A caller wanting exact placement has
	 * `update-form-template`.
	 *
	 * @since  0.0.34
	 * @param  string $template Existing template.
	 * @param  string $tag      Composed tag.
	 * @param  string $label    Visible label; '' emits the tag alone.
	 * @param  bool   $prepend  Place at the start rather than the end.
	 * @return string
	 */
	public static function append_field( string $template, string $tag, string $label = '', bool $prepend = false ): string {
		$block = '' === $label
			? $tag
			: sprintf( "<label> %s\n    %s </label>", $label, $tag );

		$template = rtrim( $template );

		return $prepend
			? $block . "\n\n" . $template
			: $template . "\n\n" . $block;
	}

	/**
	 * Replace the tag named $name with $replacement.
	 *
	 * @since  0.0.34
	 * @param  string $template    Existing template.
	 * @param  string $name        Field name to find.
	 * @param  string $replacement New tag text, or '' to remove it.
	 * @return string|WP_Error
	 */
	public static function replace_tag( string $template, string $name, string $replacement ) {
		$pattern = self::tag_pattern( $name );
		$count   = 0;
		$result  = preg_replace( $pattern, str_replace( '\\', '\\\\', $replacement ), $template, -1, $count );

		if ( null === $result ) {
			return new WP_Error( 'template_rewrite_failed', __( 'Could not rewrite the form template.', 'acrossai-abilities-manager' ) );
		}

		if ( 0 === $count ) {
			return new WP_Error(
				'field_not_found',
				sprintf(
					/* translators: %s: field name */
					__( 'No field named "%s" in this form.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		return $result;
	}

	/**
	 * Remove a field, and its wrapping label when that label held nothing else.
	 *
	 * Removing the tag alone would leave an orphan `<label> Your name </label>` — visible on the
	 * form, attached to nothing.
	 *
	 * @since  0.0.34
	 * @param  string $template Existing template.
	 * @param  string $name     Field name.
	 * @return string|WP_Error
	 */
	public static function remove_field( string $template, string $name ) {
		$tag = self::tag_pattern( $name, false );

		// A <label> whose only tag is this one: drop the whole block.
		$labelled = '/<label>(?:(?!<label>).)*?' . $tag . '(?:(?!<\/label>).)*?<\/label>\s*/is';
		$count    = 0;
		$result   = preg_replace( $labelled, '', $template, -1, $count );

		if ( null !== $result && $count > 0 ) {
			return trim( $result ) . "\n";
		}

		$stripped = self::replace_tag( $template, $name, '' );

		if ( is_wp_error( $stripped ) ) {
			return $stripped;
		}

		return trim( $stripped ) . "\n";
	}

	/**
	 * Regex matching the tag with a given name.
	 *
	 * Anchored on the name as a whole word so `your-email` does not match `your-email-2`.
	 *
	 * @since  0.0.34
	 * @param  string $name      Field name.
	 * @param  bool   $delimited Return a complete pattern rather than a fragment.
	 * @return string
	 */
	private static function tag_pattern( string $name, bool $delimited = true ): string {
		$fragment = '\[[a-zA-Z0-9_]+\*?[\t ]+' . preg_quote( $name, '/' ) . '(?![0-9a-zA-Z_-])[^\]]*\]';

		return $delimited ? '/' . $fragment . '/' : $fragment;
	}
}
