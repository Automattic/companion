<?php
/**
 * Plugin Name: Companion Plugin
 * Plugin URI: https://github.com/Automattic/companion
 * Description: Helps keep the launched WordPress in order.
 * Version: 1.28
 * Author: Osk
*/

// Do nothing if it happens to be running in an Atomic site.
// This may happen when importing a full Jurassic Ninja site into an Atomic one
// and this plugin is eventually imported into the site.
if ( defined( 'IS_ATOMIC' ) && IS_ATOMIC ) {
	return true;
}

// Do a minimal set of stuff on a multisite installation on sites other than the main one
if ( is_multisite() && ! is_main_site() ) {
	add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );
	add_action( 'admin_notices', 'companion_admin_notices' );
	return true;
}

add_action( 'wp_login', 'companion_wp_login', 1, 2 );
add_action( 'after_setup_theme', 'companion_after_setup_theme' );
add_action( 'admin_notices', 'companion_admin_notices' );
add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );
/*
 * Run this function as early as we can relying in WordPress loading plugin in alphabetical order
 */
companion_tamper_with_jetpack_constants();
add_action( 'init', 'companion_add_jetpack_constants_option_page' );

/**
 * Get the API base URL for Jurassic Ninja.
 *
 * @return string
 */
function companion_get_api_base_url() {
	return (string) get_option( 'companion_api_base_url', 'https://jurassic.ninja/wp-json/jurassic.ninja' );
}

function companion_clipboard( $target, $inner = '&#x1f4cb;' ) {
	?>
	<a class="jurassic_ninja_field_clipboard" target="<?php echo esc_attr( $target ); ?>" href="#"><?php echo $inner; ?></a>
	<?php
}

/**
 * Get the number of days remaining before this site is destroyed.
 *
 * @return int
 */
function companion_get_days_remaining() {
	$last_checkin = (int) companion_get_multisite_option( 'jurassic_ninja_last_checkin', 0 );
	if ( 0 === $last_checkin ) {
		return 0;
	}

	$last_date = new DateTimeImmutable( "@{$last_checkin}" );
	$now = new DateTimeImmutable();

	$diff = date_diff( $now, $last_date );

	return 7 - $diff->d;
}

