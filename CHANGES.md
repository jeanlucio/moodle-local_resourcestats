# Changes

## v1.2.1 (2026062000)

- The plugin now removes its per-user preferences (the per-user column display
  choices) on uninstall. Moodle core drops the plugin's own tables and settings
  automatically, but never touches the core user_preferences table, so these
  rows were previously left behind. A db/uninstall.php hook now deletes every
  local_resourcestats_* user preference.

## v1.2.0 (2026060903)

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

## v1.1.0 (2026060901)

- Display preferences changed from a single radio-button mode to three independent
  checkboxes — total accesses, unique students, and last student who accessed
- Admin settings updated to match: three separate on/off defaults instead of one
  combined mode selector
- All three badges default to off at the site level; each badge is opt-in independently

## v1.0.2 (2026051302)

- Badges now appear in course formats that do not use `[data-region="activity-card"]`
  (e.g. Blocos, Grid): injector falls back to `.activity-grid` (inserted after the
  row, not inside it) and then to the `cmitem` element itself
- Subsections are excluded from badge injection, same as labels — they are structural
  elements and never fire a `course_module_viewed` event
- Updated plugin icon

## v1.0.1 (2026051200)

- Fix PHPUnit test namespaces to satisfy Moodle Code Precheckers sniff
  `moodle.PHPUnit.TestCaseNames.UnexpectedLevel2NS`: removed the redundant
  `\tests` segment so each namespace mirrors the actual subdirectory path
  under `tests/` (observer_test → `local_resourcestats`, provider_test →
  `local_resourcestats\privacy`, controller_test →
  `local_resourcestats\view_stats`)

## v1.0.0 (2026043000) — Initial public release

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
