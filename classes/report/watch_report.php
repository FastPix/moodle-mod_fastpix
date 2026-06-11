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

namespace mod_fastpix\report;

use local_fastpix\service\asset_service;
use mod_fastpix\service\playback_service;

/**
 * Watch-report aggregation service for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Read-only aggregation over mdl_fastpix_attempt for the teacher watch reports.
 *
 * DISPLAY ONLY: never writes, never changes fraud/coverage logic, never calls
 * FastPix (A2). Asset duration is read through the consumed asset_service
 * surface (CC1/CC5) and cached per call. All coverage maths reuse
 * playback_service::compute_initial_coverage_percent so the report matches the
 * player's progress strip exactly.
 */
class watch_report {
    /** Max heatmap buckets — caps the payload for long videos. */
    const HEATMAP_BUCKETS = 120;

    /** The four watch milestones (percent). */
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
     * Sum the watched seconds in a stored intervals blob.
     *
     * @param string|null $intervalsjson JSON [[start,end],...] or '' / null.
     * @return float Watched seconds.
     */
    public function watched_seconds(?string $intervalsjson): float {
        $intervals = json_decode($intervalsjson ?: '[]', true) ?: [];
        $total = 0.0;
        foreach ($intervals as $iv) {
            if (is_array($iv) && isset($iv[0], $iv[1])) {
                $total += max(0.0, (float)$iv[1] - (float)$iv[0]);
            }
        }
        return $total;
    }

    /**
     * Resolve the asset duration (seconds) for an activity, via the consumed
     * asset_service. Returns 0 when no ready asset is linked.
     *
     * @param \stdClass $activity Row from mdl_fastpix.
     * @return int Duration in seconds (0 if unknown).
     */
    public function duration_for(\stdClass $activity): int {
        if (empty($activity->fastpix_asset_id)) {
            return 0;
        }
        $asset = asset_service::get_by_id((int)$activity->fastpix_asset_id);
        return $asset !== null ? (int)($asset->duration ?? 0) : 0;
    }

    /**
     * Shape one attempt row into the report's per-student record.
     *
     * @param \stdClass $attempt Row from mdl_fastpix_attempt (+ joined user fields).
     * @param int $duration Asset duration in seconds.
     * @return \stdClass A flat record for table + CSV.
     */
    public function row_for_attempt(\stdClass $attempt, int $duration): \stdClass {
        $watchedseconds = $this->watched_seconds($attempt->watched_intervals ?? '');
        $milestones = [];
        foreach (self::MILESTONES as $m) {
            $milestones[$m] = !empty($attempt->{'milestone_' . $m . '_at'});
        }
        return (object)[
            'attemptid'      => (int)$attempt->id,
            'userid'         => (int)$attempt->userid,
            'coveragepercent' => playback_service::compute_initial_coverage_percent(
                (string)($attempt->watched_intervals ?? ''),
                $duration
            ),
            'watchedseconds' => (int)round($watchedseconds),
            'milestones'     => $milestones,
            'completed'      => !empty($attempt->has_completed),
            'currentposition' => (int)round((float)($attempt->current_position ?? 0)),
            'seekcount'      => (int)$attempt->seek_count,
            'fraudcount'     => (int)$attempt->fraud_count,
            'fraudreason'    => $attempt->last_fraud_reason !== null ? (string)$attempt->last_fraud_reason : '',
            'watchedintervals' => (string)($attempt->watched_intervals ?? '[]'),
        ];
    }

