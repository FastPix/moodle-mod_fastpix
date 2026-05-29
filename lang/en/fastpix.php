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
 * English language strings for mod_fastpix.
 *
 * @package    mod_fastpix
 * @copyright  2026 FastPix
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activityname']      = 'Activity name';
$string['autocaptions']        = 'Show captions by default';
$string['autocaptions_desc']   = 'Captions will be displayed when the video starts.';
$string['autocaptions_help']   = 'When the asset has captions, they will be enabled on first play. Students can still toggle captions in the player.';
$string['completion_badge']                = 'FastPix';
$string['completion_minimum_watched']      = 'Minimum watched';
$string['completion_watch_card_desc']      = 'The activity is marked complete once the student has watched this much of the video.';
$string['completionwatchedpercent']        = 'Students must watch the video';
$string['completionwatchedpercent_desc']   = 'Student must watch at least {$a}% of the video';
$string['completionwatchedpercent_group']  = 'Watched percentage';
$string['completionwatchedpercentenabled'] = 'Watched percentage';
$string['error_assetswapblocked'] = 'Students have already started watching this video, so it cannot be replaced. To use a different video, create a new activity instead — this preserves their progress.';
$string['error_capability_lost']     = 'You no longer have permission to view this video.';
$string['error_drm_unsupported']     = 'Your browser cannot play this protected video. Please try a different browser.';
$string['error_fraud_capability_lost']    = 'You no longer have permission to watch.';
$string['error_fraud_exceeds_duration']   = 'Watch time exceeds video length.';
$string['error_fraud_exceeds_wall_clock'] = 'Watch time exceeds session duration.';
$string['error_fraud_implausible_gain']   = 'Watch time gain exceeds elapsed time.';
$string['error_fraud_regression']         = 'Watch time regressed (impossible).';
$string['error_fraud_seek_on_noskip']     = 'Seeking is disabled for this activity.';
$string['error_session_finalised']   = 'Your watch session for this activity has already been completed.';
$string['error_session_invalid']     = 'The watch session token is invalid or expired.';
$string['error_session_no_attempt']  = 'No active watch session was found for this activity.';
$string['error_thresholdrange']   = 'Watched percentage must be between 1 and 100.';
$string['error_uploadrequired']   = 'You must upload a video before saving.';
$string['error_urlnotvalidated']  = 'Click the Upload button to submit the URL before saving.';
$string['error_urlrequired']      = 'A source URL is required for URL pull.';
$string['error_videounavailable']    = 'This video is currently unavailable.';
$string['event_watch_milestone']          = 'Watch milestone reached';
$string['eventactivityviewed']  = 'FastPix Video viewed';
$string['fastpix:addinstance']      = 'Add a new FastPix Video activity';
$string['fastpix:graderoverride']   = 'Override grades for FastPix Video attempts';
$string['fastpix:uploadmedia']      = 'Upload videos to FastPix from the activity edit form';
$string['fastpix:view']             = 'View a FastPix Video and have completion tracked';
$string['fastpix:viewallattempts']  = 'View watch attempts for all students';
$string['gradeitem']                       = 'FastPix Video grade';
$string['modulename']        = 'FastPix Video';
$string['modulename_help']   = 'The FastPix Video activity lets teachers add a video for students to watch, with watch tracking, completion thresholds, and gradebook integration.';
$string['modulenameplural']  = 'FastPix Videos';
$string['noskip']              = 'Disable seeking';
$string['noskip_desc']         = 'Prevent students from skipping ahead during playback.';
$string['noskip_help']         = 'When enabled, the player blocks forward seeks. Backward seeks are still allowed.';
$string['playbackoptions']            = 'Playback options';
$string['playbackoptions_card_title'] = 'Player behaviour';
$string['playbackoptions_intro']      = 'Control how learners interact with the video during playback. These settings apply to this activity only.';
$string['pluginadministration'] = 'FastPix Video administration';
$string['pluginname']        = 'FastPix Video';
$string['privacy:metadata:fastpix_attempt']                   = 'Per-user watch progress and attempt state for FastPix Video activities. Stores the watched-intervals geometry that drives completion, the last playback position used for resume, and the session token that authenticates progress callbacks. Deleting an attempt clears the user\'s progress and resets their completion state.';
$string['privacy:metadata:fastpix_attempt:activity_id']       = 'The FastPix Video activity the attempt is for.';
$string['privacy:metadata:fastpix_attempt:asset_id']          = 'Reference to the FastPix asset (local_fastpix) snapshotted at session start.';
$string['privacy:metadata:fastpix_attempt:completion_state']  = 'Server-side completion state for the attempt (in_progress or complete).';
$string['privacy:metadata:fastpix_attempt:current_position']  = 'Last playback position in seconds, used to resume the video on the next view.';
$string['privacy:metadata:fastpix_attempt:fraud_count']       = 'Number of fraud-check violations recorded across this attempt.';
$string['privacy:metadata:fastpix_attempt:has_completed']     = 'Sticky flag: 1 once the user has met the completion threshold at least once.';
$string['privacy:metadata:fastpix_attempt:last_callback_ts']  = 'Unix timestamp of the most recent watch-progress callback accepted by the server.';
$string['privacy:metadata:fastpix_attempt:last_fraud_reason'] = 'Typed reason for the most recent fraud-check violation.';
$string['privacy:metadata:fastpix_attempt:milestones']        = 'Timestamps at which the user crossed the 25%, 50%, 75%, and 100% coverage milestones.';
$string['privacy:metadata:fastpix_attempt:seek_count']        = 'Number of seek events reported by the player during the session.';
$string['privacy:metadata:fastpix_attempt:session_start_ts']  = 'Unix timestamp marking the start of the current session window.';
$string['privacy:metadata:fastpix_attempt:session_token']     = 'HMAC-bound session token used to authenticate watch-progress callbacks. Redacted from data exports.';
$string['privacy:metadata:fastpix_attempt:userid']            = 'The user the attempt belongs to.';
$string['privacy:metadata:fastpix_attempt:watched_intervals'] = 'JSON-encoded list of watched [start,end] ranges, used to compute coverage for completion.';
$string['privacy:path:attempt']                               = 'Watch attempt';
$string['processing_max_polls']      = 'Still preparing. Please refresh the page in a few minutes.';
$string['processing_message']        = 'This usually takes a minute. The page updates automatically.';
$string['processing_progress_aria']  = 'Video preparation in progress';
$string['processing_title']         = 'Preparing your video';
$string['status_completed']   = 'Completed';
$string['upload_in_progress']       = 'Uploading your video…';
$string['videosource']         = 'Video source';
$string['videosource_card_title']          = 'Upload a video';
$string['videosource_dropzone_browse']     = 'Browse';
$string['videosource_dropzone_meta']       = 'You can upload multiple files at once.';
$string['videosource_dropzone_text_before'] = 'Drag & drop video and audio or';
$string['videosource_intro']               = 'Upload from your device or pull from a public URL. Either method goes directly to FastPix — Moodle never stores the video bytes.';
$string['videosource_urlpull_button']      = 'Upload';
$string['videosource_urlpull_label']       = 'Or upload using video URL';
$string['videosource_urlpull_placeholder'] = 'Paste your video URL here…';
$string['watch_status_complete']    = '✓ Completed · ';
