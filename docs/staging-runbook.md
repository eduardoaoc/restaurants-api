# Runbook de staging — AFORO (API + Web)

Versão: 2026-10-25. Procedimento para o **primeiro** deploy de staging e para
os seguintes. Nada aqui foi executado contra servidor, DNS, Supabase, Meta ou
SMTP reais: ver `docs/staging-whatsapp-readiness-report.md` para o que está
pronto, pendente e bloqueado.

Arquivos de apoio (revisáveis, sem segredos):

| Arquivo | Uso |
|---|---|
| `.env.staging.example` (API) | modelo de `shared/.env` do servidor |
| `restaurants-web/.env.staging.example` | modelo de `.env.staging` da máquina de build do SPA |
| `deploy/staging/nginx-api.conf` | API + Reverb (wss) no mesmo host |
| `restaurants-web/deploy/staging/nginx-web.conf` | SPA estático com CSP e fallback do router |
| `deploy/staging/supervisor-aforo-staging.conf` | Reverb + 1 worker por fila, usuário `aforo` |
| `deploy/staging/deploy.sh` | deploy por releases, rollback e status |
| `scripts/restore-test.sh` | teste de restauração de backup em contêiner descartável |
| `php artisan aforo:deploy-check` | gate de configuração (nomes, nunca valores) |
| `php artisan aforo:queue-status` | tamanho/idade das filas, sem payloads |

## 1. Pressupostos

- **Um servidor Linux com root/sudo** (VPS). Reverb e workers precisam de
  processos persistentes: hospedagem compartilhada sem supervisor/SSH **não**
  serve. Os documentos anteriores citam Hostinger; o tipo de plano não está
  registrado → pergunta pendente.
- PHP ≥ 8.4.1 com `pdo_pgsql`, `bcmath`, `intl`, `gd`, `mbstring`, `xml`,
  `curl`, `zip`, `opcache`; Composer 2; nginx; supervisor; certbot. Node 24
  apenas na máquina que gera o build do SPA (pode ser a sua).
- Banco: **projeto Supabase de staging** (separado de qualquer produção),
  pooler de sessão `:5432`, `DB_SSLMODE=require`.
- Drivers de sessão/cache/fila: **database** (contrato
  `docs/staging-production-contract.md`). O modelo antigo usava Redis; não há
  Redis provisionado nem segunda instância, então não foi mantido (cópia do
  modelo anterior em `deploy/staging/env.staging.example.previous`).

## 2. Topologia, DNS e certificados

```
navegador ──https──► WEB_HOST  (nginx: dist/ do SPA)
          ──https──► API_HOST  (nginx ─FastCGI─► PHP-FPM pool "aforo-staging")
          ──wss────► API_HOST/app/{key} (nginx ─► Reverb 127.0.0.1:8081)
PHP (broadcast) ─https─► API_HOST/apps/{id}/events ─► Reverb
PHP/workers ─TLS─► Supabase pooler :5432
Meta ──https──► API_HOST/api/v1/webhooks/whatsapp   (só quando WhatsApp for ativado)
```

- `API_HOST` = `api-staging.conejocreative.com` (já presente no modelo
  anterior). `WEB_HOST` = **a definir** (sugestão: `app-staging.conejocreative.com`,
  mesmo domínio pai → `SESSION_DOMAIN=.conejocreative.com`).
- Reverb usa o **mesmo host da API** (caminhos `/app` e `/apps`): um nome DNS e
  um certificado a menos. Se preferir host próprio, troque `REVERB_HOST`,
  `VITE_REVERB_HOST` e crie outro server block com o mesmo `location`.
- DNS (você/provedor): registros `A`/`AAAA` de `API_HOST` e `WEB_HOST` para o
  IP do servidor. Se usar Cloudflare com proxy laranja, `TRUSTED_PROXIES`
  passa a ser obrigatório (faixas da Cloudflare) e WebSocket precisa estar
  ativo no painel — prefira **DNS only** em staging.