    /**
     * Build the per-video (class) report for one activity.
     *
     * Single joined query (no N+1). Aggregates the engagement heatmap, the
     * summary strip, and the per-student rows.
     *
     * @param \stdClass $activity Row from mdl_fastpix.
     * @return \stdClass {duration, viewers, rows[], summary, heatmap}
     */
    public function get_video_report(\stdClass $activity): \stdClass {
        global $DB;

        $duration = $this->duration_for($activity);

        $sql = "SELECT a.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.picture, u.imagealt, u.email
                  FROM {fastpix_attempt} a
                  JOIN {user} u ON u.id = a.userid
                 WHERE a.activity_id = :activityid
              ORDER BY u.lastname, u.firstname, a.id";
        $attempts = $DB->get_records_sql($sql, ['activityid' => (int)$activity->id]);

        $rows = [];
        $users = [];
        foreach ($attempts as $attempt) {
            $row = $this->row_for_attempt($attempt, $duration);
            // Stash the (already-fetched) user record for name rendering.
            $row->user = (object)[
                'id' => (int)$attempt->userid,
                'firstname' => $attempt->firstname,
                'lastname' => $attempt->lastname,
                'firstnamephonetic' => $attempt->firstnamephonetic,
                'lastnamephonetic' => $attempt->lastnamephonetic,
                'middlename' => $attempt->middlename,
                'alternatename' => $attempt->alternatename,
                'picture' => $attempt->picture,
                'imagealt' => $attempt->imagealt,
                'email' => $attempt->email,
            ];
            $rows[] = $row;
            $users[(int)$attempt->userid] = true;
        }

        $viewers = count($rows);
        $heatmap = $this->build_heatmap($attempts, $duration);

        return (object)[
            'duration' => $duration,
            'viewers'  => $viewers,
            'rows'     => $rows,
            'summary'  => $this->summarise($rows, $heatmap, $duration),
            'heatmap'  => $heatmap,
        ];
    }

