# Moodle Local Resource Stats

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_resourcestats/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_resourcestats/actions/workflows/ci.yml)
![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Stable-brightgreen?style=flat-square)

[English](#english) | [Português](#português)

---

## English

**Resource Stats** is a Moodle local plugin that tracks and displays access statistics for course modules directly on the course page.

It shows teachers how many times each resource or activity has been accessed — total views, unique students, and the last student who visited — without requiring any third-party analytics tool.

---

### ✨ Features

* 📊 **Access Badges:** Up to three independent badges displayed below each resource on the course page, visible only to teachers: total accesses, unique students, and last student who accessed.
* 👤 **Unique Student Count:** Tracks how many distinct students accessed each module.
* 🔁 **Total View Count:** Tracks repeated accesses, counting every visit individually.
* 🧑 **Last Visitor:** Displays the name of the most recent student who accessed the module.
* 📅 **Per-Student Statistics:** Dedicated page per module showing each student's view count, first access date, and last access date, with server-side sorting and pagination.
* 📈 **Course Statistics Overview:** Single page listing all trackable activities with total accesses, unique students, engagement percentage, last access date, and section name; activities with zero unique views are highlighted; all columns are sortable.
* 📥 **Data Export:** Both the per-module and course overview statistics pages offer one-click export to **CSV** and **Excel**, covering the full dataset (not just the current page).
* 🔔 **Engagement Alerts:** Panel on the course overview that flags activities not yet viewed by any student, low-engagement activities, and enrolled students with zero accesses; low-engagement threshold is configurable.
* 🔢 **Site-wide Defaults:** Administrators control three independent on/off defaults — one per badge. All three default to off, so the plugin installs quietly and teachers opt in.
* ⚙️ **Display Preferences:** Each teacher overrides the site defaults via the **Configure display** button inside the Course Statistics page.
* 🔒 **Privacy-Aware:** GDPR erasure **deletes** per-student rows and transfers their view counts into aggregate columns (`deletedviews`, `deletedcount`) — no nullable user IDs in unique indexes (SQL Server compatible).
* ✅ **GDPR Compliant:** Full Privacy API implementation with data export and deletion support.

---

### 🎓 Educational Purpose

Resource Stats is designed to support teachers in **data-driven course management**:

* **Monitor engagement:** Identify which resources and activities are being accessed and how frequently.
* **Detect non-participation early:** Spot students who have never accessed a given material and intervene before they fall behind.
* **Evaluate resource effectiveness:** Low access counts may signal that a resource is poorly positioned, unattractive, or unclear — prompting a pedagogical review.
* **Improve course structure:** Use access patterns over time to make evidence-based decisions about content sequencing and relevance.

Suitable for:

* Online and hybrid courses where access visibility is limited
* Courses with self-paced materials where participation is harder to track
* Teachers who want to act preventively rather than reactively

---

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5+    |
| PHP       | 8.1+    |

Moodle 4.5 or later is required for PSR-14 hooks support (`core\hook\output\before_standard_footer_html_generation`).

---

### 🛠️ Installation

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `local/` directory.
3. Rename the folder to `resourcestats` (if necessary).
   Final path:
   `your-moodle/local/resourcestats/`
4. Visit **Site administration > Notifications** to complete installation.

---

### 📖 Usage

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

---

### 🧪 Automated Tests

Resource Stats ships with **PHPUnit unit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB):

| Test file | Cases | Scenarios covered |
|-----------|------:|------------------|
| `tests/observer_test.php` | 10 | View tracking logic: guests skipped, teachers skipped, first/repeat access, two-student isolation |
| `tests/view_stats/controller_test.php` | 10 | Per-module statistics page: ordering, totals, sort fallback, Moodle-deleted users, GDPR-erased aggregate |
| `tests/course_stats/insights_test.php` | 9 | Engagement alerts engine: unviewed grouping, low-engagement threshold, zero-access students, singular/plural |
| `tests/course_stats/controller_test.php` | 10 | Course overview controller: activity rows, engagement calculation, teacher exclusion, sort by column, invalid sort fallback |
| `tests/privacy/provider_test.php` | 7 | Privacy API: context lookup, export, row deletion with aggregate transfer, bulk deletion |

Run them locally with:

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

