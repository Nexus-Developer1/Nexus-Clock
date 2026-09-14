# Changelog

Registo de alterações do **Nexus Tempos**. Mais recente no topo.
Categorias: 🔒 Segurança · 🧰 Funcionalidade · 🎨 UI/Marca · 🧹 Limpeza · 🛠️ Infra
_(itens de infra vivem no servidor e não têm commit)._

---

## 2026-09-14

- 🛠️ **Esqueleto da aplicação ligado à suite** — Laravel 13 numa aplicação própria, como a Knowledgebase e o Portal. Sem login próprio: sessão partilhada com o portal, acesso pela chave `tempos` na tabela `aplicacoes`, papel (`admin`/`tecnico`) lido de `acessos.papel`, conta desativada posta fora no pedido seguinte. Mesma base de dados da Nexus Infra com registo de migrações próprio (`migrations_tempos`); as tabelas da Nexus Infra e do portal só são criadas em bases vazias (desenvolvimento e testes). A aplicação recusa `migrate:fresh`/`db:wipe` fora de `tempos_dev`/`tempos_testing`. Especificação do módulo em `docs/modulo-tempos.md`. 8 testes.
