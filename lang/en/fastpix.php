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

$string['accesspolicy']        = 'Access policy';
$string['accesspolicy_drm']      = 'DRM';
$string['accesspolicy_drm_help']     = 'Encrypted playback on licensed devices. Strongest protection.';
$string['accesspolicy_private']  = 'Private';
$string['accesspolicy_private_help'] = 'Only logged-in learners can play. Recommended.';
$string['accesspolicy_public']   = 'Public';
$string['accesspolicy_public_help']  = 'Anyone with the link can play.';
$string['activityname']      = 'Activity name';
$string['autocaptions']        = 'Show captions by default';
$string['autocaptions_desc']   = 'Captions will be displayed when the video starts.';
$string['autocaptions_help']   = 'When the asset has captions, they will be enabled on first play. Students can still toggle captions in the player.';
$string['captions']            = 'Captions & transcript';
$string['captions_badtype']    = 'Only .vtt subtitle files are accepted.';
$string['captions_beta_tag']   = '(Beta)';
$string['captions_desc']       = 'Auto-generate from the audio, or upload your own .vtt file.';
$string['captions_uploaderror'] = 'Upload failed. Please try again.';
$string['captions_uploading']  = 'Uploading…';
$string['captionsfile']        = 'WebVTT file';
$string['captionsfile_browse'] = 'browse';
$string['captionsfile_droptext'] = 'Drop a .vtt file here, or';
$string['captionsfile_help']   = 'WebVTT subtitle file. Add your own captions instead of auto-generating.';
$string['captionslanguage']      = 'Language';
$string['captionslanguage_help'] = 'Subtitles match the spoken language — they are not translated.';
$string['captionsmode_auto']   = 'Auto-generate';
$string['captionsmode_vtt']    = 'Upload .vtt file';
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
$string['error_drmnotconfigured'] = 'DRM is not configured for this site. Choose a different access policy, or ask an administrator to set up DRM in the FastPix settings.';
$string['error_fraud_capability_lost']    = 'You no longer have permission to watch.';
$string['error_fraud_exceeds_duration']   = 'Watch time exceeds video length.';
$string['error_fraud_exceeds_wall_clock'] = 'Watch time exceeds session duration.';
$string['error_fraud_implausible_gain']   = 'Watch time gain exceeds elapsed time.';
$string['error_fraud_regression']         = 'Watch time regressed (impossible).';
$string['error_fraud_seek_on_noskip']     = 'Seeking is disabled for this activity.';
$string['error_languagerequired'] = 'Choose a language for the auto-generated captions.';
$string['error_session_finalised']   = 'Your watch session for this activity has already been completed.';
$string['error_session_invalid']     = 'The watch session token is invalid or expired.';
$string['error_session_no_attempt']  = 'No active watch session was found for this activity.';
$string['error_thresholdrange']   = 'Watched percentage must be between 1 and 100.';
$string['error_upload_failed']    = 'This video failed to upload. Remove it and try again.';
$string['error_uploadrequired']   = 'You must upload a video before saving.';
$string['error_urlnotvalidated']  = 'Click the Upload button to submit the URL before saving.';
$string['error_urlrequired']      = 'A source URL is required for URL pull.';
$string['error_user_not_in_course'] = 'That user is not enrolled in this course, so their watch report is not available.';
$string['error_videounavailable']    = 'This video is currently unavailable.';
$string['error_vttrequired']      = 'Upload a .vtt subtitle file, or switch to auto-generated captions.';
$string['event_watch_milestone']          = 'Watch milestone reached';
$string['eventactivityviewed']  = 'FastPix Video viewed';
$string['fastpix:addinstance']      = 'Add a new FastPix Video activity';
$string['fastpix:graderoverride']   = 'Override grades for FastPix Video attempts';
$string['fastpix:uploadmedia']      = 'Upload videos to FastPix from the activity edit form';
$string['fastpix:view']             = 'View a FastPix Video and have completion tracked';
$string['fastpix:viewallattempts']  = 'View watch attempts for all students';
$string['gradeitem']                       = 'FastPix Video grade';
$string['lang_bg'] = 'Bulgarian';
$string['lang_ca'] = 'Catalan';
$string['lang_cs'] = 'Czech';
$string['lang_da'] = 'Danish';
$string['lang_de'] = 'German';
$string['lang_el'] = 'Greek';
$string['lang_en'] = 'English';
$string['lang_es'] = 'Spanish';
$string['lang_fi'] = 'Finnish';
$string['lang_fr'] = 'French';
$string['lang_hr'] = 'Croatian';
$string['lang_it'] = 'Italian';
$string['lang_nl'] = 'Dutch';
$string['lang_no'] = 'Norwegian';
$string['lang_pl'] = 'Polish';
$string['lang_pt'] = 'Portuguese';
$string['lang_ro'] = 'Romanian';
$string['lang_ru'] = 'Russian';
$string['lang_sk'] = 'Slovak';
$string['lang_sv'] = 'Swedish';
$string['lang_tr'] = 'Turkish';
$string['lang_uk'] = 'Ukrainian';
$string['mediasettings']            = 'Media settings';
$string['mediasettings_card_title'] = 'Protection & captions';
$string['mediasettings_intro']      = 'How this video is protected and captioned. These settings are applied when the video is uploaded to FastPix.';
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
$string['privacy:metadata:fastpix']                           = 'When a learner plays a FastPix Video, their browser streams it directly from the FastPix video service. A signed, per-user playback token and the asset\'s playback ID are sent to FastPix to authorise playback, and (as with any third-party video host) the connection exposes the learner\'s IP address and player telemetry to FastPix. This activity never sends data to FastPix from the server — the playback token is minted by the local_fastpix foundation plugin.';
$string['privacy:metadata:fastpix:playbackid']                = 'The playback ID of the FastPix asset the learner is viewing, sent to FastPix to load the stream.';
$string['privacy:metadata:fastpix:token']                     = 'A short-lived, per-user signed token sent to FastPix to authorise playback of the asset.';
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
$string['report_avgwatch']        = 'Average watched';
$string['report_backtovideo']     = 'Back to class report';
$string['report_biggestdropoff']  = 'Biggest drop-off at';
$string['report_col_activity']    = 'Activity';
$string['report_col_completed']   = 'Completed';
$string['report_col_fraud']       = 'Flags';
$string['report_col_lastposition'] = 'Last position';
$string['report_col_milestones']  = 'Milestones';
$string['report_col_seeks']       = 'Seeks';
$string['report_col_student']     = 'Student';
$string['report_col_watchpercent'] = 'Watch %';
$string['report_col_watchtime']   = 'Watch time';
$string['report_completionrate']  = 'Completion rate';
$string['report_downloadcsv']     = 'Download (CSV)';
$string['report_engagement']      = 'Engagement — viewers who watched each point of the video';
$string['report_noattempts']      = 'No students have watched this video yet.';
$string['report_nouserattempts']  = 'This student has no watch activity in this course.';
$string['report_uniqueviewers']   = 'Unique viewers';
$string['report_userheading']     = 'Watch activity for {$a}';
$string['report_viewerspct']      = '% of viewers';
$string['status_completed']   = 'Completed';
$string['upload_complete']          = 'Upload complete. Save the activity to finalise.';
$string['upload_failed']            = 'Upload failed. Please try again.';
$string['upload_in_progress']       = 'Uploading your video…';
$string['upload_sessionfailed']     = 'Could not start the upload. Please try again.';
$string['upload_untitledvideo']     = 'Untitled video';
$string['upload_urlaccepted']       = 'URL accepted. Save the activity to finalise.';
$string['upload_urlenterfirst']     = 'Enter a URL first.';
$string['upload_urlrejected']       = 'URL rejected.';
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
$string['watchreport']               = 'Watch report';
