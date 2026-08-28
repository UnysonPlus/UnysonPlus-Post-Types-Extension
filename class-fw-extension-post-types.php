<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Post Types extension.
 *
 * A no-code custom post type + taxonomy creator. The admin page
 * (Unyson+ -> Post Types) has three tabs:
 *
 *   - Post Types  Two `addable-popup` lists — post types and taxonomies — plus a
 *                 live overview of what is registered and how much content each
 *                 one holds.
 *   - Blueprints  One-click content-type packs (post type + taxonomies + fields).
 *   - Tools       JSON export / import (including CPT UI imports), PHP code
 *                 export, and the post-type key rename migration.
 *
 * Each saved row is registered with WordPress on `init` via register_post_type() /
 * register_taxonomy(). The two lists live in the extension settings store under the
 * `post_types` and `taxonomies` option ids (WP option:
 * fw_ext_settings_options:post-types).
 *
 * This class is the orchestrator only; the work lives in includes/:
 *
 *   FW_Post_Types_Registrar   definitions -> WordPress objects
 *   FW_Post_Types_Schema      the options arrays
 *   FW_Post_Types_Validator   save-time checks that produce user-facing notices
 *   FW_Post_Types_Blueprints  the ready-made packs
 *   FW_Post_Types_Porter      import / export / PHP code / key rename
 *   FW_Post_Types_Admin_Page  the screen and its POST actions
 *   FW_Post_Types_Dashicons   the Dashicons set for the menu-icon picker
 */
class FW_Extension_Post_Types extends FW_Extension {

	const PARENT_SLUG   = 'fw-extensions';
	const PAGE_SLUG     = 'fw-post-types';
	const CAPABILITY    = 'manage_options';
	const OPTION_ID     = 'post_types';
	const TAX_OPTION_ID = 'taxonomies';
	const NONCE         = 'fw_ext_post_types_save';
	const FLUSH_FLAG    = 'fw_ext_post_types_flush_rewrite';

	/** @var FW_Post_Types_Registrar */
	private $registrar;

	/** @var FW_Post_Types_Admin_Page */
	private $admin_page;

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-post-types-dashicons.php';
		require_once $dir . 'class-fw-post-types-registrar.php';
		require_once $dir . 'class-fw-post-types-schema.php';
		require_once $dir . 'class-fw-post-types-validator.php';
		require_once $dir . 'class-fw-post-types-blueprints.php';
		require_once $dir . 'class-fw-post-types-porter.php';
		require_once $dir . 'class-fw-post-types-admin-page.php';

		$this->registrar = new FW_Post_Types_Registrar( $this );

		// Register stored post types + taxonomies on both front-end and admin.
		add_action( 'init', array( $this->registrar, 'register_all' ), 20 );

		// The deferred rewrite flush runs at `wp_loaded`, not inside the `init`
		// callback above: flushing mid-`init` regenerates the rules from whatever has
		// registered so far, silently dropping the rules of any plugin that registers
		// its post types at a later `init` priority.
		add_action( 'wp_loaded', array( $this->registrar, 'maybe_flush' ) );

		if ( ! is_admin() ) {
			add_action( 'pre_get_posts', array( $this->registrar, '_action_pre_get_posts' ) );
			add_filter( 'body_class', array( $this->registrar, '_filter_body_class' ) );
		}

