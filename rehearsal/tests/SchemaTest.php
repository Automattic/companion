<?php
/**
 * Tests for Companion_Rehearsal_Schema.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * Validation decides what may run in an admin's browser, and the ID decides which results count.
 */
class SchemaTest extends TestCase {

	/**
	 * @dataProvider invalid_rehearsals
	 *
	 * @param array  $rehearsal Decoded rehearsal.
	 * @param string $expected  Part of the error message.
	 */
	public function test_rejects_invalid_rehearsals( $rehearsal, $expected ) {
		$this->assertStringContainsString( $expected, Companion_Rehearsal_Schema::validate( $rehearsal ) );
	}

	public function invalid_rehearsals() {
		$step = array( array( 'goto' => '/' ) );
		return array(
			'no steps'                    => array( array( 'steps' => array() ), 'non-empty "steps"' ),
			'goto to another site'        => array( array( 'steps' => array( array( 'goto' => '//evil.example/' ) ) ), '"goto" must be a path' ),
			'goto with a full URL'        => array( array( 'steps' => array( array( 'goto' => 'https://evil.example/' ) ) ), '"goto" must be a path' ),
			'two actions in one step'     => array( array( 'steps' => array( array( 'hover' => '#a', 'click' => '#b' ) ) ), 'exactly one of' ),
			'unknown action'              => array( array( 'steps' => array( array( 'evaluate' => 'alert(1)' ) ) ), 'exactly one of' ),
			'expect without a matcher'    => array( array( 'steps' => array( array( 'expect' => 'h1' ) ) ), '"expect" needs exactly one of' ),
			'expect with two matchers'    => array( array( 'steps' => array( array( 'expect' => 'h1', 'toHaveText' => 'a', 'toBeVisible' => true ) ) ), '"expect" needs exactly one of' ),
			'selectOption without value'  => array( array( 'steps' => array( array( 'selectOption' => '#x' ) ) ), '"selectOption" needs a "value"' ),
			'press without a key'         => array( array( 'steps' => array( array( 'press' => '' ) ) ), '"press" needs a key' ),
			'unknown budget'              => array( array( 'budgets' => array( 'speed' => 1 ), 'steps' => $step ), '"budgets"' ),
			'negative budget'             => array( array( 'budgets' => array( 'lcp' => -1 ), 'steps' => $step ), '"budgets"' ),
			'plugin slug with spaces'     => array( array( 'requires' => array( 'my plugin' ), 'steps' => $step ), '"requires"' ),
			'theme slug with punctuation' => array( array( 'theme' => 'Twenty Four!', 'steps' => $step ), '"theme"' ),
			'role with capitals'          => array( array( 'role' => 'Editor', 'steps' => $step ), '"role"' ),
			'covers label with spaces'    => array( array( 'covers' => array( 'packages forms' ), 'steps' => $step ), '"covers"' ),
			'needs something else'        => array( array( 'needs' => 'selenium', 'steps' => $step ), '"needs"' ),
			'format zero'                 => array( array( 'format' => 0, 'steps' => $step ), '"format"' ),
			'format as text'              => array( array( 'format' => '2', 'steps' => $step ), '"format"' ),
			// An object where a list belongs breaks the whole Rehearsal screen, which maps over it.
			'steps as an object'          => array( array( 'steps' => array( 'a' => array( 'goto' => '/' ) ) ), 'non-empty "steps"' ),
			'covers as an object'         => array( array( 'covers' => array( 'a' => 'x' ), 'steps' => $step ), '"covers"' ),
			'requires as an object'       => array( array( 'requires' => array( 'a' => 'jetpack' ), 'steps' => $step ), '"requires"' ),
			'theme as an object'          => array( array( 'theme' => array( 'a' => 'twentytwentyfour' ), 'steps' => $step ), '"theme"' ),
			'goto null'                   => array( array( 'steps' => array( array( 'goto' => null ) ) ), '"goto" must be a path' ),
			'goto with a backslash'       => array( array( 'steps' => array( array( 'goto' => '/\\evil.example' ) ) ), '"goto" must be a path' ),
			'goto with a tab'             => array( array( 'steps' => array( array( 'goto' => "/\t/evil.example" ) ) ), '"goto" must be a path' ),
			'selector null'               => array( array( 'steps' => array( array( 'click' => null ) ) ), '"click" must be a selector' ),
			'timeout as text'             => array( array( 'timeout' => '10s', 'steps' => $step ), '"timeout"' ),
			'step timeout as code'        => array( array( 'steps' => array( array( 'click' => '#a', 'timeout' => '1 ); process.exit( 1 ); ( 2' ) ) ), '"timeout"' ),
			'toHaveCount as text'         => array( array( 'steps' => array( array( 'expect' => 'li', 'toHaveCount' => '3' ) ) ), '"toHaveCount"' ),
			'screenshot outside its dir'  => array( array( 'steps' => array( array( 'screenshot' => '../../x' ) ) ), '"screenshot"' ),
			'ignore as text'              => array( array( 'steps' => array( array( 'expectNoConsoleErrors' => array( 'ignore' => 'x' ) ) ) ), '"expectNoConsoleErrors"' ),
			// false used to check the same thing as true.
			'toBeVisible false'           => array( array( 'steps' => array( array( 'expect' => 'h1', 'toBeVisible' => false ) ) ), '"not": true' ),
			'toBeChecked false'           => array( array( 'steps' => array( array( 'expect' => '#a', 'toBeChecked' => false ) ) ), '"toBeChecked" must be true' ),
			'toBeEnabled as text'         => array( array( 'steps' => array( array( 'expect' => '#a', 'toBeEnabled' => 'yes' ) ) ), '"toBeEnabled"' ),
			'viewport tablet'             => array( array( 'viewport' => 'tablet', 'steps' => $step ), '"viewport" must be "desktop" or "mobile"' ),
			'dialog with another answer'  => array( array( 'steps' => array( array( 'dialog' => 'ok' ) ) ), '"dialog" must be "accept" or "dismiss"' ),
			'requires with a bad or'      => array( array( 'requires' => array( 'jetpack or' ), 'steps' => $step ), '"requires"' ),
			'requires with a comma'       => array( array( 'requires' => array( 'jetpack, akismet' ), 'steps' => $step ), '"requires"' ),
		);
	}

