<?php
/*
Plugin Name: Companion Plugin
Plugin URI: https://github.com/Automattic/companion
Description: Helps keep the launched WordPress in order.
Version: 1.32
Author: Osk
*/

// Do a minimal set of stuff on a multisite installation on sites other than the main one
if ( is_multisite() && ! is_main_site() ) {
	add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );

	if( ! defined( 'IS_WP_STUDIO') ) {
        add_action( 'admin_notices', 'companion_admin_notices' );
	}
	return true;
}

$companion_api_base_url = get_option( 'companion_api_base_url' );

// Setup Hooks for WP.cloud
if ( defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN ) {
	add_action( 'wp_login', 'companion_wp_login', 1, 2 );
	add_action( 'pre_current_active_plugins', 'companion_hide_plugin' );

    if( defined( 'IS_WP_STUDIO') && IS_WP_STUDIO ) {
        // TODO : Add hooks for WP Studio sites.
    } else {
	    add_action( 'after_setup_theme', 'companion_after_setup_theme' );
	    add_action( 'admin_notices', 'companion_admin_notices' );

	    /*
         * Run this function as early as we can relying in WordPress loading plugin in alphabetical order
         */
	    companion_tamper_with_jetpack_constants();
	    add_action( 'init', 'companion_add_jetpack_constants_option_page' );
    }

}

// Installed on WP.cloud
if ( class_exists( 'Atomic_Persistent_Data' ) ) {

	$persistent_data = new Atomic_Persistent_Data();

	// Restrictions for Anonymous Sites.
	if ( ! empty( $persistent_data->JN_ANONYMOUS ) ) {

	    define( "IS_ATOMIC_JN_ANONYMOUS", true );

		// Prevent installation and activation of disallowed plugins.
		add_action( 'user_has_cap', 'companion_prevent_disallowed_plugin_from_installing_and_activation', 10, 4 );

		// Restrict email to send only to the admin_email.
		add_filter( 'wp_mail', function ( array $data ) {
			$data['subject'] = ' ' . json_encode( $data['to'] ) . ' ' . $data['subject'];
			$data['to']      = get_option( 'admin_email' );

			return $data;
		} );

		// Force WooPay into Sandbox and testing modes
		add_filter( 'wcpay_dev_mode', '__return_true', 1000 );
		add_filter( 'wcpay_test_mode', '__return_true', 1000 );

	} else {
		// WP.com disables core update permissions, so we need to re-add.
		add_filter( 'user_has_cap', 'companion_user_has_cap', 10, 2 );
    }

}

/**
 * Reset update_core permission that is removed on WP.cloud
 * @param $all
 * @param $caps
 *
 * @return mixed
 */
function companion_user_has_cap( $all, $caps ) {
    if ( isset( $all['install_plugins' ] ) && $all['install_plugins'] == 1 ) {
	    $all['update_core'] = 1;
    }
	return $all;
}

/**
 * Disallowed Plugins from being installed on Jurassic.Ninja sites.
 */
$companion_disallowed_plugins_list = [
    // plugin slugs
];

/**
 * Remove user permissions when attempting to install disallowed plugins
 *
 * @param $all_caps
 * @param $caps
 * @param $args
 * @param $extra
 *
 * @return mixed
 */
function companion_prevent_disallowed_plugin_from_installing_and_activation( $all_caps, $caps, $args, $extra ) {
	global $companion_disallowed_plugins_list;
    // Plugin Install Flow.
    if ( isset( $_POST['slug'] ) ) {
		$slug  = sanitize_key( wp_unslash( $_POST['slug'] ) );

	    if ( in_array( $slug, $companion_disallowed_plugins_list ) ) {
		    unset($all_caps['install_plugins']);
		    unset($all_caps['activate_plugins']);
		    unset($all_caps['update_plugins']);
	    }
	}

    // Plugin activation.
	if ( isset( $_GET['plugin'] ) && isset( $_GET['action'] ) && $_GET['action'] == 'activate' ) {
		$slug = explode('/', $_GET['plugin'])[0];

		if ( in_array( $slug, $companion_disallowed_plugins_list ) ) {
			unset($all_caps['install_plugins']);
			unset($all_caps['activate_plugins']);
			unset($all_caps['update_plugins']);
		}
	}
	return $all_caps;
}

