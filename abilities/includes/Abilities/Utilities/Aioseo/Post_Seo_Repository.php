<?php
/**
 * Feature 132 — bulk post SEO writes through AIOSEO's own service.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use AIOSEO\Plugin\Common\Services\PostSeoService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one set of SEO fields across many posts.
 *
 * **Nothing here reimplements a write.** Each post goes through
 * `PostSeoService::updateSeoData()` — the same public service AIOSEO's own
 * `aioseo-posts/seo-data-update` calls. That matters for two reasons beyond not duplicating code:
 *
 * 1. It keeps AIOSEO's per-post `current_user_can( 'edit_post', $id )` check in the loop. A bulk
 *    editor that mapped fields itself and called `Models\Post::savePost()` directly would drop that
 *    check and hand any caller past the floor a write to every post on the site.
 * 2. `savePost()` is patch-aware, and the service is what decides which keys that means. A field the
 *    caller did not name keeps its value; a title equal to the post type's template is cleared so the
 *    post goes back to inheriting rather than storing a duplicate.
 *
 * Per-post outcomes are reported individually and a failure never aborts the run: a bulk edit that
 * stopped on the third of twenty posts, having written two, would leave the caller with no way to
 * know where it got to.
 *
 * @since 0.0.44
 */
final class Post_Seo_Repository {

	/**
	 * Field names AIOSEO's service accepts.
	 *
	 * Read off `PostSeoService::applyBasicFields()`, `applyKeyphraseFields()`, `applyRobotsFields()`
	 * and `applySocialFields()`. Validated here rather than passed through, because the service
	 * silently ignores a key it does not recognise — so a typo would report success on every post and
	 * change nothing.
	 *
	 * @since 0.0.44
	 * @var   string[]
	 */
	private const FIELDS = array(
		'title',
		'description',
		'canonical_url',
		'pillar_content',
		'focus_keyphrase',
		'additional_keyphrases',
		'robots',
		'social',
	);

	/**
	 * The only `robots` sub-keys AIOSEO's service maps.
	 *
	 * Read off `PostSeoService::applyRobotsFields()`, whose map is exactly these eight. Anything else
	 * — `maxSnippet`, `maxImagePreview`, `maxVideoPreview`, or a typo — is dropped by the service
	 * without comment, so the write reports success and the directive never exists. Validated here
	 * for the same reason the top-level names are.
	 *
	 * @since 0.0.44
	 * @var   string[]
	 */
	private const ROBOTS_KEYS = array(
		'use_default',
		'noindex',
		'nofollow',
		'noarchive',
		'nosnippet',
		'noimageindex',
		'notranslate',
		'noodp',
	);

