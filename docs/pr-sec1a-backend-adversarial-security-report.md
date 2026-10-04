# PR-SEC1A — BACKEND ADVERSARIAL SECURITY REPORT

Data: 2026-10-04 (Europe/Madrid).

**SECURITY VERDICT: BLOCKED — SEC-001, escalada de permissões administrativas entre restaurantes da mesma organização. Auditoria interrompida pela condição de parada da carta.**

Esta é uma auditoria **parcial**. Não representa aprovação de segurança do backend,
nem validação de todas as superfícies solicitadas. Não foi implementada correção
ampla de autorização. As reproduções abaixo são locais e reversíveis, executadas
no banco PostgreSQL `testing` por `RefreshDatabase`. Nenhum sistema externo foi
atacado; nenhum endpoint Meta foi chamado. Composer consultou apenas o feed de
advisories necessário ao baseline explicitamente solicitado.

## PRECHECK

- HEAD: `4fdff01` — feat: harden backend for staging readiness.
- Branch: `main`, no mesmo commit que `origin/main`.
- Initial clean: YES; status, branches e últimos 15 commits registrados antes de alterações.
- Composer audit --locked: PASS, zero advisories conhecidos no Sail.

## ATTACK SURFACE

153 rotas capturadas por `./vendor/bin/sail artisan route:list --json`.
Inventário por rota: [pr-sec1a-route-inventory.md](pr-sec1a-route-inventory.md).
A tabela registra middleware, throttle, controller/input declarado,
autorizações diretas e tipo de resposta/resource extraídos estaticamente.
A revisão de cada helper/action e a validação dinâmica de todas as rotas ficaram
pendentes quando SEC-001 disparou a condição de parada.

| Classe principal | Rotas |
| --- | ---: |
| PUBLIC | 13 |
| AUTHENTICATED | 3 |
| TENANT-SCOPED | 117 |
| PLATFORM | 11 |
| WEBHOOK | 2 |
| REALTIME AUTH | 1 |
| HEALTH | 2 |
| DOCUMENTATION | 4 |

Classificação exclusiva para contagem: realtime é autenticado, mas aparece na
sua própria classe. AUTHENTICATED inclui me/context/logout. PUBLIC inclui as
rotas públicas QR/menu/orders/requests/feedback/visit, login, root,
Sanctum csrf-cookie e as duas rotas framework de storage (não analisadas).
TENANT-SCOPED usa auth:sanctum + active_user + tenant; PLATFORM usa
auth:sanctum + active_user + platform_admin. A presença de middleware não
prova a correção das permissões, como SEC-001 demonstra.

## FINDINGS SUMMARY

| Severity | Confirmados |
| --- | ---: |
| CRITICAL | 0 encontrados no escopo executado |
| HIGH | 1 — SEC-001, não corrigido |
| MEDIUM | 0 confirmados |
| LOW | 0 confirmados |
| INFORMATIONAL | 0 findings separados |

Os zeros não significam ausência de vulnerabilidades nas partes não auditadas.

## SEC-001 — HIGH — grants de um restaurante autorizam outro restaurante

**Attack:** usuário autenticado com manager em A1 e waiter em A2 faz chamadas
API diretas e autorização de canal de A2. O frontend/context informa somente
capabilities de waiter em A2; o backend aceita capabilities de manager vindas de A1.

**Affected endpoint/code:**

- PATCH `/api/v1/restaurants/{A2}/settings`:
  `RestaurantSettingsController::update`, `RestaurantPolicy::manageSettings`.
- GET `/api/v1/restaurants/{A2}/day-closes`:
  `DayCloseController::index`, `RestaurantPolicy::viewDayCloses`.
- POST `/broadcasting/auth`, canal `private-restaurant.{A2}.activity`:
  `routes/channels.php:83`.
- Causa comum: `app/Models/User.php:113`, `User::hasPermission` filtra
  `organization_id`, mas não limita a role assignment ao restaurante alvo.
- `RestaurantScope` limita quais restaurantes são alcançáveis, mas não qual
  grant pode ser usado em cada restaurante. A2 está no escopo por ser waiter ali.
- O contrato de `AuthContextBuilder` (`app/Support/Auth/AuthContextBuilder.php:173`)
  filtra grants por restaurante e explicita que grants de A1 não valem em A2.
  O teste existente `AuthContextTest::test_permissions_are_scoped_per_restaurant_when_roles_differ`
  cobre a representação dessa regra, sem exercitar sua aplicação nas APIs.

**Precondition:** mesma organização, membership ativa nos dois restaurantes,
manager atribuído somente a A1, waiter atribuído a A2. Não precisa ser owner,
platform admin ou conhecer qualquer secret. IDs próprios dos restaurantes bastam.
As fixtures usam as relações reais organization_users/restaurant_users/user_roles.

