<?php
/**
 * Shared state for one troubleshooter run (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;

defined( 'ABSPATH' ) || exit;

/**
 * Carries everything the checks share within a single run: the HTTP probe
 * cache, and the results produced so far.
 *
 * The probe cache matters. Six checks want to look at the discovery document,
 * and without this each would fetch it again — six round trips, six chances
 * for a cache layer to behave differently mid-run, and a report that cannot be
 * reasoned about because its rows disagree about what the server returned.
 * One fetch per (URL, headers) pair, reused.
 */
final class Context {

	/** Seconds before a probe gives up. Short: this runs in an admin request. */
	public const TIMEOUT = 5;

	/**
	 * Cached probe responses, keyed by URL + request headers.
	 *
	 * @var array<string, array{code: int, headers: array<string, string>, body: string, error: string}>
	 */
	private array $probes = array();

	/**
	 * Results so far, keyed by check id.
	 *
	 * @var array<string, Result>
	 */
	private array $results = array();

	/**
	 * Fetch a URL once per run.
	 *
	 * Redirects are deliberately NOT followed: on these paths a redirect is
	 * itself the finding. Hosting platforms ship edge rules that 301 the
	 * `.well-known` paths to the homepage, and following it would report
	 * "200 but not JSON" — which names the symptom instead of the owner.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @return array{code: int, headers: array<string, string>, body: string, error: string}
	 */
	public function fetch( string $url, array $headers = array() ): array {
		$key = $url . '|' . wp_json_encode( $headers );

		if ( isset( $this->probes[ $key ] ) ) {
			return $this->probes[ $key ];
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				// A local site serves a self-signed or local-CA certificate.
				// Without this every probe fails on the certificate instead of
				// testing the thing it was written to test.
				'sslverify'   => ! LocalEnvironment::needs_tls_bypass(),
				'headers'     => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$probe = array(
				'code'    => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => $response->get_error_message(),
			);
		} else {
			$probe = array(
				'code'    => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => self::flatten_headers( wp_remote_retrieve_headers( $response ) ),
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'error'   => '',
			);
		}

		$this->probes[ $key ] = $probe;

		return $probe;
	}

	/**
	 * Normalise a headers value into a flat lowercase-keyed string map.
	 *
	 * `wp_remote_retrieve_headers()` returns a `CaseInsensitiveDictionary`,
	 * an array, or occasionally arrays of values for repeated headers. Every
	 * caller here wants one lowercase map of strings.
	 *
	 * @param mixed $raw Whatever the HTTP API returned.
	 * @return array<string, string>
	 */
	private static function flatten_headers( $raw ): array {
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$raw = $raw->getAll();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$flat = array();
		foreach ( $raw as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$flat[ strtolower( (string) $name ) ] = (string) $value;
		}

		return $flat;
	}

	/**
	 * Remember a result so later checks can see whether they are blocked.
	 *
	 * @param Result $result The finished result.
	 * @return void
	 */
	public function record( Result $result ): void {
		$this->results[ $result->id() ] = $result;
	}

	/**
	 * Labels of the given checks that failed, for a skip message.
	 *
	 * @param string ...$ids Check ids to inspect.
	 * @return array<int, string>
	 */
	public function blockers( string ...$ids ): array {
		$labels = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->results[ $id ] ) && $this->results[ $id ]->is_fail() ) {
				$labels[] = $this->results[ $id ]->label();
			}
		}
		return $labels;
	}

	/**
	 * Every header seen on any probe this run, for the edge-layer report.
	 *
	 * @return array<string, string>
	 */
	public function all_observed_headers(): array {
		$merged = array();
		foreach ( $this->probes as $probe ) {
			$merged = array_merge( $merged, $probe['headers'] );
		}
		return $merged;
	}
}
