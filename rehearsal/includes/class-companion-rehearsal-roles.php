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

	// User meta set on the test users Rehearsal creates.
	const MARK = '_rehearsal_test_user';

	/**
	 * Whether this request was switched to a role's test user.
	 *
	 * @return bool
	 */
	public static function is_acting() {
		return '' !== self::$acting_as;
	}

	/**
	 * Whether a request comes from the runner's frame: a page load into a frame, or a request the
	 * recorder marked, which it only does inside the runner's frame. Other tabs' requests carry neither.
	 *
	 * @param string $dest   The Sec-Fetch-Dest header.
	 * @param string $marker The X-Rehearsal-Frame header.
	 * @return bool
	 */
	public static function is_from_frame( $dest, $marker ) {
		return 'iframe' === $dest || ( '1' === $marker && 'document' !== $dest );
	}

	/**
	 * Whether a user is one of the test users Rehearsal made, and not an account that only shares the name.
	 *
	 * @param WP_User|false $user User.
	 * @param string        $role Role it must have.
	 * @return bool
	 */
	public static function is_test_user( $user, $role ) {
		return $user && in_array( $role, (array) $user->roles, true ) && get_user_meta( $user->ID, self::MARK, true );
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
		$dest   = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		$marker = isset( $_SERVER['HTTP_X_REHEARSAL_FRAME'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_X_REHEARSAL_FRAME'] ) ) : '';
		if ( ! self::is_from_frame( $dest, $marker ) || ! user_can( $user_id, 'manage_options' ) ) {
			return $user_id;
		}
		$role = sanitize_key( wp_unslash( $_COOKIE['rehearsal_role'] ) );
		$test = get_user_by( 'login', 'rehearsal-' . $role );
		if ( ! self::is_test_user( $test, $role ) ) {
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
			update_user_meta( $id, self::MARK, 1 );
			$user = get_user_by( 'id', $id );
		} elseif ( ! get_user_meta( $user->ID, self::MARK, true ) ) {
			return new WP_Error( 'rehearsal_user_taken', "There is already a user called \"$login\" that Rehearsal did not create, so it will not run as that account. Rename or delete it to run rehearsals as $role.", array( 'status' => 409 ) );
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
