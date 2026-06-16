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

namespace mod_fastpix\output;

use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_processing;
use mod_fastpix\report\watch_report;

/**
 * Output renderer for the FastPix activity module.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Dispatches a view_state DTO to the matching mustache template.
 */
class renderer extends \plugin_renderer_base {
    /** @var string Path to the activity's report endpoint, used to build report.php URLs. */
    private const REPORT_URL = '/mod/fastpix/report.php';

    /**
     * Render the given view-state DTO to HTML.
     *
     * @param object $state One of the view_state_* DTOs.
     * @return string The rendered HTML.
     */
    public function render_state(object $state): string {
        if ($state instanceof view_state_player) {
            return $this->render_player($state);
        }
        if ($state instanceof view_state_processing) {
            return $this->render_processing($state);
        }
        if ($state instanceof view_state_error) {
            return $this->render_error($state);
        }
        throw new \coding_exception('Unknown view state: ' . get_class($state));
    }

    /**
     * Render the player view state.
     *
     * @param view_state_player $s The player view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_player(view_state_player $s): string {
        return $this->render_from_template('mod_fastpix/view', array_merge($s->progress_card_context(), [
            'playback_id'              => $s->playbackid,
            'playback_token'           => $s->playbacktoken,
            'expires_at_ts'            => $s->expiresatts,
            'drm_required'             => $s->drmrequired,
            'accent_color'             => $s->accentcolor,
            'default_show_captions'    => $s->defaultshowcaptions,
            'activity_name'            => $s->activityname,
            'activity_id'              => $s->activityid,
            'cm_id'                    => $s->cmid,
            'asset_id'                 => $s->assetid,
            'session_token'            => $s->sessiontoken,
            'no_skip_required'         => $s->noskiprequired,
            // Phase D Slice A Step 1 — progress strip + resume + tracker prereqs.
            'initial_coverage_percent' => $s->initialcoveragepercent,
            'completion_watch_percent' => $s->completionwatchpercent,
            'current_position'         => $s->currentposition,
            'asset_duration_seconds'   => $s->assetdurationseconds,
            // Phase D Slice A Step 2 — tracker JS hydration.
            'initial_intervals_json'   => $s->initialintervalsjson,
            'has_completed'            => $s->hascompleted,
            // Phase 2 DRM — separate license-server JWT.
            'drm_token'                => $s->drmtoken,
            // Activity intro — rendered BELOW the player inside player_wrapper
            // (raw {{{ }}}), only when non-empty. Same field flows through the
            // poller swap via to_player_payload(), so both paths match.
            'intro_html'               => $s->introhtml,
        ]));
    }

    /**
     * Render the processing view state.
     *
     * @param view_state_processing $s The processing view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_processing(view_state_processing $s): string {
        return $this->render_from_template('mod_fastpix/processing', [
            'activity_id'       => $s->activityid,
            'cm_id'             => $s->cmid,
            'upload_session_id' => $s->uploadsessionid,
            'activity_name'     => $s->activityname,
        ]);
    }

    /**
     * Render the error view state.
     *
     * @param view_state_error $s The error view-state DTO.
     * @return string The rendered HTML.
     */
    private function render_error(view_state_error $s): string {
        return $this->render_from_template('mod_fastpix/error', [
            'reason_key'    => $s->reasonkey,
            'activity_name' => $s->activityname,
            'is_videounavailable' => $s->reasonkey === 'videounavailable',
            'is_drm_unsupported'  => $s->reasonkey === 'drm_unsupported',
            'is_capability_lost'  => $s->reasonkey === 'capability_lost',
            'is_upload_failed'    => $s->reasonkey === 'upload_failed',
        ]);
    }