- Certificados: Let's Encrypt via `certbot certonly --webroot -w
  /var/www/letsencrypt -d <host>` para cada host (o server block :80 já
  serve o desafio). Renovação automática do pacote certbot + `systemctl
  reload nginx` no hook.
- Mesmo-site: `WEB_HOST` e `API_HOST` precisam compartilhar o domínio
  registrável (cookies `SameSite=lax` + leitura do `XSRF-TOKEN`).

## 3. Preparação do servidor (uma vez)

### 3.1 Usuário e diretórios

```bash
sudo adduser --system --group --home /srv/aforo-staging --shell /bin/bash aforo
sudo -u aforo mkdir -p /srv/aforo-staging/api/{releases,shared/storage/{app/public,framework/{cache/data,sessions,views},logs},shared/deploy-logs} \
                      /srv/aforo-staging/web/releases
sudo -u aforo git clone --mirror <URL-do-repositório-da-API> /srv/aforo-staging/api/repo.git
sudo chmod 750 /srv/aforo-staging /srv/aforo-staging/api/shared
```

`shared/storage` guarda uploads (`app/public`), logs e a caixa de captura de
e-mail: **persiste entre releases** e nunca fica sob `public/`.

### 3.2 `.env` e APP_KEY

```bash
sudo -u aforo cp .env.staging.example /srv/aforo-staging/api/shared/.env   # a partir de um checkout
sudo chmod 600 /srv/aforo-staging/api/shared/.env
# APP_KEY: gerar UMA vez, guardar no cofre de segredos, colar no .env:
php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

Preencha todo `CHANGE_ME` (o deploy recusa enquanto houver um). A APP_KEY de
staging é **nova**, nunca a local. Trocar a APP_KEY invalida sessões, jobs
criptografados e os telefones WhatsApp cifrados (ver
`docs/whatsapp-daily-close.md`, "key rotation").

### 3.3 PHP-FPM (pool dedicado)

`/etc/php/8.4/fpm/pool.d/aforo-staging.conf`:

```ini
[aforo-staging]
user = aforo
group = aforo
listen = /run/php/aforo-staging.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 8
php_admin_value[expose_php] = Off
php_admin_value[zend.exception_ignore_args] = On
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 2
```

`pm.max_children` + 3 workers + margem deve caber no limite de conexões do
pooler Supabase do plano. `zend.exception_ignore_args=On` também no
`php.ini` do **CLI** (workers). Com `$realpath_root` no nginx e
`validate_timestamps=1`, a troca do symlink `current` não exige reload do FPM.

### 3.4 Supervisor

```bash
sudo cp deploy/staging/supervisor-aforo-staging.conf /etc/supervisor/conf.d/aforo-staging.conf
sudo supervisorctl reread && sudo supervisorctl update aforo-staging
# sudoers mínimo para o deploy reiniciar SÓ o grupo (visudo -f /etc/sudoers.d/aforo-staging):
#   aforo ALL=(root) NOPASSWD: /usr/bin/supervisorctl restart aforo-staging\:*
```

Processos: `reverb` (127.0.0.1:8081), `realtime`, `mail`, `whatsapp` — todos
como `aforo`. Não há worker na fila `default` (nenhum job deve cair nela; o
`aforo:queue-status` mostra se cair). **Scheduler não é necessário**: não há
tarefas agendadas (`routes/console.php` sem `Schedule`); sessões expiram pela
loteria do driver database.

### 3.5 nginx

```bash
sudo cp deploy/staging/nginx-api.conf /etc/nginx/sites-available/aforo-api-staging.conf
sudo cp <restaurants-web>/deploy/staging/nginx-web.conf /etc/nginx/sites-available/aforo-web-staging.conf
# substituir CHANGE_ME_WEB_HOST; ln -s nos sites-enabled; antes dos certificados,
# comente os blocos :443 e emita os certificados (§2); depois:
sudo nginx -t && sudo systemctl reload nginx
```

