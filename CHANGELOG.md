# Changelog

Registo de alterações do **Nexus Tempos**. Mais recente no topo.
Categorias: 🔒 Segurança · 🧰 Funcionalidade · 🎨 UI/Marca · 🧹 Limpeza · 🛠️ Infra
_(itens de infra vivem no servidor e não têm commit)._

---

## 2026-09-22

- 🛠️ **Primeira instalação em produção** — `infra.nexus-solutions.pt/tempos`, ao lado do portal e do Knowledgebase, com `deploy/instalar.sh` (código, utilizador `app-tempos`, pool PHP-FPM, `/tempos` no vhost da Nexus Ops, `.env` gerado a partir do da Nexus Ops, `migrate --force` só das tabelas dos Tempos, worker e scheduler) e `deploy/portal.sql` (aplicação `tempos` e acessos copiados da Nexus IFE). APP_KEY alinhada com a da Nexus Ops para a sessão partilhada. 260 testes.
- 🧹 **Histórico recuperado** — o repositório local das Fases 2–6 perdeu-se; este commit repõe no GitHub o estado exatamente instalado no servidor, em cima da Fase 1. A tag `tempos-completo` (estado antes de §15 das notas) não sobreviveu. As decisões dessas fases estão em `docs/modulo-tempos-notas.md` §4–§28.
- 🧰 **Interface ao estilo do Clockify (2026-09-15 a 2026-09-18, notas §16–§28)** — menu com Cronómetro, Calendário, Painel, Relatórios (Resumo, Detalhado, Semanal, Presenças, Atribuições, Despesas, relatórios partilhados por link com envio agendado), Projetos (tabela própria, com registos ligados a projeto), Equipa (grupos, papéis, lembretes de horas por email) e Clientes (dados dos Tempos por cima dos clientes da Nexus Infra). Despesas com recibo em disco privado.
- 🧹 **Páginas da especificação retiradas (2026-09-14, notas §15)** — as 7 páginas originais, os exports CSV/PDF e os emails saíram a pedido; ficaram os serviços com as regras de negócio (GravadorRegistos, FolhaSemanal, Cronometro, EdicaoEmMassa, tarifas, horas incluídas, relatórios, faturação, alertas) e os seus testes.
- 🧰 **Fases 2–6 da especificação (2026-09-14, notas §4–§14)** — folha de horas semanal, relatórios, tarifas e margem, fecho mensal e exportação de faturação, cronómetro, edição em massa, alertas de horas, aprovação de semanas e resumo livre.

## 2026-09-14

- 🧰 **Fase 1 — fundações do registo de tempos** — sem UI ainda. Quatro tabelas próprias na base da Nexus Infra: `registos_tempo` (durações em segundos, `timestamptz` em UTC, etiquetas `text[]`, um só cronómetro a correr por técnico garantido pela base de dados), `tarifas` (preço e custo por hora, por contrato/cliente/técnico/global, com validade), `contrato_horas_incluidas` (horas por mês/trimestre/ano/total, com transporte, arredondamento de faturação e validade — as condições podem mudar a meio do contrato) e `semanas_tempo` (rascunho → submetida → reaberta), mais a view materializada `contrato_consumo_periodo`, refrescada todas as noites às 03h. Regras de negócio 1–11 da especificação: técnico só mexe no que é seu e não mexe em semanas submetidas; registos fechados só com permissão explícita de reabrir; faturados só se anulam, com motivo, na auditoria da Nexus Infra; cliente, contrato e intervenção têm de bater certo (intervenção sem cliente é recusada; **concluída só avisa**); consumo sem arredondamento, excedente e transporte dentro da validade; tarifa por contrato → cliente → técnico → global. Leitura de durações escritas à mão (`1:30`, `1,5`, `90m`, `1h30`). Nomes em português e decisões fora da especificação em `docs/modulo-tempos-notas.md`. Seeder com ~3 meses de dados para a base de desenvolvimento. **Requer `migrate`**. 97 testes. `83a84f1` `f4ee1a7` `74446ad`

- 🛠️ **Esqueleto da aplicação ligado à suite** — Laravel 13 numa aplicação própria, como a Knowledgebase e o Portal. Sem login próprio: sessão partilhada com o portal, acesso pela chave `tempos` na tabela `aplicacoes`, papel (`admin`/`tecnico`) lido de `acessos.papel`, conta desativada posta fora no pedido seguinte. Mesma base de dados da Nexus Infra com registo de migrações próprio (`migrations_tempos`); as tabelas da Nexus Infra e do portal só são criadas em bases vazias (desenvolvimento e testes). A aplicação recusa `migrate:fresh`/`db:wipe` fora de `tempos_dev`/`tempos_testing`. Especificação do módulo em `docs/modulo-tempos.md`. 8 testes. `cab9f74`
