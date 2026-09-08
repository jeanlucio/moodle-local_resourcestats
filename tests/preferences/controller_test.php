<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * PHPUnit tests for the preferences page controller.
 *
 * @package    local_resourcestats
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_resourcestats\preferences;

use advanced_testcase;
use local_resourcestats\hook_listener;
use moodle_url;

/**
 * Test cases for local_resourcestats\preferences\controller.
 *
 * handle_post() ends in redirect(), which raises redirecterrordetected under CLI (which
 * PHPUnit always is) — the post-conditions are asserted after catching that exception, since
 * the preference writes always happen before the redirect.
 *
 * @package    local_resourcestats
 * @covers     \local_resourcestats\preferences\controller
 */
final class controller_test extends advanced_testcase {
    /** @var moodle_url The return URL every controller instance in this file is built with. */
    private moodle_url $returnurl;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $_POST = [];
        $_GET = [];

        $this->returnurl = new moodle_url('/course/view.php', ['id' => 2]);
        $this->setUser($this->getDataGenerator()->create_user());
    }

    #[\Override]
    protected function tearDown(): void {
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /**
     * Runs handle_post(), which always ends in a redirect, and asserts that it did.
     *
     * @param controller $controller The controller under test.
     */
    private function assert_redirects(controller $controller): void {
        try {
            $controller->handle_post();
            $this->fail('Expected handle_post() to end in a redirect.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }
    }

    /**
     * Submitting all three checkboxes checked must save all three preferences as enabled.
     */
    public function test_handle_post_saves_all_three_preferences_enabled(): void {
        $_POST['show_total'] = '1';
        $_POST['show_unique'] = '1';
        $_POST['show_lastuser'] = '1';

        $this->assert_redirects(new controller($this->returnurl));

        $this->assertSame('1', get_user_preferences(hook_listener::PREF_SHOW_TOTAL));
        $this->assertSame('1', get_user_preferences(hook_listener::PREF_SHOW_UNIQUE));
        $this->assertSame('1', get_user_preferences(hook_listener::PREF_SHOW_LASTUSER));
    }

    /**
     * Submitting with a checkbox absent from the request (the real browser behaviour for an
     * unchecked checkbox) must save that preference as disabled, not leave it untouched.
     */
    public function test_handle_post_saves_unchecked_boxes_as_disabled(): void {
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, '1');
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, '1');
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, '1');

        // Only show_unique is submitted; the other two are absent, as a real unchecked
        // checkbox would be.
        $_POST['show_unique'] = '1';

        $this->assert_redirects(new controller($this->returnurl));

        $this->assertSame('0', get_user_preferences(hook_listener::PREF_SHOW_TOTAL));
        $this->assertSame('1', get_user_preferences(hook_listener::PREF_SHOW_UNIQUE));
        $this->assertSame('0', get_user_preferences(hook_listener::PREF_SHOW_LASTUSER));
    }

    /**
     * The template context must reflect the current user's saved preferences, the correct
     * action/return URLs, and a valid sesskey.
     */
    public function test_get_template_context_reflects_saved_preferences(): void {
        set_user_preference(hook_listener::PREF_SHOW_TOTAL, '1');
        set_user_preference(hook_listener::PREF_SHOW_UNIQUE, '0');
        set_user_preference(hook_listener::PREF_SHOW_LASTUSER, '1');

        $ctx = (new controller($this->returnurl))->get_template_context();

        $this->assertTrue($ctx['show_total']);
        $this->assertFalse($ctx['show_unique']);
        $this->assertTrue($ctx['show_lastuser']);
        $this->assertSame(
            (new moodle_url('/local/resourcestats/preferences.php'))->out(false),
            $ctx['actionurl']
        );
        $this->assertSame($this->returnurl->out(false), $ctx['returnurl']);
        $this->assertSame(sesskey(), $ctx['sesskey']);
    }

    /**
     * The page URL must be the preferences page carrying the given return URL as a parameter.
     */
    public function test_get_page_url_carries_the_return_url(): void {
        $pageurl = (new controller($this->returnurl))->get_page_url();

        $expected = new moodle_url(
            '/local/resourcestats/preferences.php',
            ['returnurl' => $this->returnurl->out(false)]
        );
        $this->assertSame($expected->out(false), $pageurl->out(false));
    }
}
