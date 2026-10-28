<?php
/**
 * Tests for Companion_Rehearsal_Roles.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * Only the runner's frame may be switched to a role, and only to a test user Rehearsal made.
 */
class RolesTest extends TestCase {

	protected function setUp(): void {
		$user = static fn( $id, $login, $roles, $meta = array() ) => (object) array(
			'ID'         => $id,
			'user_login' => $login,
			'roles'      => $roles,
			'meta'       => $meta,
		);
		$GLOBALS['rh_users'] = array(
			$user( 1, 'admin', array( 'administrator' ) ),
			$user( 2, 'author', array( 'author' ) ),
			$user( 3, 'rehearsal-editor', array( 'editor' ), array( Companion_Rehearsal_Roles::MARK => 1 ) ),
			$user( 4, 'rehearsal-author', array( 'author' ) ),
			$user( 5, 'rehearsal-contributor', array( 'subscriber' ), array( Companion_Rehearsal_Roles::MARK => 1 ) ),
		);
		$_COOKIE = array( 'rehearsal_role' => 'editor' );
		$_SERVER = array( 'HTTP_SEC_FETCH_DEST' => 'iframe' );
		$acting = new ReflectionProperty( Companion_Rehearsal_Roles::class, 'acting_as' );
		if ( PHP_VERSION_ID < 80100 ) {
			$acting->setAccessible( true );
		}
		$acting->setValue( null, '' );
	}

	/**
	 * @dataProvider requests
	 *
	 * @param string $dest     Sec-Fetch-Dest.
	 * @param string $marker   X-Rehearsal-Frame.
	 * @param bool   $expected Whether the request counts as coming from the frame.
	 */
	public function test_which_requests_switch( $dest, $marker, $expected ) {
		$this->assertSame( $expected, Companion_Rehearsal_Roles::is_from_frame( $dest, $marker ) );
	}

	public function requests() {
		return array(
			'page load into a frame'                => array( 'iframe', '', true ),
			'request the frame marked'              => array( 'empty', '1', true ),
			'unmarked request (screen or other tab)' => array( 'empty', '', false ),
			'top-level page load'                   => array( 'document', '', false ),
			'top-level page load claiming the mark' => array( 'document', '1', false ),
			'old browser, unmarked'                 => array( '', '', false ),
		);
	}

	/**
	 * @dataProvider switches
	 *
	 * @param array $cookie   Cookies.
	 * @param array $server   Request headers.
	 * @param int   $user_id  User from the auth cookies.
	 * @param int   $expected User the request runs as.
	 */
	public function test_who_a_request_runs_as( $cookie, $server, $user_id, $expected ) {
		$_COOKIE = $cookie;
		$_SERVER = $server;
		$this->assertSame( $expected, Companion_Rehearsal_Roles::act_as_role( $user_id ) );
		$this->assertSame( $expected !== $user_id, Companion_Rehearsal_Roles::is_acting() );
	}

	public function switches() {
		$frame  = array( 'HTTP_SEC_FETCH_DEST' => 'iframe' );
		$editor = array( 'rehearsal_role' => 'editor' );
		return array(
			'admin in the frame becomes the editor test user' => array( $editor, $frame, 1, 3 ),
			'marked request from the frame'                   => array( $editor, array( 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_X_REHEARSAL_FRAME' => '1' ), 1, 3 ),
			'no role cookie'                                  => array( array(), $frame, 1, 1 ),
			'not an admin'                                    => array( $editor, $frame, 2, 2 ),
			'top-level page'                                  => array( $editor, array( 'HTTP_SEC_FETCH_DEST' => 'document' ), 1, 1 ),
			'no test user for the role'                       => array( array( 'rehearsal_role' => 'shop_manager' ), $frame, 1, 1 ),
			'account Rehearsal did not create'                => array( array( 'rehearsal_role' => 'author' ), $frame, 1, 1 ),
			'test user without the role'                      => array( array( 'rehearsal_role' => 'contributor' ), $frame, 1, 1 ),
		);
	}
}
