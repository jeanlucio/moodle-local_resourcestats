# 🧪 Testes Automatizados

O Resource Stats inclui **113 casos de teste PHPUnit**, executados em todo push de CI na matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB).

| Arquivo de teste | Casos | O que é coberto |
|-------------------|------:|------------------|
| `admin/setting_configcheckbox_reset_pref_test.php` | 3 | Comportamento de escrita da configuração admin: salvar um campo não relacionado não toca em nenhuma preferência de professor; um padrão de site genuinamente alterado limpa a preferência de todos os professores para que o novo padrão valha imediatamente; uma preferência de outra chave nunca é tocada |
| `course_stats/controller_test.php` | 21 | Controller da visão geral do curso: estado vazio sem atividades rastreáveis; flag de não-visualizada e cálculo do percentual de engajamento; proteção contra divisão por zero sem estudantes inscritos; professores excluídos dos totais; a contagem de queries de `get_students()` não escala com o número de matrículas; nome de seção derivado do formato do curso; ordenação por nome da atividade asc/desc e por total de acessos desc, com fallback para ordenação inválida; um professor restrito a grupo vê apenas os totais do seu próprio grupo em nível de curso e por atividade (tanto no cenário do curso inteiro quanto no de uma atividade que sobrescreve o modo de grupo do curso), um professor sem grupo vê totais zerados por atividade, um professor com `moodle/site:accessallgroups` sempre vê todo mundo; a exportação CSV/Excel respeita a mesma restrição de grupo (em nível de curso e por sobrescrita de atividade) e carrega os dados reais de acesso de cada estudante (quantidade de acessos, primeiro/último acesso formatados), não só o nome; a exportação sem atividades rastreáveis retorna um arquivo vazio bem formado; a paginação aparece quando as atividades ultrapassam uma página (50) |
| `course_stats/insights_test.php` | 13 | Motor de alertas de engajamento: nenhum alerta para uma lista de atividades vazia ou quando tudo está bem engajado; uma única atividade não visualizada produz um alerta singular, várias são agrupadas em um único alerta plural; uma atividade não visualizada nunca também é sinalizada como baixo engajamento (as duas categorias são mutuamente exclusivas); uma atividade estritamente abaixo do limiar configurado dispara um aviso, uma exatamente no limiar não dispara; texto singular/plural para estudantes sem nenhum acesso; a contagem de "sem acesso" de um chamador restrito a grupo considera apenas os estudantes visíveis a ele, nunca vazando a atividade de outro grupo; uma lista de atividades no limite máximo visível mostra tudo sem nada recolhido, uma atividade além desse limite usa o rótulo singular "mostrar mais 1", e várias além dele usam a forma plural |
| `db_upgrade_test.php` | 2 | O único passo de upgrade com lógica real de migração de dados (limpar linhas órfãs): cruzar o savepoint 2026090700 chama a limpeza e grava o savepoint; um `$oldversion` já no savepoint é um no-op — nem a limpeza nem a gravação do savepoint acontecem de novo |
| `hook_listener_test.php` | 10 | Injeção dos badges na página do curso: um professor com `moodle/site:accessallgroups` vê os totais combinados do curso inteiro e o nome real do último visitante; um professor restrito a grupo vê apenas os totais do seu próprio grupo e nunca o nome de um estudante de outro grupo; um professor sem grupo vê um badge zerado; os badges são renderizados corretamente em várias atividades que compartilham o mesmo agrupamento (cache de memoização compartilhado); seis guardas de no-op — uma página que não é de visão de curso, o próprio curso do site, um chamador sem `moodle/course:manageactivities`, as três preferências de exibição desligadas, e um curso sem atividades rastreáveis — além de labels/subsections serem excluídos do rastreamento e ainda assim aparecerem no array `excludedcmids` repassado ao módulo AMD |
| `lib_test.php` | 6 | Os dois callbacks de extensão de navegação: `extend_navigation_course()` adiciona o link "Estatísticas do curso" para quem tem `moodle/course:manageactivities` e não adiciona para um estudante; `extend_settings_navigation()` adiciona a aba "Estatísticas" aninhada sob o próprio nó de configurações do módulo para um gestor, não adiciona para um estudante, não adiciona para um label (sem página de visualização própria), e é no-op para um contexto não-módulo — exercitado através de uma árvore `settings_navigation` real, o único jeito pelo qual o core de fato chama esse hook |
| `local/group_visibility_test.php` | 3 | Memoização do helper compartilhado de restrição por grupo: um cache fornecido pelo chamador é reaproveitado entre duas atividades do mesmo agrupamento (a segunda consulta custa zero queries extras), as mesmas duas chamadas *sem* cache compartilhado consultam cada uma independentemente (o comportamento anterior à correção, mantido funcionando para um chamador de atividade única); a própria query de matrícula com escopo de grupo também é reaproveitada entre atividades que resolvem para os mesmos IDs de grupo |
| `observer_test.php` | 14 | Lógica de rastreamento de eventos de visualização: usuários convidados e qualquer um com `moodle/course:manageactivities` (professores, gestores) são sempre ignorados; o primeiro acesso de um estudante cria as duas linhas de estatística com timestamps de primeiro/último acesso iguais e não-nulos; cada acesso repetido incrementa `viewcount`/`totalviews`, atualiza `lastviewtime` e nunca altera `firstviewtime`; `uniqueviews` só aumenta na primeira visualização de cada estudante; dois estudantes são rastreados de forma independente sem interferência mútua; apagar um módulo pela API real `course_delete_module()` remove apenas as linhas de estatística daquele módulo, sem afetar os demais; apagar um curso inteiro pela API real `delete_course()` remove as linhas de estatística de todos os módulos que ele continha; a exclusão de um curso também varre qualquer linha remanescente de antes desses observers existirem |
| `preferences/controller_test.php` | 4 | O controller do formulário de preferências de exibição: submeter as três caixas marcadas salva as três como ativadas; submeter com uma caixa ausente da requisição (uma caixa desmarcada de verdade) salva como desativada em vez de deixar intocada; o contexto do template reflete as preferências salvas mais as URLs de ação/retorno corretas e um sesskey válido; a URL da página carrega a URL de retorno informada |
| `privacy/provider_test.php` | 17 | Cobertura completa da Privacy API: descoberta de contexto para um usuário e a listagem reversa de usuários por contexto; exportação de dados para um único contexto aprovado e corretamente através de todos os contextos aprovados de uma vez, com uma proteção de contagem de queries provando que a abordagem em lote não escala com o número de contextos; a exclusão por usuário transfere o `viewcount` apagado para as colunas agregadas do módulo, não afeta as linhas de outros estudantes, e atualiza corretamente cada módulo afetado quando o estudante acessou mais de um; a exclusão em lote atinge apenas os usuários aprovados, acumulando o `viewcount` combinado deles; exportação das três preferências de exibição por usuário; `get_metadata()` declara todas as colunas reais das duas tabelas de armazenamento (verificado contra `$DB->get_columns()`, não um subconjunto escolhido a dedo) mais as três preferências; cinco guardas separadas de "contexto não-módulo" em `get_users_in_context`, `export_user_data`, `delete_data_for_all_users_in_context`, `delete_data_for_user` e `delete_data_for_users` são cada uma comprovada como no-op |
| `uninstall_test.php` | 2 | O hook de pré-desinstalação (`xmldb_local_resourcestats_uninstall()`) apaga apenas as preferências de usuário prefixadas com `local_resourcestats_*` deste plugin, sem tocar em uma preferência não relacionada nem na chave de preferência de outro plugin com formato idêntico; rodá-lo sem nada para casar é um no-op inofensivo |
| `view_stats/controller_test.php` | 18 | Página de estatísticas por atividade: estado vazio sem registros de acesso; estudantes ordenados por quantidade de acessos decrescente; totais somam corretamente entre todos os estudantes; visualizações apagadas por LGPD/GDPR e um estudante com exclusão leve do Moodle (`user.deleted = 1`) são detectados e combinados em uma única linha de excluídos; ordenação por nome completo asc/desc e por último acesso desc, com fallback para ordenação inválida; a contagem de queries de `build_student_rows()` não escala com o número de matrículas; a paginação aparece quando os estudantes inscritos ultrapassam uma página (50); a exportação CSV/Excel carrega os dados reais de acesso de cada estudante (quantidade de acessos, primeiro/último acesso formatados), não só o nome, e retorna um arquivo vazio bem formado para uma atividade sem estudantes inscritos; um professor restrito a grupo vê apenas o seu próprio grupo (sem contar erroneamente o outro grupo como uma linha excluída/apagada), um professor sem grupo não vê nenhum estudante, um professor sem `accessallgroups` nunca vê os totais de LGPD/GDPR do curso inteiro (que não podem ser atribuídos a um grupo específico), e um professor com `accessallgroups` sempre vê todo mundo, sem ser afetado |
| **Total Geral** | **113** | |

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

