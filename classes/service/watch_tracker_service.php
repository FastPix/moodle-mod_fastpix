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

use mod_fastpix\event\watch_milestone;

/**
 * Watch-progress recorder and fraud checks for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Watch-progress recorder + the six fraud checks (rule S4).
 *
 * Hot path for every 10-second client callback. The contract is:
 *   record_progress(activity, attempt, client_intervals, current_position,
 *                   ended_fired, client_seek_count, context, now)
 *     → updated_attempt + coverage_percent + completion_state
 *
 * Fraud-check order is fixed (PR-9). ALL six run on every call — never
 * short-circuit. fraud_count is incremented once per failing check;
 * last_fraud_reason captures the FIRST failure (S4). On any failure,
 * watched_intervals / current_position / seek_count / last_callback_ts
 * are NOT updated and completion is NOT triggered.
 *
 * Direct DB writes to fastpix_attempt are allowed here; gradebook /
 * grade_grades writes are routed through Moodle's grade_update() in
 * a later step (CG1).
 *
 * Auth contract (rule S3 / PR-7): the caller — i.e. the external
 * function record_view_progress::execute — MUST already have invoked
 * session_token_service::verify (via resolve_active_attempt) before
 * handing the resolved $attempt row to record_progress(). This service
 * trusts that the attempt has been verified upstream; the in-service
 * fraud-check ⑤ (capability_lost) plus the resolve_active_attempt
 * chain on every endpoint hit are the two halves of the same contract.
 */
class watch_tracker_service {
    /** Epsilon (seconds) below which two intervals collapse on merge. */
    const MERGE_EPS_S = 0.01;

    /** Tolerance (seconds) for the wall-clock fraud checks (S4 ②, ④). */
    const WALL_CLOCK_TOLERANCE_S = 10;

    /** Defensive cap — never accept more than this many intervals from client. */
    const MAX_INTERVALS = 1000;

    /** Watch milestones (percentage) fired exactly once per (user, activity) pair. */
    const MILESTONES = [25, 50, 75, 100];

