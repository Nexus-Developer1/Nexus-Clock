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

## 4. Decisões da Fase 2 — folha de horas semanal (2026-09-14)

### Nomes
| Especificação | Implementação |
|---|---|
| `Timesheet\WeekGrid` | `Timesheet\Folha` (rota `/folha`), UI "Folha de horas" |
| vista admin | `Timesheet\Semanas` (rota `/semanas`) |
| — | serviço `Services\Tempos\FolhaSemanal` (toda a lógica da grelha) |
| lembrete semanal | job `LembrarSemanasPorSubmeter` + notificação `SemanaPorSubmeter` |

### Dados
- **Tabela nova `linhas_semana`** (não estava no plano). Uma linha por técnico × semana × cliente/
  contrato/intervenção, com descrição, faturável e etiquetas. Sem ela não havia onde guardar uma
  linha acrescentada e ainda sem horas (desaparecia ao recarregar) nem uma linha persistente que o
  técnico retirou (voltava a aparecer). As horas continuam só em `registos_tempo`.
- Uma linha da semana vem de: `linhas_semana` (acrescentada/copiada) · registos na semana (ex.:
  cronómetro, admin) · **persistente** (horas na semana anterior, vazia, a menos que retirada).
- Horas registadas aparecem **sempre**, mesmo numa linha retirada.

### Grelha
- **Célula = soma dos registos daquele dia e daquela linha.** Com um registo, escrever altera-o; vazio
  ou `0` apaga-o (soft delete). Com **vários registos** (ex.: cronómetro, Fase 6) a célula fica só de
  leitura — altera-se registo a registo.
- Descrição, faturável e etiquetas são **da linha**: mudar passa para todos os registos da linha na
  semana, e as células novas herdam-nos. Etiquetas escrevem-se separadas por vírgulas.
- **Retirar uma linha só sem horas** na semana (evita apagar horas sem querer).
- Ordem das linhas: alfabética por cliente, depois contrato e intervenção.
- Aviso de **mais de 10 h** no total do dia (fundo amarelo e dica), sem bloquear.
- Um erro numa célula (ex.: "uma hora") fica junto dela, com o texto escrito, até ser corrigido.
- Contrato e intervenção escolhem-se em listas filtradas pelo cliente; a intervenção mostra
  `#id · tipo · equipamento · data` (as intervenções da Nexus Infra não têm título).

### Copiar semana anterior
- Copia **linhas e horas**, dia a dia, com os atributos da linha. **Não escreve por cima** de horas
  que já existam nesta semana; copiar duas vezes não duplica. Linhas vazias acrescentadas na semana
  anterior também vêm; linhas persistentes vazias não.

### Submeter e reabrir
- Submete **o próprio técnico ou um admin**. Não se submete uma semana que ainda não começou; a
  semana corrente pode ser submetida (ex.: sexta à tarde).
- Submeter preenche `submetido_em` nos registos da semana; reabrir limpa-o e **fica na auditoria**
  (`tempo_semana_reaberta`). Regra 12 (fecho mensal impede submissão) fica para a Fase 5.
- Vista **Semanas** (admin): todas as pessoas com acesso aos tempos, últimas 4/8/12/26 semanas.
  **Em falta** = semana já acabada e não submetida; reaberta e não resubmetida mostra "Reaberta"
  (e conta como em falta).

### Lembrete
- Segunda-feira às **09h de Lisboa**, a quem tem papel **`tecnico`** nos tempos e conta ativa, com a
  semana anterior por submeter (inclui reabertas não resubmetidas e semanas sem horas). Admins não
  recebem. Pela fila, com o transporte **Microsoft Graph copiado da Nexus Infra** (`MAIL_MAILER=graph`
  em produção, mesmas credenciais `MS_GRAPH_*`).

### Interface e desenvolvimento
- Layout, sidebar, barra superior, cores e componentes **copiados da Nexus Infra** (mesmos tokens do
  Tailwind). **Tailwind 3 pela CLI**, CSS compilado em `public/css/app.css` e commitado — sem Vite e
  sem Node no servidor. Depois de mexer em vistas: `npm run css`.
- **Entrada de desenvolvimento** `/dev/entrar`: sem o portal local não há como entrar. Só existe com
  `APP_ENV=local` **e** base `tempos_dev`/`tempos_testing`; em produção e nos testes não existe (há
  teste que o garante).

## 5. Decisões da Fase 3 — relatórios (2026-09-14)

### Nomes e acesso
| Especificação | Implementação |
|---|---|
| `Reports\ContractUsage` | `Relatorios\Contrato` (`/relatorios/contratos/{contrato}`) + `Services\Tempos\Relatorios\RelatorioContrato` |
| `Reports\ClientSummary` | `Relatorios\Cliente` (`/relatorios/clientes/{cliente}`) + `RelatorioCliente` |
| `Reports\TechnicianSummary` | `Relatorios\Tecnicos` (`/relatorios/tecnicos`) + `RelatorioTecnicos` |
| filtros comuns | `PeriodoRelatorio` + `FiltrosRelatorio` (query string partilhado por páginas e exportações) |

- Nesta aplicação não há outros "relatórios", por isso as páginas ficaram em `App\Livewire\Relatorios`.
- **Contrato e cliente: só quem vê os tempos de todos** (admin). **Técnico: só "As minhas horas"**
  (o relatório de técnicos, sempre filtrado por ele — também nas exportações, mesmo forçando `?tecnico=`).

### Relatório de contrato
- **Períodos lidos da view materializada**; o transporte é acumulado em PHP desde o início de cada
  validade, com a **mesma função** do cálculo direto (`CalculadorHorasIncluidas::acumular`). Há teste
  que compara os dois num ano com três mudanças de horas incluídas.
- **O detalhe (por técnico, por intervenção) vem dos registos e respeita os filtros**; o consumo das
  horas incluídas é **sempre o do contrato inteiro** (os filtros não o mudam — está escrito na página).
- Aparecem **inteiros** os períodos das horas incluídas que tocam no período do relatório (ex.: num
  relatório de maio de um contrato trimestral aparece abril–junho).
- Horas **fora de quaisquer horas incluídas** aparecem como "Sem horas incluídas", por mês, e **todo o
  faturável conta como excedente** (coerente com a regra 13).
- Totais: transportadas = as que **entram** no primeiro período; disponível = o do último período;
  consumo % = faturável dentro das horas incluídas ÷ (incluídas + transportadas).
- **Frescura:** mostra "atualizado em …" (hora do último refresco da view, guardada em cache pelo job);
  o admin tem **Atualizar agora**, que refresca a view na hora.
- Barra de consumo: verde até 80 %, amarelo até 100 %, vermelho acima.

### Resumo de cliente e horas por técnico
- O resumo de cliente usa **só o período** (os outros filtros ficam escondidos): contratos com horas
  incluídas ou consumo no período, mais uma linha **Sem contrato** (faturável = excedente).
- Horas por técnico: consulta agrupada aos registos (índice técnico + início), com cliente, contrato,
  técnico, faturável, etiqueta e período livre.

### Exportações
- **CSV** para o Excel em português: separador `;`, UTF-8 com BOM, **horas decimais com vírgula**
  (`1,50`). No contrato, uma só tabela com a coluna "Secção" (Período / Técnico / Intervenção).
- **PDF** com o pacote `barryvdh/laravel-dompdf` 3.1 (o mesmo da Nexus Infra), A4 retrato, logótipo
  embebido e os filtros ativos no cabeçalho. Durações sem arredondamento.

### Correção à view da Fase 1
- Nas horas incluídas **sem fim**, a view cortava o fim do período corrente no **dia de hoje** (ex.:
  setembro terminava a 14/09) em vez de ir até ao fim do mês. Corrigido: o fim de um período só é
  cortado pela validade real. **A migração da view foi editada no sítio** porque ainda não foi
  instalada em lado nenhum; localmente é preciso `migrate:fresh`.
- A view gera períodos em aberto só **até à data de hoje da base de dados** (ou o último registo).

### Desempenho (critério de aceitação)
- 40 contratos × 12 meses, **100 800 registos**: relatório de ano de um contrato em **43–149 ms**
  (teste `RelatorioDesempenhoTest`, limite 1 s).

### Ligação a partir da Nexus Infra (opção A, aprovada a 2026-09-14)
- Nas fichas de contrato e de cliente da Nexus Infra há um botão **"Ver tempos"** que abre
  `/relatorios/contratos/{id}` e `/relatorios/clientes/{id}`. Só para admin e **só com `TEMPOS_URL`**
  no `.env` da Nexus Infra (sem ele, nada aparece). Não há resumo embebido: a Nexus Infra não lê as
  tabelas dos tempos. Está na branch `feature/link-tempos` do `nexus-ops` (commits `015f1b9`, `0309156`).

## 6. Decisões da Fase 4 — tarifas, horas incluídas e margem (2026-09-14)

### Nomes e acesso
| Especificação | Implementação |
|---|---|
| CRUD de `rates` | página **Tarifas** (`/tarifas`) + `Services\Tempos\GestorTarifas` |
| CRUD de `contract_allowances` | **Horas incluídas** do contrato (`/contratos/{id}/horas-incluidas`) + `GestorHorasIncluidas` |
| `Reports\Margin` | `Relatorios\Margem` (`/relatorios/margem`) + `RelatorioMargem` |
| — | Gate `tempos-gerir-tarifas` (admin) para tarifas e horas incluídas |

- "Acessível a partir da página do contrato": a página do contrato nesta aplicação é o relatório de
  consumo — tem o botão **Horas incluídas e tarifas**. A partir daí cria-se uma tarifa já com o
  contrato escolhido.

### Tarifas
- Valores escritos como em Portugal (`45,50`, `1.234,56 €`), guardados em cêntimos.
- **Sobreposição no mesmo âmbito recusada** (incluindo validades em aberto); validades seguidas
  (acaba a 30/06, a seguinte começa a 01/07) aceites; tarifas apagadas não contam.
- Criar, alterar e apagar ficam na **auditoria** com o antes e o depois (pesam na faturação e na margem).
- Listagem por omissão mostra **em vigor e futuras**; "todas" inclui as expiradas.
- ⚠️ Alterar o preço de uma tarifa muda a margem e a faturação **de trás** (as horas usam a tarifa do
  seu dia). Para mudar um preço a partir de uma data, **termina-se a tarifa e cria-se outra**. A Fase 5
  (fecho mensal) deve impedir mexer em tarifas que cubram meses fechados.
- O resolvedor passou a ler as tarifas **uma vez por pedido** e a resolver em memória (a margem resolve
  milhares de combinações sem consultas); esquece-as quando uma tarifa é gravada no mesmo pedido.

### Horas incluídas
- **Uma só ativa por contrato em cada data** (validades sobrepostas recusadas) — a validação que a
  especificação pedia "no request".