**Cobertura de linhas por classe** (`moodle-coverage`, PHPUnit + Xdebug):

| Classe | Cobertura de linhas |
|--------|:--------------------:|
| `admin\setting_configcheckbox_reset_pref` | 100% |
| `course_stats\controller` | 100% |
| `course_stats\insights` | 100% |
| `hook_listener` | 98% |
| `local\group_visibility` | 100% |
| `observer` | 98% |
| `preferences\controller` | 100% |
| `privacy\provider` | 100% |
| `view_stats\controller` | 100% |
| **Geral** | **100%** |

### Arquivos de Funções Globais

`lib.php` e `db/upgrade.php` definem apenas funções globais, não classes, então o
detalhamento por classe acima não tem a quem atribuí-los. Ambos são instrumentados e
entram no cálculo do percentual **Geral**; medidos isoladamente:

| Arquivo | Cobertura de linhas |
|---------|:--------------------:|
| `lib.php` | 97% (30/31) |
| `db/upgrade.php` | 100% (4/4) |

A única linha não coberta do `lib.php` é o fallback de nível superior
`$settingsnav->add_node($node)`, usado apenas quando o próprio nó `modulesettings` de um
módulo está ausente da árvore de navegação de configurações — o
`settings_navigation::load_module_settings()` do Moodle sempre cria esse nó para um contexto
de módulo de curso real, então esse ramo é defensivo e não é alcançável pela API pública de
navegação em um teste.