**Limite de exposição:** o atual fluxo público de gestão de staff aplica uma
única role uniformemente aos restaurantes do funcionário (`UpdateStaffAction:24–25`).
Portanto, a reprodução não demonstra que um waiter puro consiga criar sozinho a
role manager, nem que o fluxo atual de staff normalmente gere roles distintas.
Ela demonstra falha no enforcement de um estado admitido pelo modelo e pelo
contrato/testes de auth context. Roles históricas, provisionadas separadamente ou
removidas apenas em um restaurante podem produzir essa diferença. Não se confirmou
escape para outra organização ou aquisição de plataforma/super_admin.

**Impact:** escrita administrativa de settings no restaurante em que o usuário
só possui role waiter; acesso ao endpoint de histórico financeiro; assinatura do
canal de activity reservado a owner/manager. Outras policies compartilham o helper
(40 chamadas em `app/Policies`), o que indica risco de propagação e exige revisão
sistemática. Não foram declarados explorados os outros endpoints apenas por
compartilharem o helper.

**Reproduction:**

```bash
./vendor/bin/sail artisan test tests/Feature/Security/RestaurantPermissionBoundaryTest.php
```

Três testes, sete assertions, três falhas comprovadas:

1. Auth context omite `manage_restaurants` de A2, mas o PATCH altera o valor
   persistido de `customer_ordering_enabled`. Falha: false em lugar de true.
2. Auth context omite `view_daily_closes` de A2, mas GET day-closes retorna
   **200**, em lugar do **403** esperado. O fixture não tem fechamentos;
   o teste prova acesso indevido ao endpoint, não exfiltração de um registro real.
3. O canal activity de A2 retorna **200**, em lugar de **403**. Reverb usa
   credenciais fictícias somente para assinatura local, sem conexão externa.

**Fix:** NÃO APLICADO. A carta manda parar antes de implementar uma escalada
administrativa ampla ou mudança ampla de arquitetura/contrato. Alterar o helper
atual isoladamente não é seguro: ele recebe apenas Organization e é reutilizado
em fluxos de catálogo/staff e operações com semânticas diferentes.

Correção proposta para uma carta posterior: definir enforcement explícito de
permissões por restaurante (grants organization-wide ou do restaurante alvo),
preservar a semântica de operações genuinamente organizacionais, revisar policies,
controllers/actions e canais, e reconciliar isso com auth context e revogação.
Não resolver escondendo capabilities no frontend ou rejeitando somente estas três rotas.

**Regression coverage:** os três testes novos mantêm o comportamento seguro
esperado e estão intencionalmente vermelhos até a correção. Não foram marcados
como skipped, nem alterados para aceitar o comportamento vulnerável.

## SQL INJECTION

Busca estática inicial executada: DB::raw/selectRaw/whereRaw/orderByRaw,
statement/unprepared e superfícies correlatas. Há agregações SQL e expressões
JSON/PostgreSQL em analytics, staff, activity e day close. A análise de fluxo de
entrada de todas as ocorrências e os payloads solicitados não foram concluídos.
Nenhuma SQL injection explorável confirmada. Não considerar esta área aprovada.

## XSS

Busca estática inicial encontrou escaping `nl2br(e(...))` no PDF e expressões
raw no template Swagger. Não foram concluídas as reproduções com texto persistido,
renderização PDF/emails e análise da origem de todas as expressões.

## TENANT / IDOR

Confirmado SEC-001 dentro da mesma organização e com A2 alcançável por membership.
Matriz completa A1/A2/B1 e nested IDs para todos os recursos: NÃO EXECUTADA.
Não foi comprovado escape entre organizações nesta etapa.

## MASS ASSIGNMENT

Revisão inicial de models e inputs relacionados; ataques abrangentes aos campos
ownership/status/roles/tokens/preços: NÃO EXECUTADOS. Pendente.

## PRIVILEGE ESCALATION

SEC-001 confirmado em settings, history e activity channel. Matriz completa
owner/manager/waiter/cashier/kitchen e remoção de grants durante sessão: pendente.

## AUTH / CSRF

Middleware/auth context inspecionados. Session fixation/regeneration, CSRF real
fora do bypass de testes, logout e stale sessions: NÃO VALIDADOS nesta carta.
Nenhuma conclusão de auth bypass ou segurança de bearer/JWT foi emitida.

## CORS

Config PR-2A localizada. Payloads Origin null/wildcard/confusão de domínio e
headers em runtime: NÃO EXECUTADOS nesta carta. Testes PR-2A não foram usados
como substituto da auditoria adversarial pedida.

## RATE LIMITING

Limiters inventariados estaticamente: login 5/min por email+IP; public-menu
60/min por IP; orders/requests/feedback 10/min por IP+token; webhook 600/min
por IP; resend 3/min por user+dayClose. Bypass por headers/token/usuário e trusted
proxies: NÃO TESTADOS. Nenhuma garantia sobre resistência a bypass.

## QR / PUBLIC

Rotas públicas e exclusão explícita de stateful/CSRF middleware identificadas.
Casos token inválido/truncado/longo/replay, table/session/status: pendentes.

## ORDERS

