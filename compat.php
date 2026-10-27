<?php
/**
 * PHP 8 Compatibility Functions
 * Symfony Polyfill https://github.com/symfony/polyfill/blob/master/src/Php80/Php80.php
 *
 * @package AFSRReloaded
 * @since   4.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * Check if string starts with substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for.
	 * @return bool
	 */
	function str_starts_with( $haystack, $needle ) {
		 return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	/**
	 * Check if string ends with substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for.
	 * @return bool
	 */
	function str_ends_with( $haystack, $needle ) {
		 return '' === $needle || ( '' !== $haystack && 0 === substr_compare( $haystack, $needle, -strlen( $needle ) ) );
	}
}

if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Check if string contains substring.
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for.
	 * @return bool
	 */
	function str_contains( $haystack, $needle ) {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}
