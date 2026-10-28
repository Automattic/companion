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

// Users, their meta and capabilities for the role-switching tests, set per test.
$GLOBALS['rh_users'] = array();

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	return $value;
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['rh_users'] as $user ) {
		if ( ( 'login' === $field && $user->user_login === $value ) || ( 'id' === $field && $user->ID === (int) $value ) ) {
			return $user;
		}
	}
	return false;
}

function get_user_meta( $id, $key, $single = false ) {
	$user = get_user_by( 'id', $id );
	return $user && isset( $user->meta[ $key ] ) ? $user->meta[ $key ] : '';
}

function user_can( $user_id, $cap ) {
	$user = get_user_by( 'id', $user_id );
	return $user && 'manage_options' === $cap && in_array( 'administrator', $user->roles, true );
}

require dirname( __DIR__ ) . '/class-companion-rehearsal.php';