	/**
	 * The shape each accepted field takes.
	 *
	 * AIOSEO's service assigns most of these straight into the save array and lets the model cast
	 * later, so an array handed to `title` is stored as an array and reported as a success. An
	 * adversarial pass did exactly that. 'scalar' means a string, number or boolean, or null to
	 * clear; 'list' means an array.
	 *
	 * @since 0.0.44
	 * @var   array<string, string>
	 */
	private const SHAPES = array(
		'title'                 => 'scalar',
		'description'           => 'scalar',
		'canonical_url'         => 'scalar',
		'pillar_content'        => 'scalar',
		'focus_keyphrase'       => 'scalar',
		'additional_keyphrases' => 'list',
		'robots'                => 'list',
		'social'                => 'list',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * @since  0.0.44
	 * @return string[]
	 */
	public static function fields(): array {
		return self::FIELDS;
	}

	/**
	 * Apply fields to many posts.
	 *
	 * @since  0.0.44
	 * @param  array<int, int>      $post_ids Post ids, in the caller's order.
	 * @param  array<string, mixed> $fields   Field name => value.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function bulk_update( array $post_ids, array $fields ) {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( ! class_exists( PostSeoService::class ) ) {
			return new WP_Error(
				'aioseo_post_service_unavailable',
				__( 'This AIOSEO version does not expose the post SEO service this ability writes through, so nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		$unknown = array_values( array_diff( array_keys( $fields ), self::FIELDS ) );

		if ( array() !== $unknown ) {
			return new WP_Error(
				'unknown_field',
				sprintf(
					/* translators: 1: comma-separated unknown field names, 2: comma-separated accepted names */
					__( 'All in One SEO does not accept the field(s) %1$s, so nothing was written to any post. The accepted fields are: %2$s. Schema and EEAT are not writable through abilities — AIOSEO refuses those on its own update ability too.', 'acrossai-abilities-manager' ),
					implode( ', ', $unknown ),
					implode( ', ', self::FIELDS )
				)
			);
		}

		if ( array() === $fields ) {
			return new WP_Error(
				'no_fields',
				__( 'No fields were given, so there is nothing to write.', 'acrossai-abilities-manager' )
			);
		}

		$shapes = self::assert_shapes( $fields );
		if ( is_wp_error( $shapes ) ) {
			return $shapes;
		}

		$robots = self::reconcile_robots( $fields );
		if ( is_wp_error( $robots ) ) {
			return $robots;
		}

		$notes = $robots['notes'];
		$fields = $robots['fields'];

		$service   = new PostSeoService();
		$results   = array();
		$updated   = 0;
		$failed    = 0;

		foreach ( $post_ids as $given ) {
			$post_id = self::post_id( $given );

			if ( null === $post_id ) {
				++$failed;

				$results[] = array(
					'post_id' => null,
					'updated' => false,
					'reason'  => __( 'Not a post id. Casting it would have silently resolved to post 1 and written there.', 'acrossai-abilities-manager' ),
					'code'    => 'invalid_post_id',
				);

				continue;
			}

			$outcome = $service->updateSeoData( $post_id, $fields );

			if ( is_wp_error( $outcome ) ) {
				++$failed;

				$results[] = array(
					'post_id' => $post_id,
					'updated' => false,
					'reason'  => (string) $outcome->get_error_message(),
					'code'    => (string) $outcome->get_error_code(),
				);

				continue;
			}

			++$updated;

			$results[] = array(
				'post_id' => $post_id,
				'updated' => true,
				'reason'  => null,
				'code'    => null,
			);
		}

		return array(
			'results' => $results,
			'updated' => $updated,
			'failed'  => $failed,
			'fields'  => array_keys( $fields ),
			'notes'   => $notes,
			'message' => 0 === $failed
				? sprintf(
					/* translators: %d: number of posts */
					_n( 'SEO fields written to %d post.', 'SEO fields written to %d posts.', $updated, 'acrossai-abilities-manager' ),
					$updated
				)
				: sprintf(
					/* translators: 1: number written, 2: number skipped */
					__( 'SEO fields written to %1$d post(s); %2$d were skipped. Each skipped post reports its own reason — a missing post and one you cannot edit are different answers.', 'acrossai-abilities-manager' ),
					$updated,
					$failed
				),
		);
	}

	/**
	 * Validate the `robots` sub-keys and reconcile AIOSEO's per-post "use default" switch.
	 *
	 * **The trap this exists for.** A post's robots directives are only read when its
	 * `robots_default` flag is OFF — `Meta\Robots.php:211` returns the global post-type values and
	 * never looks at the per-post columns otherwise. AIOSEO's service maps `use_default` but never
	 * clears it, so `robots: { noindex: true }` stores `robots_noindex = true` beside
	 * `robots_default = true`, reports success, and the page goes on emitting the post type's
	 * defaults. Verified on AIOSEO 5.0.3: both columns true after such a write.
	 *
	 * AIOSEO's own `aioseo-posts/seo-data-update` has the same hole. Easy MCP AI's AIOSEO tool works
	 * around it by forcing the flag off silently; this does the same thing and SAYS SO in `notes`,
	 * because a write that quietly did more than it was asked is the harder thing to debug later.
	 *
	 * A caller who passes `use_default: true` alongside flags has asked for the defaults explicitly.
	 * That is honoured, with a note that the flags will not take effect — overriding an explicit
	 * instruction would be worse than reporting the contradiction.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $fields Caller's fields.
	 * @return array{fields: array<string, mixed>, notes: string[]}|WP_Error
	 */
	private static function reconcile_robots( array $fields ) {
		$notes = array();

		if ( ! isset( $fields['robots'] ) ) {
			return array(
				'fields' => $fields,
				'notes'  => $notes,
			);
		}

		if ( ! is_array( $fields['robots'] ) ) {
			return new WP_Error(
				'invalid_field_value',
				__( 'robots must be an object of directive => true/false, for example { "noindex": true }.', 'acrossai-abilities-manager' )
			);
		}

		$unknown = array_values( array_diff( array_keys( $fields['robots'] ), self::ROBOTS_KEYS ) );

		if ( array() !== $unknown ) {
			return new WP_Error(
				'unknown_field',
				sprintf(
					/* translators: 1: comma-separated unknown keys, 2: comma-separated accepted keys */
					__( 'All in One SEO does not store the robots directive(s) %1$s through this path, so nothing was written to any post — it would have reported success and changed nothing. The directives it accepts are: %2$s. The max-snippet and max-preview limits are not among them; set those on the post type with aioseo/update-object-settings.', 'acrossai-abilities-manager' ),
					implode( ', ', $unknown ),
					implode( ', ', self::ROBOTS_KEYS )
				)
			);
		}

		$flags = array_diff( array_keys( $fields['robots'] ), array( 'use_default' ) );

		if ( array() === $flags ) {
			return array(
				'fields' => $fields,
				'notes'  => $notes,
			);
		}

		if ( ! array_key_exists( 'use_default', $fields['robots'] ) ) {
			$fields['robots']['use_default'] = false;

			$notes[] = __( 'Turned the per-post "use default robots settings" switch off, because All in One SEO ignores every per-post robots directive while it is on — the directives you set would have been stored and had no effect. Pass use_default explicitly to control this yourself.', 'acrossai-abilities-manager' );

			return array(
				'fields' => $fields,
				'notes'  => $notes,
			);
		}

		if ( ! empty( $fields['robots']['use_default'] ) ) {
			$notes[] = __( 'You passed use_default: true along with robots directives. The directives were stored as asked, but All in One SEO will ignore them and serve the post type\'s defaults while that switch is on.', 'acrossai-abilities-manager' );
		}

		return array(
			'fields' => $fields,
			'notes'  => $notes,
		);
	}

	/**
	 * One caller value as a usable post id, or null.
	 *
	 * `(int) $value` is the obvious spelling and it is dangerous: PHP casts an array or an object to
	 * 1, so `post_ids: [[1]]` or a stray object wrote SEO fields to post 1 and reported success. Found
	 * by an adversarial pass. Only an integer or an integer-valued numeric string above zero counts;
	 * everything else is reported per post as `invalid_post_id` and touches nothing.
	 *
	 * @since  0.0.44
	 * @param  mixed $value Caller value.
	 * @return int|null
	 */
	private static function post_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * Whether each field's value has a shape AIOSEO will store meaningfully.
	 *
	 * Separate from `bulk_update()` so it can be tested without AIOSEO installed — the availability
	 * guard correctly runs first in the real path, which would otherwise make this unreachable in a
	 * host-free test.
	 *
	 * The reason it exists: AIOSEO's service assigns most of these straight into the save array and
	 * lets the model cast later, so `title: ["a"]` was stored as an array and reported as a success.
	 * Found by an adversarial pass. Rejects the WHOLE call rather than skipping the bad field — a
	 * partially-applied batch beside a success report is the worst outcome available.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $fields Caller's fields.
	 * @return true|WP_Error
	 */
	private static function assert_shapes( array $fields ) {
		foreach ( $fields as $name => $value ) {
			$shape = self::SHAPES[ (string) $name ] ?? 'scalar';

			if ( 'list' === $shape && ! is_array( $value ) ) {
				return new WP_Error(
					'invalid_field_value',
					sprintf(
						/* translators: %s: field name */
						__( 'The field "%s" takes an object or list, and a single value was given. Nothing was written to any post.', 'acrossai-abilities-manager' ),
						$name
					)
				);
			}

			if ( 'scalar' === $shape && null !== $value && ! is_scalar( $value ) ) {
				return new WP_Error(
					'invalid_field_value',
					sprintf(
						/* translators: %s: field name */
						__( 'The field "%s" takes a single value, or null to clear it, and a list was given. AIOSEO would store the list as-is and report success. Nothing was written to any post.', 'acrossai-abilities-manager' ),
						$name
					)
				);
			}
		}

		return true;
	}
}
