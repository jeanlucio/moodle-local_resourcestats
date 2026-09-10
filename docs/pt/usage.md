# 📖 Como Usar

Após a instalação, o plugin registra acessos em segundo plano apenas para **estudantes** (convidados e professores com `manageactivities` nunca são contados).

**Professores:**

1. Por padrão, **não há badges** até o administrador habilitar um padrão ou o professor ativar a exibição.
2. Abra o curso e clique em **Estatísticas do curso** na navegação do curso (pode ficar em *Mais* se a barra estiver cheia). Isso abre a visão geral do curso com dados de acesso de todas as atividades e o painel de alertas de engajamento.
3. Para ajustar quais badges aparecem na página do curso, clique em **Configurar exibição** no canto superior direito da página Estatísticas do curso.
4. Com pelo menos um badge ativado, ele aparece abaixo de cada módulo na página do curso.
5. Para o detalhamento por estudante de um módulo específico, clique no ícone de lupa em qualquer linha da tabela de estatísticas do curso.

**Administradores do site:**

1. Acesse **Administração do site > Plugins > Plugins locais > Estatísticas de Recursos**.
2. Ative os badges que devem estar ligados por padrão para todos os professores. Os cinco são desligados por padrão de fábrica.

**Badges disponíveis:**

| Badge | Descrição |
|-------|-----------|
| **Acessos totais** | Conta cada visita, incluindo repetições do mesmo estudante |
| **Estudantes únicos** | Conta estudantes distintos que acessaram ao menos uma vez |
| **Último estudante** | Exibe o nome do estudante que acessou mais recentemente |
| **Estudantes que concluíram** | Dos estudantes que o curso rastreia para conclusão, quantos atenderam às condições de conclusão da atividade. Exibido apenas para atividades que rastreiam conclusão |
| **Estudantes aprovados** | Quantos atingiram a nota de aprovação. Exibido apenas onde a conclusão exige nota de aprovação, único caso em que o Moodle registra aprovação |

Cada badge é controlado de forma independente — professores podem ativar qualquer combinação pela página de preferências.

**Os números de conclusão vêm do Moodle, não deste plugin.** São lidos ao vivo dos dados de conclusão do core, então já cobrem o período anterior à instalação do plugin, e seguem a regra do próprio Moodle para o que conta como concluído: onde a atividade exige nota de aprovação, uma nota reprovada não conta como concluída; onde não exige, conta. Os números devem, portanto, bater com o relatório **Conclusão de atividades** do mesmo curso.

**Grupos separados:** se o curso (ou uma atividade específica que o sobrescreva) usa o modo de grupos separados, um professor sem a capability `moodle/site:accessallgroups` vê os badges, a visão geral do curso e a tabela por atividade restritos apenas ao seu próprio grupo. Um professor com essa capability sempre vê os totais completos do curso, sem restrição.
