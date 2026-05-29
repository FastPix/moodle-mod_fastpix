# Watch Tracker Edge-Case Smoke Test (Slice B)

Manual reproduction steps for the 6 edge cases hardened in Phase D Slice B,
plus the multi-session merge scenario. Each scenario lists:

1. **How to trigger** in the browser.
2. **What you should see** in the player + on-page progress strip.
3. **What you should see** in `mdl_fastpix_attempt` and `mdl_grade_grades`.

Prereqs:

- Phase D Slice A + B applied (version >= 2026051302).
- A FastPix Video activity is live in a test course with a known asset
  duration. Completion threshold = 90%.
- DevTools open with both **Network** (filter `service.php`) and
  **Application → Local Storage** panels visible.
- A `docker exec ... mysql ...` shell ready for spot-checks.

## E1 / E4 — 2× playback rate + backgrounded tab

**Trigger**

1. Start playback at 2× speed (player settings).
2. After 10 seconds, switch to a different browser tab.
3. Leave it backgrounded for 30 seconds.
4. Return to the player tab.

**Expected — UI**

The progress strip shows roughly the same coverage % as the wall-clock
elapsed (not throttled to half). No "fraud" badge in the gradebook.

**Expected — DB**

```sql
SELECT id, watched_intervals, fraud_count, last_fraud_reason
FROM mdl_fastpix_attempt
WHERE userid = <studentid>;
```

- `watched_intervals` contains one contiguous interval covering roughly
  the same seconds as wall-clock time (since 2× playback consumes twice
  the video per wall-clock second).
- `fraud_count = 0`. The 2× rate + visibility boost together push
  `maxDelta` to `Math.max(1.5, 2 × 1.5) × 2 = 6.0`, comfortably above the
  ~1s throttled tick.

## E2 — loop mode

**Trigger**

1. In DevTools console: `document.querySelector('fastpix-player').loop = true;`
2. Watch the video through to the end and let it loop back to the start.

**Expected — UI**

Progress strip should NOT jump backwards. Coverage stays at the value it
reached just before the loop boundary.

**Expected — DB**

```sql
SELECT watched_intervals FROM mdl_fastpix_attempt WHERE userid = <studentid>;
```

- Final `watched_intervals` shows one big interval up to roughly `duration`,
  not a chain of small intervals after the loop boundary.
- `fraud_count = 0`. The loop's negative delta is detected (`delta < 0 &&
  !isSeeking`) and `lastTime` resyncs without crediting.

## E3 — ended-event boundary snap

**Trigger**

1. Watch the video continuously to its end.
2. Let it auto-fire `ended` (do NOT seek away first).

**Expected — UI**

Progress strip immediately flips to 100% and turns green when `ended`
fires, even if the final `timeupdate` was at 99.97s.

**Expected — DB**

```sql
SELECT watched_intervals, has_completed, milestone_100_at
FROM mdl_fastpix_attempt WHERE userid = <studentid>;
```

- Last interval ends at the asset `duration`, not 99.97.
- `has_completed = 1`.
- `milestone_100_at` populated.

**Network**

A `record_view_progress` POST should fire with `ended_fired: true`. Server
response: `completion_state: 'complete'`.

## E5 — source changed mid-session

**Trigger**

This is rare in practice — `mod_form::validation` already blocks asset
swap on activities with real attempts. To force-trigger from DevTools:

```js
document.querySelector('fastpix-player').setAttribute('playback-id', 'fake-new-id');
document.querySelector('fastpix-player').dispatchEvent(new Event('loadedmetadata'));
```

**Expected — UI**

Progress strip resets to 0%. Local `watched` array clears, hasCompleted
flag drops.

**Expected — Console (no DB check needed since this is client-only reset)**

Next persist tick will POST `watched_intervals: '[]'`. Server may flag this
as ③ regression if the server already had intervals — that's the correct
behaviour: the legitimate teacher-swap case is forbidden, so this branch
is mainly defensive.

## E6 — buffering stall

**Trigger**

1. Open DevTools Network panel.
2. Throttle to "Slow 3G".
3. Click play and let the buffer stall mid-playback.
4. Wait 30 seconds while the player shows the buffering spinner.
5. Disable throttling; playback resumes.

**Expected — UI**

Progress strip does NOT grow during the stall (`waiting` event resyncs
`lastTime`).

**Expected — DB**

```sql
SELECT watched_intervals FROM mdl_fastpix_attempt WHERE userid = <studentid>;
```

- The total watched seconds in the intervals matches the user's actual
  playback time, not playback + stall duration.
- `fraud_count = 0` for what would otherwise have been an implausible_gain.

## Multi-session merge (D5 test mirror)

**Trigger**

1. Watch 30 seconds of a 100-second video.
2. Close the browser tab.
3. Re-open the activity; the strip rehydrates from server intervals.
4. Watch from 40s onward through the end.

**Expected — DB**

```sql
SELECT watched_intervals, has_completed
FROM mdl_fastpix_attempt
WHERE userid = <studentid>;
```

- `watched_intervals = '[[0,30],[40,100]]'` (two segments, server-merged).
- `coverage = 90s / 100s = 90% ≥ threshold` → `has_completed = 1`.
- `mdl_grade_grades.finalgrade = activity.grademax`.

**Replay check**

Watch a few more seconds on a third session. Expected:

- `mdl_grade_grades.timemodified` stays the same.
- `mdl_grade_grades_history` row count stays the same (CG4 idempotency).

## Reset between scenarios

```sql
DELETE FROM mdl_fastpix_attempt WHERE userid = <studentid>;
DELETE FROM mdl_grade_grades  WHERE userid = <studentid>;
```

Then reload the activity to start a fresh session.
