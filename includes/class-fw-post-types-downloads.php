<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Makes the Downloads blueprint's fields do something on the front end: a post with a
 * `download_file` gets a download box under its content, and `download_gated` ("Requires an
 * email address") swaps the button for an email form.
 *
 * The file is served through `?fw_download=<post id>` rather than its media URL, so the page
 * never prints the file's address. A gated file needs a signed, short-lived link, which only the
 * email form hands out; the email is kept under Form Entries (when Forms stores entries) and
 * announced on `fw_ext_post_types_download_requested`.
 *
 * Keyed on the fields, not the post type, so a renamed Downloads post type keeps working.
 */
class FW_Post_Types_Downloads {

	const QUERY_VAR = 'fw_download';
	const LINK_TTL  = DAY_IN_SECONDS;

	/** @var bool The box's few layout rules are printed once per page. */
	private static $styled = false;

	public function __construct() {
		add_filter( 'the_content', array( $this, '_filter_content' ), 20 );
		add_action( 'template_redirect', array( $this, '_action_serve' ), 1 );
		add_action( 'admin_post_fw_download_request', array( $this, '_action_request' ) );
		add_action( 'admin_post_nopriv_fw_download_request', array( $this, '_action_request' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Fields
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $post_id
	 *
	 * @return array { attachment_id, url } or empty.
	 */
	public static function file( $post_id ) {
		$v = fw_get_db_post_option( (int) $post_id, 'download_file', null );

		if ( ! is_array( $v ) || ( empty( $v['attachment_id'] ) && empty( $v['url'] ) ) ) {
			return array();
		}

		return array(
			'attachment_id' => (int) ( $v['attachment_id'] ?? 0 ),
			'url'           => (string) ( $v['url'] ?? '' ),
		);
	}

	/**
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public static function is_gated( $post_id ) {
		$v = fw_get_db_post_option( (int) $post_id, 'download_gated', false );

		return in_array( $v, array( true, 1, '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * @param int  $post_id
	 * @param bool $signed  A signed link, which a gated file requires.
	 *
	 * @return string
	 */
	public static function link( $post_id, $signed = false ) {
		$args = array( self::QUERY_VAR => (int) $post_id );

		if ( $signed ) {
			$args['e'] = time() + self::LINK_TTL;
			$args['t'] = self::token( $post_id, $args['e'] );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * @param int $post_id
	 * @param int $expires
	 *
	 * @return string
	 */
	private static function token( $post_id, $expires ) {
		return substr( hash_hmac( 'sha256', (int) $post_id . '|' . (int) $expires, wp_salt( 'auth' ) ), 0, 32 );
	}

	/* ---------------------------------------------------------------------- *
	 * Front end
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function _filter_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = (int) get_the_ID();

		if ( ! $post_id || ! self::file( $post_id ) || false !== strpos( $content, 'fw-download-box' ) ) {
			return $content;
		}

		/**
		 * Filters whether a download box is added under a post that has a downloadable file.
		 * Return false when a template already places its own download button.
		 *
		 * @param bool $show
		 * @param int  $post_id
		 */
		if ( ! apply_filters( 'fw_ext_post_types_download_box', true, $post_id ) ) {
			return $content;
		}

		return $content . $this->box( $post_id );
	}

	/**
	 * @param int $post_id
	 *
	 * @return string
	 */
	private function box( $post_id ) {
		$version = (string) fw_get_db_post_option( $post_id, 'download_version', '' );
		$details = array_filter( array(
			'' !== $version ? sprintf( __( 'Version %s', 'fw' ), $version ) : '',
			(string) fw_get_db_post_option( $post_id, 'download_size', '' ),
		), 'strlen' );
		$meta    = $details ? '<span class="fw-download-box__meta">' . esc_html( implode( ' · ', $details ) ) . '</span>' : '';
		$style   = self::$styled ? '' : '<style>'
			. '.fw-download-box{margin:1.5em 0;padding:1.25em;border:1px solid rgba(127,127,127,.3);border-radius:8px}'
			. '.fw-download-box form{display:flex;flex-wrap:wrap;gap:.5em;align-items:center;margin:0}'
			. '.fw-download-box form p,.fw-download-box form label{flex-basis:100%;margin:0}'
			. '.fw-download-box form label{font-weight:600}'
			. '.fw-download-box input[type=email]{flex:1 1 16em;min-height:2.75em;padding:.5em .75em;border:1px solid rgba(127,127,127,.5);border-radius:6px;font:inherit}'
			. '.fw-download-box__meta{display:inline-block;margin-left:.5em;opacity:.75;font-size:.9em}'
			. '.fw-download-box__error{color:#b32d2e}'
			. '</style>';
		self::$styled = true;

		if ( ! self::is_gated( $post_id ) ) {
			return $style . '<div class="fw-download-box"><a class="btn btn-primary" href="' . esc_url( self::link( $post_id ) ) . '">'
				. esc_html__( 'Download', 'fw' ) . '</a>' . $meta . '</div>';
		}

		$id     = 'fw-download-email-' . $post_id;
		$notice = '';

		if ( isset( $_GET['fw_download_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = '<p class="fw-download-box__error" role="alert">' . esc_html__( 'Please enter a valid email address.', 'fw' ) . '</p>';
		}

		return $style . '<div class="fw-download-box fw-download-box--gated">'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="fw_download_request" />'
			. '<input type="hidden" name="post_id" value="' . (int) $post_id . '" />'
			. wp_nonce_field( 'fw_download_request_' . $post_id, '_fw_download_nonce', true, false )
			. '<p>' . esc_html__( 'Enter your email address to download this file.', 'fw' ) . $meta . '</p>'
			. $notice
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Email', 'fw' ) . '</label> '
			. '<input type="email" id="' . esc_attr( $id ) . '" name="email" autocomplete="email" required /> '
			// Left empty by people, filled in by bots.
			. '<input type="text" name="fw_download_website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px" />'
			. '<button type="submit" class="btn btn-primary">' . esc_html__( 'Download', 'fw' ) . '</button>'
			. '</form></div>';
	}

	/**
	 * The email form's handler: keep the lead, then hand over a signed link.
	 *
	 * @internal
	 */
	public function _action_request() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! self::file( $post_id ) || ! check_admin_referer( 'fw_download_request_' . $post_id, '_fw_download_nonce' ) ) {
			wp_die( esc_html__( 'This download is not available.', 'fw' ), '', array( 'response' => 404 ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'fw_download_error', 1, get_permalink( $post_id ) ) );
			exit;
		}

		if ( empty( $_POST['fw_download_website'] ) ) {
			if ( class_exists( 'FW_Forms_Entries' ) && class_exists( 'FW_Forms_Entries_Capture' ) && FW_Forms_Entries_Capture::enabled() ) {
				FW_Forms_Entries::insert( array(
					'form_id'    => 'download',
					'form_type'  => 'download',
					'form_title' => sprintf( __( 'Download: %s', 'fw' ), get_the_title( $post_id ) ),
					'post_id'    => $post_id,
					'email'      => $email,
					'fields'     => array(
						array( 'id' => 'email', 'type' => 'email', 'label' => __( 'Email', 'fw' ), 'value' => $email ),
						array( 'id' => 'download', 'type' => 'text', 'label' => __( 'Download', 'fw' ), 'value' => get_the_title( $post_id ) ),
					),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				) );
			}

			/**
			 * Fires when someone gives their email address to download a gated file.
			 *
			 * @param int    $post_id
			 * @param string $email
			 */
			do_action( 'fw_ext_post_types_download_requested', $post_id, $email );
		}

		wp_safe_redirect( self::link( $post_id, true ) );
		exit;
	}

	/**
	 * Serves `?fw_download=<id>`: the attachment itself, or a redirect to an external file URL.
	 *
	 * @internal
	 */
	public function _action_serve() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_id = absint( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file    = $post_id ? self::file( $post_id ) : array();
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $file || ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return; // Not a download: let WordPress answer as usual.
		}

		if ( self::is_gated( $post_id ) ) {
			$expires = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token   = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $expires < time() || ! hash_equals( self::token( $post_id, $expires ), $token ) ) {
				wp_safe_redirect( get_permalink( $post_id ) );
				exit;
			}
		}

		$path = $file['attachment_id'] ? get_attached_file( $file['attachment_id'] ) : '';

		if ( $path && is_readable( $path ) ) {
			nocache_headers();
			header( 'Content-Type: ' . ( get_post_mime_type( $file['attachment_id'] ) ?: 'application/octet-stream' ) );
			header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', basename( $path ) ) . '"' );
			header( 'Content-Length: ' . filesize( $path ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}

		if ( $file['url'] ) {
			wp_redirect( esc_url_raw( $file['url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the site owner's own file URL
			exit;
		}
	}
}
