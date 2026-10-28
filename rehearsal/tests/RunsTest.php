<?php
/**
 * Tests for Companion_Rehearsal_Runs.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * What the runner sends is stored as evidence, so cleaning must keep it accurate and bounded.
 */
class RunsTest extends TestCase {

	public function test_log_keeps_messages_verbatim() {
		$msg = 'Step 2: timed out on a[href="/wp-admin/admin.php?p=%2Fforms"] · page showed: <b>Saved</b>';
		$log = Companion_Rehearsal_Runs::clean_log( array( array( 'ok' => true, 'msg' => $msg, 'ms' => 12 ) ) );
		$this->assertSame( $msg, $log[0]['msg'] );
	}

	public function test_log_limits_sizes() {
		$entries = array_fill( 0, 250, array( 'ok' => 1, 'msg' => str_repeat( 'a', 2000 ), 'ms' => -5 ) );
		$log     = Companion_Rehearsal_Runs::clean_log( $entries );

		$this->assertCount( 200, $log );
		$this->assertSame( 1000, strlen( $log[0]['msg'] ) );
		$this->assertTrue( $log[0]['ok'] );
		$this->assertSame( 5, $log[0]['ms'] );
	}

	public function test_page_metrics_keep_only_known_numbers() {
		$log  = Companion_Rehearsal_Runs::clean_log(
			array(
				array(
					'ok'    => true,
					'msg'   => 'goto /',
					'pages' => array(
						array( 'url' => '/', 'lcp' => '1234', 'cls' => 0.05, 'weight' => 'lots', 'secret' => 'x', 'warn' => array( 'lcp', 'bogus' ), 'limits' => array( 'lcp' => '1000', 'bogus' => 1 ) ),
					),
				),
			)
		);
		$page = $log[0]['pages'][0];

		$this->assertSame( 1234, $page['lcp'] );
		$this->assertSame( 0.05, $page['cls'] );
		$this->assertNull( $page['weight'] );
		$this->assertArrayNotHasKey( 'secret', $page );
		$this->assertSame( array( 'lcp' ), $page['warn'] );
		$this->assertSame( array( 'lcp' => 1000.0 ), $page['limits'] );
	}

	public function test_errors_stay_verbatim_for_bug_reports() {
		$errors = Companion_Rehearsal_Runs::clean_errors( array( array( 'msg' => 'Store "x" <already> registered', 'page' => '/a', 'source' => 'at f (x.js:1:2)', 'count' => 0 ) ) );

		$this->assertSame( 'Store "x" <already> registered', $errors[0]['msg'] );
		$this->assertSame( 1, $errors[0]['count'] );
	}
}