function clipboard( $target, $inner = '&#x1f4cb;' ) {
?>
	<a class="jurassic_ninja_field_clipboard" target="<?php echo $target; ?>" href="#"><?php echo $inner; ?></a>
<?php
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
	$sshuser = $sysuser;
	$host = parse_url( network_site_url(), PHP_URL_HOST );
	$sftp = 'sftp://'. $sysuser . ':' . $admin_password . '@' . $host . ':22/' . get_home_path(); // Extra `/` after port is needed for some SFTP apps
	$ssh = 'ssh ' . $sysuser . '@'. $host;
	$path = get_home_path();
	$rsync_command = 'jetpack rsync jetpack ' . $sysuser . '@' . str_replace( 'https://', '', network_site_url() ) . ':' . $path . 'wp-content/plugins/jetpack';

	// Jurassic.Ninja on Atomic credentials
	if ( defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN ) {
		$path = '/srv/htdocs/';
		$persistent_data = new Atomic_Persistent_Data();
		$admin_password  = $persistent_data->JN_PASSWORD;
		$ssh_password    = $admin_password;
		$sshuser         = $host;
		$ssh             = 'ssh ' . $sshuser . '@ssh.atomicsites.net';
		$sftp            = 'sftp://' . $sshuser . ':' . $admin_password . '@ssh.atomicsites.net:22/' . $path;
		$rsync_command   = 'jetpack rsync jetpack ' . $sshuser . '@ssh.atomicsites.net:' . $path .'wp-content/plugins/jetpack';
    }
	?>
	<div class="notice notice-success is-dismissible jn_notice">
		<h3 class="jurassic_ninja_welcome">
			<!-- <img src="https://i2.wp.com/jurassic.ninja/wp-content/uploads/2018/05/jurassicninja-transparent.png?w=80&ssl=1" alt=""> -->
			<?php echo esc_html__( 'Welcome to your Jurassic.Ninja test site!' ); ?>
		</h3>
		<p>
			<strong><span id="jurassic_url" class="jurassic_ninja_field"><?php echo esc_html( network_site_url() ); ?></span></strong>
			<?php clipboard( 'jurassic_url' ); ?>
			<?php
                if( defined( 'IS_ATOMIC_JN_ANONYMOUS' ) ) {
	                echo esc_html__( 'will be destroyed 24 hours from site creation.' );
                } else {
                    echo esc_html__( 'will be destroyed 7 days from last login.' );
                }
                ?>
		</p>
		<p>
			<strong>User:</strong> <code id="jurassic_username" class="jurassic_ninja_field">demo</code>
			<code id="jurassic_password" class="jurassic_ninja_field"><?php echo esc_html( $admin_password ); ?></code>
			<?php clipboard( 'jurassic_password' ); ?>
		</p>
        <?php
                if ( ! defined( 'IS_ATOMIC_JN_ANONYMOUS' ) ) {
        ?>
		<p>
			<strong>SSH User:</strong> <code id="jurassic_ssh_user" class="jurassic_ninja_field"><?php echo esc_html( $sshuser ); ?></code>
			<?php clipboard( 'jurassic_ssh_user' ); ?>
			<strong>Password:</strong> <code id="jurassic_ssh_password" class="jurassic_ninja_field"><?php echo esc_html( $ssh_password ); ?></code>
			<?php clipboard( 'jurassic_ssh_password' ); ?>
			<span style="display:none" id="jurassic_ssh"><?php echo esc_html( $ssh ); ?></span>
			<span style="display:none" id="jurassic_sftp"><?php echo esc_html( $sftp ); ?></span>
			<strong>SSH command</strong>
			<?php clipboard( 'jurassic_ssh' ); ?>
			<strong>SFTP connection string</strong>
			<?php clipboard( 'jurassic_sftp' ); ?>
		</p>
		<p>
			<strong>Server path:</strong> <code id="jurassic_ninja_server_path" class="jurassic_ninja_field"><?php echo esc_html( $path ); ?></code>
			<?php clipboard( 'jurassic_ninja_server_path' ); ?>
		</p>
		<p>
			<strong>Jetpack rsync command:</strong> <code id="jurassic_ninja_rsync_path" class="jurassic_ninja_field"><?php echo esc_html( $rsync_command ); ?></code>
			<?php clipboard( 'jurassic_ninja_rsync_path' ); ?>
		</p>
        <?php } ?>
	</div>
	<style type="text/css">
        .jn_notice {
            border-left-color: #6053F9 !important;
        }

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
	global $companion_api_base_url;

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$auto_login = get_option( 'auto_login' );

	update_option( 'auto_login', 0 );

	// Jurassic.Ninja on Atomic
	if ( defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN ) {
		$urlparts = wp_parse_url( network_site_url() );
		$domain   = $urlparts['host'];
		// WP.COM Public.API Endpoint
		$url      = "https://public-api.wordpress.com/wpcom/v2/jurassic-ninja/extend";
		// Retrieve Shared Token from persistent data.
		$persistent_data = new Atomic_Persistent_Data();
		$api_token = $persistent_data->JN_API_TOKEN;
		wp_remote_post( $url, [
			'headers' => [
			],
			'body'    => [
				'domain'    => $domain,
                'api_token' => $api_token,
			],
		] );
		if ( ! empty ( $auto_login ) ) {
		    if ( empty( get_option( 'big_sky_api_key' ) ) ) {
			    wp_safe_redirect( '/wp-admin' );
		    } else {
			    wp_safe_redirect( '/wp-admin/site-editor.php' );
		    }
			exit( 0 );
		}
	} else {
		if ( empty( $auto_login ) ) {
			$urlparts = wp_parse_url( network_site_url() );
			$domain   = $urlparts['host'];
			$url      = "$companion_api_base_url/extend";
			wp_remote_post( $url, [
				'headers' => [
					'content-type' => 'application/json',
				],
				'body'    => wp_json_encode( [
					'domain' => $domain,
				] ),
			] );
		} else {
			$urlparts = wp_parse_url( network_site_url() );
			$domain   = $urlparts ['host'];
			$url      = "$companion_api_base_url/checkin";
			wp_remote_post( $url, [
				'headers' => [
					'content-type' => 'application/json',
				],
				'body'    => wp_json_encode( [
					'domain' => $domain,
				] ),
			] );
			wp_safe_redirect( '/wp-admin' );
			exit( 0 );
		}
	}
}


