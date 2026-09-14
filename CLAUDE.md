# CLAUDE.md — Nexus Tempos

Aplicação da suite Nexus (como a Knowledgebase e o Portal) para registo de horas dos técnicos.
Ler antes de mexer: `README.md`, `docs/modulo-tempos.md` (especificação) e
`docs/modulo-tempos-notas.md` (adaptações e decisões — onde discordar da especificação, vale esta).

## Invariantes

- **PT-PT em tudo**: UI, mensagens, comentários, commits. Nunca PT-BR.
- **Base partilhada com a Nexus Infra** (`C:\Users\dev02\nexus-ops`): tabelas próprias, migrações em
  `migrations_tempos`. Nunca alterar a estrutura de tabelas da Nexus Infra ou do portal — propor
  e esperar aprovação. Nunca `migrate:fresh`/`db:wipe` fora de `tempos_dev`/`tempos_testing`.
- **Chaves estrangeiras para tabelas da Nexus Infra: `ON DELETE SET NULL`** (convenção da suite —
  apagar uma pessoa no portal não pode rebentar).
- **Sem PHC.** Pontos onde o PHC "faria sentido" vão para as notas.
- **Durações em segundos** (`duracao_seg`), **timestamptz em UTC**, apresentação em
  `config('tempos.fuso')` (Europe/Lisbon). O dia de um registo é a data local de `inicio`.
- **Arredondamento só na faturação**, nunca na gravação nem no consumo mostrado ao técnico.
- Toda a escrita de registos passa por `App\Services\Tempos\GravadorRegistos` (autorização +
  regras de negócio). Não gravar `RegistoTempo` diretamente a partir da UI.

## Convenções (imitadas da Nexus Infra)

- Nomes de domínio em português, snake_case. Enums em `app/Enums` com `rotulo()`.
- Serviços em `app/Services/Tempos`, componentes Livewire em `app/Livewire/<Área>`.
- Permissões: papel do portal (`acessos.papel`) + Gates `tempos-*` no `AppServiceProvider`.
- Auditoria: `App\Services\Auditor::registar()` → tabela `auditoria` da Nexus Infra.
- Testes: Feature tests em PostgreSQL (`tempos_testing`), sem factories — `Model::create` e
  auxiliares no `Tests\TestCase`.
- Commits `Área: descrição` em PT-PT, com entrada no `CHANGELOG.md`. Sem push sem pedido.
