<?php
/**
 * Loads Rehearsal's test bootstrap, then the JN ledger class with stand-ins for the WordPress
 * functions its welcome notice calls.
 *
 * @package companion
 */

require dirname( __DIR__ ) . '/rehearsal/tests/bootstrap.php';

define( 'IS_ATOMIC_JN', true );
define( 'MINUTE_IN_SECONDS', 60 );

class Atomic_Persistent_Data {}

// Site transients and the current user's capabilities, set per test.
$GLOBALS['jn_site_transients'] = array();
$GLOBALS['jn_can_manage']      = false;

function get_site_transient( $key ) {
	return $GLOBALS['jn_site_transients'][ $key ] ?? false;
}

function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['jn_site_transients'][ $key ] = $value;
	return true;
}

function current_user_can( $cap ) {
	return $GLOBALS['jn_can_manage'] && 'manage_options' === $cap;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function esc_url_raw( $url, $protocols = null ) {
	$url    = trim( (string) $url );
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return '' !== $url && in_array( $scheme, null === $protocols ? array( 'http', 'https' ) : $protocols, true ) ? $url : '';
}

function esc_url( $url ) {
	return esc_html( esc_url_raw( $url ) );
}

function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		$list
	);
}

require dirname( __DIR__ ) . '/class-companion-jn-ledger.php';