function companion_after_setup_theme() {
	$auto_login = get_option( 'auto_login' );
	$do_login = false;

	if ( defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN ) {
	    // Sites on Atomic may see a request before the user.
		if ( ! empty( $auto_login ) && ( $_SERVER['REQUEST_URI'] == '/?auto_login' ) ) {
			$do_login = true;
		}
	} else {
		// Only autologin for requests to the homepage.
		if ( ! empty( $auto_login ) && ( $_SERVER['REQUEST_URI'] == '/' ) ) {
			$do_login = true;
		}
	}

	if ( $do_login ) {
		$password               = get_option( 'jurassic_ninja_admin_password' );
		if ( defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN ) {
			$persistent_data = new Atomic_Persistent_Data();
			$password        = $persistent_data->JN_PASSWORD;
		}
		$creds                  = array();
		$creds['user_login']    = 'demo';
		$creds['user_password'] = $password;
		$creds['remember']      = true;
		$user                   = wp_signon( $creds, companion_site_uses_https() );
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
			'jetpack_blocks_variation' => array(
				'id'      => 'jetpack_blocks_variation',
				'title'   => __( 'Type of blocks to load in the editor', 'companion' ),
				'text'    => esc_html__( 'Choose the type of blocks to load in the editor', 'companion' ),
				'type'    => 'select',
				'choices' => array(
					'production'   => 'Production',
					'beta'         => 'Beta',
					'experimental' => 'Experimental'
				),
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

	/**
	 * My Jetpack options
	 */
	if ( class_exists( 'Automattic\Jetpack\My_Jetpack\Initializer' ) ) {
		$options_page['companion']['sections']['jetpack_my_jetpack'] = array(
			'title' => __( 'My Jetpack', 'companion' ),
			'text'  => '<p>' . __( 'Configure some defaults constants used by My Jetpack development page', 'companion' ) . '</p>',
			'fields' => array(
				'jetpack_my_jetpack_videopress_stats_enabled' => array(
					'id' => 'jetpack_my_jetpack_videopress_stats_enabled',
					'title' => __( 'VideoPress Stats', 'companion' ),
					'text' =>
						esc_html__( 'Enable stats in VideoPress card', 'companion' ),
					'type' => 'checkbox',
				),
				'jetpack_ai' => array(
					'id' => 'jetpack_ai',
					'title' => __( 'Jetpack AI', 'companion' ),
					'text' =>
						esc_html__( 'Enable Jetpack AI feature', 'companion' ),
					'type' => 'checkbox',
				),
			),
		);
	}
	/**
	 * End of My Jetpack options
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
	if ( ! ( defined( 'JETPACK_BLOCKS_VARIATION' ) && JETPACK_BLOCKS_VARIATION ) && companion_get_option( 'jetpack_blocks_variation', '' ) ) {
		define( 'JETPACK_BLOCKS_VARIATION', companion_get_option( 'jetpack_blocks_variation', '' ) );
	}
	if ( ! ( defined( 'JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME' ) && JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME ) && companion_get_option( 'jetpack_should_not_use_connection_iframe', '' ) ) {
		define( 'JETPACK_SHOULD_NOT_USE_CONNECTION_IFRAME', companion_get_option( 'jetpack_should_not_use_connection_iframe', '' ) ? true : false );
	}
	if ( ! ( defined( 'JETPACK_DEV_DEBUG' ) && JETPACK_DEV_DEBUG ) && companion_get_option( 'jetpack_dev_debug', '' ) ) {
		define( 'JETPACK_DEV_DEBUG', companion_get_option( 'jetpack_dev_debug', '' ) ? true : false );
	}

	/**
	 * My Jetpack options
	 */
	if ( ! ( defined( 'JETPACK_MY_JETPACK_VIDEOPRESS_STATS_ENABLED' ) && JETPACK_MY_JETPACK_VIDEOPRESS_STATS_ENABLED ) && companion_get_option( 'jetpack_my_jetpack_videopress_stats_enabled', '' ) ) {
		define( 'JETPACK_MY_JETPACK_VIDEOPRESS_STATS_ENABLED', companion_get_option( 'jetpack_my_jetpack_videopress_stats_enabled', '' ) ? true : false );
	}
	if ( ! ( defined( 'JETPACK_AI_ENABLED' ) && JETPACK_AI_ENABLED ) && companion_get_option( 'jetpack_ai', '' ) ) {
		define( 'JETPACK_AI_ENABLED', companion_get_option( 'jetpack_ai', '' ) ? true : false );
	}
	/**
	 * End of My Jetpack options
	 */

	/**
	 * Jetpack Protect options
	 */
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__BYPASS_CACHE' ) && JETPACK_PROTECT_DEV__BYPASS_CACHE ) && companion_get_option( 'jetpack_protect_bypass_cache', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__BYPASS_CACHE', companion_get_option( 'jetpack_protect_bypass_cache', '' ) ? true : false );
	}
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__API_RESPONSE_TYPE' ) && JETPACK_PROTECT_DEV__API_RESPONSE_TYPE ) && companion_get_option( 'jetpack_protect_response_type', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__API_RESPONSE_TYPE', companion_get_option( 'jetpack_protect_response_type', '' ) );
	}
	if ( ! ( defined( 'JETPACK_PROTECT_DEV__API_CORE_VULS' ) && JETPACK_PROTECT_DEV__API_CORE_VULS ) && companion_get_option( 'jetpack_protect_core_vuls', '' ) ) {
		define( 'JETPACK_PROTECT_DEV__API_CORE_VULS', companion_get_option( 'jetpack_protect_core_vuls', '' ) );
	}
	/**
	 * End of Jetpack Protect options
	 */
}
