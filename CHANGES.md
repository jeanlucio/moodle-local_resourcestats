# Changes

## [v1.4.0] — 2026-09-11

- Completion badges on the course page: for activities that track completion, a new badge
  shows how many of the students the course tracks for completion have met the activity's
  conditions ("X/total"), and — where completion requires a passing grade — a second badge
  shows how many passed. Both are opt-in, with a site-wide default set by the administrator
  and a personal override per teacher, exactly like the existing access badges. An activity
  that does not track completion shows no completion badge at all rather than a zero.
- The figures are read from Moodle's own completion data, so they cover the whole history
  of the course, including everything from before the plugin was installed, and they follow
  core's own rule for what counts as completed: on an activity that requires a passing
  grade, a failing grade does not count as completed; on one that only requires a grade,
  it does. The numbers therefore match the Activity completion report for the same course.
- Course statistics overview: two new sortable columns, "Completed" and "Passed", alongside
  the engagement figures. An activity without completion tracking, or without a pass mark,
  shows an em dash in the corresponding column.
- Per-activity page and both exports (per-activity and course-wide): each student's own
  completion state — completed, passed, did not pass, or not completed. A student the course
  does not track for completion is reported as "not tracked" rather than as not completed.
- Engagement alerts panel: two new alerts, for activities nobody has completed and for
  activities completed by fewer than a configurable share of the tracked students (new
  "Low completion threshold" setting, separate from the engagement one). Only activities
  that track completion are considered.
- Group visibility applies to the completion figures on every surface, including their
  denominator: a teacher restricted to their own groups never sees another group's counts.
- The last-viewer badge now renders after the numeric ones, so the numbers line up in the
  same position on every activity row regardless of the student's name length.

## [v1.3.0] — 2026-09-08

- Group visibility (separate groups) is now respected consistently everywhere the plugin
  shows data: the course-view badges, the course statistics overview, the per-activity
  table, and the CSV/Excel export. A teacher without `moodle/site:accessallgroups` sees
  only their own group's totals on all four surfaces, including when an individual
  activity overrides the course's own group mode. The course overview header now also
  shows how many of the enrolled students are in the caller's own group, when relevant.
- Engagement alerts panel redesigned: activities are grouped into a single alert per
  category (not yet viewed / low engagement) with activity names shown as clickable
  pills; beyond the first five, the rest collapse behind a native "show more" disclosure.
  Replaces the previous wall of comma-separated names and one alert row per activity.
- Statistics rows are now purged when their course module or course is deleted, and any
  row left over from before this cleanup existed is swept up the next time a course is
  deleted.
- Fixed: a teacher without `moodle/site:accessallgroups` could see the course-wide
  GDPR-erased totals on the per-activity page, which cannot be attributed to any single
  group.
- Fixed: saving the site-wide badge default settings reset every teacher's personal
  preference even when the saved value had not actually changed.
- Fixed: labels were incorrectly getting the "Statistics" settings tab meant only for
  trackable activities.
- Privacy Provider now declares all real columns of `local_resourcestats_views`
  (`totalviews`, `uniqueviews`, `deletedviews`, `deletedcount`), previously undeclared.
- Performance: several N+1 query patterns fixed in group-restricted rendering and in the
  Privacy Provider's export/deletion paths, particularly for courses with many
  activities, groups, or contexts.
- Quality: added a Behat acceptance suite (4 features, 7 scenarios) alongside the
  existing PHPUnit suite, now at 113 cases with 100% line coverage. Documentation moved
  to a dedicated site at jeanlucio.github.io/moodle-local_resourcestats.

## [v1.2.1] — 2026-06-20

- The plugin now removes its per-user preferences (the per-user column display
  choices) on uninstall. Moodle core drops the plugin's own tables and settings
  automatically, but never touches the core user_preferences table, so these
  rows were previously left behind. A db/uninstall.php hook now deletes every
  local_resourcestats_* user preference.
- The privacy provider now declares and exports the three per-user column
  display preferences (`local_resourcestats_show_total`, `_show_unique`,
  `_show_lastuser`). They were stored but previously undeclared, so a data
  subject access export omitted them. Added the metadata declarations, the
  `export_user_preferences` implementation and a covering unit test.
