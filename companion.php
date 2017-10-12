<?php
/*
Plugin Name: Companion Plugin
Plugin URI: https://github.com/oskosk/companion
Description: Helps keep the launched WordPress in order.
Version: 1.0.0
Author: Osk
Author URI: https://github.com/oskosk
*/


$companion_api_base_url = get_option( 'companion_api_base_url' );

add_action( 'wp_login', 'companion_wp_login', 1, 2 );
add_action( 'after_setup_theme', 'companion_after_setup_theme' );
add_action( 'admin_notices', 'companion_admin_notices' );
add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );

function companion_admin_notices() {
	?>
	<div class="notice notice-success is-dismissible">
		<h3><?php _e( 'Welcome to Jurassic Ninja!', 'sample-text-domain' ); ?></h3>
		<p><?php _e( 'This WP will be destroyed 7 days after the last time you logged in.' ); ?></p>
		<p><strong>URL:</strong> <code><?php echo get_site_url(); ?></code></p>
		<p><?php _e( 'These are your credentials' ); ?></p>
		<p><strong>Username:</strong> <code>demo</code></p>
		<p><strong>Password:</strong> <code><?php echo get_option( 'jurassic_ninja_admin_password' ); ?></code></p>
	</div>
	<?php
}

function companion_hide_plugin() {
	global $wp_list_table;
	$hidearr = array( 'companion/companion.php' );
	$myplugins = $wp_list_table->items;
	foreach ( $myplugins as $key => $val ) {
		if ( in_array( $key, $hidearr, true ) ) {
			unset( $wp_list_table->items[ $key ] );
		}
	}
}

function companion_wp_login() {
	delete_transient( '_wc_activation_redirect' );

	$auto_login = get_option( 'auto_login' );

	update_option( 'auto_login', 0 );

	if ( empty( $auto_login ) ) {
		$urlparts = wp_parse_url( site_url() );
		$domain = $urlparts['host'];
		$url = "$companion_api_base_url/extend/";
		wp_remote_post( $url, [
			'body' => [
				'domain' => $domain,
			],
		] );
	} else {
		$urlparts = wp_parse_url( site_url() );
		$domain = $urlparts ['host'];
		$url = "$companion_api_base_url/checkin/";
		wp_remote_post( $url, [
			'body' => [
				'domain' => $domain,
			],
		] );
		wp_safe_redirect( '/wp-admin' );
		exit( 0 );
	}
}


function companion_after_setup_theme() {
	$auto_login = get_option( 'auto_login' );
	if ( ! empty( $auto_login ) ) {
		$password = get_option( 'jurassic_ninja_admin_password' );
		$creds = array();
		$creds['user_login'] = 'demo';
		$creds['user_password'] = $password;
		$creds['remember'] = true;
		$user = wp_signon( $creds, false );
	}
}
