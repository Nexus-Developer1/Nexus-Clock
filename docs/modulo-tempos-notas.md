# Módulo de Tempos — notas de implementação

Adaptações à especificação ([`modulo-tempos.md`](modulo-tempos.md)) e decisões tomadas durante a
implementação. **Onde as duas discordarem, vale este documento.** Cada entrada diz quando e porquê.

---

## 1. Arquitetura (2026-09-14, decidido com o responsável técnico)

- **Aplicação própria da suite**, em `Nexus-Clock`, como a Knowledgebase e o Portal — não é um
  módulo dentro do repositório da Nexus Infra (`nexus-ops`). Liga-se no servidor.
- **Sem login próprio**: sessão partilhada com o portal; acesso pela chave `tempos` na tabela
  `aplicacoes`; papel lido de `acessos.papel` (`admin` | `tecnico`; valor desconhecido = `tecnico`).
- **Mesma base de dados da Nexus Infra**, com registo de migrações próprio (`migrations_tempos`).
  É preciso para as chaves estrangeiras e para a view materializada juntar tempos com contratos.
- **Laravel 13 + Livewire** (a UI chega na Fase 2). A especificação pede Livewire e a grelha semanal
  com gravação célula a célula precisa dele. Sem Node no servidor (CSS compilado no repositório).
- **Testes em PostgreSQL** (`tempos_testing`), não em SQLite: view materializada, índices parciais
  e `text[]` não existem em SQLite.
- A secção "Tempos" nas fichas de cliente e contrato (Fase 3) mexe na Nexus Infra — fica para o
  fim, como um link, e só com aprovação.

## 2. Nomes (a especificação usava inglês; a suite usa português)

| Especificação | Implementação |
|---|---|
| `time_entries` | `registos_tempo` |
| `user_id` · `client_id` · `contract_id` · `intervention_id` | `tecnico_id` · `cliente_id` · `contrato_id` · `intervencao_id` |
| `started_at` · `ended_at` · `duration_sec` | `inicio` · `fim` · `duracao_seg` |
| `billable` · `description` · `tags` · `source` | `faturavel` · `descricao` · `etiquetas` · `origem` |
| `source`: `timesheet`/`timer`/`import` | `timesheet`/`cronometro`/`importacao` |
| `submitted_at` · `locked_at` · `invoiced_at` | `submetido_em` · `fechado_em` · `faturado_em` |
| `created_by` · `updated_by` | `criado_por` · `alterado_por` |
| `rates` | `tarifas` |
| `scope_type` (`App\Models\Contract`…) · `scope_id` | `ambito_tipo` (`contrato`/`cliente`/`tecnico`, null = global) · `ambito_id` |
| `bill_rate_cents` · `cost_rate_cents` | `preco_hora_cent` · `custo_hora_cent` |
| `valid_from` · `valid_to` | `valido_de` · `valido_ate` |
| `contract_allowances` | `contrato_horas_incluidas` |
| `period`: `monthly`/`quarterly`/`yearly`/`total` | `periodo`: `mensal`/`trimestral`/`anual`/`total` |
| `hours_included` · `rollover` · `overage_billable` · `overage_rate_id` | `horas_incluidas` · `transita` · `excedente_faturavel` · `tarifa_excedente_id` |
| `rounding_minutes` · `rounding_mode` (`up`/`nearest`/`down`) | `arredondamento_min` · `arredondamento_modo` (`cima`/`proximo`/`baixo`) |
| `timesheet_periods` (`week_start`, `status`, `draft`/`submitted`/`reopened`, `reopened_by`) | `semanas_tempo` (`semana_inicio`, `estado`, `rascunho`/`submetida`/`reaberta`, `reaberta_por`) |
| view `contract_period_usage` | view `contrato_consumo_periodo` |
| `TimeEntryPolicy` | `RegistoTempoPolicy` |
| `RateResolver` · `AllowanceCalculator` · `DurationParser` | `ResolvedorTarifa` · `CalculadorHorasIncluidas` · `LeitorDuracao` |
| Job `RefreshContractPeriodUsage` | Job `AtualizarConsumoContratos` |
| scopes `forUser` · `forContract` · `inPeriod` · `billable` · `unlocked` | `doTecnico` · `doContrato` · `noPeriodo` · `faturaveis` · `porFechar` |
| permissões `time.view.all` · `time.edit.all` · `time.lock` · `time.unlock` · `time.export` | Gates `tempos-ver-todos` · `tempos-editar-todos` · `tempos-fechar-mes` · `tempos-reabrir` · `tempos-exportar` |
| `Reports\…` (Fase 3) | `Tempos\Relatorios\…` — "Relatórios" na Nexus Infra são os de intervenção |

## 3. Decisões da Fase 1 (2026-09-14)

