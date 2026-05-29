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
}
