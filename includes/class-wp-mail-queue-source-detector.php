<?php
/**
 * Detects the plugin that initiated a wp_mail() call.
 *
 * @package WP_Mail_Queue_Throttle
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds likely source plugin slugs from the current call stack.
 */
class WP_Mail_Queue_Source_Detector {
	/**
	 * Detects the first plugin slug in the backtrace that is not this plugin.
	 *
	 * @return string
	 */
	public static function detect() {
		$own_slugs = self::own_slugs();
		$trace     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}

			$slug = self::slug_from_path( $frame['file'] );

			if ( '' !== $slug && ! in_array( $slug, $own_slugs, true ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Returns possible slugs for this plugin across common install layouts.
	 *
	 * @return string[]
	 */
	private static function own_slugs() {
		$slugs = array();

		if ( defined( 'WMQT_PLUGIN_FILE' ) ) {
			$plugin_file = wp_normalize_path( WMQT_PLUGIN_FILE );
			$slugs[]     = basename( dirname( $plugin_file ) );
			$slugs[]     = basename( $plugin_file, '.php' );

			if ( function_exists( 'plugin_basename' ) ) {
				$plugin_basename = plugin_basename( WMQT_PLUGIN_FILE );
				$plugin_parts    = explode( '/', wp_normalize_path( $plugin_basename ) );

				if ( ! empty( $plugin_parts[0] ) && '.' !== $plugin_parts[0] ) {
					$slugs[] = $plugin_parts[0];
				}
			}
		}

		if ( defined( 'WMQT_PLUGIN_DIR' ) ) {
			$slugs[] = basename( untrailingslashit( wp_normalize_path( WMQT_PLUGIN_DIR ) ) );
		}

		$slugs = array_map( 'sanitize_key', $slugs );

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * Extracts a plugin slug from a filesystem path.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function slug_from_path( $path ) {
		$path = wp_normalize_path( $path );

		if ( ! preg_match( '#/wp-content/plugins/([^/]+)/#', $path, $matches ) ) {
			return '';
		}

		$slug = sanitize_key( $matches[1] );

		return '' !== $slug ? $slug : '';
	}
}