- Aviso (não bloqueio) quando começam antes do início do contrato ou vão além do fim.
- Arredondamento de faturação só nos valores 0, 5, 6, 10, 15, 30 ou 60 minutos.
- A tarifa do excedente escolhe-se entre as do contrato, do cliente e as globais; vazia = a normal.
- Gravar ou apagar **refresca a view de consumo** (os relatórios ficam certos logo) e fica na auditoria.

### Margem
- **Receita = horas faturáveis × preço/hora; custo = todas as horas × custo/hora**, com a tarifa do dia
  de cada registo e a cadeia da regra 11.
- **O custo vem da primeira tarifa da cadeia que tenha custo preenchido** (ex.: o contrato vende a 60 €
  sem custo; o custo vem da tarifa do técnico ou da global). A especificação não dizia de onde vinha
  o custo quando a tarifa de venda não o tem.
- As horas incluídas **não mudam a receita**: todas as horas faturáveis valem o preço da tarifa (como a
  especificação diz). A avença do contrato (`contratos.valor`) não entra.
- Horas **sem tarifa de venda** ou **sem custo** não entram nos valores e aparecem à parte, para não
  parecerem margem.
- Por contrato, cliente ou técnico; **pior margem primeiro**; linhas negativas a vermelho e contagem no
  resumo. Filtros: período, técnico, etiqueta (o de faturável não se aplica — o custo conta todas).
- CSV e PDF como os outros relatórios.

## 7. Decisões da Fase 5 — fecho mensal e exportação de faturação (2026-09-14)

### Nomes e dados
| Especificação | Implementação |
|---|---|
| `Billing\MonthClose` | página **Faturação** (`/faturacao`) + `Services\Tempos\Faturacao\{FechoMensal, CalculadorFaturacao, ExportadorFaturacao, MesesFechados}` |
| `locked_at` · `invoiced_at` | `fechado_em` · `faturado_em` (já existiam) |
| — | **tabela nova `meses_tempo`** (estado aberto/fechado, quem e quando fechou/reabriu) |
| — | **tabela nova `exportacoes_faturacao`** (fotografia das linhas exportadas, anulação) + coluna `registos_tempo.exportacao_faturacao_id` |

- A especificação não dizia onde guardar o estado do mês nem o que foi exportado. A **fotografia** garante
  que o CSV e os PDF de um mês exportado são sempre os mesmos, mesmo que tarifas ou horas incluídas mudem.

### Fecho (regra 12)
- Só **meses acabados**; **sem buracos** (não se fecha agosto com horas de julho por fechar; não se reabre
  julho com agosto fechado); **cronómetros a correr** com início no mês impedem o fecho.
- O bloqueio vale pela **data**: criar ou mudar um registo para um dia de um mês fechado exige permissão
  de reabrir, e esse registo **nasce fechado** (entra na faturação). Na folha, os dias de meses fechados
  ficam desativados para quem não pode reabrir.
- **Reabrir:** permissão explícita (`tempos-reabrir`) e **motivo**; na auditoria. Os registos já faturados
  continuam fechados (regra 4).
- As **condições dos meses fechados ficam bloqueadas**: tarifas e horas incluídas que valham nesses dias
  não se criam, não mudam de preço/datas nesses dias e não se apagam. Termina-se no fim do último mês
  fechado e cria-se outra a partir do mês seguinte (a mensagem diz isso).

### O que se fatura (regra 13)
- Só registos **faturáveis, fechados e por faturar**.
- **Com horas incluídas: só o excedente, faturado à medida** — em cada mês fatura-se o excedente acumulado
  no período até ao fim do mês, menos o que já foi faturado desse período em exportações anteriores.
  Assim um trimestre ou um ano faturam o excedente no mês em que acontece, sem faturar duas vezes; e um
  mês fechado mas não exportado é apanhado no seguinte.
- Na faturação, **consumo e transporte usam as durações arredondadas** (arredondamento por registo,
  segundo as horas incluídas) — os relatórios mostram sem arredondar, por isso podem diferir.
- **Sem horas incluídas nesses dias: todas as horas**, arredondadas a **15 min para cima** (o valor por
  omissão da especificação), **uma linha por preço** (tarifa de cada registo, com técnico).
- **Sem contrato:** uma linha por cliente e preço, "Horas sem contrato — mês".
- Preço do excedente: a **tarifa do excedente** escolhida nas horas incluídas (usada tal como escolhida),
  senão contrato → cliente → global no último dia do período faturado (sem técnico: é um valor do contrato).
- **Excedente não faturável** aparece na pré-visualização mas não se exporta.
- **Sem tarifa** numa linha → a exportação é recusada com a indicação da linha.
- Marcam-se como faturados os registos das linhas exportadas; registos de contratos sem excedente ficam só
  fechados.
- Descrições: "Horas excedentes contrato X — agosto de 2026", "Horas contrato X — …", "Horas sem contrato — …".

### Exportação
- Só com o mês **fechado**. **Voltar a exportar** exige confirmação explícita (caixa "Confirmo…"): a
  exportação anterior é **anulada** (fica no histórico, com CSV), os registos voltam a "por faturar" e o
  cálculo é refeito. Tudo na auditoria.
- **CSV global** (Cliente, NIF, Nº cliente ERP, Contrato, Descrição, Horas, Preço/hora, Valor) e **PDF por
  cliente** com o logótipo, sempre a partir da fotografia.
- A página abre por omissão no **mês anterior**. Num mês aberto, a pré-visualização inclui o que ainda não
  está fechado (avisa que pode mudar).

### Correção na folha de horas
- A mensagem "Não pode alterar esta folha de horas" numa célula ficava presa depois de gravar outra célula.
  Agora só fica "por corrigir" o texto que ainda difere do valor gravado.

### Continua por confirmar (usado por omissão)
- **Arredondamento por registo** (e não sobre o total do mês).
- **Período parcial com as horas todas** (sem proporcional).

## 8. Página inicial (2026-09-14, a pedido — não estava na especificação)

- Inspirada num mockup de dashboard (cartões grandes, números em destaque, cartão de cor forte,
  gráfico de linhas, barras e anel de progresso), **com o ADN Nexus**: verde da marca em vez de laranja,
  Poppins, fundo claro e o cartão do gráfico no verde-escuro da sidebar.
- **Técnico:** a minha semana (horas, % faturável, dias úteis com horas), hoje (por cliente), cartão de
  "semana anterior por submeter", gráfico de horas por dia contra a semana anterior (todas ou só
  faturáveis), o meu mês por cliente, as minhas últimas semanas e o anel de faturável do mês.
- **Quem gere:** por omissão vê a **equipa** (alterna para "Eu"); em vez do mês pessoal, vê as horas
  incluídas a esgotar (período corrente, pela view materializada), a equipa na semana anterior (em falta
  primeiro) e o fecho do mês anterior (percentagem de semanas submetidas e atalho para a faturação).
- A **variação** compara com os **mesmos dias** da semana anterior até hoje (à quarta, seg–qua contra
  seg–qua) — comparar com a semana anterior inteira dava −70 % todas as segundas.
- Gráfico em SVG gerado no servidor (sem biblioteca), curva monótona (não inventa picos nem desce
  abaixo de zero), cores **validadas para daltonismo e contraste** sobre o fundo escuro (verde
  `#16A34A` esta semana, violeta `#8B5CF6` semana anterior), cruz de leitura com tooltip por rato e
  teclado, legenda e tabela para leitores de ecrã.
- `/` passa a ser a página inicial (antes redirecionava para a folha de horas).

## 9. Pontos onde o PHC "faria sentido" (não implementados)

- Nenhum até agora.

## 10. Aprovação das semanas (2026-09-14, a pedido — ideia tirada do Clockify)

- **Um só nível** (a especificação exclui aprovações multi-nível, não a aprovação). Estados novos em
  `semanas_tempo`: `aprovada` e `rejeitada`, com `aprovada_em/por`, `rejeitada_em/por` e
  `motivo_rejeicao`. Migração própria e reversível (a tabela é deste módulo).
- **Aprovar** (quem gere, `tempos-editar-todos`): só uma semana submetida. **Aprovada fica fechada a
  todos, admin incluído** — para corrigir, reabre-se (auditoria `tempo_semana_reaberta` com
  `estava_aprovada`). Assim a aprovação quer dizer alguma coisa.
- **Rejeitar**: só uma semana submetida, **motivo obrigatório**. Volta a ser editável pelo técnico,
  limpa `submetido_em` dos registos, conta como **em falta** (e recebe o lembrete de segunda) e o
  técnico recebe **email com o motivo** (pela fila). Voltar a submeter limpa a rejeição; o histórico
  fica na auditoria (`tempo_semana_aprovada`, `tempo_semana_rejeitada`).
- "Entregue" = submetida ou aprovada: é o que conta para o lembrete, a vista Semanas e a página inicial.
- **Não se submete com um cronómetro a correr** nessa semana (as horas ainda não estão todas).
- Vista **Semanas**: "Por aprovar" como indicador e filtro, aprovar/rejeitar/reabrir em cada célula e
  **Aprovar todas** (as submetidas à vista). Na folha, o admin aprova ou rejeita a semana aberta.
- O **fecho do mês avisa** (não bloqueia) quando há semanas desse mês por aprovar. Fechar sem aprovar
  continua possível — a aprovação é controlo interno, não condição de faturação.

## 11. Fase 6 — cronómetro (2026-09-14, a pedido)

| Especificação | Implementação |
|---|---|
| `Timer\Bar` no layout global | `Cronometro\Barra` (no topo de todas as páginas, só quando corre) |
| — | serviço `Services\Tempos\Cronometro` (iniciar, continuar, parar, descartar) |
| — | página **Registos** (`/registos`, `Registos\Listagem`) com a barra completa de nova entrada |

- **Estado no servidor**: registo com `inicio` à hora real e `fim` nulo (regra 5 garantida pela base de
  dados e validada no `GravadorRegistos`). A barra sincroniza a cada **30 s** e logo que o cronómetro
  muda noutro componente (evento `cronometro-alterado`); os segundos contam no navegador, corrigidos
  pela diferença de relógio para o servidor.
- **Iniciar com um a correr para o anterior** (troca de tarefa, como no Clockify) em vez de dar erro.
- **Menos de 1 minuto ao parar = descartado** (arranque sem querer). **Mais de 24 h não para sozinho**:
  corrige-se a hora nos Registos ou descarta-se.
- O registo fica no **dia em que começou**, mesmo passando a meia-noite (decisão da especificação:
  não se partem registos). Na folha soma-se à célula desse dia; com mais de um registo a célula fica
  só de leitura (já decidido na Fase 2). A célula onde o cronómetro corre tem um ponto verde.
- **Seleção rápida**: na folha, cada linha tem ▶ (só na própria folha, na semana de hoje); nos
  Registos, os **6 trabalhos recentes** diferentes arrancam com um clique ou preenchem a barra.
- **Não se submete uma semana com o cronómetro a correr** (§10).
- O cronómetro é sempre de quem o inicia; quem gere não arranca cronómetros de outros.

