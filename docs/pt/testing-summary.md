# 🧪 Testes Automatizados

O Resource Stats inclui **113 casos de teste PHPUnit**, executados em todo push de CI na matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

| Arquivo de teste | Casos |
|-------------------|------:|
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

**Cobertura de linhas geral** (`moodle-coverage`, PHPUnit + Xdebug): **100%**.

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