		if ( is_admin() ) {
			$this->admin_page = new FW_Post_Types_Admin_Page( $this );

			// Priority 20: after the parent Unyson+ menu (registered at 10),
			// before Shortcodes / Component Presets (registered at 100).
			add_action( 'admin_menu', array( $this->admin_page, '_action_admin_menu' ), 20 );

			// Priority 999: after every Unyson+ submenu exists, enforce the desired
			// order. Owned here because this extension is the anchor.
			add_action( 'admin_menu', array( $this, '_action_reorder_submenu' ), 999 );

			add_action( 'admin_enqueue_scripts', array( $this->admin_page, '_action_enqueue' ) );

			// Expose a Dashicons-only set to the icon option type, used by the
			// menu-icon picker. Admin-only (the grid renders in admin) and
			// non-intrusive: other icon fields keep their own `set`.
			add_filter( 'fw_option_type_icon_sets', array( 'FW_Post_Types_Dashicons', 'filter_sets' ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Definitions
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
	 * The definition row for a post type key, if we own it.
	 *
	 * @param string $slug
	 *
	 * @return array|null
	 */
	public function find_definition( $slug ) {
		if ( $slug === '' ) {
			return null;
		}

		foreach ( $this->get_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			if ( FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 ) === $slug ) {
				return $def;
			}
		}

		return null;
	}

	/**
	 * Write both lists and arrange the deferred rewrite flush.
	 *
	 * The flush flag is only set when something actually changed — a Save that
	 * altered nothing should not cost the next request a full rewrite regeneration —
	 * and it is stored non-autoloaded, since it is read once and deleted.
	 *
	 * @param array $post_types
	 * @param array $taxonomies
	 */
	public function save_definitions( array $post_types, array $taxonomies ) {
		$prev_post_types = $this->get_definitions();
		$prev_taxonomies = $this->get_taxonomy_definitions();

		fw_set_db_ext_settings_option( $this->get_name(), self::OPTION_ID, $post_types );
		fw_set_db_ext_settings_option( $this->get_name(), self::TAX_OPTION_ID, $taxonomies );

		if ( $prev_post_types !== $post_types || $prev_taxonomies !== $taxonomies ) {
			update_option( self::FLUSH_FLAG, 1, false );
		}

		/**
		 * Fires after post type and taxonomy definitions change, passing new and previous definitions so listeners can invalidate registration-dependent caches.
		 *
		 * Fires after the post type / taxonomy definitions change.
		 *
		 * Use it to invalidate anything keyed on the set of registered post types.
		 *
		 * @param array $post_types      The new post type definitions.
		 * @param array $taxonomies      The new taxonomy definitions.
		 * @param array $prev_post_types The previous post type definitions.
		 * @param array $prev_taxonomies The previous taxonomy definitions.
		 */
		do_action( 'fw_ext_post_types_saved', $post_types, $taxonomies, $prev_post_types, $prev_taxonomies );
	}

	/* ---------------------------------------------------------------------- *
	 * Options schema (delegated)
	 * ---------------------------------------------------------------------- */

	/**
	 * The raw leaf options — what the save and enqueue paths use.
	 *
	 * @return array
	 */
	public function get_page_options() {
		return FW_Post_Types_Schema::page_options( $this );
	}

	/**
	 * The same options wrapped in the standard metabox-holder layout.
	 *
	 * @return array
	 */
	public function get_page_boxes() {
		return FW_Post_Types_Schema::page_boxes( $this );
	}

	/* ---------------------------------------------------------------------- *
	 * Export helpers (used by the PHP code generator)
	 * ---------------------------------------------------------------------- */

	/**
	 * The register_post_type() args a definition would produce.
	 *
	 * Exposed so the porter can print the exact arguments this extension passes,
	 * rather than a hand-written approximation that could drift.
	 *
	 * @param array  $def
	 * @param string $slug
	 *
	 * @return array
	 */
	public function build_args_for_export( array $def, $slug ) {
		return (array) $this->registrar_instance()->build_post_type_args( $def, $slug );
	}

	/**
	 * The register_taxonomy() args a definition would produce.
	 *
	 * @param array  $def
	 * @param string $slug
	 *
	 * @return array
	 */
	public function build_tax_args_for_export( array $def, $slug ) {
		return (array) $this->registrar_instance()->build_taxonomy_args( $def, $slug );
	}

	/**
	 * @return FW_Post_Types_Registrar
	 */
	private function registrar_instance() {
		if ( ! $this->registrar ) {
			$this->registrar = new FW_Post_Types_Registrar( $this );
		}

		return $this->registrar;
	}

	/* ---------------------------------------------------------------------- *
	 * Admin menu
	 * ---------------------------------------------------------------------- */

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 * Re-order the shared Unyson+ submenu into the canonical sequence.
	 *
	 * WordPress orders submenu items by their numeric position key, but the append
	 * path (`position >= count`) makes cross-extension ordering depend on load order.
	 * We sort explicitly instead: known slugs by the desired weight, unknown slugs
	 * keep their original relative order at the end.
	 */
	public function _action_reorder_submenu() {
		global $submenu;

		if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}

		/**
		 * Filters the ordering of Unyson+ admin submenu items by page slug, letting code reorder the submenu.
		 *
		 * The leading order of the Unyson+ submenu, by page slug. The parent slug
		 * itself is the "Extensions" landing item and stays on top.
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
}
