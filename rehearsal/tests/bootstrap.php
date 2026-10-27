<?php
/**
 * Loads Rehearsal's classes with stand-ins for the few WordPress functions their pure parts call.
 *
 * @package companion
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}

require dirname( __DIR__ ) . '/class-companion-rehearsal.php';