CSP do SPA: o hash do `<script>` inline do `index.html` está no arquivo. Se o
`index.html` mudar, recalcule sobre o `dist/index.html` gerado:

```bash
python3 -c "import re,hashlib,base64;h=open('dist/index.html').read();[print('sha256-'+base64.b64encode(hashlib.sha256(m.encode()).digest()).decode()) for m in re.findall(r'<script>(.*?)</script>',h,re.S)]"
```

### 3.6 Firewall

Abertas: 22 (de preferência restrita), 80, 443. **Fechadas**: 8081 (Reverb),
5432 local, qualquer Redis. O banco é o Supabase (remoto, TLS); não exponha
Postgres/Redis no servidor.

### 3.7 Supabase (staging)

1. Projeto **separado** para staging; região próxima do servidor.
2. Roles: migração (dona dos objetos) e runtime (DML apenas), conforme
   `docs/pr-sec1a-2-db-runtime-privilege-plan.md`. Enquanto esse plano não for
   validado, documente qual role o runtime usa — não use a senha do
   `postgres` no `.env` sem registrar o risco.
3. **Data API/PostgREST**: as tabelas do Laravel ficam no schema `public`.
   Desative a Data API do projeto **ou** remova `public` dos schemas expostos
   **ou** ative RLS em todas as tabelas. Sem isso, a chave `anon` pública do
   projeto pode ler/escrever as tabelas pela REST do Supabase. **Obrigatório
   antes do primeiro dado.**
4. Backups: confira o que o plano oferece (diário/PITR). Antes de cada
   deploy com migration, faça um dump próprio (§7).

## 4. Deploy

### 4.1 Pré-condições

- O código a publicar está **commitado** num ref (branch/tag/commit): o
  script exporta `git archive <ref>`; mudanças locais não commitadas **não**
  vão para o servidor. (Em 2026-10-25 todo o trabalho de convites,
  recuperação de senha, turnos e esta preparação ainda não está commitado.)
- `php artisan aforo:deploy-check` sem FAIL com o `.env` final.

### 4.2 API

```bash
sudo -u aforo HEALTH_URL=https://api-staging.conejocreative.com/up \
  /srv/aforo-staging/api/current/deploy/staging/deploy.sh deploy <ref> --dry-run   # revisar o plano
# primeiro deploy: o script ainda não existe em current → rode-o de um checkout do mesmo ref
sudo -u aforo HEALTH_URL=... deploy/staging/deploy.sh deploy <ref> --migrate --provision
```

Sequência executada (ver o script): preflight (não-root, `.env` 600,
`APP_ENV=staging`, `APP_DEBUG=false`, APP_KEY presente, sem `CHANGE_ME`, PHP)
→ `git archive` em `releases/<id>` → links `storage`/`.env` compartilhados →
`composer install --no-dev` + `check-platform-reqs` → `config/route/event/view:cache`
→ **`aforo:deploy-check`** → `migrate:status`; com migrations pendentes
aborta, a menos de `--migrate` (salva `migrate --pretend` em
`shared/deploy-logs/` e aplica com `--force`) → `aforo:provision` só com
`--provision` (papéis/permissões, aditivo, sem usuários/demonstração) →
`storage:link` → troca atômica de `current` → `supervisorctl restart
aforo-staging:*` → `/up` + `aforo:queue-status` → mantém 5 releases.

Inventário de migrations em 2026-10-25: **77 arquivos** em
`database/migrations` (última `2026_10_23_000002_add_onboarding_idempotency_to_restaurants_table`).
Num banco de staging vazio, todas as 77 rodam no primeiro deploy. Todas as
desta fase são aditivas; confira de novo com `migrate:status` antes de cada
deploy — não reutilize esta lista.

