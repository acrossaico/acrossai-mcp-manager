<?php
/**
 * Feature 119 — the only place email delivery configuration is read, written or tested.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Email
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Email;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes and the one real diagnostic.
 *
 * @since 0.0.34
 */
final class Email_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Settings that may be read, and the credentials that may only be counted.
	 *
	 * Built from the RAW option. Nothing here calls `Options::get()` for a credential key, because
	 * that accessor ends in `Crypto::decrypt()` — asking it politely for the SMTP password returns
	 * the SMTP password.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$raw  = Email_Guard::raw_options();
		$mail = isset( $raw['mail'] ) && is_array( $raw['mail'] ) ? $raw['mail'] : array();

		$credentials = array();
		$withheld    = array();

		foreach ( $raw as $group => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $key => $value ) {
				if ( ! Email_Guard::is_credential( (string) $key ) ) {
					continue;
				}

				$credentials[ $group . '.' . $key ] = '' !== (string) $value;
				$withheld[]                         = $group . '.' . $key;
			}
		}

		return array(
			'mailer'            => isset( $mail['mailer'] ) ? (string) $mail['mailer'] : '',
			'from_email'        => isset( $mail['from_email'] ) ? (string) $mail['from_email'] : '',
			'from_name'         => isset( $mail['from_name'] ) ? (string) $mail['from_name'] : '',
			'from_email_force'  => ! empty( $mail['from_email_force'] ),
			'from_name_force'   => ! empty( $mail['from_name_force'] ),
			'return_path'       => ! empty( $mail['return_path'] ),
			'credentials_set'   => $credentials,
			'withheld'          => $withheld,
			'configured_groups' => array_values( array_keys( $raw ) ),
		);
	}

	/**
	 * Whether the configured mailer can plausibly send.
	 *
	 * `mail` is PHP's own `mail()`. It needs no credentials and is the default, which is exactly why
	 * it is the usual cause of "the site sends nothing": many hosts drop it silently, and the site
	 * reports success either way.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$settings = self::settings();
		$mailer   = $settings['mailer'];
		$notes    = array();

		if ( '' === $mailer ) {
			$notes[] = __( 'No mailer is selected, so WordPress falls back to PHP mail().', 'acrossai-abilities-manager' );
		}

		if ( 'mail' === $mailer ) {
			$notes[] = __( 'The mailer is PHP mail(), which needs no credentials and is the default. Many hosts silently discard it while the site still reports the message as sent, so it is the usual explanation for mail that never arrives.', 'acrossai-abilities-manager' );
		}

		$needed  = self::credentials_for( $mailer, $settings['credentials_set'] );
		$missing = array_keys( array_filter( $needed, static fn( $set ): bool => false === $set ) );

		if ( array() !== $missing ) {
			$notes[] = sprintf(
				/* translators: %s: comma-separated setting names. */
				__( 'The selected mailer has no value stored for: %s. It cannot authenticate, so sending will fail.', 'acrossai-abilities-manager' ),
				implode( ', ', $missing )
			);
		}

		$conflict = self::conflict();

		if ( '' !== $conflict['name'] ) {
			$notes[] = sprintf(
				/* translators: %s: conflicting plugin name. */
				__( 'Another plugin is also taking over email on this site: %s. Two of them competing is a common cause of mail that works intermittently.', 'acrossai-abilities-manager' ),
				$conflict['name']
			);
		}

		$last = self::last_error();

		return array(
			'mailer'              => $mailer,
			'credentials_missing' => array_values( $missing ),
			'conflicting_plugin'  => $conflict['name'],
			'all_conflicts'       => $conflict['all'],
			'last_error'          => $last,
			'looks_deliverable'   => '' !== $mailer && 'mail' !== $mailer && array() === $missing && '' === $conflict['name'],
			'notes'               => $notes,
		);
	}

	/**
	 * Which credential keys matter for the selected mailer.
	 *
	 * Judged from what the mailer's own group actually stores rather than from a hardcoded map, so a
	 * mailer added by a later release is covered without an edit here.
	 *
	 * @since  0.0.34
	 * @param  string             $mailer Selected mailer.
	 * @param  array<string,bool> $set    Credential presence, keyed group.key.
	 * @return array<string, bool>
	 */
	private static function credentials_for( string $mailer, array $set ): array {
		if ( '' === $mailer || 'mail' === $mailer ) {
			return array();
		}

		$out = array();

		foreach ( $set as $path => $present ) {
			if ( 0 === strpos( $path, $mailer . '.' ) ) {
				$out[ $path ] = $present;
			}
		}

		return $out;
	}

	/**
	 * Another mail plugin fighting for the same job.
	 *
	 * @since  0.0.34
	 * @return array{name: string, all: string[]}
	 */
	private static function conflict(): array {
		$class = '\WPMailSMTP\Conflicts';

		if ( ! class_exists( $class ) ) {
			return array(
				'name' => '',
				'all'  => array(),
			);
		}

		try {
			$conflicts = new $class();

			if ( ! method_exists( $conflicts, 'is_detected' ) || ! $conflicts->is_detected() ) {
				return array(
					'name' => '',
					'all'  => array(),
				);
			}

			$all = method_exists( $conflicts, 'get_all_conflict_names' ) ? (array) $conflicts->get_all_conflict_names() : array();

			return array(
				'name' => method_exists( $conflicts, 'get_conflict_name' ) ? (string) $conflicts->get_conflict_name() : '',
				'all'  => array_values( array_map( 'strval', $all ) ),
			);
		} catch ( \Throwable $e ) {
			unset( $e );

			return array(
				'name' => '',
				'all'  => array(),
			);
		}
	}

	/**
	 * The last failure the mail plugin recorded.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	private static function last_error(): string {
		$class = '\WPMailSMTP\Debug';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_last' ) ) {
			return '';
		}

		try {
			return (string) $class::get_last();
		} catch ( \Throwable $e ) {
			unset( $e );

			return '';
		}
	}

	/**
	 * Change the small, safe part of the configuration.
	 *
	 * An allow-list, not a passthrough. The mailer choice is absent because switching it without the
	 * matching credentials stops ALL mail on the site — password resets included — and the
	 * credentials are absent because writing a secret is not this plugin's job.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_settings( array $fields ) {
		$raw  = Email_Guard::raw_options();
		$mail = isset( $raw['mail'] ) && is_array( $raw['mail'] ) ? $raw['mail'] : array();

		$changed = false;

		if ( isset( $fields['from_email'] ) ) {
			$email = sanitize_email( (string) $fields['from_email'] );

			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: supplied value. */
						__( '"%s" is not a valid email address. A sending address that does not parse means the site sends nothing at all.', 'acrossai-abilities-manager' ),
						(string) $fields['from_email']
					)
				);
			}

			$mail['from_email'] = $email;
			$changed            = true;
		}

		foreach ( array( 'from_name' => 'sanitize_text_field' ) as $key => $filter ) {
			if ( isset( $fields[ $key ] ) ) {
				$mail[ $key ] = call_user_func( $filter, (string) $fields[ $key ] );
				$changed      = true;
			}
		}

		foreach ( array( 'from_email_force', 'from_name_force' ) as $flag ) {
			if ( array_key_exists( $flag, $fields ) ) {
				$mail[ $flag ] = (bool) $fields[ $flag ];
				$changed       = true;
			}
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				__( 'Nothing to change. Accepted fields are: from_email, from_name, from_email_force, from_name_force.', 'acrossai-abilities-manager' )
			);
		}

		$raw['mail'] = $mail;

		update_option( Email_Guard::OPTION, $raw );

		$saved = self::settings();

		foreach ( array( 'from_email', 'from_name' ) as $key ) {
			if ( isset( $fields[ $key ] ) && (string) $saved[ $key ] !== (string) $mail[ $key ] ) {
				return new WP_Error(
					'update_failed',
					__( 'The mail settings reported a successful save but read back differently.', 'acrossai-abilities-manager' )
				);
			}
		}

		return array( 'settings' => $saved );
	}

	/**
	 * Actually try to deliver a message.
	 *
	 * The only thing that answers the question. Settings can be perfect and mail still not arrive —
	 * a host blocking the port, a provider rejecting the sender, a rival plugin taking over — and
	 * none of that is visible in configuration.
	 *
	 * Routed through the mail plugin's own test sender so the domain check and its error capture
	 * come with it.
	 *
	 * @since  0.0.34
	 * @param  string $recipient Where to send it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send_test( string $recipient ) {
		$recipient = sanitize_email( $recipient );

		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'A valid recipient address is required. It is never defaulted to the site administrator, because a test send puts a real message in a real inbox and the caller should be the one choosing whose.', 'acrossai-abilities-manager' )
			);
		}

		$class = '\WPMailSMTP\TestEmail\TestEmail';

		if ( ! class_exists( $class ) ) {
			return new WP_Error(
				'test_unavailable',
				__( 'This edition of the mail plugin does not expose a test sender.', 'acrossai-abilities-manager' )
			);
		}

		try {
			$test = new $class();

			if ( method_exists( $test, 'as_html' ) ) {
				$test = $test->as_html( false );
			}

			if ( method_exists( $test, 'with_domain_check' ) ) {
				$test = $test->with_domain_check( true );
			}

			$test->send( $recipient );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'test_send_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The test message could not be sent: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		/*
		 * `is_successful()` is NOT "it was delivered", and must not be reported as such. Two reasons,
		 * both measured:
		 *
		 * 1. It returns true for SUCCESS **or FAILED_DOMAIN_CHECK** (TestEmail.php:189-192), so a
		 *    failed domain check reads as a pass.
		 * 2. With the `mail` mailer it means only that PHP's mail() accepted the message. Measured on
		 *    this site: sending to `probe@example.invalid`, a domain that cannot exist, returned
		 *    success — because mail() hands off to the local agent and returns true regardless.
		 *
		 * So the result is reported as ACCEPTANCE, with the domain check separate and an explicit
		 * statement of what acceptance does and does not prove. Reporting "delivered: true" there
		 * would be the exact failure this suite exists to expose, committed by the suite itself.
		 */
		$result = method_exists( $test, 'get_result' ) ? $test->get_result() : null;

		$accepted      = method_exists( $test, 'is_successful' ) ? (bool) $test->is_successful() : false;
		$domain_failed = property_exists( $class, 'FAILED_DOMAIN_CHECK' ) || defined( $class . '::FAILED_DOMAIN_CHECK' )
			? ( constant( $class . '::FAILED_DOMAIN_CHECK' ) === $result )
			: false;

		if ( ! $accepted ) {
			$detail = self::last_error();

			return new WP_Error(
				'test_send_failed',
				'' !== $detail
					? sprintf(
						/* translators: %s: the mail plugin's own error text. */
						__( 'The mailer refused the test message. It reported: %s', 'acrossai-abilities-manager' ),
						$detail
					)
					: __( 'The mailer refused the test message and recorded no reason. Check the mailer settings, then read the mail plugin debug events.', 'acrossai-abilities-manager' )
			);
		}

		$mailer = self::settings()['mailer'];
		$notes  = array();

		if ( 'mail' === $mailer || '' === $mailer ) {
			$notes[] = __( 'The mailer is PHP mail(), which reports success as soon as it hands the message to the local mail agent. That is not evidence of delivery: a message to an address that cannot exist is accepted just the same. Check the recipient inbox to confirm, or configure a real mailer.', 'acrossai-abilities-manager' );
		}

		if ( $domain_failed ) {
			$notes[] = __( 'The sending domain failed its check, which usually means the SPF or DKIM records do not authorise this sender. The message was still handed off, but receiving servers are likely to reject it or file it as spam.', 'acrossai-abilities-manager' );
		}

		return array(
			'recipient'           => $recipient,
			'mailer'              => $mailer,
			'accepted_by_mailer'  => true,
			'domain_check_passed' => ! $domain_failed,
			'proves_delivery'     => 'mail' !== $mailer && '' !== $mailer && ! $domain_failed,
			'notes'               => $notes,
			'message'             => __( 'The mailer accepted the test message. Acceptance is not the same as arrival - check the inbox to confirm.', 'acrossai-abilities-manager' ),
		);
	}
}