Abuso de quantity/modifiers/preço/tenant/status e idempotency: NÃO EXECUTADO.

## PAYMENTS

Manipulação de amount/currency/status/ownership e duplicação: NÃO EXECUTADA.

## UPLOADS

Candidato identificado por inspeção: `StoreOrReplaceProductMediaAction` usa
`storeAs` sem check explícito para retorno false antes do updateOrCreate/delete
da mídia anterior. Não foi reproduzido nem corrigido: a auditoria parou em
SEC-001. Fixtures MIME/filename/size e tenant scope: pendentes. Este candidato
não é contado como finding confirmado.

## PATH TRAVERSAL

Superfícies de mídia/PDF localizadas; payloads de traversal/encoding: pendentes.

## SSRF

Busca inicial localizou Http no provider Meta WhatsApp. Não se concluiu a análise
completa de URLs dinâmicas. Não declarar NONE sem terminar o fluxo de dados.

## WEBHOOK

Rotas, throttle e controller localizados. HMAC/replay/body modificado/invalid
JSON/oversize/regressão de status: NÃO TESTADOS nesta carta.

## IDEMPOTENCY

Auditoria e casos same/different actor/payload/parallel/keys: pendentes.

## CONCURRENCY

Nenhum teste paralelo novo executado. Order/session/payment/cash/day-close/resend:
pendentes. Os testes de reprodução de SEC-001 rodaram sequencialmente.

## WEBSOCKET

Autorização direta do activity channel confirmou SEC-001. Outros casos
user.X/user.Y, B1, inativo, membership removida e canal operacional: pendentes.

## INFORMATION DISCLOSURE

Respostas 403/404/422/429/500 com APP_DEBUG=false e inspeção de secrets/paths/SQL:
NÃO EXECUTADAS. Nenhum secret real foi reportado/exibido.

## INPUT LIMITS

Malformed/deep JSON, arrays/scalars, strings longas/unicode: pendentes.

## OPEN REDIRECT

Busca estática inicial executada; análise completa de fluxos: pendente.

## COMMAND/CODE INJECTION

Busca estática inicial executada; análise completa de fluxos: pendente.
Nenhuma RCE confirmada.

## DESERIALIZATION

Session/cache serialization e ausência de unserialize cliente: NÃO CONFIRMADOS.

## ENUMERATION

Comparação de responses/timing login/tokens/tenant IDs: NÃO EXECUTADA.

## DB PRIVILEGE PLAN

Plano definitivo NÃO PRODUZIDO após a parada. Direção preliminar, não implementada:
separar usuário de migrations (DDL e ownership dos objetos) de usuário runtime
(DML nas tabelas usadas, USAGE em schema/sequences, sem superuser/CREATEDB/CREATEROLE).
Inventário exato de operações runtime, locks, provisioning e grants de sequences
precisa acompanhar a auditoria retomada. Nenhum DB user/grant alterado.

## FIXES APPLIED

Nenhuma alteração de aplicação. Somente reproduções e documentação da auditoria.

## SECURITY TESTS ADDED

`tests/Feature/Security/RestaurantPermissionBoundaryTest.php`:

- `test_manager_grant_at_a1_must_not_allow_settings_write_as_waiter_at_a2`
- `test_manager_grant_at_a1_must_not_allow_history_read_as_waiter_at_a2`
- `test_manager_grant_at_a1_must_not_allow_activity_channel_as_waiter_at_a2`

## QUALITY

- Security targeted suite: FAIL, 3 testes / 7 assertions / 3 falhas esperadas do finding.
- Relevant feature groups: NÃO EXECUTADOS após condição de parada.
- Full backend suite: NÃO EXECUTADA nesta carta; três regressões novas estão vermelhas.
- Pint: PASS, `./vendor/bin/sail bin pint --dirty --test`.
- Composer audit: PASS no baseline, zero advisories conhecidos.
- Composer validate: PASS.
- git diff --check: PASS.

Não reutilizar o PASS de 1.853 testes da PR-2A como resultado desta auditoria.

## FILES CHANGED

Somente três arquivos novos, sem stage/commit:

- docs/pr-sec1a-backend-adversarial-security-report.md
- docs/pr-sec1a-route-inventory.md
- tests/Feature/Security/RestaurantPermissionBoundaryTest.php

`git diff --stat` não inclui esses arquivos, pois continuam untracked.

## SECURITY VERDICT

**B) BLOCKED — SEC-001 (HIGH): grants administrativos de A1 autorizam settings,
history e activity channel de A2, onde o ator possui apenas role waiter.**

Condição de parada atendida. Precisa de correção de autorização com escopo
explicitamente acordado e retomada das áreas pendentes antes de qualquer staging
público ou veredito READY FOR SUPABASE SECURITY VALIDATION.

COMMIT: NO.
PUSH: NO.
DEPLOY: NO.
SUPABASE/HOSTINGER: NÃO CONFIGURADOS.
BANCO DE DESENVOLVIMENTO: NÃO ALTERADO DESTRUTIVAMENTE.