	public function test_accepts_every_setting_together() {
		$rehearsal = array(
			'format'   => 1,
			'requires' => array( 'jetpack', 'akismet or antispam-bee' ),
			'theme'    => array( 'twentytwentyfour', 'twentytwentyfive' ),
			'role'     => 'editor',
			'viewport' => 'mobile',
			'budgets'  => array( 'lcp' => 2500, 'cls' => 0.1 ),
			'covers'   => array( 'packages/forms', 'src/checkout/*' ),
			'timeout'  => 20000,
			'steps'    => array(
				array( 'goto' => '/wp-admin/' ),
				array( 'expect' => 'h1', 'not' => true, 'toBeVisible' => true, 'optional' => true, 'timeout' => 4000 ),
				array( 'dialog' => 'accept', 'value' => 'Ada' ),
				array( 'dialog' => 'dismiss' ),
			),
		);
		$this->assertSame( '', Companion_Rehearsal_Schema::validate( $rehearsal ) );
	}

	public function test_the_id_ignores_formatting_but_not_steps() {
		$pretty  = json_decode( "{\n  \"steps\": [ { \"goto\": \"/\" } ]\n}", true );
		$compact = json_decode( '{"steps":[{"goto":"/"}]}', true );
		$changed = json_decode( '{"steps":[{"goto":"/wp-admin/"}]}', true );
		$id      = static fn( $r ) => Companion_Rehearsal_Schema::hash( Companion_Rehearsal_Schema::canonical( $r ) );

		$this->assertSame( $id( $pretty ), $id( $compact ) );
		$this->assertNotSame( $id( $compact ), $id( $changed ) );
	}

