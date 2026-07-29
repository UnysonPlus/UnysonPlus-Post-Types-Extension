<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ -> Post Types screen.
 *
 * Three native `nav-tab-wrapper` tabs (the house convention — no hand-rolled pill
 * UI): the definitions themselves, the Blueprints gallery, and Tools. The options
 * tab renders through fw()->backend->render_options() so each section is a real
 * postbox; the Blueprints and Tools tabs are bespoke UIs framed by hand.
 *
 * All POST actions are handled on the page's `load-` hook, before any output, so
 * every one of them can PRG-redirect and the JSON export can stream a download.
 */
class FW_Post_Types_Admin_Page {

	const TRANSIENT_NOTICES = 'fw_ext_post_types_notices_';
	const TRANSIENT_CODE    = 'fw_ext_post_types_code_';
	const OPTION_REMOVED    = 'fw_ext_post_types_removed';

	/** @var FW_Extension_Post_Types */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	public function __construct( $ext ) {
		$this->ext = $ext;
	}

	/* ---------------------------------------------------------------------- *
	 * Menu + assets
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( FW_Extension_Post_Types::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			FW_Extension_Post_Types::PARENT_SLUG,
			__( 'Post Types & Taxonomies', 'fw' ), // page title (browser <title>)
			__( 'Post Types', 'fw' ),              // menu label (kept short so it doesn't wrap)
			FW_Extension_Post_Types::CAPABILITY,
			FW_Extension_Post_Types::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_action_load' ) );
		}
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		// Registers + enqueues the Unyson option-editor assets (fw-backend-options
		// plus every option type's JS/CSS, including addable-popup + icon).
		fw()->backend->enqueue_options_static( $this->ext->get_page_options() );

		$uri = $this->ext->get_uri();
		$ver = $this->ext->manifest->get_version();

		wp_enqueue_style( 'fw-ext-post-types', $uri . '/static/css/admin.css', array(), $ver );
		wp_enqueue_script( 'fw-ext-post-types', $uri . '/static/js/admin.js', array( 'jquery' ), $ver, true );

		wp_localize_script( 'fw-ext-post-types', 'fwExtPostTypes', array(
			'confirmRemove' => __( "Remove this row?\n\nThe content already saved under it is NOT deleted, but it becomes invisible — no admin menu, no edit screen, and its URLs stop resolving.\n\nTo switch it off safely instead, cancel and untick \"Enabled\" in the row's General tab.", 'fw' ),
			'confirmImport' => __( 'Replace mode discards every post type and taxonomy definition currently on this screen. Continue?', 'fw' ),
			'confirmRename' => __( 'Renaming rewrites the post_type of every item of this type in the database. Back up first if you are unsure. Continue?', 'fw' ),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Request handling
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 * Runs on the page's `load-` hook, before any output.
	 */
	public function _action_load() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( FW_Extension_Post_Types::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( FW_Extension_Post_Types::NONCE );

		$action = isset( $_POST['fw_pt_action'] ) ? sanitize_key( $_POST['fw_pt_action'] ) : 'save';

		switch ( $action ) {
			case 'export':
				FW_Post_Types_Porter::download( $this->ext );
				return; // download() exits.

			case 'php':
				$this->handle_php_export();
				break;

			case 'import':
				$this->handle_import();
				break;

			case 'blueprint':
				$this->handle_blueprint();
				break;

			case 'restore':
				$this->handle_restore();
				break;

			case 'forget':
				$this->handle_forget();
				break;

			case 'rename':
				$this->handle_rename();
				break;

			case 'save':
			default:
				$this->handle_save();
				break;
		}

		$this->redirect( isset( $_POST['fw_pt_tab'] ) ? sanitize_key( $_POST['fw_pt_tab'] ) : 'types' );
	}

