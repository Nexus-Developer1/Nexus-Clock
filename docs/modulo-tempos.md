# Nexus Ops — Módulo de Registo de Tempos

Plano de implementação para Claude Code. Ler na totalidade antes de começar. Executar fase a fase; no fim de cada fase correr os testes e parar para revisão antes de avançar.

> **Nota (2026-09-14):** o módulo passou a ser uma aplicação própria da suite (pasta `Nexus-Clock`), ligada ao portal como a Knowledgebase. As adaptações a este documento — nomes de tabelas em português, permissões, base de dados partilhada — estão em [`modulo-tempos-notas.md`](modulo-tempos-notas.md). Onde os dois discordarem, valem as notas.

---

## 0. Contexto e regras

**Stack existente:** Laravel + Livewire + PostgreSQL 16 + Redis (queue worker em systemd) + MinIO. **Este módulo não tem qualquer ligação ao PHC** — não lê nem escreve no schema `phc`, não usa o sync PHC. A faturação sai como export (CSV + PDF por cliente) para tratamento manual.

**Entidades já existentes a reutilizar (não duplicar):** `users` (técnicos), `clients`, `contracts`, `interventions`, `agenda`, `reports`. Combobox search já existe como componente Livewire — reutilizar.

**Objetivo do módulo:** registo de horas por técnico, ligado a cliente/contrato/intervenção, com horas incluídas por contrato, cálculo de excedentes, rates, margem e export de faturação (CSV/PDF).

**Uso principal:** preenchimento em bulk ao fim do dia/semana numa timesheet. Timer em tempo real é opcional e fica para a última fase.

**Fora de scope (não implementar, não sugerir):** scheduling, férias/ausências, kiosk, expenses, auto-tracker, localização, aprovações multi-nível, app mobile nativa.

**Convenções obrigatórias:**
- Toda a UI, labels, mensagens e comentários em Português Europeu (PT-PT). Nunca PT-BR ("utilizador" não "usuário", "ficheiro" não "arquivo", "registar" não "registrar").
- Seguir os padrões já usados no repo (estrutura de componentes Livewire, policies, form requests, nomes de tabelas). Antes de criar qualquer ficheiro, ler 2–3 exemplos equivalentes já existentes e imitar.
- Datas: guardar em UTC, apresentar em `Europe/Lisbon`.
- Durações: guardar sempre em segundos (`duration_sec` integer). Nunca arredondar na gravação; arredondar apenas na apresentação/faturação.
- Soft deletes em `time_entries`, `rates`, `contract_allowances`.
- Migrations sempre reversíveis.
- Não alterar tabelas existentes sem o pedir explicitamente; quando for necessário, propor a alteração e esperar aprovação.
- Qualquer referência ao schema `phc` ou ao sync PHC é erro. Se parecer fazer sentido, registar em `docs/modulo-tempos-notas.md` e seguir.
- Testes: Feature tests para cada regra de negócio listada nas fases. Usar a factory pattern já existente no repo.

---

## 1. Modelo de dados

### 1.1 `time_entries`

| coluna | tipo | notas |
|---|---|---|
| id | bigserial | |
| user_id | fk users | técnico |
| client_id | fk clients | obrigatório |
| contract_id | fk contracts, nullable | |
| intervention_id | fk interventions, nullable | |
| started_at | timestamptz | timesheet: meia-noite local do dia; timer: hora real |
| ended_at | timestamptz, nullable | null = timer a correr |
| duration_sec | integer, nullable | calculado ao terminar; fonte de verdade para relatórios |
| billable | boolean, default true | |
| description | text, nullable | |
| tags | text[] | ex. `deslocação`, `remoto` |
| source | enum `timesheet`/`timer`/`import` | |
| submitted_at | timestamptz, nullable | semana submetida pelo técnico |
| locked_at | timestamptz, nullable | fecho mensal para faturação |
| invoiced_at | timestamptz, nullable | incluída num export de faturação |
| created_by / updated_by | fk users | |
| timestamps + soft deletes | | |

**Índices:** `(user_id, started_at)`, `(contract_id, started_at)`, `(client_id, started_at)`, partial unique `(user_id) WHERE ended_at IS NULL AND deleted_at IS NULL` (máx. 1 timer ativo por user).

**Constraints:** `duration_sec >= 0`; `duration_sec <= 86400`; se `ended_at` não nulo então `duration_sec` não nulo.

### 1.2 `rates`

| coluna | tipo | notas |
|---|---|---|
| id | bigserial | |
| scope_type | string, nullable | `App\Models\Contract`, `App\Models\Client`, `App\Models\User`; null = default global |
| scope_id | bigint, nullable | |
| bill_rate_cents | integer | €/h faturado ao cliente |
| cost_rate_cents | integer, nullable | €/h custo interno (margem) |
| valid_from | date | |
| valid_to | date, nullable | |
| timestamps + soft deletes | | |

