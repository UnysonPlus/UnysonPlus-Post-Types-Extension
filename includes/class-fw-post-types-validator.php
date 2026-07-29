<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Validates definition rows at SAVE time.
 *
 * The registrar can only skip a bad row silently — by `init` there is nowhere to
 * report anything. That produced the extension's worst behaviour: the user saved,
 * got a "Saved." notice, and nothing appeared, with no way to find out why. This
 * class runs on the save request (which happens on the page's `load-` hook, i.e.
 * after `init`, so post_type_exists() / taxonomy_exists() are meaningful) and
 * returns a list of human-readable problems to show as notices.
 *
 * Nothing here blocks the save. Definitions are always stored as entered so the
 * user does not lose typing; the notices explain what will not register and why.
 */
class FW_Post_Types_Validator {

	const LEVEL_ERROR   = 'error';
	const LEVEL_WARNING = 'warning';

	/**
	 * @param array $post_types      Submitted post type definition rows.
	 * @param array $taxonomies      Submitted taxonomy definition rows.
	 * @param array $prev_post_types Previously saved post types — these are what is
	 *                               registered on THIS request, so they are how we
	 *                               tell "claimed by another plugin" apart from
	 *                               "claimed by us a moment ago".
	 * @param array $prev_taxonomies Previously saved taxonomies.
	 *
	 * @return array[] Each: [ level, list, index, label, message ]
	 */
	public static function validate( array $post_types, array $taxonomies, array $prev_post_types = array(), array $prev_taxonomies = array() ) {
		$issues = array();

		$pt_slugs  = self::collect_slugs( $post_types, 'slug', 20 );
		$tax_slugs = self::collect_slugs( $taxonomies, 'tax_slug', 32 );

		$ours_pt  = array_values( self::collect_slugs( $prev_post_types, 'slug', 20 ) );
		$ours_tax = array_values( self::collect_slugs( $prev_taxonomies, 'tax_slug', 32 ) );

		self::validate_post_types( $post_types, $pt_slugs, $tax_slugs, $ours_pt, $issues );
		self::validate_taxonomies( $taxonomies, $pt_slugs, $tax_slugs, $ours_tax, $issues );

		return $issues;
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * @param array   $rows
	 * @param array   $pt_slugs  index => cleaned slug
	 * @param array   $tax_slugs index => cleaned slug
	 * @param array   $ours      Slugs this extension already has registered.
	 * @param array[] $issues
	 */
	private static function validate_post_types( array $rows, array $pt_slugs, array $tax_slugs, array $ours, array &$issues ) {
		$seen = array();

		foreach ( $rows as $i => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}

			$label = self::row_label( $def, 'plural', 'singular', 'slug', $i );
			$raw   = isset( $def['slug'] ) ? trim( (string) $def['slug'] ) : '';
			$slug  = isset( $pt_slugs[ $i ] ) ? $pt_slugs[ $i ] : '';

			if ( $raw === '' ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					__( 'No key / slug, so it cannot be registered. Give it a lowercase key such as "book".', 'fw' ) );
				continue;
			}

			if ( $slug === '' ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( 'The key "%s" contains no usable characters. Use lowercase letters, numbers and underscores.', 'fw' ), $raw ) );
				continue;
			}

			// Length. Enforced rather than truncated: silently registering
			// "customer_testimonia" for a typed "customer_testimonial" leaves the row
			// label and the real key permanently out of step.
			if ( strlen( sanitize_key( $raw ) ) > 20 ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( 'The key "%1$s" is longer than the 20 characters WordPress allows, so it was shortened to "%2$s". Set a key of 20 characters or fewer.', 'fw' ), $raw, $slug ) );
			} elseif ( $slug !== $raw ) {
				self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
					sprintf( __( 'The key was adjusted from "%1$s" to "%2$s" to make it valid.', 'fw' ), $raw, $slug ) );
			}

			if ( in_array( $slug, FW_Post_Types_Registrar::reserved_post_types(), true ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( '"%s" is reserved by WordPress (or clashes with a core query variable) and cannot be used as a post type key.', 'fw' ), $slug ) );
				continue;
			}

			if ( isset( $seen[ $slug ] ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( 'The key "%1$s" is already used by row %2$d. Only the first row with a given key is registered — give this one a different key.', 'fw' ), $slug, $seen[ $slug ] + 1 ) );
				continue;
			}
			$seen[ $slug ] = $i;

			// A post type and a taxonomy may not share a key — WordPress stores both
			// against the same rewrite/query-var namespace and the behaviour is undefined.
			$tax_clash = array_search( $slug, $tax_slugs, true );
			if ( $tax_clash !== false ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( 'The key "%s" is also used by a taxonomy. A post type and a taxonomy cannot share a key.', 'fw' ), $slug ) );
			}

			// Claimed by something else? Our own types are registered on this request
			// too, so only flag keys that are NOT ours.
			if ( post_type_exists( $slug ) && ! in_array( $slug, $ours, true ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'post_types', $i, $label,
					sprintf( __( 'The key "%s" is already registered by WordPress, a theme or another plugin. It will not be re-registered here — choose a different key.', 'fw' ), $slug ) );
			}

			// Permalink collision with an existing page/post of the same slug.
			$rewrite = FW_Post_Types_Registrar::clean_slug_loose(
				isset( $def['rewrite_slug'] ) && trim( (string) $def['rewrite_slug'] ) !== ''
					? $def['rewrite_slug']
					: $slug
			);
			if ( $rewrite !== '' && strpos( $rewrite, '/' ) === false ) {
				$conflict = get_page_by_path( $rewrite, OBJECT, array( 'page', 'post' ) );
				if ( $conflict ) {
					self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
						sprintf( __( 'The URL slug "%1$s" is also the slug of an existing %2$s ("%3$s"). One of the two will win — set a different URL slug on the URLs tab to be safe.', 'fw' ),
							$rewrite, get_post_type( $conflict ), get_the_title( $conflict ) ) );
				}
			}

			// Archive on a non-public type cannot resolve.
			if ( FW_Post_Types_Registrar::bool( $def, 'has_archive', true )
			     && ! FW_Post_Types_Registrar::bool( $def, 'public', true )
			     && ! FW_Post_Types_Registrar::bool( $def, 'publicly_queryable', false ) ) {
				self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
					__( 'Has archive is on but the type is not public, so the archive URL cannot resolve. Turn on Public (or Publicly queryable) on the Visibility tab, or turn the archive off.', 'fw' ) );
			}

			// Page Builder support asked for, but the extension is not available.
			if ( ! empty( $def['page_builder'] ) && ( ! function_exists( 'fw_ext' ) || ! fw_ext( 'page-builder' ) ) ) {
				self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
					__( 'Page Builder is ticked but the Page Builder extension is not active, so nothing was enabled.', 'fw' ) );
			}

			// Menu order sanity.
			if ( isset( $def['menu_position'] ) && trim( (string) $def['menu_position'] ) !== ''
			     && ! is_numeric( $def['menu_position'] ) ) {
				self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
					__( 'Menu position must be a number. It was ignored.', 'fw' ) );
			}

			if ( isset( $def['archive_per_page'] ) && trim( (string) $def['archive_per_page'] ) !== ''
			     && ( ! is_numeric( $def['archive_per_page'] ) || (int) $def['archive_per_page'] < -1 ) ) {
				self::add( $issues, self::LEVEL_WARNING, 'post_types', $i, $label,
					__( 'Items per page must be a positive number (or -1 for all). It was ignored.', 'fw' ) );
			}
		}
	}

	/**
	 * @param array   $rows
	 * @param array   $pt_slugs
	 * @param array   $tax_slugs
	 * @param array   $ours      Slugs this extension already has registered.
	 * @param array[] $issues
	 */
	private static function validate_taxonomies( array $rows, array $pt_slugs, array $tax_slugs, array $ours, array &$issues ) {
		$seen = array();

		foreach ( $rows as $i => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}

			$label = self::row_label( $def, 'tax_plural', 'tax_singular', 'tax_slug', $i );
			$raw   = isset( $def['tax_slug'] ) ? trim( (string) $def['tax_slug'] ) : '';
			$slug  = isset( $tax_slugs[ $i ] ) ? $tax_slugs[ $i ] : '';

			if ( $raw === '' ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					__( 'No key / slug, so it cannot be registered. Give it a lowercase key such as "genre".', 'fw' ) );
				continue;
			}

			if ( $slug === '' ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( 'The key "%s" contains no usable characters. Use lowercase letters, numbers and underscores.', 'fw' ), $raw ) );
				continue;
			}

			if ( strlen( sanitize_key( $raw ) ) > 32 ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( 'The key "%1$s" is longer than the 32 characters WordPress allows, so it was shortened to "%2$s". Set a key of 32 characters or fewer.', 'fw' ), $raw, $slug ) );
			} elseif ( $slug !== $raw ) {
				self::add( $issues, self::LEVEL_WARNING, 'taxonomies', $i, $label,
					sprintf( __( 'The key was adjusted from "%1$s" to "%2$s" to make it valid.', 'fw' ), $raw, $slug ) );
			}

			if ( in_array( $slug, FW_Post_Types_Registrar::reserved_taxonomies(), true ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( '"%s" is reserved by WordPress and cannot be used as a taxonomy key.', 'fw' ), $slug ) );
				continue;
			}

			if ( isset( $seen[ $slug ] ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( 'The key "%1$s" is already used by row %2$d. Only the first row with a given key is registered.', 'fw' ), $slug, $seen[ $slug ] + 1 ) );
				continue;
			}
			$seen[ $slug ] = $i;

			if ( in_array( $slug, $pt_slugs, true ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( 'The key "%s" is also used by a post type. A post type and a taxonomy cannot share a key.', 'fw' ), $slug ) );
			}

			if ( taxonomy_exists( $slug ) && ! in_array( $slug, $ours, true ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					sprintf( __( 'The key "%s" is already registered by WordPress, a theme or another plugin. It will not be re-registered here — choose a different key.', 'fw' ), $slug ) );
			}

			// Bound to nothing = registered but invisible. We skip registration in
			// that case, so say so.
			$object_types = FW_Post_Types_Registrar::parse_object_types(
				isset( $def['object_types'] ) ? $def['object_types'] : ''
			);

			if ( empty( $object_types ) ) {
				self::add( $issues, self::LEVEL_ERROR, 'taxonomies', $i, $label,
					__( 'No post types selected, so this taxonomy was not registered — a taxonomy attached to nothing has no term screen and no effect. Pick at least one post type on the General tab.', 'fw' ) );
			} else {
				$unknown = array();
				foreach ( $object_types as $ot ) {
					if ( ! post_type_exists( $ot ) && ! in_array( $ot, $pt_slugs, true ) ) {
						$unknown[] = $ot;
					}
				}
				if ( $unknown ) {
					self::add( $issues, self::LEVEL_WARNING, 'taxonomies', $i, $label,
						sprintf( __( 'Attached to post type(s) that do not exist: %s. Those attachments do nothing — remove them, or create the post type first.', 'fw' ), implode( ', ', $unknown ) ) );
				}
			}

			if ( FW_Post_Types_Registrar::bool( $def, 'tax_rewrite_hierarchical', false )
			     && ! FW_Post_Types_Registrar::bool( $def, 'tax_hierarchical', true ) ) {
				self::add( $issues, self::LEVEL_WARNING, 'taxonomies', $i, $label,
					__( 'Nested term URLs only apply to a hierarchical taxonomy. It has no effect here.', 'fw' ) );
			}
		}
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Cleaned slugs keyed by row index (so a row's position survives filtering).
	 *
	 * @param array  $rows
	 * @param string $key
	 * @param int    $max
	 *
	 * @return array
	 */
	private static function collect_slugs( array $rows, $key, $max ) {
		$out = array();

		foreach ( $rows as $i => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$out[ $i ] = FW_Post_Types_Registrar::clean_slug( isset( $def[ $key ] ) ? $def[ $key ] : '', $max );
		}

		return $out;
	}

	/**
	 * A readable name for a row, for the notice text.
	 *
	 * @param array  $def
	 * @param string $plural_key
	 * @param string $singular_key
	 * @param string $slug_key
	 * @param int    $index
	 *
	 * @return string
	 */
	private static function row_label( array $def, $plural_key, $singular_key, $slug_key, $index ) {
		foreach ( array( $plural_key, $singular_key, $slug_key ) as $key ) {
			if ( ! empty( $def[ $key ] ) && is_string( $def[ $key ] ) ) {
				return trim( $def[ $key ] );
			}
		}

		return sprintf( __( 'Row %d', 'fw' ), $index + 1 );
	}

	/**
	 * @param array[] $issues
	 * @param string  $level
	 * @param string  $list
	 * @param int     $index
	 * @param string  $label
	 * @param string  $message
	 */
	private static function add( array &$issues, $level, $list, $index, $label, $message ) {
		$issues[] = array(
			'level'   => $level,
			'list'    => $list,
			'index'   => $index,
			'label'   => $label,
			'message' => $message,
		);
	}
}
