<?php
/**
 * Feature 103 — every Contact Form 7 read and write the suite performs.
 *
 * All persistence goes through `wpcf7_save_contact_form()`, CF7's own canonical write path — the one
 * its REST controller and admin screen both use. It sanitises each property and leaves any key passed
 * as null untouched, which is exactly the patch semantics the update abilities need.
 * `set_properties()` + `save()` is deliberately not used: it skips sanitisation entirely and silently
 * drops unknown keys.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7;

use WP_Error;
use WPCF7_ConfigValidator;
use WPCF7_ContactForm;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over CF7's form API.
 */
final class Form_Repository {

	/**
	 * The five properties CF7 stores per form, plus the two scalars.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const PROPERTIES = array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' );

	/**
	 * Fields of a mail template.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const MAIL_FIELDS = array(
		'active',
		'subject',
		'sender',
		'recipient',
		'body',
		'additional_headers',
		'attachments',
		'use_html',
		'exclude_blank',
	);

	/**
	 * Additional settings this suite is willing to write.
	 *
	 * CF7 does not sanitise `additional_settings` at all — only `trim()` — and the values are
	 * behaviour switches, not cosmetics. `skip_mail: on` stops a form emailing anything with no
	 * indication on the form itself. A free-text passthrough here would let a caller disable a site's
	 * contact route by accident, so the suite writes only these four and rejects the rest.
	 *
	 * @since 0.0.34
	 * @var   array<string, bool> setting => whether changing it needs confirmation.
	 */
	public const WRITABLE_SETTINGS = array(
		'demo_mode'                => true,
		'skip_mail'                => true,
		'subscribers_only'         => false,
		'acceptance_as_validation' => false,
	);

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Load one form.
	 *
	 * @since  0.0.34
	 * @param  int $form_id Post ID.
	 * @return WPCF7_ContactForm|WP_Error
	 */
	public static function get( int $form_id ) {
		if ( $form_id < 1 ) {
			return new WP_Error( 'invalid_input', __( 'A form id is required.', 'acrossai-abilities-manager' ) );
		}

		$form = WPCF7_ContactForm::get_instance( $form_id );

		if ( ! $form instanceof WPCF7_ContactForm ) {
			return new WP_Error(
				'form_not_found',
				sprintf(
					/* translators: %d: form id */
					__( 'No contact form with id %d.', 'acrossai-abilities-manager' ),
					$form_id
				)
			);
		}

		return $form;
	}

	/**
	 * Summarise every form.
	 *
	 * @since  0.0.34
	 * @param  string $search Optional title substring.
	 * @param  int    $limit  Maximum rows.
	 * @param  int    $offset Rows to skip.
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_all( string $search = '', int $limit = 100, int $offset = 0 ): array {
		$args = array( 'posts_per_page' => -1 );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$forms = WPCF7_ContactForm::find( $args );
		$rows  = array();

		foreach ( array_slice( $forms, $offset, $limit ) as $form ) {
			$rows[] = self::summarise( $form );
		}

		return $rows;
	}

	/**
	 * Compact description of a form, for list views.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<string,mixed>
	 */
	public static function summarise( WPCF7_ContactForm $form ): array {
		return array(
			'form_id'      => (int) $form->id(),
			'title'        => (string) $form->title(),
			'slug'         => (string) $form->name(),
			'hash'         => (string) $form->hash(),
			'locale'       => (string) $form->locale(),
			'shortcode'    => (string) $form->shortcode(),
			'field_count'  => count( Form_Tag_Repository::tags( $form ) ),
			'config_errors' => self::count_config_errors( $form ),
		);
	}

	/**
	 * Full description of a form, for single reads.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<string,mixed>
	 */
	public static function describe( WPCF7_ContactForm $form ): array {
		return array_merge(
			self::summarise( $form ),
			array(
				'form'                => (string) $form->prop( 'form' ),
				'mail'                => (array) $form->prop( 'mail' ),
				'mail_2'              => (array) $form->prop( 'mail_2' ),
				'messages'            => (array) $form->prop( 'messages' ),
				'additional_settings' => (string) $form->prop( 'additional_settings' ),
			)
		);
	}

