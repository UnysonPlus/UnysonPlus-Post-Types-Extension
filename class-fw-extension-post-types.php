<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Post Types extension.
 *
 * A no-code custom post type + taxonomy creator. The admin page
 * (Unyson+ -> Post Types) renders two Unyson `addable-popup` lists:
 *
 *   - Post Types  (one row per custom post type)
 *   - Taxonomies  (one row per taxonomy; each says which post type key(s) it
 *                  attaches to — a taxonomy can be shared across several types)
 *
 * Each saved row is registered with WordPress on `init` via register_post_type()
 * / register_taxonomy(). The two lists live in the extension settings store
 * under the `post_types` and `taxonomies` option ids
 * (WP option: fw_ext_settings_options:post-types).
 *
 * This extension is also the "anchor" that orders the Unyson+ submenu, so the
 * Post Types / Custom Fields / Shortcodes / Component Presets pages always
 * appear in that sequence regardless of extension load order.
 */
class FW_Extension_Post_Types extends FW_Extension {

	const PARENT_SLUG   = 'fw-extensions';
	const PAGE_SLUG     = 'fw-post-types';
	const CAPABILITY    = 'manage_options';
	const OPTION_ID     = 'post_types';
	const TAX_OPTION_ID = 'taxonomies';
	const NONCE         = 'fw_ext_post_types_save';
	const FLUSH_FLAG    = 'fw_ext_post_types_flush_rewrite';

	/**
	 * Post type keys that WordPress (or common plugins) already own. We never
	 * register over them — doing so would break the core editor or other CPTs.
	 *
	 * @var string[]
	 */
	private $reserved = array(
		'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
		'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
		'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
		'action', 'order', 'theme', 'fw-portfolio',
	);

	/**
	 * Taxonomy keys WordPress (or common plugins) already own.
	 *
	 * @var string[]
	 */
	private $reserved_tax = array(
		'category', 'post_tag', 'nav_menu', 'link_category', 'post_format',
		'post_translations', 'term_translations', 'language', 'author',
	);

	/** @var string|null Hook suffix returned by add_submenu_page() */
	private $hook_suffix = null;

	/**
	 * @internal
	 */
	public function _init() {
		// Register stored post types + taxonomies on both front-end and admin.
		add_action( 'init', array( $this, '_action_register' ), 20 );

		if ( is_admin() ) {
			// Priority 20: after the parent Unyson+ menu (registered at 10),
			// before Shortcodes / Component Presets (registered at 100).
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 20 );

			// Priority 999: after every Unyson+ submenu exists, enforce the
			// desired order. Owned here because this extension is the anchor.
			add_action( 'admin_menu', array( $this, '_action_reorder_submenu' ), 999 );

			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );

			// Expose a Dashicons-only set to the icon (v1) option type, used by
			// the menu-icon picker. Admin-only (the grid renders in admin) and
			// non-intrusive: other icon fields keep their own `set`.
			add_filter( 'fw_option_type_icon_sets', array( $this, '_filter_add_dashicons_set' ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Definitions + registration
	 * ---------------------------------------------------------------------- */

	/**
	 * The saved post-type definitions.
	 *
	 * @return array[]
	 */
	public function get_definitions() {
		$value = fw_get_db_ext_settings_option( $this->get_name(), self::OPTION_ID, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * The saved taxonomy definitions.
	 *
	 * @return array[]
	 */
	public function get_taxonomy_definitions() {
		$value = fw_get_db_ext_settings_option( $this->get_name(), self::TAX_OPTION_ID, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @internal
	 * Registers every saved post type and taxonomy with WordPress.
	 */
	public function _action_register() {
		// Post types first, so taxonomies can attach to them.
		foreach ( $this->get_definitions() as $def ) {
			$this->register_post_type_def( is_array( $def ) ? $def : array() );
		}

		foreach ( $this->get_taxonomy_definitions() as $def ) {
			$this->register_taxonomy_def( is_array( $def ) ? $def : array() );
		}

		// A definition changed on the previous request: flush rewrite rules now
		// that the new objects are registered, so archives/permalinks resolve.
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Sanitises one post-type definition row and registers it. Silently skips
	 * invalid or reserved keys (a malformed row should never fatal the site).
	 *
	 * @param array $def
	 */
	private function register_post_type_def( array $def ) {
		$slug = isset( $def['slug'] ) ? sanitize_key( $def['slug'] ) : '';
		// WordPress post type keys are limited to 20 characters.
		$slug = substr( $slug, 0, 20 );

		if ( $slug === '' || in_array( $slug, $this->reserved, true ) ) {
			return;
		}

		// Don't clobber a post type someone else already registered.
		if ( post_type_exists( $slug ) ) {
			return;
		}

		$singular = isset( $def['singular'] ) ? trim( (string) $def['singular'] ) : '';
		$plural   = isset( $def['plural'] ) ? trim( (string) $def['plural'] ) : '';

		if ( $singular === '' ) {
			$singular = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}
		if ( $plural === '' ) {
			$plural = $singular . 's';
		}

		$supports = $this->selected_keys( isset( $def['supports'] ) ? $def['supports'] : array() );
		if ( empty( $supports ) ) {
			$supports = array( 'title', 'editor' );
		}

		$menu_icon = $this->resolve_menu_icon( isset( $def['menu_icon'] ) ? $def['menu_icon'] : null );

		$menu_position = isset( $def['menu_position'] ) && is_numeric( $def['menu_position'] )
			? (int) $def['menu_position']
			: null;

		$args = array(
			'labels'       => $this->build_labels( $singular, $plural ),
			'public'       => $this->bool( $def, 'public', true ),
			'show_ui'      => true,
			'show_in_menu' => true,
			'has_archive'  => $this->bool( $def, 'has_archive', true ),
			'hierarchical' => $this->bool( $def, 'hierarchical', false ),
			'show_in_rest' => $this->bool( $def, 'show_in_rest', true ),
			'menu_icon'    => $menu_icon,
			'supports'     => $supports,
			'rewrite'      => array( 'slug' => $slug ),
		);

		if ( $menu_position !== null ) {
			$args['menu_position'] = $menu_position;
		}

		/**
		 * Filter the final register_post_type() args for a user-created post type.
		 *
		 * @param array  $args The arguments passed to register_post_type().
		 * @param string $slug The post type key.
		 * @param array  $def  The raw saved definition row.
		 */
		$args = apply_filters( 'fw_ext_post_types_args', $args, $slug, $def );

		register_post_type( $slug, $args );
	}

	/**
	 * Sanitises one taxonomy definition row and registers it, attaching it to
	 * the post type key(s) named in the row.
	 *
	 * @param array $def
	 */
	private function register_taxonomy_def( array $def ) {
		$slug = isset( $def['tax_slug'] ) ? sanitize_key( $def['tax_slug'] ) : '';
		// WordPress taxonomy keys are limited to 32 characters.
		$slug = substr( $slug, 0, 32 );

		if ( $slug === '' || in_array( $slug, $this->reserved_tax, true ) ) {
			return;
		}
		if ( taxonomy_exists( $slug ) ) {
			return;
		}

		$singular = isset( $def['tax_singular'] ) ? trim( (string) $def['tax_singular'] ) : '';
		$plural   = isset( $def['tax_plural'] ) ? trim( (string) $def['tax_plural'] ) : '';

		if ( $singular === '' ) {
			$singular = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}
		if ( $plural === '' ) {
			$plural = $singular . 's';
		}

		$object_types = $this->parse_object_types( isset( $def['object_types'] ) ? $def['object_types'] : '' );

		$args = array(
			'labels'            => $this->build_tax_labels( $singular, $plural ),
			'public'            => true,
			'hierarchical'      => $this->bool( $def, 'tax_hierarchical', true ),
			'show_ui'           => true,
			'show_admin_column' => $this->bool( $def, 'tax_show_admin_column', true ),
			'show_in_rest'      => $this->bool( $def, 'tax_show_in_rest', true ),
			'query_var'         => true,
			'rewrite'           => array( 'slug' => $slug ),
		);

		/**
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
	 * Parse the comma-separated "attach to post type key(s)" field into a clean
	 * list of sanitised post type slugs.
	 *
	 * @param mixed $raw
	 *
	 * @return string[]
	 */
	private function parse_object_types( $raw ) {
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
	 * Resolve the menu-icon multi-picker value into a register_post_type()
	 * `menu_icon` argument: a Dashicons class, an image URL, a base64 SVG data
	 * URI, or 'none'.
	 *
	 * @param mixed $mp The saved `menu_icon` value (multi-picker array).
	 *
	 * @return string
	 */
	private function resolve_menu_icon( $mp ) {
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
				if ( $code !== '' && stripos( $code, '<svg' ) !== false ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $code );
				}
				return $default;

			case 'none':
				return 'none';

			case 'dashicon':
			default:
				$icon = isset( $mp['dashicon']['icon'] ) ? (string) $mp['dashicon']['icon'] : '';
				return preg_match( '/dashicons-[a-z0-9-]+/', $icon, $m ) ? $m[0] : $default;
		}
	}

	/**
	 * Choices for the taxonomy "attach to post types" multi-select: every
	 * registered post type (minus internal ones), plus saved post-type
	 * definitions that may not be registered yet. Keyed by slug.
	 *
	 * @return array
	 */
	private function available_post_type_choices() {
		$skip = array(
			'attachment', 'revision', 'nav_menu_item', 'custom_css',
			'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
			'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
			'wp_font_family', 'wp_font_face', // WP Font Library internals
		);

		$choices = array();

		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, $skip, true ) ) {
				continue;
			}
			$label = ( isset( $pt->labels->singular_name ) && $pt->labels->singular_name )
				? $pt->labels->singular_name
				: $pt->name;
			$choices[ $pt->name ] = $label . ' (' . $pt->name . ')';
		}

		// Include saved definitions that may not be registered yet (e.g. just
		// added in a prior save), so they're pickable here.
		foreach ( $this->get_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = isset( $def['slug'] ) ? substr( sanitize_key( $def['slug'] ), 0, 20 ) : '';
			if ( $slug !== '' && ! isset( $choices[ $slug ] ) ) {
				$label = ! empty( $def['singular'] ) ? $def['singular'] : $slug;
				$choices[ $slug ] = $label . ' (' . $slug . ')';
			}
		}

		return $choices;
	}

	/**
	 * Build a full WordPress labels array from a singular + plural name.
	 *
	 * @param string $singular
	 * @param string $plural
	 *
	 * @return array
	 */
	private function build_labels( $singular, $plural ) {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'add_new'            => __( 'Add New', 'fw' ),
			'add_new_item'       => sprintf( __( 'Add New %s', 'fw' ), $singular ),
			'edit_item'          => sprintf( __( 'Edit %s', 'fw' ), $singular ),
			'new_item'           => sprintf( __( 'New %s', 'fw' ), $singular ),
			'view_item'          => sprintf( __( 'View %s', 'fw' ), $singular ),
			'view_items'         => sprintf( __( 'View %s', 'fw' ), $plural ),
			'all_items'          => sprintf( __( 'All %s', 'fw' ), $plural ),
			'search_items'       => sprintf( __( 'Search %s', 'fw' ), $plural ),
			'not_found'          => sprintf( __( 'No %s found', 'fw' ), strtolower( $plural ) ),
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash', 'fw' ), strtolower( $plural ) ),
			'parent_item_colon'  => sprintf( __( 'Parent %s:', 'fw' ), $singular ),
		);
	}

	/**
	 * Build a full WordPress taxonomy labels array.
	 *
	 * @param string $singular
	 * @param string $plural
	 *
	 * @return array
	 */
	private function build_tax_labels( $singular, $plural ) {
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
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Dashicons set for the icon (v1) option type
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 * Adds a "dashicons" set to the icon (v1) option type. The menu-icon field
	 * sets `'set' => 'dashicons'`, so its grid shows only Dashicons.
	 *
	 * @param array $sets
	 *
	 * @return array
	 */
	public function _filter_add_dashicons_set( $sets ) {
		$sets['dashicons'] = array(
			// WordPress bundles Dashicons; point the picker at its stylesheet.
			'font-style-src'  => includes_url( 'css/dashicons.min.css' ),
			'container-class' => '',
			'groups'          => array(
				'dashicons' => __( 'Dashicons', 'fw' ),
			),
			'icons'           => $this->dashicons_icons(),
		);

		return $sets;
	}

	/**
	 * The full list of Dashicon classes, parsed once from WordPress' bundled
	 * dashicons stylesheet and cached. Each entry is keyed by the full class
	 * string the picker renders on the <i> element, e.g.
	 * "dashicons dashicons-book".
	 *
	 * @return array
	 */
	private function dashicons_icons() {
		$cached = get_transient( 'fw_ext_post_types_dashicons' );
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
		set_transient( 'fw_ext_post_types_dashicons', $icons, WEEK_IN_SECONDS );

		return $icons;
	}

	/**
	 * Read a `checkboxes` value (array of choice_id => true) into a flat list of
	 * selected choice ids.
	 *
	 * @param mixed $value
	 *
	 * @return string[]
	 */
	private function selected_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_keys( array_filter( $value ) );
	}

	/**
	 * Read a `checkbox` boolean from a definition row, with a fallback default
	 * for rows saved before the field existed.
	 *
	 * @param array  $def
	 * @param string $key
	 * @param bool   $default
	 *
	 * @return bool
	 */
	private function bool( $def, $key, $default ) {
		return array_key_exists( $key, $def ) ? (bool) $def[ $key ] : (bool) $default;
	}

	/* ---------------------------------------------------------------------- *
	 * Options schema (the two addable-popup editors)
	 * ---------------------------------------------------------------------- */

	/**
	 * The Unyson options array rendered on the admin page: a Post Types list and
	 * a Taxonomies list, each its own `addable-popup` with its own Add button.
	 *
	 * @return array
	 */
	public function get_page_options() {
		return array(

			/* ===== Post Types list ===== */
			self::OPTION_ID => array(
				'type'            => 'addable-popup',
				'label'           => __( 'Post Types', 'fw' ),
				'desc'            => __( 'Each row becomes a custom post type.', 'fw' ),
				'template'        => '{{= plural }} <small style="opacity:.6">({{= slug }})</small>',
				'popup-title'     => __( 'Post Type', 'fw' ),
				'add-button-text' => __( 'Add Post Type', 'fw' ),
				'size'            => 'large',
				'sortable'        => true,
				'popup-options'   => array(
					'singular' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Singular label', 'fw' ),
						'desc'            => __( 'e.g. "Book". Used for "Add New Book", "Edit Book", etc.', 'fw' ),
						'dynamic_content' => false,
					),
					'plural' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Plural label', 'fw' ),
						'desc'            => __( 'e.g. "Books". Shown as the admin menu item and archive title.', 'fw' ),
						'dynamic_content' => false,
					),
					'slug' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Key / slug', 'fw' ),
						'desc'            => __( 'Lowercase letters, numbers and underscores. Max 20 characters, e.g. "book". This is permanent — changing it later orphans existing content.', 'fw' ),
						'dynamic_content' => false,
					),
					'supports' => array(
						'type'    => 'checkboxes',
						'label'   => __( 'Supports', 'fw' ),
						'desc'    => __( 'Which editor panels this post type provides.', 'fw' ),
						'value'   => array( 'title' => true, 'editor' => true, 'thumbnail' => true ),
						'choices' => array(
							'title'           => __( 'Title', 'fw' ),
							'editor'          => __( 'Editor (content)', 'fw' ),
							'thumbnail'       => __( 'Featured image', 'fw' ),
							'excerpt'         => __( 'Excerpt', 'fw' ),
							'author'          => __( 'Author', 'fw' ),
							'comments'        => __( 'Comments', 'fw' ),
							'revisions'       => __( 'Revisions', 'fw' ),
							'page-attributes' => __( 'Page attributes (order / parent)', 'fw' ),
							'custom-fields'   => __( 'Custom fields (native)', 'fw' ),
						),
					),
					'public' => array(
						'type'  => 'checkbox',
						'label' => __( 'Public', 'fw' ),
						'text'  => __( 'Visible on the front-end and queryable', 'fw' ),
						'value' => true,
					),
					'has_archive' => array(
						'type'  => 'checkbox',
						'label' => __( 'Has archive', 'fw' ),
						'text'  => __( 'Enable a post type archive page', 'fw' ),
						'value' => true,
					),
					'hierarchical' => array(
						'type'  => 'checkbox',
						'label' => __( 'Hierarchical', 'fw' ),
						'text'  => __( 'Behaves like Pages (parent / child) instead of Posts', 'fw' ),
						'value' => false,
					),
					'show_in_rest' => array(
						'type'  => 'checkbox',
						'label' => __( 'Block editor / REST', 'fw' ),
						'text'  => __( 'Enable the Gutenberg block editor and the REST API', 'fw' ),
						'value' => true,
					),
					'menu_icon' => array(
						'type'         => 'multi-picker',
						'label'        => false,
						'desc'         => false,
						'show_borders' => false,
						'picker'       => array(
							'source' => array(
								'type'    => 'select',
								'label'   => __( 'Menu icon', 'fw' ),
								'desc'    => __( 'The admin menu icon. Choose a source, then set it below.', 'fw' ),
								'choices' => array(
									'dashicon' => __( 'Dashicon', 'fw' ),
									'image'    => __( 'Image file (PNG / JPG)', 'fw' ),
									'svg'      => __( 'Custom SVG code', 'fw' ),
									'none'     => __( 'None (style via CSS)', 'fw' ),
								),
								'value'   => 'dashicon',
							),
						),
						'choices'      => array(
							'dashicon' => array(
								'icon' => array(
									'type'  => 'icon',
									'set'   => 'dashicons',
									'label' => __( 'Dashicon', 'fw' ),
									'desc'  => __( 'Auto-colored to match the admin scheme.', 'fw' ),
									'value' => 'dashicons dashicons-admin-post',
								),
							),
							'image' => array(
								'file' => array(
									'type'  => 'upload',
									'label' => __( 'Image file', 'fw' ),
									'desc'  => __( 'A PNG or JPG (a small square ~20×20px reads best). Its URL is used as the menu icon; it will not recolor with the admin scheme.', 'fw' ),
								),
							),
							'svg' => array(
								'code' => array(
									'type'            => 'textarea',
									'label'           => __( 'SVG markup', 'fw' ),
									'desc'            => __( 'Paste raw &lt;svg&gt;…&lt;/svg&gt; markup. Use fill="currentColor" (or no fill) so it tints with the admin color scheme. Stored as a data URI.', 'fw' ),
									'dynamic_content' => false,
								),
							),
							'none' => array(),
						),
					),
					'menu_position' => array(
						'type'            => 'short-text',
						'label'           => __( 'Menu position', 'fw' ),
						'desc'            => __( 'Optional admin menu order number, e.g. 25 (just below Comments). Leave blank for default.', 'fw' ),
						'dynamic_content' => false,
					),
				),
			),

			/* ===== Taxonomies list ===== */
			self::TAX_OPTION_ID => array(
				'type'            => 'addable-popup',
				'label'           => __( 'Taxonomies', 'fw' ),
				'desc'            => __( 'Each row becomes a taxonomy (like Categories or Tags). Set which post type key(s) it attaches to — a taxonomy can be shared by several post types.', 'fw' ),
				'template'        => '{{= tax_plural }} <small style="opacity:.6">({{= tax_slug }})</small>',
				'popup-title'     => __( 'Taxonomy', 'fw' ),
				'add-button-text' => __( 'Add Taxonomy', 'fw' ),
				'size'            => 'large',
				'sortable'        => true,
				'popup-options'   => array(
					'tax_singular' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Singular label', 'fw' ),
						'desc'            => __( 'e.g. "Genre".', 'fw' ),
						'dynamic_content' => false,
					),
					'tax_plural' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Plural label', 'fw' ),
						'desc'            => __( 'e.g. "Genres".', 'fw' ),
						'dynamic_content' => false,
					),
					'tax_slug' => array(
						'type'            => 'medium-text',
						'label'           => __( 'Key / slug', 'fw' ),
						'desc'            => __( 'Lowercase letters, numbers and underscores. Max 32 characters, e.g. "genre". Permanent once content is assigned.', 'fw' ),
						'dynamic_content' => false,
					),
					'object_types' => array(
						'type'        => 'multi-select',
						'label'       => __( 'Attach to post types', 'fw' ),
						'desc'        => __( 'Which post types this taxonomy appears on. Newly added post types show up here after you Save once.', 'fw' ),
						'population'  => 'array',
						'choices'     => $this->available_post_type_choices(),
						'prepopulate' => 100,
					),
					'tax_hierarchical' => array(
						'type'  => 'checkbox',
						'label' => __( 'Hierarchical', 'fw' ),
						'text'  => __( 'Behaves like Categories (parent / child). Off = like Tags.', 'fw' ),
						'value' => true,
					),
					'tax_show_admin_column' => array(
						'type'  => 'checkbox',
						'label' => __( 'Admin column', 'fw' ),
						'text'  => __( 'Show a column for this taxonomy on the post list screen', 'fw' ),
						'value' => true,
					),
					'tax_show_in_rest' => array(
						'type'  => 'checkbox',
						'label' => __( 'Block editor / REST', 'fw' ),
						'text'  => __( 'Enable the taxonomy in the block editor and REST API', 'fw' ),
						'value' => true,
					),
				),
			),
		);
	}

	/**
	 * The page options wrapped in the standard Unyson metabox-holder layout: one
	 * border-less `group` inside a single `box`, so the Post Types and Taxonomies
	 * lists read as one panel without inner row borders (matches the Breadcrumbs /
	 * Page Builder settings look). Leaf option ids are unchanged, so the save and
	 * enqueue paths keep using get_page_options().
	 *
	 * @return array
	 */
	public function get_page_boxes() {
		return array(
			'main_box' => array(
				'title'   => '',
				'type'    => 'box',
				'options' => array(
					'group_main' => array(
						'type'    => 'group',
						'options' => $this->get_page_options(),
					),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Admin menu + page
	 * ---------------------------------------------------------------------- */

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Post Types & Taxonomies', 'fw' ), // page title (browser <title>)
			__( 'Post Types', 'fw' ),              // menu label (kept short so it doesn't wrap)
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			// Handle the save before any output so we can PRG-redirect.
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_save' ) );
		}
	}

	/**
	 * @internal
	 * Re-order the shared Unyson+ submenu into the canonical sequence.
	 *
	 * WordPress orders submenu items by their numeric position key, but the
	 * append path (`position >= count`) makes cross-extension ordering depend on
	 * load order. We sort explicitly instead: known slugs by the desired weight,
	 * unknown slugs keep their original relative order at the end.
	 */
	public function _action_reorder_submenu() {
		global $submenu;

		if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		/**
		 * The leading order of the Unyson+ submenu, by page slug. The parent
		 * slug itself is the "Extensions" landing item and stays on top.
		 *
		 * @param string[] $order
		 */
		$order = apply_filters( 'fw_unysonplus_admin_submenu_order', array(
			self::PARENT_SLUG,        // Extensions (landing)
			self::PAGE_SLUG,          // Post Types
			'fw-custom-fields',       // Custom Fields
			'fw-shortcodes',          // Shortcodes
			'fw-component-presets',   // Component Presets
		) );

		$weight   = array_flip( array_values( $order ) );
		$fallback = count( $weight );

		$rows = array();
		$i    = 0;
		foreach ( $submenu[ self::PARENT_SLUG ] as $item ) {
			$slug   = isset( $item[2] ) ? $item[2] : '';
			$rows[] = array(
				'w'    => isset( $weight[ $slug ] ) ? $weight[ $slug ] : ( $fallback + $i ),
				'i'    => $i, // original index keeps the sort stable
				'item' => $item,
			);
			$i++;
		}

		usort( $rows, function ( $a, $b ) {
			return ( $a['w'] <=> $b['w'] ) ?: ( $a['i'] <=> $b['i'] );
		} );

		$submenu[ self::PARENT_SLUG ] = array_values( wp_list_pluck( $rows, 'item' ) );
	}

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		// Registers + enqueues the Unyson option-editor assets (fw-backend-options
		// plus every option type's JS/CSS, including addable-popup + icon).
		fw()->backend->enqueue_options_static( $this->get_page_options() );
	}

	/**
	 * @internal
	 * Save handler (runs on the page's `load-` hook, before any output).
	 */
	public function _maybe_save() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$values = fw_get_options_values_from_input( $this->get_page_options() );

		$post_types = isset( $values[ self::OPTION_ID ] ) && is_array( $values[ self::OPTION_ID ] )
			? $values[ self::OPTION_ID ]
			: array();
		$taxonomies = isset( $values[ self::TAX_OPTION_ID ] ) && is_array( $values[ self::TAX_OPTION_ID ] )
			? $values[ self::TAX_OPTION_ID ]
			: array();

		fw_set_db_ext_settings_option( $this->get_name(), self::OPTION_ID, $post_types );
		fw_set_db_ext_settings_option( $this->get_name(), self::TAX_OPTION_ID, $taxonomies );

		// Rewrite rules can't be flushed reliably here (the previous objects were
		// already registered on this request's `init`). Defer the flush to the
		// next request, when `init` registers the new set first.
		update_option( self::FLUSH_FLAG, 1 );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'fw-saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$values = array(
			self::OPTION_ID     => $this->get_definitions(),
			self::TAX_OPTION_ID => $this->get_taxonomy_definitions(),
		);
		?>
		<div class="wrap fw-ext-post-types">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Post Types &amp; Taxonomies', 'fw' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create custom post types and taxonomies without writing code. Add a row for each, set its labels, key and features, then save. New post types appear in the admin menu and can be targeted by the Custom Fields extension.', 'fw' ); ?>
			</p>

			<?php if ( isset( $_GET['fw-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Saved.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="" style="margin-top:1.5em">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php echo fw()->backend->render_options( $this->get_page_boxes(), $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