## 12. Registos e edição em massa (2026-09-14, a pedido — ideias tiradas do Clockify)

- Página **Registos**: barra de nova entrada (cronómetro ou **à mão**, com início e fim ou só a
  duração), recentes, filtros (hoje, esta semana, semana passada, este mês, datas, técnico, pesquisa)
  e os registos **por dia**, com **continuar**, **duplicar**, **alterar** e **apagar**. Quem gere
  escolhe o técnico ou "todos"; as horas à mão vão para o técnico escolhido. Máximo de 300 registos
  por página (aviso para encurtar o período).
- Entrada à mão com **fim antes do início** (ex.: 22:00–01:30) acaba no dia seguinte, e fica no dia em
  que começou. Só com duração, grava-se como na folha (meia-noite do dia).
- O cadeado de cada registo (faturado, mês fechado, semana submetida/aprovada) é só indicação; quem
  decide é sempre a `RegistoTempoPolicy`.
- **Edição em massa** (`Services\Tempos\EdicaoEmMassa`): faturável, descrição, acrescentar/retirar
  etiquetas e contrato/intervenção (só com registos todos do mesmo cliente), ou apagar. **Tudo ou
  nada**: um registo que não possa ser alterado (ou que falhe a regra 6) desfaz tudo e a mensagem diz
  qual. Máximo de 500 de cada vez. Auditoria `tempo_registos_editados_em_massa` /
  `tempo_registos_apagados_em_massa`.
- A descrição, o faturável e as etiquetas continuam a ser "da linha" na folha; mudar em massa só
  alguns registos de uma linha deixa-os diferentes dentro da mesma semana (a folha mostra os do
  primeiro registo). É o preço de corrigir registo a registo.

## 13. Alertas de horas (2026-09-14, a pedido — ideia tirada do Clockify)

- `Services\Tempos\AlertasHoras`: numa semana, por técnico, **horas abaixo do mínimo semanal**
  (`TEMPOS_HORAS_SEMANA_MINIMAS`, por omissão **35 h**; 0 desliga), **dias acima de 10 h** (o mesmo
  limite do aviso da folha), semanas **por entregar** e **por aprovar**. Técnicos e, como na vista
  Semanas, administradores só se registaram horas.
- **Resumo por email a quem gere** (papel `admin`), segunda às **09h15** de Lisboa (depois do lembrete
  aos técnicos das 09h): quatro números, quem tem alertas e porquê, botão para aprovar. **Só sai se
  houver alguma coisa a assinalar.** Os números calculam-se quando o email sai da fila.
- Na **vista Semanas**, as semanas acabadas abaixo do mínimo têm um ⚠ junto das horas.
- Só avisa, nunca bloqueia: **férias e ausências não estão nos tempos** (fora de âmbito), por isso uma
  semana curta pode ter explicação. O mínimo de 35 h é um ponto de partida — ajustar no `.env`.

## 14. Resumo livre (2026-09-14, a pedido — ideia tirada do "Summary report" do Clockify)

- Novo separador **Resumo livre** (`/relatorios/resumo`, `Relatorios\Resumo`, serviço
  `Relatorios\RelatorioResumo`): agrupar por **cliente, contrato, técnico, intervenção, etiqueta, dia,
  semana ou mês** e, opcionalmente, **depois por** outra dimensão. Faturável e não faturável por grupo,
  % do total (e % do grupo nos subgrupos), gráfico de barras e CSV/PDF com os mesmos filtros.
- **Técnicos também o veem**, só com as suas horas (os separadores deles são "As minhas horas" e
  "Resumo"); a exportação impõe o mesmo. `/relatorios` continua a abrir onde abria.
- **Por etiqueta, um registo com várias etiquetas conta em cada uma** (e sem etiquetas conta em "Sem
  etiqueta"); os totais vêm sempre dos registos, não da soma dos grupos — a página e o PDF dizem-no.
- Dimensões de **tempo** por ordem cronológica e **com os dias/semanas/meses sem horas a zero** (o
  gráfico não salta); as outras do maior para o menor, com "Sem contrato/intervenção/etiqueta" no fim.
  O gráfico por categoria mostra os 10 maiores (os restantes estão na tabela).
- Gráfico em HTML (sem biblioteca): verde = faturável, cinzento = não faturável, legenda sempre à
  vista, leitura dos valores ao passar o rato ou com o foco do teclado, e a tabela por baixo como
  alternativa acessível.

## 15. Páginas retiradas (2026-09-14, a pedido)

O responsável técnico pediu para **apagar as 7 páginas** (Início, Folha de horas, Registos, Relatórios,
Semanas, Tarifas, Faturação): o que é preciso afinal é algo muito mais simples. O estado completo
ficou na tag **`tempos-completo`** (recuperável com `git checkout tempos-completo -- <caminho>`).

- **Saiu:** todos os componentes Livewire e vistas das páginas (incluindo Horas incluídas de um
  contrato e a barra do cronómetro), as exportações CSV/PDF e os PDF, os emails e as notificações
  (lembrete de segunda, semana rejeitada, resumo a quem gere) e os respetivos jobs agendados, o
  serviço da página inicial e os componentes visuais só dessas páginas. Os testes das páginas também.
- **Ficou:** a ligação à suite (sessão do portal, acesso, layout, sidebar só com Início e uma página
  inicial vazia), os componentes visuais genéricos (`x-cabecalho-pagina`, `x-kpi`, `x-icone`,
  `x-avatar`, `x-estado-vazio`, `x-toast-sucesso`), a base de dados e as migrações, os modelos, a
  policy e os **serviços com as regras de negócio** (GravadorRegistos, FolhaSemanal, Cronometro,
  EdicaoEmMassa, tarifas, horas incluídas, relatórios, faturação, alertas), com os seus testes, e o
  refresco noturno do consumo dos contratos.
- Rejeitar uma semana já não envia email (a página para onde apontava deixou de existir).
- As secções §4–§14 descrevem páginas que já não existem; mantêm-se como histórico das decisões.
- Na Nexus Infra, o botão "Ver tempos" (branch `feature/link-tempos`, não integrada) aponta para o
  relatório de contrato, que deixou de existir — não integrar essa branch como está.

## 16. Menu com 5 páginas (2026-09-15, a pedido — como a sidebar do Clockify)

- Menu: **Painel** (`/`), **Relatórios** com submenu (**Resumo**, **Detalhado**, **Semanal** —
  os relatórios base do Clockify; a confirmar), **Projetos**, **Equipa** e **Clientes**. Nomes em
  PT-PT e aspeto Nexus (não em maiúsculas como no Clockify).
- As páginas estão **por fazer**: uma só vista (`resources/views/pagina.blade.php`) com cabeçalho e
  estado vazio, servida por `Route::view`. Todas visíveis a quem tem acesso aos tempos.
- **A decidir antes de fazer "Projetos":** a especificação diz que "não existe entidade projeto; o
  equivalente é contrato/intervenção" (§5 de `modulo-tempos.md`). Ou os projetos são os contratos (e
  intervenções) da Nexus Infra, ou são uma tabela nova deste módulo.

## 17. Página Clientes (2026-09-15, a pedido — como a do Clockify)

- **Lista própria dos Tempos** (escolha do responsável técnico): tabela nova `clientes_tempos`, modelo
  `ClienteTempo`, serviço `GestorClientes`. **Não** são os clientes da Nexus Infra (`clientes`, que vêm
  do ERP e aqui são só de leitura) nem têm ligação a eles; os registos de horas continuam a apontar
  para os da Nexus Infra. Se um dia os registos passarem a usar estes clientes, é uma decisão à parte.
- Campos: **nome** (obrigatório, único sem distinguir maiúsculas, espaços normalizados), **email**,
  **emails em cópia** (até 3, `text[]`), **morada**, **nota** e **moeda** (EUR por omissão; lista EUR,
  USD, GBP, CHF, BRL, AOA). A lista mostra Nome, Morada e Moeda, como na imagem.
- Acrescenta-se só pelo nome (como no Clockify); o resto altera-se no formulário (lápis).
- **Arquivar** tira da lista de ativos; **apagar só arquivados** (soft delete, o nome fica livre).
  Ações num a um (menu ⋮) ou em vários (caixas de seleção), tudo ou nada. Auditoria
  `tempo_cliente_criado/alterado/arquivado/restaurado/apagado`.
- Só **admin** gere (`tempos-gerir-clientes`); o técnico vê a lista sem ações.

## 18. Página Equipa (2026-09-15, a pedido — como a do Clockify)

Separadores **Membros**, **Limitados**, **Grupos** e **Lembretes** (`/equipa`, `/equipa/limitados`,
`/equipa/grupos`, `/equipa/lembretes`). Decisões do responsável técnico:

- **Membros plenos = pessoas com acesso aos Tempos no portal.** "Acrescentar membro" abre o portal;
  aqui não se criam contas nem se dá acesso. Cada pessoa com acesso ganha uma linha em
  `membros_equipa` na primeira vez que a página abre (papel inicial: Administrador se é admin no
  portal, senão Membro). Quem perde o acesso deixa de aparecer (a linha fica).
- **Limitados**: pessoas sem conta (nome e email opcional), criadas, alteradas e apagadas aqui. Por
  agora não registam horas (os registos ligam-se a contas da suite) e não recebem lembretes.
- **Papéis ao estilo do Clockify**, guardados aqui: Proprietário (só um; passar a propriedade faz
  do anterior administrador; limitados não podem), Administrador, Gestor de projeto, Gestor de
  equipa, Membro. **Por agora só informativos: as permissões continuam a vir do papel no portal.**
- **Taxa faturável e taxa de custo por membro, com histórico** (`taxas_membros`): "Mudar" grava um
  valor a partir de uma data (hoje por omissão); valor vazio = sem taxa a partir dessa data. São
  independentes das `tarifas` antigas (por técnico/cliente/contrato), que ficaram sem página.
- **Grupos** (`grupos_equipa`, nome único): criar, renomear, escolher membros (plenos e limitados),
  apagar; "+ Grupo" em cada membro e "Pôr num grupo" para vários.
- **Lembretes** (`lembretes_equipa`): "menos de X h no dia anterior / na semana anterior", nos dias da
  semana e à hora escolhidos (Lisboa, hora certa), para todos os membros plenos ou só alguns grupos.
  Job `EnviarLembretesEquipa` de hora a hora; cada lembrete sai no máximo uma vez por dia. O email
  leva à página inicial (ainda não há página para registar horas).
- Só admin gere (`tempos-gerir-equipa`). O técnico vê Membros, Limitados e Grupos **sem taxas e sem
  ações**; Lembretes é só para admin. Exportação CSV dos membros. Tudo na auditoria.

### Campos de trabalho e menu Filtros (2026-09-15, a pedido — como no Clockify)

