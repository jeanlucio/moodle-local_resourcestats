# ✨ Funcionalidades

* 📊 **Badges de Acesso:** Até três badges independentes exibidos abaixo de cada recurso na página do curso, visíveis apenas para professores: total de acessos, estudantes únicos e último estudante que acessou.
* 👤 **Contagem de Estudantes Únicos:** Registra quantos estudantes distintos acessaram cada módulo.
* 🔁 **Total de Visualizações:** Registra acessos repetidos, contando cada visita individualmente.
* 🧑 **Último Visitante:** Exibe o nome do estudante que acessou o módulo mais recentemente.
* 📅 **Estatísticas por Estudante:** Página dedicada por módulo com contagem de acessos, data do primeiro acesso e data do último acesso por estudante, com ordenação e paginação server-side.
* 📈 **Visão Geral do Curso:** Página única listando todas as atividades rastreáveis com total de acessos, estudantes únicos, percentual de engajamento, data do último acesso e seção; atividades sem nenhum acesso são destacadas; todas as colunas são ordenáveis.
* 📥 **Exportação de Dados:** Tanto a página de estatísticas por módulo quanto a visão geral do curso oferecem exportação com um clique para **CSV** e **Excel**, cobrindo o conjunto completo de dados (não apenas a página atual).
* 🔔 **Alertas de Engajamento:** Painel na visão geral do curso que sinaliza atividades não acessadas por nenhum estudante, atividades com baixo engajamento e estudantes sem nenhum acesso. Cada categoria é consolidada em um único alerta, com os nomes das atividades exibidos como pills clicáveis — o que passar de cinco fica recolhido atrás de um "mostrar mais", mantendo o painel legível mesmo com dezenas de atividades. O limiar de baixo engajamento é configurável.
* 👥 **Sensível a Grupos:** Em um curso com grupos separados, um professor sem `moodle/site:accessallgroups` vê totais, badges e a tabela por estudante restritos apenas ao seu próprio grupo — inclusive quando uma atividade específica sobrescreve o modo de grupo do curso.
* 🔢 **Padrões do site:** O administrador controla três padrões on/off independentes — um por badge. Os três são desligados por padrão, então o plugin instala sem impacto visual até alguém optar.
* ⚙️ **Preferências de Exibição:** Cada professor ajusta a exibição pelo botão **Configurar exibição** dentro da página Estatísticas do curso.
* 🔒 **Privacidade:** Na exclusão LGPD/GDPR, as linhas por aluno são **deletadas** e as contagens são transferidas para colunas agregadas (`deletedviews`, `deletedcount`) — sem `userid` nulo em índice único (compatível com SQL Server).
* ✅ **Conformidade com LGPD/GDPR:** Privacy API completa com suporte a exportação e exclusão de dados.
