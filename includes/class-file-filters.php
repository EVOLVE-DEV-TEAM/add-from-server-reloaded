<?php
/**
 * Pure helpers for import file filtering (unit-test friendly).
 *
 * @package AFSRReloaded
 * @since   5.4.0
 */

namespace AFSRReloaded;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File filter rules used by scanners and schedules.
 *
 * @since 5.4.0
 */
class File_Filters {

	/**
	 * Whether a file path passes filter options.
	 *
	 * @since 5.4.0
	 *
	 * @param string $absolute Absolute filesystem path.
	 * @param array  $options  Job/schedule options.
	 * @return bool
	 */
	public static function passes( $absolute, array $options ) {
		$absolute = (string) $absolute;
		if ( '' === $absolute || ! is_file( $absolute ) ) {
			return false;
		}

		$max_mb = isset( $options['max_file_size_mb'] ) ? (float) $options['max_file_size_mb'] : 0;
		if ( $max_mb > 0 ) {
			$size = filesize( $absolute );
			if ( false !== $size && $size > ( $max_mb * MB_IN_BYTES ) ) {
				return false;
			}
		}

		$min_mtime = isset( $options['min_mtime'] ) ? absint( $options['min_mtime'] ) : 0;
		if ( $min_mtime > 0 ) {
			$mtime = filemtime( $absolute );
			if ( $mtime && $mtime < $min_mtime ) {
				return false;
			}
		}

		$file_types = isset( $options['file_types'] ) ? sanitize_key( $options['file_types'] ) : 'all';
		if ( 'all' === $file_types || '' === $file_types ) {
			return true;
		}

		$check = function_exists( 'wp_check_filetype' ) ? wp_check_filetype( $absolute ) : array(
			'type' => '',
			'ext'  => pathinfo( $absolute, PATHINFO_EXTENSION ),
		);
		$mime  = (string) ( $check['type'] ?? '' );
		$ext   = strtolower( (string) ( $check['ext'] ?? pathinfo( $absolute, PATHINFO_EXTENSION ) ) );

		if ( 'custom' === $file_types ) {
			$allowed = isset( $options['allowed_exts'] ) ? (string) $options['allowed_exts'] : '';
			$parts   = array_filter( array_map( 'trim', explode( ',', strtolower( $allowed ) ) ) );
			return empty( $parts ) ? true : in_array( $ext, $parts, true );
		}

		if ( 'images' === $file_types ) {
			return 0 === strpos( $mime, 'image/' ) || in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true );
		}
		if ( 'audio' === $file_types ) {
			return 0 === strpos( $mime, 'audio/' );
		}
		if ( 'video' === $file_types ) {
			return 0 === strpos( $mime, 'video/' );
		}
		if ( 'documents' === $file_types ) {
			return ( false !== strpos( $mime, 'pdf' )
				|| false !== strpos( $mime, 'msword' )
				|| false !== strpos( $mime, 'officedocument' )
				|| 0 === strpos( $mime, 'text/' )
				|| in_array( $ext, array( 'pdf', 'txt', 'doc', 'docx' ), true ) );
		}

		return true;
	}

	/**
	 * Build a safe preserved uploads subdirectory from a relative source path.
	 *
	 * @since 5.4.0
	 *
	 * @param string $relative Relative source file path.
	 * @param string $fallback Fallback when file is at root.
	 * @return string
	 */
	public static function preserve_subdir_from_relative( $relative, $fallback = 'afsr-imports' ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );
		if ( '' === $relative ) {
			return trim( (string) $fallback, '/' );
		}

		// Reject traversal attempts early.
		$raw_parts = explode( '/', $relative );
		foreach ( $raw_parts as $raw_part ) {
			if ( '..' === $raw_part ) {
				return trim( (string) $fallback, '/' );
			}
		}

		$dir = dirname( $relative );
		if ( '.' === $dir || '/' === $dir ) {
			return trim( (string) $fallback, '/' );
		}

		$parts = array_filter(
			explode( '/', $dir ),
			static function ( $part ) {
				return '' !== $part && '.' !== $part && '..' !== $part;
			}
		);

		if ( empty( $parts ) ) {
			return trim( (string) $fallback, '/' );
		}

		$clean = array();
		foreach ( $parts as $part ) {
			$clean[] = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $part ) : preg_replace( '/[^A-Za-z0-9._-]/', '', $part );
		}

		return implode( '/', array_filter( $clean ) );
	}

	/**
	 * Whether a candidate absolute path is inside an allowed root.
	 *
	 * @since 5.4.0
	 *
	 * @param string $root      Allowed root.
	 * @param string $candidate Absolute candidate.
	 * @return bool
	 */
	public static function path_is_under_root( $root, $candidate ) {
		return Path_Guard::is_under_root( $root, $candidate, true );
	}
}
