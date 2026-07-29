<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Moving schemas in and out.
 *
 * Four jobs, all of which exist for the same reason: a content-type schema should
 * never be trapped inside one install's database.
 *
 *   - Export / import JSON, so a schema can travel between sites (and ride along
 *     inside a demo or child theme).
 *   - Import a CPT UI export, because most people already have their schema
 *     somewhere else and will not retype it.
 *   - Emit the equivalent register_post_type() PHP, so a developer can graduate a
 *     definition into a child theme and stop depending on this extension at all.
 *   - Rename a post type key, migrating the existing content with it — turning the
 *     documented "permanent, don't do this" warning into a supported operation.
 */
class FW_Post_Types_Porter {

	/* ---------------------------------------------------------------------- *
	 * Export
	 * ---------------------------------------------------------------------- */

	/**
	 * Stream the definitions as a JSON download and exit.
	 *
	 * Safe to call from the page's `load-` hook, which runs before any output.
	 *
	 * @param FW_Extension_Post_Types $ext
	 */
	public static function download( $ext ) {
		$json = wp_json_encode(
			self::export_array( $ext ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$filename = sprintf(
			'unysonplus-post-types-%s.json',
			sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) )
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	/**
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	public static function export_array( $ext ) {
		return array(
			'_format'    => 'unysonplus-post-types',
			'_version'   => 1,
			'post_types' => array_values( $ext->get_definitions() ),
			'taxonomies' => array_values( $ext->get_taxonomy_definitions() ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Import
	 * ---------------------------------------------------------------------- */

	/**
	 * Parse pasted JSON and merge (or replace) the saved definitions.
	 *
	 * Accepts this extension's own export, a CPT UI export (either the combined
	 * "cpt_custom_post_type" / "cpt_custom_tax" shape or a single-section one), and
	 * a bare list of rows.
	 *
	 * @param string                  $raw
	 * @param bool                    $replace
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array {
	 *     @type bool     $ok
	 *     @type string   $message
	 *     @type string[] $added
	 *     @type string[] $skipped
	 * }
	 */
	public static function import( $raw, $replace, $ext ) {
		$fail = function ( $message ) {
			return array( 'ok' => false, 'message' => $message, 'added' => array(), 'skipped' => array() );
		};

		$raw = trim( (string) $raw );

		if ( $raw === '' ) {
			return $fail( __( 'Nothing to import — paste a JSON export into the box first.', 'fw' ) );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return $fail( __( 'That is not valid JSON. Paste the whole file, including the outermost { and }.', 'fw' ) );
		}

		$parsed = self::parse( $data );

		if ( $parsed === null ) {
			return $fail( __( 'The JSON parsed, but it does not look like a post type export. Expected an Unyson+ export or a CPT UI export.', 'fw' ) );
		}

		if ( empty( $parsed['post_types'] ) && empty( $parsed['taxonomies'] ) ) {
			return $fail( __( 'The file contained no post types or taxonomies.', 'fw' ) );
		}

		$post_types = $replace ? array() : $ext->get_definitions();
		$taxonomies = $replace ? array() : $ext->get_taxonomy_definitions();

		$added   = array();
		$skipped = array();

		$have_pt  = self::keys_of( $post_types, 'slug', 20 );
		$have_tax = self::keys_of( $taxonomies, 'tax_slug', 32 );

		foreach ( $parsed['post_types'] as $def ) {
			$key = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );

			if ( $key === '' ) {
				$skipped[] = __( 'A post type with no key was skipped.', 'fw' );
				continue;
			}
			if ( in_array( $key, $have_pt, true ) ) {
				$skipped[] = sprintf( __( 'Post type "%s" already defined — left untouched.', 'fw' ), $key );
				continue;
			}

			$post_types[] = $def;
			$have_pt[]    = $key;
			$added[]      = sprintf( __( 'Post type: %s', 'fw' ), $key );
		}

		foreach ( $parsed['taxonomies'] as $def ) {
			$key = FW_Post_Types_Registrar::clean_slug( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '', 32 );

			if ( $key === '' ) {
				$skipped[] = __( 'A taxonomy with no key was skipped.', 'fw' );
				continue;
			}
			if ( in_array( $key, $have_tax, true ) ) {
				$skipped[] = sprintf( __( 'Taxonomy "%s" already defined — left untouched.', 'fw' ), $key );
				continue;
			}

			$taxonomies[] = $def;
			$have_tax[]   = $key;
			$added[]      = sprintf( __( 'Taxonomy: %s', 'fw' ), $key );
		}

		$ext->save_definitions( $post_types, $taxonomies );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: number created, 2: number skipped */
				__( 'Import finished: %1$d created, %2$d skipped.', 'fw' ),
				count( $added ),
				count( $skipped )
			),
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Normalise any recognised export shape into our definition rows.
	 *
	 * @param array $data
	 *
	 * @return array|null [ post_types, taxonomies ]
	 */
	private static function parse( array $data ) {
		// Our own export.
		if ( isset( $data['_format'] ) && $data['_format'] === 'unysonplus-post-types' ) {
			return array(
				'post_types' => isset( $data['post_types'] ) ? array_values( (array) $data['post_types'] ) : array(),
				'taxonomies' => isset( $data['taxonomies'] ) ? array_values( (array) $data['taxonomies'] ) : array(),
			);
		}

		// A CPT UI export — combined, or one section on its own.
		if ( isset( $data['cpt_custom_post_type'] ) || isset( $data['cpt_custom_tax'] ) ) {
			return array(
				'post_types' => self::from_cpt_ui_post_types( isset( $data['cpt_custom_post_type'] ) ? (array) $data['cpt_custom_post_type'] : array() ),
				'taxonomies' => self::from_cpt_ui_taxonomies( isset( $data['cpt_custom_tax'] ) ? (array) $data['cpt_custom_tax'] : array() ),
			);
		}

		// Our own shape without the marker (an older hand-made file).
		if ( isset( $data['post_types'] ) || isset( $data['taxonomies'] ) ) {
			return array(
				'post_types' => isset( $data['post_types'] ) ? array_values( (array) $data['post_types'] ) : array(),
				'taxonomies' => isset( $data['taxonomies'] ) ? array_values( (array) $data['taxonomies'] ) : array(),
			);
		}

		// A bare CPT UI section: { "book": { "name": "book", ... } }. Distinguished
		// from a taxonomy section by the presence of object_types.
		$first = reset( $data );
		if ( is_array( $first ) && isset( $first['name'] ) ) {
			return isset( $first['object_types'] )
				? array( 'post_types' => array(), 'taxonomies' => self::from_cpt_ui_taxonomies( $data ) )
				: array( 'post_types' => self::from_cpt_ui_post_types( $data ), 'taxonomies' => array() );
		}

		return null;
	}

	/**
	 * @param array $rows
	 *
	 * @return array
	 */
	private static function from_cpt_ui_post_types( array $rows ) {
		$out = array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = isset( $row['name'] ) ? $row['name'] : $key;

			$supports = array();
			foreach ( (array) ( isset( $row['supports'] ) ? $row['supports'] : array() ) as $s ) {
				$supports[ (string) $s ] = true;
			}

			$builtin = array();
			foreach ( (array) ( isset( $row['taxonomies'] ) ? $row['taxonomies'] : array() ) as $t ) {
				if ( in_array( $t, array( 'category', 'post_tag' ), true ) ) {
					$builtin[ $t ] = true;
				}
			}

			// CPT UI stores has_archive as a boolean plus an optional custom string.
			$archive_slug = isset( $row['has_archive_string'] ) ? (string) $row['has_archive_string'] : '';

			$menu_icon = isset( $row['menu_icon'] ) ? trim( (string) $row['menu_icon'] ) : '';

			$def = array(
				'enabled'             => true,
				'singular'            => isset( $row['singular_label'] ) ? (string) $row['singular_label'] : '',
				'plural'              => isset( $row['label'] ) ? (string) $row['label'] : '',
				'slug'                => $slug,
				'description'         => isset( $row['description'] ) ? (string) $row['description'] : '',
				'supports'            => $supports ? $supports : array( 'title' => true, 'editor' => true ),
				'page_builder'        => false,
				'hierarchical'        => self::truthy( $row, 'hierarchical', false ),
				'menu_icon'           => self::cpt_ui_menu_icon( $menu_icon ),
				'menu_position'       => isset( $row['menu_position'] ) ? (string) $row['menu_position'] : '',
				'public'              => self::truthy( $row, 'public', true ),
				'publicly_queryable'  => self::truthy( $row, 'publicly_queryable', true ),
				'exclude_from_search' => self::truthy( $row, 'exclude_from_search', false ),
				'show_in_nav_menus'   => self::truthy( $row, 'show_in_nav_menus', true ),
				'show_ui'             => self::truthy( $row, 'show_ui', true ),
				'show_in_menu'        => self::truthy( $row, 'show_in_menu', true ),
				'show_in_admin_bar'   => true,
				'show_in_rest'        => self::truthy( $row, 'show_in_rest', true ),
				'rest_base'           => isset( $row['rest_base'] ) ? (string) $row['rest_base'] : '',
				'can_export'          => true,
				'rewrite_slug'        => isset( $row['rewrite_slug'] ) ? (string) $row['rewrite_slug'] : '',
				'with_front'          => self::truthy( $row, 'rewrite_withfront', true ),
				'has_archive'         => self::truthy( $row, 'has_archive', true ),
				'archive_slug'        => $archive_slug,
				'builtin_taxonomies'  => $builtin,
				'archive_per_page'    => '',
				'archive_orderby'     => '',
				'archive_order'       => '',
			);

			foreach ( self::label_map( isset( $row['labels'] ) ? $row['labels'] : array(), 'label_' ) as $k => $v ) {
				$def[ $k ] = $v;
			}

			$out[] = $def;
		}

		return $out;
	}

	/**
	 * @param array $rows
	 *
	 * @return array
	 */
	private static function from_cpt_ui_taxonomies( array $rows ) {
		$out = array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug = isset( $row['name'] ) ? $row['name'] : $key;

			$def = array(
				'tax_enabled'              => true,
				'tax_singular'             => isset( $row['singular_label'] ) ? (string) $row['singular_label'] : '',
				'tax_plural'               => isset( $row['label'] ) ? (string) $row['label'] : '',
				'tax_slug'                 => $slug,
				'object_types'             => array_values( (array) ( isset( $row['object_types'] ) ? $row['object_types'] : array() ) ),
				'tax_description'          => isset( $row['description'] ) ? (string) $row['description'] : '',
				'tax_hierarchical'         => self::truthy( $row, 'hierarchical', true ),
				'tax_default_term'         => '',
				'tax_meta_box'             => 'default',
				'tax_show_admin_column'    => self::truthy( $row, 'show_admin_column', true ),
				'tax_show_in_quick_edit'   => self::truthy( $row, 'show_in_quick_edit', true ),
				'tax_show_tagcloud'        => self::truthy( $row, 'show_tagcloud', true ),
				'tax_public'               => self::truthy( $row, 'public', true ),
				'tax_show_in_nav_menus'    => self::truthy( $row, 'show_in_nav_menus', true ),
				'tax_show_in_rest'         => self::truthy( $row, 'show_in_rest', true ),
				'tax_rest_base'            => isset( $row['rest_base'] ) ? (string) $row['rest_base'] : '',
				'tax_rewrite_slug'         => isset( $row['rewrite_slug'] ) ? (string) $row['rewrite_slug'] : '',
				'tax_with_front'           => self::truthy( $row, 'rewrite_withfront', true ),
				'tax_rewrite_hierarchical' => self::truthy( $row, 'rewrite_hierarchical', false ),
			);

			foreach ( self::label_map( isset( $row['labels'] ) ? $row['labels'] : array(), 'tax_label_' ) as $k => $v ) {
				$def[ $k ] = $v;
			}

			$out[] = $def;
		}

