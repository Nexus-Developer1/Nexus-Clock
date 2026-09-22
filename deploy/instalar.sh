#!/usr/bin/env bash
# Instala ou atualiza o Nexus Suporte no servidor da suite (infra.nexus-solutions.pt), em /tempos,
# ao lado do portal e do Knowledgebase. Idempotente: corre-se na primeira instalação e em cada
# atualização.
#
#   1. Na máquina de desenvolvimento:  git archive --format=tar.gz -o nexus-tempos.tar.gz HEAD
#   2. Copiar para o servidor:          scp nexus-tempos.tar.gz deploy/instalar.sh deploy/portal.sql dev@192.168.1.69:
#   3. No servidor:                     sudo bash instalar.sh
#   (se o portal.sql estiver ao lado do pacote, corre no fim; pode repetir-se sem duplicar)
#
# O que faz: extrai o código, composer install, utilizador app-tempos + pool PHP-FPM + Apache
# (/tempos dentro do vhost da Nexus Ops), .env a partir do da Nexus Ops (base, sessão, Redis,
# email — os segredos nunca saem do servidor), migrate --force (só tabelas dos Tempos), caches,
# worker da fila (systemd) e scheduler (cron). Nunca toca nas tabelas da Nexus Infra.
set -euo pipefail

APP=/var/www/nexus-tempos
PACOTE=${1:-/home/dev/nexus-tempos.tar.gz}
ORIG=/var/www/nexus-ops/.env
UTIL=app-tempos
URL=https://infra.nexus-solutions.pt:9443/tempos
PORTAL=https://infra.nexus-solutions.pt:9443/portal

[ "$(id -u)" = 0 ] || { echo "Correr com sudo."; exit 1; }
[ -f "$PACOTE" ] || { echo "Falta o pacote $PACOTE (git archive HEAD)."; exit 1; }

passo() { echo; echo "== $*"; }

# ---------------------------------------------------------------- código
passo "Código para $APP"
mkdir -p "$APP"
tar -xzf "$PACOTE" -C "$APP"
mkdir -p "$APP"/storage/app/public "$APP"/storage/framework/cache/data "$APP"/storage/framework/sessions \
         "$APP"/storage/framework/views "$APP"/storage/logs "$APP"/bootstrap/cache
cd "$APP"
COMPOSER_HOME=/tmp/composer-tempos COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev -o --no-interaction --no-progress 2>&1 | tail -2
# Ficheiros do Livewire em public/vendor/livewire: assim o script é servido por baixo de /tempos e
# não pela raiz do host (onde dá 404). O endereço dos pedidos trata-o App\Support\LivewireSubpasta.
php artisan livewire:publish --assets -n >/dev/null && echo "livewire: ficheiros publicados"

# ---------------------------------------------------------------- utilizador
passo "Utilizador $UTIL"
if ! getent passwd "$UTIL" >/dev/null; then
    useradd --system --home-dir "$APP" --shell /usr/sbin/nologin "$UTIL"
fi
mkdir -p /var/lib/nexus-apps/$UTIL
chown $UTIL:$UTIL /var/lib/nexus-apps/$UTIL; chmod 700 /var/lib/nexus-apps/$UTIL

# ---------------------------------------------------------------- .env
if [ ! -f .env ]; then
    passo ".env a partir do da Nexus Ops"
    cp .env.example .env
    for k in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
             SESSION_DRIVER SESSION_STORE SESSION_SERIALIZATION SESSION_COOKIE SESSION_LIFETIME SESSION_ENCRYPT \
             SESSION_SECURE_COOKIE SESSION_PATH SESSION_DOMAIN \
             REDIS_CLIENT REDIS_HOST REDIS_PASSWORD REDIS_PORT REDIS_PREFIX CACHE_STORE QUEUE_CONNECTION \
             MAIL_MAILER MAIL_FROM_ADDRESS MAIL_FROM_NAME MS_GRAPH_TENANT_ID MS_GRAPH_CLIENT_ID MS_GRAPH_CLIENT_SECRET MS_GRAPH_SENDER; do
        linha=$(grep -E "^$k=" "$ORIG" | head -1 || true)
        [ -n "$linha" ] || continue
        if grep -qE "^$k=" .env; then
            python3 - "$k" "$linha" <<'PY'
import re, sys
k, linha = sys.argv[1], sys.argv[2]
s = open('.env', encoding='utf-8').read()
s = re.sub(r'(?m)^' + re.escape(k) + r'=.*$', lambda m: linha, s)
open('.env', 'w', encoding='utf-8').write(s)
PY
        else
            echo "$linha" >> .env
        fi
    done
    sed -i "s#^APP_URL=.*#APP_URL=$URL#; s#^PORTAL_URL=.*#PORTAL_URL=$PORTAL#; s#^APP_ENV=.*#APP_ENV=production#; s#^APP_DEBUG=.*#APP_DEBUG=false#" .env
    php artisan key:generate --force -n | tail -1
else
    passo ".env já existe — mantido"
fi

# ---------------------------------------------------------------- permissões
passo "Permissões"
chown -R $UTIL:www-data "$APP"
find "$APP" -type d -exec chmod 750 {} +
find "$APP" -type f -exec chmod 640 {} +
chmod -R g+w "$APP/storage" "$APP/bootstrap/cache"
chmod 640 .env

