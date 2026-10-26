# Preparação de staging + auditoria WhatsApp/Meta — relatório

Data: 2026-10-25. API `fix/security-hardening` (HEAD `c195492`), Web `main`
(HEAD `9ee3d46`). **Sem commit, push ou deploy. Nenhum servidor, DNS,
Supabase, Meta ou SMTP tocado. Nenhum e-mail ou WhatsApp enviado. Nenhuma
migration/provisioning no banco habitual. Sessão #490 intocada.**

Documentos desta entrega: `docs/staging-runbook.md` (procedimento),
`docs/whatsapp-meta-audit.md` (auditoria completa), este relatório.

## 1. Estado atual e bloqueios

Recuperado de código, Git, banco local (somente leitura) e relatórios:

- **Git**: todo o trabalho desde `c195492`/`9ee3d46` (convites, recuperação
  de senha, organização ativa, turnos pela «Equipo», reconcile de realtime)
  continua **não commitado** — API 19 arquivos rastreados alterados + ~40
  não rastreados; Web 20 + ~20. O deploy por `git archive` só publica refs
  commitados → **bloqueio**: commit/branch de staging é decisão sua.
- **Ambiente local**: Docker no ar; só `artisan serve` rodando (Reverb e
  workers **parados**, apesar de 8081 aceitar conexão pelo mapeamento do
  Docker); Vite em 5174. Filas: `default` 284 (backlog antigo, intocado),
  `realtime-local` **14 pendentes desde 2026-10-23 21:27** (worker parado),
  `failed_jobs` 1. 77 migrations = 77 aplicadas. Portas 8080/8081 escutando em
  `*` (provável `SAIL_BIND_ADDRESS=0.0.0.0` para o teste no telefone) — volte
  para loopback quando terminar.
- **Rodada manual**: confirmações do usuário registradas no checklist (seção
  "Resultados registrados"); demais cenários PENDENTE.
- **Staging pré-existente**: só `.env.staging.example` (não rastreado) e o
  contrato `docs/staging-production-contract.md`. Não havia nginx,
  supervisor, script de deploy, config de hospedagem do SPA nem runbook.
  Decisões encontradas e mantidas: Supabase (pooler `eu-west-2`, sessão
  `:5432`), `api-staging.conejocreative.com`, Reverb, banco PostgreSQL.
- **Bloqueios externos**: tipo de hospedagem (VPS?), `WEB_HOST`, DNS,
  certificados, projeto Supabase de staging, provedor SMTP, conta Meta,
  primeiro Super Admin (§6–7).

## 2. Correções e arquivos

### Problemas encontrados (com evidência)

| # | Problema | Efeito | Correção |
|---|---|---|---|
| 1 | `.env.staging.example` com `SESSION_DOMAIN=null` e SPA/API em hosts diferentes | o SPA não lê `XSRF-TOKEN` (axios `withXSRFToken`) → todo POST, inclusive login, falharia com 419 | `SESSION_DOMAIN=.<pai>` + `SESSION_COOKIE` próprio; gate no `deploy-check` |
| 2 | `SANCTUM_STATEFUL_DOMAINS` ausente → default do Sanctum com `localhost`, `127.0.0.1` em qualquer ambiente | reaproveitamento silencioso de config local | default só em local/testing (`config/sanctum.php`) |
| 3 | `REVERB_ALLOWED_ORIGINS` ausente → `localhost,127.0.0.1` em qualquer ambiente | idem | default só em local/testing; fora disso nega tudo (`config/reverb.php`) |
| 4 | Reverb em `0.0.0.0:8081` no modelo de staging | WS sem TLS exposto se o firewall falhar | `127.0.0.1` atrás do nginx (`/app`, `/apps`) |
| 5 | Modelo de staging com `MAIL_MAILER=log` sem canal | links de convite/reset no `laravel.log` comum | captura protegida `mail_capture` (0600) ou SMTP; `deploy-check` FAIL se cair no log comum |
| 6 | Modelo com Redis para sessão/cache/fila, contra o contrato versionado (database) e sem Redis provisionado | serviço extra sem decisão | database, conforme contrato; anterior salvo em `deploy/staging/env.staging.example.previous` |
| 7 | Job WhatsApp na fila `default`, sem `timeout` próprio | worker compartilhado com backlog/broadcasts; com timeout ≥ `retry_after` o job poderia ir a um 2º worker durante a chamada | `WHATSAPP_QUEUE`; `timeout=30`, `failOnTimeout=true`; `deploy-check` exige `retry_after` > 60 |
| 8 | Build de staging aceitava `CHANGE_ME_*` (host sintaticamente válido) | SPA publicado apontando para placeholder | `src/config/env.ts` recusa `CHANGE_ME` em staging/produção |
| 9 | `.env.staging`/`.env.production` do Web não eram ignorados pelo Git | domínios reais commitados por engano | `.gitignore` |
| 10 | Modelo antigo pedia `TRUSTED_PROXIES` | com nginx→FastCGI no mesmo host não há salto HTTP; confiar em proxy seria desnecessário | vazio por padrão, documentado; nginx zera `X-Forwarded-*` vindos do cliente |