Nenhum dado real ou de demonstração é provisionado. O primeiro Super Admin
de plataforma precisa de uma ação explícita sua (pergunta pendente: não
existe comando de criação de Super Admin para staging — `PlatformAdminDevSeeder`
recusa staging/produção por desenho).

### 4.3 Web

```bash
# máquina de build
cp .env.staging.example .env.staging   # preencher; o build recusa CHANGE_ME/http/localhost
npm ci && npm run build:staging        # vue-tsc + vite --mode staging
rsync -a --delete dist/ aforo@<servidor>:/srv/aforo-staging/web/releases/<id>/
ssh aforo@<servidor> 'ln -sfn /srv/aforo-staging/web/releases/<id> /srv/aforo-staging/web/current.next && mv -Tf /srv/aforo-staging/web/current.next /srv/aforo-staging/web/current'
```

Ordem quando ambos mudam: **API primeiro** (aditiva), depois Web.

## 5. Rollback

```bash
sudo -u aforo /srv/aforo-staging/api/current/deploy/staging/deploy.sh rollback
```

Volta o `current` para o release anterior, refaz os caches com o `.env`
atual, roda o `aforo:deploy-check`, reinicia só o grupo do supervisor.
**Só código**: nunca roda `migrate:rollback`. É seguro porque as migrations
desta fase são aditivas (tabelas/colunas novas que o código anterior
ignora). Uma futura migration destrutiva (drop/rename/alteração de tipo)
precisa de expand → migrate → contract em deploys separados, ou de restore
do dump (§7) — decida antes de publicá-la. Web: aponte `current` para o
release anterior (mesmo comando `ln -sfn … && mv -Tf …`).

## 6. E-mail

- Modo A (padrão do modelo): `MAIL_MAILER=log` + `MAIL_LOG_CHANNEL=mail_capture`
  → `shared/storage/logs/mail-capture.log`, permissão 0600, fora de `public/`.
  **Nada é entregue**; links de convite/reset só por SSH
  (`grep -o 'https://<WEB_HOST>/[a-z-]*#[^" ]*' mail-capture.log | tail -1`).
  O `laravel.log` nunca recebe corpo de e-mail.
- Modo B (SMTP real): escolher provedor, configurar SPF + DKIM (+ DMARC) do
  domínio remetente conforme o painel dele, preencher o bloco SMTP do `.env`,
  `config:cache` e reiniciar o grupo.
- Estados distintos: **enfileirado** (job na fila `mail`) → **aceito pelo
  transporte** (UI: "Entregada al servidor de correo" = SMTP respondeu 250 /
  ou gravado na captura) → **recebido** (só confirmável na caixa de entrada;
  o sistema não sabe).

## 7. Backup e restauração

- Antes de cada deploy com migration: `pg_dump -Fc --no-owner` do banco de
  staging para fora do servidor web, com permissão 600.
- Teste de restauração **sempre em banco descartável**:
  `scripts/restore-test.sh <dump>` sobe um Postgres 18 sem rede e sem portas,
  confere o destino, restaura, conta tabelas e remove o contêiner. Nunca
  restaure sobre o banco habitual nem sobre staging sem conferir
  `current_database()`/host antes.

## 8. Diagnóstico

```bash
php artisan aforo:deploy-check                       # configuração efetiva (sem valores)
php artisan aforo:queue-status                       # pending/delayed/reserved/idade + failed_jobs por fila
php artisan aforo:queue-status --queue=whatsapp --max-age=300   # exit 1 se parado
curl -fsS https://<API_HOST>/up                      # saúde HTTP
sudo supervisorctl status 'aforo-staging:*'
```

Nunca use `queue:flush`, `queue:clear`, `queue:retry all` nem `pkill` como
diagnóstico. Falhas WhatsApp aparecem na tela do Cierre e em
`restaurant_day_close_deliveries` (código sanitizado, telefone mascarado).