	/**
	 * Create or update a form.
	 *
	 * Every write in the suite funnels here. Note the deliberate `wpcf7_kses()` call on the two
	 * fields CF7 only sanitises for users *without* `unfiltered_html`: on a single-site install an
	 * administrator holds that capability, so CF7's own guard would let `<script>` through. That
	 * exemption exists for a trusted human typing into wp-admin; an ability is reachable by an AI
	 * client, so the suite does not inherit it.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $data Keys: id, title, locale, form, mail, mail_2, messages,
	 *                                   additional_settings. Absent keys are untouched.
	 * @return int|WP_Error New or existing post ID.
	 */
	public static function save( array $data ) {
		if ( isset( $data['form'] ) ) {
			$data['form'] = wpcf7_kses( (string) $data['form'], 'form' );
		}

		foreach ( array( 'mail', 'mail_2' ) as $which ) {
			if ( isset( $data[ $which ]['body'] ) ) {
				$data[ $which ]['body'] = self::sanitise_mail_body( (string) $data[ $which ]['body'] );
			}
		}

		$form = wpcf7_save_contact_form( $data );

		if ( ! $form instanceof WPCF7_ContactForm ) {
			return new WP_Error( 'save_failed', __( 'Contact Form 7 refused the save.', 'acrossai-abilities-manager' ) );
		}

		return (int) $form->id();
	}

	/**
	 * Run a mail body through `wpcf7_kses()` without eating its mail tags.
	 *
	 * `From: [your-name] <[your-email]>` is the canonical way to write a From
	 * line in a plain-text mail body, and it is what anyone familiar with email
	 * will type. kses reads `<[your-email]>` as an unknown HTML tag and deletes
	 * it — silently, taking the mail tag with it. The write then reports success,
	 * and `validate-mail-tags` afterwards reports the template as VALID, because
	 * the tag it would have flagged is no longer there to flag. Found by writing
	 * exactly that line over MCP and reading it back.
	 *
	 * The sanitising itself is not the bug and is not relaxed here: an ability is
	 * reachable by an AI client, so the suite deliberately does not inherit CF7's
	 * `unfiltered_html` exemption (see {@see self::save()}). What changes is that
	 * one specific, harmless shape survives it.
	 *
	 * The mask is deliberately narrow. Only `<` + a bare mail tag + `>` matches,
	 * with the tag name held to the same character class the tag parser uses, so
	 * nothing but a tag reference can ride through — no attribute, no second
	 * token, no element. Everything else still meets kses exactly as before.
	 *
	 * @since  0.0.38
	 * @param  string $body Mail body as supplied.
	 * @return string Sanitised body, angle-bracketed mail tags intact.
	 */
	public static function sanitise_mail_body( string $body ): string {
		$masked = array();

		$protected = (string) preg_replace_callback(
			'/<(\[[a-zA-Z_][0-9a-zA-Z:._-]*\])>/',
			static function ( array $match ) use ( &$masked ): string {
				// No angle brackets in the placeholder, so kses leaves it alone.
				$key            = sprintf( '{{acrossai-cf7-mailtag-%d}}', count( $masked ) );
				$masked[ $key ] = '<' . $match[1] . '>';

				return $key;
			},
			$body
		);

		$clean = wpcf7_kses( $protected, 'text' );

		return array() === $masked ? $clean : strtr( $clean, $masked );
	}

	/**
	 * Duplicate a form.
	 *
	 * `copy()` returns an unsaved object, so the save is ours to make.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form  Source.
	 * @param  string            $title Optional new title.
	 * @return int|WP_Error
	 */
	public static function duplicate( WPCF7_ContactForm $form, string $title = '' ) {
		$copy = $form->copy();

		if ( '' !== $title ) {
			$copy->set_title( $title );
		}

		$new_id = $copy->save();

		if ( ! $new_id ) {
			return new WP_Error( 'duplicate_failed', __( 'Could not duplicate the form.', 'acrossai-abilities-manager' ) );
		}

		return (int) $new_id;
	}

