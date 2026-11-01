<?php
/**
 * Extends a Jurassic Ninja site's life, from a sign-in or from the "extend it" link.
 *
 * Jurassic Ninja destroys a site some days after its last sign-in. Signing in calls the
 * `jurassic-ninja/extend` endpoint on WordPress.com, which moves that date and answers with
 * the new one. The link in the welcome notice makes the same call, so an admin who is already
 * signed in does not have to sign out and in again.
 */
class Companion_JN_Extend {

	/**
	 * The extend endpoint. It authenticates with the site's domain and JN_API_TOKEN, both in
	 * the POST body.
	 */
	const API_URL = 'https://public-api.wordpress.com/wpcom/v2/jurassic-ninja/extend';

	/**
	 * Option holding the date (Y-m-d, UTC) the site will be destroyed on, as the endpoint returned it.
	 */
	const EXPIRES_AT_OPTION = 'jurassic_ninja_expires_at';

	/**
	 * admin-post.php action, and nonce action, for the link.
	 */
	const ACTION = 'companion_jn_extend';

	/**
	 * Hooks up the link on Jurassic Ninja sites on WP.cloud. Does nothing anywhere else.
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_extend' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_result_notice' ) );
	}

	/**
	 * Whether this site can call the extend endpoint.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN && class_exists( 'Atomic_Persistent_Data' );
	}

	/**
	 * Asks WordPress.com to extend the site, and stores the new destruction date.
	 *
	 * @return bool Whether WordPress.com extended the site.
	 */
	public static function extend() {
		if ( ! self::is_available() ) {
			return false;
		}

		$urlparts        = wp_parse_url( network_site_url() );
		$persistent_data = new Atomic_Persistent_Data();
		$response        = wp_remote_post(
			self::API_URL,
			array(
				'body' => array(
					'domain'    => $urlparts['host'],
					'api_token' => $persistent_data->JN_API_TOKEN,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// Older endpoint versions answer with a bare boolean instead of an object, and with false when the token is wrong.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return true === $body;
		}
		if ( ! empty( $body['expires_at'] ) ) {
			update_option( self::EXPIRES_AT_OPTION, $body['expires_at'] );
		}
		return ! empty( $body['extended'] );
	}

	/**
	 * URL of the "extend it" link. It comes back to the page it was clicked on.
	 *
	 * @return string Not escaped.
	 */
	public static function link_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Extends the site and redirects back with the outcome.
	 */
	public static function handle_extend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'companion' ), 403 );
		}
		check_admin_referer( self::ACTION );

		set_transient( self::notice_key(), self::extend() ? 'extended' : 'failed', MINUTE_IN_SECONDS );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Shows the outcome of the link, once, on the page it redirected to.
	 */
	public static function render_result_notice() {
		$result = get_transient( self::notice_key() );
		if ( false === $result ) {
			return;
		}
		delete_transient( self::notice_key() );

		if ( 'extended' === $result ) {
			$type    = 'success';
			$message = __( 'Site extended.', 'companion' );
		} else {
			$type    = 'error';
			$message = __( "Couldn't extend the site right now. Try again in a few minutes, or sign in again.", 'companion' );
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Transient carrying the link's outcome to the page it redirects to, per user.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'companion_jn_extend_notice_' . get_current_user_id();
	}
}
