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

// Persistent data, options, actions, cron events, site transients and the current user's capabilities, set per test.
$GLOBALS['jn_persistent_data'] = array();
$GLOBALS['jn_options']         = array();
$GLOBALS['jn_actions']         = array();
$GLOBALS['jn_cron_events']     = array();
$GLOBALS['jn_cron_fails']      = false;
$GLOBALS['jn_site_transients'] = array();
$GLOBALS['jn_can_manage']      = false;

class Atomic_Persistent_Data {
	public function __get( $name ) {
		return $GLOBALS['jn_persistent_data'][ $name ] ?? null;
	}
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['jn_options'] ) ? $GLOBALS['jn_options'][ $key ] : $default;
}

function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['jn_options'] ) ) {
		return false;
	}
	$GLOBALS['jn_options'][ $key ] = $value;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['jn_actions'][] = array( $hook, $callback, $priority );
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	if ( $GLOBALS['jn_cron_fails'] ) {
		return false;
	}
	$GLOBALS['jn_cron_events'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);
	return true;
}

function wp_unschedule_hook( $hook ) {
	$GLOBALS['jn_cron_events'] = array_values(
		array_filter(
			$GLOBALS['jn_cron_events'],
			function ( $event ) use ( $hook ) {
				return $event['hook'] !== $hook;
			}
		)
	);
	return 0;
}

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
