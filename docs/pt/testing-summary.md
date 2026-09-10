# 🧪 Testes Automatizados

O Resource Stats inclui **141 casos de teste PHPUnit** e uma suíte Behat com **9 cenários**, executados em todo push de CI na matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

### PHPUnit — Testes Unitários e de Integração

| Arquivo de teste | Casos |
|-------------------|------:|
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

**Cobertura de linhas geral** (`moodle-coverage`, PHPUnit + Xdebug): **100%**.

### Behat — Testes de Aceitação

| Arquivo de feature | Cenários |
|---------------------|----------:|
| `local_resourcestats_teacher.feature` | 4 |
| `local_resourcestats_insights.feature` | 1 |
| `local_resourcestats_export.feature` | 2 |
| `local_resourcestats_access.feature` | 2 |
| **Total** | **9** |

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --tags=@local_resourcestats --profile=chrome
```

[Detalhamento completo teste a teste e tabela de cobertura →]({{ '/testing-pt.html' | relative_url }})