		return $out;
	}

	/**
	 * CPT UI stores booleans as the strings "true" / "false"; older exports use
	 * real booleans or 0/1. Read all three.
	 *
	 * @param array  $row
	 * @param string $key
	 * @param bool   $default
	 *
	 * @return bool
	 */
	private static function truthy( array $row, $key, $default ) {
		if ( ! array_key_exists( $key, $row ) ) {
			return $default;
		}

		$value = $row[ $key ];

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( $value === '' ) {
				return $default;
			}
			return ! in_array( $value, array( 'false', '0', 'no', 'off' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Keep only the labels we expose as override fields.
	 *
	 * @param mixed  $labels
	 * @param string $prefix
	 *
	 * @return array
	 */
	private static function label_map( $labels, $prefix ) {
		if ( ! is_array( $labels ) ) {
			return array();
		}

		$allowed = array(
			'menu_name', 'all_items', 'add_new_item', 'edit_item', 'view_item',
			'search_items', 'not_found', 'archives', 'featured_image',
			'set_featured_image', 'new_item_name', 'parent_item', 'choose_from_most_used',
		);

		$out = array();

		foreach ( $allowed as $key ) {
			if ( ! empty( $labels[ $key ] ) && is_string( $labels[ $key ] ) ) {
				$out[ $prefix . $key ] = $labels[ $key ];
			}
		}

		return $out;
	}

	/**
	 * @param string $icon
	 *
	 * @return array
	 */
	private static function cpt_ui_menu_icon( $icon ) {
		if ( $icon === '' ) {
			$icon = 'dashicons-admin-post';
		}

		// CPT UI allows a media URL here as well as a Dashicon class.
		if ( preg_match( '#^https?://#i', $icon ) ) {
			return array(
				'source' => 'image',
				'image'  => array( 'file' => array( 'url' => $icon ) ),
			);
		}

		if ( strpos( $icon, 'dashicons' ) === false ) {
			$icon = 'dashicons-admin-post';
		}

		return array(
			'source'   => 'dashicon',
			'dashicon' => array(
				'icon' => array(
					'type'       => 'icon-font',
					'icon-class' => 'dashicons ' . str_replace( 'dashicons ', '', $icon ),
				),
			),
		);
	}

	/**
	 * @param array  $rows
	 * @param string $key
	 * @param int    $max
	 *
	 * @return string[]
	 */
	private static function keys_of( array $rows, $key, $max ) {
		$out = array();

		foreach ( $rows as $def ) {
			if ( is_array( $def ) ) {
				$slug = FW_Post_Types_Registrar::clean_slug( isset( $def[ $key ] ) ? $def[ $key ] : '', $max );
				if ( $slug !== '' ) {
					$out[] = $slug;
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * PHP code export
	 * ---------------------------------------------------------------------- */

	/**
	 * Emit the register_post_type() / register_taxonomy() calls equivalent to the
	 * saved definitions, ready to paste into a child theme's functions.php.
	 *
	 * The point is that nothing here is locked in: the extension is a UI over these
	 * exact calls, and this proves it.
	 *
	 * @param FW_Extension_Post_Types $ext
	 * @param string                  $text_domain
	 *
	 * @return string
	 */
	public static function php_code( $ext, $text_domain = 'your-theme' ) {
		$domain = sanitize_key( $text_domain );
		if ( $domain === '' ) {
			$domain = 'your-theme';
		}

		$lines   = array();
		$lines[] = "<?php";
		$lines[] = "/**";
		$lines[] = " * Post types and taxonomies, exported from Unyson+ &rarr; Post Types.";
		$lines[] = " *";
		$lines[] = " * Paste into your child theme's functions.php (or a small plugin). Once this";
		$lines[] = " * code is running you can delete the matching rows in the Post Types screen —";
		$lines[] = " * the definitions are equivalent, so existing content is unaffected.";
		$lines[] = " */";
		$lines[] = "add_action( 'init', function () {";
		$lines[] = "";

		foreach ( $ext->get_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}

			$slug = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			if ( $slug === '' ) {
				continue;
			}

			$args = $ext->build_args_for_export( $def, $slug );
			if ( ! $args ) {
				continue;
			}

			$lines[] = "\tregister_post_type( '" . $slug . "', " . self::export_php( $args, 1, $domain ) . " );";
			$lines[] = "";
		}

		foreach ( $ext->get_taxonomy_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}

			$slug = FW_Post_Types_Registrar::clean_slug( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '', 32 );
			if ( $slug === '' ) {
				continue;
			}

			$object_types = FW_Post_Types_Registrar::parse_object_types(
				isset( $def['object_types'] ) ? $def['object_types'] : ''
			);
			if ( empty( $object_types ) ) {
				continue;
			}

			$args = $ext->build_tax_args_for_export( $def, $slug );
			if ( ! $args ) {
				continue;
			}

			$lines[] = "\tregister_taxonomy(";
			$lines[] = "\t\t'" . $slug . "',";
			$lines[] = "\t\t" . self::export_php( $object_types, 2, $domain ) . ",";
			$lines[] = "\t\t" . self::export_php( $args, 2, $domain );
			$lines[] = "\t);";
			$lines[] = "";
		}

		$lines[] = "}, 20 );";

		return implode( "\n", $lines );
	}

	/**
	 * A var_export() that emits short array syntax, tab indentation, and wraps
	 * label strings in __() against the given text domain.
	 *
	 * @param mixed  $value
	 * @param int    $depth
	 * @param string $domain
	 * @param bool   $translate
	 *
	 * @return string
	 */
	private static function export_php( $value, $depth, $domain, $translate = false ) {
		$pad  = str_repeat( "\t", $depth );
		$pad2 = str_repeat( "\t", $depth + 1 );

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			$quoted = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";

			return $translate ? "__( {$quoted}, '{$domain}' )" : $quoted;
		}
		if ( ! is_array( $value ) ) {
			return "''";
		}

		if ( empty( $value ) ) {
			return 'array()';
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out     = "array(\n";

		foreach ( $value as $k => $v ) {
			// Every string inside a `labels` array is user-facing copy, so it is
			// emitted wrapped in __() — the whole point of exporting to a theme is
			// that the strings become translatable there.
			$rendered = self::export_php( $v, $depth + 1, $domain, $translate || $k === 'labels' );

			$out .= $is_list
				? $pad2 . $rendered . ",\n"
				: $pad2 . "'" . $k . "' => " . $rendered . ",\n";
		}

		return $out . $pad . ')';
	}

	/* ---------------------------------------------------------------------- *
	 * Rename a post type key
	 * ---------------------------------------------------------------------- */

	/**
	 * How many posts would a rename move?
	 *
	 * @param string $from
	 *
	 * @return int
	 */
	public static function rename_count( $from ) {
		global $wpdb;

		$from = FW_Post_Types_Registrar::clean_slug( $from, 20 );

		if ( $from === '' ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			$from
		) );
	}

	/**
	 * Rename a post type key and migrate its content.
	 *
	 * Updates the definition, every wp_posts row of that type, and any taxonomy
	 * definition attached to the old key. Term relationships and post meta key off
	 * the post ID, so they need no migration.
	 *
	 * @param string                  $from
	 * @param string                  $to
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array [ ok, message ]
	 */
	public static function rename( $from, $to, $ext ) {
		global $wpdb;

		$from = FW_Post_Types_Registrar::clean_slug( $from, 20 );
		$to   = FW_Post_Types_Registrar::clean_slug( $to, 20 );

		if ( $from === '' || $to === '' ) {
			return array( 'ok' => false, 'message' => __( 'Both the current and the new key are required.', 'fw' ) );
		}
		if ( $from === $to ) {
			return array( 'ok' => false, 'message' => __( 'The new key is the same as the current one.', 'fw' ) );
		}
		if ( in_array( $to, FW_Post_Types_Registrar::reserved_post_types(), true ) ) {
			return array( 'ok' => false, 'message' => sprintf( __( '"%s" is reserved by WordPress and cannot be used.', 'fw' ), $to ) );
		}

		$post_types = $ext->get_definitions();
		$taxonomies = $ext->get_taxonomy_definitions();

		$target = null;
		foreach ( $post_types as $i => $def ) {
			if ( is_array( $def ) && FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 ) === $from ) {
				$target = $i;
			}
			if ( is_array( $def ) && FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 ) === $to ) {
				return array( 'ok' => false, 'message' => sprintf( __( 'The key "%s" is already used by another row.', 'fw' ), $to ) );
			}
		}

		if ( $target === null ) {
			return array( 'ok' => false, 'message' => sprintf( __( 'No post type defined here with the key "%s".', 'fw' ), $from ) );
		}

		// Anything else already owning the destination key would silently absorb the
		// migrated content.
		if ( post_type_exists( $to ) ) {
			return array( 'ok' => false, 'message' => sprintf( __( 'The key "%s" is already registered by something else.', 'fw' ), $to ) );
		}

		$moved = $wpdb->update( $wpdb->posts, array( 'post_type' => $to ), array( 'post_type' => $from ) );

		if ( $moved === false ) {
			return array( 'ok' => false, 'message' => __( 'The database update failed; nothing was changed.', 'fw' ) );
		}

		$post_types[ $target ]['slug'] = $to;

		// Re-point any taxonomy attached to the old key.
		foreach ( $taxonomies as $i => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$types = FW_Post_Types_Registrar::parse_object_types( isset( $def['object_types'] ) ? $def['object_types'] : '' );
			$index = array_search( $from, $types, true );
			if ( $index !== false ) {
				$types[ $index ]                  = $to;
				$taxonomies[ $i ]['object_types'] = array_values( array_unique( $types ) );
			}
		}

		$ext->save_definitions( $post_types, $taxonomies );

		clean_post_cache( 0 );
		wp_cache_flush();

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: old key, 2: new key, 3: number of posts moved */
				__( 'Renamed "%1$s" to "%2$s" and moved %3$d item(s). Check your permalinks — the URL slug still uses whatever is set on the URLs tab.', 'fw' ),
				$from,
				$to,
				(int) $moved
			),
		);
	}
}
