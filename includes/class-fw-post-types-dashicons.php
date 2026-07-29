<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Exposes a Dashicons-only set to the icon option type, so the menu-icon picker
 * can show exactly the icons WordPress will actually render in the admin menu.
 *
 * The list is parsed from core's bundled dashicons.min.css rather than hardcoded,
 * so it tracks whatever the installed WordPress ships. The parse result is cached
 * in a transient keyed on the WordPress version — without the version in the key a
 * core upgrade that adds or renames icons would serve a stale list for up to a week.
 */
class FW_Post_Types_Dashicons {

	/**
	 * @internal
	 * Filter callback for `fw_option_type_icon_sets`.
	 *
	 * @param array $sets
	 *
	 * @return array
	 */
	public static function filter_sets( $sets ) {
		$sets['dashicons'] = array(
			// WordPress bundles Dashicons; point the picker at its stylesheet.
			'font-style-src'  => includes_url( 'css/dashicons.min.css' ),
			'container-class' => '',
			'groups'          => array(
				'dashicons' => __( 'Dashicons', 'fw' ),
			),
			'icons'           => self::icons(),
		);

		return $sets;
	}

	/**
	 * Every Dashicon class, keyed by the full class string the picker renders on
	 * the <i> element (e.g. "dashicons dashicons-book").
	 *
	 * @return array
	 */
	public static function icons() {
		$key    = 'fw_ext_post_types_dashicons_' . md5( (string) get_bloginfo( 'version' ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$icons    = array();
		$css_file = ABSPATH . WPINC . '/css/dashicons.min.css';

		if ( is_readable( $css_file ) ) {
			$css = file_get_contents( $css_file );
			if ( $css && preg_match_all( '/\.(dashicons-[a-z0-9-]+):before/', $css, $m ) ) {
				foreach ( array_unique( $m[1] ) as $class ) {
					// `.dashicons-before` is the base helper, not a real icon.
					if ( $class === 'dashicons-before' ) {
						continue;
					}
					$icons[ 'dashicons ' . $class ] = array( 'group' => 'dashicons' );
				}
			}
		}

		if ( empty( $icons ) ) {
			// Minimal fallback so the picker is never empty if the CSS moves.
			$fallback = array(
				'dashicons-admin-post', 'dashicons-admin-page', 'dashicons-admin-media',
				'dashicons-admin-links', 'dashicons-admin-users', 'dashicons-admin-tools',
				'dashicons-admin-settings', 'dashicons-format-image', 'dashicons-format-gallery',
				'dashicons-format-video', 'dashicons-format-audio', 'dashicons-book',
				'dashicons-portfolio', 'dashicons-products', 'dashicons-cart', 'dashicons-store',
				'dashicons-calendar', 'dashicons-location', 'dashicons-star-filled',
				'dashicons-heart', 'dashicons-megaphone', 'dashicons-groups', 'dashicons-id',
			);
			foreach ( $fallback as $class ) {
				$icons[ 'dashicons ' . $class ] = array( 'group' => 'dashicons' );
			}
		}

		ksort( $icons );
		set_transient( $key, $icons, WEEK_IN_SECONDS );

		return $icons;
	}
}