**Resolução de rate para uma entry** (ordem, primeiro que existir e esteja válido na data da entry): contrato → cliente → técnico → default global. Implementar em `RateResolver` (service, com cache por request).

### 1.3 `contract_allowances`

| coluna | tipo | notas |
|---|---|---|
| id | bigserial | |
| contract_id | fk contracts | |
| period | enum `monthly`/`quarterly`/`yearly`/`total` | |
| hours_included | numeric(8,2) | |
| rollover | boolean, default false | horas não usadas transitam para o período seguinte |
| overage_billable | boolean, default true | |
| overage_rate_id | fk rates, nullable | se null, resolve pela cadeia normal |
| rounding_minutes | smallint, default 15 | arredondamento para faturação (0 = sem arredondamento) |
| rounding_mode | enum `up`/`nearest`/`down`, default `up` | |
| valid_from | date | |
| valid_to | date, nullable | |
| timestamps + soft deletes | | |

Tabela separada (e não colunas em `contracts`) porque as condições mudam a meio do contrato e é preciso histórico. Só pode haver uma allowance ativa por contrato numa dada data — validar no request.

### 1.4 `timesheet_periods`

Estado da semana por técnico, para o fluxo de submissão.

| coluna | tipo |
|---|---|
| id | bigserial |
| user_id | fk users |
| week_start | date (segunda-feira) |
| status | enum `draft`/`submitted`/`reopened` |
| submitted_at | timestamptz, nullable |
| reopened_by | fk users, nullable |
| timestamps |

Unique `(user_id, week_start)`.

### 1.5 View materializada `contract_period_usage`

Por `(contract_id, period_start, period_end)`: soma de `duration_sec` faturável e não faturável, horas incluídas (da allowance ativa), excedente. Refrescada por job agendado (noturno) e a pedido após lock mensal. Usada pelos relatórios; nunca calcular consumo anual on-the-fly em listagens.

---

## 2. Regras de negócio (implementar como testes)

1. Um técnico só pode criar/editar entries suas; admin pode editar de qualquer um. (Policy)
2. Entry num período com `status = submitted` não é editável pelo técnico; só admin, ou depois de admin reabrir.
3. Entry com `locked_at` não é editável por ninguém excepto admin com permissão explícita `time.unlock`.
4. Entry com `invoiced_at` nunca é editável; só anulável por admin, com registo.
5. Não pode haver duas entries com `ended_at IS NULL` para o mesmo user.
6. `intervention_id`, quando preenchido, tem de pertencer ao `client_id` (e ao `contract_id` se preenchido).
7. Entry em intervenção fechada: bloquear com mensagem clara.
8. Consumo de um contrato num período = soma de `duration_sec` das entries com `billable = true` e `contract_id` = X dentro do período, arredondadas segundo a allowance **apenas na fase de faturação**, não no consumo mostrado ao técnico.
9. Excedente = max(0, consumo − hours_included − rollover_acumulado).
10. Rollover só se `rollover = true`; acumula apenas dentro da validade da allowance.
11. Resolução de rate segue estritamente: contrato → cliente → técnico → default. Rate expirada não é usada.
12. Lock mensal: só admin; bloqueia todas as entries do mês para todos os técnicos; impede submissão de semanas que cruzem o mês fechado.
13. Export de faturação só gera linhas para entries `billable`, `locked_at` não nulo, `invoiced_at` nulo, e apenas o excedente por contrato (ou todas as horas se o contrato não tiver allowance).

---

## 3. Fases

### Fase 1 — Fundações
**Entregáveis:**
- Migrations das 4 tabelas + view materializada.
- Models com relações, casts, scopes (`forUser`, `forContract`, `inPeriod`, `billable`, `unlocked`).
- Factories e seeders de desenvolvimento.
- `TimeEntryPolicy`, permissões `time.view.all`, `time.edit.all`, `time.lock`, `time.unlock`, `time.export`.
- Services: `RateResolver`, `AllowanceCalculator` (consumo, excedente, rollover), `DurationParser` (aceita `1:30`, `1.5`, `90m`, `1h30`).
- Job `RefreshContractPeriodUsage`.
- Testes das regras 1–11.

**Critério de aceitação:** `php artisan migrate:fresh --seed` corre limpo; testes verdes.

### Fase 2 — Timesheet semanal
**Entregáveis:**
- Componente Livewire `Timesheet\WeekGrid`:
  - Navegação por semana (anterior/seguinte/hoje, seletor de data).
  - Linhas = combinação cliente/contrato/intervenção + descrição + faturável + tags. Colunas = seg–dom. Célula = duração editável.
  - Adicionar linha via combobox existente (cliente → contrato → intervenção, em cascata).
  - Linhas com horas na semana anterior aparecem automaticamente vazias na semana seguinte ("linhas persistentes"); removíveis.
  - "Copiar semana anterior" (linhas e horas).
  - Totais por dia e por semana; aviso visual (não bloqueio) se dia > 10h.
  - Gravação célula a célula (wire:model.blur), sem botão "guardar".
  - Botão "Submeter semana" → `timesheet_periods.status = submitted`; grelha fica read-only para o técnico.