Nenhum segredo versionado encontrado (arquivos rastreados, não rastreados,
histórico completo dos dois repositórios e `dist/`): os únicos acertos de
padrão são documentação de padrões, uma APP_KEY gerada por `openssl` num
comando de exemplo e um valor fictício de teste. Nenhum `.env` real no
histórico. `dist/` local (ignorado) contém só a chave **pública** local do
Reverb. **Nenhuma rotação necessária.**

### API — `restaurants-api`

Alterados (rastreados): `config/sanctum.php`, `config/reverb.php`,
`config/whatsapp.php`, `config/logging.php` (comentário; o canal
`mail_capture` já estava no diff), `app/Jobs/SendDayCloseWhatsApp.php`,
`app/Support/WhatsApp/DayCloseDeliveryService.php`, `.env.example`
(+`WHATSAPP_QUEUE` comentado), `docs/staging-production-contract.md`,
`docs/whatsapp-daily-close.md`, `tests/Feature/Configuration/EnvironmentContractTest.php`,
`tests/Feature/WhatsApp/DayCloseWhatsAppDeliveryTest.php`.

Não rastreados (novos ou já não rastreados): `.env.staging.example`
(reescrito), `app/Console/Commands/DeployCheck.php`,
`app/Console/Commands/QueueStatus.php`, `deploy/staging/{nginx-api.conf,
supervisor-aforo-staging.conf, deploy.sh, env.staging.example.previous}`,
`scripts/restore-test.sh`, `tests/Feature/Configuration/{DeployCheckTest,
QueueStatusTest}.php`, `docs/{staging-runbook,whatsapp-meta-audit,
staging-whatsapp-readiness-report}.md`, `docs/manual-checklist-local.md`
(resultados registrados).

### Web — `restaurants-web`

Alterados: `src/config/env.ts` (+3 linhas), `tests/frontend-hardening-build.mjs`
(+2 casos negativos, contagem dinâmica), `.gitignore`. Novos:
`.env.staging.example`, `deploy/staging/nginx-web.conf`. Finais de linha
originais (CRLF/mistos) preservados.

## 3. Evidências e testes

