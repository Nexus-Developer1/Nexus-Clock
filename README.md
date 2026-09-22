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

Para ver a aplicação localmente (sem o portal a correr):

```bash
php artisan serve --port=8001
# abrir http://localhost:8001/dev/entrar e escolher uma pessoa do seeder
```

A rota `/dev/entrar` só existe com `APP_ENV=local` numa base descartável.

**CSS:** Tailwind 3 com os tokens da Nexus Infra, compilado para `public/css/app.css`, que vai no
repositório. Depois de mexer em vistas: `npm install` (uma vez) e `npm run css`.

## Instalar no servidor

Corre em `infra.nexus-solutions.pt` em `/tempos`, ao lado do portal e do Knowledgebase, com o
utilizador `app-tempos`, pool PHP-FPM próprio e a mesma base da Nexus Infra. Tudo está no script
idempotente `deploy/instalar.sh` (primeira instalação e atualizações):

```bash
git archive --format=tar.gz -o nexus-tempos.tar.gz HEAD
scp nexus-tempos.tar.gz deploy/instalar.sh deploy/portal.sql dev@192.168.1.69:
ssh dev@192.168.1.69 sudo bash instalar.sh
```

Na primeira vez, registar a aplicação no portal e dar acesso (admin/técnico, copiado da Nexus IFE):
`sudo -u postgres psql -d nexus_ops -f portal.sql`. O `.env` é gerado a partir do da Nexus Ops
(base, sessão, Redis, email) e depois mantido; o worker é o serviço `nexus-tempos-worker` e o
scheduler o cron do `app-tempos`.
