<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Blueprints — one-click content-type packs.
 *
 * A blueprint installs a coherent set in one action: the post type, the taxonomies
 * that belong with it, and (when the Custom Fields extension is active) a matching
 * field group. Creating a post type is the easy half; wiring up the taxonomy and
 * the six fields that always follow is the tedious half, and it is the same six
 * fields every time.
 *
 * Blueprints only ever ADD. A key that already exists — registered by anything, or
 * present in the saved definitions — is skipped, never overwritten, so installing a
 * blueprint can never damage existing content.
 */
class FW_Post_Types_Blueprints {

	/**
	 * @return array slug => blueprint
	 */
	public static function all() {
		static $cache = null;

		if ( $cache !== null ) {
			return $cache;
		}

		$b = array();

		$b['testimonials'] = array(
			'title'      => __( 'Testimonials', 'fw' ),
			'desc'       => __( 'Customer quotes with an author, role, company and rating. Flat "Testimonial Category" taxonomy for grouping by product or service.', 'fw' ),
			'icon'       => 'dashicons-format-quote',
			'post_types' => array( self::pt( 'testimonial', __( 'Testimonial', 'fw' ), __( 'Testimonials', 'fw' ), array(
				'menu_icon'    => 'dashicons-format-quote',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'  => false,
				'rewrite_slug' => 'testimonials',
			) ) ),
			'taxonomies' => array( self::tax( 'testimonial_category', __( 'Testimonial Category', 'fw' ), __( 'Testimonial Categories', 'fw' ), array( 'testimonial' ) ) ),
			'fields'     => array(
				'title'  => __( 'Testimonial Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Author name', 'fw' ), 'testimonial_author', 'text' ),
					self::field( __( 'Role / title', 'fw' ), 'testimonial_role', 'text' ),
					self::field( __( 'Company', 'fw' ), 'testimonial_company', 'text' ),
					self::field( __( 'Company URL', 'fw' ), 'testimonial_url', 'url' ),
					self::field( __( 'Rating', 'fw' ), 'testimonial_rating', 'slider', array(
						'min' => 1, 'max' => 5, 'step' => 1, 'default' => 5,
					) ),
				),
			),
		);

		$b['team'] = array(
			'title'      => __( 'Team Members', 'fw' ),
			'desc'       => __( 'Staff profiles with a headshot, role, contact details and an optional link to a WordPress user. Hierarchical "Department" taxonomy so the team page can be grouped.', 'fw' ),
			'icon'       => 'dashicons-groups',
			'post_types' => array( self::pt( 'team_member', __( 'Team Member', 'fw' ), __( 'Team', 'fw' ), array(
				'menu_icon'          => 'dashicons-groups',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'rewrite_slug'       => 'team',
				'archive_orderby'    => 'menu_order',
				'archive_order'      => 'ASC',
				'label_featured_image' => __( 'Headshot', 'fw' ),
			) ) ),
			'taxonomies' => array( self::tax( 'department', __( 'Department', 'fw' ), __( 'Departments', 'fw' ), array( 'team_member' ), array(
				'tax_hierarchical' => true,
			) ) ),
			'fields'     => array(
				'title'  => __( 'Profile', 'fw' ),
				'fields' => array(
					self::field( __( 'Job title', 'fw' ), 'member_role', 'text' ),
					self::field( __( 'Email', 'fw' ), 'member_email', 'email' ),
					self::field( __( 'Phone', 'fw' ), 'member_phone', 'text' ),
					self::field( __( 'LinkedIn URL', 'fw' ), 'member_linkedin', 'url' ),
					self::field( __( 'Short bio', 'fw' ), 'member_bio', 'textarea' ),
					self::field( __( 'WordPress user', 'fw' ), 'member_user', 'relation-user', array( 'limit' => 1 ) ),
				),
			),
		);

		$b['case-studies'] = array(
			'title'      => __( 'Case Studies', 'fw' ),
			'desc'       => __( 'Long-form project write-ups with a client, sector, result and related studies. Page Builder enabled, since case studies are usually laid out rather than typed.', 'fw' ),
			'icon'       => 'dashicons-portfolio',
			'post_types' => array( self::pt( 'case_study', __( 'Case Study', 'fw' ), __( 'Case Studies', 'fw' ), array(
				'menu_icon'    => 'dashicons-portfolio',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite_slug' => 'case-studies',
				'page_builder' => true,
			) ) ),
			'taxonomies' => array(
				self::tax( 'case_study_sector', __( 'Sector', 'fw' ), __( 'Sectors', 'fw' ), array( 'case_study' ) ),
				self::tax( 'case_study_service', __( 'Service', 'fw' ), __( 'Services', 'fw' ), array( 'case_study' ) ),
			),
			'fields'     => array(
				'title'  => __( 'Case Study Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Client', 'fw' ), 'case_client', 'text' ),
					self::field( __( 'Project URL', 'fw' ), 'case_url', 'url' ),
					self::field( __( 'Year', 'fw' ), 'case_year', 'text' ),
					self::field( __( 'Headline result', 'fw' ), 'case_result', 'text' ),
					self::field( __( 'Gallery', 'fw' ), 'case_gallery', 'gallery' ),
					self::field( __( 'Related case studies', 'fw' ), 'case_related', 'relation-post', array(
						'source' => array( 'case_study' ), 'limit' => 3,
					) ),
				),
			),
		);

		$b['faq'] = array(
			'title'      => __( 'FAQ', 'fw' ),
			'desc'       => __( 'Questions and answers, ordered manually and grouped by topic. Not public on its own — designed to be pulled into a page rather than browsed.', 'fw' ),
			'icon'       => 'dashicons-editor-help',
			'post_types' => array( self::pt( 'faq', __( 'FAQ', 'fw' ), __( 'FAQs', 'fw' ), array(
				'menu_icon'       => 'dashicons-editor-help',
				'supports'        => array( 'title', 'editor', 'page-attributes' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'has_archive'     => false,
				'archive_orderby' => 'menu_order',
				'archive_order'   => 'ASC',
				'label_menu_name' => __( 'FAQs', 'fw' ),
			) ) ),
			'taxonomies' => array( self::tax( 'faq_topic', __( 'Topic', 'fw' ), __( 'Topics', 'fw' ), array( 'faq' ), array(
				'tax_public' => false,
			) ) ),
		);

		$b['services'] = array(
			'title'      => __( 'Services', 'fw' ),
			'desc'       => __( 'What you offer, with an icon, summary and price. Hierarchical so a service can sit under a parent category, and Page Builder enabled for landing-page style pages.', 'fw' ),
			'icon'       => 'dashicons-hammer',
			'post_types' => array( self::pt( 'service', __( 'Service', 'fw' ), __( 'Services', 'fw' ), array(
				'menu_icon'    => 'dashicons-hammer',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
				'hierarchical' => true,
				'rewrite_slug' => 'services',
				'page_builder' => true,
			) ) ),
			'taxonomies' => array(),
			'fields'     => array(
				'title'  => __( 'Service Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Short summary', 'fw' ), 'service_summary', 'textarea' ),
					self::field( __( 'Price from', 'fw' ), 'service_price', 'text' ),
					self::field( __( 'Icon', 'fw' ), 'service_icon', 'icon' ),
				),
			),
		);

		$b['events'] = array(
			'title'      => __( 'Events', 'fw' ),
			'desc'       => __( 'Dated events with a venue, start and end times, a booking link and a video embed, sorted oldest first so the next one leads. Event Type uses a single-select term box.', 'fw' ),
			'icon'       => 'dashicons-calendar-alt',
			'post_types' => array( self::pt( 'event', __( 'Event', 'fw' ), __( 'Events', 'fw' ), array(
				'menu_icon'      => 'dashicons-calendar-alt',
				'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite_slug'   => 'events',
				'archive_order'  => 'ASC',
			) ) ),
			'taxonomies' => array( self::tax( 'event_type', __( 'Event Type', 'fw' ), __( 'Event Types', 'fw' ), array( 'event' ), array(
				'tax_meta_box' => 'radio',
			) ) ),
			'fields'     => array(
				'title'  => __( 'Event Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Starts', 'fw' ), 'event_start', 'datetime' ),
					self::field( __( 'Ends', 'fw' ), 'event_end', 'datetime' ),
					self::field( __( 'Venue', 'fw' ), 'event_venue', 'text' ),
					self::field( __( 'Address', 'fw' ), 'event_address', 'textarea' ),
					self::field( __( 'Booking URL', 'fw' ), 'event_booking_url', 'url' ),
					self::field( __( 'Video', 'fw' ), 'event_video', 'oembed' ),
				),
			),
		);

		$b['properties'] = array(
			'title'      => __( 'Properties', 'fw' ),
			'desc'       => __( 'Real-estate listings with price, beds, baths, floor area, a map location and a gallery. Property Type is single-select; Location is hierarchical with nested term URLs.', 'fw' ),
			'icon'       => 'dashicons-admin-home',
			'post_types' => array( self::pt( 'property', __( 'Property', 'fw' ), __( 'Properties', 'fw' ), array(
				'menu_icon'    => 'dashicons-admin-home',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite_slug' => 'properties',
			) ) ),
			'taxonomies' => array(
				self::tax( 'property_type', __( 'Property Type', 'fw' ), __( 'Property Types', 'fw' ), array( 'property' ), array(
					'tax_meta_box' => 'radio',
				) ),
				self::tax( 'property_location', __( 'Location', 'fw' ), __( 'Locations', 'fw' ), array( 'property' ), array(
					'tax_hierarchical'         => true,
					'tax_rewrite_hierarchical' => true,
				) ),
				self::tax( 'property_feature', __( 'Feature', 'fw' ), __( 'Features', 'fw' ), array( 'property' ), array(
					'tax_hierarchical' => false,
				) ),
			),
			'fields'     => array(
				'title'  => __( 'Listing Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Price', 'fw' ), 'property_price', 'text' ),
					self::field( __( 'Bedrooms', 'fw' ), 'property_beds', 'number' ),
					self::field( __( 'Bathrooms', 'fw' ), 'property_baths', 'number' ),
					self::field( __( 'Floor area', 'fw' ), 'property_area', 'unit', array( 'units' => 'm2, ft2' ) ),
					self::field( __( 'Address', 'fw' ), 'property_address', 'textarea' ),
					self::field( __( 'Map location', 'fw' ), 'property_map', 'map' ),
					self::field( __( 'Gallery', 'fw' ), 'property_gallery', 'gallery' ),
					self::field( __( 'Virtual tour', 'fw' ), 'property_tour', 'oembed' ),
				),
			),
		);

		$b['jobs'] = array(
			'title'      => __( 'Job Listings', 'fw' ),
			'desc'       => __( 'Open roles with a location, contract type, salary range and closing date. Kept out of site search so old vacancies do not surface.', 'fw' ),
			'icon'       => 'dashicons-id',
			'post_types' => array( self::pt( 'job_listing', __( 'Job', 'fw' ), __( 'Jobs', 'fw' ), array(
				'menu_icon'           => 'dashicons-id',
				'supports'            => array( 'title', 'editor' ),
				'rewrite_slug'        => 'careers',
				'exclude_from_search' => true,
			) ) ),
			'taxonomies' => array(
				self::tax( 'job_department', __( 'Department', 'fw' ), __( 'Departments', 'fw' ), array( 'job_listing' ) ),
				self::tax( 'job_type', __( 'Contract Type', 'fw' ), __( 'Contract Types', 'fw' ), array( 'job_listing' ), array(
					'tax_meta_box' => 'radio',
				) ),
			),
			'fields'     => array(
				'title'  => __( 'Role Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Location', 'fw' ), 'job_location', 'text' ),
					self::field( __( 'Salary range', 'fw' ), 'job_salary', 'text' ),
					self::field( __( 'Closing date', 'fw' ), 'job_closes', 'date' ),
					self::field( __( 'Hiring manager', 'fw' ), 'job_manager', 'relation-user', array( 'limit' => 1 ) ),
					self::field( __( 'Apply URL', 'fw' ), 'job_apply_url', 'url' ),
				),
			),
		);

		$b['locations'] = array(
			'title'      => __( 'Locations', 'fw' ),
			'desc'       => __( 'Branches, stores or venues with an address, a map location, phone and opening hours. Hierarchical so a region can contain its sites.', 'fw' ),
			'icon'       => 'dashicons-location',
			'post_types' => array( self::pt( 'location', __( 'Location', 'fw' ), __( 'Locations', 'fw' ), array(
				'menu_icon'    => 'dashicons-location',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'hierarchical' => true,
				'rewrite_slug' => 'locations',
			) ) ),
			'taxonomies' => array(),
			'fields'     => array(
				'title'  => __( 'Location Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Address', 'fw' ), 'location_address', 'textarea' ),
					self::field( __( 'Phone', 'fw' ), 'location_phone', 'text' ),
					self::field( __( 'Email', 'fw' ), 'location_email', 'email' ),
					self::field( __( 'Opening hours', 'fw' ), 'location_hours', 'textarea' ),
					self::field( __( 'Map location', 'fw' ), 'location_map', 'map' ),
				),
			),
		);

		$b['recipes'] = array(
			'title'      => __( 'Recipes', 'fw' ),
			'desc'       => __( 'Recipes with servings, prep and cook times, repeating ingredient and step lists and a video. Cuisine, Course and Dietary taxonomies for filtering.', 'fw' ),
			'icon'       => 'dashicons-carrot',
			'post_types' => array( self::pt( 'recipe', __( 'Recipe', 'fw' ), __( 'Recipes', 'fw' ), array(
				'menu_icon'          => 'dashicons-carrot',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments' ),
				'rewrite_slug'       => 'recipes',
				'builtin_taxonomies' => array( 'post_tag' => true ),
			) ) ),
			'taxonomies' => array(
				self::tax( 'cuisine', __( 'Cuisine', 'fw' ), __( 'Cuisines', 'fw' ), array( 'recipe' ) ),
				self::tax( 'course', __( 'Course', 'fw' ), __( 'Courses', 'fw' ), array( 'recipe' ), array(
					'tax_meta_box' => 'radio',
				) ),
				self::tax( 'dietary', __( 'Dietary', 'fw' ), __( 'Dietary', 'fw' ), array( 'recipe' ), array(
					'tax_hierarchical' => false,
				) ),
			),
			'fields'     => array(
				'title'  => __( 'Recipe Details', 'fw' ),
				'fields' => array(
					self::field( __( 'Servings', 'fw' ), 'recipe_servings', 'number' ),
					self::field( __( 'Prep time (minutes)', 'fw' ), 'recipe_prep', 'number' ),
					self::field( __( 'Cook time (minutes)', 'fw' ), 'recipe_cook', 'number' ),
					self::field( __( 'Ingredients', 'fw' ), 'recipe_ingredients', 'list' ),
					self::field( __( 'Method', 'fw' ), 'recipe_method', 'list', array( 'multiline' => true, 'add_text' => 'Add step' ) ),
					self::field( __( 'Video', 'fw' ), 'recipe_video', 'oembed' ),
				),
			),
		);

		$b['downloads'] = array(
			'title'      => __( 'Downloads', 'fw' ),
			'desc'       => __( 'A resource library — whitepapers, spec sheets, brochures. Each item carries a file, a version and a gated flag.', 'fw' ),
			'icon'       => 'dashicons-download',
			'post_types' => array( self::pt( 'download', __( 'Download', 'fw' ), __( 'Downloads', 'fw' ), array(
				'menu_icon'    => 'dashicons-download',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite_slug' => 'downloads',
			) ) ),
			'taxonomies' => array( self::tax( 'download_category', __( 'Download Category', 'fw' ), __( 'Download Categories', 'fw' ), array( 'download' ) ) ),
			'fields'     => array(
				'title'  => __( 'File', 'fw' ),
				'fields' => array(
					self::field( __( 'File', 'fw' ), 'download_file', 'file' ),
					self::field( __( 'Version', 'fw' ), 'download_version', 'text' ),
					self::field( __( 'File size', 'fw' ), 'download_size', 'text' ),
					self::field( __( 'Requires an email address', 'fw' ), 'download_gated', 'switch' ),
				),
			),
		);

		/**
		 * Filter the available Post Types blueprints.
		 *
		 * @param array $b slug => blueprint definition.
		 */
		$cache = apply_filters( 'fw_ext_post_types_blueprints', $b );

		return $cache;
	}

	/**
	 * @param string $slug
	 *
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();

		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/* ---------------------------------------------------------------------- *
	 * Install
	 * ---------------------------------------------------------------------- */

	/**
	 * Merge a blueprint into the saved definitions.
	 *
	 * Never overwrites: a key that is already registered, or already present in the
	 * definitions, is reported as skipped and left exactly as it was.
	 *
	 * @param string                  $slug
	 * @param FW_Extension_Post_Types $ext
	 *
	 * @return array {
	 *     @type string[] $added   Human-readable names of what was created.
	 *     @type string[] $skipped Human-readable reasons for what was not.
	 * }
	 */
	public static function install( $slug, $ext ) {
		$blueprint = self::get( $slug );
		$result    = array( 'added' => array(), 'skipped' => array() );

		if ( ! $blueprint ) {
			return $result;
		}

		$post_types = $ext->get_definitions();
		$taxonomies = $ext->get_taxonomy_definitions();

		$existing_pt  = self::existing_keys( $post_types, 'slug', 20 );
		$existing_tax = self::existing_keys( $taxonomies, 'tax_slug', 32 );
		$installed_pt = array();

		foreach ( (array) $blueprint['post_types'] as $def ) {
			$key = $def['slug'];

			if ( in_array( $key, $existing_pt, true ) || post_type_exists( $key ) ) {
				$result['skipped'][] = sprintf(
					/* translators: %s: post type key */
					__( 'Post type "%s" already exists — left untouched.', 'fw' ),
					$key
				);
				continue;
			}

			$post_types[]   = $def;
			$existing_pt[]  = $key;
			$installed_pt[] = $key;
			$result['added'][] = sprintf( __( 'Post type: %1$s (%2$s)', 'fw' ), $def['plural'], $key );
		}

		foreach ( (array) $blueprint['taxonomies'] as $def ) {
			$key = $def['tax_slug'];

			if ( in_array( $key, $existing_tax, true ) || taxonomy_exists( $key ) ) {
				$result['skipped'][] = sprintf(
					/* translators: %s: taxonomy key */
					__( 'Taxonomy "%s" already exists — left untouched.', 'fw' ),
					$key
				);
				continue;
			}

			$taxonomies[]      = $def;
			$existing_tax[]    = $key;
			$result['added'][] = sprintf( __( 'Taxonomy: %1$s (%2$s)', 'fw' ), $def['tax_plural'], $key );
		}

		$ext->save_definitions( $post_types, $taxonomies );

		// The field group only makes sense against a post type we actually created —
		// attaching one to a pre-existing type would silently change that type's edit
		// screen, which is not what "install a blueprint" should ever do.
		if ( ! empty( $blueprint['fields'] ) && $installed_pt ) {
			$added = self::install_field_group( $blueprint['fields'], $installed_pt );
			if ( $added === true ) {
				$result['added'][] = sprintf( __( 'Field group: %s', 'fw' ), $blueprint['fields']['title'] );
			} elseif ( is_string( $added ) ) {
				$result['skipped'][] = $added;
			}
		}

		return $result;
	}

	/**
	 * @param array    $spec
	 * @param string[] $locations
	 *
	 * @return true|string|null true on success, a message on skip, null if silent.
	 */
	private static function install_field_group( array $spec, array $locations ) {
		if ( ! function_exists( 'fw_ext' ) ) {
			return null;
		}

		$cf = fw_ext( 'custom-fields' );

		if ( ! $cf || ! method_exists( $cf, 'get_field_groups' ) ) {
			return __( 'Field group not created — the Custom Fields extension is not active. Activate it and install this blueprint again.', 'fw' );
		}

		$groups = $cf->get_field_groups();

		// Don't create a second identical group if the user re-runs the blueprint.
		foreach ( $groups as $group ) {
			if ( is_array( $group ) && isset( $group['title'] ) && $group['title'] === $spec['title'] ) {
				return sprintf( __( 'Field group "%s" already exists — left untouched.', 'fw' ), $spec['title'] );
			}
		}

		$groups[] = array(
			'title'        => $spec['title'],
			'active'       => true,
			'show_in_rest' => false,
			'location'     => array_values( $locations ),
			'context'      => 'normal',
			'fields'       => $spec['fields'],
		);

		fw_set_db_ext_settings_option( $cf->get_name(), 'field_groups', $groups );

		return true;
	}

	/**
	 * @param array  $rows
	 * @param string $key
	 * @param int    $max
	 *
	 * @return string[]
	 */
	private static function existing_keys( array $rows, $key, $max ) {
		$out = array();

		foreach ( $rows as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = FW_Post_Types_Registrar::clean_slug( isset( $def[ $key ] ) ? $def[ $key ] : '', $max );
			if ( $slug !== '' ) {
				$out[] = $slug;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Definition builders
	 * ---------------------------------------------------------------------- */

	/**
	 * A post type definition row with the schema defaults filled in, so a blueprint
	 * only has to state what makes it different.
	 *
	 * @param string $slug
	 * @param string $singular
	 * @param string $plural
	 * @param array  $overrides
	 *
	 * @return array
	 */
	private static function pt( $slug, $singular, $plural, array $overrides = array() ) {
		$supports = isset( $overrides['supports'] )
			? $overrides['supports']
			: array( 'title', 'editor', 'thumbnail' );
		unset( $overrides['supports'] );

		$menu_icon = isset( $overrides['menu_icon'] ) ? $overrides['menu_icon'] : 'dashicons-admin-post';
		unset( $overrides['menu_icon'] );

		$def = array(
			'enabled'             => true,
			'singular'            => $singular,
			'plural'              => $plural,
			'slug'                => $slug,
			'description'         => '',
			'supports'            => array_fill_keys( $supports, true ),
			'page_builder'        => false,
			'hierarchical'        => false,
			'menu_icon'           => array(
				'source'   => 'dashicon',
				'dashicon' => array(
					'icon' => array(
						'type'       => 'icon-font',
						'icon-class' => 'dashicons ' . $menu_icon,
					),
				),
			),
			'menu_position'       => '',
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => false,
			'show_in_nav_menus'   => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'rest_base'           => '',
			'can_export'          => true,
			'rewrite_slug'        => '',
			'with_front'          => false,
			'has_archive'         => true,
			'archive_slug'        => '',
			'builtin_taxonomies'  => array(),
			'archive_per_page'    => '',
			'archive_orderby'     => '',
			'archive_order'       => '',
		);

		return array_merge( $def, $overrides );
	}

	/**
	 * @param string   $slug
	 * @param string   $singular
	 * @param string   $plural
	 * @param string[] $object_types
	 * @param array    $overrides
	 *
	 * @return array
	 */
	private static function tax( $slug, $singular, $plural, array $object_types, array $overrides = array() ) {
		$def = array(
			'tax_enabled'              => true,
			'tax_singular'             => $singular,
			'tax_plural'               => $plural,
			'tax_slug'                 => $slug,
			'object_types'             => array_values( $object_types ),
			'tax_description'          => '',
			'tax_hierarchical'         => true,
			'tax_default_term'         => '',
			'tax_meta_box'             => 'default',
			'tax_show_admin_column'    => true,
			'tax_show_in_quick_edit'   => true,
			'tax_show_tagcloud'        => true,
			'tax_public'               => true,
			'tax_show_in_nav_menus'    => true,
			'tax_show_in_rest'         => true,
			'tax_rest_base'            => '',
			'tax_rewrite_slug'         => '',
			'tax_with_front'           => false,
			'tax_rewrite_hierarchical' => false,
		);

		return array_merge( $def, $overrides );
	}

	/**
	 * A Custom Fields field row.
	 *
	 * @param string $label
	 * @param string $name
	 * @param string $type
	 *
	 * @return array
	 */
	private static function field( $label, $name, $type, array $attrs = array() ) {
		return array(
			'label'       => $label,
			'name'        => $name,
			'field_type'  => array(
				'type' => $type,
				$type  => $attrs,
			),
			'description' => '',
			'help'        => '',
		);
	}
}
