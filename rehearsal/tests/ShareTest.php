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
}