function companion_admin_notices() {
	if ( ! companion_get_option( 'jurassic_ninja_credentials_notice', true ) ) {
		return;
	}
	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen->id === 'post' ) {
			return;
		}
	}
	$password_option_key = 'jurassic_ninja_admin_password';
	$sysuser_option_key = 'jurassic_ninja_sysuser';
	$admin_password = is_multisite() ? get_blog_option( 1, $password_option_key ) : get_option( $password_option_key );
	$ssh_password = $admin_password;
	$sysuser = is_multisite() ? get_blog_option( 1, $sysuser_option_key ) : get_option( $sysuser_option_key );
	$host = parse_url( network_site_url(), PHP_URL_HOST );
	$sftp = 'sftp://'. $sysuser . ':' . $admin_password . '@' . $host . ':22/' . get_home_path(); // Extra `/` after port is needed for some SFTP apps
	$ssh = 'ssh ' . $sysuser . '@'. $host;
	?>
	<div class="notice notice-success is-dismissible">
		<h3 class="jurassic_ninja_welcome">
			<img src="https://i2.wp.com/jurassic.ninja/wp-content/uploads/2018/05/jurassicninja-transparent.png?w=80&ssl=1" alt="">
			<?php echo esc_html__( 'Welcome to Jurassic Ninja!' ); ?>
		</h3>
		<p>
			<strong><span id="jurassic_url" class="jurassic_ninja_field"><?php echo esc_html( network_site_url() ); ?></span></strong>
			<?php echo esc_html__( 'will be destroyed in 7 days.' ); ?>
			<?php companion_clipboard( 'jurassic_url' ); ?>
		</p>
		<p>
			<strong>User:</strong> <code id="jurassic_username" class="jurassic_ninja_field">demo</code> 
			<code id="jurassic_password" class="jurassic_ninja_field"><?php echo esc_html( $admin_password ); ?></code>
			<?php companion_clipboard( 'jurassic_password' ); ?>
		</p>
		<p>
			<strong>SSH User:</strong> <code id="jurassic_ssh_user" class="jurassic_ninja_field"><?php echo esc_html( $sysuser ); ?></code>
			<strong>Password:</strong> <code id="jurassic_ssh_password" class="jurassic_ninja_field"><?php echo esc_html( $ssh_password ); ?></code>
			<?php companion_clipboard( 'jurassic_ssh_user' ); ?>
			<?php companion_clipboard( 'jurassic_ssh_password' ); ?>
			<span style="display:none" id="jurassic_ssh"><?php echo esc_html( $ssh ); ?></span>
			<span style="display:none" id="jurassic_sftp"><?php echo esc_html( $sftp ); ?></span>
			<strong>SSH command</strong>
			<?php companion_clipboard( 'jurassic_ssh' ); ?>
			<strong>SFTP connection string</strong>
			<?php companion_clipboard( 'jurassic_sftp' ); ?>
		</p>
		<p>
			<strong>Server path:</strong> <code id="jurassic_ninja_server_path" class="jurassic_ninja_field"><?php echo esc_html( get_home_path() ); ?></code>
			<?php companion_clipboard( 'jurassic_ninja_server_path' ); ?>
		</p>
	</div>
	<style type="text/css">
		.jurassic_ninja_welcome {
			display: flex;
			align-items: center;
		}
		.jurassic_ninja_welcome img {
			margin: 0 5px 0 0;
			max-width: 40px;
		}

		code.jurassic_ninja_field {
			color: #0366d6;
			background: #eff7ff;
			font: .95em/2 SFMono-Regular,Consolas,Liberation Mono,Menlo,monospace;
		}
		.jurassic_ninja_field_clipboard {
			user-select: all;
			cursor: pointer;
		}
	</style>
	<script>
		/**
		 * Helper to copy-paste credential fields in notice
		 */
		function jurassic_ninja_clippy( str ) {
			var el = document.createElement( 'input' );
			el.value = str;
			document.body.appendChild( el );
			el.select();
			document.execCommand( 'copy' );
			document.body.removeChild( el );
		};

		var jurassic_ninja_fields = document.getElementsByClassName( 'jurassic_ninja_field_clipboard' );

		// IE11 compatible way to loop this
		// https://developer.mozilla.org/en-US/docs/Web/API/NodeList#Example
		Array.prototype.forEach.call( jurassic_ninja_fields, function ( field ) {
			field.addEventListener( 'click', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				// These html entities are represented by images in the end
				// So we need to figure out if the target of the click is a link or an img
				const isChild = ! e.target.getAttribute( 'target' );
				const el = ! isChild ?
					document.getElementById( e.target.getAttribute( 'target' ) ) :
					document.getElementById( e.target.parentNode.getAttribute( 'target' ) );
				const str = el.innerText; 
				jurassic_ninja_clippy( str );
				// Transition to checkmark and back
				if ( isChild ) {
					const parent = e.target.parentNode;
					parent.innerHTML = '&#10004;';
					setTimeout( () => {
						parent.innerHTML= '&#x1f4cb;';
					}, 1000 );
				}
			} );
		} );
	</script>
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
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$auto_login = companion_get_option( 'auto_login' );

	companion_update_multisite_option( 'auto_login', 0 );
	companion_update_multisite_option( 'jurassic_ninja_last_checkin', time() );

	if ( empty( $auto_login ) ) {
		companion_api_request( 'extend' );
	} else {
		companion_api_request( 'checkin' );
		wp_safe_redirect( admin_url() );
		exit( 0 );
	}
}


