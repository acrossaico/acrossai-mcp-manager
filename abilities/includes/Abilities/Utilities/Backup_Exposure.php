<?php
/**
 * Feature 127 — whether the web server will hand out a backup archive.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * A backup archive is the entire site in one file: every table, every password hash, every
 * customer's address, every API key stored in an option. Anyone who can download one owns the site.
 *
 * Both plugins drop a `.htaccess` in their backup directory to prevent exactly that — and on nginx,
 * which serves a large share of WordPress, `.htaccess` is not read at all. The protection is present,
 * looks right, and does nothing. Measured on the site this was developed against: nginx, both backup
 * directories answering HTTP 200.
 *
 * This checks what the server actually does rather than what the plugin intended, by asking for a
 * real URL and reading the real status code.
 *
 * @since 0.0.34
 */
final class Backup_Exposure {

	/**
	 * How long to wait for the site to answer itself.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	private const TIMEOUT = 10;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Examine one backup plugin's storage directories.
	 *
	 * Takes the directories rather than discovering them: each backup suite owns its own plugin and
	 * knows where that plugin writes. This helper only answers the question both suites share --
	 * will the web server hand the archive out -- and that question has nothing to do with which
	 * plugin wrote it.
	 *
	 * @since  0.0.35
	 * @param  string             $label Plugin name, for the report.
	 * @param  array<int, string> $paths Absolute directories to check.
	 * @return array<string, mixed>
	 */
	public static function scan( string $label, array $paths ): array {
		$findings = array();
		$exposed  = 0;

		foreach ( $paths as $path ) {
			$finding = self::examine( $label, (string) $path );

			if ( ! empty( $finding['reachable'] ) ) {
				++$exposed;
			}

			$findings[] = $finding;
		}

		return array(
			'server'            => self::server_software(),
			'htaccess_honoured' => self::htaccess_honoured(),
			'directories'       => $findings,
			'exposed_count'     => $exposed,
			'note'              => self::summary( $exposed, count( $findings ) ),
		);
	}

	/**
	 * One directory, checked against the running web server.
	 *
	 * @since  0.0.34
	 * @param  string $label Plugin name.
	 * @param  string $path  Absolute directory path.
	 * @return array<string, mixed>
	 */
	private static function examine( string $label, string $path ): array {
		$url       = self::url_for( $path );
		$guards    = self::guards_in( $path );
		$reachable = null;
		$status    = null;

		if ( '' !== $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					// A local site is commonly on a self-signed certificate; refusing to look would
					// report "not exposed" for a directory that is in fact wide open.
					'sslverify'   => false,
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$status = (int) wp_remote_retrieve_response_code( $response );

				// 200 means the server served something from the directory. 403/404 mean it refused
				// or hid it, which is what the guard files are meant to achieve.
				$reachable = 200 === $status;
			}
		}

		return array(
			'label'        => $label,
			'path'         => $path,
			'url'          => $url,
			'inside_webroot' => '' !== $url,
			'guard_files'  => $guards,
			'http_status'  => $status,
			'reachable'    => $reachable,
			'verdict'      => self::verdict( $url, $reachable, $guards ),
		);
	}

	/**
	 * Plain words for one directory.
	 *
	 * @since  0.0.34
	 * @param  string             $url       Public URL, empty when outside the webroot.
	 * @param  bool|null          $reachable Whether the server served it.
	 * @param  array<int, string> $guards    Guard files present.
	 * @return string
	 */
	private static function verdict( string $url, $reachable, array $guards ): string {
		if ( '' === $url ) {
			return __( 'Outside the web root, so no URL can reach it. This is the safest arrangement.', 'acrossai-abilities-manager' );
		}

		if ( null === $reachable ) {
			return __( 'Inside the web root, but the site could not be reached to test it. Check by hand whether the directory URL is served.', 'acrossai-abilities-manager' );
		}

		if ( ! $reachable ) {
			return __( 'Inside the web root, and the server refuses to serve it. That is the intended arrangement.', 'acrossai-abilities-manager' );
		}

		if ( ! self::htaccess_honoured() && in_array( '.htaccess', $guards, true ) ) {
			return __( 'SERVED OVER HTTP. The directory holds a .htaccess meant to block exactly this, but this server does not read .htaccess files, so the protection is inert. A backup archive is the whole database - every password hash and every stored key. Block this directory in the server configuration, or move backups outside the web root.', 'acrossai-abilities-manager' );
		}

		return __( 'SERVED OVER HTTP. A backup archive is the whole database - every password hash and every stored key - so anyone who learns a filename can take the site. Block this directory in the server configuration, or move backups outside the web root.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  int $exposed Directories served.
	 * @param  int $total   Directories checked.
	 * @return string
	 */
	private static function summary( int $exposed, int $total ): string {
		if ( 0 === $total ) {
			return __( 'No backup storage directory was found to check.', 'acrossai-abilities-manager' );
		}

		if ( 0 === $exposed ) {
			return __( 'No backup directory is served over HTTP.', 'acrossai-abilities-manager' );
		}

		return sprintf(
			/* translators: 1: number of exposed directories, 2: total checked. */
			_n(
				'%1$d of %2$d backup directories is served over HTTP and should be blocked.',
				'%1$d of %2$d backup directories are served over HTTP and should be blocked.',
				$exposed,
				'acrossai-abilities-manager'
			),
			$exposed,
			$total
		);
	}

	/**
	 * Which guard files the plugin left in the directory.
	 *
	 * @since  0.0.34
	 * @param  string $path Directory.
	 * @return array<int, string>
	 */
	private static function guards_in( string $path ): array {
		$present = array();

		foreach ( array( '.htaccess', 'index.php', 'index.html', 'web.config' ) as $guard ) {
			if ( is_file( trailingslashit( $path ) . $guard ) ) {
				$present[] = $guard;
			}
		}

		return $present;
	}

	/**
	 * The public URL of a directory, or empty when it has none.
	 *
	 * @since  0.0.34
	 * @param  string $path Absolute directory path.
	 * @return string
	 */
	private static function url_for( string $path ): string {
		$content_dir = untrailingslashit( (string) WP_CONTENT_DIR );
		$path        = untrailingslashit( $path );

		if ( '' === $path || 0 !== strpos( $path, $content_dir ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $content_dir ) ), '/' );

		return trailingslashit( content_url( $relative ) );
	}

	/**
	 * Whether this web server reads .htaccess at all.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	private static function htaccess_honoured(): bool {
		$server = strtolower( self::server_software() );

		// Apache and LiteSpeed read .htaccess; nginx, IIS and Caddy do not. An unknown server is
		// reported as not honouring it, because assuming protection that may not exist is the
		// dangerous direction to be wrong in.
		return false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	private static function server_software(): string {
		return isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
	}
}