- O botão **Filtros** abre a lista de campos: Taxa faturável, Taxa de custo, Papel e Grupo (por
  omissão) e ainda **Início da semana**, **Dias de trabalho**, **Capacidade diária** e **Gestor de
  equipa atribuído**. Cada campo ligado aparece **como filtro e como coluna**; desligar limpa o filtro.
  A escolha fica na sessão de quem está a ver ("Repor os campos por omissão" volta ao início). Os
  técnicos não veem as taxas no menu.
- Novas colunas em `membros_equipa` (migração própria): `inicio_semana` (1 = segunda, por omissão),
  `dias_trabalho` (segunda a sexta por omissão, pelo menos um), `capacidade_diaria_seg` (duração
  escrita à mão: 8, 7:30…; vazio = sem) e `gestor_id`. O **gestor atribuído tem de ter o papel Gestor
  de equipa** e não pode ser o próprio; quem deixa esse papel perde as atribuições.
- Alteram-se diretamente na tabela (só admin), com auditoria `tempo_membro_campo`. Por agora são só
  informação — ainda não mudam lembretes nem cálculos.
- Correção: o texto "Ações" só para leitores de ecrã escapava ao corte das tabelas largas e fazia a
  página inteira deslizar para o lado; os contentores das tabelas passam a `relative`.

### Menu alargado (2026-09-15, a pedido — como o do Clockify)

- **Relatórios** passa a ter secções: **Tempo** (Resumo, Detalhado, Semanal, Partilhados),
  **Equipa** (Presenças, Tarefas) e **Despesas** (Detalhado). Nova secção **Gerir** com Quiosques,
  Aprovações, Projetos, Equipa, Clientes e Etiquetas. As páginas novas estão por fazer (cabeçalho e
  estado vazio); a lista do menu desliza quando não cabe.
- **A confirmar antes de fazer:** a especificação original deixava de fora quiosques e despesas; a
  Nexus Infra já tem despesas (com aprovação). Antes de construir Quiosques, Aprovações de despesas
  ou o relatório de Despesas, decidir se ficam aqui ou se se ligam ao que já existe na Nexus Infra.
- **2026-09-15, a pedido:** retirados do menu o título Gerir, Quiosques, Aprovações e Etiquetas (e as
  páginas vazias). Ficam Projetos, Equipa e Clientes.

## 19. Página Painel (2026-09-15, a pedido — como o Dashboard do Clockify)

