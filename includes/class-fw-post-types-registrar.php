<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Turns saved definition rows into real WordPress objects.
 *
 * Everything here is deliberately non-fatal: a malformed row is skipped, never
 * thrown. The user-facing explanation for a skipped row comes from the validator
 * at save time (FW_Post_Types_Validator), not from here — by the time we are on
 * `init` there is nowhere to show it.
 */
class FW_Post_Types_Registrar {

	/** @var FW_Extension_Post_Types */
	private $ext;

	/**
	 * Post type keys that WordPress (or common plugins) already own, or that
	 * collide with core query vars. We never register over them.
	 *
	 * @var string[]
	 */
	private static $reserved = array(
		'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
		'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
		'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
		'wp_font_family', 'wp_font_face',
		// Core query vars — a post type with these keys breaks the main query.
		'action', 'order', 'orderby', 'theme', 'author', 'type', 'date', 'name',
		'fields', 'error', 'terms', 'title', 'content', 'status', 'tag', 'cat',
		'fw-portfolio',
	);

	/**
	 * Taxonomy keys WordPress (or common plugins) already own.
	 *
	 * @var string[]
	 */
	private static $reserved_tax = array(
		'category', 'post_tag', 'nav_menu', 'link_category', 'post_format',
		'post_translations', 'term_translations', 'language', 'author',
		'type', 'terms', 'fields', 'name', 'date', 'title', 'content', 'status',
	);

	public function __construct( $ext ) {
		$this->ext = $ext;
	}

	/**
	 * @return string[]
	 */
	public static function reserved_post_types() {
		return self::$reserved;
	}

	/**
	 * @return string[]
	 */
	public static function reserved_taxonomies() {
		return self::$reserved_tax;
	}

	/* ---------------------------------------------------------------------- *
	 * Registration
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 * Registers every enabled definition. Hooked to `init` at 20.
	 */
	public function register_all() {
		// Post types first, so taxonomies can attach to them.
		foreach ( $this->ext->get_definitions() as $def ) {
			if ( is_array( $def ) ) {
				$this->register_post_type_def( $def );
			}
		}

		foreach ( $this->ext->get_taxonomy_definitions() as $def ) {
			if ( is_array( $def ) ) {
				$this->register_taxonomy_def( $def );
			}
		}
	}

	/**
	 * @internal
	 * Flush deferred from the previous request's save.
	 *
	 * Hooked to `wp_loaded`, NOT to our own `init` callback. Flushing at `init`/20
	 * regenerates the rewrite_rules option from whatever has registered *so far* —
	 * so any post type or taxonomy another plugin registers at a later `init`
	 * priority would have its rules silently dropped, 404-ing that plugin's
	 * permalinks until something else triggered a flush. By `wp_loaded`, `init` has
	 * completed and every plugin's rules are present.
	 */
	public function maybe_flush() {
		if ( ! get_option( FW_Extension_Post_Types::FLUSH_FLAG ) ) {
			return;
		}

		delete_option( FW_Extension_Post_Types::FLUSH_FLAG );
		flush_rewrite_rules( false );
	}

	/**
	 * Sanitises one post-type definition row and registers it.
	 *
	 * @param array $def
	 */
	private function register_post_type_def( array $def ) {
		if ( ! self::is_enabled( $def ) ) {
			return;
		}

		$slug = self::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );

		if ( $slug === '' || in_array( $slug, self::$reserved, true ) ) {
			return;
		}

		// Don't clobber a post type someone else already registered.
		if ( post_type_exists( $slug ) ) {
			return;
		}

		$args = $this->build_post_type_args( $def, $slug );

		/**
		 * Filters the final register_post_type() args for a user-created post type before it is registered, passing the slug and saved definition row.
		 *
		 * Filter the final register_post_type() args for a user-created post type.
		 *
		 * @param array  $args The arguments passed to register_post_type().
		 * @param string $slug The post type key.
		 * @param array  $def  The raw saved definition row.
		 */
		$args = apply_filters( 'fw_ext_post_types_args', $args, $slug, $def );