	/**
	 * Save the two lists, validate them, and record any removals.
	 */
	private function handle_save() {
		$values = fw_get_options_values_from_input( $this->ext->get_page_options() );

		$post_types = ( isset( $values[ FW_Extension_Post_Types::OPTION_ID ] ) && is_array( $values[ FW_Extension_Post_Types::OPTION_ID ] ) )
			? array_values( $values[ FW_Extension_Post_Types::OPTION_ID ] )
			: array();
		$taxonomies = ( isset( $values[ FW_Extension_Post_Types::TAX_OPTION_ID ] ) && is_array( $values[ FW_Extension_Post_Types::TAX_OPTION_ID ] ) )
			? array_values( $values[ FW_Extension_Post_Types::TAX_OPTION_ID ] )
			: array();

		$prev_post_types = $this->ext->get_definitions();
		$prev_taxonomies = $this->ext->get_taxonomy_definitions();

		// Removals first, while we still have both sides.
		$this->record_removals( $prev_post_types, $post_types, $prev_taxonomies, $taxonomies );

		$this->ext->save_definitions( $post_types, $taxonomies );

		$notices = array( array( 'level' => 'success', 'text' => __( 'Saved.', 'fw' ) ) );

		// Validation never blocks the save — the definitions are stored as entered so
		// nothing is lost. What it does is explain what will not register, which is
		// the whole difference between "nothing happened" and "here is why".
		foreach ( FW_Post_Types_Validator::validate( $post_types, $taxonomies, $prev_post_types, $prev_taxonomies ) as $issue ) {
			$notices[] = array(
				'level' => $issue['level'] === FW_Post_Types_Validator::LEVEL_ERROR ? 'error' : 'warning',
				'text'  => sprintf( '<strong>%s</strong> — %s', esc_html( $issue['label'] ), esc_html( $issue['message'] ) ),
				'raw'   => true,
			);
		}

		$this->add_removal_notices( $notices );
		$this->set_notices( $notices );
	}

	/**
	 * Compare the old and new key sets and stash anything that disappeared, so a
	 * removal is recoverable instead of a one-way door.
	 *
	 * @param array $prev_pt
	 * @param array $new_pt
	 * @param array $prev_tax
	 * @param array $new_tax
	 */
	private function record_removals( array $prev_pt, array $new_pt, array $prev_tax, array $new_tax ) {
		$removed = array();

		$new_pt_keys = array();
		foreach ( $new_pt as $def ) {
			if ( is_array( $def ) ) {
				$new_pt_keys[] = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			}
		}

		foreach ( $prev_pt as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$key = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			if ( $key === '' || in_array( $key, $new_pt_keys, true ) ) {
				continue;
			}

			$removed[] = array(
				'kind'  => 'post_type',
				'key'   => $key,
				'label' => ! empty( $def['plural'] ) ? $def['plural'] : $key,
				'count' => $this->count_posts( $key ),
				'time'  => time(),
				'def'   => $def,
			);
		}

		$new_tax_keys = array();
		foreach ( $new_tax as $def ) {
			if ( is_array( $def ) ) {
				$new_tax_keys[] = FW_Post_Types_Registrar::clean_slug( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '', 32 );
			}
		}

		foreach ( $prev_tax as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$key = FW_Post_Types_Registrar::clean_slug( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '', 32 );
			if ( $key === '' || in_array( $key, $new_tax_keys, true ) ) {
				continue;
			}

			$removed[] = array(
				'kind'  => 'taxonomy',
				'key'   => $key,
				'label' => ! empty( $def['tax_plural'] ) ? $def['tax_plural'] : $key,
				'count' => $this->count_terms( $key ),
				'time'  => time(),
				'def'   => $def,
			);
		}

		if ( empty( $removed ) ) {
			return;
		}

		$store = array_merge( $this->removed_records(), $removed );

		// Keep the list bounded; the oldest entries are the least likely to be wanted.
		if ( count( $store ) > 20 ) {
			$store = array_slice( $store, -20 );
		}

		update_option( self::OPTION_REMOVED, $store, false );

		set_transient( 'fw_ext_post_types_just_removed_' . get_current_user_id(), wp_list_pluck( $removed, 'key' ), 60 );
	}

