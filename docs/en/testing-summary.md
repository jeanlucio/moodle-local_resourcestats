# 🧪 Automated Tests

Resource Stats ships with **113 PHPUnit test cases** and a **7-scenario Behat suite**, run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB).

### PHPUnit — Unit & Integration Tests

| Test file | Cases |
|-----------|------:|
| `tests/admin/setting_configcheckbox_reset_pref_test.php` | 3 |
| `tests/course_stats/controller_test.php` | 21 |
| `tests/course_stats/insights_test.php` | 13 |
| `tests/db_upgrade_test.php` | 2 |
| `tests/hook_listener_test.php` | 10 |
| `tests/lib_test.php` | 6 |
| `tests/local/group_visibility_test.php` | 3 |
| `tests/observer_test.php` | 14 |
| `tests/preferences/controller_test.php` | 4 |
| `tests/privacy/provider_test.php` | 17 |
| `tests/uninstall_test.php` | 2 |
| `tests/view_stats/controller_test.php` | 18 |
| **Total** | **113** |

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

**Overall line coverage** (`moodle-coverage`, PHPUnit + Xdebug): **100%**.

### Behat — Acceptance Tests

| Feature file | Scenarios |
|--------------|----------:|
| `local_resourcestats_teacher.feature` | 2 |
| `local_resourcestats_insights.feature` | 1 |
| `local_resourcestats_export.feature` | 2 |
| `local_resourcestats_access.feature` | 2 |
| **Total** | **7** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@local_resourcestats --profile=chrome
```

[Full test-by-test breakdown and coverage table →]({{ '/testing.html' | relative_url }})
