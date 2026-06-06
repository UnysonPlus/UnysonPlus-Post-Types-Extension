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

$manifest['version']    = '1.0.8';
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
