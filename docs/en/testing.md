# 🧪 Automated Tests

Resource Stats ships with **113 PHPUnit test cases**, run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

| Test file | Cases | What is covered |
|-----------|------:|----------------|
| `admin/setting_configcheckbox_reset_pref_test.php` | 3 | Admin setting write behaviour: saving an unrelated field leaves every teacher's preference untouched; a genuinely changed site default clears every teacher's preference override so the new default takes effect immediately; a preference under a different key is never touched |
| `course_stats/controller_test.php` | 21 | Course overview controller: empty state for no trackable activities; unviewed-activity flag and engagement-percentage calculation; zero-enrolled-students division-by-zero guard; teachers excluded from totals; `get_students()`'s query count stays flat as enrolment grows; section name derivation from the course format; sort by activity name asc/desc and by totalviews desc, with an invalid-sort fallback; a group-restricted teacher sees only their own group's course-level totals and per-activity totals (both course-wide and activity-overriding-course-groupmode scenarios), a teacher in no group sees zeroed activity totals, a teacher holding `moodle/site:accessallgroups` always sees everyone; the CSV/Excel export respects the same group restriction (course-level and per-activity override) and carries each student's real view data (viewcount, formatted first/last access), not just their name; export with no trackable activities returns a well-formed empty file; pagination renders once activities exceed one page (50) |
| `course_stats/insights_test.php` | 13 | Engagement alerts engine: no alerts for an empty activity list or when every activity is well-engaged; a single unviewed activity produces a singular alert, multiple unviewed activities are grouped into one plural alert; an unviewed activity is never also flagged as low-engagement (the two categories are mutually exclusive); an activity strictly below the configured threshold triggers a warning, one exactly at the threshold does not; singular/plural wording for students with zero access; a group-restricted caller's zero-access count only ever considers their own visible students, never leaking another group's activity into it; an activity list at the maximum-visible threshold shows every item with nothing collapsed, one activity beyond it uses the singular "show 1 more" disclosure, and several beyond it use the plural form |
| `db_upgrade_test.php` | 2 | The one upgrade step carrying real data-migration logic (purging orphaned rows): crossing the 2026090700 savepoint calls the purge and records the savepoint; an `$oldversion` already at the savepoint is a no-op — neither the purge nor the savepoint write happens twice |
| `hook_listener_test.php` | 10 | Course-view badge injection: a teacher holding `moodle/site:accessallgroups` sees the combined course-wide totals and the true last viewer's name; a group-restricted teacher sees only their own group's totals and never the other group's student name; a teacher in no group sees a zeroed-out badge; badges render correctly across multiple activities sharing the same grouping (the shared memoisation cache); six no-op guards — a page that is not a course-view page, the site course itself, a caller without `moodle/course:manageactivities`, all three display preferences left disabled, and a course with no trackable activities — plus labels/subsections being excluded from badge tracking while still appearing in the `excludedcmids` array handed to the AMD module |
| `lib_test.php` | 6 | The two navigation-extension callbacks: `extend_navigation_course()` adds a "Course statistics" link for a user holding `moodle/course:manageactivities` and skips a student; `extend_settings_navigation()` adds a "Statistics" tab nested under a module's own settings node for a manager, skips a student, skips a label (no dedicated view page), and is a no-op for a non-module context — exercised through a real `settings_navigation` tree, the only way core actually calls this hook |
| `local/group_visibility_test.php` | 3 | The shared group-restriction helper's memoisation: a caller-supplied cache is reused across two activities sharing the same grouping (the second lookup costs zero extra queries), the same two calls made *without* a shared cache each query independently (the pre-fix baseline behaviour, kept working for a single-activity caller); the group-scoped enrolment query itself is likewise reused across activities that resolve to the same group IDs |
| `observer_test.php` | 14 | View-tracking event logic: guest users and anyone holding `moodle/course:manageactivities` (teachers, managers) are always skipped; a student's first access creates both statistics rows with equal, non-zero first/last timestamps; each repeat access increments `viewcount`/`totalviews`, updates `lastviewtime`, and never touches `firstviewtime`; `uniqueviews` only increases on a student's first view; two students are tracked independently without interfering with each other; deleting a course module through the real `course_delete_module()` API removes only that module's statistics rows, leaving every other module untouched; deleting an entire course through the real `delete_course()` API removes statistics rows for every module it contained; a course deletion also sweeps up any row left over from before these observers existed |
| `preferences/controller_test.php` | 4 | The display-preferences form controller: submitting all three checkboxes saves all three as enabled; submitting with a checkbox absent from the request (a real unchecked checkbox) saves it as disabled rather than leaving it untouched; the template context reflects the saved preferences plus the correct action/return URLs and a valid sesskey; the page URL carries the given return URL |
| `privacy/provider_test.php` | 17 | Full Privacy API coverage: context discovery for a user and the reverse per-context user listing; data export for a single approved context and correctly across every approved context at once, with a query-count regression guard proving the batched approach does not scale with the number of contexts; per-user deletion transfers the erased viewcount into the module's aggregate columns, leaves other students' rows untouched, and updates every affected module's own aggregate when a student accessed more than one; bulk deletion touches only the approved users, accumulating their combined viewcount; export of the three per-user display preferences; `get_metadata()` declares every real column of both storage tables (verified against `$DB->get_columns()`, not a hand-picked subset) plus all three preferences; five separate "non-module context" guards across `get_users_in_context`, `export_user_data`, `delete_data_for_all_users_in_context`, `delete_data_for_user`, and `delete_data_for_users` are each proven to be a no-op |
| `uninstall_test.php` | 2 | The pre-uninstallation hook (`xmldb_local_resourcestats_uninstall()`) deletes only this plugin's own `local_resourcestats_*`-prefixed user preferences, leaving an unrelated preference and another plugin's identically-shaped preference key untouched; running it with nothing to match is a harmless no-op |
| `view_stats/controller_test.php` | 18 | Per-activity statistics page: empty state with no access records; students ordered by viewcount descending; totals sum correctly across all students; GDPR-erased views and a Moodle soft-deleted (`user.deleted = 1`) student are each detected and combined into a single deleted-row; sort by fullname asc/desc and by lastviewtime desc, with an invalid-sort fallback to the default; `build_student_rows()`'s query count stays flat as enrolment grows; pagination renders once enrolled students exceed one page (50); the CSV/Excel export carries each student's real view data (viewcount, formatted first/last access), not just their name, and returns a well-formed empty file for an activity with no enrolled students; a group-restricted teacher sees only their own group (never miscounting the other group as a deleted/erased row), a teacher in no group sees no students at all, a teacher without `accessallgroups` never sees the course-wide GDPR-erased totals (which cannot be attributed to any one group), and a teacher holding `accessallgroups` always sees everyone unaffected |
| **Grand Total** | **113** | |

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

