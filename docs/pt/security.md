# 🔐 Segurança e Conformidade

* Controle de acesso baseado em capabilities (`moodle/course:manageactivities`)
* Visualizações de professores e convidados nunca são registradas
* Proteção com `require_sesskey()` em todas as ações POST
* Labels e subsections são excluídos (nunca disparam evento de visualização)
* Sensível a grupos: toda superfície de exibição (badges na página do curso, visão
  geral do curso, tabela por atividade e exportação CSV/Excel) aplica a mesma
  restrição de grupos separados, limitada ao próprio grupo do professor quando ele
  não possui `moodle/site:accessallgroups` — inclusive quando uma atividade
  específica sobrescreve o modo de grupo do curso
* Exclusão de instância e de curso são observadas: apagar um módulo ou um curso
  inteiro remove imediatamente suas linhas de estatística, e qualquer linha
  remanescente de antes desses observers existirem é varrida na próxima exclusão
  de curso

### 🗑️ Modelo de Exclusão LGPD/GDPR

A linha de acesso por estudante (`local_resourcestats_user_views`) é **deletada**, não
anonimizada in loco, em pedidos de exclusão. O `viewcount` do estudante é transferido para
as colunas agregadas do módulo (`deletedviews`, `deletedcount`) antes da remoção da linha,
mantendo os totais do curso significativos sem reter nenhum dado identificável. Esse
modelo também evita armazenar `userid` nulo em uma coluna de índice único, o que falharia
no Microsoft SQL Server.

### 🔒 Privacy API

Implementação completa: declaração de metadados (as duas tabelas de armazenamento e as
três preferências de exibição por usuário), descoberta de contexto, exportação e exclusão
de dados tanto individual quanto em lote. Todo ponto de entrada que recebe um contexto
valida o nível do contexto antes de tocar em qualquer dado.

### ⚠️ Compatibilidade com Formatos de Curso

O Resource Stats funciona com qualquer formato de curso que utilize a renderização padrão
de atividades do Moodle (`[data-region="activity-card"]`), o que inclui os formatos
nativos **Tópicos**, **Semanas** e **Atividade Única**.

Formatos de terceiros que substituem o HTML padrão dos módulos por um layout próprio
(como formatos visuais de trilha ou quadro) podem não exibir os badges na página do
curso. A página de estatísticas e a coleta de dados não são afetadas — apenas a exibição
dos badges.
