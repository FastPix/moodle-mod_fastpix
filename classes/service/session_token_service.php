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

namespace mod_fastpix\service;

/**
 * HMAC-bound session token service for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * HMAC-bound session token issuer + verifier (rule S1).
 *
 * Token = hash_hmac('sha256', "userid|activity_id|session_start_ts", session_secret).
 * TTL = 4h, enforced by session_start_ts. Comparison is constant-time (S2).
 * The secret is bootstrapped on install (db/install.php) and is never logged (S6).
 */
class session_token_service {
    /** Session lifetime in seconds (4h). */
    const TTL_SECONDS = 14400;

    /** @var self|null Singleton instance. */
    private static $instance = null;

    /** @var string|null Lazily resolved HMAC secret. */
    private $secret = null;

    /**
     * Get the shared service instance.
     *
     * @return self The singleton instance.
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Issue a fresh session token.
     */
    public function issue(int $userid, int $activityid, int $sessionstartts): string {
        $message = $userid . '|' . $activityid . '|' . $sessionstartts;
        return hash_hmac('sha256', $message, $this->get_secret(), false);
    }

    /**
     * Verify a provided token against the stored row token. Constant-time.
     * Also checks the 4h TTL.
     */
    public function verify(string $provided, string $expected, int $sessionstartts): bool {
        return $this->is_within_ttl($sessionstartts)
            && strlen($provided) === strlen($expected)
            && hash_equals($expected, $provided);
    }

    /**
     * True if the session has not exceeded TTL. Used by callers that need to
     * decide whether to reuse an existing attempt row.
     */
    public function is_within_ttl(int $sessionstartts): bool {
        return $sessionstartts > 0 && (time() - $sessionstartts) <= self::TTL_SECONDS;
    }

    /**
     * Resolve the active in-progress attempt for (userid, activity_id) and
     * verify the supplied token in constant time. Centralises the three
     * failure modes the refresh endpoint used to inline:
     *
     *   - no attempt row exists                 → error_session_no_attempt
     *   - attempt finalised (≠ in_progress)      → error_session_finalised
     *   - token mismatch / expired               → error_session_invalid
     *
     * @throws \moodle_exception with one of the three lang keys above.
     */
    public function resolve_active_attempt(int $userid, int $activityid, string $providedtoken): \stdClass {
        global $DB;

        $attempt = $DB->get_record(
            'fastpix_attempt',
            ['userid' => $userid, 'activity_id' => $activityid]
        );
        if (!$attempt) {
            throw new \moodle_exception('error_session_no_attempt', 'mod_fastpix');
        }
        if ($attempt->completion_state !== 'in_progress') {
            throw new \moodle_exception('error_session_finalised', 'mod_fastpix');
        }
        if (
            !$this->verify(
                $providedtoken,
                (string)$attempt->session_token,
                (int)$attempt->session_start_ts
            )
        ) {
            throw new \moodle_exception('error_session_invalid', 'mod_fastpix');
        }
        return $attempt;
    }

    /**
     * Resolve (and lazily bootstrap) the HMAC session secret.
     *
     * @return string The HMAC secret.
     */
    private function get_secret(): string {
        if ($this->secret === null) {
            $secret = get_config('mod_fastpix', 'session_secret');
            if (empty($secret)) {
                // Auto-heal if for any reason install.php did not run (e.g. older
                // installs predating Phase C). Never echo or log this value.
                $secret = bin2hex(random_bytes(32));
                set_config('session_secret', $secret, 'mod_fastpix');
            }
            $this->secret = $secret;
        }
        return $this->secret;
    }
}