	/**
	 * Delete a form permanently.
	 *
	 * CF7's `delete()` calls `wp_delete_post( $id, true )` — it bypasses the trash entirely and there
	 * is no recovery. Callers must be confirm-gated.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return true|WP_Error
	 */
	public static function delete( WPCF7_ContactForm $form ) {
		if ( ! $form->delete() ) {
			return new WP_Error( 'delete_failed', __( 'Could not delete the form.', 'acrossai-abilities-manager' ) );
		}

		return true;
	}

	/**
	 * Run CF7's own configuration validator.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<int, array<string,mixed>>
	 */
	public static function config_errors( WPCF7_ContactForm $form ): array {
		if ( ! class_exists( 'WPCF7_ConfigValidator' ) ) {
			return array();
		}

		$validator = new WPCF7_ConfigValidator( $form );
		$validator->validate();

		$errors = array();

		foreach ( (array) $validator->collect_error_messages() as $section => $messages ) {
			foreach ( (array) $messages as $entry ) {
				$errors[] = array(
					'section' => (string) $section,
					'code'    => isset( $entry['code'] ) ? (string) $entry['code'] : '',
					'message' => isset( $entry['message'] ) ? (string) $entry['message'] : '',
					'link'    => isset( $entry['link'] ) ? (string) $entry['link'] : '',
				);
			}
		}

		return $errors;
	}

	/**
	 * How many configuration errors a form has.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return int
	 */
	public static function count_config_errors( WPCF7_ContactForm $form ): int {
		return count( self::config_errors( $form ) );
	}

	/**
	 * Parse `additional_settings` into key/value pairs.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<string,string>
	 */
	public static function parse_additional_settings( WPCF7_ContactForm $form ): array {
		$parsed = array();

		foreach ( explode( "\n", (string) $form->prop( 'additional_settings' ) ) as $line ) {
			if ( 1 === preg_match( '/^([a-zA-Z0-9_]+)[\t ]*:(.*)$/', trim( $line ), $matches ) ) {
				$parsed[ $matches[1] ] = trim( $matches[2] );
			}
		}

		return $parsed;
	}

	/**
	 * Describe `additional_settings` as a list of rows.
	 *
	 * A list, not the parsed map: `parse_additional_settings()` returns an associative array, which
	 * PHP encodes as a JSON object, and an ability declaring `type => array` for it fails its own
	 * output schema the moment a form has one setting. Both the read and the write ability share this
	 * shape so a caller sees the same rows before and after a change.
	 *
	 * @since  0.0.34
	 * @param  WPCF7_ContactForm $form Form.
	 * @return array<int, array<string,mixed>>
	 */
	public static function describe_additional_settings( WPCF7_ContactForm $form ): array {
		$rows = array();

		foreach ( self::parse_additional_settings( $form ) as $key => $value ) {
			$rows[] = array(
				'key'      => (string) $key,
				'value'    => (string) $value,
				'writable' => array_key_exists( $key, self::WRITABLE_SETTINGS ),
			);
		}

		return $rows;
	}

	/**
	 * Render key/value pairs back into the newline-separated property.
	 *
	 * @since  0.0.34
	 * @param  array<string,string> $settings Settings.
	 * @return string
	 */
	public static function render_additional_settings( array $settings ): string {
		$lines = array();

		foreach ( $settings as $key => $value ) {
			$lines[] = $key . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * The message slugs CF7 recognises, with their default text.
	 *
	 * @since  0.0.34
	 * @return array<string,string>
	 */
	public static function default_messages(): array {
		$defaults = array();

		foreach ( (array) wpcf7_messages() as $slug => $message ) {
			$defaults[ (string) $slug ] = isset( $message['default'] ) ? (string) $message['default'] : '';
		}

		return $defaults;
	}
}