    /**
     * Render the per-video (class) watch report.
     *
     * @param \stdClass $data From watch_report::get_video_report().
     * @param \stdClass $cm Course-module record.
     * @return string The rendered HTML.
     */
    public function render_video_report(\stdClass $data, \stdClass $cm): string {
        $svc = watch_report::instance();

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'studentname'  => fullname($r->user),
                'userurl'      => (new \moodle_url(self::REPORT_URL, [
                    'id' => $cm->id, 'mode' => 'user', 'userid' => $r->userid,
                ]))->out(false),
                'watchpercent' => $r->coveragepercent,
                'watchtime'    => $svc->format_clock($r->watchedseconds),
                'milestones'   => $svc->milestones_text($r->milestones),
                'completed'    => $r->completed,
                'lastposition' => $svc->format_clock($r->currentposition),
                'seeks'        => $r->seekcount,
                'fraudcount'   => $r->fraudcount,
                'fraudreason'  => $r->fraudreason,
                'hasfraud'     => $r->fraudcount > 0,
            ];
        }

        $context = [
            'cmid'           => (int)$cm->id,
            'hasrows'        => !empty($rows),
            'rows'           => $rows,
            'viewers'        => $data->summary->viewers,
            'avgpercent'     => $data->summary->avgpercent,
            'completionrate' => $data->summary->completionrate,
            'hasdropoff'     => !empty($data->summary->dropoff),
            'dropofflabel'   => $data->summary->dropoff['atlabel'] ?? '',
            'dropoffpct'     => $data->summary->dropoff['droppct'] ?? 0,
            'chart'          => $this->coverage_chart($data->heatmap),
            'downloadurl'    => (new \moodle_url(self::REPORT_URL, [
                'id' => $cm->id, 'mode' => 'video', 'download' => 'csv',
            ]))->out(false),
        ];
        return $this->render_from_template('mod_fastpix/report_video', $context);
    }

    /**
     * Render the per-user watch report (one student across the course).
     *
     * @param \stdClass $data From watch_report::get_user_report().
     * @param \stdClass $student The student user record.
     * @param \stdClass $cm Course-module record.
     * @return string The rendered HTML.
     */
    public function render_user_report(\stdClass $data, \stdClass $student, \stdClass $cm): string {
        $svc = watch_report::instance();

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'activityname' => $r->activityname,
                'activityurl'  => (new \moodle_url('/mod/fastpix/view.php', ['id' => $r->cmid]))->out(false),
                'watchpercent' => $r->coveragepercent,
                'watchtime'    => $svc->format_clock($r->watchedseconds),
                'milestones'   => $svc->milestones_text($r->milestones),
                'completed'    => $r->completed,
                'lastposition' => $svc->format_clock($r->currentposition),
                'seeks'        => $r->seekcount,
                'fraudcount'   => $r->fraudcount,
                'fraudreason'  => $r->fraudreason,
                'hasfraud'     => $r->fraudcount > 0,
            ];
        }

        $context = [
            'cmid'         => (int)$cm->id,
            'studentname'  => fullname($student),
            'backurl'      => (new \moodle_url(self::REPORT_URL, [
                'id' => $cm->id, 'mode' => 'video',
            ]))->out(false),
            'hasrows'      => !empty($rows),
            'rows'         => $rows,
            'downloadurl'  => (new \moodle_url(self::REPORT_URL, [
                'id' => $cm->id, 'mode' => 'user', 'userid' => (int)$student->id, 'download' => 'csv',
            ]))->out(false),
        ];
        return $this->render_from_template('mod_fastpix/report_user', $context);
    }

    /**
     * Build the engagement / drop-off line chart from a heatmap object.
     * Returns '' when there is no usable timeline (no duration / no viewers).
     *
     * @param \stdClass $heatmap From watch_report::build_heatmap().
     * @return string Chart HTML, or '' if not renderable.
     */
    private function coverage_chart(\stdClass $heatmap): string {
        $values = array_map('intval', $heatmap->values ?? []);
        $labels = array_values($heatmap->labels ?? []);
        $n = count($values);
        if ($n === 0) {
            return '';
        }

        // Inline SVG with a HARD 0-100 y-axis. Moodle's \core\chart_line can't
        // lock the axis: its adapter passes the bound as Chart.js v2-style
        // ticks.min/max, which the bundled Chart.js v4 ignores — so an
        // all-100% dataset auto-scales to ~95-105. SVG gives a fixed scale,
        // is theme-friendly (CSS tokens), and needs no JS.
        $w = 720;
        $h = 220;
        $padl = 34;
        $padr = 10;
        $padt = 10;
        $padb = 26;
        $plotw = $w - $padl - $padr;
        $ploth = $h - $padt - $padb;
        $x0 = $padl;
        $ybottom = $padt + $ploth;

        $xfor = function ($i) use ($n, $x0, $plotw) {
            return $n <= 1 ? $x0 + $plotw / 2 : $x0 + ($i / ($n - 1)) * $plotw;
        };
        $yfor = function ($pct) use ($padt, $ploth) {
            $pct = max(0, min(100, (float)$pct));
            return $padt + (1 - $pct / 100) * $ploth;
        };

        // Gridlines + y-axis labels locked to 0/25/50/75/100.
        $grid = '';
        foreach ([0, 25, 50, 75, 100] as $g) {
            $y = round($yfor($g), 1);
            $grid .= \html_writer::tag('line', '', [
                'class' => 'fastpix-eng-grid', 'x1' => $x0, 'y1' => $y, 'x2' => $x0 + $plotw, 'y2' => $y,
            ]);
            $grid .= \html_writer::tag('text', $g, [
                'class' => 'fastpix-eng-ylabel', 'x' => $x0 - 6, 'y' => round($y + 3, 1), 'text-anchor' => 'end',
            ]);
        }

        // Line + filled area.
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($xfor($i), 1) . ',' . round($yfor($v), 1);
        }
        $line = implode(' ', $pts);
        $area = round($xfor(0), 1) . ',' . round($ybottom, 1) . ' ' . $line . ' '
            . round($xfor($n - 1), 1) . ',' . round($ybottom, 1);

        // A handful of x-axis time labels.
        $xlabels = '';
        $positions = array_values(array_unique([
            0, intdiv($n - 1, 4), intdiv($n - 1, 2), intdiv(3 * ($n - 1), 4), $n - 1,
        ]));
        foreach ($positions as $i) {
            if (!isset($labels[$i])) {
                continue;
            }
            if ($i === 0) {
                $anchor = 'start';
            } else if ($i === $n - 1) {
                $anchor = 'end';
            } else {
                $anchor = 'middle';
            }
            $xlabels .= \html_writer::tag('text', s($labels[$i]), [
                'class' => 'fastpix-eng-xlabel', 'x' => round($xfor($i), 1), 'y' => $h - 8, 'text-anchor' => $anchor,
            ]);
        }

        $svg = \html_writer::tag(
            'svg',
            $grid
                . \html_writer::tag('polygon', '', ['class' => 'fastpix-eng-area', 'points' => $area])
                . \html_writer::tag('polyline', '', ['class' => 'fastpix-eng-line', 'points' => $line])
                . $xlabels,
            [
                'class' => 'fastpix-eng-svg',
                'viewBox' => "0 0 {$w} {$h}",
                'role' => 'img',
                'aria-label' => get_string('report_engagement', 'mod_fastpix'),
            ]
        );

        return \html_writer::div(
            \html_writer::div(get_string('report_viewerspct', 'mod_fastpix'), 'fastpix-eng-caption') . $svg,
            'fastpix-eng-chart'
        );
    }
}
