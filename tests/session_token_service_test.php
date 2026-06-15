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
 * Tests for \mod_fastpix\service\session_token_service.
 *
 * Covers S1 (HMAC formula), S2 (constant-time compare), TTL boundary,
 * binding isolation (userid + activity_id + start_ts vary).
 *
 * @package    mod_fastpix
 * @category   test
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_fastpix;

use mod_fastpix\service\session_token_service;
/**
 * Tests for the class(es) listed in @covers.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\service\session_token_service
 */
final class session_token_service_test extends \advanced_testcase {
    public function test_issue_and_verify_round_trip(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $ts = time();
        $token = $svc->issue(42, 1, $ts);
        $this->assertSame(64, strlen($token), 'HMAC-SHA256 hex output must be 64 chars (S1)');
        $this->assertTrue($svc->verify($token, $token, $ts));
    }

    public function test_verify_rejects_mismatched_token(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $ts = time();
        $tokena = $svc->issue(42, 1, $ts);
        $tokenb = $svc->issue(43, 1, $ts);
        $this->assertNotSame($tokena, $tokenb);
        $this->assertFalse($svc->verify($tokena, $tokenb, $ts));
    }

    public function test_verify_rejects_expired_token_outside_ttl(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();

        // TTL_SECONDS is 14400. Just past the boundary.
        $past = time() - session_token_service::TTL_SECONDS - 1;
        $token = $svc->issue(42, 1, $past);
        $this->assertFalse($svc->verify($token, $token, $past));
    }

    public function test_verify_accepts_token_inside_ttl(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $recent = time() - session_token_service::TTL_SECONDS + 60;
        $token = $svc->issue(42, 1, $recent);
        $this->assertTrue($svc->verify($token, $token, $recent));
    }

    public function test_is_within_ttl_boundary(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $this->assertTrue($svc->is_within_ttl(time()));
        $this->assertTrue($svc->is_within_ttl(time() - session_token_service::TTL_SECONDS + 1));
        $this->assertFalse($svc->is_within_ttl(time() - session_token_service::TTL_SECONDS - 1));
        $this->assertFalse($svc->is_within_ttl(0));
        $this->assertFalse($svc->is_within_ttl(-1));
    }

    public function test_token_binding_isolation(): void {
        // S1 — token MUST differ for any change in (userid, activity_id, ts).
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $ts = time();
        $base = $svc->issue(42, 1, $ts);
        $this->assertNotSame($base, $svc->issue(43, 1, $ts), 'different userid → different token');
        $this->assertNotSame($base, $svc->issue(42, 2, $ts), 'different activity_id → different token');
        $this->assertNotSame($base, $svc->issue(42, 1, $ts + 1), 'different start_ts → different token');
    }

    public function test_verify_rejects_zero_session_start_ts(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $token = $svc->issue(42, 1, 100);
        $this->assertFalse($svc->verify($token, $token, 0));
    }

    /**
     * Insert a fastpix_attempt row for (userid, activityid) with a token issued
     * for the given session_start_ts. No full activity is needed —
     * resolve_active_attempt() looks the row up by (userid, activity_id) only.
     *
     * @param int $userid
     * @param int $activityid
     * @param array $overrides Column overrides (e.g. completion_state, session_start_ts).
     * @return \stdClass The inserted attempt row.
     */
    private function insert_attempt(int $userid, int $activityid, array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $svc = session_token_service::instance();
        $row = (object)array_merge([
            'userid'            => $userid,
            'activity_id'       => $activityid,
            'asset_id'          => 1,
            'session_token'     => $svc->issue($userid, $activityid, $now),
            'session_start_ts'  => $now,
            'last_callback_ts'  => null,
            'seek_count'        => 0,
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ], $overrides);
        $row->id = $DB->insert_record('fastpix_attempt', $row);
        return $row;
    }

    public function test_resolve_active_attempt_returns_row_on_valid_token(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $attempt = $this->insert_attempt(42, 7);
        $resolved = $svc->resolve_active_attempt(42, 7, $attempt->session_token);
        $this->assertEquals($attempt->id, $resolved->id);
        $this->assertSame('in_progress', $resolved->completion_state);
    }

    public function test_resolve_active_attempt_throws_when_no_row(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        try {
            $svc->resolve_active_attempt(42, 7, str_repeat('a', 64));
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_no_attempt', $e->errorcode);
        }
    }

    public function test_resolve_active_attempt_throws_when_finalised(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $attempt = $this->insert_attempt(42, 7, ['completion_state' => 'complete']);
        try {
            $svc->resolve_active_attempt(42, 7, $attempt->session_token);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_finalised', $e->errorcode);
        }
    }

    public function test_resolve_active_attempt_throws_on_token_mismatch(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        $this->insert_attempt(42, 7);
        try {
            // Right length (64 hex), wrong value → hash_equals fails → invalid.
            $svc->resolve_active_attempt(42, 7, str_repeat('0', 64));
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_invalid', $e->errorcode);
        }
    }

    public function test_resolve_active_attempt_throws_on_expired_session(): void {
        $this->resetAfterTest();
        $svc = session_token_service::instance();
        // Token is correct but the session_start_ts is past the TTL → verify() fails.
        $past = time() - session_token_service::TTL_SECONDS - 1;
        $attempt = $this->insert_attempt(42, 7, [
            'session_start_ts' => $past,
            'session_token'    => $svc->issue(42, 7, $past),
        ]);
        try {
            $svc->resolve_active_attempt(42, 7, $attempt->session_token);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_session_invalid', $e->errorcode);
        }
    }

    /**
     * get_secret() auto-bootstraps a 32-byte secret when none is configured
     * (self-heal path for installs predating the install.php bootstrap, S1).
     * Reset the cached singleton + clear the stored config so the empty branch
     * actually runs, rather than reusing the install-time secret.
     */
    public function test_get_secret_bootstraps_when_absent(): void {
        $this->resetAfterTest();
        unset_config('session_secret', 'mod_fastpix');

        // Drop the cached singleton so get_secret() re-resolves from scratch.
        $instanceprop = (new \ReflectionClass(session_token_service::class))->getProperty('instance');
        $instanceprop->setAccessible(true);
        $instanceprop->setValue(null, null);

        // Calling issue() drives get_secret(): empty config → bin2hex(random_bytes(32)) + set_config.
        $token = session_token_service::instance()->issue(1, 1, time());
        $this->assertSame(64, strlen($token), 'HMAC-SHA256 hex output is 64 chars');

        $secret = get_config('mod_fastpix', 'session_secret');
        $this->assertNotEmpty($secret, 'A secret must be persisted by the bootstrap branch');
        $this->assertSame(64, strlen($secret), 'bin2hex(random_bytes(32)) is 64 hex chars');
    }
}