	/**
	 * @param array[] $notices
	 */
	private function add_removal_notices( array &$notices ) {
		$keys = get_transient( 'fw_ext_post_types_just_removed_' . get_current_user_id() );
		delete_transient( 'fw_ext_post_types_just_removed_' . get_current_user_id() );

		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return;
		}

		foreach ( $this->removed_records() as $record ) {
			if ( ! in_array( $record['key'], $keys, true ) ) {
				continue;
			}

			if ( $record['count'] > 0 ) {
				$notices[] = array(
					'level' => 'warning',
					'raw'   => true,
					'text'  => sprintf(
						'<strong>%s</strong> — %s <a href="#fw-pt-removed">%s</a>',
						esc_html( $record['label'] ),
						esc_html( $record['kind'] === 'post_type'
							? sprintf(
								/* translators: %d: number of items */
								__( 'removed. Its %d item(s) are still in the database, but are now invisible: no admin menu, no edit screen, and the URLs no longer resolve.', 'fw' ),
								$record['count']
							)
							: sprintf(
								/* translators: %d: number of terms */
								__( 'removed. Its %d term(s) are still in the database but no longer appear anywhere.', 'fw' ),
								$record['count']
							)
						),
						esc_html__( 'Restore it', 'fw' )
					),
				);
			}
		}
	}

	private function handle_import() {
		$raw     = isset( $_POST['fw_pt_import_json'] ) ? wp_unslash( $_POST['fw_pt_import_json'] ) : '';
		$replace = ! empty( $_POST['fw_pt_import_replace'] );

		$result  = FW_Post_Types_Porter::import( $raw, $replace, $this->ext );
		$notices = array( array(
			'level' => $result['ok'] ? 'success' : 'error',
			'text'  => $result['message'],
		) );

		foreach ( $result['skipped'] as $line ) {
			$notices[] = array( 'level' => 'warning', 'text' => $line );
		}

		$this->set_notices( $notices );
	}

	private function handle_blueprint() {
		$slug   = isset( $_POST['fw_pt_blueprint'] ) ? sanitize_key( $_POST['fw_pt_blueprint'] ) : '';
		$bp     = FW_Post_Types_Blueprints::get( $slug );

		if ( ! $bp ) {
			$this->set_notices( array( array( 'level' => 'error', 'text' => __( 'Unknown blueprint.', 'fw' ) ) ) );
			return;
		}

		$result  = FW_Post_Types_Blueprints::install( $slug, $this->ext );
		$notices = array();

		if ( $result['added'] ) {
			$notices[] = array(
				'level' => 'success',
				'raw'   => true,
				'text'  => sprintf(
					'<strong>%s</strong> %s<br>%s',
					esc_html( $bp['title'] ),
					esc_html__( 'installed.', 'fw' ),
					esc_html( implode( ' · ', $result['added'] ) )
				),
			);
		} else {
			$notices[] = array( 'level' => 'warning', 'text' => sprintf( __( 'Nothing was added — everything in "%s" already exists.', 'fw' ), $bp['title'] ) );
		}

		foreach ( $result['skipped'] as $line ) {
			$notices[] = array( 'level' => 'warning', 'text' => $line );
		}

		$this->set_notices( $notices );
	}

	private function handle_restore() {
		$key   = isset( $_POST['fw_pt_key'] ) ? sanitize_key( $_POST['fw_pt_key'] ) : '';
		$store = $this->removed_records();
		$kept  = array();
		$found = null;

		// Restore the most recent record for this key.
		foreach ( array_reverse( $store, true ) as $i => $record ) {
			if ( $found === null && $record['key'] === $key ) {
				$found = $record;
				continue;
			}
			$kept[ $i ] = $record;
		}

		if ( ! $found ) {
			$this->set_notices( array( array( 'level' => 'error', 'text' => __( 'That removed definition is no longer available.', 'fw' ) ) ) );
			return;
		}

		ksort( $kept );

		$post_types = $this->ext->get_definitions();
		$taxonomies = $this->ext->get_taxonomy_definitions();

		if ( $found['kind'] === 'post_type' ) {
			$post_types[] = $found['def'];
		} else {
			$taxonomies[] = $found['def'];
		}

		$this->ext->save_definitions( $post_types, $taxonomies );
		update_option( self::OPTION_REMOVED, array_values( $kept ), false );

		$this->set_notices( array( array(
			'level' => 'success',
			'text'  => sprintf( __( '"%s" was restored. Its existing content is visible again.', 'fw' ), $found['label'] ),
		) ) );
	}

	private function handle_forget() {
		$key   = isset( $_POST['fw_pt_key'] ) ? sanitize_key( $_POST['fw_pt_key'] ) : '';
		$store = $this->removed_records();
		$kept  = array();

		foreach ( $store as $record ) {
			if ( $record['key'] !== $key ) {
				$kept[] = $record;
			}
		}

		update_option( self::OPTION_REMOVED, $kept, false );

		$this->set_notices( array( array(
			'level' => 'success',
			'text'  => __( 'Removed from the restore list. No content was deleted.', 'fw' ),
		) ) );
	}

	private function handle_rename() {
		$from = isset( $_POST['fw_pt_rename_from'] ) ? sanitize_key( $_POST['fw_pt_rename_from'] ) : '';
		$to   = isset( $_POST['fw_pt_rename_to'] ) ? sanitize_key( $_POST['fw_pt_rename_to'] ) : '';

		$result = FW_Post_Types_Porter::rename( $from, $to, $this->ext );

		$this->set_notices( array( array(
			'level' => $result['ok'] ? 'success' : 'error',
			'text'  => $result['message'],
		) ) );
	}

	private function handle_php_export() {
		$domain = isset( $_POST['fw_pt_text_domain'] ) ? sanitize_key( wp_unslash( $_POST['fw_pt_text_domain'] ) ) : '';

		set_transient(
			self::TRANSIENT_CODE . get_current_user_id(),
			FW_Post_Types_Porter::php_code( $this->ext, $domain ),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * @param string $tab
	 */
	private function redirect( $tab ) {
		wp_safe_redirect( add_query_arg(
			array(
				'page'    => FW_Extension_Post_Types::PAGE_SLUG,
				'fw-tab'  => $tab !== '' ? $tab : 'types',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Notices
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array[] $notices
	 */
	private function set_notices( array $notices ) {
		set_transient( self::TRANSIENT_NOTICES . get_current_user_id(), $notices, 60 );
	}

	/**
	 * @return array[]
	 */
	private function take_notices() {
		$key      = self::TRANSIENT_NOTICES . get_current_user_id();
		$notices  = get_transient( $key );
		delete_transient( $key );

		return is_array( $notices ) ? $notices : array();
	}

	private function render_notices() {
		foreach ( $this->take_notices() as $notice ) {
			$class = 'notice notice-' . ( isset( $notice['level'] ) ? $notice['level'] : 'info' ) . ' is-dismissible';

			printf(
				'<div class="%s"><p>%s</p></div>',
				esc_attr( $class ),
				empty( $notice['raw'] ) ? esc_html( $notice['text'] ) : wp_kses_post( $notice['text'] )
			);
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Content counts
	 * ---------------------------------------------------------------------- */

	/**
	 * Every item of a post type that a user would consider "their content" —
	 * everything except auto-drafts and attachment children.
	 *
	 * @param string $post_type
	 *
	 * @return int
	 */
	private function count_posts( $post_type ) {
		global $wpdb;

		if ( $post_type === '' ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'auto-draft', 'inherit' )",
			$post_type
		) );
	}

	/**
	 * @param string $taxonomy
	 *
	 * @return int
	 */
	private function count_terms( $taxonomy ) {
		global $wpdb;

		if ( $taxonomy === '' ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			$taxonomy
		) );
	}

	/**
	 * @return array[]
	 */
	private function removed_records() {
		$store = get_option( self::OPTION_REMOVED, array() );

		return is_array( $store ) ? array_values( $store ) : array();
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( FW_Extension_Post_Types::CAPABILITY ) ) {
			return;
		}

		$tabs = array(
			'types'      => __( 'Post Types', 'fw' ),
			'blueprints' => __( 'Blueprints', 'fw' ),
			'tools'      => __( 'Tools', 'fw' ),
		);

		$active = isset( $_GET['fw-tab'] ) ? sanitize_key( $_GET['fw-tab'] ) : 'types';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'types';
		}
		?>
		<div class="wrap fw-ext-post-types">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Post Types &amp; Taxonomies', 'fw' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create custom post types and taxonomies without writing code. Start from a blueprint, or add a row for each and set its labels, key and features. New post types appear in the admin menu and can be targeted by the Custom Fields extension.', 'fw' ); ?>
			</p>

			<?php $this->render_notices(); ?>

			<h2 class="nav-tab-wrapper fw-post-types-tabs">
				<?php foreach ( $tabs as $id => $title ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>"
					   class="nav-tab<?php echo $id === $active ? ' nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php endforeach; ?>
			</h2>

			<div class="fw-pt-panel<?php echo $active === 'types' ? ' is-active' : ''; ?>" data-panel="types">
				<?php $this->render_types_tab(); ?>
			</div>

			<div class="fw-pt-panel<?php echo $active === 'blueprints' ? ' is-active' : ''; ?>" data-panel="blueprints">
				<?php $this->render_blueprints_tab(); ?>
			</div>

			<div class="fw-pt-panel<?php echo $active === 'tools' ? ' is-active' : ''; ?>" data-panel="tools">
				<?php $this->render_tools_tab(); ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Types */

	private function render_types_tab() {
		$values = array(
			FW_Extension_Post_Types::OPTION_ID     => $this->ext->get_definitions(),
			FW_Extension_Post_Types::TAX_OPTION_ID => $this->ext->get_taxonomy_definitions(),
		);

		$this->render_overview();
		?>
		<form method="post" action="" class="fw-pt-form">
			<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
			<input type="hidden" name="fw_pt_action" value="save">
			<input type="hidden" name="fw_pt_tab" value="types">
			<?php echo fw()->backend->render_options( $this->ext->get_page_boxes(), $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
			</p>
		</form>
		<?php
		$this->render_removed();
	}

	/**
	 * A live picture of what is actually registered, with content counts.
	 *
	 * The definitions list alone cannot answer the two questions people actually
	 * have — "is this thing on?" and "how much is in it?" — because a row can be
	 * disabled, or silently losing to another plugin's claim on the same key.
	 */
	private function render_overview() {
		$post_types = $this->ext->get_definitions();
		$taxonomies = $this->ext->get_taxonomy_definitions();

		if ( empty( $post_types ) && empty( $taxonomies ) ) {
			?>
			<div class="fw-pt-empty">
				<p><strong><?php esc_html_e( 'No post types yet.', 'fw' ); ?></strong></p>
				<p><?php esc_html_e( 'Add one below, or open the Blueprints tab to install a ready-made set — Testimonials, Team, Events, Properties and more — complete with their taxonomies and fields.', 'fw' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped fw-pt-overview">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Key', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Content', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $post_types as $def ) {
				if ( is_array( $def ) ) {
					$this->render_overview_row( $def, 'post_type' );
				}
			}
			foreach ( $taxonomies as $def ) {
				if ( is_array( $def ) ) {
					$this->render_overview_row( $def, 'taxonomy' );
				}
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array  $def
	 * @param string $kind
	 */
	private function render_overview_row( array $def, $kind ) {
		$is_pt = ( $kind === 'post_type' );

		$key     = FW_Post_Types_Registrar::clean_slug( $is_pt ? ( isset( $def['slug'] ) ? $def['slug'] : '' ) : ( isset( $def['tax_slug'] ) ? $def['tax_slug'] : '' ), $is_pt ? 20 : 32 );
		$label   = $is_pt
			? ( ! empty( $def['plural'] ) ? $def['plural'] : $key )
			: ( ! empty( $def['tax_plural'] ) ? $def['tax_plural'] : $key );
		$enabled = FW_Post_Types_Registrar::is_enabled( $def, $is_pt ? 'enabled' : 'tax_enabled' );
		$exists  = $key !== '' && ( $is_pt ? post_type_exists( $key ) : taxonomy_exists( $key ) );

		if ( $key === '' ) {
			$status = array( 'error', __( 'No key', 'fw' ) );
		} elseif ( ! $enabled ) {
			$status = array( 'off', __( 'Disabled', 'fw' ) );
		} elseif ( $exists ) {
			$status = array( 'on', __( 'Registered', 'fw' ) );
		} else {
			$status = array( 'error', __( 'Not registered', 'fw' ) );
		}

		$count = $key === '' ? 0 : ( $is_pt ? $this->count_posts( $key ) : $this->count_terms( $key ) );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $label ); ?></strong>
				<span class="fw-pt-kind"><?php echo esc_html( $is_pt ? __( 'Post type', 'fw' ) : __( 'Taxonomy', 'fw' ) ); ?></span>
			</td>
			<td><code><?php echo esc_html( $key !== '' ? $key : '—' ); ?></code></td>
			<td><span class="fw-pt-status fw-pt-status--<?php echo esc_attr( $status[0] ); ?>"><?php echo esc_html( $status[1] ); ?></span></td>
			<td>
				<?php
				echo esc_html( $is_pt
					/* translators: %d: number of items */
					? sprintf( _n( '%d item', '%d items', $count, 'fw' ), $count )
					/* translators: %d: number of terms */
					: sprintf( _n( '%d term', '%d terms', $count, 'fw' ), $count )
				);
				?>
			</td>
			<td class="fw-pt-actions">
				<?php if ( $exists && $is_pt ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $key ) ); ?>"><?php esc_html_e( 'View all', 'fw' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $key ) ); ?>"><?php esc_html_e( 'Add new', 'fw' ); ?></a>
					<?php if ( function_exists( 'fw_ext' ) && fw_ext( 'custom-fields' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fw-custom-fields' ) ); ?>"><?php esc_html_e( 'Fields', 'fw' ); ?></a>
					<?php endif; ?>
				<?php elseif ( $exists ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $key ) ); ?>"><?php esc_html_e( 'Manage terms', 'fw' ); ?></a>
				<?php else : ?>
					<span class="fw-pt-muted">&mdash;</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Removed definitions that still have content behind them.
	 */
	private function render_removed() {
		$records = $this->removed_records();

		if ( empty( $records ) ) {
			return;
		}
		?>
		<div id="fw-pt-removed" class="fw-pt-removed metabox-holder">
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Recently removed', 'fw' ); ?></h2></div>
				<div class="inside">
					<p class="description">
						<?php esc_html_e( 'Definitions removed from the lists above. Removing a definition never deletes content — it only stops registering the type, which makes the content invisible. Restore one to bring its content back exactly as it was.', 'fw' ); ?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'fw' ); ?></th>
								<th><?php esc_html_e( 'Key', 'fw' ); ?></th>
								<th><?php esc_html_e( 'Orphaned content', 'fw' ); ?></th>
								<th><?php esc_html_e( 'Removed', 'fw' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( array_reverse( $records ) as $record ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $record['label'] ); ?></strong></td>
								<td><code><?php echo esc_html( $record['key'] ); ?></code></td>
								<td>
									<?php if ( $record['count'] > 0 ) : ?>
										<strong><?php echo esc_html( number_format_i18n( $record['count'] ) ); ?></strong>
										<?php echo esc_html( $record['kind'] === 'post_type' ? __( 'item(s) hidden', 'fw' ) : __( 'term(s) hidden', 'fw' ) ); ?>
									<?php else : ?>
										<span class="fw-pt-muted"><?php esc_html_e( 'None', 'fw' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( human_time_diff( $record['time'] ) . ' ' . __( 'ago', 'fw' ) ); ?></td>
								<td>
									<form method="post" action="" class="fw-pt-inline-form">
										<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
										<input type="hidden" name="fw_pt_tab" value="types">
										<input type="hidden" name="fw_pt_key" value="<?php echo esc_attr( $record['key'] ); ?>">
										<button type="submit" name="fw_pt_action" value="restore" class="button button-small button-primary"><?php esc_html_e( 'Restore', 'fw' ); ?></button>
										<button type="submit" name="fw_pt_action" value="forget" class="button button-small"><?php esc_html_e( 'Dismiss', 'fw' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------- Blueprints */

	private function render_blueprints_tab() {
		$installed_pt = array();
		foreach ( $this->ext->get_definitions() as $def ) {
			if ( is_array( $def ) ) {
				$installed_pt[] = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			}
		}
		?>
		<p class="description fw-pt-intro">
			<?php esc_html_e( 'A blueprint installs a whole content type in one click: the post type, the taxonomies that belong with it, and a matching Custom Fields group. Blueprints only ever add — anything that already exists is left exactly as it is.', 'fw' ); ?>
		</p>

		<div class="fw-pt-blueprints">
			<?php foreach ( FW_Post_Types_Blueprints::all() as $slug => $bp ) : ?>
				<?php
				$keys      = wp_list_pluck( $bp['post_types'], 'slug' );
				$is_there  = (bool) array_intersect( $keys, $installed_pt );
				$tax_count = count( $bp['taxonomies'] );
				$fld_count = empty( $bp['fields'] ) ? 0 : count( $bp['fields']['fields'] );
				?>
				<div class="fw-pt-blueprint<?php echo $is_there ? ' is-installed' : ''; ?>">
					<div class="fw-pt-blueprint__head">
						<span class="dashicons <?php echo esc_attr( $bp['icon'] ); ?>"></span>
						<h3><?php echo esc_html( $bp['title'] ); ?></h3>
					</div>
					<p class="fw-pt-blueprint__desc"><?php echo esc_html( $bp['desc'] ); ?></p>
					<ul class="fw-pt-blueprint__meta">
						<li><?php echo '<code>' . implode( '</code> <code>', array_map( 'esc_html', $keys ) ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
						<?php if ( $tax_count ) : ?>
							<li><?php printf( esc_html( _n( '%d taxonomy', '%d taxonomies', $tax_count, 'fw' ) ), (int) $tax_count ); ?></li>
						<?php endif; ?>
						<?php if ( $fld_count ) : ?>
							<li><?php printf( esc_html( _n( '%d custom field', '%d custom fields', $fld_count, 'fw' ) ), (int) $fld_count ); ?></li>
						<?php endif; ?>
					</ul>
					<form method="post" action="">
						<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
						<input type="hidden" name="fw_pt_action" value="blueprint">
						<input type="hidden" name="fw_pt_tab" value="types">
						<input type="hidden" name="fw_pt_blueprint" value="<?php echo esc_attr( $slug ); ?>">
						<button type="submit" class="button<?php echo $is_there ? '' : ' button-primary'; ?>">
							<?php echo $is_there ? esc_html__( 'Already installed', 'fw' ) : esc_html__( 'Install', 'fw' ); ?>
						</button>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Tools */

	private function render_tools_tab() {
		$code = get_transient( self::TRANSIENT_CODE . get_current_user_id() );
		if ( $code ) {
			delete_transient( self::TRANSIENT_CODE . get_current_user_id() );
		}

		$rename_choices = array();
		foreach ( $this->ext->get_definitions() as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$key = FW_Post_Types_Registrar::clean_slug( isset( $def['slug'] ) ? $def['slug'] : '', 20 );
			if ( $key !== '' ) {
				$rename_choices[ $key ] = ( ! empty( $def['plural'] ) ? $def['plural'] : $key ) . ' (' . $key . ')';
			}
		}
		?>
		<div class="metabox-holder fw-pt-tools">

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Export', 'fw' ); ?></h2></div>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'Download every post type and taxonomy definition as JSON. Use it to move a schema to another site, to keep it in version control, or to bundle it with a theme.', 'fw' ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
						<input type="hidden" name="fw_pt_tab" value="tools">
						<button type="submit" name="fw_pt_action" value="export" class="button button-primary"><?php esc_html_e( 'Download JSON', 'fw' ); ?></button>
					</form>
				</div>
			</div>

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Import', 'fw' ); ?></h2></div>
				<div class="inside">
					<p class="description">
						<?php esc_html_e( 'Paste an Unyson+ export, or a Custom Post Type UI export — both the combined file and a single post type / taxonomy section are understood. Existing keys are never overwritten.', 'fw' ); ?>
					</p>
					<form method="post" action="" class="fw-pt-import-form">
						<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
						<input type="hidden" name="fw_pt_action" value="import">
						<input type="hidden" name="fw_pt_tab" value="tools">
						<textarea name="fw_pt_import_json" rows="10" class="large-text code" placeholder="{ &quot;post_types&quot;: [ … ] }"></textarea>
						<p>
							<label>
								<input type="checkbox" name="fw_pt_import_replace" value="1" class="fw-pt-replace">
								<?php esc_html_e( 'Replace everything instead of adding to it', 'fw' ); ?>
							</label>
						</p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'fw' ); ?></button>
					</form>
				</div>
			</div>

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Get PHP code', 'fw' ); ?></h2></div>
				<div class="inside">
					<p class="description">
						<?php esc_html_e( 'Generate the register_post_type() and register_taxonomy() calls equivalent to these definitions, ready to paste into a child theme. This extension is only a UI over those exact calls — nothing here is locked in, and content created through the extension keeps working once the code takes over.', 'fw' ); ?>
					</p>
					<form method="post" action="">
						<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
						<input type="hidden" name="fw_pt_action" value="php">
						<input type="hidden" name="fw_pt_tab" value="tools">
						<p>
							<label>
								<?php esc_html_e( 'Text domain', 'fw' ); ?>
								<input type="text" name="fw_pt_text_domain" value="your-theme" class="regular-text">
							</label>
						</p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate', 'fw' ); ?></button>
					</form>
					<?php if ( $code ) : ?>
						<textarea rows="20" class="large-text code fw-pt-code" readonly onclick="this.select()"><?php echo esc_textarea( $code ); ?></textarea>
					<?php endif; ?>
				</div>
			</div>

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Rename a post type key', 'fw' ); ?></h2></div>
				<div class="inside">
					<p class="description">
						<?php esc_html_e( 'A post type key is normally permanent, because changing it orphans every item already saved under it. This moves the content with the key: it rewrites the post_type of every existing item, updates the definition, and re-points any taxonomy attached to it. Back up before running it on a large site.', 'fw' ); ?>
					</p>
					<?php if ( empty( $rename_choices ) ) : ?>
						<p><em><?php esc_html_e( 'No post types defined yet.', 'fw' ); ?></em></p>
					<?php else : ?>
						<form method="post" action="" class="fw-pt-rename-form">
							<?php wp_nonce_field( FW_Extension_Post_Types::NONCE ); ?>
							<input type="hidden" name="fw_pt_action" value="rename">
							<input type="hidden" name="fw_pt_tab" value="tools">
							<p>
								<label>
									<?php esc_html_e( 'Post type', 'fw' ); ?>
									<select name="fw_pt_rename_from">
										<?php foreach ( $rename_choices as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" data-count="<?php echo esc_attr( FW_Post_Types_Porter::rename_count( $key ) ); ?>">
												<?php echo esc_html( $label ); ?>
												<?php printf( esc_html__( '— %d item(s)', 'fw' ), (int) FW_Post_Types_Porter::rename_count( $key ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
							<p>
								<label>
									<?php esc_html_e( 'New key', 'fw' ); ?>
									<input type="text" name="fw_pt_rename_to" maxlength="20" class="regular-text" placeholder="new_key">
								</label>
							</p>
							<button type="submit" class="button"><?php esc_html_e( 'Rename and migrate', 'fw' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>

		</div>
		<?php
	}
}