| Verificação | Resultado |
|---|---|
| `tests/Feature/Configuration` + `tests/Feature/WhatsApp` (Sail, banco `testing`, storage isolado) | 112 testes: WhatsApp 66/66; configuração 46/46 após ajuste do fixture (`example.org` é domínio reservado e é corretamente recusado) |
| Suíte backend completa (sequencial, Sail) | **2202/2202** (§3.1) |
| Pint `--test` nos 11 arquivos PHP tocados | ok (1 import corrigido no teste novo) |
| `aforo:deploy-check` contra o `.env` **local** | exit 1, 23 FAIL/9 WARN esperados; saída sem nenhum valor (conferido por grep) |
| `vue-tsc -b` | ok |
| `tests/frontend-hardening-build.mjs` | 18 builds inválidos recusados (incl. `CHANGE_ME`) + 2 builds HTTPS/WSS válidos; sem `.map`, sem segredos/origens locais |
| Build de staging com hosts reais em diretório temporário | ok; só o fallback inerte `http://localhost` do fornecedor e a regex do validador; sem chave local; hash CSP calculado |
| `deploy.sh` em raiz simulada (espelho do repo local) | recusa: sem `.env`, modo ≠ 600, APP_KEY vazia, `APP_ENV=local`, `APP_DEBUG=true`, `CHANGE_ME`, ref inexistente; `--dry-run` mostra a sequência completa; rollback real com stubs troca o symlink e recusa sem release anterior |
| `bash -n` nos scripts | ok (shellcheck indisponível) |
| **Restauração do backup** `laravel-local-pre-activation-20261022-112551.dump` | **OK** em contêiner descartável `postgres:18-alpine`, `--network none`, 0 portas, banco `restore_test` conferido antes: 57/57 tabelas, 74 migrations (backup anterior às 3 últimas), SHA-256 igual ao registrado; contêiner removido |

Não validado de verdade (não declarar funcionando): HTTPS, WSS externo,
nginx/supervisor/PHP-FPM reais (configs só revisadas, `nginx -t` não
disponível aqui), entrega SMTP, qualquer chamada à Meta, Supabase.

### 3.1 Suíte backend completa

`scripts/test-sail.sh` (código final desta tarefa): **OK — 2202 testes,
9638 assertions, 0 falhas, 0 erros ambientais** (9 min 34 s). Linha de base
anterior: 2157 (+45 novos: deploy-check, queue-status, contrato de ambiente,
fila/timeout do WhatsApp). O `laravel.log` habitual não foi tocado (mesmo
tamanho e data de 2026-10-22).

## 4. Preparação de staging

| Item | Estado |
|---|---|
| Isolamento (banco/cookie/cache/filas/APP_KEY próprios; sem defaults locais; testes presos ao banco `testing`; nada provisionado além de papéis/permissões) | **Pronto** (configuração) |
| HTTPS/WSS (nginx API+Reverb mesmo host, SPA com CSP/HSTS, Reverb em loopback, origens restritas) | **Preparado**, pendente DNS/certificado/servidor |
| Autenticação (Sanctum stateful explícito, CORS exato com credenciais, cookie secure/httponly/lax com domínio pai, canais privados por restaurante já testados) | **Pronto**; validação real pendente (§9) |
| Segredos e logs (nada versionado; `APP_DEBUG=false` exigido; `.env`/logs fora de `public/`; nginx nega dotfiles/logs/dumps; links só na captura 0600) | **Pronto** |
| E-mail | **Captura protegida pronta**; SMTP **bloqueado** (provedor) |
| Processos (supervisor, usuário `aforo`, 3 filas + Reverb, timeouts < `retry_after`, restart só do grupo, sem scheduler, `queue-status`) | **Preparado**, não instalado |
| Deploy/rollback (releases, migrations com aprovação explícita, provisioning aditivo, APP_KEY nunca gerada, storage compartilhado) | **Pronto e simulado**; nunca rodou num servidor |
| Restauração de backup | **Testada** (local, descartável) |
| Supabase: Data API/RLS sobre `public`, roles runtime/migração | **Bloqueado** (sua conta) — obrigatório antes de dados |

## 5. Estado real da integração WhatsApp

Implementada contra a **WhatsApp Cloud API oficial** e testada só com
transporte simulado (`Http::fake`), incluindo falhas, retries, concorrência
com processos reais, duplicação e webhooks fora de ordem. **Nunca falou com
a Meta.** Sem app, WABA, número, token, template aprovado nem webhook
registrado. `accepted` ≠ entregue: entrega só via webhook. Falha nunca afeta
o fechamento. Detalhes, classificação por parte, requisitos oficiais com
links e plano da primeira validação: `docs/whatsapp-meta-audit.md`.

Lacunas reais (não corrigidas, exigem decisão): consentimento é declarado
pelo admin (sem registro do opt-in da própria pessoa nem opt-out por ela);
template com 16 variáveis pode ser rejeitado; entrega `pending` cujo job se
perdeu bloqueia o reenvio (detectável com `queue-status`).

