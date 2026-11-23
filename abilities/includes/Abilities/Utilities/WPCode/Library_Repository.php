<?php
/**
 * Feature 112 — the WPCode cloud library and snippet packs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode;

use WPCode_Snippet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and installs from WPCode's hosted library.
 *
 * Everything installed here lands INACTIVE and stays that way. A library snippet is third-party code
 * that will execute on this site, so the decision to run it belongs to a human looking at it, not to
 * whatever asked for the install. WPCode's own `create_snippet_from_data()` already saves without
 * setting `active`, but that is its choice and could change; this enforces it rather than inheriting
 * it.
 *
 * @since 0.0.34
 */
final class Library_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * WPCode's library object, loading it if this request is not an admin one.
	 *
	 * WPCode only builds `$this->library` inside `if ( is_admin() || DOING_CRON )` (ihaf.php:435),
	 * and only requires the class file under the same condition. A REST or MCP request is neither,
	 * so without this every library ability would report the library as unavailable on a site where
	 * it works perfectly well in wp-admin. The constructor only adds hooks, so building it here is
	 * cheap and has no side effect beyond what wp-admin already does.
	 *
	 * @since  0.0.34
	 * @return object|null
	 */
	private static function library() {
		if ( ! function_exists( 'wpcode' ) ) {
			return null;
		}

		if ( isset( wpcode()->library ) && is_object( wpcode()->library ) ) {
			return wpcode()->library;
		}

		/*
		 * The library leans on two more components that sit behind the same admin gate, and each
		 * fatals on null rather than degrading: file_cache backs get_data(), and library_auth is
		 * reached through get_authenticated_headers() on every API call, so installing without it
		 * dies on has_auth(). Loaded in dependency order before the library itself.
		 */
		foreach (
			array(
				'file_cache'   => array( 'WPCode_File_Cache', 'includes/class-wpcode-file-cache.php' ),
				'library_auth' => array( 'WPCode_Library_Auth', 'includes/class-wpcode-library-auth.php' ),
			) as $property => $component
		) {
			if ( null === self::load_component( $property, $component[0], $component[1] ) ) {
				return null;
			}
		}

		return self::load_component( 'library', 'WPCode_Library', 'includes/class-wpcode-library.php' );
	}

	/**
	 * Build one of WPCode's admin-only components and hang it back on the main object.
	 *
	 * Assigning it back matters: WPCode's own code reads these through `wpcode()->…`, so a local
	 * instance would leave the next internal call still facing null.
	 *
	 * @since  0.0.34
	 * @param  string $property Property on the wpcode() object.
	 * @param  string $class    Global class name.
	 * @param  string $relative Path under WPCODE_PLUGIN_PATH.
	 * @return object|null
	 */
	private static function load_component( string $property, string $class, string $relative ) {
		if ( ! function_exists( 'wpcode' ) ) {
			return null;
		}

		if ( isset( wpcode()->{$property} ) && is_object( wpcode()->{$property} ) ) {
			return wpcode()->{$property};
		}

		if ( ! class_exists( $class ) ) {
			if ( ! defined( 'WPCODE_PLUGIN_PATH' ) ) {
				return null;
			}

			$file = WPCODE_PLUGIN_PATH . $relative;

			if ( ! is_readable( $file ) ) {
				return null;
			}

			require_once $file;
		}

		if ( ! class_exists( $class ) ) {
			return null;
		}

		wpcode()->{$property} = new $class();

		return wpcode()->{$property};
	}

	/**
	 * WPCode's packs helper.
	 *
	 * A singleton reached through `WPCode_Packs::get_instance()`, NOT a property on `wpcode()` —
	 * there is no `wpcode()->packs` at all. Its class file is required only in the admin branch, so
	 * the same on-demand load applies.
	 *
	 * @since  0.0.34
	 * @return object|null
	 */
	private static function packs_helper() {
		/*
		 * get_packs() reads wpcode()->library->get_data() internally, so the packs helper is useless
		 * without the library loaded first - it fatals on null rather than returning no packs.
		 */
		if ( null === self::library() ) {
			return null;
		}

		if ( ! class_exists( 'WPCode_Packs' ) ) {
			if ( ! defined( 'WPCODE_PLUGIN_PATH' ) ) {
				return null;
			}

			$file = WPCODE_PLUGIN_PATH . 'includes/class-wpcode-packs.php';

			if ( ! is_readable( $file ) ) {
				return null;
			}

			require_once $file;
		}

		if ( ! class_exists( 'WPCode_Packs' ) || ! method_exists( 'WPCode_Packs', 'get_instance' ) ) {
			return null;
		}

		return \WPCode_Packs::get_instance();
	}

	/**
	 * Whether the library is reachable and has data.
	 *
	 * @since  0.0.34
	 * @return true|WP_Error
	 */
	public static function assert_reachable() {
		$library = self::library();

		if ( null === $library ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode\'s snippet library could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		$data = $library->get_data();

		if ( empty( $data ) || empty( $data['snippets'] ) ) {
			$connection = self::connection();

			if ( empty( $connection['connected'] ) ) {
				return self::not_connected_error( __( 'reading the snippet library', 'acrossai-abilities-manager' ) );
			}

			return new WP_Error(
				'library_unavailable',
				__( 'WPCode returned no library data even though this site is signed in. The site may be offline, or the library request may have failed and been cached as empty for a few minutes.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Search the library.
	 *
	 * @since  0.0.34
	 * @param  string $search   Free-text search, empty for everything.
	 * @param  int    $per_page Maximum rows to return.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search( string $search, int $per_page ) {
		$reachable = self::assert_reachable();

		if ( is_wp_error( $reachable ) ) {
			return $reachable;
		}

		$data     = self::library()->get_data();
		$needle   = strtolower( trim( $search ) );
		$results  = array();

		foreach ( (array) $data['snippets'] as $snippet ) {
			if ( ! is_array( $snippet ) ) {
				continue;
			}

			$row = self::shape( $snippet );

			if ( '' !== $needle ) {
				$haystack = strtolower( $row['title'] . ' ' . $row['note'] . ' ' . implode( ' ', $row['categories'] ) );

				if ( false === strpos( $haystack, $needle ) ) {
					continue;
				}
			}

			$results[] = $row;

			if ( count( $results ) >= $per_page ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * One library snippet by its library id.
	 *
	 * @since  0.0.34
	 * @param  int $library_id Library id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function find( int $library_id ) {
		$reachable = self::assert_reachable();

		if ( is_wp_error( $reachable ) ) {
			return $reachable;
		}

		$data = self::library()->get_data();

		foreach ( (array) $data['snippets'] as $snippet ) {
			if ( is_array( $snippet ) && (int) ( $snippet['library_id'] ?? 0 ) === $library_id ) {
				return self::shape( $snippet );
			}
		}

		return new WP_Error(
			'unknown_library_snippet',
			sprintf(
				/* translators: %d: library snippet id. */
				__( 'No snippet with library id %d. Use search-library to find one.', 'acrossai-abilities-manager' ),
				$library_id
			)
		);
	}

	/**
	 * Install a library snippet, inactive.
	 *
	 * @since  0.0.34
	 * @param  int $library_id Library id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function install( int $library_id ) {
		$reachable = self::assert_reachable();

		if ( is_wp_error( $reachable ) ) {
			return $reachable;
		}

		$snippet = self::library()->create_new_snippet( $library_id );

		if ( ! $snippet instanceof WPCode_Snippet ) {
			/*
			 * Measured: fetching a snippet body needs the connection even though searching does not.
			 * Search reads a cached public catalogue; the install fetches through the API, which
			 * refuses an unauthenticated request. Reporting "it may not exist" to a disconnected
			 * site sends the caller hunting for a wrong library id instead of connecting.
			 */
			$connection = self::connection();

			if ( empty( $connection['connected'] ) ) {
				return self::not_connected_error( __( 'installing a snippet from the library', 'acrossai-abilities-manager' ) );
			}

			return new WP_Error(
				'install_failed',
				sprintf(
					/* translators: %d: library snippet id. */
					__( 'WPCode could not install library snippet %d. It may not exist, or the library request failed.', 'acrossai-abilities-manager' ),
					$library_id
				)
			);
		}

		return self::force_inactive( $snippet );
	}

	/**
	 * Apply a snippet pack, leaving everything it created inactive.
	 *
	 * @since  0.0.34
	 * @param  string $slug Pack slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_pack( string $slug ) {
		$packs = self::packs_helper();

		if ( null === $packs ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode snippet packs could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		if ( empty( $packs->find_pack( $slug ) ) ) {
			return new WP_Error(
				'unknown_pack',
				sprintf(
					/* translators: %s: pack slug. */
					__( 'No WPCode snippet pack with slug "%s". Use list-packs to see what is available.', 'acrossai-abilities-manager' ),
					$slug
				)
			);
		}

		$result = (array) $packs->install_pack( $slug );

		/*
		 * install_pack() returns its own envelope and reports success => false for every refusal,
		 * including "the current user cannot edit snippets" and "the library is not connected",
		 * without distinguishing them. Created ids are the only unambiguous signal that work happened.
		 */
		$created = array_map( 'intval', (array) ( $result['created_ids'] ?? array() ) );

		if ( empty( $created ) && empty( $result['success'] ) ) {
			$connection = self::connection();

			if ( empty( $connection['connected'] ) ) {
				return self::not_connected_error( __( 'installing a snippet pack', 'acrossai-abilities-manager' ) );
			}

			return new WP_Error(
				'pack_install_failed',
				__( 'WPCode installed nothing from this pack, even though this site is signed in. Every snippet in it may already be installed, or the library request failed.', 'acrossai-abilities-manager' )
			);
		}

		$installed = array();

		foreach ( $created as $id ) {
			$snippet = Snippet_Repository::find( $id );

			if ( is_wp_error( $snippet ) ) {
				continue;
			}

			$shaped = self::force_inactive( $snippet );

			if ( ! is_wp_error( $shaped ) ) {
				$installed[] = $shaped;
			}
		}

		return array(
			'pack'             => $slug,
			'installed'        => $installed,
			'installed_count'  => count( $installed ),
			'skipped_count'    => count( (array) ( $result['skipped_options'] ?? array() ) ),
			'failed_count'     => count( (array) ( $result['failed_options'] ?? array() ) ),
		);
	}

	/**
	 * Every pack, with whether it is installed.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function packs() {
		$packs = self::packs_helper();

		if ( null === $packs ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode snippet packs could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		$installed = (array) $packs->get_installed_state();
		$rows      = array();

		foreach ( (array) $packs->get_packs() as $slug => $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}

			$key = is_string( $slug ) ? $slug : (string) ( $pack['slug'] ?? '' );

			$rows[] = array(
				// 'name', not 'title' - that is the key get_packs() builds and the key WPCode's own
				// pack view reads. Asking for 'title' returns an empty string for every pack.
				'title'         => (string) ( $pack['name'] ?? '' ),
				'slug'          => $key,
				'description'   => (string) ( $pack['description'] ?? '' ),
				'group'         => (string) ( $pack['group'] ?? '' ),
				'snippet_count' => count( (array) ( $pack['snippets'] ?? array() ) ),
				'installed'     => ! empty( $pack['installed'] ) || ! empty( $installed[ $key ] ),
			);
		}

		return $rows;
	}

	/**
	 * Whether the site is signed in to the WPCode library, and as whom.
	 *
	 * Internal, and there is deliberately no ability wrapping it. The connection state is only ever
	 * interesting when something else has just failed for want of it, so a dedicated ability would
	 * be a tool call spent learning a fact that the failing call can simply state. It is reported
	 * inside the errors that need it instead.
	 *
	 * Never returns the key. `wpcode_library_api_auth` holds an auth key, a webhook secret and a
	 * client id; handing those to a caller means handing them off-site, which is the hazard Feature
	 * 106 found in Yoast's stored OAuth tokens. Only the state and the public username leave.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function connection(): array {
		$auth = self::load_component( 'library_auth', 'WPCode_Library_Auth', 'includes/class-wpcode-library-auth.php' );

		if ( null === $auth ) {
			return array(
				'connected'   => false,
				'username'    => '',
				'connect_url' => self::connect_url(),
			);
		}

		$connected = (bool) $auth->has_auth();

		return array(
			'connected'   => $connected,
			'username'    => $connected ? (string) $auth->get_auth_username() : '',
			'connect_url' => $connected ? '' : self::connect_url(),
		);
	}

	/**
	 * Where a human goes to connect the library.
	 *
	 * The real admin URL rather than a description of the menu path: an assistant relaying
	 * "WPCode > Library" makes the person hunt for it, and a link they can click is the difference
	 * between the step happening and not.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public static function connect_url(): string {
		return admin_url( 'admin.php?page=wpcode-library' );
	}

	/**
	 * The human-readable message for a library READ, which says when the account is missing.
	 *
	 * A read succeeds while disconnected, so it cannot use not_connected_error() — but returning a
	 * bare "Done." hides the one fact that decides what the caller can do next. The state is on the
	 * response as `library_connected` either way; this puts it somewhere an assistant will actually
	 * read, because a field it did not think to look at is a field it will not mention.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $connection Result of self::connection().
	 * @param  string               $done       Message to use when connected.
	 * @return string
	 */
	public static function reader_message( array $connection, string $done ): string {
		if ( ! empty( $connection['connected'] ) ) {
			return $done;
		}

		return sprintf(
			/* translators: 1: the normal success message, 2: admin URL of the WPCode Library page. */
			__( '%1$s Note: this site is not signed in to the WPCode library, so you can search and browse but NOT install - fetching a snippet\'s code needs the account. To install anything from here, ask the user to open %2$s, use the Connect button to sign in, and tell you once it is done.', 'acrossai-abilities-manager' ),
			$done,
			self::connect_url()
		);
	}

	/**
	 * The error to return when a call genuinely needs the library connection.
	 *
	 * Connecting involves signing in to an external account, so no ability can do it and none should
	 * try. What the ability CAN do is hand back an instruction precise enough to act on: the exact
	 * URL, what to click, and an explicit request to be told when it is done — otherwise an
	 * assistant tends to either give up or silently retry the same failing call.
	 *
	 * @since  0.0.34
	 * @param  string $what What the caller was trying to do.
	 * @return WP_Error
	 */
	public static function not_connected_error( string $what ): WP_Error {
		return new WP_Error(
			'library_not_connected',
			sprintf(
				/* translators: 1: what the caller attempted, 2: admin URL of the WPCode Library page. */
				__( 'This site is not signed in to the WPCode library, which %1$s needs. Signing in means authorising a WPCode account, so it cannot be done through an ability - a person has to do it. Ask them to open %2$s, use the Connect button there to sign in, and tell you once it is done; then run this call again. Connecting also unlocks snippets shared with them by link and any snippets saved to their own account.', 'acrossai-abilities-manager' ),
				$what,
				self::connect_url()
			),
			array( 'connect_url' => self::connect_url() )
		);
	}

	/**
	 * Installed library snippets that have a newer version upstream.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function updates() {
		$library = self::library();

		if ( null === $library ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode\'s snippet library could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		$used = (array) $library->get_used_library_snippets();
		$rows = array();

		foreach ( $used as $library_id => $snippet_id ) {
			if ( ! $library->check_snippet_update( (int) $snippet_id, (int) $library_id ) ) {
				continue;
			}

			$snippet = Snippet_Repository::find( (int) $snippet_id );

			if ( is_wp_error( $snippet ) ) {
				continue;
			}

			$rows[] = array(
				'id'              => (int) $snippet_id,
				'library_id'      => (int) $library_id,
				'title'           => (string) $snippet->get_title(),
				'code_type'       => (string) $snippet->get_code_type(),
				'active'          => (bool) $snippet->is_active(),
				'current_version' => (string) get_post_meta( (int) $snippet_id, '_wpcode_snippet_version', true ),
			);
		}

		return $rows;
	}

	/**
	 * Pull the newer library version of an installed snippet.
	 *
	 * Two guards, both because WPCode's own updater is written for its admin screen:
	 *
	 * 1. It saves the library payload wholesale, so `active` comes from the LIBRARY, not from this
	 *    site. A snippet deliberately switched off here could be switched back on as a side effect
	 *    of an update. The local state is captured first and restored after.
	 * 2. It replaces the code outright, so any local edit is lost. That is the documented behaviour
	 *    of an update and is fine — but it must be said out loud, which is why the ability is
	 *    confirm-gated.
	 *
	 * @since  0.0.34
	 * @param  int $snippet_id Local snippet id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function pull_update( int $snippet_id ) {
		$library = self::library();

		if ( null === $library ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode\'s snippet library could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		$snippet = Snippet_Repository::find( $snippet_id );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$library_id = (int) $library->get_snippet_library_id( $snippet_id );

		if ( $library_id <= 0 ) {
			return new WP_Error(
				'not_a_library_snippet',
				sprintf(
					/* translators: %d: snippet id. */
					__( 'Snippet %d did not come from the WPCode library, so there is nothing to update it from.', 'acrossai-abilities-manager' ),
					$snippet_id
				)
			);
		}

		$was_active = (bool) $snippet->is_active();

		if ( ! $library->update_snippet_from_library( $snippet_id, $library_id ) ) {
			return new WP_Error(
				'update_failed',
				sprintf(
					/* translators: %d: snippet id. */
					__( 'WPCode could not fetch a newer version for snippet %d. The library request may have failed.', 'acrossai-abilities-manager' ),
					$snippet_id
				)
			);
		}

		$updated = Snippet_Repository::find( $snippet_id );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Restore the local active state if the library payload changed it.
		if ( (bool) $updated->is_active() !== $was_active ) {
			$updated->active = $was_active;

			$restored = Snippet_Repository::persist( $updated, $was_active );

			if ( is_wp_error( $restored ) ) {
				return $restored;
			}

			return $restored;
		}

		Snippet_Repository::rebuild_cache();

		return Snippet_Repository::shape( $updated );
	}

	/**
	 * Install a snippet shared by link.
	 *
	 * @since  0.0.34
	 * @param  string $hash Share hash from the library URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function install_shared( string $hash ) {
		$library = self::library();

		if ( null === $library ) {
			return new WP_Error(
				'library_unavailable',
				__( 'WPCode\'s snippet library could not be loaded on this site.', 'acrossai-abilities-manager' )
			);
		}

		$auth      = self::load_component( 'library_auth', 'WPCode_Library_Auth', 'includes/class-wpcode-library-auth.php' );
		$auth_hash = ( null !== $auth && $auth->has_auth() ) ? (string) $auth->get_auth_key() : '';

		if ( '' === $auth_hash ) {
			return self::not_connected_error( __( 'installing a snippet shared by link', 'acrossai-abilities-manager' ) );
		}

		$data = $library->get_public_snippet( $hash, $auth_hash );

		if ( empty( $data ) || ! is_array( $data ) || ( isset( $data['status'] ) && 'error' === $data['status'] ) ) {
			return new WP_Error(
				'unknown_shared_snippet',
				sprintf(
					/* translators: %s: share hash. */
					__( 'The WPCode library returned no snippet for share code "%s". It may have expired or never existed.', 'acrossai-abilities-manager' ),
					$hash
				)
			);
		}

		$payload = isset( $data['snippet'] ) && is_array( $data['snippet'] ) ? $data['snippet'] : $data;
		$created = $library->create_snippet_from_data( $payload );

		if ( ! $created instanceof WPCode_Snippet ) {
			return new WP_Error(
				'install_failed',
				__( 'WPCode returned the shared snippet but could not save it.', 'acrossai-abilities-manager' )
			);
		}

		return self::force_inactive( $created );
	}

	/**
	 * Guarantee a freshly installed snippet is not running.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet Snippet.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function force_inactive( WPCode_Snippet $snippet ) {
		if ( $snippet->is_active() ) {
			$snippet->deactivate();
			Snippet_Repository::rebuild_cache();
		}

		$saved = Snippet_Repository::find( (int) $snippet->get_id() );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return Snippet_Repository::shape( $saved );
	}

	/**
	 * Shape one library row. Rows, never maps.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $snippet Library payload.
	 * @return array<string, mixed>
	 */
	private static function shape( array $snippet ): array {
		$categories = array();

		foreach ( (array) ( $snippet['categories'] ?? array() ) as $category ) {
			if ( is_scalar( $category ) ) {
				$categories[] = (string) $category;
			} elseif ( is_array( $category ) && isset( $category['slug'] ) ) {
				$categories[] = (string) $category['slug'];
			}
		}

		return array(
			'library_id' => (int) ( $snippet['library_id'] ?? 0 ),
			'title'      => (string) ( $snippet['title'] ?? '' ),
			'note'       => (string) ( $snippet['note'] ?? '' ),
			'code_type'  => (string) ( $snippet['code_type'] ?? '' ),
			'categories' => array_values( $categories ),
		);
	}
}
