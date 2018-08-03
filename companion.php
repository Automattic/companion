<?php
/*
Plugin Name: Companion Plugin
Plugin URI: https://github.com/Automattic/companion
Description: Helps keep the launched WordPress in order.
Version: 1.3.1
Author: Osk
*/

if ( is_multisite() && ! is_main_site() ) {
	add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );
	add_action( 'admin_notices', 'companion_admin_notices' );
	return true;
}

$companion_api_base_url = get_option( 'companion_api_base_url' );

add_action( 'wp_login', 'companion_wp_login', 1, 2 );
add_action( 'after_setup_theme', 'companion_after_setup_theme' );
add_action( 'admin_notices', 'companion_admin_notices' );
add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );
/*
 * Run this function as early as we can relying in WordPress loading plugin in alphabetical order
 */
companion_tamper_with_jetpack_constants();
add_action( 'init', 'companion_add_jetpack_constants_option_page' );

function companion_admin_notices() {
	$password_option_key = 'jurassic_ninja_admin_password';
	$sysuser_option_key = 'jurassic_ninja_sysuser';
	$admin_password = is_multisite() ? get_blog_option( 1, $password_option_key ) : get_option( $password_option_key );
	$sysuser = is_multisite() ? get_blog_option( 1, $sysuser_option_key ) : get_option( $sysuser_option_key );
	$host = parse_url( network_site_url(), PHP_URL_HOST ); 
	?>
	<div class="notice notice-success is-dismissible">
		<h3><?php echo esc_html__( 'Welcome to Jurassic Ninja!' ); ?></h3>
		<p><strong><span id="jurassic_url"><?php echo esc_html( network_site_url() ); ?></span></strong> <?php echo esc_html__( 'will be destroyed 7 days after the last time anybody logged in.' ); ?></p>
		<p>
			<strong>WP user:</strong> <code><span id="jurassic_username">demo</span></code>
			<strong>SSH:</strong> <code><span id="jurassic_ssh_command">ssh <?php echo esc_html( $sysuser ); ?>@<?php echo esc_html( $host ); ?></span></code>
		</p>
		<p>
			<strong>WP/SSH password:</strong> <code><span id="jurassic_password"><?php echo esc_html( $admin_password ); ?></span></code>
			<strong>SSH server path:</strong> <code><span id="jurassic_ssh_server_path"><?php echo esc_html( get_home_path() ); ?></span></code>
		</p>
	</div>
	<style type="text/css">
		#jurassic_ssh_command {
			user-select: all;	
		}
	</style>
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
	global $companion_api_base_url;
	delete_transient( '_wc_activation_redirect' );

	$auto_login = get_option( 'auto_login' );

	update_option( 'auto_login', 0 );

	if ( empty( $auto_login ) ) {
		$urlparts = wp_parse_url( network_site_url() );
		$domain = $urlparts['host'];
		$url = "$companion_api_base_url/extend";
		wp_remote_post( $url, [
			'headers' => [
				'content-type' => 'application/json',
			],
			'body' => wp_json_encode( [
				'domain' => $domain,
			] ),
		] );
	} else {
		$urlparts = wp_parse_url( network_site_url() );
		$domain = $urlparts ['host'];
		$url = "$companion_api_base_url/checkin";
		wp_remote_post( $url, [
			'headers' => [
				'content-type' => 'application/json',
			],
			'body' => wp_json_encode( [
				'domain' => $domain,
			] ),
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
		$user = wp_signon( $creds, companion_site_uses_https() );
	}
}

function companion_site_uses_https() {
	$url = network_site_url();
	$scheme = wp_parse_url( $url )['scheme'];
	return 'https' === $scheme;
}

function companion_add_jetpack_constants_option_page() {
	if ( ! companion_is_jetpack_here() ) {
		return;
	}
	if ( ! class_exists( 'RationalOptionPages' ) ) {
		require 'RationalOptionPages.php';
	}
	$options_page = array(
		'companion' => array(
			'page_title' => __( 'Jurassic Ninja Tweaks for Jetpack Constants', 'companion' ),
			'menu_title' => __( 'Jetpack Constants', 'companion' ),
			'menu_slug' => 'companion_settings',
			'parent_slug' => 'options-general.php',
			'sections' => array(
				'jetpack_tweaks' => array(
					'title' => __( 'Sites', 'companion' ),
					'text' => '<p>' . __( 'Configure some defaults constants used by Jetpack.', 'companion' ) . '</p>',
					'fields' => array(
						'jetpack_api_base' => array(
							'id' => 'jetpack_api_base',
							'title' => __( 'JETPACK__API_BASE', 'companion' ),
							'text' => sprintf( __( "Base URL for API requests to Jetpack.com's XML RPC API. Current value for JETPACK__API_BASE: %s", 'companion' ), JETPACK__API_BASE ),
							'placeholder' => JETPACK__API_BASE,
						),
						'jetpack_wpcom_json_api_host' => array(
							'id' => 'jetpack_wpcom_json_api_host',
							'title' => __( 'JETPACK__WPCOM_JSON_API_HOST', 'companion' ),
							'text' => sprintf( __( "Base URL for API requests to WordPress.com's REST API. Current value for JETPACK__WPCOM_JSON_API_HOST : %s", 'companion' ), JETPACK__WPCOM_JSON_API_HOST ),
							'placeholder' => JETPACK__WPCOM_JSON_API_HOST,
						),
						'jetpack_protect_api_host' => array(
							'id' => 'jetpack_protect_api_host',
							'title' => __( 'JETPACK_PROTECT__API_HOST', 'companion' ),
							'text' => sprintf( __( "Base URL for API requests to Jetpack Protect's REST API. Current value for JETPACK_PROTECT__API_HOST : %s", 'companion' ), JETPACK_PROTECT__API_HOST ),
							'placeholder' => JETPACK_PROTECT__API_HOST,
						),
					),
				),
			),
		),
	);
	new RationalOptionPages( $options_page );
}

function companion_is_jetpack_here() {
	return class_exists( 'Jetpack' );
}

function companion_get_option( $slug, $default = null ) {
	$options = get_option( 'companion', array() );
	return isset( $options[ $slug ] )  ? $options[ $slug ] : $default;
}


function companion_tamper_with_jetpack_constants() {
	if ( companion_get_option( 'jetpack_api_base', '' ) ) {
		define( 'JETPACK__API_BASE', companion_get_option( 'jetpack_api_base', '' ) );
	}
	if ( companion_get_option( 'jetpack_wpcom_json_api_host', '' ) ) {
		define( 'JETPACK__WPCOM_JSON_API_HOST', companion_get_option( 'jetpack_wpcom_json_api_host', '' ) );
	}
	if ( companion_get_option( 'jetpack_protect_api_host', '' ) ) {
		define( 'JETPACK_PROTECT__API_HOST', companion_get_option( 'jetpack_protect_api_host', '' ) );
	}
}
