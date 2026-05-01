# Moodle Local Resource Stats

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-local_resourcestats/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-local_resourcestats/actions/workflows/ci.yml)
![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat-square&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Stable-brightgreen?style=flat-square)

[English](#english) | [Português](#português)

---

## English

**Resource Stats** is a Moodle local plugin that tracks and displays access statistics for course modules directly on the course page.

It shows teachers how many times each resource or activity has been accessed — total views and unique students — without requiring any third-party analytics tool.

---

### ✨ Features

* 📊 **Access Badges:** Small badges displayed below each resource on the course page, visible only to teachers.
* 👤 **Unique Student Count:** Tracks how many distinct students accessed each module.
* 🔁 **Total View Count:** Tracks repeated accesses, counting every visit individually.
* 📅 **Per-Student Statistics:** Dedicated page showing each student's view count, first access date, and last access date.
* 🔢 **Site-wide Default:** Administrators choose the default badge mode for teachers who have not set a personal preference. Factory default is **none** — the plugin installs quietly and teachers opt in.
* ⚙️ **Display Preferences:** Each teacher overrides the site default via the **Statistics** link in the course navigation (tab bar / *More* overflow).
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

1. By default, **no badges** are shown until the site administrator sets a different default or the teacher opts in.
2. Open the course and click **Statistics** in the course navigation (it may appear under *More* if the tab bar is full). Choose a display mode and save.
3. Once a non-*none* mode is active, small access badges appear below each module on the course page.
4. For the full per-student breakdown, open any module and click the **Statistics** tab in the module settings navigation.

**Site administrators:**

1. Go to **Site administration > Plugins > Local plugins > Resource Statistics**.
2. Set **Default display mode**. Factory setting is *Don't display anything*.

**Badge display modes:**

| Mode     | Description                                   |
|----------|-----------------------------------------------|
| `none`   | Hides all badges (factory default)            |
| `unique` | Shows unique student count only              |
| `total`  | Shows total view count only                   |
| `both`   | Shows both counts and last viewer information |

The **Default** badge on the preferences page reflects the **administrator's** site-wide default, not a hardcoded value.

---

### 🧪 Automated Tests

Resource Stats ships with **PHPUnit unit tests** that run on every CI push across the full matrix (Moodle 4.5 → 5.x, PostgreSQL & MariaDB):

| Test file | Scenarios covered |
|-----------|------------------|
| `tests/observer_test.php` | View tracking logic: guests skipped, teachers skipped, first/repeat access, two-student isolation |
| `tests/view_stats/controller_test.php` | Statistics page: ordering, totals, Moodle-deleted users, GDPR-erased aggregate handling |
| `tests/privacy/provider_test.php` | Privacy API: context lookup, export, row deletion with aggregate transfer, bulk deletion |

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

Ele mostra ao professor quantas vezes cada recurso ou atividade foi acessado — total de visualizações e alunos únicos — sem precisar de nenhuma ferramenta externa de analytics.

---

### ✨ Funcionalidades

* 📊 **Badges de Acesso:** Pequenos badges exibidos abaixo de cada recurso na página do curso, visíveis apenas para professores.
* 👤 **Contagem de Alunos Únicos:** Registra quantos alunos distintos acessaram cada módulo.
* 🔁 **Total de Visualizações:** Registra acessos repetidos, contando cada visita individualmente.
* 📅 **Estatísticas por Aluno:** Página dedicada com contagem de acessos, data do primeiro acesso e data do último acesso por aluno.
* 🔢 **Padrão do site:** O administrador define o modo de exibição padrão para professores que ainda não definiram preferência pessoal. O padrão de fábrica é **nenhum** — o plugin instala sem impacto visual até alguém optar.
* ⚙️ **Preferências de Exibição:** Cada professor substitui o padrão do site pelo link **Estatísticas** na navegação do curso (barra de abas / menu *Mais*).
* 🔒 **Privacidade:** Na exclusão LGPD/GDPR, as linhas por aluno são **deletadas** e as contagens são transferidas para colunas agregadas (`deletedviews`, `deletedcount`) — sem `userid` nulo em índice único (compatível com SQL Server).
* ✅ **Conformidade com LGPD/GDPR:** Privacy API completa com suporte a exportação e exclusão de dados.

---

### 🎓 Finalidade Educacional

O Resource Stats foi projetado para apoiar o professor na **gestão baseada em dados**:

* **Monitorar o engajamento:** Identifique quais recursos e atividades estão sendo acessados e com que frequência.
* **Detectar a não participação de forma preventiva:** Perceba quais alunos nunca acessaram determinado material e intervenha antes que fiquem para trás.
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

1. Por padrão, **não há badges** até o administrador mudar o padrão do site ou o professor ativar a exibição.
2. Abra o curso e clique em **Estatísticas** na navegação do curso (pode ficar em *Mais* se a barra estiver cheia). Escolha o modo e salve.
3. Com um modo diferente de *nenhum*, os badges aparecem abaixo de cada módulo na página do curso.
4. Para o detalhamento por aluno, abra qualquer módulo e use a aba **Estatísticas** na navegação de configurações do módulo.

**Administradores do site:**

1. Acesse **Administração do site > Plugins > Plugins locais > Estatísticas de Recursos**.
2. Defina o **Modo de exibição padrão**. O padrão de fábrica é *Não exibir nada*.

**Modos de exibição do badge:**

| Modo     | Descrição                                            |
|----------|------------------------------------------------------|
| `none`   | Oculta todos os badges (padrão de fábrica)         |
| `unique` | Exibe apenas a contagem de alunos únicos           |
| `total`  | Exibe apenas o total de visualizações               |
| `both`   | Exibe ambas as contagens e o último visualizador    |

O badge **Padrão** na página de preferências reflete o padrão **definido pelo administrador**, não um valor fixo no código.

---

### 🧪 Testes Automatizados

O Resource Stats inclui **testes unitários PHPUnit** executados em todo push de CI na matriz completa (Moodle 4.5 → 5.x, PostgreSQL e MariaDB):

| Arquivo de teste | Cenários cobertos |
|------------------|------------------|
| `tests/observer_test.php` | Lógica de rastreamento: guests ignorados, professores ignorados, primeiro acesso, repetição, isolamento entre alunos |
| `tests/view_stats/controller_test.php` | Página de estatísticas: ordenação, totais, usuários excluídos pelo admin, dados agregados pós-LGPD |
| `tests/privacy/provider_test.php` | Privacy API: contextos, exportação, exclusão com transferência para o agregado, exclusão em lote |

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
