# Prozessstarter runtime chart implementation plan

Goal: Replace misleading activity percentages with one bar per recorded cronjob run, using exact timestamps and automatic duration scaling in the existing UI.
Architecture: A pure PHP log adapter pairs start IDs with child events. A dedicated Chart.js 2.7.2 adapter renders timestamp-based bars, status colors, summaries and selected-run details without changing shared chart behavior.
Spec: Approved conversation design, 2026-10-30. Existing theme, day-based windows, no connecting lines or averaging, honest unknown states. Explicit authorization covers implementation, commits, pushes, CI manifest integration and one production build, not a live deployment.

- [x] Add standalone regression tests for same-minute/zero/overnight durations, interleaved jobs, intermediate/mutex events, missing completion, sticky errors and invalid timestamps.
- [x] Add PHP log adapter and replace only getChart data path. Preserve the existing last-five-event table and global date filters. Default mini detail to seven days, provide 24h/7d/30d window selection.
- [x] Add isolated Chart.js runtime renderer. True timestamp X, auto nice Y with seconds/minutes/hours, no invented durations, per-run statuses and safe text details. Test scale extremes, timeframe changes, config and interactions with Node.
- [ ] Run all feature tests, changed-PHP lint, JS syntax, one fresh branch review, merge-compatibility check against production. Commit and push feature branch.
- [ ] Add manifest entry on feature/ci-workflow before final remote override and include regression suite in Pass 3. Validate, commit, push, dispatch Build Production and verify result.

Review focus: Unknown endpoints must never imply zero or success; error followed by completion must remain error; no clock rounding or aggregation; HTML/script escaping; dark-theme and repeated AJAX initialization must keep working.

Validation: PHP 21/21 assertions; Node 9/9 tests; three changed PHP files lint clean; JS syntax clean; 11 actual Chart.js browser checks and 4 theme lifecycle checks passed. One fresh review found three issues (Chart.js 2.7 numeric bar positions, completion beyond filter end, theme listener order); all corrected and regression-covered without a second review.
