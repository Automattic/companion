<?php
/**
 * Tests for Companion_Rehearsal_Playwright.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * A rehearsal must mean the same thing in Playwright as in the browser runner.
 */
class PlaywrightTest extends TestCase {

	/**
	 * Generate a test file for a list of steps plus extra settings.
	 *
	 * @param array $steps    Steps.
	 * @param array $settings Other rehearsal keys.
	 * @return string
	 */
	private function generate( array $steps, array $settings = array() ) {
		return Companion_Rehearsal_Playwright::to_playwright(
			array(
				'title'      => 'A rehearsal',
				'id'         => str_repeat( 'a', 64 ),
				'site'       => 'https://example.test/',
				'definition' => array( 'steps' => $steps ) + $settings,
			)
		);
	}

	/**
	 * @dataProvider translations
	 *
	 * @param array  $step     One step.
	 * @param string $expected A line the generated test must contain.
	 */
	public function test_translates_steps( $step, $expected ) {
		$this->assertStringContainsString( $expected, $this->generate( array( $step ) ) );
	}

	public function translations() {
		return array(
			// The browser runner's text= also matches aria-label; icon-only buttons depend on it.
			'text= also matches aria-label'   => array( array( 'click' => 'text=Add a contact form' ), 'page.locator( "text=Add a contact form" ).or( page.locator( "[aria-label*=\"Add a contact form\"]" ) )' ),
			'iframe part becomes frameLocator' => array( array( 'click' => 'iframe[name="editor-canvas"] >> .x' ), 'page.frameLocator( "iframe[name=\"editor-canvas\"]" ).locator( ".x" )' ),
			'{{run}} becomes the run constant' => array( array( 'fill' => '#name', 'value' => 'Ada {{run}}' ), '.fill( "Ada " + run );' ),
			'acts on the first visible match'  => array( array( 'click' => '#submit' ), 'page.locator( "#submit" ).filter( { visible: true } ).first()' ),
			'not toBeVisible means none shown' => array( array( 'expect' => '.notice', 'not' => true, 'toBeVisible' => true ), 'page.locator( ".notice" ).filter( { visible: true } ) ).toHaveCount( 0 );' ),
			'toHaveCount counts hidden ones'   => array( array( 'expect' => 'tr', 'toHaveCount' => 3 ), 'expect( page.locator( "tr" ) ).toHaveCount( 3 );' ),
			'step timeout is kept'             => array( array( 'waitFor' => '#x', 'timeout' => 4000 ), '.toBeVisible( { timeout: 4000 } );' ),
			'press without a target'           => array( array( 'press' => 'Escape' ), 'await page.keyboard.press( "Escape" );' ),
			'ignored console errors'           => array( array( 'expectNoConsoleErrors' => array( 'ignore' => array( 'already registered' ) ) ), 'new RegExp( "already registered" )' ),
		);
	}


	public function test_optional_steps_cannot_fail_the_test() {
		$this->assertStringContainsString( "await optional( async () => {\n\t\t\tawait click(", $this->generate( array( array( 'click' => '#close', 'optional' => true ) ) ) );
	}

	public function test_no_page_measuring_without_budgets() {
		$code = $this->generate( array( array( 'goto' => '/' ) ) );
		$this->assertStringNotContainsString( 'measure', $code );
		$this->assertStringNotContainsString( 'trackLoads', $code );
	}

	public function test_measures_each_page_load_against_the_rehearsals_budgets() {
		$code = $this->generate( array( array( 'goto' => '/' ), array( 'click' => 'a' ) ), array( 'budgets' => array( 'lcp' => 2500 ) ) );
		$this->assertStringContainsString( 'const measure = await trackLoads( page, {"lcp":2500} );', $code );
		$this->assertSame( 3, substr_count( $code, 'await measure();' ) );
	}

	public function test_a_role_reads_its_own_storage_state_first() {
		$this->assertStringContainsString( 'test.use( { actionTimeout: 10000, storageState: process.env.STORAGE_STATE_EDITOR || process.env.STORAGE_STATE } );', $this->generate( array( array( 'goto' => '/' ) ), array( 'role' => 'editor' ) ) );
	}

	public function test_a_bundle_shares_one_setup_and_keeps_each_rehearsals_settings() {
		$payload = static fn( $title, $def ) => array(
			'title'      => $title,
			'id'         => str_repeat( 'b', 64 ),
			'site'       => 'https://example.test/',
			'definition' => $def,
		);
		$code    = Companion_Rehearsal_Playwright::to_playwright_bundle(
			array(
				$payload( 'Plain', array( 'steps' => array( array( 'goto' => '/' ) ) ) ),
				$payload( 'As editor', array( 'role' => 'editor', 'timeout' => 20000, 'steps' => array( array( 'goto' => '/wp-admin/' ) ) ) ),
				$payload( 'Budgeted', array( 'budgets' => array( 'weight' => 1000 ), 'steps' => array( array( 'goto' => '/' ) ) ) ),
			),
			'https://example.test/'
		);

		$this->assertSame( 1, substr_count( $code, "from '@playwright/test';" ) );
		$this->assertSame( 1, substr_count( $code, 'async function trackLoads(' ) );
		$this->assertSame( 3, substr_count( $code, 'test.describe( ' ) );
		$this->assertSame( 1, substr_count( $code, 'process.env.STORAGE_STATE_EDITOR' ) );
		$this->assertStringContainsString( "test.describe( \"As editor\", () => {\n\tconst expect = baseExpect.configure( { timeout: 20000 } );", $code );
		$this->assertStringContainsString( 'trackLoads( page, {"weight":1000} )', $code );
	}

	public function test_a_title_stays_on_its_comment_line() {
		$code = Companion_Rehearsal_Playwright::to_playwright(
			array(
				'title'      => "Two\nlines",
				'id'         => str_repeat( 'a', 64 ),
				'site'       => 'https://example.test/',
				'definition' => array( 'steps' => array( array( 'goto' => '/' ) ) ),
			)
		);
		$this->assertStringContainsString( '// Rehearsal: Two lines', $code );
	}
}
