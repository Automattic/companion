<?php
/**
 * Tests for Companion_Rehearsal_Requirements.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * Decides which rehearsals can run on this site.
 */
class RequirementsTest extends TestCase {

	public function test_indexes_plugins_by_folder_and_text_domain() {
		$index = Companion_Rehearsal_Requirements::index_plugins(
			array(
				'jetpack-dev/jetpack.php' => array( 'Name' => 'Jetpack', 'TextDomain' => 'jetpack' ),
				'hello.php'               => array( 'Name' => 'Hello Dolly', 'TextDomain' => 'hello-dolly' ),
			),
			static fn() => true
		);
		$this->assertArrayHasKey( 'jetpack', $index );
		$this->assertArrayHasKey( 'jetpack-dev', $index );
		$this->assertArrayHasKey( 'hello', $index );
	}

	public function test_an_active_copy_wins_over_an_inactive_one() {
		$index = Companion_Rehearsal_Requirements::index_plugins(
			array(
				'jetpack/jetpack.php'     => array( 'Name' => 'Jetpack', 'TextDomain' => 'jetpack' ),
				'jetpack-dev/jetpack.php' => array( 'Name' => 'Jetpack Beta copy', 'TextDomain' => 'jetpack' ),
			),
			static fn( $file ) => 'jetpack-dev/jetpack.php' === $file
		);
		$this->assertTrue( $index['jetpack']['active'] );
	}

	/**
	 * @dataProvider sites
	 *
	 * @param array  $rehearsal Rehearsal settings.
	 * @param string $expected  "kind:state" of each unmet requirement, comma-separated.
	 */
	public function test_finds_what_is_missing( $rehearsal, $expected ) {
		$missing = Companion_Rehearsal_Requirements::find_missing(
			$rehearsal,
			array(
				'jetpack' => array( 'name' => 'Jetpack', 'active' => true ),
				'akismet' => array( 'name' => 'Akismet', 'active' => false ),
			),
			array( 'child-theme', 'twentytwentyfour' ),
			array( 'twentytwentyfive' => 'Twenty Twenty-Five' ),
			array( 'administrator', 'editor' )
		);
		$this->assertSame( $expected, implode( ',', array_map( static fn( $m ) => $m['kind'] . ':' . $m['state'], $missing ) ) );
	}

	public function sites() {
		return array(
			'nothing required'              => array( array(), '' ),
			'active plugin'                 => array( array( 'requires' => array( 'jetpack' ) ), '' ),
			'inactive plugin'               => array( array( 'requires' => array( 'akismet' ) ), 'plugin:inactive' ),
			'plugin not installed'          => array( array( 'requires' => array( 'woocommerce' ) ), 'plugin:missing' ),
			'parent of the active theme'    => array( array( 'theme' => 'twentytwentyfour' ), '' ),
			'installed but inactive theme'  => array( array( 'theme' => 'twentytwentyfive' ), 'theme:inactive' ),
			'theme not installed'           => array( array( 'theme' => 'astra' ), 'theme:missing' ),
			'any one theme of a list'       => array( array( 'theme' => array( 'astra', 'twentytwentyfour' ) ), '' ),
			'existing role'                 => array( array( 'role' => 'editor' ), '' ),
			'unknown role'                  => array( array( 'role' => 'shop_manager' ), 'role:missing' ),
			'several unmet at once'         => array( array( 'requires' => array( 'akismet', 'woocommerce' ), 'theme' => 'astra', 'role' => 'shop_manager' ), 'plugin:inactive,plugin:missing,role:missing,theme:missing' ),
		);
	}
}
