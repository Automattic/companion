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
		);
	}

	public function test_accepts_every_setting_together() {
		$rehearsal = array(
			'format'   => 1,
			'requires' => array( 'jetpack' ),
			'theme'    => array( 'twentytwentyfour', 'twentytwentyfive' ),
			'role'     => 'editor',
			'budgets'  => array( 'lcp' => 2500, 'cls' => 0.1 ),
			'covers'   => array( 'packages/forms', 'src/checkout/*' ),
			'timeout'  => 20000,
			'steps'    => array(
				array( 'goto' => '/wp-admin/' ),
				array( 'expect' => 'h1', 'not' => true, 'toBeVisible' => true, 'optional' => true, 'timeout' => 4000 ),
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
