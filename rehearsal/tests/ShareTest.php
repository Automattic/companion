<?php
/**
 * Tests for Companion_Rehearsal_Share.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * A share link must open its own rehearsal and nothing else.
 */
class ShareTest extends TestCase {

	/**
	 * The ?rehearsal= value of a rehearsal's share link.
	 *
	 * @param int $id Rehearsal ID.
	 * @return string
	 */
	private function token( $id ) {
		parse_str( (string) parse_url( Companion_Rehearsal_Share::share_url( $id ), PHP_URL_QUERY ), $query );
		return $query['rehearsal'];
	}

	public function test_a_share_link_opens_its_rehearsal() {
		$this->assertSame( 42, Companion_Rehearsal_Share::id_from_token( $this->token( 42 ) ) );
	}

	/**
	 * @dataProvider bad_tokens
	 *
	 * @param callable $token Builds the token from a valid one for rehearsal 42.
	 */
	public function test_rejects_tokens_that_do_not_match( $token ) {
		$this->assertSame( 0, Companion_Rehearsal_Share::id_from_token( $token( $this->token( 42 ) ) ) );
	}

	public function bad_tokens() {
		return array(
			'key changed'              => array( static fn( $t ) => substr( $t, 0, -1 ) . ( '0' === substr( $t, -1 ) ? '1' : '0' ) ),
			'id changed, key kept'     => array( static fn( $t ) => '43' . substr( $t, 2 ) ),
			'key missing'              => array( static fn( $t ) => '42' ),
			'key empty'                => array( static fn( $t ) => '42.' ),
			'id zero with a valid key' => array( static fn( $t ) => '0' . substr( $t, 2 ) ),
			'not a token'              => array( static fn( $t ) => 'hello' ),
		);
	}

	public function test_a_bundle_link_opens_exactly_its_rehearsals() {
		parse_str( (string) parse_url( Companion_Rehearsal_Share::bundle_url( array( 3, 7, 12 ) ), PHP_URL_QUERY ), $query );
		$token = $query['rehearsal_bundle'];

		$this->assertSame( array( 3, 7, 12 ), Companion_Rehearsal_Share::ids_from_bundle_token( $token ) );
		$this->assertSame( array(), Companion_Rehearsal_Share::ids_from_bundle_token( '3-7-12-13' . substr( $token, strpos( $token, '.' ) ) ) );
		$this->assertSame( array(), Companion_Rehearsal_Share::ids_from_bundle_token( '3-7' . substr( $token, strpos( $token, '.' ) ) ) );
		$this->assertSame( array(), Companion_Rehearsal_Share::ids_from_bundle_token( '3-7-12.' ) );
	}

	public function test_a_run_link_runs_exactly_its_rehearsals_and_is_not_a_bundle_link() {
		parse_str( (string) parse_url( Companion_Rehearsal_Share::run_url( array( 3, 7 ), 'task-1' ), PHP_URL_QUERY ), $query );
		parse_str( (string) parse_url( Companion_Rehearsal_Share::bundle_url( array( 3, 7 ) ), PHP_URL_QUERY ), $bundle );

		$this->assertSame( 'task-1', $query['ref'] );
		$this->assertSame( array( 3, 7 ), Companion_Rehearsal_Share::ids_from_run_token( $query['run'] ) );
		$this->assertSame( array(), Companion_Rehearsal_Share::ids_from_run_token( '3-7-8' . substr( $query['run'], strpos( $query['run'], '.' ) ) ) );
		// A Playwright link given to CI must not start runs in an admin's browser.
		$this->assertSame( array(), Companion_Rehearsal_Share::ids_from_run_token( $bundle['rehearsal_bundle'] ) );
	}

	/**
	 * A share payload for the GitHub block.
	 *
	 * @return array
	 */
	private function payload() {
		return array(
			'title'      => 'Forms: responses list loads',
			'id'         => str_repeat( 'd', 64 ),
			'source'     => 'https://example.test/?rehearsal=5.key',
			'site'       => 'https://example.test/',
			'definition' => array(
				'covers' => array( 'packages/forms' ),
				'steps'  => array( array( 'goto' => '/wp-admin/' ), array( 'waitFor' => '#wpbody' ) ),
			),
		);
	}

	public function test_the_github_block_shows_the_latest_result_of_this_version() {
		$run   = array( 'hash' => str_repeat( 'd', 64 ), 'status' => 'fail', 'runner' => 'playwright', 'build' => 'WordPress 6.9', 'id' => 233, 'date' => '2026-10-28T12:00:00+00:00' );
		$block = Companion_Rehearsal_Share::to_github( $this->payload(), $run );

		$this->assertStringStartsWith( "<!-- rehearsal v1 -->\n#### Rehearsal: Forms: responses list loads\n", $block );
		$this->assertStringContainsString( 'Rehearsal `ddddddd` · 2 steps · covers `packages/forms`', $block );
		$this->assertStringContainsString( 'Last run: **Failed** in Playwright on WordPress 6.9, run #233, 2026-10-28.', $block );
		$this->assertStringContainsString( '[JSON](https://example.test/?rehearsal=5.key&format=json)', $block );
		$this->assertStringContainsString( "```json\n{\n", $block );
	}

	public function test_the_github_block_does_not_claim_a_result_from_an_older_version() {
		$run = array( 'hash' => str_repeat( 'e', 64 ), 'status' => 'pass', 'runner' => 'browser', 'build' => 'x', 'id' => 1, 'date' => '2026-10-01' );
		$this->assertStringContainsString( 'Not run since its last change.', Companion_Rehearsal_Share::to_github( $this->payload(), $run ) );
		$this->assertStringContainsString( 'Not run since its last change.', Companion_Rehearsal_Share::to_github( $this->payload(), null ) );
	}
}
