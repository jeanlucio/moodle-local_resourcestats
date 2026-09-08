# ✨ Features

* 📊 **Access Badges:** Up to three independent badges displayed below each resource on the course page, visible only to teachers: total accesses, unique students, and last student who accessed.
* 👤 **Unique Student Count:** Tracks how many distinct students accessed each module.
* 🔁 **Total View Count:** Tracks repeated accesses, counting every visit individually.
* 🧑 **Last Visitor:** Displays the name of the most recent student who accessed the module.
* 📅 **Per-Student Statistics:** Dedicated page per module showing each student's view count, first access date, and last access date, with server-side sorting and pagination.
* 📈 **Course Statistics Overview:** Single page listing all trackable activities with total accesses, unique students, engagement percentage, last access date, and section name; activities with zero unique views are highlighted; all columns are sortable.
* 📥 **Data Export:** Both the per-module and course overview statistics pages offer one-click export to **CSV** and **Excel**, covering the full dataset (not just the current page).
* 🔔 **Engagement Alerts:** Panel on the course overview that flags activities not yet viewed by any student, low-engagement activities, and enrolled students with zero accesses. Each category is consolidated into a single alert with the affected activity names shown as clickable pills — anything beyond the first five collapses behind a "show more" disclosure, so a course with dozens of matching activities stays scannable. The low-engagement threshold is configurable.
* 👥 **Group-Aware:** In a course using separate groups, a teacher without `moodle/site:accessallgroups` sees totals, badges, and the per-student table scoped to their own group only — including when an individual activity overrides the course's own group mode.
* 🔢 **Site-wide Defaults:** Administrators control three independent on/off defaults — one per badge. All three default to off, so the plugin installs quietly and teachers opt in.
* ⚙️ **Display Preferences:** Each teacher overrides the site defaults via the **Configure display** button inside the Course Statistics page.
* 🔒 **Privacy-Aware:** GDPR erasure **deletes** per-student rows and transfers their view counts into aggregate columns (`deletedviews`, `deletedcount`) — no nullable user IDs in unique indexes (SQL Server compatible).
* ✅ **GDPR Compliant:** Full Privacy API implementation with data export and deletion support.
