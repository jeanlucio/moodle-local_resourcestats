# 🧪 Automated Tests

Resource Stats ships with **101 PHPUnit test cases**, run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

| Test file | Cases |
|-----------|------:|
| `tests/admin/setting_configcheckbox_reset_pref_test.php` | 3 |
| `tests/course_stats/controller_test.php` | 21 |
| `tests/course_stats/insights_test.php` | 13 |
| `tests/hook_listener_test.php` | 10 |
| `tests/local/group_visibility_test.php` | 3 |
| `tests/observer_test.php` | 14 |
| `tests/privacy/provider_test.php` | 17 |
| `tests/uninstall_test.php` | 2 |
| `tests/view_stats/controller_test.php` | 18 |
| **Total** | **101** |

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **93%**.

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