## 6. Ações manuais suas, em ordem

1. Revisar este diff e decidir o **commit/branch** a publicar (o deploy só
   exporta refs commitados).
2. Confirmar a hospedagem: VPS com root (Hostinger VPS?) — compartilhada não
   roda Reverb/workers.
3. Escolher `WEB_HOST` (sugestão `app-staging.conejocreative.com`).
4. DNS `A/AAAA` de `api-staging` e `WEB_HOST` → IP do servidor (DNS only).
5. Supabase: projeto de **staging**; desativar Data API ou tirar `public` dos
   schemas expostos (ou RLS em tudo); criar role de runtime (e de migração);
   anotar host/usuário do pooler de sessão.
6. Servidor: pacotes (§1 do runbook), usuário `aforo`, diretórios, espelho
   Git, pool FPM, supervisor, sudoers do grupo, firewall (runbook §3).
7. Gerar **uma vez** APP_KEY e as credenciais Reverb de staging; preencher
   `shared/.env` (600); certificados (certbot webroot).
8. Primeiro deploy: `deploy.sh deploy <ref> --dry-run`, depois `--migrate
   --provision`; `aforo:deploy-check` sem FAIL.
9. Build e publicação do SPA (`npm run build:staging`).
10. Criar o primeiro Super Admin de plataforma (ver pergunta Q5).
11. Plano de teste no telefone (§9).
12. (Depois) SMTP real; (depois) Meta com número de teste — audit §6.

## 7. Configurações/credenciais faltantes (só nomes)

API `shared/.env`: `APP_KEY`, `DB_USERNAME`, `DB_PASSWORD` (e o
`PROJECT_REF`), `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`,
`CORS_ALLOWED_ORIGINS`, `REVERB_APP_ID`, `REVERB_APP_KEY`,
`REVERB_APP_SECRET`, `REVERB_ALLOWED_ORIGINS`, `AFORO_WEB_URL`,
`MAIL_FROM_ADDRESS` (+ `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` no modo
SMTP). WhatsApp (só quando ativar): `WHATSAPP_PHONE_NUMBER_ID`,
`WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_WABA_ID`, `WHATSAPP_TEMPLATE_NAME`,
`WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `META_APP_SECRET`.
Web `.env.staging`: `VITE_PUBLIC_APP_URL`, `VITE_REVERB_APP_KEY`.
nginx: `CHANGE_ME_WEB_HOST` (3 ocorrências + caminho do certificado).
Sudoers: regra do `supervisorctl restart aforo-staging:*`.

## 8. Deploy e rollback

`docs/staging-runbook.md` §4 (API → Web; migrations só com `--migrate`,
SQL salvo antes; provisioning aditivo só com `--provision`; troca atômica;
restart só do grupo; `/up` + `queue-status`) e §5 (rollback só de código,
compatível porque as 77 migrations atuais são aditivas; destrutivas exigem
expand/contract ou restore do dump do §7).

## 9. Plano de teste no telefone real (HTTPS)

Pré: staging publicado, `deploy-check` sem FAIL, supervisor com os 4
processos `RUNNING`, um restaurante de teste criado pelos fluxos (nunca por
SQL). Use o telefone em **4G** (fora da sua rede) e um computador.

1. **TLS**: no telefone abrir `https://WEB_HOST/login` — cadeado válido, sem
   aviso; `https://API_HOST/up` → 200; `http://` redireciona para `https://`.
2. **Login/CSRF**: login no telefone e no computador; aba Rede do computador:
   `GET /sanctum/csrf-cookie` 204, `POST /api/v1/auth/login` 200 (nunca 419),
   cookie `aforo_staging_session` com `Secure`, `HttpOnly`, `SameSite=Lax`,
   domínio pai.
3. **Origem recusada** (computador): `curl -si -H 'Origin: https://evil.example'
   -X OPTIONS https://API_HOST/api/v1/auth/login` → sem
   `Access-Control-Allow-Origin`; mesma origem do SPA → header exato +
   `Allow-Credentials: true`.
