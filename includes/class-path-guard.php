<?php
/**
 * Path boundary helpers (directory-jail security).
 *
 * Matches the wordpress.org 5.2.2 fix for authenticated path boundary bypass
 * (sibling-prefix: /path/app vs /path/app2).
 *
 * @package AFSRReloaded
 * @since   5.4.1
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical path checks that reject sibling-prefix bypasses.
 *
 * @since 5.4.1
 */
class Path_Guard {

	/**
	 * Whether $path is the $root directory or a child under $root/.
	 *
	 * Requires a directory separator after the root prefix so that
	 * root `/path/app` does NOT match `/path/app2/...`.
	 *
	 * @since 5.4.1
	 *
	 * @param string $path Absolute path (preferably realpath()).
	 * @param string $root Absolute root (preferably realpath()).
	 * @return bool
	 */
	public static function path_has_root_boundary( $path, $root ) {
		if ( ! is_string( $path ) || '' === $path || ! is_string( $root ) || '' === $root ) {
			return false;
		}

		$path = wp_normalize_path( $path );
		$root = untrailingslashit( wp_normalize_path( $root ) );

		if ( '' === $root ) {
			return false;
		}

		if ( $path === $root ) {
			return true;
		}

		$root_with_boundary = $root . '/';

		if ( str_starts_with( $path, $root_with_boundary ) ) {
			return true;
		}

		// Windows paths are case-insensitive.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			return (
				0 === strcasecmp( $path, $root )
				|| 0 === strncasecmp( $path, $root_with_boundary, strlen( $root_with_boundary ) )
			);
		}

		return false;
	}

	/**
	 * Convert an absolute path to a root-relative path (prefix strip, not str_replace).
	 *
	 * Using str_replace( $root, '', $path ) corrupts paths when $root appears more
	 * than once (e.g. nested copies under uploads/…/wordpress/…).
	 *
	 * @since 5.3.0
	 *
	 * @param string $absolute Absolute filesystem path.
	 * @param string $root     Allowed root directory.
	 * @return string|false Relative path or false if outside root.
	 */
	public static function absolute_to_relative( $absolute, $root ) {
		if ( ! is_string( $absolute ) || '' === $absolute || ! is_string( $root ) || '' === $root ) {
			return false;
		}

		$abs_real  = realpath( $absolute );
		$root_real = realpath( $root );
		$absolute  = wp_normalize_path( $abs_real ? $abs_real : $absolute );
		$root      = untrailingslashit( wp_normalize_path( $root_real ? $root_real : $root ) );

		if ( ! self::path_has_root_boundary( $absolute, $root ) ) {
			return false;
		}

		if ( $absolute === $root ) {
			return '';
		}

		return ltrim( substr( $absolute, strlen( $root ) ), '/' );
	}

	/**
	 * Whether a path is accessible under PHP's open_basedir restriction.
	 *
	 * Used before is_dir()/is_readable() so restricted parent paths (common on
	 * WordPress Studio / shared hosting) do not emit open_basedir warnings.
	 *
	 * @since 5.4.3
	 *
	 * @param string      $path         Absolute path.
	 * @param string|null $open_basedir Optional override (defaults to ini_get). Empty = unrestricted.
	 * @return bool True if open_basedir is unset/empty or the path is inside an allowed directory.
	 */
	public static function is_path_allowed( $path, $open_basedir = null ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		if ( null === $open_basedir ) {
			$open_basedir = (string) ini_get( 'open_basedir' );
		} else {
			$open_basedir = (string) $open_basedir;
		}

		if ( '' === $open_basedir ) {
			return true;
		}

		$path = wp_normalize_path( rtrim( $path, '/\\' ) ) . '/';

		foreach ( explode( PATH_SEPARATOR, $open_basedir ) as $allowed ) {
			$allowed = trim( (string) $allowed );
			if ( '' === $allowed ) {
				continue;
			}

			$allowed = wp_normalize_path( rtrim( $allowed, '/\\' ) ) . '/';

			if ( str_starts_with( $path, $allowed ) ) {
				return true;
			}

			if ( 'Windows' === PHP_OS_FAMILY && 0 === strncasecmp( $path, $allowed, strlen( $allowed ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a candidate path is inside an allowed root after realpath when possible.
	 *
	 * @since 5.4.1
	 *
	 * @param string $root       Allowed root directory.
	 * @param string $candidate  Absolute path to validate.
	 * @param bool   $allow_root Whether the root directory itself is allowed.
	 * @return bool
	 */
	public static function is_under_root( $root, $candidate, $allow_root = true ) {
		$root      = (string) $root;
		$candidate = (string) $candidate;

		if ( '' === $root || '' === $candidate ) {
			return false;
		}

		$root_real = realpath( $root );
		$cand_real = realpath( $candidate );

		if ( $root_real ) {
			$root = $root_real;
		}
		if ( $cand_real ) {
			$candidate = $cand_real;
		}

		$ok = self::path_has_root_boundary( $candidate, $root );

		if ( $ok && ! $allow_root ) {
			$root_n = untrailingslashit( wp_normalize_path( $root ) );
			$cand_n = untrailingslashit( wp_normalize_path( $candidate ) );
			return $cand_n !== $root_n;
		}

		return $ok;
	}
}