		register_post_type( $slug, $args );

		// Page Builder support, straight from the row — so creating a builder-ready
		// post type is one checkbox instead of a second trip to the Page Builder
		// settings screen. That screen still works as a global override; support is
		// additive, so ticking either place is enough.
		if ( ! empty( $def['page_builder'] ) && function_exists( 'fw_ext' ) ) {
			$pb = fw_ext( 'page-builder' );
			if ( $pb && method_exists( $pb, 'get_supports_feature_name' ) ) {
				add_post_type_support( $slug, $pb->get_supports_feature_name() );
			}
		}
	}

	/**
	 * Build the register_post_type() argument array from a definition row.
	 *
	 * @param array  $def
	 * @param string $slug
	 *
	 * @return array
	 */
	public function build_post_type_args( array $def, $slug ) {
		list( $singular, $plural ) = self::names( $def, $slug, 'singular', 'plural' );

		$supports = self::selected_keys( isset( $def['supports'] ) ? $def['supports'] : array() );
		if ( empty( $supports ) ) {
			$supports = array( 'title', 'editor' );
		}

		$public = self::bool( $def, 'public', true );

		// Archive: on + a slug given => that slug; on with no slug => true (the key).
		$has_archive = self::bool( $def, 'has_archive', true );
		if ( $has_archive ) {
			$archive_slug = self::clean_slug_loose( isset( $def['archive_slug'] ) ? $def['archive_slug'] : '' );
			$has_archive  = $archive_slug !== '' ? $archive_slug : true;
		}

		$rewrite_slug = self::clean_slug_loose( isset( $def['rewrite_slug'] ) ? $def['rewrite_slug'] : '' );
		$rewrite      = array(
			'slug'       => $rewrite_slug !== '' ? $rewrite_slug : $slug,
			'with_front' => self::bool( $def, 'with_front', true ),
		);

		$menu_position = ( isset( $def['menu_position'] ) && is_numeric( $def['menu_position'] ) )
			? (int) $def['menu_position']
			: null;

		$args = array(
			'labels'              => self::apply_label_overrides(
				self::build_labels( $singular, $plural ),
				self::label_overrides( $def, 'label_' )
			),
			'description'         => isset( $def['description'] ) ? (string) $def['description'] : '',
			'public'              => $public,
			'publicly_queryable'  => self::bool( $def, 'publicly_queryable', $public ),
			'exclude_from_search' => self::bool( $def, 'exclude_from_search', ! $public ),
			'show_ui'             => self::bool( $def, 'show_ui', true ),
			'show_in_menu'        => self::bool( $def, 'show_in_menu', true ),
			'show_in_nav_menus'   => self::bool( $def, 'show_in_nav_menus', $public ),
			'show_in_admin_bar'   => self::bool( $def, 'show_in_admin_bar', true ),
			'has_archive'         => $has_archive,
			'hierarchical'        => self::bool( $def, 'hierarchical', false ),
			'show_in_rest'        => self::bool( $def, 'show_in_rest', true ),
			'menu_icon'           => self::resolve_menu_icon( isset( $def['menu_icon'] ) ? $def['menu_icon'] : null ),
			'supports'            => $supports,
			'taxonomies'          => self::selected_keys( isset( $def['builtin_taxonomies'] ) ? $def['builtin_taxonomies'] : array() ),
			'can_export'          => self::bool( $def, 'can_export', true ),
			'query_var'           => true,
			'rewrite'             => $rewrite,
		);

		$rest_base = self::clean_slug_loose( isset( $def['rest_base'] ) ? $def['rest_base'] : '' );
		if ( $rest_base !== '' ) {
			$args['rest_base'] = $rest_base;
		}

		if ( $menu_position !== null ) {
			$args['menu_position'] = $menu_position;
		}

		return $args;
	}

	/**
	 * Sanitises one taxonomy definition row and registers it.
	 *
	 * @param array $def
	 */
	private function register_taxonomy_def( array $def ) {
		if ( ! self::is_enabled( $def, 'tax_enabled' ) ) {
			return;
		}

		$slug = self::clean_slug( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '', 32 );

		if ( $slug === '' || in_array( $slug, self::$reserved_tax, true ) ) {
			return;
		}
		if ( taxonomy_exists( $slug ) ) {
			return;
		}

		$object_types = self::parse_object_types( isset( $def['object_types'] ) ? $def['object_types'] : '' );

		// A taxonomy bound to nothing registers successfully but has no meta box, no
		// admin menu and no visible effect — while still claiming the key, so a later
		// correct attempt is blocked by the taxonomy_exists() guard above. Skipping is
		// strictly better; the validator explains it at save time.
		if ( empty( $object_types ) ) {
			return;
		}

		$args = $this->build_taxonomy_args( $def, $slug );

		/**
		 * Filters the final register_taxonomy() args for a user-created taxonomy before registration, passing the slug, bound object types, and definition row.
		 *
		 * Filter the final register_taxonomy() args for a user-created taxonomy.
		 *
		 * @param array    $args         The arguments passed to register_taxonomy().
		 * @param string   $slug         The taxonomy key.
		 * @param string[] $object_types Post type keys the taxonomy is bound to.
		 * @param array    $def          The raw saved definition row.
		 */
		$args = apply_filters( 'fw_ext_post_types_taxonomy_args', $args, $slug, $object_types, $def );

		register_taxonomy( $slug, $object_types, $args );
	}

	/**
	 * @param array  $def
	 * @param string $slug
	 *
	 * @return array
	 */
	public function build_taxonomy_args( array $def, $slug ) {
		list( $singular, $plural ) = self::names( $def, $slug, 'tax_singular', 'tax_plural' );

		$public       = self::bool( $def, 'tax_public', true );
		$hierarchical = self::bool( $def, 'tax_hierarchical', true );

		$rewrite_slug = self::clean_slug_loose( isset( $def['tax_rewrite_slug'] ) ? $def['tax_rewrite_slug'] : '' );

		$args = array(
			'labels'             => self::apply_label_overrides(
				self::build_tax_labels( $singular, $plural ),
				self::label_overrides( $def, 'tax_label_' )
			),
			'description'        => isset( $def['tax_description'] ) ? (string) $def['tax_description'] : '',
			'public'             => $public,
			'publicly_queryable' => $public,
			'hierarchical'       => $hierarchical,
			'show_ui'            => true,
			'show_admin_column'  => self::bool( $def, 'tax_show_admin_column', true ),
			'show_in_nav_menus'  => self::bool( $def, 'tax_show_in_nav_menus', $public ),
			'show_tagcloud'      => self::bool( $def, 'tax_show_tagcloud', true ),
			'show_in_quick_edit' => self::bool( $def, 'tax_show_in_quick_edit', true ),
			'show_in_rest'       => self::bool( $def, 'tax_show_in_rest', true ),
			'query_var'          => true,
			'rewrite'            => array(
				'slug'         => $rewrite_slug !== '' ? $rewrite_slug : $slug,
				'with_front'   => self::bool( $def, 'tax_with_front', true ),
				'hierarchical' => self::bool( $def, 'tax_rewrite_hierarchical', false ),
			),
		);

		$rest_base = self::clean_slug_loose( isset( $def['tax_rest_base'] ) ? $def['tax_rest_base'] : '' );
		if ( $rest_base !== '' ) {
			$args['rest_base'] = $rest_base;
		}

		// A single-select (radio) term box is the most-requested taxonomy behaviour
		// WordPress has no built-in argument for; 'none' hides the box entirely for
		// taxonomies managed purely in code or via the REST API.
		$meta_box = isset( $def['tax_meta_box'] ) ? (string) $def['tax_meta_box'] : 'default';
		if ( $meta_box === 'radio' ) {
			$args['meta_box_cb'] = array( __CLASS__, 'radio_meta_box' );
		} elseif ( $meta_box === 'none' ) {
			$args['meta_box_cb'] = false;
		}

		$default_term = isset( $def['tax_default_term'] ) ? trim( (string) $def['tax_default_term'] ) : '';
		if ( $default_term !== '' ) {
			$args['default_term'] = array(
				'name' => $default_term,
				'slug' => sanitize_title( $default_term ),
			);
		}

		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Single-select (radio) term meta box
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 * Renders a taxonomy meta box that allows exactly one term.
	 *
	 * Uses the same `tax_input` field names core uses, so saving is handled by
	 * wp_insert_post() with no extra hook: an array of term IDs for a hierarchical
	 * taxonomy, a comma string of names for a flat one.
	 *
	 * @param WP_Post $post
	 * @param array   $box
	 */
	public static function radio_meta_box( $post, $box ) {
		$taxonomy = isset( $box['args']['taxonomy'] ) ? $box['args']['taxonomy'] : '';
		$tax      = $taxonomy ? get_taxonomy( $taxonomy ) : null;

		if ( ! $tax ) {
			return;
		}

		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to assign terms here.', 'fw' ) . '</p>';
			return;
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			printf(
				'<p>%s</p>',
				esc_html( sprintf(
					/* translators: %s: taxonomy plural label */
					__( 'No %s yet.', 'fw' ),
					strtolower( $tax->labels->name )
				) )
			);
			return;
		}

		$assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
		$current  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? (int) $assigned[0] : 0;

		// Hierarchical taxonomies post term IDs as an array; flat ones post a
		// comma-separated string of names.
		$name = $tax->hierarchical
			? 'tax_input[' . $taxonomy . '][]'
			: 'tax_input[' . $taxonomy . ']';

		echo '<div class="fw-cpt-radio-tax"><ul>';

		printf(
			'<li><label><input type="radio" name="%s" value="%s" %s /> <em>%s</em></label></li>',
			esc_attr( $name ),
			$tax->hierarchical ? '0' : '',
			checked( $current, 0, false ),
			esc_html__( 'None', 'fw' )
		);

		foreach ( $terms as $term ) {
			printf(
				'<li><label><input type="radio" name="%s" value="%s" %s /> %s</label></li>',
				esc_attr( $name ),
				esc_attr( $tax->hierarchical ? $term->term_id : $term->name ),
				checked( $current, (int) $term->term_id, false ),
				esc_html( $term->name )
			);
		}

		echo '</ul></div>';
	}

	/* ---------------------------------------------------------------------- *
	 * Archive behaviour
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 * Applies the per-type archive settings to the main archive query.
	 *
	 * Kept in the plugin rather than the theme so it works on any theme: the
	 * template that renders the archive is still resolved by the normal WordPress
	 * template hierarchy (archive-{slug}.php, then archive.php).
	 *
	 * @param WP_Query $q
	 */
	public function _action_pre_get_posts( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_post_type_archive() ) {
			return;
		}

		$post_type = $q->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		$def = $this->ext->find_definition( (string) $post_type );
		if ( ! $def ) {
			return;
		}

		$vars = array();

		if ( ! empty( $def['archive_per_page'] ) && is_numeric( $def['archive_per_page'] ) ) {
			$vars['posts_per_page'] = (int) $def['archive_per_page'];
		}
		if ( ! empty( $def['archive_orderby'] ) ) {
			$vars['orderby'] = (string) $def['archive_orderby'];
		}
		if ( ! empty( $def['archive_order'] ) ) {
			$vars['order'] = (string) $def['archive_order'];
		}

		/**
		 * Filters the query vars applied to a user-created post type's archive, given the post type, definition, and query.
		 *
		 * Filter the query vars applied to a user-created post type's archive.
		 *
		 * @param array    $vars      Query vars to set (may be empty).
		 * @param string   $post_type The post type key.
		 * @param array    $def       The saved definition row.
		 * @param WP_Query $q         The main query.
		 */
		$vars = apply_filters( 'fw_ext_post_types_archive_query', $vars, $post_type, $def, $q );

		foreach ( (array) $vars as $key => $value ) {
			$q->set( $key, $value );
		}
	}

	/**
	 * @internal
	 * Adds a predictable hook for styling user-created types.
	 *
	 * @param string[] $classes
	 *
	 * @return string[]
	 */
	public function _filter_body_class( $classes ) {
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			if ( $post_type && $this->ext->find_definition( (string) $post_type ) ) {
				$classes[] = 'fw-cpt-archive';
				$classes[] = 'fw-cpt-archive-' . sanitize_html_class( $post_type );
			}
		} elseif ( is_singular() ) {
			$post_type = get_post_type();
			if ( $post_type && $this->ext->find_definition( (string) $post_type ) ) {
				$classes[] = 'fw-cpt-single';
				$classes[] = 'fw-cpt-single-' . sanitize_html_class( $post_type );
			}
		}

		return $classes;
	}

	/* ---------------------------------------------------------------------- *
	 * Value helpers (static — the validator and porter reuse them)
	 * ---------------------------------------------------------------------- */

	/**
	 * Rows saved before the Enabled checkbox existed have no key at all, and must
	 * keep registering — so a missing key means enabled.
	 *
	 * @param array  $def
	 * @param string $key
	 *
	 * @return bool
	 */
	public static function is_enabled( array $def, $key = 'enabled' ) {
		return ! array_key_exists( $key, $def ) || (bool) $def[ $key ];
	}

	/**
	 * @param mixed $raw
	 * @param int   $max
	 *
	 * @return string
	 */
	public static function clean_slug( $raw, $max ) {
		return substr( sanitize_key( (string) $raw ), 0, $max );
	}

	/**
	 * A URL slug, which unlike a post type key may contain dashes and slashes.
	 *
	 * @param mixed $raw
	 *
	 * @return string
	 */
	public static function clean_slug_loose( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}

		// Keep path separators so an archive can live at "resources/guides".
		$parts = array_filter( array_map( 'sanitize_title', explode( '/', $raw ) ), 'strlen' );

		return implode( '/', $parts );
	}

	/**
	 * Resolve singular + plural, falling back to a title-cased slug.
	 *
	 * @param array  $def
	 * @param string $slug
	 * @param string $singular_key
	 * @param string $plural_key
	 *
	 * @return string[] [ singular, plural ]
	 */
	public static function names( array $def, $slug, $singular_key, $plural_key ) {
		$singular = isset( $def[ $singular_key ] ) ? trim( (string) $def[ $singular_key ] ) : '';
		$plural   = isset( $def[ $plural_key ] ) ? trim( (string) $def[ $plural_key ] ) : '';

		if ( $singular === '' ) {
			$singular = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}
		if ( $plural === '' ) {
			$plural = $singular . 's';
		}

		return array( $singular, $plural );
	}

	/**
	 * Collect the optional label-override fields out of a definition row.
	 *
	 * They are stored as flat prefixed keys (`label_menu_name`, `tax_label_all_items`,
	 * …) rather than a nested array, because Unyson's `group` container is a layout
	 * wrapper only — it does not nest saved values — so a flat key is the shape that
	 * actually round-trips through the options save path.
	 *
	 * @param array  $def
	 * @param string $prefix
	 *
	 * @return array label key => override
	 */
	public static function label_overrides( array $def, $prefix ) {
		$out = array();
		$len = strlen( $prefix );

		foreach ( $def as $key => $value ) {
			if ( strpos( $key, $prefix ) !== 0 || ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( $value !== '' ) {
				$out[ substr( $key, $len ) ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Merge the optional per-label overrides over the derived label set. Empty
	 * strings are ignored, so a blank override field means "keep the derived one".
	 *
	 * @param array $labels
	 * @param mixed $overrides
	 *
	 * @return array
	 */
	public static function apply_label_overrides( array $labels, $overrides ) {
		if ( ! is_array( $overrides ) ) {
			return $labels;
		}

		foreach ( $overrides as $key => $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( $value !== '' ) {
				$labels[ $key ] = $value;
			}
		}

		return $labels;
	}

	/**
	 * Parse the "attach to post type(s)" value into clean slugs.
	 *
	 * @param mixed $raw
	 *
	 * @return string[]
	 */
	public static function parse_object_types( $raw ) {
		// `multi-select` returns an array of slugs; tolerate a legacy
		// comma-separated string too.
		$items = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		$out = array();
		foreach ( $items as $ot ) {
			$ot = sanitize_key( trim( (string) $ot ) );
			if ( $ot !== '' ) {
				$out[] = $ot;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Read a `checkboxes` value (choice_id => true) into a flat list.
	 *
	 * @param mixed $value
	 *
	 * @return string[]
	 */
	public static function selected_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_keys( array_filter( $value ) );
	}

	/**
	 * Read a `checkbox` boolean with a fallback for rows saved before the field
	 * existed.
	 *
	 * @param array  $def
	 * @param string $key
	 * @param bool   $default
	 *
	 * @return bool
	 */
	public static function bool( $def, $key, $default ) {
		return array_key_exists( $key, $def ) ? (bool) $def[ $key ] : (bool) $default;
	}

	/**
	 * Resolve the menu-icon multi-picker value into a `menu_icon` argument.
	 *
	 * @param mixed $mp
	 *
	 * @return string
	 */
	public static function resolve_menu_icon( $mp ) {
		$default = 'dashicons-admin-post';

		// Backward-compat: earlier rows stored a plain icon class string.
		if ( is_string( $mp ) ) {
			return preg_match( '/dashicons-[a-z0-9-]+/', $mp, $m ) ? $m[0] : $default;
		}
		if ( ! is_array( $mp ) ) {
			return $default;
		}

		$source = isset( $mp['source'] ) ? $mp['source'] : 'dashicon';

		switch ( $source ) {
			case 'image':
				$file = isset( $mp['image']['file'] ) ? $mp['image']['file'] : null;
				if ( is_array( $file ) && ! empty( $file['url'] ) ) {
					return (string) $file['url'];
				}
				return $default;

			case 'svg':
				$code = isset( $mp['svg']['code'] ) ? trim( (string) $mp['svg']['code'] ) : '';
				$code = self::sanitize_svg( $code );
				if ( $code !== '' ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $code );
				}
				return $default;

			case 'none':
				return 'none';

			case 'dashicon':
			default:
				// The icon value is the modern engine's array shape
				// ({ type:'icon-font', 'icon-class':'dashicons dashicons-book' }),
				// but a legacy string may still be stored — read either. A
				// non-Dashicon pick has no dashicons-* token, so it safely falls
				// back to the default.
				$raw  = isset( $mp['dashicon']['icon'] ) ? $mp['dashicon']['icon'] : '';
				$icon = is_array( $raw )
					? ( isset( $raw['icon-class'] ) ? (string) $raw['icon-class'] : '' )
					: (string) $raw;
				return preg_match( '/dashicons-[a-z0-9-]+/', $icon, $m ) ? $m[0] : $default;
		}
	}

	/**
	 * Strip anything scriptable out of pasted SVG markup before it is encoded into
	 * a data URI. An SVG used as a CSS background cannot execute script, so this is
	 * hygiene rather than a live hole — but the markup is stored and re-emitted, and
	 * there is no reason to keep a <script> around.
	 *
	 * @param string $code
	 *
	 * @return string '' if the input is not usable SVG.
	 */
	public static function sanitize_svg( $code ) {
		if ( $code === '' || stripos( $code, '<svg' ) === false ) {
			return '';
		}

		// Drop XML/doctype preamble, script and style blocks, and external entities.
		$code = preg_replace( '/<\?xml.*?\?>/is', '', $code );
		$code = preg_replace( '/<!DOCTYPE.*?>/is', '', $code );
		$code = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $code );
		$code = preg_replace( '#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $code );
		// Inline event handlers and javascript: URLs.
		$code = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $code );
		$code = preg_replace( '/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $code );

		$code = trim( (string) $code );

		return stripos( $code, '<svg' ) !== false ? $code : '';
	}

	/* ---------------------------------------------------------------------- *
	 * Label sets
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $singular
	 * @param string $plural
	 *
	 * @return array
	 */
	public static function build_labels( $singular, $plural ) {
		return array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'add_new'               => __( 'Add New', 'fw' ),
			'add_new_item'          => sprintf( __( 'Add New %s', 'fw' ), $singular ),
			'edit_item'             => sprintf( __( 'Edit %s', 'fw' ), $singular ),
			'new_item'              => sprintf( __( 'New %s', 'fw' ), $singular ),
			'view_item'             => sprintf( __( 'View %s', 'fw' ), $singular ),
			'view_items'            => sprintf( __( 'View %s', 'fw' ), $plural ),
			'all_items'             => sprintf( __( 'All %s', 'fw' ), $plural ),
			'search_items'          => sprintf( __( 'Search %s', 'fw' ), $plural ),
			'not_found'             => sprintf( __( 'No %s found', 'fw' ), strtolower( $plural ) ),
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash', 'fw' ), strtolower( $plural ) ),
			'parent_item_colon'     => sprintf( __( 'Parent %s:', 'fw' ), $singular ),
			'archives'              => sprintf( __( '%s Archives', 'fw' ), $singular ),
			'featured_image'        => __( 'Featured image', 'fw' ),
			'set_featured_image'    => __( 'Set featured image', 'fw' ),
			'remove_featured_image' => __( 'Remove featured image', 'fw' ),
			'use_featured_image'    => __( 'Use as featured image', 'fw' ),
		);
	}

	/**
	 * @param string $singular
	 * @param string $plural
	 *
	 * @return array
	 */
	public static function build_tax_labels( $singular, $plural ) {
		return array(
			'name'                       => $plural,
			'singular_name'              => $singular,
			'menu_name'                  => $plural,
			'all_items'                  => sprintf( __( 'All %s', 'fw' ), $plural ),
			'edit_item'                  => sprintf( __( 'Edit %s', 'fw' ), $singular ),
			'view_item'                  => sprintf( __( 'View %s', 'fw' ), $singular ),
			'update_item'                => sprintf( __( 'Update %s', 'fw' ), $singular ),
			'add_new_item'               => sprintf( __( 'Add New %s', 'fw' ), $singular ),
			'new_item_name'              => sprintf( __( 'New %s Name', 'fw' ), $singular ),
			'parent_item'                => sprintf( __( 'Parent %s', 'fw' ), $singular ),
			'parent_item_colon'          => sprintf( __( 'Parent %s:', 'fw' ), $singular ),
			'search_items'               => sprintf( __( 'Search %s', 'fw' ), $plural ),
			'popular_items'              => sprintf( __( 'Popular %s', 'fw' ), $plural ),
			'separate_items_with_commas' => sprintf( __( 'Separate %s with commas', 'fw' ), strtolower( $plural ) ),
			'add_or_remove_items'        => sprintf( __( 'Add or remove %s', 'fw' ), strtolower( $plural ) ),
			'choose_from_most_used'      => sprintf( __( 'Choose from the most used %s', 'fw' ), strtolower( $plural ) ),
			'not_found'                  => sprintf( __( 'No %s found', 'fw' ), strtolower( $plural ) ),
			'back_to_items'              => sprintf( __( '&larr; Go to %s', 'fw' ), $plural ),
		);
	}
}
