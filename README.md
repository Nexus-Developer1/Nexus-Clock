# Nexus Tempos

Registo de horas dos técnicos da Nexus Solutions, ligado a cliente, contrato e intervenção:
horas incluídas por contrato, excedentes, tarifas, margem e export de faturação (CSV + PDF)
para lançamento manual no ERP.

Especificação: [`docs/modulo-tempos.md`](docs/modulo-tempos.md). Adaptações e decisões tomadas
durante a implementação: [`docs/modulo-tempos-notas.md`](docs/modulo-tempos-notas.md).

## Faz parte da suite

Tal como a Knowledgebase, esta aplicação **não tem login próprio**: a entrada é feita no
**Nexus Portal**, que decide quem pode abrir cada aplicação.

- **Sessão partilhada:** `APP_KEY`, `SESSION_COOKIE`, `SESSION_STORE=sessao`,
  `SESSION_SERIALIZATION=php` e `REDIS_PREFIX` têm de coincidir com as das outras aplicações.
- **Acesso:** a aplicação regista-se no portal com a chave `tempos` (tabela `aplicacoes`). O papel
  de cada pessoa aqui dentro — `admin` ou `tecnico` — é o `acessos.papel` dessa linha.
- **Base de dados:** a MESMA da Nexus Infra. As tabelas dos tempos são próprias e têm registo de
  migrações separado (`migrations_tempos`). As tabelas da Nexus Infra (`utilizadores`, `clientes`,
  `contratos`, `intervencoes`…) são só lidas — nunca se lhes altera a estrutura.
- **Sem PHC:** não lê nem escreve nada do ERP. A faturação sai como export.

> ⚠️ **Nunca correr `migrate:fresh`, `migrate:refresh` ou `db:wipe` contra a base partilhada** —
> apagava todas as tabelas da Nexus Infra e do portal. A aplicação recusa-se a fazê-lo fora das
> bases `tempos_dev` e `tempos_testing`.

## Trabalhar nisto

Precisa de PHP 8.3 com `pdo_pgsql` e de um PostgreSQL local com duas bases vazias:
`tempos_dev` (desenvolvimento) e `tempos_testing` (testes).

```bash
php composer.phar install
cp .env.example .env        # ajustar DB_* para tempos_dev, SESSION_DRIVER=file
php artisan key:generate
php artisan migrate:fresh --seed
php artisan test
```

A migração `0001_01_01_000000_garantir_tabelas_partilhadas` cria as tabelas da Nexus Infra e do
portal só quando não existem (bases vazias de desenvolvimento e testes). Em produção não faz nada.

## Instalar no servidor

1. Copiar para `/var/www/nexus-tempos`, `php composer.phar install --no-dev -o`.
2. `.env` a partir do `.env.example`, com as definições de sessão iguais às do portal e
   `DB_DATABASE` a base da Nexus Infra.
3. `php artisan migrate --force` (só cria as tabelas dos tempos).
4. No portal: registar a aplicação com a chave `tempos` e dar acesso às pessoas (papel `admin`
   ou `tecnico`).
5. Scheduler (`php artisan schedule:run` no cron) — refresca o consumo dos contratos de noite.
