<?php
/**
 * Rehearsal: Runs a rehearsal as a test user with a given role.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs a rehearsal as a test user with a given role.
 */
final class Companion_Rehearsal_Roles {
	/**
	 * The role the current request is acting as, when act_as_role() switched it.
	 *
	 * @var string
	 */
	private static $acting_as = '';

	/**
	 * Whether this request was switched to a role's test user.
	 *
	 * @return bool
	 */
	public static function is_acting() {
		return '' !== self::$acting_as;
	}

	/**
	 * Whether a request comes from the runner's frame: frame page loads do, and so do other requests
	 * unless the Rehearsal page itself made them. Top-level page loads never do.
	 *
	 * @param string $dest    The Sec-Fetch-Dest header.
	 * @param string $referer The Referer header.
	 * @return bool
	 */
	public static function is_from_frame( $dest, $referer ) {
		if ( 'iframe' === $dest ) {
			return true;
		}
		return 'document' !== $dest && false === strpos( (string) $referer, 'page=' . Companion_Rehearsal::SLUG );
	}

	/**
	 * While a rehearsal with a "role" runs, requests from the runner's frame act as that role's test user.
	 * Only for a real admin, and never for top-level pages or the Rehearsal page's own requests.
	 *
	 * @param int|false $user_id User from the auth cookies.
	 * @return int|false
	 */
	public static function act_as_role( $user_id ) {
		if ( ! $user_id || empty( $_COOKIE['rehearsal_role'] ) ) {
			return $user_id;
		}
		$dest    = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! self::is_from_frame( $dest, $referer ) || ! user_can( $user_id, 'manage_options' ) ) {
			return $user_id;
		}
		$role = sanitize_key( wp_unslash( $_COOKIE['rehearsal_role'] ) );
		$test = get_user_by( 'login', 'rehearsal-' . $role );
		if ( ! $test || ! in_array( $role, (array) $test->roles, true ) ) {
			return $user_id;
		}
		self::$acting_as = $role;
		return $test->ID;
	}

	/**
	 * POST /rehearsal/v1/role-user: make sure the test user for a role exists, before a rehearsal runs as it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_role_user( $request ) {
		$role = sanitize_key( $request['role'] );
		if ( ! get_role( $role ) ) {
			return new WP_Error( 'rehearsal_no_role', "There is no \"$role\" role on this site.", array( 'status' => 400 ) );
		}
		$login = 'rehearsal-' . $role;
		$user  = get_user_by( 'login', $login );
		if ( ! $user ) {
			$id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'user_email'   => $login . '@example.invalid',
					'display_name' => "Rehearsal ($role)",
					'role'         => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$user = get_user_by( 'id', $id );
		} elseif ( ! in_array( $role, (array) $user->roles, true ) ) {
			$user->set_role( $role );
		}
		return rest_ensure_response(
			array(
				'id'    => $user->ID,
				'login' => $login,
				'role'  => $role,
			)
		);
	}
}