function companion_after_setup_theme() {
	$auto_login = get_option( 'auto_login' );
	// Only autologin for requests to the homepage.
	if ( ! empty( $auto_login ) && ( $_SERVER['REQUEST_URI'] == '/' ) ) {
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
	$jetpack_beta_present_and_supports_jetpack_constants_settings = class_exists( 'Jetpack_Beta' ) &&
		version_compare( JPBETA_VERSION, '3', '>' );
	if ( $jetpack_beta_present_and_supports_jetpack_constants_settings ) {
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
				),
			),
		),
	);

	$jetpack_sandbox_domain = defined( 'JETPACK__SANDBOX_DOMAIN' ) ? JETPACK__SANDBOX_DOMAIN : '';
	$jetpack_protect_api_host = defined( 'JETPACK_PROTECT__API_HOST' ) ? JETPACK_PROTECT__API_HOST : '';

	$global_fields = array(
		'jetpack_sandbox_domain' => array(
			'id' => 'jetpack_sandbox_domain',
			'title' => __( 'JETPACK__SANDBOX_DOMAIN', 'companion' ),
			'text' => sprintf(
				esc_html__( "The domain of a WordPress.com Sandbox to which you wish to send all of Jetpack's remote requests. Current value for JETPACK__SANDBOX_DOMAIN: %s", 'companion' ),
				'<code>' . esc_html( $jetpack_sandbox_domain ) . '</code>'
			),
			'placeholder' => esc_attr( $jetpack_sandbox_domain ),
		),
		'jurassic_ninja_credentials_notice' => array(
			'id' => 'jurassic_ninja_credentials_notice',
			'title' => __( 'Jurassic Ninja Credentials', 'companion' ),
			'text' => esc_html__( 'Show Jurassic Ninja Credentials on every page', 'companion' ),
			'type' => 'checkbox',
			'checked' => companion_get_option( 'jurassic_ninja_credentials_notice', true ) ,
		),
	);

	$jetpack_fields = array();
	if ( companion_is_jetpack_here() ) {
		$jetpack_fields = array(
			'jetpack_beta_blocks' => array(
				'id' => 'jetpack_beta_blocks',
				'title' => __( 'JETPACK_BETA_BLOCKS', 'companion' ),
				'text' =>
					esc_html__( 'Check to enable Jetpack Beta blocks', 'companion' ),
				'type' => 'checkbox',
			),
			'jetpack_experimental_blocks' => array(
				'id' => 'jetpack_experimental_blocks',
				'title' => __( 'JETPACK_EXPERIMENTAL_BLOCKS', 'companion' ),
				'text' =>
					esc_html__( 'Check to enable experimental Jetpack blocks', 'companion' ),
				'type' => 'checkbox',
			),
			'jetpack_protect_api_host' => array(
				'id' => 'jetpack_protect_api_host',
				'title' => __( 'JETPACK_PROTECT__API_HOST', 'companion' ),
				'text' => sprintf(
					esc_html__( "Base URL for API requests to Jetpack Brute force Protection REST API. Current value for JETPACK_PROTECT__API_HOST: %s", 'companion' ),
					'<code>' . esc_html( $jetpack_protect_api_host ) . '</code>'
				),
				'placeholder' => esc_attr( $jetpack_protect_api_host ),
			),
			'jetpack_should_not_use_connection_iframe' => array(
				'id' => 'jetpack_should_not_use_connection_iframe',
				'title' => __( 'JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME', 'companion' ),
				'text' => sprintf(
					esc_html__( "Don't use connection iFrame (dropped in Jetpack 10.1)", 'companion' )
				),
				'type' => 'checkbox',
			),
			'jetpack_dev_debug' => array(
				'id' => 'jetpack_dev_debug',
				'title' => __( 'JETPACK_DEV_DEBUG', 'companion' ),
				'text' =>
					esc_html__( 'Check to enable offline mode, and access features that can be used without a connection to WordPress.com', 'companion' ),
				'type' => 'checkbox',
			),
		);
	}

	/**
	 * Jetpack Protect options
	 */
	if ( class_exists( 'Jetpack_Protect' ) ) {
		$options_page['companion']['sections']['jetpack_protect'] = array(
			'title' => __( 'Jetpack Protect', 'companion' ),
			'text'  => '<p>' . __( 'Configure some defaults constants used by Jetpack Protect development plugin', 'companion' ) . '</p>',
			'fields' => array(
				'jetpack_protect_bypass_cache' => array(
					'id' => 'jetpack_protect_bypass_cache',
					'title' => __( 'Bypass cache', 'companion' ),
					'text' =>
						esc_html__( 'Check to bypass local cache and always ask WPCOM for fresh information on vulnerabilities', 'companion' ),
					'type' => 'checkbox',
				),
				'jetpack_protect_response_type' => array(
					'id' => 'jetpack_protect_response_type',
					'title' => __( 'Response type', 'companion' ),
					'text' => sprintf(
						esc_html__( "What response would you like to get from WPCOM?", 'companion' )
					),
					'type' => 'select',
					'choices' => array(
						'empty' => 'Empty: Response as if the first check was not performed yet',
						'complete_green' => 'Complete Green: Response will include all plugins and zero vulnerabitlies',
						'incomplete_green' => 'Incomplete Green: Response will miss one plugin and have zero vulnerabilities',
						'complete' => 'Complete: Response will include all plugins and 2 of them will have vulnerabilities',
						'incomplete' => 'Incomplete: Response will miss one plugin and 2 of them will have vulnerabilities',
					),
				),
				'jetpack_protect_core_vuls' => array(
					'id' => 'jetpack_protect_core_vuls',
					'title' => __( 'Number of core vulnerabilities', 'companion' ),
					'text' =>
						esc_html__( 'The number of vulnerabilities found in WP core that the response should include.', 'companion' ),
					'type' => 'number',
				),
			),
		);
	}
	/**
	 * End of Jetpack Protect options
	 */

	$options_page['companion']['sections']['jetpack_tweaks']['fields'] = array_merge( $global_fields, $jetpack_fields );

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
	if ( ! ( defined( 'JETPACK__SANDBOX_DOMAIN' ) && JETPACK__SANDBOX_DOMAIN ) && companion_get_option( 'jetpack_sandbox_domain', '' ) ) {
		define( 'JETPACK__SANDBOX_DOMAIN', companion_get_option( 'jetpack_sandbox_domain', '' ) );
	}

	if ( ! ( defined( 'JETPACK_PROTECT__API_HOST' ) && JETPACK_PROTECT__API_HOST ) && companion_get_option( 'jetpack_protect_api_host', '' ) ) {
		define( 'JETPACK_PROTECT__API_HOST', companion_get_option( 'jetpack_protect_api_host', '' ) );
	}
	if ( ! ( defined( 'JETPACK_BETA_BLOCKS' ) && JETPACK_BETA_BLOCKS ) && companion_get_option( 'jetpack_beta_blocks', '' ) ) {
		define( 'JETPACK_BETA_BLOCKS', (bool) companion_get_option( 'jetpack_beta_blocks', '' ) );
	}
	if ( ! ( defined( 'JETPACK_EXPERIMENTAL_BLOCKS' ) && JETPACK_EXPERIMENTAL_BLOCKS ) && companion_get_option( 'jetpack_experimental_blocks', '' ) ) {
		define( 'JETPACK_EXPERIMENTAL_BLOCKS', (bool) companion_get_option( 'jetpack_experimental_blocks', '' ) );
	}
	if ( ! ( defined( 'JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME' ) && JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME ) && companion_get_option( 'jetpack_should_not_use_connection_iframe', '' ) ) {
		define( 'JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME', (bool) companion_get_option( 'jetpack_should_not_use_connection_iframe', '' ) );
	}
	if ( ! ( defined( 'JETPACK_DEV_DEBUG' ) && JETPACK_DEV_DEBUG ) && companion_get_option( 'jetpack_dev_debug', '' ) ) {
		define( 'JETPACK_DEV_DEBUG', (bool) companion_get_option( 'jetpack_dev_debug', '' ) );
	}

	if ( ! ( defined( 'JETPACK_ENABLE_MY_JETPACK' ) && JETPACK_ENABLE_MY_JETPACK ) && companion_get_option( 'jetpack_enable_my_jetpack', '' ) ) {
		define( 'JETPACK_ENABLE_MY_JETPACK', (bool) companion_get_option( 'jetpack_enable_my_jetpack', '' ) );
	}

	// Jetpack Protect options
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__BYPASS_CACHE' ) && JETPACK_PROTECT_DEV__BYPASS_CACHE ) && companion_get_option( 'jetpack_protect_bypass_cache', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__BYPASS_CACHE', (bool) companion_get_option( 'jetpack_protect_bypass_cache', '' ) );
	}
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__API_RESPONSE_TYPE' ) && JETPACK_PROTECT_DEV__API_RESPONSE_TYPE ) && companion_get_option( 'jetpack_protect_response_type', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__API_RESPONSE_TYPE', companion_get_option( 'jetpack_protect_response_type', '' ) );
	}
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__API_CORE_VULS' ) && JETPACK_PROTECT_DEV__API_CORE_VULS ) && companion_get_option( 'jetpack_protect_core_vuls', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__API_CORE_VULS', companion_get_option( 'jetpack_protect_core_vuls', '' ) );
	}
}

