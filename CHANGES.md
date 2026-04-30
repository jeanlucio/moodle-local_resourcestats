# Changes

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