    /**
     * Build the per-user report: one student's attempts across the fastpix
     * activities in a course.
     *
     * @param int $courseid Course id to scope to.
     * @param int $userid The student.
     * @return \stdClass {rows[]} one row per activity the student has an attempt in
     */
    public function get_user_report(int $courseid, int $userid): \stdClass {
        global $DB;

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'fastpix'], MUST_EXIST);

        // Scope by the authoritative course-module placement (cm.course), not the
        // fastpix.course column, which can drift on raw data resets / orphan cms.
        $sql = "SELECT a.*, f.name AS activityname, f.fastpix_asset_id, cm.course, cm.id AS cmid
                  FROM {fastpix_attempt} a
                  JOIN {fastpix} f ON f.id = a.activity_id
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = :moduleid
                 WHERE cm.course = :courseid AND a.userid = :userid
              ORDER BY f.name, a.id";
        $attempts = $DB->get_records_sql($sql, [
            'moduleid' => $moduleid,
            'courseid' => $courseid,
            'userid'   => $userid,
        ]);

        $rows = [];
        foreach ($attempts as $attempt) {
            // Duration is per-activity; asset_service caches, so this is cheap.
            $duration = $this->duration_for((object)['fastpix_asset_id' => $attempt->fastpix_asset_id]);
            $row = $this->row_for_attempt($attempt, $duration);
            $row->activityname = (string)$attempt->activityname;
            $row->cmid = (int)$attempt->cmid;
            $row->duration = $duration;
            $rows[] = $row;
        }

        return (object)['rows' => $rows];
    }

    /**
     * Aggregate every viewer's watched_intervals into a bucketed coverage
     * curve: for each bucket, the percentage of viewers who watched it.
     *
     * @param array $attempts Raw attempt rows (need watched_intervals).
     * @param int $duration Asset duration in seconds.
     * @return \stdClass {labels[], values[], buckets, bucketseconds, peak_dropoff}
     */
    public function build_heatmap(array $attempts, int $duration): \stdClass {
        if ($duration <= 0 || empty($attempts)) {
            return (object)['labels' => [], 'values' => [], 'buckets' => 0, 'bucketseconds' => 0, 'dropoff' => null];
        }

        $buckets = (int)min(self::HEATMAP_BUCKETS, max(1, $duration));
        $bucketseconds = $duration / $buckets;
        $counts = array_fill(0, $buckets, 0);
        $viewers = 0;

        foreach ($attempts as $attempt) {
            if ($this->bucket_attempt($attempt->watched_intervals ?? '[]', $counts, $buckets, $bucketseconds, $duration)) {
                $viewers++;
            }
        }

        $labels = [];
        $values = [];
        for ($b = 0; $b < $buckets; $b++) {
            $labels[] = $this->format_clock((int)round($b * $bucketseconds));
            $values[] = $viewers > 0 ? (int)round(($counts[$b] / $viewers) * 100) : 0;
        }

        return (object)[
            'labels'        => $labels,
            'values'        => $values,
            'buckets'       => $buckets,
            'bucketseconds' => $bucketseconds,
            'dropoff'       => $this->biggest_dropoff($values, $bucketseconds),
        ];
    }

    /**
     * Bucket one viewer's watched intervals into the shared per-bucket counts.
     * Each bucket is counted at most once per viewer (de-duped via $seen).
     *
     * @param string $intervalsjson The attempt's watched_intervals JSON.
     * @param int[] $counts Per-bucket viewer counts, mutated in place.
     * @param int $buckets Number of buckets.
     * @param float $bucketseconds Seconds per bucket.
     * @param int $duration Asset duration in seconds.
     * @return bool True if the row was a countable viewer.
     */
    private function bucket_attempt(
        string $intervalsjson,
        array &$counts,
        int $buckets,
        float $bucketseconds,
        int $duration
    ): bool {
        $intervals = json_decode($intervalsjson, true) ?: [];
        if (!is_array($intervals)) {
            return false;
        }
        $seen = array_fill(0, $buckets, false);
        foreach ($intervals as $iv) {
            if (!is_array($iv) || !isset($iv[0], $iv[1])) {
                continue;
            }
            $start = max(0.0, (float)$iv[0]);
            $end = min((float)$duration, (float)$iv[1]);
            if ($end <= $start) {
                continue;
            }
            $b0 = (int)floor($start / $bucketseconds);
            $b1 = (int)floor(($end - 0.0001) / $bucketseconds);
            for ($b = $b0; $b <= $b1 && $b < $buckets; $b++) {
                if ($b >= 0 && !$seen[$b]) {
                    $seen[$b] = true;
                    $counts[$b]++;
                }
            }
        }
        return true;
    }

    /**
     * Find the timeline point with the largest fall in viewer coverage.
     *
     * @param int[] $values Per-bucket viewer-percentages.
     * @param float $bucketseconds Seconds per bucket.
     * @return array|null {atseconds, atlabel, droppct} or null when flat.
     */
    private function biggest_dropoff(array $values, float $bucketseconds): ?array {
        $worst = 0;
        $at = null;
        for ($i = 1; $i < count($values); $i++) {
            $drop = $values[$i - 1] - $values[$i];
            if ($drop > $worst) {
                $worst = $drop;
                $at = $i;
            }
        }
        if ($at === null || $worst <= 0) {
            return null;
        }
        $seconds = (int)round($at * $bucketseconds);
        return ['atseconds' => $seconds, 'atlabel' => $this->format_clock($seconds), 'droppct' => $worst];
    }

    /**
     * Compute the per-video summary strip.
     *
     * @param array $rows Per-student records (from row_for_attempt).
     * @param \stdClass $heatmap Heatmap object.
     * @param int $duration Asset duration in seconds.
     * @return \stdClass {viewers, avgpercent, completionrate, dropoff}
     */
    private function summarise(array $rows, \stdClass $heatmap, int $duration): \stdClass {
        $viewers = count($rows);
        $sumpercent = 0;
        $completed = 0;
        foreach ($rows as $row) {
            $sumpercent += $row->coveragepercent;
            if ($row->completed) {
                $completed++;
            }
        }
        return (object)[
            'viewers'        => $viewers,
            'avgpercent'     => $viewers > 0 ? (int)round($sumpercent / $viewers) : 0,
            'completionrate' => $viewers > 0 ? (int)round(($completed / $viewers) * 100) : 0,
            'dropoff'        => $heatmap->dropoff,
        ];
    }

    /**
     * Render the reached milestones as a compact "25/50/75%" string (or '—').
     *
     * @param array<int,bool> $milestones Map of milestone => reached.
     * @return string Display text.
     */
    public function milestones_text(array $milestones): string {
        $reached = array_keys(array_filter($milestones));
        return empty($reached) ? '—' : implode('/', $reached) . '%';
    }

    /**
     * Format a seconds value as H:MM:SS / M:SS for axis labels.
     *
     * @param int $seconds Seconds.
     * @return string Clock string.
     */
    public function format_clock(int $seconds): string {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }
}