### Permissões
- A suite não tem sistema de permissões granular: há o papel do portal e Gates. `tempos-reabrir`
  (a `time.unlock`) é **admin E email na lista** `TEMPOS_PODE_REABRIR` (por omissão `suporte@nxs.pt`)
  — o mesmo padrão dos aprovadores de despesas da Nexus Infra. As outras quatro são "é admin".

### Base de dados
- **Chaves estrangeiras para tabelas da Nexus Infra com `ON DELETE SET NULL`** (convenção da suite,
  depois do erro 500 do portal ao eliminar quem tinha despesas). Por isso `tecnico_id` e
  `cliente_id` são nullable na base de dados; a obrigatoriedade fica na aplicação.
- Constraints da especificação implementadas como `check` (duração 0–86400, `fim` ⇒ `duracao_seg`,
  valores dos enums, âmbito coerente das tarifas, semana a começar à segunda).
- **Não há** constraint de "uma só horas-incluídas ativa por contrato numa data" na base de dados
  (exigiria a extensão `btree_gist` na base partilhada). Fica validado na gestão (Fase 4).
- Modelos das tabelas da Nexus Infra (`Cliente`, `Contrato`, `Intervencao`, `Equipamento`, `Local`)
  **recusam gravar e apagar** (trait `TabelaDaNexusInfra`), exceto nos testes e no seeder.
- As migrações `cache`/`jobs`/`users` do esqueleto Laravel foram retiradas: a base partilhada já tem
  `cache` e `jobs` (da Nexus Infra) e as pessoas estão em `utilizadores`. Fila e cache em Redis.
- **`migrate:fresh`/`db:wipe` recusados** fora de `tempos_dev`/`tempos_testing`. O critério de
  aceitação `migrate:fresh --seed` só vale para essas bases; em produção é só `migrate`.
- A migração `garantir_tabelas_partilhadas` cria as tabelas da Nexus Infra e do portal só quando não
  existem (padrão do portal). Em produção não faz nada.

### Registos
- **Registo da timesheet: `inicio` = meia-noite de Lisboa do dia, `fim` = `inicio` + duração.** A
  especificação não dizia o que pôr em `fim`; tem de estar preenchido (senão é um cronómetro a correr).
- **Regra 6 — intervenção sem cliente é recusada.** Na Nexus Infra a intervenção não tem `cliente_id`:
  o cliente é o do local do equipamento, e há equipamentos "por associar" sem local.
- **Regra 6 — com contrato no registo, a intervenção tem de ter ESSE contrato.** Uma intervenção sem
  contrato não serve para um registo com contrato (leitura estrita de "e ao contract_id se preenchido").
- **Regra 7 — intervenção concluída (`estado = concluida`) AVISA, não bloqueia.** A timesheet é
  preenchida ao fim da semana, quando a intervenção normalmente já foi concluída; bloquear impedia o
  uso normal. `GravadorRegistos::avisos()` devolve o aviso para a UI.
- **Toda a escrita passa por `GravadorRegistos`** (substitui os form requests da especificação — a
  Nexus Infra também não os usa; valida nos componentes/serviços).
- Numa alteração, a policy verifica o registo como estava **e** como fica: mudar o dia ou o técnico
  não serve para tirar um registo de uma semana submetida nem para o meter numa.
- A timesheet é **independente** da agenda e das horas das intervenções da Nexus Infra (não sugere
  horas a partir delas).

### Consumo e horas incluídas
- **Períodos civis** (mês, trimestre, ano do calendário) cortados pela validade. Ex.: validade a partir
  de 15/03, mensal → 15/03–31/03, 01/04–30/04… Alinha com o fecho mensal.
- **Período parcial recebe as horas todas** (sem pro-rata). Ex.: 10 h/mês a partir de 15/03 → 10 h
  para 15/03–31/03. A rever se o negócio quiser proporcional.
- **"total"** = a validade inteira; em aberto, vai até à data pedida.
- **O transporte não passa de uma validade para a seguinte** (regra 10): novas condições recomeçam do zero.
- **Arredondamento de faturação é por registo** (cada registo arredondado e depois somado), não sobre
  o total do período. A rever na Fase 5 se o negócio preferir arredondar o total.
- **View materializada:** traz consumo e incluídas por período, mais uma linha por mês para registos de
  contratos fora de qualquer horas-incluídas (incluídas 0). **Não traz o transporte** — é sequencial e
  fica no `CalculadorHorasIncluidas`; a coluna `excedente_sem_transporte_seg` diz isso mesmo. O fuso
  `Europe/Lisbon` está fixo no SQL da view (uma view não recebe parâmetros).
- Refresco: todas as noites às 03h de Lisboa (longe do sync do ERP da Nexus Infra), `CONCURRENTLY`.

### Testes
- Sem factories (a Nexus Infra não as usa): `Model::create` e auxiliares no `Tests\TestCase`.

## 4. Pontos onde o PHC "faria sentido" (não implementados)

- Nenhum até agora.
