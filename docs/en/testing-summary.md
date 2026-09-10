# 🧪 Automated Tests

Resource Stats ships with **141 PHPUnit test cases** and a **10-scenario Behat suite**, run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests

| Test file | Cases |
|-----------|------:|
| `tests/admin/setting_configcheckbox_reset_pref_test.php` | 3 |
| `tests/course_stats/controller_test.php` | 24 |
| `tests/course_stats/insights_test.php` | 16 |
| `tests/db_upgrade_test.php` | 2 |
| `tests/hook_listener_test.php` | 14 |
| `tests/lib_test.php` | 6 |
| `tests/local/completion_stats_test.php` | 15 |
| `tests/local/group_visibility_test.php` | 3 |
| `tests/observer_test.php` | 14 |
| `tests/preferences/controller_test.php` | 4 |
| `tests/privacy/provider_test.php` | 17 |
| `tests/uninstall_test.php` | 2 |
| `tests/view_stats/controller_test.php` | 21 |
| **Total** | **141** |

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **100%**.

### Behat — Acceptance Tests

| Feature file | Scenarios |
|--------------|----------:|
| `local_resourcestats_teacher.feature` | 5 |
| `local_resourcestats_insights.feature` | 1 |
| `local_resourcestats_export.feature` | 2 |
| `local_resourcestats_access.feature` | 2 |
| **Total** | **10** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@local_resourcestats --profile=chrome
```

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
