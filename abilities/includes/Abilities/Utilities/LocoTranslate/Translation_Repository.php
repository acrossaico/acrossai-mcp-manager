<?php
/**
 * Feature 116 — the only place this plugin reads or writes a translation file.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything goes through Loco's own gettext layer. Nothing here writes a file itself.
 *
 * A `.po` is not what WordPress reads. One save produces up to four artefacts
 * (Loco_gettext_Compiler): the `.po` a human edits, the `.mo` that `load_textdomain()` historically
 * loads, the `.l10n.php` cache WordPress 6.5+ reads IN PREFERENCE to the `.mo`, and one `.json` JED
 * fragment per JavaScript reference for `wp.i18n`. Edit the `.po` alone — which the file-manager
 * abilities can do — and all of them fall out of step: the file says one thing and the site renders
 * another, with no error anywhere.
 *
 * @since 0.0.34
 */
final class Translation_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Parse a PO/POT file into Loco's data object.
	 *
	 * @since  0.0.34
	 * @param  string $path Absolute path.
	 * @return object|WP_Error Loco_gettext_Data on success.
	 */
	public static function load( string $path ) {
		$file = new \Loco_fs_File( $path );

		if ( ! $file->exists() ) {
			return new WP_Error(
				'unknown_translation_file',
				sprintf(
					/* translators: %s: file path. */
					__( 'No translation file at %s. Call translations/get-bundle to see which files a bundle actually has.', 'acrossai-abilities-manager' ),
					$path
				)
			);
		}

		try {
			return \Loco_gettext_Data::load( $file );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'unreadable_translation_file',
				sprintf(
					/* translators: 1: file path, 2: parser message. */
					__( 'Loco could not parse %1$s: %2$s', 'acrossai-abilities-manager' ),
					$path,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Write a PO and every artefact that depends on it, then prove each one landed.
	 *
	 * The proof is the point, and it is not paranoia. `Loco_gettext_Compiler::writeMo()` CATCHES its
	 * own exceptions: on failure it raises an admin notice, sets the byte count to 0 and returns
	 * normally (Compiler.php:85-105). `writePhp()` then runs only `if ( 0 !== $bytes )`, so a failed
	 * MO silently takes the `.l10n.php` with it. `writeAll()` can therefore return a perfectly happy
	 * FileList having written nothing but the `.po`, leaving the site rendering the old strings.
	 *
	 * So the byte counts are read back from DISK rather than from Loco's own progress object. Loco's
	 * counter is what a caller would have trusted; the file sizes are what actually happened.
	 *
	 * `writeAll()` also only writes the JSON fragments when a project is passed — omit it and the
	 * block editor keeps the old strings while everything else updates.
	 *
	 * @since  0.0.34
	 * @param  string      $po_path Absolute path to the PO file.
	 * @param  object      $po      Loco_gettext_Data to write.
	 * @param  object|null $project Loco_package_Project, required for the JSON fragments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function write( string $po_path, $po, $project = null ) {
		$file     = new \Loco_fs_File( $po_path );
		$compiler = new \Loco_gettext_Compiler( $file );

		try {
			$compiler->writeAll( $po, $project );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'write_failed',
				sprintf(
					/* translators: 1: file path, 2: error message. */
					__( 'Loco could not write %1$s: %2$s', 'acrossai-abilities-manager' ),
					$po_path,
					$e->getMessage()
				)
			);
		}

		$domain  = ( $project && method_exists( $project, 'getDomain' ) )
			? (string) $project->getDomain()
			: '';
		$written = self::artefacts( $po_path, $domain );

		if ( 0 === $written['po_bytes'] ) {
			return new WP_Error(
				'write_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'Loco reported no error but %s is empty or absent on disk.', 'acrossai-abilities-manager' ),
					$po_path
				)
			);
		}

		if ( 0 === $written['mo_bytes'] ) {
			return new WP_Error(
				'compile_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'The PO file %s saved, but Loco could not compile the MO alongside it — so WordPress will keep serving the previous translations. Loco swallows this failure internally; the file sizes on disk are what reveal it.', 'acrossai-abilities-manager' ),
					$po_path
				)
			);
		}

		if ( Loco_Guard::uses_php_cache() && 0 === $written['php_bytes'] ) {
			return new WP_Error(
				'compile_failed',
				__( 'The PO and MO saved, but the .l10n.php cache did not. This WordPress reads that cache in preference to the MO, so the old strings would keep rendering.', 'acrossai-abilities-manager' )
			);
		}

		return $written;
	}

	/**
	 * Shape the messages in a parsed PO/POT.
	 *
	 * Addressed by source text plus context, not by index. An index is meaningless across a `sync`,
	 * which reorders and renumbers everything — a caller that stored "entry 42" would silently patch
	 * the wrong string afterwards. Source and context are what gettext itself keys on.
	 *
	 * @since  0.0.34
	 * @param  object $po     Loco_gettext_Data.
	 * @param  string $filter '', 'untranslated' or 'fuzzy'.
	 * @param  int    $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages( $po, string $filter = '', int $limit = 200 ): array {
		$rows = array();

		foreach ( $po as $entry ) {
			$source = (string) $entry['source'];

			// The header is a real entry with an empty source. It is metadata, not a translatable
			// string, and returning it would invite a caller to "translate" the headers.
			if ( '' === $source ) {
				continue;
			}

			$target = (string) $entry['target'];
			$fuzzy  = false !== stripos( (string) ( $entry['flag'] ?? '' ), 'fuzzy' );

			if ( 'untranslated' === $filter && '' !== $target ) {
				continue;
			}

			if ( 'fuzzy' === $filter && ! $fuzzy ) {
				continue;
			}

			$rows[] = array(
				'source'  => $source,
				'target'  => $target,
				'context' => (string) ( $entry['context'] ?? '' ),
				'fuzzy'   => $fuzzy,
				'comment' => (string) ( $entry['comment'] ?? '' ),
				'refs'    => (string) ( $entry['refs'] ?? '' ),
			);

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Apply translations to a parsed PO, matching on source and context.
	 *
	 * @since  0.0.34
	 * @param  object                        $po      Loco_gettext_Data.
	 * @param  array<int, array<string,mixed>> $changes Rows of source, target and optional context.
	 * @return array<string, mixed> The rebuilt PO under 'po', plus applied and unmatched buckets.
	 */
	public static function apply( $po, array $changes ): array {
		/*
		 * Operate on the RAW array, not on the iterator.
		 *
		 * `LocoPoIterator::current()` builds a fresh LocoPoMessage from the underlying array on every
		 * call (`item()` in lib/compiled/gettext.php), so `foreach ( $po as $entry )` hands out
		 * throwaway copies and `$entry['target'] = ...` is discarded. Measured: an earlier version of
		 * this method reported applied_count 1 and wrote a header-only MO — a false success, which is
		 * the exact failure mode this whole suite exists to catch. The raw array is the only thing
		 * that survives into the written file.
		 */
		$raw = $po->getArrayCopy();

		$wanted = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['source'] ) ) {
				continue;
			}

			$key            = (string) $change['source'] . "\x04" . (string) ( $change['context'] ?? '' );
			$wanted[ $key ] = $change;
		}

		$applied = 0;

		foreach ( $raw as $i => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['source'] ) ) {
				continue;
			}

			// Plural children carry a 'parent' index and are addressed through their parent, never
			// on their own.
			if ( array_key_exists( 'parent', $entry ) ) {
				continue;
			}

			$key = (string) $entry['source'] . "\x04" . (string) ( $entry['context'] ?? '' );

			if ( ! isset( $wanted[ $key ] ) ) {
				continue;
			}

			$raw[ $i ]['target'] = (string) ( $wanted[ $key ]['target'] ?? '' );

			/*
			 * Clear the fuzzy flag on anything deliberately translated. Fuzzy means "machine-matched,
			 * a human should check"; leaving it set keeps the string flagged for review forever, and
			 * some loaders skip fuzzy entries outright.
			 */
			if ( isset( $raw[ $i ]['flag'] ) && false !== stripos( (string) $raw[ $i ]['flag'], 'fuzzy' ) ) {
				$raw[ $i ]['flag'] = trim( str_ireplace( 'fuzzy', '', (string) $raw[ $i ]['flag'] ), " ,\t" );
			}

			++$applied;
			unset( $wanted[ $key ] );
		}

		$unmatched = array();

		foreach ( $wanted as $change ) {
			$unmatched[] = array(
				'source'  => (string) $change['source'],
				'context' => (string) ( $change['context'] ?? '' ),
			);
		}

		return array(
			'po'              => new \Loco_gettext_Data( $raw ),
			'applied_count'   => $applied,
			'unmatched'       => $unmatched,
			'unmatched_count' => count( $unmatched ),
		);
	}

	/**
	 * Build a template from a bundle's source code (xgettext).
	 *
	 * @since  0.0.34
	 * @param  object $bundle  Loco_package_Bundle.
	 * @param  object $project Loco_package_Project.
	 * @return array<string, mixed>|WP_Error 'po' plus the skipped-file report.
	 */
	public static function extract( $bundle, $project ) {
		try {
			$extraction = new \Loco_gettext_Extraction( $bundle );
			$extraction->addProject( $project );

			$skipped = (array) $extraction->getSkipped();
			$po      = $extraction->includeMeta()->getTemplate( (string) $project->getDomain() );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'extract_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Loco could not scan the source for translatable strings: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		/*
		 * Skipped files are reported, never swallowed. Loco refuses PHP files above its
		 * `max_php_size` setting, so a template can come back looking complete while missing every
		 * string in the largest file in the plugin — which is exactly where a big translatable block
		 * tends to live.
		 */
		return array(
			'po'            => $po,
			'string_count'  => count( self::messages( $po, '', PHP_INT_MAX ) ),
			'skipped_files' => count( $skipped ),
			'max_php_size'  => (int) $extraction->getMaxPhpSize(),
		);
	}

	/**
	 * Reconcile a translation against a newer template or the source.
	 *
	 * @since  0.0.34
	 * @param  object $target  Loco_gettext_Data being updated.
	 * @param  object $source  Loco_gettext_Data acting as the template.
	 * @param  object $project Loco_package_Project.
	 * @return array<string, mixed>|WP_Error 'po' plus the merge report.
	 */
	public static function sync( $target, $source, $project ) {
		try {
			$matcher = new \Loco_gettext_Matcher( $project );
			$matcher->loadRefs( $source, false );

			// Fuzziness 0: exact matches only. Loco's admin screens offer fuzzy matching so a human
			// can eyeball the guesses; an ability has nobody to eyeball them, and a wrong fuzzy match
			// silently attaches the wrong translation to a string.
			$matcher->setFuzziness( '0' );

			$merged = clone $target;
			$merged->clear();

			$report = $matcher->merge( $target, $merged );
			$merged->sort();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'sync_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Loco could not reconcile the translation against its template: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		return array(
			'po'     => $merged,
			'report' => is_array( $report ) ? $report : array(),
		);
	}

	/**
	 * Write a POT template.
	 *
	 * Separate from write() because a template has no locale, so nothing compiles from it — no MO, no
	 * `.l10n.php`, no JSON. Running it through the four-artefact path would produce a `.mo` beside a
	 * `.pot`, which WordPress would never load and a reader would rightly find baffling.
	 *
	 * @since  0.0.34
	 * @param  string $path Absolute path to the POT.
	 * @param  object $po   Loco_gettext_Data.
	 * @return int|WP_Error Bytes written.
	 */
	public static function write_template( string $path, $po ) {
		$file     = new \Loco_fs_File( $path );
		$compiler = new \Loco_gettext_Compiler( $file );

		try {
			$compiler->writePo( $po );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'write_failed',
				sprintf(
					/* translators: 1: file path, 2: error message. */
					__( 'Loco could not write %1$s: %2$s', 'acrossai-abilities-manager' ),
					$path,
					$e->getMessage()
				)
			);
		}

		$bytes = self::size_of( $path );

		if ( 0 === $bytes ) {
			return new WP_Error(
				'write_failed',
				sprintf(
					/* translators: %s: file path. */
					__( 'Loco reported no error but %s is empty or absent on disk.', 'acrossai-abilities-manager' ),
					$path
				)
			);
		}

		return $bytes;
	}

	/**
	 * Delete a PO and every artefact compiled from it.
	 *
	 * `Loco_fs_Siblings::expand()` returns exactly the set that belongs to this PO — the MO, the
	 * `.l10n.php` cache, the JSON fragments and any backups — which is the point of deleting through
	 * Loco rather than unlinking the file. Removing the PO alone leaves the compiled artefacts on
	 * disk, and WordPress goes on serving the translations the caller believed they had deleted.
	 *
	 * @since  0.0.34
	 * @param  string $po_path Absolute path to the PO file.
	 * @return array<int, string>|WP_Error Paths removed.
	 */
	public static function delete( string $po_path ) {
		$file = new \Loco_fs_File( $po_path );

		if ( ! $file->exists() ) {
			return new WP_Error(
				'unknown_translation_file',
				sprintf(
					/* translators: %s: file path. */
					__( 'No translation file at %s.', 'acrossai-abilities-manager' ),
					$po_path
				)
			);
		}

		$siblings = new \Loco_fs_Siblings( $file );
		$targets  = array();

		foreach ( $siblings->expand() as $sibling ) {
			$targets[] = (string) $sibling->getPath();
		}

		// expand() reports what exists NOW; the PO itself is included, but belt and braces since the
		// whole point is that nothing compiled survives the delete.
		if ( ! in_array( $po_path, $targets, true ) ) {
			$targets[] = $po_path;
		}

		$removed = array();

		foreach ( $targets as $path ) {
			$target = new \Loco_fs_File( $path );

			try {
				$target->unlink();
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'delete_failed',
					sprintf(
						/* translators: 1: file path, 2: error message. */
						__( 'Could not delete %1$s: %2$s', 'acrossai-abilities-manager' ),
						$path,
						$e->getMessage()
					)
				);
			}

			clearstatcache( true, $path );

			if ( file_exists( $path ) ) {
				return new WP_Error(
					'delete_failed',
					sprintf(
						/* translators: %s: file path. */
						__( '%s still exists after the delete reported success.', 'acrossai-abilities-manager' ),
						$path
					)
				);
			}

			$removed[] = $path;
		}

		return $removed;
	}

	/**
	 * Size every artefact that belongs to one PO file, read from disk.
	 *
	 * @since  0.0.34
	 * `getJsons()` takes the text domain as a REQUIRED argument — it prefixes the filename with it
	 * when the PO is named by locale alone. Calling it without one is a fatal, and passing the wrong
	 * one silently finds no fragments, which would read as "no JS translations" rather than "asked
	 * the wrong question".
	 *
	 * @since  0.0.34
	 * @param  string $po_path Absolute path to the PO file.
	 * @param  string $domain  Text domain, for resolving the JSON fragment names.
	 * @return array<string, mixed>
	 */
	public static function artefacts( string $po_path, string $domain = '' ): array {
		$siblings = new \Loco_fs_Siblings( new \Loco_fs_File( $po_path ) );

		$mo  = $siblings->getBinary();
		$php = $siblings->getCache();

		$json_bytes = 0;
		$json_count = 0;

		foreach ( (array) $siblings->getJsons( $domain ) as $json ) {
			$size = self::size_of( is_object( $json ) && method_exists( $json, 'getPath' ) ? (string) $json->getPath() : (string) $json );

			if ( $size > 0 ) {
				$json_bytes += $size;
				++$json_count;
			}
		}

		return array(
			'po_path'        => $po_path,
			'po_bytes'       => self::size_of( $po_path ),
			'mo_bytes'       => $mo ? self::size_of( (string) $mo->getPath() ) : 0,
			'php_bytes'      => $php ? self::size_of( (string) $php->getPath() ) : 0,
			'json_files'     => $json_count,
			'json_bytes'     => $json_bytes,
			'uses_php_cache' => Loco_Guard::uses_php_cache(),
		);
	}

	/**
	 * Bytes on disk, or 0 when the file is absent.
	 *
	 * clearstatcache() first: these files were written moments ago in the same request, and a stale
	 * stat cache would report the size the file had BEFORE the write — which is exactly the reading
	 * this method exists to make trustworthy.
	 *
	 * @since  0.0.34
	 * @param  string $path Absolute path.
	 * @return int
	 */
	private static function size_of( string $path ): int {
		if ( '' === $path ) {
			return 0;
		}

		clearstatcache( true, $path );

		return is_readable( $path ) ? (int) filesize( $path ) : 0;
	}
}