    /** @var self|null Singleton instance. */
    private static $instance = null;

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
     * Concat → sort by start → merge adjacent/overlapping (gap <= MERGE_EPS_S).
     * Output is sorted by start, non-overlapping. Pure; no IO.
     *
     * @param array $existing Existing [start,end] interval pairs.
     * @param array $new New [start,end] interval pairs to merge in.
     * @return array Sorted, non-overlapping [start,end] pairs.
     */
    public function merge_intervals(array $existing, array $new): array {
        $all = [];
        foreach (array_merge($existing, $new) as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $all[] = [(float)$iv[0], (float)$iv[1]];
            }
        }
        usort($all, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($all as $cur) {
            $n = count($merged);
            if ($n > 0 && $cur[0] - $merged[$n - 1][1] <= self::MERGE_EPS_S) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $cur[1]);
            } else {
                $merged[] = [$cur[0], $cur[1]];
            }
        }
        return $merged;
    }

    /**
     * Sum (end - start) over the interval set.
     *
     * @param array $intervals [start,end] interval pairs.
     * @return float Total seconds covered.
     */
    public function coverage_seconds(array $intervals): float {
        $total = 0.0;
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $total += max(0.0, (float)$iv[1] - (float)$iv[0]);
            }
        }
        return $total;
    }

    /**
     * Run the six fraud checks in fixed order (S4), persist progress on
     * a clean callback, increment fraud_count + record reason on any
     * violation. Returns the post-checks attempt row plus derived counters.
     *
     * @param \stdClass $activity            mdl_fastpix row (needs no_skip_required, completion_watch_percent, course)
     * @param \stdClass $attempt             mdl_fastpix_attempt row (mutated in-place)
     * @param \stdClass $asset               local_fastpix asset_summary DTO (needs duration)
     * @param \stdClass $report             Client report bundle: intervals (array<[float,float]>),
     *                                      position (float), ended (bool), seekcount (int)
     * @param \context_module $context       Module context (for capability check)
     * @param int   $now                     Wall-clock time (for testability)
     * @return \stdClass {
     *     attempt: \stdClass,             // updated row
     *     coverage_percent: int,          // post-merge coverage (or pre-merge on fraud)
     *     completion_state: string,       // 'in_progress' | 'complete'
     *     fraud_reasons: string[],        // every failing check this call
     * }
     */
    public function record_progress(
        \stdClass $activity,
        \stdClass $attempt,
        \stdClass $asset,
        \stdClass $report,
        \context_module $context,
        int $now
    ): \stdClass {
        global $DB;

        // Report bundles this callback's client values: intervals (array of
        // [start,end] pairs), position (float seconds) and seekcount (int).
        // 'ended' (bool) is carried for the external layer's fraud audit and is
        // intentionally not consulted by the coverage logic (see CG4 note).
        $clientintervals = (array)($report->intervals ?? []);
        $currentposition = (float)($report->position ?? 0.0);
        $clientseekcount = (int)($report->seekcount ?? 0);

        $existing = $this->decode_intervals_or_empty((string)($attempt->watched_intervals ?? ''));
        $duration = max(0, (int)($asset->duration ?? 0));

        $serverpersisted = $this->coverage_seconds($existing);
        $clientclaimed   = $this->coverage_seconds($clientintervals);
        $clientmaxend   = $this->max_end($clientintervals);

        // Fraud checks (S4) — collected in collect_fraud_reasons() so the six
        // checks live as one auditable, fixed-order block (PR-9: no drop /
        // reorder / short-circuit). $metrics carries the pre-computed coverages.
        $metrics = (object)[
            'duration'        => $duration,
            'serverpersisted' => $serverpersisted,
            'clientclaimed'   => $clientclaimed,
            'clientmaxend'    => $clientmaxend,
        ];
        $reasons = $this->collect_fraud_reasons($activity, $attempt, $context, $clientseekcount, $now, $metrics);

        if (!empty($reasons)) {
            // Increment fraud_count once per failing check. Record FIRST reason
            // only (S4) — caller can inspect $result->fraud_reasons for the
            // full set this call.
            $attempt->fraud_count = (int)$attempt->fraud_count + count($reasons);
            $attempt->last_fraud_reason = $reasons[0];
            $DB->update_record('fastpix_attempt', $attempt);

            // Coverage exposed to client = whatever the server already has;
            // the client's claimed coverage is rejected.
            $coveragepercent = $duration > 0
                ? (int) min(100, round(($serverpersisted / $duration) * 100))
                : 0;

            return (object)[
                'attempt'          => $attempt,
                'coverage_percent' => $coveragepercent,
                'completion_state' => $attempt->has_completed ? 'complete' : 'in_progress',
                'fraud_reasons'    => $reasons,
            ];
        }

        // Clean callback. Merge, persist, fire milestones, recompute completion.
        $merged = $this->merge_intervals($existing, $clientintervals);
        $coveragesecondsafter = $this->coverage_seconds($merged);

        // Edge case (tt.md #14) — reload mid-lesson without saved state.
        // Defensive belt-and-braces: if merging somehow produced LESS coverage
        // than the server already had (impossible geometrically since merge
        // is union, but possible if a future helper bug regressed precision
        // or column-corruption sneaks bad data through), prefer the server's
        // existing set. This NEVER suppresses fraud check ③ — that branch
        // returns before reaching here. The 0.5s tolerance protects against
        // float-precision noise from json_decode → float conversion.
        if ($coveragesecondsafter < $this->coverage_seconds($existing) - 0.5) {
            $merged = $existing;
            $coveragesecondsafter = $this->coverage_seconds($merged);
        }

        $coveragepercent = $duration > 0
            ? (int) min(100, round(($coveragesecondsafter / $duration) * 100))
            : 0;

        $attempt->watched_intervals = json_encode($merged);
        $attempt->current_position  = max(0.0, $currentposition);
        $attempt->seek_count        = $clientseekcount;
        $attempt->last_callback_ts  = $now;

        // CG4 — capture the pre-update state so the transition test is
        // strictly 0 → 1. Sticky completion (any subsequent callback after
        // has_completed=1 is a replay) must NOT re-fire the grade write.
        $wascomplete = !empty($attempt->has_completed);

        $threshold = (int)($activity->completion_watch_percent ?? 90);
        // Completion gate: coverage must reach the threshold. The 'ended'
        // event alone is NOT a shortcut — a learner who seeks to the end
        // without watching has zero coverage and zero completion. The
        // ended_fired flag is still recorded for fraud audit, just not as
        // a completion trigger.
        $crossedthreshold = $coveragepercent >= $threshold;
        if ($crossedthreshold && !$wascomplete) {
            $attempt->has_completed = 1;
        }

        $iscomplete = !empty($attempt->has_completed);

        $DB->update_record('fastpix_attempt', $attempt);

        // CG4 — exactly-once grade write on the 0 → 1 transition. Goes through
        // grade_update() via lib.php's bare-name fastpix_grade_item_update
        // (CG1 / PR-6: never $DB->{insert,update}_record on grade_grades).
        if (!$wascomplete && $iscomplete) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/fastpix/lib.php');
            // The grade_update() call REQUIRES the $grades array to be keyed by user id
            // (Moodle's gradelib emits "Incorrect grade array index" and
            // silently drops the entry otherwise — even with userid set on
            // the object, the array KEY is what matters).
            $userid = (int)$attempt->userid;
            $grades = [
                $userid => (object)[
                    'userid'     => $userid,
                    'rawgrade'   => (float)($activity->grademax ?? 100),
                    'dategraded' => $now,
                ],
            ];
            fastpix_grade_item_update($activity, $grades);
        }

        // Milestones (CG5) — fire once per (user, activity, milestone) pair,
        // and persist the timestamp in the same row so replays do not re-fire.
        $this->fire_milestones_for($attempt, $coveragepercent, $now);

        // Completion recomputation per CG4 (best-effort; never blocks recording).
        $this->recompute_completion($activity, $attempt);

        return (object)[
            'attempt'          => $attempt,
            'coverage_percent' => $coveragepercent,
            'completion_state' => !empty($attempt->has_completed) ? 'complete' : 'in_progress',
            'fraud_reasons'    => [],
        ];
    }

    /**
     * Run the six fraud checks in fixed order (S4 / PR-9). ALL six run on every
     * call — never short-circuit. Returns every failing check's reason this call
     * (caller increments fraud_count by the count and keeps reasons[0] as the
     * recorded last_fraud_reason). Pure: reads only, no IO, no mutation.
     *
     * @param \stdClass $activity        mdl_fastpix row (no_skip_required).
     * @param \stdClass $attempt         mdl_fastpix_attempt row.
     * @param \context_module $context   Module context (for capability check).
     * @param int $clientseekcount       Monotonic seek counter from client.
     * @param int $now                   Wall-clock time.
     * @param \stdClass $metrics         {duration, serverpersisted, clientclaimed, clientmaxend}.
     * @return string[] Failing-check reasons, in check order.
     */
    private function collect_fraud_reasons(
        \stdClass $activity,
        \stdClass $attempt,
        \context_module $context,
        int $clientseekcount,
        int $now,
        \stdClass $metrics
    ): array {
        $reasons = [];

        // Check 1: exceeds_duration.
        if (
            $metrics->duration > 0
            && ($metrics->clientmaxend > $metrics->duration || $metrics->clientclaimed > $metrics->duration)
        ) {
            $reasons[] = 'exceeds_duration';
        }

        // Check 2: exceeds_wall_clock.
        $elapsed = $now - (int)$attempt->session_start_ts;
        if ($metrics->clientclaimed > $elapsed + self::WALL_CLOCK_TOLERANCE_S) {
            $reasons[] = 'exceeds_wall_clock';
        }

        // Check 3: regression.
        if ($metrics->clientclaimed < $metrics->serverpersisted) {
            $reasons[] = 'regression';
        }

        // Check 4: implausible_gain.
        $prevcallback = !empty($attempt->last_callback_ts)
            ? (int)$attempt->last_callback_ts
            : (int)$attempt->session_start_ts;
        $gain = $metrics->clientclaimed - $metrics->serverpersisted;
        $wall = $now - $prevcallback;
        if ($gain > $wall + self::WALL_CLOCK_TOLERANCE_S) {
            $reasons[] = 'implausible_gain';
        }

        // Check 5: capability_lost.
        if (!has_capability('mod/fastpix:view', $context, (int)$attempt->userid, false)) {
            $reasons[] = 'capability_lost';
        }

        // Check 6: seek_on_noskip.
        if (!empty($activity->no_skip_required) && $clientseekcount > (int)$attempt->seek_count) {
            $reasons[] = 'seek_on_noskip';
        }

        return $reasons;
    }

    /**
     * Best-effort completion recomputation (CG4). Passes COMPLETION_UNKNOWN —
     * not COMPLETION_COMPLETE — so Moodle invokes our custom_completion rule.
     * Never blocks progress recording on a completion-side failure; logs for
     * audit. Tests with completion disabled skip the update.
     *
     * @param \stdClass $activity mdl_fastpix row (id, course).
     * @param \stdClass $attempt  mdl_fastpix_attempt row (userid).
     */
    private function recompute_completion(\stdClass $activity, \stdClass $attempt): void {
        global $DB;

        try {
            $course = $DB->get_record('course', ['id' => (int)$activity->course], '*', MUST_EXIST);
            // Pass course id to scope the lookup — protects against orphan
            // course_modules rows from raw-SQL data resets (see
            // playback_service::get_or_create_attempt for the full note).
            $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, (int)$activity->course, false, MUST_EXIST);
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
        } catch (\Throwable $e) {
            debugging('mod_fastpix: completion update_state failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Fire any milestone events not yet fired for this attempt. Idempotent
     * by virtue of the milestone_*_at column being non-null sentinel.
     * The transaction makes the (set column, fire event) pair atomic so a
     * concurrent callback cannot double-fire.
     *
     * @param \stdClass $attempt The attempt row (mutated with milestone timestamps).
     * @param int $coveragepercent Current coverage percentage.
     * @param int $now Wall-clock timestamp.
     * @return void
     */
    private function fire_milestones_for(\stdClass $attempt, int $coveragepercent, int $now): void {
        global $DB;

        foreach (self::MILESTONES as $milestone) {
            if ($coveragepercent < $milestone) {
                continue;
            }
            $column = 'milestone_' . $milestone . '_at';
            if (!empty($attempt->{$column})) {
                continue;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                // Re-read inside the transaction in case a concurrent
                // record_progress call fired this milestone first.
                $current = (int) $DB->get_field(
                    'fastpix_attempt',
                    $column,
                    ['id' => $attempt->id]
                );
                if ($current === 0) {
                    $DB->set_field('fastpix_attempt', $column, $now, ['id' => $attempt->id]);
                    $attempt->{$column} = $now;
                    watch_milestone::create_from_attempt((int)$attempt->id, $milestone)->trigger();
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
                throw $e;
            }
        }
    }

    /**
     * Tolerant JSON decoder for the stored intervals column. Returns []
     * on any error rather than throwing — the column is a server-owned
     * encoding and corrupt content here is an invariant break, not a
     * user-facing condition.
     *
     * @param string $json The stored watched_intervals JSON.
     * @return array Decoded [start,end] pairs, or [] on error.
     */
    private function decode_intervals_or_empty(string $json): array {
        $clean = [];
        if ($json !== '' && $json !== '[]') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $iv) {
                    if (is_array($iv) && isset($iv[0], $iv[1])) {
                        $clean[] = [(float)$iv[0], (float)$iv[1]];
                    }
                }
            }
        }
        return $clean;
    }

    /**
     * Largest interval end seen — used by fraud check ①.
     *
     * @param array $intervals [start,end] interval pairs.
     * @return float The largest interval end value.
     */
    private function max_end(array $intervals): float {
        $max = 0.0;
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv[1])) {
                $max = max($max, (float)$iv[1]);
            }
        }
        return $max;
    }
}