/**
 * Make an API request to the main Jurassic Ninja server.
 *
 * @param string $endpoint The endpoint to request. Valid values are 'extend' and 'checkin'.
 *
 * @return void
 * @throws Exception When an invalid endpoint is provided.
 */
function companion_api_request( string $endpoint ) {
	$endpoint = trim( $endpoint, ' /' );
	$valid_endpoints = [
		'extend' => true,
		'checkin' => true,
	];

	if ( ! isset( $valid_endpoints[ $endpoint ] ) ) {
		throw new Exception( sprintf( 'Invalid endpoint: %s', $endpoint ) );
	}

	$api_base_url = companion_get_api_base_url();
	$domain       = parse_url( network_site_url(), PHP_URL_HOST );
	wp_remote_post(
		"{$api_base_url}/{$endpoint}",
		[
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => json_encode( [ 'domain' => $domain ] ),
		]
	);
}

/**
 * Get an option that should be set on the main site for multisite installs.
 *
 * @param string $option The option name.
 * @param mixed $default (Optional) The default value to return if the option is not set.
 *
 * @return mixed
 */
function companion_get_multisite_option( string $option, $default = null ) {
	return is_multisite() ? get_blog_option( 1, $option, $default ) : get_option( $option, $default );
}

/**
 * Update an option that should be set on the main site for multisite installs.
 *
 * @param string $option The option name.
 * @param mixed $value The option value.
 *
 * @return bool
 */
function companion_update_multisite_option( string $option, $value ) {
	return is_multisite() ? update_blog_option( 1, $option, $value ) : update_option( $option, $value );
}