**Line coverage by class** (`moodle-coverage`, PHPUnit + Xdebug):

| Class | Line coverage |
|-------|:-------------:|
| `admin\setting_configcheckbox_reset_pref` | 100% |
| `course_stats\controller` | 100% |
| `course_stats\insights` | 100% |
| `hook_listener` | 98% |
| `local\group_visibility` | 100% |
| `observer` | 98% |
| `preferences\controller` | 100% |
| `privacy\provider` | 100% |
| `view_stats\controller` | 100% |
| **Overall** | **100%** |

### Global-Function Files

`lib.php` and `db/upgrade.php` define only global functions, not classes, so the
per-class breakdown above has nothing to attribute them to. Both are still instrumented
and folded into the **Overall** figure; measured on their own instead:

| File | Lines coverage |
|------|:--------------:|
| `lib.php` | 97% (30/31) |
| `db/upgrade.php` | 100% (4/4) |

`lib.php`'s one uncovered line is the top-level `$settingsnav->add_node($node)` fallback
used only when a module's own `modulesettings` node is missing from the settings navigation
tree — Moodle's `settings_navigation::load_module_settings()` always creates that node for a
real course module context, so this branch is defensive and not reachable through the public
navigation API in a test.

### A Real Bug Found Writing These Tests

Auditing this suite's real coverage attribution (rather than trusting the headline
percentage at face value) surfaced three completely untested areas —
`preferences\controller`, `lib.php`, and `db/upgrade.php` — all now closed above. Writing
the label-exclusion test for `lib.php` caught a genuine, pre-existing bug in the process:
`local_resourcestats_extend_settings_navigation()` guarded its label check with
`isset($PAGE->cm)`, but `moodle_page` exposes `cm` only through `__get()` with no matching
`__isset()` — PHP's `isset()` on a magic-only property always returns `false`, regardless of
whether a course module is actually set. The guard could never fire, so every label received
a "Statistics" tab it was explicitly meant never to get. Fixed by checking `$PAGE->cm !== null`
instead, which actually reads the value.
