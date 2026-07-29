<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson options arrays rendered on the Post Types admin page.
 *
 * Two `addable-popup` lists — Post Types and Taxonomies — whose popups are split
 * into `tab` containers. The tabs matter: the row now carries ~30 options, and a
 * single flat popup that long is unusable. Containers are layout only, so every
 * leaf id stays flat and the save / enqueue paths are unaffected by the grouping.
 *
 * Field ids added after 1.0.12 are all read through FW_Post_Types_Registrar::bool()
 * with an explicit default, so rows saved by an earlier version keep their previous
 * behaviour without a migration.
 */
class FW_Post_Types_Schema {

	/**
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	public static function page_options( $ext ) {
		return array(
			FW_Extension_Post_Types::OPTION_ID     => self::post_types_list( $ext ),
			FW_Extension_Post_Types::TAX_OPTION_ID => self::taxonomies_list( $ext ),
		);
	}

	/**
	 * The page options wrapped in the standard Unyson metabox-holder layout: one
	 * border-less `group` inside a single `box`, so the two lists read as one panel
	 * without inner row borders. Leaf ids are unchanged, so the save and enqueue
	 * paths keep using page_options().
	 *
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	public static function page_boxes( $ext ) {
		return array(
			'main_box' => array(
				'title'   => '',
				'type'    => 'box',
				'options' => array(
					'group_main' => array(
						'type'    => 'group',
						'options' => self::page_options( $ext ),
					),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Post Types
	 * ---------------------------------------------------------------------- */