4. **Convite**: Super Admin dá de alta restaurante com e-mail seu → link da
   captura por SSH (modo A) ou da caixa (modo B) → abrir **no telefone** →
   barra fica `/invitation` sem `#token` → aceitar → reabrir o link: "ya se ha
   usado". Repetir para um garçom.
5. **Recuperação de senha** no telefone (mensagem idêntica para e-mail
   existente/inexistente; link de uso único).
6. **QR**: imprimir/exibir o QR de uma mesa no computador → câmera do
   telefone → abre `https://WEB_HOST/t/…` (nunca localhost/IP) → carta;
   antes de abrir o dia: só leitura; depois: pedido. (`crypto.randomUUID`
   exige contexto seguro — por isso o teste em HTTP de LAN não é
   representativo.)
7. **Realtime/WSS**: garçom logado no telefone em «Servicio», cozinha no
   computador em «Cocina»; aba Rede → WS `wss://API_HOST/app/<key>` status 101,
   `POST /broadcasting/auth` 200; pedido QR aprovado aparece na cozinha **sem
   recarregar**; garçom recebe "listo para servir". Atribuição: QR de mesa
   nova → sino do garçom em turno.
8. **Canal privado negado**: usuário de outra organização não recebe
   eventos do restaurante (aba Rede: `broadcasting/auth` 403 se tentar).
9. **Resiliência**: `sudo supervisorctl stop aforo-staging:aforo-staging-reverb`
   → indicador de conexão + polling ~15 s → `start` → reconecta sem
   duplicar. Worker realtime parado → reconcile ≤ 60 s.
10. **Fechamento** (dados de teste) → histórico; WhatsApp desligado →
    sem entrega (ou `skipped` se o switch do restaurante estiver ligado).
11. Registrar evidências sem segredos (prints, status HTTP), como no
    checklist local.

## 10. Ponto exato para retomar

- Se esta execução for interrompida antes do relatório final: tudo acima
  está em disco; rodar `scripts/test-sail.sh` (suíte completa) e
  `node tests/frontend-hardening-build.mjs` e conferir §3.
- Próximo passo de trabalho: **sua decisão de commit** (§6.1) e as
  respostas Q1–Q6 abaixo; depois runbook §3 no servidor, item por item, e
  `deploy.sh deploy <ref> --dry-run`.
- Local (independente de staging): religar Reverb e os workers
  `realtime-local`/`mail-local` (checklist "Como começar" 3–5) para drenar os
  14 broadcasts pendentes antes de continuar a rodada manual; voltar
  `SAIL_BIND_ADDRESS` a loopback quando não precisar do telefone na LAN.

## Perguntas

- **Q1** Hospedagem: é VPS com root (qual SO/PHP)? Ou outro plano?
- **Q2** `WEB_HOST`: `app-staging.conejocreative.com` serve? O domínio
  `conejocreative.com` também será usado em produção (risco de cookies
  compartilhados — por isso o cookie de staging tem nome próprio)?
- **Q3** Redis: confirma database (contrato) para staging, ou havia um Redis
  gerenciado previsto no modelo antigo?
- **Q4** E-mail: captura protegida no 1º staging, ou já tem provedor SMTP
  (qual, e qual domínio remetente)?
- **Q5** Primeiro Super Admin em staging: não há comando para isso fora do
  seeder de desenvolvimento. Prefere um comando `aforo:create-platform-admin`
  (interativo, sem senha em argumento), ou criação manual assistida?
- **Q6** Mapeamento das confirmações "atribuição à mesa" (6.1 ou 6.2) e
  "abertura" (abrir o dia, 5.3, ou abertura de mesa por QR) no checklist.

## Conclusão

**Preparados para revisão.** Não estamos preparados para deploy em staging:
**bloqueados por ações externas** (commit, servidor/hospedagem, DNS,
certificados, Supabase de staging com a Data API fechada, credenciais) — a
parte que depende só do repositório está pronta e testada. WhatsApp
permanece desligado e não validado com a Meta. Nada aqui indica prontidão
de produção.