- Vista admin: seleção de técnico; ver semanas submetidas/em falta; reabrir semana.
- Lembrete por email à segunda-feira aos técnicos com semana anterior não submetida (job agendado, usar mailer existente).
- Testes: regra 2, parsing de durações, linhas persistentes, cópia de semana.

**Critério de aceitação:** um técnico consegue preencher e submeter uma semana inteira sem sair da página; admin vê estado de todas as semanas.

### Fase 3 — Relatórios
**Entregáveis:**
- `Reports\ContractUsage`: por contrato e período — horas incluídas, consumidas (faturável/não faturável), rollover, excedente, breakdown por técnico e por intervenção. Barra de progresso de consumo.
- `Reports\ClientSummary`: todos os contratos de um cliente, mesmo período.
- `Reports\TechnicianSummary`: horas por técnico, por cliente, faturável vs não, período livre.
- Filtros comuns: período (mês/trimestre/ano/custom), cliente, contrato, técnico, faturável, tags.
- Export CSV e PDF com branding Nexus (reutilizar o gerador de PDF já usado nos `reports`).
- Página de detalhe de contrato e de cliente já existentes: adicionar secção "Tempos" com o resumo do período corrente e link para o relatório.
- Testes: regras 8–10 com cenários de rollover e mudança de allowance a meio do ano.

**Critério de aceitação:** relatório de contrato com 12 meses de dados abre em < 1s (usa a view materializada).

### Fase 4 — Rates e margem
**Entregáveis:**
- CRUD de `rates` (admin): listagem por scope, validade, deteção de sobreposição de datas no mesmo scope.
- CRUD de `contract_allowances` (admin), acessível a partir da página do contrato.
- `Reports\Margin`: por contrato/cliente/técnico — receita (horas faturáveis × bill_rate), custo (todas as horas × cost_rate), margem €, margem %. Contratos com margem negativa destacados.
- Testes: regra 11, sobreposição de validades.

### Fase 5 — Fecho mensal e export de faturação
**Entregáveis:**
- `Billing\MonthClose`: admin escolhe mês → pré-visualização (contratos, horas incluídas, excedentes arredondados segundo a allowance, valor por contrato) → confirmar → `locked_at` em todas as entries do mês → refresh da view.
- Reabertura de mês (permissão `time.unlock`) com registo em log de auditoria.
- Export de faturação: CSV global (cliente, contrato, descrição padrão "Horas excedentes contrato X — mês Y", horas arredondadas, rate, valor) + PDF por cliente com branding Nexus, para lançamento manual no ERP.
- Marcar `invoiced_at` nas entries incluídas no export. Reexportar um mês já exportado exige confirmação explícita.
- Testes: regras 12–13, arredondamento up/nearest/down, contrato sem allowance.

### Fase 6 — Timer (opcional)
- Componente `Timer\Bar` no layout global: start/stop, seleção rápida cliente/contrato/intervenção, faturável.
- Estado persistido no servidor (entry com `ended_at = null`); poll a cada 30s para sincronizar entre separadores/dispositivos.
- Ao parar, a entry aparece na timesheet como célula normal (agregada ao dia).
- Regra 5 testada.

---

## 4. Ordem de execução e checkpoints

1. Ler o repo: `app/Models`, `app/Policies`, 2–3 componentes Livewire de CRUD, o componente de combobox, o gerador de PDF, o mailer e o sistema de permissões. Resumir em 10 linhas o que vais imitar. **Parar para confirmação.**
2. Fase 1. Testes verdes. **Parar.**
3. Fase 2. Demo funcional. **Parar.**
4. Fase 3. **Parar.**
5. Fase 4. **Parar.**
6. Fase 5. **Parar.**
7. Fase 6 só se pedido.

Em cada checkpoint: listar ficheiros criados/alterados, migrations pendentes, e qualquer decisão tomada que não estava neste plano.

---

## 5. Decisões já tomadas (não reabrir)

- Não existe entidade "projeto"; o equivalente é contrato/intervenção.
- Sem ligação ao PHC; faturação por export manual.
- Timesheet é o fluxo primário; timer é secundário.
- Entry de timesheet tem `started_at` à meia-noite local; não se parte entries à meia-noite.
- Deslocação é tag, não coluna.
- Arredondamento só na faturação, configurável por allowance, default 15 min para cima.
- Descrição e faturável ao nível da linha da timesheet (uma por combinação cliente/contrato/intervenção/semana), não por célula.
- Allowances em tabela própria com validade, não em colunas de `contracts`.

## 6. Perguntas a fazer só se bloquear

- Se `interventions` tem estado "fechada" e qual o campo.
- Se já existe log de auditoria genérico no repo para reutilizar no fecho/reabertura.
