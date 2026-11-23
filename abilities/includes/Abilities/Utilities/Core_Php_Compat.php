<?php
/**
 * Whether a WordPress release can run on the PHP this site is using.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * The compatibility question a core downgrade has to answer, and that nothing else answers.
 *
 * WordPress.org publishes `php_version` on every core offer, and it is a **minimum**. Measured on
 * the live API: the 4.7.39 offer declares `php_version: 5.2.4`. So the check WordPress itself
 * performs — `version_compare( PHP_VERSION, $offer->php_version, '>=' )` in
 * `wp-admin/update-core.php` — passes trivially when rolling backwards, because any modern PHP
 * exceeds an ancient minimum. It guards upgrades, not downgrades.
 *
 * The dangerous direction has no published bound at all. The full 4.7.39 offer carries eleven
 * fields and not one of them says which PHP versions that release tolerates. So installing
 * WordPress 4.7 on PHP 8.4 passes every check the API makes possible, and then white-screens the
 * site including wp-admin, with no route back except the filesystem.
 *
 * This fills that gap with the one thing that is actually documented: the first WordPress release
 * that claimed any support for each PHP series. Below that line the answer is not "probably fine" —
 * the release predates the PHP version entirely and will fatal on syntax and removed functions it
 * never knew about. Above it, compatibility is plausible but unproven, and this says so rather than
 * implying a verdict it cannot reach.
 *
 * @since 0.0.45
 */
final class Core_Php_Compat {

	/**
	 * Earliest WordPress release claiming any support for a given PHP series.
	 *
	 * From WordPress's own PHP compatibility documentation, which records the release in which
	 * each PHP series was first supported — in several cases as "beta support", which is why this
	 * is treated as a floor below which the answer is certain, not a guarantee above it.
	 *
	 * Deliberately a floor and deliberately incomplete. A PHP series absent from this table yields
	 * `unknown` rather than a guess: a wrong refusal blocks legitimate recovery, and a wrong pass
	 * bricks a site, so the only safe behaviour for an unrecognised PHP is to say so and let the
	 * confirmation flag carry the decision.
	 *
	 * @since 0.0.45
	 * @var   array<string,string>
	 */
	private const FIRST_SUPPORTING_WP = array(
		'7.2' => '4.9',
		'7.3' => '5.0',
		'7.4' => '5.3',
		'8.0' => '5.6',
		'8.1' => '5.9',
		'8.2' => '6.1',
		'8.3' => '6.4',
		'8.4' => '6.7',
	);

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The `major.minor` series of a PHP version string.
	 *
	 * @since  0.0.45
	 * @param  string $php PHP version, e.g. `8.4.12`.
	 * @return string Series, e.g. `8.4`; '' when unparseable.
	 */
	public static function php_series( string $php ): string {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)/', trim( $php ), $m ) ) {
			return '';
		}

		return $m[1] . '.' . $m[2];
	}

	/**
	 * The earliest WordPress release that supports a PHP version.
	 *
	 * @since  0.0.45
	 * @param  string $php PHP version.
	 * @return string WordPress version, or '' when this PHP series is not in the table.
	 */
	public static function minimum_wp_for_php( string $php ): string {
		$series = self::php_series( $php );

		return self::FIRST_SUPPORTING_WP[ $series ] ?? '';
	}

	/**
	 * Assess installing a WordPress version on a PHP version.
	 *
	 * @since  0.0.45
	 * @param  string $target_wp WordPress version to be installed.
	 * @param  string $php       PHP version the site runs.
	 * @return array{verdict:string,minimum_wp:string,php_series:string,message:string}
	 *         verdict is `ok`, `unsupported`, or `unknown`.
	 */
	public static function assess( string $target_wp, string $php ): array {
		$series     = self::php_series( $php );
		$minimum    = self::minimum_wp_for_php( $php );
		$target_wp  = trim( $target_wp );

		if ( '' === $target_wp || '' === $series || '' === $minimum ) {
			return array(
				'verdict'    => 'unknown',
				'minimum_wp' => $minimum,
				'php_series' => $series,
				'message'    => sprintf(
					/* translators: 1: PHP version, 2: WordPress version */
					__( 'No record of which WordPress releases support the PHP %1$s series, so whether WordPress %2$s can run on it is not established. Verify before proceeding — a core version that predates the running PHP fatals on every request, wp-admin included.', 'acrossai-abilities-manager' ),
					'' !== $series ? $series : ( '' !== $php ? $php : __( 'unknown', 'acrossai-abilities-manager' ) ),
					'' !== $target_wp ? $target_wp : __( 'unknown', 'acrossai-abilities-manager' )
				),
			);
		}

		if ( version_compare( $target_wp, $minimum, '<' ) ) {
			return array(
				'verdict'    => 'unsupported',
				'minimum_wp' => $minimum,
				'php_series' => $series,
				'message'    => sprintf(
					/* translators: 1: target WordPress version, 2: running PHP version, 3: PHP series, 4: minimum WordPress version */
					__( 'WordPress %1$s cannot run on PHP %2$s. Support for the PHP %3$s series was first added in WordPress %4$s, so %1$s predates it and will fatal on every request, including wp-admin — leaving no way back except the filesystem. WordPress.org publishes no maximum PHP version per release, so nothing in the update API reports this.', 'acrossai-abilities-manager' ),
					$target_wp,
					$php,
					$series,
					$minimum
				),
			);
		}

		return array(
			'verdict'    => 'ok',
			'minimum_wp' => $minimum,
			'php_series' => $series,
			'message'    => sprintf(
				/* translators: 1: target WordPress version, 2: PHP series, 3: minimum WordPress version */
				__( 'WordPress %1$s is at or above %3$s, the first release supporting the PHP %2$s series. Supported, though WordPress.org publishes no per-release maximum, so this is not a guarantee.', 'acrossai-abilities-manager' ),
				$target_wp,
				$series,
				$minimum
			),
		);
	}

	/**
	 * The check WordPress's own update screen performs, which this plugin was bypassing.
	 *
	 * `wp-admin/update-core.php` compares the running PHP and MySQL against the offer's declared
	 * minimums before it will render an update button. `Core_Upgrader` does not repeat the check,
	 * so any caller driving that class directly skips it. Verified by grepping both.
	 *
	 * @since  0.0.45
	 * @param  string $php_minimum   Offer's `php_version`.
	 * @param  string $mysql_minimum Offer's `mysql_version`.
	 * @param  string $php           Running PHP version.
	 * @param  string $mysql         Running MySQL version, '' when unavailable.
	 * @return string[] Reasons the offer fails; empty when it passes.
	 */
	public static function offer_minimums( string $php_minimum, string $mysql_minimum, string $php, string $mysql ): array {
		$reasons = array();

		if ( '' !== $php_minimum && '' !== $php && version_compare( $php, $php_minimum, '<' ) ) {
			$reasons[] = sprintf(
				/* translators: 1: required PHP version, 2: running PHP version */
				__( 'That WordPress version requires PHP %1$s or newer; this site runs PHP %2$s.', 'acrossai-abilities-manager' ),
				$php_minimum,
				$php
			);
		}

		if ( '' !== $mysql_minimum && '' !== $mysql && version_compare( $mysql, $mysql_minimum, '<' ) ) {
			$reasons[] = sprintf(
				/* translators: 1: required MySQL version, 2: running MySQL version */
				__( 'That WordPress version requires MySQL %1$s or newer; this site runs MySQL %2$s.', 'acrossai-abilities-manager' ),
				$mysql_minimum,
				$mysql
			);
		}

		return $reasons;
	}
}