---

### 🔐 Security & Compliance

* Capability-based access control (`moodle/course:manageactivities`)
* No teacher or guest views are ever recorded
* `require_sesskey()` protection on all POST actions
* Labels are excluded (they never fire a view event)
* GDPR: per-student rows are **deleted** on erasure; view counts are accumulated in aggregate columns so totals stay meaningful without storing identifying data

---

### ⚠️ Course Format Compatibility

Resource Stats works with any course format that uses Moodle's standard activity rendering (`[data-region="activity-card"]`), which includes the built-in **Topics**, **Weeks**, and **Single Activity** formats.

Third-party formats that replace the standard module HTML with a custom layout (such as visual trail or board formats) may not display the badges on the course page. The statistics page and data collection are not affected — only the badge display.

---

## 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

---

## Português

O **Resource Stats** é um plugin local para Moodle que registra e exibe estatísticas de acesso aos módulos do curso diretamente na página do curso.

Ele mostra ao professor quantas vezes cada recurso ou atividade foi acessado — total de visualizações, estudantes únicos e o último estudante que visitou — sem precisar de nenhuma ferramenta externa de analytics.

---

### ✨ Funcionalidades

* 📊 **Badges de Acesso:** Até três badges independentes exibidos abaixo de cada recurso na página do curso, visíveis apenas para professores: total de acessos, estudantes únicos e último estudante que acessou.
* 👤 **Contagem de Estudantes Únicos:** Registra quantos estudantes distintos acessaram cada módulo.
* 🔁 **Total de Visualizações:** Registra acessos repetidos, contando cada visita individualmente.
* 🧑 **Último Visitante:** Exibe o nome do estudante que acessou o módulo mais recentemente.
* 📅 **Estatísticas por Estudante:** Página dedicada por módulo com contagem de acessos, data do primeiro acesso e data do último acesso por estudante, com ordenação e paginação server-side.
* 📈 **Visão Geral do Curso:** Página única listando todas as atividades rastreáveis com total de acessos, estudantes únicos, percentual de engajamento, data do último acesso e seção; atividades sem nenhum acesso são destacadas; todas as colunas são ordenáveis.
* 📥 **Exportação de Dados:** Tanto a página de estatísticas por módulo quanto a visão geral do curso oferecem exportação com um clique para **CSV** e **Excel**, cobrindo o conjunto completo de dados (não apenas a página atual).
* 🔔 **Alertas de Engajamento:** Painel na visão geral do curso que sinaliza atividades não acessadas por nenhum estudante, atividades com baixo engajamento e estudantes sem nenhum acesso; o limiar de baixo engajamento é configurável.
* 🔢 **Padrões do site:** O administrador controla três padrões on/off independentes — um por badge. Os três são desligados por padrão, então o plugin instala sem impacto visual até alguém optar.
* ⚙️ **Preferências de Exibição:** Cada professor ajusta a exibição pelo botão **Configurar exibição** dentro da página Estatísticas do curso.
* 🔒 **Privacidade:** Na exclusão LGPD/GDPR, as linhas por aluno são **deletadas** e as contagens são transferidas para colunas agregadas (`deletedviews`, `deletedcount`) — sem `userid` nulo em índice único (compatível com SQL Server).
* ✅ **Conformidade com LGPD/GDPR:** Privacy API completa com suporte a exportação e exclusão de dados.

---

### 🎓 Finalidade Educacional

O Resource Stats foi projetado para apoiar o professor na **gestão baseada em dados**:

* **Monitorar o engajamento:** Identifique quais recursos e atividades estão sendo acessados e com que frequência.
* **Detectar a não participação de forma preventiva:** Perceba quais estudantes nunca acessaram determinado material e intervenha antes que fiquem para trás.
* **Avaliar a efetividade dos recursos:** Baixos índices de acesso podem indicar que um material está mal posicionado, pouco atrativo ou pouco claro — sinalizando a necessidade de revisão pedagógica.
* **Aprimorar a estrutura do curso:** Use os padrões de acesso ao longo do tempo para tomar decisões embasadas sobre sequenciamento e relevância do conteúdo.

Indicado para:

* Cursos online e híbridos onde a visibilidade de acesso é limitada
* Cursos com materiais de ritmo livre onde a participação é mais difícil de acompanhar
* Professores que preferem agir de forma preventiva em vez de reativa

---

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5+   |
| PHP        | 8.1+   |

O Moodle 4.5 ou superior é necessário para suporte a hooks PSR-14 (`core\hook\output\before_standard_footer_html_generation`).

---

### 🛠️ Instalação

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `local/` do seu Moodle.
3. Renomeie para `resourcestats` (se necessário).
   Caminho final:
   `seu-moodle/local/resourcestats/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.

---

### 📖 Como Usar

Após a instalação, o plugin registra acessos em segundo plano apenas para **alunos** (convidados e professores com `manageactivities` nunca são contados).

**Professores:**

1. Por padrão, **não há badges** até o administrador habilitar um padrão ou o professor ativar a exibição.
2. Abra o curso e clique em **Estatísticas do curso** na navegação do curso (pode ficar em *Mais* se a barra estiver cheia). Isso abre a visão geral do curso com dados de acesso de todas as atividades e o painel de alertas de engajamento.
3. Para ajustar quais badges aparecem na página do curso, clique em **Configurar exibição** no canto superior direito da página Estatísticas do curso.
4. Com pelo menos um badge ativado, ele aparece abaixo de cada módulo na página do curso.
5. Para o detalhamento por estudante de um módulo específico, clique no ícone de lupa em qualquer linha da tabela de estatísticas do curso.

**Administradores do site:**

1. Acesse **Administração do site > Plugins > Plugins locais > Estatísticas de Recursos**.
2. Ative os badges que devem estar ligados por padrão para todos os professores. Os três são desligados por padrão de fábrica.

**Badges disponíveis:**

| Badge | Descrição |
|-------|-----------|
| **Acessos totais** | Conta cada visita, incluindo repetições do mesmo estudante |
| **Estudantes únicos** | Conta estudantes distintos que acessaram ao menos uma vez |
| **Último estudante** | Exibe o nome do estudante que acessou mais recentemente |

Cada badge é controlado de forma independente — professores podem ativar qualquer combinação pela página de preferências.

---

### 🧪 Testes Automatizados

O Resource Stats inclui **testes unitários PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB):

| Arquivo de teste | Casos | Cenários cobertos |
|------------------|------:|------------------|
| `tests/observer_test.php` | 10 | Lógica de rastreamento: guests ignorados, professores ignorados, primeiro acesso, repetição, isolamento entre alunos |
| `tests/view_stats/controller_test.php` | 10 | Página por módulo: ordenação, totais, fallback de ordenação, usuários excluídos pelo admin, dados agregados pós-LGPD |
| `tests/course_stats/insights_test.php` | 9 | Motor de alertas de engajamento: agrupamento de não visualizados, limiar de baixo engajamento, estudantes sem acesso, singular/plural |
| `tests/course_stats/controller_test.php` | 10 | Visão geral do curso: linhas de atividade, cálculo de engajamento, exclusão de professores, ordenação por coluna, fallback de parâmetro inválido |
| `tests/privacy/provider_test.php` | 7 | Privacy API: contextos, exportação, exclusão com transferência para o agregado, exclusão em lote |

Para executar localmente:

```bash
vendor/bin/phpunit --testsuite local_resourcestats
```

---

### 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities (`moodle/course:manageactivities`)
* Visualizações de professores e convidados nunca são registradas
* Proteção com `require_sesskey()` em todas as ações POST
* Labels excluídos (nunca disparam evento de visualização)
* LGPD/GDPR: na exclusão, as linhas por aluno são **deletadas**; as contagens são acumuladas em colunas agregadas para manter totais úteis sem dados identificáveis

---

### ⚠️ Compatibilidade com Formatos de Curso

O Resource Stats funciona com qualquer formato de curso que utilize a renderização padrão de atividades do Moodle (`[data-region="activity-card"]`), o que inclui os formatos nativos **Tópicos**, **Semanas** e **Atividade Única**.

Formatos de terceiros que substituem o HTML padrão dos módulos por um layout próprio (como formatos visuais de trilha ou quadro) podem não exibir os badges na página do curso. A página de estatísticas e a coleta de dados não são afetadas — apenas a exibição dos badges.

---

## 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio
