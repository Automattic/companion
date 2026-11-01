<?php
/**
 * Extending the site, from a sign-in or from the link in the welcome notice.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

// The HTTP requests sent, the response the next one gets, and transients, set per test.
$GLOBALS['jn_http_requests'] = array();
$GLOBALS['jn_http_response'] = null;
$GLOBALS['jn_transients']    = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;

		public function __construct( $code = '' ) {
			$this->code = $code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'network_site_url' ) ) {
	function network_site_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['jn_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $GLOBALS['jn_http_response'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['jn_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['jn_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['jn_transients'][ $key ] );
		return true;
	}
}

require_once dirname( __DIR__ ) . '/class-companion-jn-extend.php';

class JNExtendTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jn_persistent_data'] = array( 'JN_API_TOKEN' => 'token-123' );
		$GLOBALS['jn_options']         = array();
		$GLOBALS['jn_http_requests']   = array();
		$GLOBALS['jn_http_response']   = null;
		$GLOBALS['jn_transients']      = array();
	}

	private function respond_with( $body ) {
		$GLOBALS['jn_http_response'] = array( 'body' => json_encode( $body ) );
	}

	public function test_extend_sends_the_domain_and_api_token() {
		$this->respond_with( array( 'extended' => true ) );

		Companion_JN_Extend::extend();

		$this->assertCount( 1, $GLOBALS['jn_http_requests'] );
		$this->assertSame( Companion_JN_Extend::API_URL, $GLOBALS['jn_http_requests'][0]['url'] );
		$this->assertSame(
			array(
				'domain'    => 'example.test',
				'api_token' => 'token-123',
			),
			$GLOBALS['jn_http_requests'][0]['args']['body']
		);
	}

	public function test_extend_stores_the_new_destruction_date() {
		$GLOBALS['jn_options'][ Companion_JN_Extend::EXPIRES_AT_OPTION ] = '2026-11-01';
		$this->respond_with(
			array(
				'extended'          => true,
				'expires_at'        => '2026-11-07',
				'days_until_expiry' => 7,
			)
		);

		$this->assertTrue( Companion_JN_Extend::extend() );
		$this->assertSame( '2026-11-07', get_option( Companion_JN_Extend::EXPIRES_AT_OPTION ) );
	}

	public function test_extend_without_a_date_keeps_the_stored_one() {
		$GLOBALS['jn_options'][ Companion_JN_Extend::EXPIRES_AT_OPTION ] = '2026-11-01';
		$this->respond_with(
			array(
				'extended'   => true,
				'expires_at' => null,
			)
		);

		$this->assertTrue( Companion_JN_Extend::extend() );
		$this->assertSame( '2026-11-01', get_option( Companion_JN_Extend::EXPIRES_AT_OPTION ) );
	}

	public function test_extend_accepts_the_bare_true_of_older_endpoints() {
		$this->respond_with( true );

		$this->assertTrue( Companion_JN_Extend::extend() );
		$this->assertFalse( get_option( Companion_JN_Extend::EXPIRES_AT_OPTION ) );
	}

	public function test_extend_fails_when_the_token_is_rejected() {
		$this->respond_with( false );

		$this->assertFalse( Companion_JN_Extend::extend() );
		$this->assertFalse( get_option( Companion_JN_Extend::EXPIRES_AT_OPTION ) );
	}

	public function test_extend_fails_when_the_request_fails() {
		$GLOBALS['jn_http_response'] = new WP_Error( 'http_request_failed' );

		$this->assertFalse( Companion_JN_Extend::extend() );
	}

	public function test_result_notice_shows_once() {
		$GLOBALS['jn_transients']['companion_jn_extend_notice_1'] = 'extended';

		ob_start();
		Companion_JN_Extend::render_result_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Site extended.', $html );

		ob_start();
		Companion_JN_Extend::render_result_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_result_notice_shows_the_failure() {
		$GLOBALS['jn_transients']['companion_jn_extend_notice_1'] = 'failed';

		ob_start();
		Companion_JN_Extend::render_result_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Couldn&#039;t extend the site right now.', $html );
	}
}