	/**
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	private static function post_types_list( $ext ) {
		return array(
			'type'            => 'addable-popup',
			'label'           => __( 'Post Types', 'fw' ),
			'desc'            => __( 'Each row becomes a custom post type.', 'fw' ),
			'template'        => '{{= plural }} <small style="opacity:.6">({{= slug }})</small>',
			'popup-title'     => __( 'Post Type', 'fw' ),
			'add-button-text' => __( 'Add Post Type', 'fw' ),
			'size'            => 'large',
			'sortable'        => true,
			'popup-options'   => array(

				'tab_general' => array(
					'type'    => 'tab',
					'title'   => __( 'General', 'fw' ),
					'options' => array(
						'group_general' => array(
							'type'    => 'group',
							'options' => array(
								'enabled' => array(
									'type'  => 'checkbox',
									'label' => __( 'Enabled', 'fw' ),
									'text'  => __( 'Register this post type', 'fw' ),
									'desc'  => __( 'Turn this off to switch a post type off without deleting it. Its content stays in the database and reappears when you switch it back on — always prefer this over removing the row.', 'fw' ),
									'value' => true,
								),
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
									'desc'            => __( 'Lowercase letters, numbers and underscores. Max 20 characters, e.g. "book". This is permanent — changing it later orphans existing content, so use Tools &rarr; Rename key instead.', 'fw' ),
									'attr'            => array( 'maxlength' => 20 ),
									'dynamic_content' => false,
								),
								'description' => array(
									'type'            => 'textarea',
									'label'           => __( 'Description', 'fw' ),
									'desc'            => __( 'Optional. Shown in the REST schema and by some plugins.', 'fw' ),
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
								'page_builder' => array(
									'type'  => 'checkbox',
									'label' => __( 'Page Builder', 'fw' ),
									'text'  => __( 'Enable the Unyson+ Page Builder on this post type', 'fw' ),
									'desc'  => __( 'Requires the Page Builder extension. Ticking this is equivalent to enabling the post type on the Page Builder settings page — you do not need to do both.', 'fw' ),
									'value' => false,
								),
								'hierarchical' => array(
									'type'  => 'checkbox',
									'label' => __( 'Hierarchical', 'fw' ),
									'text'  => __( 'Behaves like Pages (parent / child) instead of Posts', 'fw' ),
									'value' => false,
								),
								'menu_icon'     => self::menu_icon_option(),
								'menu_position' => array(
									'type'            => 'short-text',
									'label'           => __( 'Menu position', 'fw' ),
									'desc'            => __( 'Optional admin menu order number, e.g. 25 (just below Comments). Leave blank for default.', 'fw' ),
									'dynamic_content' => false,
								),
							),
						),
					),
				),

				'tab_visibility' => array(
					'type'    => 'tab',
					'title'   => __( 'Visibility', 'fw' ),
					'options' => array(
						'group_visibility' => array(
							'type'    => 'group',
							'options' => array(
								'public' => array(
									'type'  => 'checkbox',
									'label' => __( 'Public', 'fw' ),
									'text'  => __( 'Visible on the front-end and queryable', 'fw' ),
									'desc'  => __( 'The master switch. The four settings below default to sensible values derived from it — only change them if you need a non-standard combination.', 'fw' ),
									'value' => true,
								),
								'publicly_queryable' => array(
									'type'  => 'checkbox',
									'label' => __( 'Publicly queryable', 'fw' ),
									'text'  => __( 'Front-end URLs resolve for this post type', 'fw' ),
									'value' => true,
								),
								'exclude_from_search' => array(
									'type'  => 'checkbox',
									'label' => __( 'Exclude from search', 'fw' ),
									'text'  => __( 'Keep these out of site search results', 'fw' ),
									'desc'  => __( 'Useful for a type that is public (so it has its own URLs) but should not compete in search — reusable blocks, landing pages, and so on.', 'fw' ),
									'value' => false,
								),
								'show_in_nav_menus' => array(
									'type'  => 'checkbox',
									'label' => __( 'Available in menus', 'fw' ),
									'text'  => __( 'Offer these in the Appearance &rarr; Menus editor', 'fw' ),
									'value' => true,
								),
								'show_ui' => array(
									'type'  => 'checkbox',
									'label' => __( 'Admin screens', 'fw' ),
									'text'  => __( 'Generate the edit / list screens', 'fw' ),
									'value' => true,
								),
								'show_in_menu' => array(
									'type'  => 'checkbox',
									'label' => __( 'Admin menu item', 'fw' ),
									'text'  => __( 'Show an entry in the admin sidebar', 'fw' ),
									'value' => true,
								),
								'show_in_admin_bar' => array(
									'type'  => 'checkbox',
									'label' => __( 'Admin bar', 'fw' ),
									'text'  => __( 'Offer this under the toolbar\'s "+ New" menu', 'fw' ),
									'value' => true,
								),
								'show_in_rest' => array(
									'type'  => 'checkbox',
									'label' => __( 'Block editor / REST', 'fw' ),
									'text'  => __( 'Enable the Gutenberg block editor and the REST API', 'fw' ),
									'value' => true,
								),
								'rest_base' => array(
									'type'            => 'medium-text',
									'label'           => __( 'REST base', 'fw' ),
									'desc'            => __( 'Optional. The endpoint name under /wp-json/wp/v2/. Defaults to the key. Set it deliberately if anything headless consumes this type — the key is permanent, but a rest_base gives you a stable public name you control.', 'fw' ),
									'dynamic_content' => false,
								),
								'can_export' => array(
									'type'  => 'checkbox',
									'label' => __( 'Exportable', 'fw' ),
									'text'  => __( 'Include in Tools &rarr; Export', 'fw' ),
									'value' => true,
								),
							),
						),
					),
				),

				'tab_urls' => array(
					'type'    => 'tab',
					'title'   => __( 'URLs', 'fw' ),
					'options' => array(
						'group_urls' => array(
							'type'    => 'group',
							'options' => array(
								'rewrite_slug' => array(
									'type'            => 'medium-text',
									'label'           => __( 'URL slug', 'fw' ),
									'desc'            => __( 'Optional. The slug used in permalinks, e.g. "books" for a post type keyed "book". Defaults to the key. Unlike the key this can be changed freely — it only affects URLs.', 'fw' ),
									'dynamic_content' => false,
								),
								'with_front' => array(
									'type'  => 'checkbox',
									'label' => __( 'Use permalink prefix', 'fw' ),
									'text'  => __( 'Prepend the site\'s permalink base to these URLs', 'fw' ),
									'desc'  => __( 'If your permalink structure starts with something like /blog/, leaving this on puts every item of this type under it (/blog/books/…). Turn it off for a clean /books/… — this is usually what you want.', 'fw' ),
									'value' => true,
								),
								'has_archive' => array(
									'type'  => 'checkbox',
									'label' => __( 'Has archive', 'fw' ),
									'text'  => __( 'Enable a post type archive page', 'fw' ),
									'desc'  => __( 'Requires Public to be on — an archive cannot resolve for a non-public type.', 'fw' ),
									'value' => true,
								),
								'archive_slug' => array(
									'type'            => 'medium-text',
									'label'           => __( 'Archive slug', 'fw' ),
									'desc'            => __( 'Optional. A different slug for the archive page than for single items, e.g. single items at /book/… and the archive at /library/. Leave blank to use the URL slug.', 'fw' ),
									'dynamic_content' => false,
								),
							),
						),
					),
				),

				'tab_taxonomies' => array(
					'type'    => 'tab',
					'title'   => __( 'Taxonomies', 'fw' ),
					'options' => array(
						'group_taxonomies' => array(
							'type'    => 'group',
							'options' => array(
								'builtin_taxonomies' => array(
									'type'    => 'checkboxes',
									'label'   => __( 'Built-in taxonomies', 'fw' ),
									'desc'    => __( 'Reuse WordPress\' own Categories and Tags on this post type, sharing the same terms as your posts. To create a separate taxonomy instead, add one in the Taxonomies list below and attach it to this type.', 'fw' ),
									'value'   => array(),
									'choices' => array(
										'category' => __( 'Categories', 'fw' ),
										'post_tag' => __( 'Tags', 'fw' ),
									),
								),
							),
						),
					),
				),

				'tab_archive' => array(
					'type'    => 'tab',
					'title'   => __( 'Archive', 'fw' ),
					'options' => array(
						'group_archive' => array(
							'type'    => 'group',
							'options' => array(
								'archive_per_page' => array(
									'type'            => 'short-text',
									'label'           => __( 'Items per page', 'fw' ),
									'desc'            => __( 'Optional. Overrides the site-wide "Blog pages show at most" setting on this type\'s archive only. Leave blank to inherit.', 'fw' ),
									'dynamic_content' => false,
								),
								'archive_orderby' => array(
									'type'    => 'select',
									'label'   => __( 'Order by', 'fw' ),
									'desc'    => __( 'How archive items are sorted. "Menu order" needs the Page attributes support enabled on the General tab.', 'fw' ),
									'value'   => '',
									'choices' => array(
										''              => __( 'Default (date)', 'fw' ),
										'title'         => __( 'Title', 'fw' ),
										'menu_order'    => __( 'Menu order', 'fw' ),
										'modified'      => __( 'Last modified', 'fw' ),
										'comment_count' => __( 'Comment count', 'fw' ),
										'rand'          => __( 'Random', 'fw' ),
									),
								),
								'archive_order' => array(
									'type'    => 'select',
									'label'   => __( 'Direction', 'fw' ),
									'value'   => '',
									'choices' => array(
										''     => __( 'Default (newest first)', 'fw' ),
										'ASC'  => __( 'Ascending', 'fw' ),
										'DESC' => __( 'Descending', 'fw' ),
									),
								),
							),
						),
					),
				),

				'tab_labels' => array(
					'type'    => 'tab',
					'title'   => __( 'Labels', 'fw' ),
					'options' => array(
						'group_labels' => array(
							'type'    => 'group',
							'options' => self::label_fields( 'label_', array(
								'menu_name'          => array( __( 'Admin menu item', 'fw' ), __( 'Defaults to the plural label. Use this when the menu entry should be shorter than the type name.', 'fw' ) ),
								'all_items'          => array( __( '"All items" link', 'fw' ), '' ),
								'add_new_item'       => array( __( '"Add new" heading', 'fw' ), '' ),
								'edit_item'          => array( __( '"Edit" heading', 'fw' ), '' ),
								'view_item'          => array( __( '"View" link', 'fw' ), '' ),
								'search_items'       => array( __( '"Search" button', 'fw' ), '' ),
								'not_found'          => array( __( '"Nothing found" message', 'fw' ), '' ),
								'archives'           => array( __( 'Archive title', 'fw' ), '' ),
								'featured_image'     => array( __( 'Featured image box', 'fw' ), __( 'e.g. "Cover" for a Book, "Headshot" for a Team Member.', 'fw' ) ),
								'set_featured_image' => array( __( '"Set featured image" link', 'fw' ), '' ),
							) ),
						),
					),
				),
			),
		);
	}

	/**
	 * The menu-icon multi-picker.
	 *
	 * This is an INLINE (non-popover) multi-picker, so per the house convention the
	 * user-visible label lives on the picker sub-option and the top level is
	 * label:false / desc:false. Putting the label on the top level here pushes the
	 * description below every revealed row.
	 *
	 * @return array
	 */
	private static function menu_icon_option() {
		return array(
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
						// Modern icon engine (the legacy FA4 `icon` type is retired).
						// The picker's Dashicons pack covers admin menu icons; a legacy
						// string value like 'dashicons dashicons-admin-post' is migrated
						// to the array shape by the engine, and resolve_menu_icon()
						// reads either shape.
						'type'  => 'icon',
						'label' => __( 'Dashicon', 'fw' ),
						'desc'  => __( 'Pick a Dashicon (auto-colored to match the admin scheme). Other packs are available but only Dashicons apply to the admin menu.', 'fw' ),
						'value' => 'dashicons dashicons-admin-post',
					),
				),
				'image' => array(
					'file' => array(
						'type'  => 'upload',
						'label' => __( 'Image file', 'fw' ),
						'desc'  => __( 'A PNG or JPG (a small square ~20&times;20px reads best). Its URL is used as the menu icon; it will not recolor with the admin scheme.', 'fw' ),
					),
				),
				'svg' => array(
					'code' => array(
						'type'            => 'textarea',
						'label'           => __( 'SVG markup', 'fw' ),
						'desc'            => __( 'Paste raw &lt;svg&gt;&hellip;&lt;/svg&gt; markup. Use fill="currentColor" (or no fill) so it tints with the admin color scheme. Stored as a data URI; script and event handlers are stripped.', 'fw' ),
						'dynamic_content' => false,
					),
				),
				'none' => array(),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Taxonomies
	 * ---------------------------------------------------------------------- */

