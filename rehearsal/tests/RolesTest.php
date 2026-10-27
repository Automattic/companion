<?php
/**
 * Tests for Companion_Rehearsal_Roles.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * Only the runner's frame may be switched to a role; the Rehearsal screen and top-level pages never are.
 */
class RolesTest extends TestCase {

	/**
	 * @dataProvider requests
	 *
	 * @param string $dest     Sec-Fetch-Dest.
	 * @param string $referer  Referer.
	 * @param bool   $expected Whether the request counts as coming from the frame.
	 */
	public function test_which_requests_switch( $dest, $referer, $expected ) {
		$this->assertSame( $expected, Companion_Rehearsal_Roles::is_from_frame( $dest, $referer ) );
	}

	public function requests() {
		$runner = 'https://example.test/wp-admin/tools.php?page=rehearsal';
		$page   = 'https://example.test/wp-admin/post.php?post=1&action=edit';
		return array(
			'frame page load from the runner'      => array( 'iframe', $runner, true ),
			'frame page load from a frame page'    => array( 'iframe', $page, true ),
			'request made by a frame page'         => array( 'empty', $page, true ),
			'request made by the Rehearsal screen' => array( 'empty', $runner, false ),
			'top-level page load'                  => array( 'document', $page, false ),
			'reloading the Rehearsal screen'       => array( 'document', $runner, false ),
			'old browser, frame page request'      => array( '', $page, true ),
			'old browser, Rehearsal screen'        => array( '', $runner, false ),
		);
	}
}