	public function test_the_id_ignores_key_order_but_not_step_order() {
		$id = static fn( $json ) => Companion_Rehearsal_Schema::hash( Companion_Rehearsal_Schema::canonical( json_decode( $json, true ) ) );

		$this->assertSame(
			$id( '{"requires":["jetpack"],"steps":[{"fill":"#a","value":"x"},{"expect":"h1","toHaveText":"Hi"}]}' ),
			$id( '{"steps":[{"value":"x","fill":"#a"},{"toHaveText":"Hi","expect":"h1"}],"requires":["jetpack"]}' )
		);
		$this->assertNotSame(
			$id( '{"steps":[{"goto":"/a"},{"goto":"/b"}]}' ),
			$id( '{"steps":[{"goto":"/b"},{"goto":"/a"}]}' )
		);
	}

	public function test_the_id_ignores_the_format() {
		$id = static fn( $r ) => Companion_Rehearsal_Schema::hash( Companion_Rehearsal_Schema::canonical( $r ) );
		$this->assertSame( $id( array( 'steps' => array( array( 'goto' => '/' ) ) ) ), $id( array( 'format' => 1, 'steps' => array( array( 'goto' => '/' ) ) ) ) );
	}

	/**
	 * @dataProvider formats
	 *
	 * @param array $rehearsal Rehearsal.
	 * @param bool  $newer     Whether it was written for a newer Rehearsal.
	 */
	public function test_knows_when_a_rehearsal_is_from_a_newer_version( $rehearsal, $newer ) {
		$this->assertSame( $newer, Companion_Rehearsal_Schema::is_newer( $rehearsal ) );
	}

	public function formats() {
		return array(
			'no format (older rehearsal)' => array( array(), false ),
			'this version'                => array( array( 'format' => Companion_Rehearsal::FORMAT ), false ),
			'a newer version'             => array( array( 'format' => Companion_Rehearsal::FORMAT + 1 ), true ),
		);
	}

	/**
	 * @dataProvider stamps
	 *
	 * @param array $rehearsal Rehearsal as saved.
	 * @param int   $expected  Format it is stored with.
	 */
	public function test_a_saved_rehearsal_keeps_or_gets_a_format( $rehearsal, $expected ) {
		$this->assertSame( $expected, Companion_Rehearsal_Schema::with_format( $rehearsal )['format'] );
	}

	public function stamps() {
		return array(
			'none: the oldest that runs it' => array( array( 'steps' => array( array( 'goto' => '/' ) ) ), 1 ),
			'none, with a dialog step'      => array( array( 'steps' => array( array( 'dialog' => 'accept' ) ) ), 2 ),
			'none, with either-or requires' => array( array( 'requires' => array( 'a or b' ), 'steps' => array() ), 2 ),
			'none, pinned to a viewport'    => array( array( 'viewport' => 'desktop', 'steps' => array() ), 2 ),
			'this version\'s'        => array( array( 'format' => Companion_Rehearsal::FORMAT ), Companion_Rehearsal::FORMAT ),
			'a newer one stays newer' => array( array( 'format' => Companion_Rehearsal::FORMAT + 1 ), Companion_Rehearsal::FORMAT + 1 ),
		);
	}

	public function test_install_error_says_to_update_for_a_newer_rehearsal() {
		$newer = Companion_Rehearsal_Schema::install_error( array( 'format' => Companion_Rehearsal::FORMAT + 1 ), 'Step 2 must have exactly one of: goto.' );
		$same  = Companion_Rehearsal_Schema::install_error( array( 'format' => Companion_Rehearsal::FORMAT ), 'Step 2 must have exactly one of: goto.' );

		$this->assertStringContainsString( 'Update the Companion plugin', $newer );
		$this->assertStringContainsString( 'Step 2 must have exactly one of', $newer );
		$this->assertStringNotContainsString( 'Update the Companion plugin', $same );
	}

	public function test_canonical_json_keeps_slashes_and_unicode_readable() {
		$json = Companion_Rehearsal_Schema::canonical( array( 'steps' => array( array( 'goto' => '/a?p=%2Fforms', 'expectText' => 'Thank you “Ada”' ) ) ) );
		$this->assertStringContainsString( '/a?p=%2Fforms', $json );
		$this->assertStringContainsString( '“Ada”', $json );
	}
}