	/**
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	private static function taxonomies_list( $ext ) {
		return array(
			'type'            => 'addable-popup',
			'label'           => __( 'Taxonomies', 'fw' ),
			'desc'            => __( 'Each row becomes a taxonomy (like Categories or Tags). Set which post type(s) it attaches to — a taxonomy can be shared by several post types.', 'fw' ),
			'template'        => '{{= tax_plural }} <small style="opacity:.6">({{= tax_slug }})</small>',
			'popup-title'     => __( 'Taxonomy', 'fw' ),
			'add-button-text' => __( 'Add Taxonomy', 'fw' ),
			'size'            => 'large',
			'sortable'        => true,
			'popup-options'   => array(

				'tab_tax_general' => array(
					'type'    => 'tab',
					'title'   => __( 'General', 'fw' ),
					'options' => array(
						'group_tax_general' => array(
							'type'    => 'group',
							'options' => array(
								'tax_enabled' => array(
									'type'  => 'checkbox',
									'label' => __( 'Enabled', 'fw' ),
									'text'  => __( 'Register this taxonomy', 'fw' ),
									'desc'  => __( 'Turn this off to switch a taxonomy off without deleting it. Its terms stay in the database.', 'fw' ),
									'value' => true,
								),
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
									'desc'            => __( 'Lowercase letters, numbers and underscores. Max 32 characters, e.g. "genre". Permanent once terms are assigned.', 'fw' ),
									'attr'            => array( 'maxlength' => 32 ),
									'dynamic_content' => false,
								),
								'object_types' => array(
									'type'        => 'multi-select',
									'label'       => __( 'Attach to post types', 'fw' ),
									'desc'        => __( 'Which post types this taxonomy appears on. A taxonomy attached to nothing is not registered, so this is required. Newly added post types show up here after you Save once.', 'fw' ),
									'population'  => 'array',
									'choices'     => self::post_type_choices( $ext ),
									'prepopulate' => 100,
								),
								'tax_description' => array(
									'type'            => 'textarea',
									'label'           => __( 'Description', 'fw' ),
									'desc'            => __( 'Optional. Shown in the REST schema and on the term management screen.', 'fw' ),
									'dynamic_content' => false,
								),
								'tax_hierarchical' => array(
									'type'  => 'checkbox',
									'label' => __( 'Hierarchical', 'fw' ),
									'text'  => __( 'Behaves like Categories (parent / child). Off = like Tags.', 'fw' ),
									'value' => true,
								),
								'tax_default_term' => array(
									'type'            => 'medium-text',
									'label'           => __( 'Default term', 'fw' ),
									'desc'            => __( 'Optional. A term automatically assigned when none is chosen, the way "Uncategorized" works for posts. Created on first use.', 'fw' ),
									'dynamic_content' => false,
								),
							),
						),
					),
				),

				'tab_tax_editor' => array(
					'type'    => 'tab',
					'title'   => __( 'Editor', 'fw' ),
					'options' => array(
						'group_tax_editor' => array(
							'type'    => 'group',
							'options' => array(
								'tax_meta_box' => array(
									'type'    => 'select',
									'label'   => __( 'Term selector', 'fw' ),
									'desc'    => __( 'How the term box appears on the edit screen. "Single select" enforces exactly one term per item — the usual choice for a status, a type, or anything mutually exclusive.', 'fw' ),
									'value'   => 'default',
									'choices' => array(
										'default' => __( 'Default (checkboxes or tag input)', 'fw' ),
										'radio'   => __( 'Single select (radio buttons)', 'fw' ),
										'none'    => __( 'Hidden (managed in code or via REST)', 'fw' ),
									),
								),
								'tax_show_admin_column' => array(
									'type'  => 'checkbox',
									'label' => __( 'Admin column', 'fw' ),
									'text'  => __( 'Show a column for this taxonomy on the post list screen', 'fw' ),
									'value' => true,
								),
								'tax_show_in_quick_edit' => array(
									'type'  => 'checkbox',
									'label' => __( 'Quick Edit', 'fw' ),
									'text'  => __( 'Offer this taxonomy in Quick Edit', 'fw' ),
									'value' => true,
								),
								'tax_show_tagcloud' => array(
									'type'  => 'checkbox',
									'label' => __( 'Tag cloud', 'fw' ),
									'text'  => __( 'Allow this taxonomy in the Tag Cloud widget', 'fw' ),
									'value' => true,
								),
							),
						),
					),
				),

				'tab_tax_visibility' => array(
					'type'    => 'tab',
					'title'   => __( 'Visibility &amp; URLs', 'fw' ),
					'options' => array(
						'group_tax_visibility' => array(
							'type'    => 'group',
							'options' => array(
								'tax_public' => array(
									'type'  => 'checkbox',
									'label' => __( 'Public', 'fw' ),
									'text'  => __( 'Term archives resolve on the front-end', 'fw' ),
									'desc'  => __( 'Turn this off for an internal taxonomy used only to group content in the admin — an editorial workflow state, for example. A non-public taxonomy generates no term URLs.', 'fw' ),
									'value' => true,
								),
								'tax_show_in_nav_menus' => array(
									'type'  => 'checkbox',
									'label' => __( 'Available in menus', 'fw' ),
									'text'  => __( 'Offer terms in the Appearance &rarr; Menus editor', 'fw' ),
									'value' => true,
								),
								'tax_show_in_rest' => array(
									'type'  => 'checkbox',
									'label' => __( 'Block editor / REST', 'fw' ),
									'text'  => __( 'Enable the taxonomy in the block editor and REST API', 'fw' ),
									'value' => true,
								),
								'tax_rest_base' => array(
									'type'            => 'medium-text',
									'label'           => __( 'REST base', 'fw' ),
									'desc'            => __( 'Optional. The endpoint name under /wp-json/wp/v2/. Defaults to the key.', 'fw' ),
									'dynamic_content' => false,
								),
								'tax_rewrite_slug' => array(
									'type'            => 'medium-text',
									'label'           => __( 'URL slug', 'fw' ),
									'desc'            => __( 'Optional. The slug used in term permalinks. Defaults to the key.', 'fw' ),
									'dynamic_content' => false,
								),
								'tax_with_front' => array(
									'type'  => 'checkbox',
									'label' => __( 'Use permalink prefix', 'fw' ),
									'text'  => __( 'Prepend the site\'s permalink base to term URLs', 'fw' ),
									'value' => true,
								),
								'tax_rewrite_hierarchical' => array(
									'type'  => 'checkbox',
									'label' => __( 'Nested term URLs', 'fw' ),
									'text'  => __( 'Include parent terms in the URL', 'fw' ),
									'desc'  => __( 'Gives /genre/fiction/thriller/ instead of /genre/thriller/. Only meaningful on a hierarchical taxonomy.', 'fw' ),
									'value' => false,
								),
							),
						),
					),
				),

				'tab_tax_labels' => array(
					'type'    => 'tab',
					'title'   => __( 'Labels', 'fw' ),
					'options' => array(
						'group_tax_labels' => array(
							'type'    => 'group',
							'options' => self::label_fields( 'tax_label_', array(
								'menu_name'             => array( __( 'Admin menu item', 'fw' ), __( 'Defaults to the plural label.', 'fw' ) ),
								'all_items'             => array( __( '"All items" heading', 'fw' ), '' ),
								'add_new_item'          => array( __( '"Add new" heading', 'fw' ), '' ),
								'edit_item'             => array( __( '"Edit" heading', 'fw' ), '' ),
								'new_item_name'         => array( __( '"New name" field', 'fw' ), '' ),
								'parent_item'           => array( __( '"Parent" field', 'fw' ), '' ),
								'search_items'          => array( __( '"Search" button', 'fw' ), '' ),
								'not_found'             => array( __( '"Nothing found" message', 'fw' ), '' ),
								'choose_from_most_used' => array( __( '"Most used" link', 'fw' ), '' ),
							) ),
						),
					),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Shared
	 * ---------------------------------------------------------------------- */

	/**
	 * Build a set of optional label-override text fields.
	 *
	 * @param string $prefix
	 * @param array  $fields label key => [ label, desc ]
	 *
	 * @return array
	 */
	private static function label_fields( $prefix, array $fields ) {
		$options = array();

		foreach ( $fields as $key => $meta ) {
			$options[ $prefix . $key ] = array(
				'type'            => 'medium-text',
				'label'           => $meta[0],
				'desc'            => $meta[1] !== ''
					? $meta[1]
					: __( 'Optional. Leave blank to use the label generated from the singular / plural names.', 'fw' ),
				'dynamic_content' => false,
			);
		}

		return $options;
	}

	/**
	 * Choices for "attach to post types": everything registered, plus definitions
	 * that are saved but not yet registered (so a type added in the same session is
	 * pickable without a second save).
	 *
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array
	 */
	public static function post_type_choices( $ext ) {
		$extra = array();

		foreach ( $ext->get_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			if ( $slug !== '' ) {
				$extra[ $slug ] = ! empty( $def['singular'] ) ? $def['singular'] : $slug;
			}
		}

		return function_exists( 'fw_upw_post_type_choices' )
			? fw_upw_post_type_choices( array( 'extra' => $extra ) )
			: $extra;
	}
}
