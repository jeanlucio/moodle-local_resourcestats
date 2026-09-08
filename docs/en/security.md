# 🔐 Security & Compliance

* Capability-based access control (`moodle/course:manageactivities`)
* No teacher or guest views are ever recorded
* `require_sesskey()` protection on all POST actions
* Labels and subsections are excluded (they never fire a view event)
* Group-aware: every display surface (course-view badges, the course overview, the
  per-activity table, and the CSV/Excel export) applies the same separate-groups
  restriction, scoped to the caller's own group whenever they lack
  `moodle/site:accessallgroups` — including when an individual activity overrides
  the course's own group mode
* Instance and course deletion are observed: deleting a course module or an entire
  course purges its statistics rows immediately, and any row left over from before
  these observers existed is swept up the next time a course is deleted

### 🗑️ GDPR / LGPD Erasure Design

A student's per-row access data (`local_resourcestats_user_views`) is **deleted**, not
anonymised in place, on right-to-erasure requests. Their `viewcount` is transferred into
the module's aggregate columns (`deletedviews`, `deletedcount`) before the row is removed,
so course-wide totals stay meaningful without retaining any identifying data. This design
also avoids storing a nullable `userid` in a unique-indexed column, which would fail on
Microsoft SQL Server.

### 🔒 Privacy API

Full implementation: metadata declaration (both storage tables and all three per-user
display preferences), context discovery, data export, and deletion for both individual
and bulk requests. Every entry point that receives a context validates its context level
before touching any data.

### ⚠️ Course Format Compatibility

Resource Stats works with any course format that uses Moodle's standard activity rendering
(`[data-region="activity-card"]`), which includes the built-in **Topics**, **Weeks**, and
**Single Activity** formats.

Third-party formats that replace the standard module HTML with a custom layout (such as
visual trail or board formats) may not display the badges on the course page. The
statistics page and data collection are not affected — only the badge display.