- The course navigation entries now use a valid core icon (`i/stats`). The
  previous reference to the non-existent `t/statistics` icon rendered as a
  broken image in themes that display navigation icons.

## [v1.2.0] — 2026-06-09

- Course statistics overview page showing all trackable activities with total accesses,
  unique students, engagement percentage, last access date, and section name; activities
  with zero unique views are highlighted
- Engagement alerts panel on the course overview: flags activities not yet viewed by any
  student (grouped into one alert when multiple), activities with low engagement, and
  enrolled students who have not accessed any activity; low-engagement threshold is
  configurable in admin settings; singular/plural forms handled correctly
- Server-side column sorting and pagination (50 rows per page) on both the per-activity
  and course overview statistics tables; active sort column is highlighted with a
  directional arrow; default sort is name ascending on the course overview and access
  count descending on the per-activity page
- "Configure display" button integrated directly into the course statistics page header,
  replacing the separate navigation link; course secondary nav now shows a single
  "Course statistics" entry
- Engagement percentage formula note shown in the course statistics footer
- Export to CSV and Excel on both the per-activity page and the course overview page,
  using `core\dataformat::download_data`
- Enrolled students who never accessed a module appear in the per-activity statistics
  table with access count = 0
- Soft-deleted users' view counts are now correctly included in the "deleted students"
  aggregate row
- Admin site settings now reset all teacher preferences on save, so every change to
  global badge visibility takes immediate effect for all teachers
- Brazilian Portuguese vocabulary: "aluno/alunos" replaced with "estudante/estudantes"
  throughout the language file

## [v1.1.0] — 2026-06-09

- Display preferences changed from a single radio-button mode to three independent
  checkboxes — total accesses, unique students, and last student who accessed
- Admin settings updated to match: three separate on/off defaults instead of one
  combined mode selector
- All three badges default to off at the site level; each badge is opt-in independently

## [v1.0.2] — 2026-05-13

- Badges now appear in course formats that do not use `[data-region="activity-card"]`
  (e.g. Blocos, Grid): injector falls back to `.activity-grid` (inserted after the
  row, not inside it) and then to the `cmitem` element itself
- Subsections are excluded from badge injection, same as labels — they are structural
  elements and never fire a `course_module_viewed` event
- Updated plugin icon

## [v1.0.1] — 2026-05-12

- Fix PHPUnit test namespaces to satisfy Moodle Code Precheckers sniff
  `moodle.PHPUnit.TestCaseNames.UnexpectedLevel2NS`: removed the redundant
  `\tests` segment so each namespace mirrors the actual subdirectory path
  under `tests/` (observer_test → `local_resourcestats`, provider_test →
  `local_resourcestats\privacy`, controller_test →
  `local_resourcestats\view_stats`)

## [v1.0.0] — 2026-04-30 — Initial public release

### Features

- Tracks total and unique views per course module (students only; teachers and
  managers are excluded from tracking)
- Badges displayed below each resource or activity on the course page (visible
  to teachers only)
- Per-teacher display mode preference: total accesses, unique students, both, or
  none — configurable from a dedicated preferences page
- Dedicated statistics page per module showing a per-student access table with
  first and last access dates
- Labels are excluded from tracking and from the Statistics navigation item
  (labels never fire a course_module_viewed event)
- Admin-deleted users (user.deleted = 1) and GDPR-erased students are combined
  into a single "deleted students" row in the statistics table, preserving
  aggregate view counts without storing personal identifiers

### Privacy API

- Full Privacy API implementation: metadata declaration, context discovery, data
  export, and erasure for both individual and bulk requests
- GDPR erasure deletes per-student rows and accumulates their view counts in
  dedicated aggregate columns (deletedviews, deletedcount), avoiding nullable
  values in a unique-indexed column — compatible with MySQL, MariaDB, PostgreSQL,
  and SQL Server

### Quality

- PHPUnit test suites covering the event observer, the view_stats controller,
  and the Privacy API provider (including GDPR erasure, admin-deleted users, and
  the combined hybrid scenario)
- GitHub Actions CI workflow: PHPCS, PHPUnit, and Behat against Moodle 4.5–5.x
  on PostgreSQL and MariaDB