- `/` passa a ser o Livewire `App\Livewire\Painel\Pagina`; os dados vêm de `App\Services\Tempos\PainelTempos::gerar()`.
- ~~Projeto = contrato~~ — desde 2026-09-17 (§20) o Painel agrupa pelo projeto dos Tempos; o contrato ficou como agrupamento à parte. **Cliente = cliente da Nexus Infra** (o dos registos), não a lista própria de `clientes_tempos`.
- Só registos terminados; o dia é a data local de `inicio`. Agrupar por etiqueta conta o registo em cada etiqueta (as percentagens somam sobre esse total); registos sem grupo aparecem como "Sem projeto/cliente/etiqueta".
- Gráfico: os 5 grupos com mais horas têm cor fixa (#16a34a, #2a78d6, #eb6834, #7c3aed, #eda100, paleta validada para daltonismo) e o resto junta-se em "Outros" (cinzento). O amarelo tem pouco contraste: por isso há legenda e lista com nomes.
- "Equipa" só para quem passa `tempos-ver-todos`; um técnico que ponha `?quem=equipa` no URL fica em "Só eu". Período no URL (`periodo=semana|mes`, `de=AAAA-MM-DD`), alinhado à segunda-feira ou ao dia 1.
- 2026-09-17: comparação com o período anterior (a mesma semana/mês antes, para as mesmas pessoas), faturável com a regra dos relatórios (registo faturável e projeto faturável ou sem projeto), agrupar por membro só na Equipa, quadro "Atividade da equipa" (`PainelTempos::equipa`: cronómetro a correr = registo sem fim; último registo = o de `inicio` mais recente já terminado) e ligações para o Detalhado (`Pagina::ligacao`: leva o período e, em "Só eu" para quem gere, o filtro do próprio; o contrato não tem ligação porque o Detalhado não filtra por contrato).

## 20. Página Projetos (2026-09-17, a pedido — como a do Clockify)

- Decisões do utilizador: **lista própria** (não os contratos da Nexus Infra) e **autorização para acrescentar `registos_tempo.projeto_id`** (opcional, `ON DELETE SET NULL`).
- Tabelas: `projetos_tempos` (nome único por cliente, sem distinguir maiúsculas; cliente de `clientes_tempos`; cor de uma lista fixa; público; faturável; `taxa_cent`; `estimativa_seg`; nota; arquivado; soft delete), `projeto_membro` (membros de projetos privados, de `membros_equipa`) e `projeto_favoritos` (por pessoa).
- Escrita em `GestorProjetos` (gate `tempos-gerir-projetos`, só admin), com auditoria (`tempo_projeto_*`). Favoritos: qualquer pessoa, nos projetos que vê (sem auditoria).
- Privado: veem-no os administradores e os membros escolhidos (`ProjetoTempo::visiveisPara`). Ainda **não** impede um técnico de registar num projeto privado de que não é membro — não há ainda página de registo; fica para quando houver.
- Horas e valor (`HorasProjetos`): registos terminados e não anulados, de sempre. Valor só de registos faturáveis em projetos faturáveis, à taxa do projeto ou, sem ela, à taxa faturável do membro em vigor no dia do registo (sem taxa = 0). Sem arredondamento. Os técnicos não veem valores.
- Progresso = registado ÷ estimativa; acima de 100 % fica a vermelho.
- `GravadorRegistos` aceita `projeto_id`: tem de existir e, ao ser escolhido, não estar arquivado (registos antigos de um projeto arquivado continuam editáveis).
- Moeda: os valores mostram-se em euros (as taxas são em €); a moeda do cliente ainda não entra nas contas.

## 21. Relatório Resumo (2026-09-17, a pedido — como o "Summary report" do Clockify)

- `/relatorios/resumo` é o Livewire `App\Livewire\Relatorios\Resumo`; os dados vêm de `App\Services\Tempos\ResumoTempos` (não confundir com `Services\Tempos\Relatorios\RelatorioResumo`, o resumo livre de §14, que ficou sem página). Os outros separadores (Detalhado, Semanal, Partilhados) continuam por fazer.
- Só registos terminados e não anulados. **Faturável** = registo faturável e (sem projeto ou projeto faturável). **Valor** = faturável × (taxa do projeto ou taxa faturável do membro no dia); **custo** = todas as horas × taxa de custo do membro no dia; **lucro** = valor − custo. Sem arredondamento (é só na faturação).
- Quem não passa `tempos-ver-todos` vê só as suas horas (o filtro Equipa é ignorado) e não vê valores.
- Agrupar por etiqueta conta o registo em cada etiqueta (e em "Sem etiqueta" se não tiver nenhuma); os totais e o gráfico por faturabilidade não duplicam.
- Períodos com mais de 62 dias mostram o gráfico por mês. Datas à escolha: no máximo um ano.
- Cliente = cliente da Nexus Infra dos registos (como no Painel). O filtro só lista clientes que já têm registos.
- Não fizemos: arredondamento (regra da suite), criar fatura, partilhar, tarefas e quiosques (não existem).

## 22. Relatório Detalhado (2026-09-17, a pedido — como o "Detailed report" do Clockify)

- `/relatorios/detalhado` é o Livewire `App\Livewire\Relatorios\Detalhado`; linhas de `ResumoTempos::registos()` (ids por ordem, com faturável, valor e custo) e totais de `ResumoTempos::totais()`. Período e filtros partilhados com o Resumo no trait `Relatorios\Concerns\PeriodoEFiltros` e nos partials `relatorios/_topo` e `relatorios/_filtros`.
- Toda a escrita passa pelo `GravadorRegistos` (alterar, acrescentar, duplicar, apagar, anular) e pela `EdicaoEmMassa` (que passou a aceitar `projeto`: id, ou 0 para tirar). As regras de sempre aplicam-se: semana entregue, mês fechado e faturado bloqueiam; um faturado só se anula (admin, com motivo).
- Acrescentar tempo: para si; quem passa `tempos-editar-todos` escolhe o membro ("Add time for others"). Com início e fim, o registo fica com horas reais (fim antes do início = dia seguinte); só com duração, fica à meia-noite do dia, como na folha de horas.
- Auditoria de tempo: sem projeto, sem descrição, sem etiquetas e com mais de 8 h (`ResumoTempos::LONGO_SEG`). Não há ainda "sobrepostos".
- O combobox de cliente (`livewire/partials/combobox-cliente` e o trait `PesquisaClientes`) voltou da tag `tempos-completo`.
- Não fizemos: arredondamento, criar fatura, partilhar, tarefas e quiosques.

## 23. Relatório Semanal (2026-09-17, a pedido — como o "Weekly report" do Clockify)

- `/relatorios/semanal` é o Livewire `App\Livewire\Relatorios\Semanal`; a grelha vem de `ResumoTempos::grelha()`. Período e filtros em `PeriodoEFiltros`, como no Resumo e no Detalhado.
- Colunas: um dia por coluna até 7 dias; por semana (segunda a domingo, cortada nas pontas do período) até 93 dias; por mês acima disso.
- Valor = o mesmo do Resumo (faturável, à taxa do projeto ou do membro no dia). Só quem passa `tempos-ver-todos` escolhe "Mostrar valor"; os outros veem só tempo.
- Por etiqueta, o registo conta em cada etiqueta; os totais por coluna vêm de uma consulta sem etiquetas e não duplicam.
- A cor das células é um só tom (verde) do claro ao escuro, pela proporção do maior valor das linhas de 1.º nível, com legenda "menos → mais"; o número está sempre escrito.
- Cada célula liga ao Detalhado com as datas da coluna, os filtros ativos e o grupo/subgrupo da linha (contrato não, por o Detalhado não filtrar por contrato).
- Não fizemos: arredondamento, criar fatura, partilhar, tarefas e quiosques.

## 24. Relatório Presenças (2026-09-17, a pedido — como o "Attendance report" do Clockify)

- `/relatorios/presencas` é o Livewire `App\Livewire\Relatorios\Presencas`; as linhas vêm de `App\Services\Tempos\Presencas` (calculadas em PHP: pessoas × dias do período).
- Pessoas: quem tem acesso aos Tempos (os membros limitados não têm registos). Quem não passa `tempos-ver-todos` vê só a sua linha.
- **Capacidade** = `membros_equipa.capacidade_diaria_seg` nos `dias_trabalho` da pessoa; sem capacidade definida usa `tempos.capacidade_diaria_horas` (8 h, como o Clockify). Fora dos dias de trabalho a capacidade é 0 e todo o trabalho conta como extra.
- **Entrada/saída** = primeiro início e último fim dos registos com horas reais (cronómetro ou início/fim indicados); os registos só com duração (folha de horas) contam no trabalho mas não têm horas. **Pausas** = (saída − entrada) − duração desses registos.
- **Ausências ("Time off")**: não existem nos Tempos; ficou de fora. Os filtros por intervalo de cada coluna do Clockify ficaram resumidos no filtro "Situação".
- Separadores de Equipa: Presenças e Tarefas (Tarefas continua página vazia; não há tarefas nos Tempos). O partial `relatorios/_topo` aceita `$separadores`.

## 25. Relatórios partilhados (2026-09-17, a pedido — como o "Share report" do Clockify)

- Só o **Resumo** se partilha por agora (`RelatorioPartilhado::TIPOS`). A tabela nova `relatorios_partilhados` guarda o link (`token`, 40 caracteres aleatórios), o nome, a visibilidade, "sempre atual", "bloquear datas", os parâmetros do relatório (período, filtros, agrupamentos, cores, valor, ordem) e o agendamento por email.
- Escrita em `GestorPartilhados` (qualquer pessoa com acesso cria; alterar, novo link e apagar = quem criou ou quem passa `tempos-gerir-equipa`), com auditoria (`tempo_relatorio_partilhado*`).
- `/partilhado/{token}` (`Relatorios\Partilhado`, subclasse do `Resumo`, layout `layouts.publico`, `throttle:60,1`): **calculado com as permissões de quem partilhou** — um técnico só partilha as suas horas; quem vê a equipa partilha a equipa com valores. Quem abre não muda filtros nem o valor mostrado (custo/lucro ficam de fora); só muda o período se não estiver bloqueado. Público = sem sessão; privado = sessão da suite com acesso aos Tempos (sem sessão vai ao portal). Se quem partilhou perder o acesso ou for desativado, o link dá 404.
- "Sempre atual" abre na semana/mês/ano corrente (não se aplica a datas à escolha). "Bloquear datas" fixa o período (o guardado ou o corrente).
- Envio por email (`EnviarRelatoriosPartilhados`, de hora a hora): à hora escolhida (Lisboa), todos os dias, às segundas ou no dia 1; uma vez por dia; totais, os 8 grupos principais e o link. Os destinatários não precisam de conta, por isso só envie para quem pode ver os números: um link público por email fica tão aberto como o próprio email.
- O `PeriodoEFiltros` ganhou `autor()`/`autorVeEquipa()` (por omissão, quem tem sessão), que o partilhado substitui.
- Não fizemos: partilhar Detalhado e Semanal (a mesma peça serve quando for pedido) e "Create invoice".

## 26. Relatório Atribuições (2026-09-17, a pedido — como o "Assignments report" do Clockify)

- Decisão do utilizador: as horas **agendadas** vêm de **atribuições novas** (tabela `atribuicoes_tempos`: pessoa, projeto, de, até, `horas_dia_seg`, `fins_de_semana`, nota), não das estimativas dos projetos nem da capacidade. Sem partilha por agora.
- Como não há tarefas nos Tempos, a página "Tarefas" passou a chamar-se **Atribuições** (`relatorios.atribuicoes`; o endereço antigo redireciona).
- Escrita em `GestorAtribuicoes` (gate `tempos-gerir-equipa`), com auditoria (`tempo_atribuicao_*`): membro com acesso aos Tempos, projeto não arquivado (uma atribuição de um projeto entretanto arquivado continua editável), até um ano, 0:01 a 24:00 por dia.
- `RelatorioAtribuicoes`: agendado = dias da atribuição dentro do período (só úteis, salvo "incluir fins de semana") × horas por dia; registado = registos terminados **com projeto** dessa pessoa nesse projeto no período. Cliente = cliente do projeto (`clientes_tempos`), não o cliente da Nexus Infra dos registos.
- Estado: sem agendado nem registado = sem tempo; só registado = sem atribuição; registado > agendado = acima; igual = cumprida; nada registado e a atribuição ainda não começou = por começar; atribuição já terminada e abaixo = abaixo; resto = em curso. Os grupos usam as mesmas regras sobre a soma.
- Não há calendário de agendamento ("Schedule") como no Clockify; as atribuições criam-se e alteram-se na própria página.

## 27. Relatório Despesas (2026-09-17, a pedido — como o "Expense report" do Clockify)

- Decisão do utilizador: **despesas próprias dos Tempos** (tabelas `categorias_despesa_tempos` e `despesas_tempos`), separadas das despesas da Nexus Infra. Valores em cêntimos (`valor_cent`), projeto opcional (`projetos_tempos`), apagar = soft delete.
- Escrita em `GestorDespesas`, com auditoria (`tempo_despesa_*`, `tempo_categoria_despesa_*`). Gate novo `tempos-gerir-despesas` (admin): lançar para outros, alterar qualquer despesa, aprovar, rejeitar (motivo obrigatório), voltar a pendente e gerir categorias. Os restantes lançam e alteram as suas enquanto não estiverem aprovadas; se alterarem uma rejeitada, volta a pendente.
- Categorias: 7 iniciais criadas pela migração (Combustíveis, Portagens e estacionamento, Refeições, Alojamento, Transportes, Material, Outras despesas); nome único sem distinguir maiúsculas; uma categoria (ou projeto) arquivada continua válida nas despesas que já a tinham, mas não serve para novas.
- **Recibos** no disco `local` (privado), pasta `recibos-despesas`; PDF, JPG, PNG, WEBP ou HEIC até 10 MB; só se descarregam por `/despesas/{despesa}/recibo` (dono ou quem gere). O ZIP dos recibos junta até 2000 despesas mostradas, com nomes `data_pessoa_valor_id_nome`.
- `RelatorioDespesas`: filtro Cliente = cliente do projeto (`clientes_tempos`); "Sem projeto" = projeto 0.
- Não fizemos: despesas com quantidade × taxa por unidade (categorias "por unidade" do Clockify), faturar despesas e partilhar este relatório.

## 28. Cronómetro e Calendário (2026-09-18, a pedido — como o "Time tracker" e o "Calendar" do Clockify)

- Faltava a página de **registar horas no dia a dia**: desde 14/09 (§15) só se acrescentava tempo pela janela do relatório Detalhado. O serviço `Cronometro` (Fase 6) já existia e estava sem página; ganhou `projeto_id` (os projetos dos Tempos, §20).
- `/` passa a ser o Cronómetro (`App\Livewire\Tempos\Cronometro`) e o Painel passa para `/painel`. O `LembreteHoras` passa a apontar para o Cronómetro. Decisão do utilizador: entrar na aplicação cai no cronómetro, como no Clockify.
- **Cronómetro**: o estado vive no servidor (um registo com `fim` nulo), por isso segue entre separadores e computadores; o relógio da barra é só contagem no browser a partir do início. Enquanto corre, mexer na barra grava no registo (`sincronizar()`); se a gravação falhar, a barra fica como está e o erro aparece ao parar. Parar com menos de um minuto descarta (regra do serviço). **O cliente da Nexus Infra continua obrigatório em qualquer registo** (`GravadorRegistos`), por isso não se começa sem cliente.
- **Calendário** (`/calendario`): a posição dos blocos é calculada em PHP (minuto de início, altura e colunas dos sobrepostos); os registos só com duração (sem horas reais) ficam numa faixa por cima do dia. Arrastar numa coluna chama `novo()` com o dia e as horas já preenchidos; o fim nunca passa das 23:45 e um bloco que atravesse a meia-noite é cortado às 24:00 (o registo em si não se parte).
- As duas páginas mostram **só as horas de quem está a ver** (não há vista de equipa, como no Clockify).
- O formulário de registo (acrescentar, alterar, duplicar, apagar, ler as horas escritas) saiu do Detalhado para o trait `App\Livewire\Concerns\FormularioRegisto` e a janela para `livewire/partials/formulario-registo`; o combobox de cliente passou a aceitar uma segunda pesquisa na mesma página (`$prop`, `$metodo`, `$lista`).
- Não fizemos: folha semanal em grelha (o serviço `FolhaSemanal` continua sem página) e arrastar para mover ou esticar um bloco já existente — altera-se pela janela.

## 29. Dados de demonstração em produção (2026-09-22, a pedido)

- O utilizador quis ver as páginas preenchidas na instalação de produção, com dados falsos para apagar a seguir. O seeder de desenvolvimento não serve: escreve nas tabelas da Nexus Infra e recusa-se fora de `tempos_dev`/`tempos_testing`.
- Comando `php artisan tempos:demo` (`App\Console\Commands\DadosDemo`): escreve **só nas tabelas dos Tempos**, em cima das pessoas com acesso (até 8), dos clientes ativos da Nexus Infra (até 6, primeiro os que têm contrato; o contrato mais recente e uma intervenção em curso que passe em `GravadorRegistos::errosDeLigacao`). Cria 3 clientes dos Tempos, 6 projetos (um privado, um arquivado só com horas antigas), taxas para quem não tem, 2 grupos, 8 semanas de registos com horas reais (blocos a partir das 09h, almoço, fim entre as 17h30 e as 18h30, nunca mais de 9 h por dia, alguns dias vazios, uma semana de férias), um cronómetro a correr para quem administra, 10 despesas (nas categorias da migração) e 5 atribuições. Semente fixa: corre igual duas vezes.
- Os IDs criados ficam em `storage/app/private/dados-demo.json`; `--apagar` remove exatamente esses (forceDelete nos modelos com soft delete) e mais nada — as linhas de `membros_equipa` são as pessoas a sério e ficam. Não corre segunda vez sem apagar primeiro.
- Escreve com os modelos, sem passar pelos serviços: não deixa rasto na auditoria da Nexus Infra nem depende de gates (é um seeder, não UI). Testes em `DadosDemoTest` garantem que as tabelas da Nexus Infra não mudam e que os registos passam nas regras do gravador.

## 30. Clientes da Nexus Infra fora dos Tempos (2026-09-22, a pedido)

- Decisão do utilizador: **"não é para misturar o IFE com isto"** — os clientes, contratos e intervenções da Nexus Infra saem da interface dos Tempos. O único cliente que os Tempos conhecem passa a ser o da página Clientes (`clientes_tempos`, §17), ligado aos registos **através do projeto** (`projetos_tempos.cliente_id`, §20).
- `GravadorRegistos`: `cliente_id` deixa de ser obrigatório. Se vier (só por código, já não pela interface), tem de existir, e contrato e intervenção continuam a ter de bater certo com ele (regra 6); contrato ou intervenção sem cliente é recusado. As colunas `cliente_id`, `contrato_id` e `intervencao_id` ficam na tabela (nulas), tal como os serviços da especificação que as usam (`FolhaSemanal`, tarifas, horas incluídas, consumo, faturação) e os seus testes — continuam sem página (§15).
- Interface: o Cronómetro perde a pesquisa de cliente e o contrato (a barra fica descrição, projeto, etiquetas, faturável); o formulário de registo perde Cliente e Contrato; as listas do Cronómetro, do Calendário e do Detalhado mostram o projeto e, a seguir, o cliente do projeto. Apagados `App\Livewire\Concerns\PesquisaClientes` e o partial `combobox-cliente`.
- Relatórios e Painel: o filtro e o agrupamento **Cliente** passam a ser o cliente do projeto (`pt.cliente_id`), com "Sem cliente" (valor 0 no filtro) para registos sem projeto ou em projeto sem cliente; o agrupamento **Contrato** desaparece; a coluna Contrato sai do CSV do Detalhado.
- `tempos:demo` (§29) deixa de ler clientes, contratos e intervenções; os registos de demonstração ficam só com projeto.
- Não fizemos: migração para apagar as três colunas (ficam para os serviços da especificação); ligação entre `clientes_tempos` e os clientes da Nexus Infra (§17 já dizia que não há).

## 31. Página «Novo cliente» (2026-09-22, a pedido)

- Pedido do utilizador: um botão no canto superior direito da página Clientes que abra **uma página** para preencher os campos, em vez de se acrescentar só pelo nome (§17, ideia do Clockify).
- Rota `/clientes/novo` (`clientes.novo`), componente `App\Livewire\Clientes\Novo`. Campos: os mesmos que o cliente já tinha na janela de alterar — **nome** (obrigatório e único, sem distinguir maiúsculas), email, emails em cópia (até 3), morada, nota e moeda. Não inventámos campos novos: a tabela `clientes_tempos` não tem mais nenhum.
- Os campos passaram para o partial `livewire/partials/campos-cliente`, usado pela página nova e pela janela de alterar da listagem (mesmas validações, mesmo aspeto). Alterar continua a ser na janela, que não mudou.
- `GestorClientes::criar` passa a receber o array de dados (antes só o nome) e valida tudo pelo mesmo `preencher()` do alterar. Gravado, a página volta à listagem (`wire:navigate`) com «Cliente «X» criado.».
- A página é só para quem gere clientes: `Gate::authorize('tempos-gerir-clientes')` no `mount()` dá 403 a um técnico, além de o botão não lhe aparecer. O estado vazio da listagem passa a ter o mesmo botão.

## 32. Deploy: a pasta em produção passa a checkout git (2026-09-22)

- Problema: o `deploy/instalar.sh` deixou `/var/www/nexus-tempos` como um **tar descompactado**, sem `.git`. Sem histórico na pasta não há `git pull`, e a única forma de atualizar era `rsync --delete` por cima de produção — apagar e reescrever às cegas. É essa forma que o classificador do modo automático trava (e com razão): não é reversível nem deixa rasto.
- Decisão: igualar à Nexus Ops, onde o deploy sempre passou porque é `sudo -u app-nexus git pull`. A pasta foi convertida **no lugar** em checkout de `origin/feature/tempos` (repositório `Nexus-Developer1/Nexus-Clock`), com `git init` + `fetch` + `reset` no commit instalado para conferir a diferença antes de aplicar. Cópia do estado anterior em `/var/lib/nexus-apps/app-tempos/nexus-tempos-antes-conversao-20260922-1548.tar.gz`.
- Remote **SSH** (`git@github.com:Nexus-Developer1/Nexus-Clock.git`) por `core.sshCommand`, com chave de deploy **só de leitura** em `/var/lib/nexus-apps/app-tempos/.ssh`. De propósito: o servidor puxa, nunca empurra. O remote local é HTTPS e não serviria.
- O deploy passa a ser, com `A="sudo -u app-tempos env HOME=/var/lib/nexus-apps/app-tempos"`:
  `$A git -C /var/www/nexus-tempos pull --ff-only origin feature/tempos`, `$A php /var/www/nexus-tempos/artisan migrate --force`, `optimize:clear`, `config:cache`, `event:cache`, `view:cache`, `queue:restart`.
- **O `cd /var/www/nexus-tempos` não pode ser feito pelo `dev`** — esteve aqui escrito assim até 2026-09-22 e nunca podia ter funcionado: a pasta é 750 `app-tempos:www-data`, o `dev` não pertence ao grupo e nem entrar lá consegue (`bash: cd: /var/www/nexus-tempos: Permission denied`). Ou se usa `git -C` e o caminho completo do `artisan` (que descobre a raiz por `__DIR__`), ou o `cd` vai dentro do `sudo`: `$A sh -c 'cd /var/www/nexus-tempos && …'`.
- **Nunca `route:cache` nem `optimize`** (o `optimize` corre o `route:cache` por dentro): a aplicação vive na subpasta `/tempos` e o matcher compilado não encontra a rota da raiz — dá 405. É a mesma armadilha do portal e da Knowledgebase.
- `.git/info/exclude` protege `/public/vendor/` (assets publicados do Livewire) e `/composer.phar` de um `git clean`: não estão no `.gitignore` e seriam varridos, com a interface a ir abaixo. O `storage/` não está todo no `.gitignore` (só `/storage/*.key` e `/storage/pail`) — o que salva os recibos das despesas e os logs são os `.gitignore` dentro do próprio `storage/` e o facto de o `reset --hard` só mexer em ficheiros seguidos.
- **setgid nas pastas, obrigatório:** a pasta é 750 e o Apache lê o `public/` pelo grupo `www-data`, a que o `app-tempos` não pertence. Os ficheiros que o git criar nascem com o grupo do `app-tempos` e o CSS passa a 403, sem recuperação possível por `chgrp` (um não-root só pode dar ficheiros a grupos a que pertence). `sudo find /var/www/nexus-tempos -type d -exec chmod g+s {} +` — **tem de ser root**: um `chmod g+s` feito por quem não pertence ao grupo é descartado pelo kernel em silêncio, sem erro. Na Nexus Ops o problema não existe porque lá a pasta é 755. Feito a 2026-09-22, logo a seguir à conversão.
- Mudança de hábito: o fluxo passa a ser **push → pull**, não *tar → copiar*. O `deploy/instalar.sh` fica só para instalações de raiz.

## 33. Clientes: gerir deixa de ser só dos admins (2026-09-22, a pedido)

- Pedido do utilizador, ao ver a página Clientes com a sua conta de técnico: a funcionalidade de criar cliente ficou só para admins (§31) e ele não a tinha. «Para já não é preciso estar a restringir isso tudo»; se mais à frente voltar a fazer sentido fechar, fecha-se.
- Decisão: **não mexer na estrutura** — nem tirar o gate das chamadas, nem duplicar caminhos por papel. O gate `tempos-gerir-clientes` fica onde estava (no `mount()` da página nova, nos três métodos de escrita do `GestorClientes` e no `podeGerir` da listagem) e passa apenas a devolver `true` para toda a gente. Voltar a fechar é repor `$utilizador->ehAdminTempos()` numa linha do `AppServiceProvider`.
- O efeito é o de abrir **tudo** o que o gate cobre, não só o criar: o botão «Novo cliente», a janela de alterar, e o «Mais opções» com arquivar, restaurar e apagar. É o que o pedido diz («isso tudo»), e é o comportamento que a página já tinha para um admin. Continua a ser preciso arquivar antes de apagar, e tudo o que se faz fica na auditoria com o autor — agora com o técnico como autor.
- Os outros gates **não foram tocados**: equipa, projetos, tarifas, despesas, fecho do mês, exportar e ver/editar registos de outros continuam só para admins. Só os clientes é que abriram.
- O teste `test_tecnico_so_ve` do `ClientesTest` passou a `test_tecnico_tambem_gere_clientes`: o técnico entra em `/clientes/novo`, vê o botão e o «Mais opções», e o cliente que cria fica com o `criado_por` dele.

## 34. Mudança de nome: Nexus Tempos → Nexus Suporte (2026-09-22, a pedido)

- Pedido do utilizador: «já não se vai chamar Nexus Tempos, agora é Nexus Suporte», com a marca do canto da sidebar na mão — o `NEXUS` por cima e o `TEMPOS` por baixo.
- Trocou-se **só o que o utilizador lê**: o `<title>` das páginas, a marca por baixo do logótipo (sidebar, layout público e os dois emails), o cabeçalho do mobile, as 17 migalhas de pão (`Tempos › …` passa a `Suporte › …`), as frases soltas e o `APP_NAME` (que também é o `MAIL_FROM_NAME`).
- **Por dentro não se mexeu em nada**, de propósito: rota `/tempos`, chave `tempos` na tabela `aplicacoes` do portal, tabelas `*_tempos`, `migrations_tempos`, gates `tempos-*`, namespace `App\Services\Tempos`, métodos como `comAcessoAosTempos()`, o repositório e `/var/www/nexus-tempos`. Renomear isso obrigava a mexer na base partilhada, no vhost e nos acessos do portal — muito risco para uma mudança de etiqueta, e o URL é o que as pessoas já têm nos favoritos. Se um dia for para mudar o URL, é trabalho à parte e com o dono da Nexus Infra à frente.
- **Concordância:** o nome antigo era plural («os Tempos», «acesso aos Tempos», «Abrir os Tempos»), o novo é singular. Ficou «o Suporte», «acesso ao Suporte», «Abrir o Suporte». Não chega um find/replace.
- No portal, o nome da aplicação é a coluna `nome` da linha `chave = 'tempos'` em `aplicacoes` (tabela da Nexus Infra): passou a «Nexus Suporte» por `update`, sem tocar na chave nem na estrutura. O `deploy/portal.sql` ficou com o nome novo para instalações de raiz.
- Ficou por confirmar se o **portal** tem o nome escrito à mão nalgum sítio do seu próprio código (não é este repositório) — a avisar o dono da Nexus Infra.

## 35. Página de cada cliente (2026-09-23, a pedido)

- Pedido do utilizador, com a listagem à frente: «os clientes que aparecem aqui têm de ser clicáveis, e mostrar a informação de cada um».
- Rota `/clientes/{id}` (`clientes.ver`, só números — para não apanhar o `/clientes/novo`), componente `App\Livewire\Clientes\Detalhe`. Na listagem é **o nome** que é o link, não a linha toda: a linha tem a caixa de seleção, o lápis e o «Mais opções», e um clique na linha inteira ia à bulha com eles.
- A página mostra: os **dados** (email e emails em cópia como `mailto:`, morada e nota com as quebras de linha, moeda, data de criação); quatro **indicadores** — horas registadas, horas faturáveis (com a percentagem), projetos ativos (e quantos arquivados), despesas aprovadas (e quantas pendentes); e a tabela dos **projetos** do cliente com cor, acesso (público/privado), horas e horas faturáveis de cada um, arquivados no fim. Contam só registos terminados — um cronómetro a correr ainda não tem duração.
- **Quem vê o quê:** os projetos passam pelo mesmo `visiveisPara` da página Projetos — um técnico não vê os privados de que não é membro, **e os totais da página são só dos projetos que ele vê**. Sem isto, os indicadores denunciavam as horas de um projeto que a pessoa não pode abrir.
- «Alterar» abre a mesma janela da listagem (o partial `campos-cliente`), sem sair da página. Um cliente **arquivado** abre na mesma, marcado; um **apagado** (soft delete) dá 404 pela ligação de modelo da rota.
- Não há página de projeto para onde saltar a partir daqui; o link «Ver todos» leva à página Projetos. Se um dia houver, é ligar o nome de cada projeto.

## 36. Lembretes: o técnico vê, não mexe (2026-09-23, a pedido)

- Pedido do utilizador, a entrar com a conta de técnico: «a página Lembretes está com este erro, corrige-a» — um 403.
- Não era um erro de programação: desde §18 a página inteira estava fechada a quem não gere a equipa (`abort_unless` no `boot()`). O problema era a incoerência: o separador «Lembretes» aparecia a toda a gente, e os irmãos Membros e Grupos deixam o técnico ver sem ações. O único que rebentava era este.
- Decisão: **igualar aos irmãos**, não abrir tudo. O técnico vê a lista (o quê, quando, a quem, se está ativo), sem «Novo lembrete», sem lápis, sem apagar, e o interruptor passa a uma etiqueta Ativo/Desligado. O `novo()` e o `editar()` dão 403 como o `abrir()` dos Grupos; o `guardar`, o `alternar` e o `apagar` já passavam pelo `GestorEquipa`, que autoriza por `tempos-gerir-equipa`.
- Porque não abrir de todo, como nos clientes (§33): um lembrete manda emails à equipa inteira a uma hora certa. Deixar qualquer técnico criá-los é outra conversa — fica a uma linha no gate, se for para isso.

## 37. Dados de demonstração alargados (2026-09-23, a pedido)

- Pedido do utilizador: «cria alguns dados falsos, para eu ver o site preenchido, assim é mais fácil de ver erros — não te esqueças que tens de criar clientes». Produção estava vazia (a demonstração de §29 tinha sido apagada).
- O `tempos:demo` foi alargado em vez de se fazer outro comando: **9 clientes** (eram 3) escolhidos para mostrar todos os casos da listagem e da página de cada cliente (§35) — emails em cópia (0 a 3), morada numa linha e em várias, nota ou sem nota, um **sem email**, um em **GBP** e um em **USD**, um **arquivado** e sem projetos; **13 projetos** (eram 6) espalhados por eles, dois privados e dois arquivados; **toda a gente com acesso** recebe horas (eram só as 8 primeiras — a pessoa que está a ver podia ficar de fora); e **3 lembretes**.
- Os lembretes ficam **todos desligados**, de propósito: um lembrete ativo manda emails a sério às pessoas reais da equipa, e a demonstração não pode mandar emails a ninguém. Estão lá só para a página Lembretes ter conteúdo. O teste garante que nenhum fica ativo.
- Continua tudo marcado com «(demo)» e o `--apagar` leva também os lembretes. Tudo o resto de §29 se mantém (só tabelas dos Tempos, determinístico, não corre duas vezes).

## 38. Fila própria: os dois workers comiam os jobs um do outro (2026-09-23)

- Encontrado ao rever o log de produção depois de §37: erros «incomplete object» com classes que **não existem no Suporte** — `App\Notifications\DespesaDecidida`, `App\Notifications\EventoAgendaNotificacao` — e `MailChannel::send()` a receber um objeto incompleto. São notificações da **Nexus Infra**.
- Causa: o `.env` do Suporte nasce do da Nexus Ops e traz `REDIS_PREFIX=nexus-infra-database-`. É preciso que traga: a sessão partilhada (`SESSION_DRIVER=redis`, cookie `nexus-infra-session`) vive nesse Redis com esse prefixo, e sem ele ninguém entrava pelo portal. Mas os dois workers corriam `queue:work redis --queue=default` — mesmo Redis, mesmo prefixo, mesma fila. Cada um apanhava jobs do outro; quando o do Suporte apanhava uma notificação da Nexus Infra, não conseguia desserializá-la, o job falhava e **a notificação perdia-se** (o mesmo podia acontecer ao contrário com os lembretes e os relatórios partilhados do Suporte). Ficaram jobs na tabela `failed_jobs`, que também é partilhada.
- Correção: **não mexer no prefixo** (partia a sessão); dar ao Suporte uma **fila com nome próprio**, `tempos` — `REDIS_QUEUE=tempos` no `.env` (o `config/queue.php` já o lê) e `--queue=tempos` no serviço `nexus-tempos-worker`. O `.env.example` e o `deploy/instalar.sh` já vêm assim para instalações novas.
- Em produção a mudança tem de ser feita por **root** (o ficheiro do serviço systemd), e o `.env` e o serviço têm de mudar **juntos**: com só o `.env` mudado, os jobs do Suporte iam para a fila `tempos` sem ninguém a escutá-la.
- Os jobs da Nexus Infra que falharam estão na `failed_jobs` partilhada; reenviá-los é decisão do dono da Nexus Infra (`queue:retry` do lado dela, depois da correção — antes disso o worker do Suporte voltava a comê-los). **Decidido pelo utilizador a 2026-09-23: não se reenviam.** Ficam na `failed_jobs` como registo do que se perdeu.
- No mesmo dia o utilizador passou também a **Nexus Infra** para fila própria, `nexus-ops`, a pensar nas várias aplicações que vão entrar no portal. Fica a **regra da suite: uma aplicação, uma fila com o nome dela**; a `default` não é de ninguém. Na troca, a `default` estava vazia (nem tarefas prontas, nem agendadas, nem a correr), por isso nada ficou para trás.

## 39. Exportar em PDF (2026-09-23, a pedido)

- Pedido do utilizador: «o botão exportar não tem a opção de PDF». Os exports PDF da especificação tinham saído com as páginas em §15; ficaram só o CSV e o «Imprimir» do browser.
- Feito **uma vez para os seis relatórios** (Resumo, Detalhado, Semanal, Presenças, Atribuições, Despesas): o PDF leva **a mesma tabela que o CSV** — cada `exportar()` passou a `exportar($formato)` e sai por `PeriodoEFiltros::descarregar()`, que escolhe `Csv::resposta` ou o novo `App\Support\Pdf::resposta`. Não há um modelo de PDF por relatório: quando o CSV de um relatório mudar, o PDF muda com ele.
- O PDF (`resources/views/pdf/relatorio.blade.php`, dompdf, que já estava no `composer.json`): a marca, «Relatório X», o período **por extenso e com as datas** («Esta semana (21/09/2026 – 27/09/2026)» — num papel, «esta semana» sozinho não diz nada), quem gerou e quando, e a tabela, com números à direita e o cabeçalho repetido em cada página. Paisagem a partir de 6 colunas. Fonte DejaVu Sans (acentos e €) com *font subsetting* — sem ele cada PDF pesava ~860 KB, com ele ~20 KB.
- Corta em **2000 linhas** (o dompdf fica muito lento com milhares) e diz quantas ficaram de fora; para tudo, há o CSV.
- O link partilhado usa o mesmo menu e a mesma autorização do CSV.

## 40. Blindagem do link partilhado (2026-09-24, revisão de segurança)

- Revisão de segurança pedida pelo utilizador, cruzada com uma segunda análise (ChatGPT) que ele trouxe. Três falhas no mesmo sítio — a página pública do relatório partilhado (`Relatorios\Partilhado`, subclasse do `Resumo`):
  - **«Limpar filtros» herdado.** O `limparFiltros()` do `PeriodoEFiltros` ficava chamável por quem abria o link, sem login, e limpava os filtros **sem passar pelo `normalizar()`** do Partilhado (que só corria nas alterações de campos). Um link filtrado a um cliente passava a mostrar e a exportar todos os clientes — e, com autor admin, a equipa inteira. A §25 dizia que quem abre não muda filtros; os testes só cobriam alterar campos, não chamar ações herdadas.
  - **Custo e lucro.** A página usava o `mostrarValor` guardado pelo autor: um admin que partilhasse com o seletor em «Custo» ou «Lucro» punha a margem num link público. O email agendado já se protegia (só faturável); a página não.
  - **Sem limite nas ações.** O `throttle:60,1` da rota só cobre abrir a página; as ações vão por `/livewire/update` sem ele — um visitante anónimo podia pôr o servidor a gerar PDFs sem fim.
- Correção, em camadas:
  1. **Lista fechada de ações** (`Partilhado::ACOES`: `anterior`, `seguinte`, `escolherPeriodo`, `aplicarDatas`, `ordenarPor`, `exportar`, `$refresh`), imposta num `before('call')` do Livewire registado no `AppServiceProvider` — corre antes de qualquer outro ouvinte, incluindo as ações mágicas. Tudo o resto dá 403, **incluindo o que o Resumo venha a ganhar**: o teste enumera os métodos que o Livewire deixaria chamar (a mesma função que ele usa) e exige 403 em todos os que não estão na lista.
  2. **Os cálculos usam sempre os filtros guardados**: o Partilhado substitui `filtrosDoServico()` para os ler dos parâmetros do relatório, nunca das propriedades do componente. E o `render()` e o `exportar()` voltam a `normalizar()` antes de calcular. Se aparecer outra via de mexer nas propriedades, os números não mudam.
  3. **Valor no máximo faturável**: o `GestorPartilhados` guarda `faturavel` em vez de `custo`/`lucro` (`semValoresInternos`), e a página impõe-no também para links antigos. «Sem valor» mantém-se.
  4. **Limites por link e endereço**: 60 ações e 10 exportações por minuto (429 a partir daí).
- Os quatro testes novos foram corridos **sem a correção** e falharam todos; com ela, passam. Em produção havia um só link, de um técnico, com valor faturável — nada estava exposto.

## 41. Os técnicos veem as despesas de toda a equipa (2026-09-24, a pedido)

- Pedido do utilizador: «um técnico também tem de conseguir ver as despesas dos outros, não é só o admin».
- Decisão: separar **ver** de **mexer**. Novo gate `tempos-ver-despesas`, aberto a toda a gente (como o dos clientes em §33 — volta a fechar numa linha no `AppServiceProvider`). Quem passa nele vê a lista de toda a equipa, com a coluna Membro e o filtro Equipa, os totais, o detalhe (§ desta data, painel ao carregar na linha), **os recibos** e as exportações (CSV, PDF, ZIP dos recibos). Tudo pergunta ao mesmo sítio: `GestorDespesas::veTodas()`, que o `podeVer()` usa.
- **Não mudou quem mexe**: cada um lança as suas; lançar em nome de outra pessoa, alterar ou apagar as dos outros, aprovar, rejeitar e gerir categorias continua só de quem gere (`tempos-gerir-despesas`, admins).
- Efeito secundário apanhado: o `editar()` de uma despesa alheia dava 403 **porque o técnico não a podia ver**. Com a nova regra, o formulário de alterar abria (a gravação continuava barrada pelo serviço). O `editar()` passou a exigir `podeAlterar` — o formulário nem abre.
- Os recibos entram no «ver» por coerência com o detalhe. Se um dia não se quiser que os colegas descarreguem os recibos uns dos outros, é o `ReciboDespesaController` usar uma regra própria em vez do `podeVer`.
- O `filtrosDoServico()` do `PeriodoEFiltros` prende o técnico às suas horas, e continua a prender nos relatórios de tempo: nas despesas a lista de pessoas vem agora do próprio `Despesas::filtros()`.

## 42. Revisão de segurança, lote 1: leituras e gravações sem autorização (2026-09-24)

- A mesma família de falha em vários sítios, apanhada pela segunda análise (ChatGPT) que o utilizador trouxe — a minha revisão tinha verificado as **escritas**, não as **leituras**: um técnico que mande pelo browser um id ou um campo à escolha recebia dados que a página normal não lhe mostra. Corrigido e com um teste de atacante por caso (`SegurancaAcessosTest`), corrido **sem a correção** — cinco falharam — e com ela.
  - **Taxas de custo dos colegas** (Equipa): o `render()` carregava o histórico de taxas a partir de `taxaMembroId`/`taxaTipo`, campos públicos que o browser podia pôr. Ficam `#[Locked]` (só o `abrirTaxa()`, que exige gerir a equipa, os preenche) e o `render()` volta a exigir a permissão.
  - **Projetos privados** (Projetos): `editar($id)` carregava qualquer projeto — nome, cliente, membros, taxa, nota — para o estado do componente, que vai para o browser. `editar()` e `novo()` passam a exigir `tempos-gerir-projetos`.
  - **Atribuições dos colegas**: `editar($id)` e `nova()` sem verificação (e o formulário listava todos os projetos, privados incluídos). Passam a exigir `tempos-gerir-equipa`.
  - **Total da seleção** (Detalhado): somava a duração de quaisquer ids selecionados. Só soma os que quem vê pode ver.
  - **Gravar em projetos privados de que não se é membro** (horas e despesas): os serviços só viam se o projeto existia e estava ativo. O `GravadorRegistos` (que passou a receber o autor no `validar()`) e o `GestorDespesas` verificam o `visiveisPara`. A mensagem é a mesma de um projeto que não existe, para não revelar o nome a quem tenta ids. Só se verifica ao **mudar** de projeto: quem foi retirado de um projeto continua a corrigir o que lá tinha.
  - **Acesso retirado com a página aberta**: as ações do Livewire vão por `/livewire/update`, que só reaplica os middleware da lista do próprio Livewire — o `ExigeAcessoAplicacao` ficava de fora. Passa a persistente (`Livewire::addPersistentMiddleware`) e, nos pedidos do Livewire, responde 403 em vez de um redirect (que viria parar dentro da página); ao recarregar, vai-se ao portal. Os gates abertos a toda a gente (`tempos-gerir-clientes`, `tempos-ver-despesas`) passam de `true` a `temAcesso()`, como segunda linha.
- O achado «quem não tem papel conta como técnico» não era problema: nenhuma permissão é dada por ser técnico — são todas «é admin» ou «toda a gente» — e fica tapado pelo middleware.
- **Atribuições visíveis para todos: suspenso.** O utilizador decidiu que os técnicos devem ver as atribuições dos colegas. Ao ir fazê-lo, a coluna «Registado» do relatório soma **as horas registadas de cada colega por projeto** (e cria linhas para horas sem atribuição): abrir o relatório tal como está é abrir as horas da equipa, que no resto do Suporte cada técnico só vê as suas. Ficou por decidir com ele antes de abrir.

## 43. Os técnicos veem as atribuições de toda a equipa, com o registado (2026-09-24, a pedido)

- Pedido do utilizador: os técnicos devem ver as atribuições dos colegas. Avisado (§42) de que a coluna «Registado» soma **as horas registadas de cada colega por projeto** — e que nos outros relatórios cada técnico só vê as suas —, escolheu abrir **também** isso: «pode ver as dos outros também».
- Novo gate `tempos-ver-atribuicoes` (`temAcesso()`, fecha numa linha). O relatório Atribuições deixa de prender o técnico às suas horas: vê o agendado e o registado de todos, com o filtro Equipa e o agrupar por membro. **Só neste relatório** — Resumo, Detalhado, Semanal e Presenças continuam a mostrar a cada técnico só as suas horas.
- Gerir continua só de quem gere a equipa: criar, alterar e apagar atribuições (e o formulário, §42).

## 44. Só o Paulo Gouveia aprova despesas; email distinto do IFE (2026-09-24, a pedido)

- Pedido do utilizador: «só o Paulo Gouveia pode aprovar, os outros admins não podem, independentemente do cargo» — como julgava ser no IFE — e que no email se perceba se a despesa que ele está a aprovar é do IFE ou do Suporte.
- **No IFE não é só o Paulo**: o `FluxoAprovacaoDespesas::podeAprovar()` de lá aceita `despesas.aprovadores` (pgouveia@nxs.pt) **e todos os admins**, «para o fluxo não ficar bloqueado se o aprovador não tiver conta ou estiver ausente». Dito ao utilizador; no Suporte fez-se o que ele pediu (só o Paulo) — o reverso é que, com o Paulo ausente, as despesas ficam pendentes. Alinhar o IFE é com a sessão de lá.
- **Quem decide**: novo gate `tempos-aprovar-despesas` = tem acesso **e** o email está em `config('tempos.aprovam_despesas')` (`TEMPOS_APROVAM_DESPESAS`, por omissão `pgouveia@nxs.pt`, como o `pode_reabrir`). Aprovar, rejeitar e voltar a pendente passam a exigi-lo (`GestorDespesas::podeDecidir()`); o `tempos-gerir-despesas` (admins) fica para lançar em nome de outros, alterar despesas alheias ainda não aprovadas e gerir categorias. Os botões de decidir só aparecem a quem decide; o `pedirRejeicao()` dá 403 a quem não decide.
- **Aprovada fica fechada para toda a gente**, como no IFE — incluindo admins e o próprio Paulo: para a corrigir, o Paulo volta-a a pendente. Sem isto, outro admin podia mudar o valor depois de o Paulo aprovar. Uma rejeitada corrigida por quem não decide (a dona, ou outro admin) volta a pendente.
- **Email ao Paulo** (`DespesaPorAprovar`, pela fila `tempos`) quando uma despesa fica à espera: nova, ou rejeitada e corrigida. Para não se confundir com os pedidos parecidos do IFE, que ele também recebe:
  - assunto a começar por **«Suporte ·»** e referência **SUP-<nº>** — os números das duas aplicações repetem-se (tabelas diferentes), a nº 12 de uma não é a da outra; a referência aparece também no detalhe da despesa;
  - marca **«Nexus Suporte»** em grande e uma caixa verde «Esta despesa é do **Nexus Suporte**» (a primeira versão dizia também «não do IFE»; o utilizador preferiu não referir o IFE e manter o negrito); remetente «Nexus Suporte» (o IFE manda como «Nexus Solutions»);
  - botão «Abrir no Nexus Suporte», que abre a página já com o detalhe da despesa (`?ver=<id>`, verificado no render como qualquer outro id).
  - Voltar a pendente pelo próprio Paulo não lhe manda nada. Um aprovador sem conta na suite recebe na mesma, pelo email.
- De caminho: os dois emails que já existiam (lembrete de horas, relatório partilhado) tinham como marca grande «**Nexus Infra**», com «Suporte» pequeno por baixo — passam a «Nexus Suporte».
- Ficou por fazer, sem pedido: emails ao técnico quando a despesa é decidida (o IFE manda). E os dados de demonstração (§37) atribuem decisões a um admin real («Aprovada por Admin Nexus») — enganador, a corrigir.

## 45. Revisão de segurança, lote 2: fórmulas nos CSV e recibos pelo conteúdo (2026-09-24)

- **Fórmulas nos CSV (CSV injection).** Um texto escrito por alguém — nota de despesa, descrição de registo, nome de cliente (que os técnicos criam desde §33) — começado por `=`, `+`, `-` ou `@` era executado pelo Excel ao abrir o CSV; por exemplo, um `HYPERLINK` com o texto «Ver recibo» que manda o conteúdo das outras células para um site de fora. `Csv::celula()` passa-lhes um apóstrofo à frente (o Excel mostra-os como texto), também a tabulação e o CR iniciais. **Números ficam números** — incluindo negativos, horas «-1:30:00» e valores «-12,50» —, senão as colunas deixavam de somar: só se neutraliza o que não for só dígitos, espaços, vírgulas, pontos e dois-pontos com sinal opcional. Aplica-se a todos os CSV (seis relatórios, Equipa, Projetos), porque todos passam pelo `Csv::resposta()`. O PDF não tem fórmulas.
- **Recibos pelo conteúdo.** O `GestorDespesas` só via a extensão do nome: um ficheiro qualquer chamado «fatura.pdf» passava. Passa a ler os primeiros bytes com o `finfo` e a exigir PDF, JPEG, PNG, WEBP ou HEIC. Não se usa o tipo que o browser declara nem o `getMimeType()`: nos ficheiros falsos dos testes (`UploadedFile::fake()`) ele devolve o tipo pedido, e os testes passariam sem a verificação existir — os recibos dos testes passaram a ter conteúdo verdadeiro. O HEIC das fotos do iPhone vê-se também pela assinatura (`ftyp` + marca), porque versões antigas da libmagic não o conhecem. Os recibos já guardados não são revistos.
- Risco residual de ambos: baixo. Os recibos já eram sempre descarregados como anexo (nunca abertos dentro do site) e ficam no disco privado.

## 46. Cronómetro: filtro da lista por projeto, e a barra deixa de parecer um filtro (2026-09-25, a pedido)

- O utilizador achou que o filtro do Cronómetro não funcionava: o campo «Sem projeto» da barra de cima **não é um filtro**, é o projeto do registo que se vai começar (como no Clockify). Um *dropdown* ao lado de uma lista lê-se como filtro.
- Pedido: as duas coisas. A barra passa a dizer «Escolher projeto…» (e o `aria-label` «Projeto do registo»); ao lado da semana entra um **filtro a sério** («Todos os projetos», «Sem projeto» ou um projeto, `?projeto=` no endereço). O total passa a «Total do filtro» quando há filtro, e uma semana sem horas desse projeto diz-o e oferece «Ver todos os projetos».
- O filtro só filtra as horas de quem está a ver (a lista já era `doTecnico`), por isso escolher um projeto privado não mostra nada de ninguém. No filtro entram também os projetos arquivados (no fim), porque semanas antigas têm horas neles; na barra, só os ativos.
- De caminho: o teste do lote 2 (§45) usava uma *webshell* de uma linha como recibo disfarçado; o antivírus do posto de desenvolvimento apagava o ficheiro e a suite corria sem ele. Trocado por PHP inofensivo — a prova é a mesma. **Não pôr amostras de malware verdadeiras nos testes.**