# ---------------------------------------------------------------- php-fpm
passo "Pool PHP-FPM nexus-tempos"
cat > /etc/php/8.3/fpm/pool.d/nexus-tempos.conf <<EOF
[nexus-tempos]
user = $UTIL
group = $UTIL
listen = /run/php/nexus-tempos.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
pm.max_requests = 500
php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 16M
php_admin_value[error_log] = /var/log/php8.3-fpm-nexus-tempos.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_value[session.save_path] = $APP/storage/framework/sessions
EOF
php-fpm8.3 -t 2>&1 | tail -1

# ---------------------------------------------------------------- apache
passo "Apache: /tempos no vhost da Nexus Ops"
if ! grep -q "nexus-tempos" /etc/apache2/conf-available/fpm-internas.conf; then
cat >> /etc/apache2/conf-available/fpm-internas.conf <<EOF

<Directory "$APP/public">
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/nexus-tempos.sock|fcgi://localhost"
    </FilesMatch>
</Directory>
EOF
fi

CONF=/etc/apache2/sites-available/nexus-ops.conf
if ! grep -q "Alias /tempos" "$CONF"; then
    cp "$CONF" "$CONF.antes-tempos-$(date +%Y%m%d)"
    python3 - "$CONF" "$APP" <<'PY'
import sys
p, app = sys.argv[1], sys.argv[2]
s = open(p, encoding='utf-8').read()
bloco = f'''    # ---- Nexus Suporte (registo de horas) ----
    Alias /tempos {app}/public
    <Directory "{app}/public">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        RewriteEngine On
        RewriteBase /tempos
        RewriteRule ^$ index.php [L]
        RewriteCond %{{HTTP:Authorization}} .
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{{HTTP:Authorization}}]
        RewriteCond %{{REQUEST_FILENAME}} !-d
        RewriteCond %{{REQUEST_FILENAME}} !-f
        RewriteRule ^ index.php [L]
    </Directory>
    <Directory "{app}">
        Require all denied
    </Directory>

'''
marca = '    Alias /knowledgebase-nexus'
assert marca in s, 'marca do knowledgebase não encontrada no vhost'
open(p, 'w', encoding='utf-8').write(s.replace(marca, bloco + marca, 1))
print('bloco /tempos inserido antes do knowledgebase')
PY
fi
# Instalações feitas antes desta linha existir: acrescenta-a ao bloco /tempos (e só a esse).
if grep -q "Alias /tempos" "$CONF" && ! grep -q 'RewriteRule \^\$ index.php' "$CONF"; then
    python3 - "$CONF" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
s = s.replace('        RewriteBase /tempos\n', '        DirectoryIndex index.php\n        RewriteBase /tempos\n        RewriteRule ^$ index.php [L]\n', 1)
open(p, 'w', encoding='utf-8').write(s)
print('vhost: a raiz /tempos/ passa a ir ao index.php')
PY
fi
apache2ctl configtest 2>&1 | tail -1
php-fpm8.3 -t >/dev/null 2>&1 && apache2ctl configtest >/dev/null 2>&1 || { echo "Configuração inválida: nada recarregado."; exit 1; }
systemctl reload php8.3-fpm
systemctl reload apache2

# ---------------------------------------------------------------- base de dados
passo "Migrações (só tabelas dos Tempos)"
sudo -u $UTIL HOME=/var/lib/nexus-apps/$UTIL php artisan migrate --force -n 2>&1 | tail -15

passo "Caches"
# Sem route:cache: com as rotas em cache o Laravel tira a barra final ao REQUEST_URI e, numa
# instalação por subpasta (/tempos), deixa de reconhecer a raiz — dá 405 em /tempos/.
sudo -u $UTIL HOME=/var/lib/nexus-apps/$UTIL php artisan optimize:clear -n >/dev/null
for c in config:cache event:cache view:cache; do
    sudo -u $UTIL HOME=/var/lib/nexus-apps/$UTIL php artisan $c -n | tail -1
done

# ---------------------------------------------------------------- worker e scheduler
passo "Worker da fila e scheduler"
cat > /etc/systemd/system/nexus-tempos-worker.service <<EOF
[Unit]
Description=Nexus Suporte Queue Worker
After=network.target redis-server.service

[Service]
User=$UTIL
Group=$UTIL
Environment=HOME=/var/lib/nexus-apps/$UTIL
UMask=0027
Restart=always
RestartSec=5
ExecStart=/usr/bin/php $APP/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600
StandardOutput=append:/var/log/nexus-tempos-worker.log
StandardError=append:/var/log/nexus-tempos-worker.log

[Install]
WantedBy=multi-user.target
EOF
touch /var/log/nexus-tempos-worker.log; chown $UTIL:$UTIL /var/log/nexus-tempos-worker.log
systemctl daemon-reload
systemctl enable -q nexus-tempos-worker
systemctl restart nexus-tempos-worker
echo "* * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1" | crontab -u $UTIL -
systemctl is-active nexus-tempos-worker

# ---------------------------------------------------------------- teste
passo "Teste"
codigo=$(curl -sk -o /dev/null -w '%{http_code}' -H 'Host: infra.nexus-solutions.pt' https://127.0.0.1/tempos/)
echo "GET /tempos/ -> HTTP $codigo (esperado 302 para o portal, sem sessão)"
[ "$codigo" = 302 ] || echo "ATENÇÃO: a raiz não respondeu 302."

# ---------------------------------------------------------------- portal
SQL=$(dirname "$PACOTE")/portal.sql
if [ -f "$SQL" ]; then
    passo "Portal: aplicação tempos e acessos"
    sudo -u postgres psql -d nexus_ops -v ON_ERROR_STOP=1 < "$SQL"
fi
echo
echo "Feito."
