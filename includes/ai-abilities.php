<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for Post Types & Taxonomies.
 *
 *   post-types-list               the defined post types + taxonomies (and whether each is registered),
 *                                 and the ready-made blueprints
 *   post-types-save               create or update a custom post type
 *   post-types-save-taxonomy      create or update a taxonomy
 *   post-types-install-blueprint  install a ready-made blueprint (never overwrites)
 *
 * Every write goes through FW_Extension_Post_Types::save_definitions() — the admin screen's own path,
 * which also schedules the rewrite-rule flush — after snapshotting the extension's settings option,
 * so undo-change puts the definitions back. Rows start from the same defaults the blueprints use.
 */

if ( ! function_exists( 'fw_ext_post_types_ai_register' ) ) :

	/** @return string The option backing this extension's settings (snapshotted for undo). */
	function fw_ext_post_types_ai_option() {
		return 'fw_ext_settings_options:post-types';
	}

	/**
	 * A default definition row from the blueprints' private builders (so it matches the admin exactly).
	 *
	 * @param string $kind pt | tax
	 * @param array  $args
	 * @return array
	 */
	function fw_ext_post_types_ai_default( $kind, array $args ) {
		$m = new ReflectionMethod( 'FW_Post_Types_Blueprints', $kind );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return $kind === 'pt'
			? $m->invoke( null, $args['slug'], $args['singular'], $args['plural'] )
			: $m->invoke( null, $args['slug'], $args['singular'], $args['plural'], (array) $args['object_types'] );
	}

	/**
	 * @param string $slug
	 * @param int    $max
	 * @return string|WP_Error
	 */
	function fw_ext_post_types_ai_slug( $slug, $max ) {
		$clean = FW_Post_Types_Registrar::clean_slug( (string) $slug, $max );
		if ( $clean === '' || $clean !== strtolower( (string) $slug ) ) {
			return new WP_Error( 'fw_pt_ai_slug', sprintf( 'Slug "%s" must be lowercase letters, numbers, dashes or underscores, at most %d characters.', $slug, $max ) );
		}
		return $clean;
	}

	function fw_ext_post_types_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_Post_Types_Blueprints' ) ) {
			return;
		}

		fw_ai_register_ability( 'post-types-list', array(
			'label'       => __( 'List custom post types', 'fw' ),
			'description' => 'The custom post types and taxonomies defined in UnysonPlus (slug, names, supports, page builder, archive, whether registered right now), and the ready-made blueprints post_types_install_blueprint can add (e.g. team members, events, testimonials). Built-in and other plugins\' post types are listed under other_post_types.',
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => function () {
				$ext = fw_ext( 'post-types' );
				$pts = array();
				foreach ( $ext->get_definitions() as $d ) {
					$slug  = (string) ( $d['slug'] ?? '' );
					$pts[] = array(
						'slug'         => $slug,
						'singular'     => (string) ( $d['singular'] ?? '' ),
						'plural'       => (string) ( $d['plural'] ?? '' ),
						'enabled'      => ! empty( $d['enabled'] ),
						'registered'   => post_type_exists( $slug ),
						'supports'     => array_keys( array_filter( (array) ( $d['supports'] ?? array() ) ) ),
						'page_builder' => ! empty( $d['page_builder'] ),
						'public'       => ! empty( $d['public'] ),
						'has_archive'  => ! empty( $d['has_archive'] ),
						'hierarchical' => ! empty( $d['hierarchical'] ),
					);
				}
				$taxes = array();
				foreach ( $ext->get_taxonomy_definitions() as $d ) {
					$taxes[] = array(
						'slug'         => (string) ( $d['tax_slug'] ?? '' ),
						'singular'     => (string) ( $d['tax_singular'] ?? '' ),
						'plural'       => (string) ( $d['tax_plural'] ?? '' ),
						'object_types' => array_values( (array) ( $d['object_types'] ?? array() ) ),
						'hierarchical' => ! empty( $d['tax_hierarchical'] ),
						'registered'   => taxonomy_exists( (string) ( $d['tax_slug'] ?? '' ) ),
					);
				}
				$blueprints = array();
				foreach ( FW_Post_Types_Blueprints::all() as $slug => $b ) {
					$blueprints[] = array(
						'blueprint'   => (string) $slug,
						'title'       => wp_strip_all_tags( (string) ( $b['title'] ?? $b['label'] ?? $slug ) ),
						'description' => wp_strip_all_tags( (string) ( $b['desc'] ?? $b['description'] ?? '' ) ),
					);
				}
				$other = array_values( array_diff( get_post_types( array( 'public' => true ) ), array_column( $pts, 'slug' ) ) );
				return array( 'post_types' => $pts, 'taxonomies' => $taxes, 'blueprints' => $blueprints, 'other_post_types' => $other );
			},
		) );

		fw_ai_register_ability( 'post-types-save', array(
			'label'       => __( 'Create or update a custom post type', 'fw' ),
			'description' => 'Creates (or updates, matched by slug) a custom post type: slug (≤20 chars, lowercase), singular / plural names, supports (title, editor, thumbnail, excerpt, revisions, page-attributes, comments, author, custom-fields), page_builder (edit its items with the page builder), public, has_archive, hierarchical (page-like), menu_icon (a dashicon class such as dashicons-groups), rewrite_slug, description, enabled. It is registered from the next request on (the permalink flush is scheduled). Undo with undo_change.',
			'input'       => array(
				'slug'         => array( 'type' => 'string' ),
				'singular'     => array( 'type' => 'string' ),
				'plural'       => array( 'type' => 'string' ),
				'supports'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'page_builder' => array( 'type' => 'boolean' ),
				'public'       => array( 'type' => 'boolean' ),
				'has_archive'  => array( 'type' => 'boolean' ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'menu_icon'    => array( 'type' => 'string' ),
				'rewrite_slug' => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'enabled'      => array( 'type' => 'boolean' ),
			),
			'required'    => array( 'slug' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => function ( $in ) {
				$ext  = fw_ext( 'post-types' );
				$slug = fw_ext_post_types_ai_slug( $in['slug'], 20 );
				if ( is_wp_error( $slug ) ) {
					return $slug;
				}
				$defs  = $ext->get_definitions();
				$index = null;
				foreach ( $defs as $i => $d ) {
					if ( ( $d['slug'] ?? '' ) === $slug ) {
						$index = $i;
					}
				}
				if ( $index === null ) {
					if ( post_type_exists( $slug ) ) {
						return new WP_Error( 'fw_pt_ai_taken', sprintf( '"%s" is already a post type registered by WordPress or another plugin — pick another slug.', $slug ) );
					}
					if ( empty( $in['singular'] ) || empty( $in['plural'] ) ) {
						return new WP_Error( 'fw_pt_ai_names', 'A new post type needs singular and plural names.' );
					}
					$row = fw_ext_post_types_ai_default( 'pt', array( 'slug' => $slug, 'singular' => $in['singular'], 'plural' => $in['plural'] ) );
				} else {
					$row = $defs[ $index ];
				}
				foreach ( array( 'singular', 'plural', 'description', 'rewrite_slug' ) as $k ) {
					if ( isset( $in[ $k ] ) ) {
						$row[ $k ] = sanitize_text_field( (string) $in[ $k ] );
					}
				}
				foreach ( array( 'page_builder', 'public', 'has_archive', 'hierarchical', 'enabled' ) as $k ) {
					if ( isset( $in[ $k ] ) ) {
						$row[ $k ] = (bool) $in[ $k ];
					}
				}
				if ( isset( $in['public'] ) ) {
					$row['publicly_queryable'] = $row['show_in_nav_menus'] = (bool) $in['public'];
					$row['exclude_from_search'] = ! $in['public'];
				}
				if ( isset( $in['supports'] ) ) {
					$row['supports'] = array_fill_keys( array_map( 'sanitize_key', (array) $in['supports'] ), true );
				}
				if ( ! empty( $in['menu_icon'] ) ) {
					$row['menu_icon']['dashicon']['icon'] = array( 'type' => 'icon-font', 'icon-class' => 'dashicons ' . sanitize_html_class( preg_replace( '/^dashicons\s+/', '', (string) $in['menu_icon'] ) ) );
					$row['menu_icon']['source'] = 'dashicon';
				}
				if ( $index === null ) {
					$defs[] = $row;
				} else {
					$defs[ $index ] = $row;
				}
				$rev = fw_ai_snapshot( array( 'options' => array( fw_ext_post_types_ai_option() ) ), 'unysonplus/post-types-save', sprintf( '%s post type "%s"', $index === null ? 'Created' : 'Updated', $slug ) );
				$ext->save_definitions( array_values( $defs ), $ext->get_taxonomy_definitions() );
				return array(
					'ok'               => true,
					'message'          => sprintf( '%s post type "%s" (%s). It is available from the next page load.', $index === null ? 'Created' : 'Updated', $row['plural'], $slug ),
					'slug'             => $slug,
					'undo_revision_id' => $rev,
				);
			},
		) );

		fw_ai_register_ability( 'post-types-save-taxonomy', array(
			'label'       => __( 'Create or update a taxonomy', 'fw' ),
			'description' => 'Creates (or updates, matched by slug) a taxonomy — categories or tags for one or more post types: slug (≤32 chars), singular / plural names, object_types (post type slugs it applies to), hierarchical (true = categories, false = tags), public, description. Registered from the next request on. Undo with undo_change.',
			'input'       => array(
				'slug'         => array( 'type' => 'string' ),
				'singular'     => array( 'type' => 'string' ),
				'plural'       => array( 'type' => 'string' ),
				'object_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'public'       => array( 'type' => 'boolean' ),
				'description'  => array( 'type' => 'string' ),
			),
			'required'    => array( 'slug' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => function ( $in ) {
				$ext  = fw_ext( 'post-types' );
				$slug = fw_ext_post_types_ai_slug( $in['slug'], 32 );
				if ( is_wp_error( $slug ) ) {
					return $slug;
				}
				$taxes = $ext->get_taxonomy_definitions();
				$index = null;
				foreach ( $taxes as $i => $d ) {
					if ( ( $d['tax_slug'] ?? '' ) === $slug ) {
						$index = $i;
					}
				}
				if ( $index === null ) {
					if ( taxonomy_exists( $slug ) ) {
						return new WP_Error( 'fw_pt_ai_taken', sprintf( '"%s" is already a taxonomy registered elsewhere — pick another slug.', $slug ) );
					}
					if ( empty( $in['singular'] ) || empty( $in['plural'] ) || empty( $in['object_types'] ) ) {
						return new WP_Error( 'fw_pt_ai_names', 'A new taxonomy needs singular and plural names and object_types.' );
					}
					$row = fw_ext_post_types_ai_default( 'tax', array( 'slug' => $slug, 'singular' => $in['singular'], 'plural' => $in['plural'], 'object_types' => $in['object_types'] ) );
				} else {
					$row = $taxes[ $index ];
				}
				$map = array( 'singular' => 'tax_singular', 'plural' => 'tax_plural', 'description' => 'tax_description' );
				foreach ( $map as $k => $col ) {
					if ( isset( $in[ $k ] ) ) {
						$row[ $col ] = sanitize_text_field( (string) $in[ $k ] );
					}
				}
				if ( isset( $in['hierarchical'] ) ) {
					$row['tax_hierarchical'] = (bool) $in['hierarchical'];
				}
				if ( isset( $in['public'] ) ) {
					$row['tax_public'] = (bool) $in['public'];
				}
				if ( isset( $in['object_types'] ) ) {
					$types = array_values( array_filter( array_map( 'sanitize_key', (array) $in['object_types'] ) ) );
					$known = array_merge( array_column( $ext->get_definitions(), 'slug' ), get_post_types() );
					$bad   = array_diff( $types, $known );
					if ( $bad ) {
						return new WP_Error( 'fw_pt_ai_types', 'Unknown post type(s): ' . implode( ', ', $bad ) . '.' );
					}
					$row['object_types'] = $types;
				}
				if ( $index === null ) {
					$taxes[] = $row;
				} else {
					$taxes[ $index ] = $row;
				}
				$rev = fw_ai_snapshot( array( 'options' => array( fw_ext_post_types_ai_option() ) ), 'unysonplus/post-types-save-taxonomy', sprintf( '%s taxonomy "%s"', $index === null ? 'Created' : 'Updated', $slug ) );
				$ext->save_definitions( $ext->get_definitions(), array_values( $taxes ) );
				return array(
					'ok'               => true,
					'message'          => sprintf( '%s taxonomy "%s" (%s). It is available from the next page load.', $index === null ? 'Created' : 'Updated', $row['tax_plural'], $slug ),
					'slug'             => $slug,
					'undo_revision_id' => $rev,
				);
			},
		) );

		fw_ai_register_ability( 'post-types-install-blueprint', array(
			'label'       => __( 'Install a post type blueprint', 'fw' ),
			'description' => 'Adds a ready-made blueprint from post_types_list (a post type with its taxonomies and, where the blueprint has them, fields). Never overwrites anything that exists; reports what was added and skipped. Undo with undo_change.',
			'input'       => array( 'blueprint' => array( 'type' => 'string' ) ),
			'required'    => array( 'blueprint' ),
			'permission'  => 'manage_options',
			'execute'     => function ( $in ) {
				$slug = sanitize_key( (string) $in['blueprint'] );
				if ( ! FW_Post_Types_Blueprints::get( $slug ) ) {
					return new WP_Error( 'fw_pt_ai_bp', 'Unknown blueprint (see post_types_list).' );
				}
				$rev = fw_ai_snapshot( array( 'options' => array( fw_ext_post_types_ai_option(), 'fw_ext_settings_options:custom-fields' ) ), 'unysonplus/post-types-install-blueprint', sprintf( 'Installed blueprint "%s"', $slug ) );
				$r   = FW_Post_Types_Blueprints::install( $slug, fw_ext( 'post-types' ) );
				return array(
					'ok'               => ! empty( $r['added'] ),
					'message'          => $r['added'] ? 'Added: ' . implode( ', ', $r['added'] ) . '. Available from the next page load.' : 'Nothing was added.',
					'added'            => $r['added'],
					'skipped'          => $r['skipped'],
					'undo_revision_id' => $rev,
				);
			},
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_post_types_ai_register' );

endif;
