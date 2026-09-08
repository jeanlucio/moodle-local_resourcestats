# 📖 Usage

After installation, the plugin records views in the background for **students only** (guests and teachers with `manageactivities` are never tracked).

**Teachers:**

1. By default, **no badges** are shown until the site administrator enables a default or the teacher opts in.
2. Open the course and click **Course statistics** in the course navigation (it may appear under *More* if the tab bar is full). This opens the course overview page showing all activities with access data and engagement alerts.
3. To adjust which badges appear on the course page, click **Configure display** in the top-right corner of the Course Statistics page.
4. Once at least one badge is enabled, it appears below each module on the course page.
5. For the full per-student breakdown of a specific module, click the magnifying glass icon on any row in the course statistics table.

**Site administrators:**

1. Go to **Site administration > Plugins > Local plugins > Resource Statistics**.
2. Enable the badges that should be on by default for all teachers. All three are off by factory default.

**Available badges:**

| Badge | Description |
|-------|-------------|
| **Total accesses** | Counts every visit, including repeat visits by the same student |
| **Unique students** | Counts distinct students who accessed at least once |
| **Last student** | Shows the name of the most recent student visitor |

Each badge is controlled independently — teachers can enable any combination via the preferences page.

**Separate groups:** if the course (or an individual activity overriding it) uses separate groups mode, a teacher without the `moodle/site:accessallgroups` capability sees badges, the course overview, and the per-activity table scoped to their own group only. A teacher holding that capability always sees the unrestricted, course-wide totals.
