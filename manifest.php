<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Post Types & Taxonomies', 'fw' );
$manifest['slug']        = 'unysonplus-post-types';
$manifest['description'] = __(
	'Create custom post types and taxonomies from the WordPress admin — no code required. Give each post type a name, key/slug, supported editor features and archive/hierarchy/menu settings; add taxonomies (like Categories or Tags) and attach them to one or more post types. Unyson+ registers everything on every request. Post types created here can also be targeted by the Custom Fields extension.',
	'fw'
);

$manifest['version']    = '1.0.19';
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Post-Types-Extension';
$manifest['display']    = true;
$manifest['standalone'] = true;

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.17 - Blueprints now use the field types Custom Fields gained in 0.1.15
 *          instead of working around them: Events carries real date-and-time
 *          fields and a video embed (the separate free-text "Start time" is
 *          gone), Recipes gets repeating ingredient and step lists, Services a
 *          proper icon picker, Locations and Properties a single map location in
 *          place of separate latitude/longitude text boxes, Properties a floor
 *          area with units and a virtual-tour embed, Testimonials a 1-5 rating
 *          slider, and Team and Jobs an optional link to a WordPress user. Case
 *          Studies gains a related-case-studies relationship field.
 *
 * 1.0.16 - Blueprints, schema portability, and a supported key rename. A new
 *          Blueprints tab installs a whole content type in one click - the post
 *          type, the taxonomies that belong with it, and a matching Custom Fields
 *          group - with ten packs shipped (Testimonials, Team, Case Studies, FAQ,
 *          Services, Events, Properties, Jobs, Locations, Recipes, Downloads) and a
 *          fw_ext_post_types_blueprints filter to add more. A Tools tab adds JSON
 *          export/import that also understands a Custom Post Type UI export (both
 *          the combined file and a single section), a "Get PHP code" generator that
 *          emits the equivalent register_post_type() / register_taxonomy() calls for
 *          a child theme, and a key-rename migration that rewrites post_type on
 *          existing content and re-points attached taxonomies instead of orphaning
 *          them. Blueprints and imports only ever add - an existing key is reported
 *          and left untouched, never overwritten.
 *
 * 1.0.15 - Integration with the rest of Unyson+. A post type row can now enable the
 *          Page Builder directly (previously this meant a second trip to the Page
 *          Builder settings screen to tick the same post type), and the overview
 *          table deep-links to each type's list, "Add new", and the Custom Fields
 *          screen. Per-type archive settings - items per page, order by, direction -
 *          are applied to the main archive query through pre_get_posts, filterable
 *          via fw_ext_post_types_archive_query, and fw-cpt-archive-{key} /
 *          fw-cpt-single-{key} body classes give themes a stable styling hook.
 *
 * 1.0.14 - Content safety and full argument coverage. Rows gain an Enabled toggle
 *          so a type can be switched off without deleting its definition; removing
 *          a row now records what it held and offers a Restore, since removal makes
 *          existing content invisible rather than deleting it. Save-time validation
 *          reports duplicate, reserved, over-length and externally-claimed keys,
 *          post-type/taxonomy key collisions, permalink clashes and taxonomies bound
 *          to nothing, as inline notices instead of failing silently. The popup is
 *          split into tabs and exposes the previously-missing arguments: URL slug,
 *          with_front, archive slug, publicly_queryable, exclude_from_search,
 *          show_in_nav_menus, show_ui, show_in_menu, show_in_admin_bar, rest_base,
 *          can_export, description, the built-in Categories/Tags attachment, and
 *          optional per-label overrides. Taxonomies additionally gain public,
 *          show_tagcloud, show_in_quick_edit, default_term, nested term URLs, and a
 *          single-select (radio) term box.
 *
 * --------------------------------------------------------------------------
 */
