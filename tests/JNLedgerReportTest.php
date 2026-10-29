<?php
/**
 * The running-state report built from Jetpack Beta's dev builds.
 *
 * @package companion
 */

namespace Automattic\JetpackBeta {
	if ( ! class_exists( Plugin::class ) ) {
		class Plugin {
			public static $plugins = array();

			private $path;

			public function __construct( $path ) {
				$this->path = $path;
			}

			public static function get_all_plugins() {
				return self::$plugins;
			}

			public function is_active( $which ) {
				return 'dev' === $which;
			}

			public function dev_plugin_path() {
				return $this->path;
			}
		}
	}
}

namespace {
	use Automattic\JetpackBeta\Plugin;
	use PHPUnit\Framework\TestCase;

	if ( ! defined( 'JPBETA__PLUGIN_FOLDER' ) ) {
		define( 'JPBETA__PLUGIN_FOLDER', 'jetpack-beta' );
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		function is_plugin_active( $plugin ) {
			return 'jetpack-beta/jetpack-beta.php' === $plugin;
		}
	}

	if ( ! function_exists( 'get_file_data' ) ) {
		// WordPress's own, without the extra-headers filter.
		function get_file_data( $file, $default_headers, $context = '' ) {
			$file_data = str_replace( "\r", "\n", (string) file_get_contents( $file, false, null, 0, 8192 ) );
			foreach ( $default_headers as $field => $regex ) {
				if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
					$default_headers[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
				} else {
					$default_headers[ $field ] = '';
				}
			}
			return $default_headers;
		}
	}

	class JNLedgerReportTest extends TestCase {

		private $dir;

		protected function setUp(): void {
			$GLOBALS['jn_site_transients'] = array();
			$this->dir                     = sys_get_temp_dir() . '/companion-report-' . uniqid();
			mkdir( $this->dir . '/jetpack-dev', 0777, true );
			Plugin::$plugins = array( 'jetpack' => new Plugin( $this->dir . '/jetpack-dev/jetpack.php' ) );
		}

		protected function tearDown(): void {
			foreach ( glob( $this->dir . '/jetpack-dev/{,.}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->dir . '/jetpack-dev' );
			rmdir( $this->dir );
		}

		private function install( $installed_version, $header_version ) {
			file_put_contents(
				$this->dir . '/jetpack-dev/.jpbeta.json',
				json_encode(
					array(
						'source'  => 'pr',
						'id'      => 'add_thing',
						'branch'  => 'add/thing',
						'version' => $installed_version,
					)
				)
			);
			if ( null !== $header_version ) {
				file_put_contents( $this->dir . '/jetpack-dev/jetpack.php', "<?php\n/**\n * Plugin Name: Jetpack\n * Version: $header_version\n */\n" );
			}
		}

		public function test_reports_the_version_on_disk_next_to_the_installed_one() {
			$this->install( '15.3-a.1-18000000002-gbbbbbbbb', '15.3-a.1-18000000001-gaaaaaaaa' );

			$this->assertSame(
				array(
					'jetpack_beta' => true,
					'plugins'      => array(
						array(
							'plugin'       => 'jetpack',
							'source'       => 'pr',
							'branch'       => 'add/thing',
							'version'      => '15.3-a.1-18000000002-gbbbbbbbb',
							'file_version' => '15.3-a.1-18000000001-gaaaaaaaa',
						),
					),
				),
				Companion_JN_Ledger::get_installed()
			);
		}

		public function test_a_missing_main_file_reports_an_empty_file_version() {
			$this->install( '15.3-a.1-18000000002-gbbbbbbbb', null );

			$this->assertSame( '', Companion_JN_Ledger::get_installed()['plugins'][0]['file_version'] );
		}
	}
}
