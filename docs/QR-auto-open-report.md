# QR: abertura automática da mesa — relatório

21 de outubro de 2026. Escopo: primeiro item do checklist do MVP. Sem commit, push ou deploy.

Atualização posterior: o [ambiente local habitual foi corrigido e validado](QR-local-validation-report.md), incluindo a migration no banco local e o pedido #654 em waiting_approval. As seções de ambiente temporário abaixo descrevem a execução inicial.

## Causa raiz e evidência

O QR resolvia apenas `GET /api/v1/public/tables/{publicToken}` e `GET .../menu`. `ResolvePublicTableAction` lia `activeSession`; não havia endpoint público de entrada nem chamada para `OpenTableAction`. `CreatePublicOrderAction` rejeitava ausência de sessão com `TABLE_SESSION_NOT_ACTIVE`. O frontend já reconhecia essa condição e bloqueava checkout, mas não iniciava atendimento. Portanto, o cliente dependia de abertura manual pelo staff.

Havia um risco associado: as mutações públicas selecionavam a sessão corrente da mesa a cada chamada. Um pedido atrasado de uma visita encerrada poderia cair na visita seguinte. A persistência de feedback entre abas também podia substituir o contexto antigo.

Antes: QR → consulta da mesa fechada → carta → checkout indisponível. Depois: QR → POST de entrada → abertura/reutilização atômica → carta vinculada à visita → pedido com o status autoritativo da API.

## Fluxo e contrato

- `POST /api/v1/public/tables/{publicToken}/enter`, com throttle próprio, inicia uma entrada nova ou retoma uma visita identificada por `X-Table-Visit`.
- A resposta mantém `data.restaurant`, `table`, `session`, `menu.available`. O identificador opaco existente `session.feedback.token` também vincula a visita no header; não foi exposto um ID interno de sessão nem criado outro segredo persistente no banco.
- Sem header: QR válido e mesa disponível abrem/reutilizam uma sessão. Uma sessão ainda ativa, porém paga, responde `409 TABLE_SESSION_ALREADY_PAID` sem entregar o token do grupo anterior.
- Com header: o token precisa pertencer à mesa e ao restaurante resolvidos pelo QR. Leituras continuam na visita original, mesmo após encerramento. Nunca seguem automaticamente a próxima sessão.
- `GET` de resolução/menu continua sendo leitura. O backfill de token legado foi movido para o POST. Consultas sem vínculo não descobrem o token de uma sessão paga; visitas encerradas só são acessíveis por quem já detém seu token.
- Pedidos e solicitações públicas precisam de `X-Table-Visit`. A ausência de vínculo é recusada; mesa sem sessão mantém `TABLE_SESSION_NOT_ACTIVE`. Payload de pedido e `Idempotency-Key` continuam no contrato existente.
- Replays legítimos recuperam o pedido da própria visita, inclusive após encerramento. A chave nunca transfere pedidos ou cria uma operação na sessão seguinte.
- Origem continua `customer_qr`; `customer_order_requires_approval=true` produz `waiting_approval`. Não foi alterado o default da configuração nem houve aprovação automática. `/approve` continua sendo aprovação do staff; `/accept` continua sendo transição da cozinha.

## Modelo, atomicidade e concorrência

`tables.status=active` representa disponibilidade cadastral. A ocupação é uma `table_sessions` com `status != closed`; pagamento usa `payment_status`. Não existe um booleano separado de mesa aberta.

O POST adquire a trava operacional compartilhada do restaurante, trava a linha do restaurante em leitura compartilhada e a mesa com `FOR UPDATE`, revalida QR/status sob as travas e reutiliza `OpenTableAction`. Staff e público usam a mesma ação de abertura, agora serializada pela linha da mesa. Transferências já travavam a mesa de destino. O índice parcial existente `table_sessions_one_active_per_table` continua garantindo uma única sessão não encerrada por mesa em todos os caminhos. Conflitos de unicidade continuam sendo conflitos de domínio, sem 500.

Sessão existente não tem horários, pedidos, conta, quantidade de pessoas ou garçom reatribuídos. Para nova abertura anônima, `guest_count=0` significa quantidade ainda não informada; não foi fabricada contagem de pessoas ou identidade de funcionário.

Auditoria e atividade permanecem dentro da transação. A abertura pública usa ator público/customer. Os eventos existentes implementam `ShouldDispatchAfterCommit`: abertura real emite uma vez; repetição e rollback não emitem abertura. Realtime e distribuição de garçons não foram reescritos.

As ações de pedido/solicitação também revalidam mesa e restaurante na sessão travada, recusando um vínculo que mudou por transferência.

Referências primárias consultadas: [locks PostgreSQL](https://www.postgresql.org/docs/current/explicit-locking.html) e [transações Laravel](https://laravel.com/framework/docs/13.x/database#database-transactions). A garantia foi exercitada no PostgreSQL local, com conexões independentes.

## Segurança e dia operacional

Mesa e restaurante vêm exclusivamente do QR validado. IDs arbitrários no corpo não selecionam outra mesa. QR desconhecido/revogado, mesa inativa ou restaurante inativo mantêm erro público neutro, sem criar sessão. Token de outra mesa/restaurante é recusado. O header não concede nenhuma permission administrativa. Nenhum middleware foi removido.

O contexto é por QR e aba no frontend, com memória e `sessionStorage`; o token não é anexado ao cliente HTTP autenticado. O pós-visita usa prioritariamente o token da própria visita, inclusive quando outra aba altera o armazenamento de feedback. Uma nova visita explícita limpa carrinho, flags de entrada, erros e contexto anterior.

Entradas novas não recebem histórico/conta de sessão paga ou encerrada. Não se ampliou acesso a pedidos de outros clientes. Os endpoints existentes de pós-visita/feedback continuam validando a visita específica e pagamento; `TABLE_SESSION_ALREADY_PAID` foi preservado.

Não foi encontrada uma regra de “dia operacional aberto” no caminho atual de abertura. Existe Cierre Diario, cujo lock operacional foi preservado. Cadastro ativo do restaurante continua obrigatório. Nenhum módulo de abertura diária foi implementado.

## Frontend

O primeiro carregamento usa POST de entrada; consultas posteriores e polling usam somente GET. Chamadas simultâneas no mesmo fluxo compartilham a operação pendente. A carta aguarda o contexto autoritativo antes de carregar; checkout aguarda sessão ativa e não paga. Duplo envio de pedido é bloqueado.

Falha inicial mostra loading/erro e retry manual, sem repetição infinita. Introdução, boas-vindas, idiomas, ações, catálogo e fluxo de aprovação existentes foram preservados.

Abas antigas continuam na visita anterior. A ação **Iniciar una nueva visita** só permite desvincular explicitamente uma visita encerrada/inválida. Isso permite reutilizar a mesma aba/celular sem fazer refresh/polling trocar de atendimento. Os textos novos foram traduzidos para `es-ES`, `ca-ES-valencia` e `en-GB`.

## Migration

`database/migrations/2026_10_21_000001_allow_public_table_session_opening.php` torna apenas `table_sessions.opened_by_user_id` nullable e preserva a foreign key. A coluna era NOT NULL, impedindo abertura pública sem inventar um funcionário.

Nenhum índice novo foi necessário. Aplicada exclusivamente na instância temporária local criada para testes. Não foi aplicada em banco compartilhado, staging ou produção. O rollback tenta restaurar NOT NULL e falha de forma segura se houver sessões anônimas; não apaga histórico nem atribui funcionário fictício para viabilizar rollback.

## Testes e validação

Backend: 381 testes; 381 passaram; 1645 assertions. Resultado: passed.

Comando executado com a configuração temporária da instância isolada:

```sh
APP_CONFIG_CACHE=/tmp/qr-auto-open-pg/config.php php vendor/bin/phpunit \
  tests/Feature/Public tests/Feature/Table/TableSessionOpenTest.php \
  tests/Feature/TableSession/PaidSessionBlocksTest.php tests/Feature/Security/PublicAbuseTest.php \
  tests/Feature/Realtime/TableSessionEventsTest.php tests/Feature/TableSession/TransferTest.php \
  tests/Feature/Activity/ActivityRecordingTest.php tests/Feature/AuditLog/AuditLogTableSessionAndRequestTest.php \
  tests/Feature/RestaurantSettings/FeatureTogglesTest.php tests/Feature/Security/SecurityCloseoutTest.php \
  tests/Feature/Order/OrderPricingTest.php tests/Feature/Order/ModifierSelectionTest.php \
  tests/Feature/TableRequest/TableRequestLifecycleTest.php
```

Cobertura nova: 12 testes de entrada/segurança/lifecycle e um teste com seis processos PHP independentes (público e staff), aguardando locks reais no PostgreSQL. Verifica uma sessão, uma auditoria/atividade de abertura, commit/rollback, reutilização, QR inválido/revogado/inativo, tenant, sessão encerrada/paga, replay, pedido pendente e rate limit. Os cenários HTTP antigos foram adaptados com um helper explícito de fixture para enviar o novo header; os testes de ausência/token antigo usam HTTP direto.

Também foram executadas regressões de menu, locale, feedback, pós-visita, solicitações, idempotência, pagamentos, abertura por staff, transferência, eventos, atividade/auditoria, toggles, preços/modifiers e segurança.

Frontend:

- `npm run build`: TypeScript (`vue-tsc -b`) e Vite passaram.
- `node tests/frontend-qr-entry.mjs`: **37 checks funcionais** com a tela/composables/services reais e transporte HTTP simulado; **18 combinações** de viewport/tema/idioma para retry, botão de 44 px e ausência de overflow; sem erros de página.
- `node tests/frontend-approvals.mjs`: **65 checks funcionais + 48 responsivos**, sem erros de página.
- `npm run test:security`: **54 checks** passaram.
- `npm run test:hardening`: **115 checks**, **14 configurações inválidas recusadas** e **2 builds HTTPS/WSS válidos** passaram.
- Pint passou em todos os 40 arquivos PHP da tarefa; verificação de sintaxe: 40 arquivos, zero erros. `git diff --check` passou no escopo da tarefa (o whitespace anterior em UserRole foi preservado); os locales foram conferidos byte a byte contra o snapshot, removendo apenas as duas adições novas. Não existe script de lint no frontend.

A skill local [ui-ux-pro-max](../../restaurants-web/.claude/skills/ui-ux-pro-max/SKILL.md), exigida por `CLAUDE.md`, orientou loading, recuperação de erro, foco, touch targets e revisão responsiva. Imagens reais de erro/retry, confirmação e pós-visita foram abertas e inspecionadas; capturas temporárias ficaram fora dos repositórios.

## Validação real local, separada dos doubles

Aplicação Vue real + API Laravel real + PostgreSQL temporário, somente dados de teste locais:

1. Criada mesa de teste com zero sessões.
2. Navegador abriu o QR e exibiu as boas-vindas; banco confirmou uma sessão ativa.
3. Produto adicionado e pedido enviado pela tela real. API respondeu 201; banco confirmou `customer_qr`, `waiting_approval`, `approved_at=null`.
4. Outro contexto de navegador e refresh reutilizaram exatamente o mesmo token; banco manteve uma sessão total/ativa.
5. Para preparar o cenário de encerramento, o pedido foi explicitamente aprovado, servido, pago e a mesa encerrada pelas ações de domínio existentes, apenas nesses dados isolados. Isso não representa uma validação da UI de caixa/aprovação.
6. Refresh da aba anterior mostrou seu resumo de pós-visita, sem checkout.
7. Ação explícita de nova visita criou outra sessão vazia. Banco: uma sessão encerrada com o pedido anterior e uma ocupada com zero pedidos.
8. A segunda aba antiga permaneceu no pós-visita original. Nenhum erro de página foi observado.

Os servidores temporários de API/Vite e a instância PostgreSQL de testes foram parados após a validação; nenhum serviço preexistente foi parado.

Broadcast/Reverb real não foi exercitado: testes e API temporária usaram broadcaster null. A emissão após commit foi validada pelo dispatcher e pelas regressões, sem alegar entrega por WebSocket.

## Ambiente e preservação

Branches preservadas: API `fix/security-hardening`; web `main`. Não havia `AGENTS.md` nas raízes/ancestrais ou nos arquivos dos projetos. Foram lidos `CLAUDE.md`, arquitetura, regras de negócio e design system aplicáveis no frontend.

O `.env` local da API tem erro de sintaxe e não foi editado. Para evitar qualquer banco externo e permitir os testes, foi criada configuração temporária fora dos repositórios, apontando apenas à instância PostgreSQL nova em loopback, porta 55439, banco `testing`. O bootstrap habitual com esse `.env` continua dependendo da correção desse problema pelo responsável pelo ambiente.

Nenhum `.env` (inclusive staging example), manifest/lockfile, Supabase, Redis, Reverb, Docker ou infraestrutura do projeto foi alterado por esta tarefa. Não houve acesso de testes a staging/produção, reset/clean/restore/stash, commit, push ou deploy. Os dados preexistentes não foram apagados/recriados. Migrations/refresh/truncation dos testes ocorreram somente na instância isolada recém-criada.

Hashes SHA-256 foram capturados antes da primeira edição; os arquivos preexistentes fora do escopo mantiveram seus bytes. Os diffs de arquivos já modificados foram comparados ao snapshot inicial para preservar o trabalho anterior. O git abaixo inclui mudanças anteriores e não deve ser atribuído integralmente à tarefa; arquivos novos não entram no `git diff --stat`.

## Arquivos desta tarefa

### restaurants-api

- `app/Actions/Orders/CreatePublicOrderAction.php`
- `app/Actions/Orders/OrderCreationService.php`
- `app/Actions/Public/EnterPublicTableAction.php`
- `app/Actions/Public/ResolvePublicTableAction.php`
- `app/Actions/TableRequests/CreatePublicTableRequestAction.php`
- `app/Actions/Tables/OpenTableAction.php`
- `app/Exceptions/Public/PublicVisitInvalidException.php`
- `app/Http/Controllers/Api/V1/Public/PublicMenuController.php`
- `app/Http/Controllers/Api/V1/Public/PublicOrderController.php`
- `app/Http/Controllers/Api/V1/Public/PublicTableController.php`
- `app/Http/Controllers/Api/V1/Public/PublicTableRequestController.php`
- `app/Http/Resources/Api/V1/Public/PublicSessionStateResource.php`
- `app/Models/TableSession.php`
- `app/OpenApi/ApiDocumentation.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `database/migrations/2026_10_21_000001_allow_public_table_session_opening.php`
- `docs/QR-auto-open-report.md`
- `routes/api.php`
- `tests/Concerns/InteractsWithPublicVisits.php`
- `tests/Feature/Activity/ActivityRecordingTest.php`
- `tests/Feature/AuditLog/AuditLogTableSessionAndRequestTest.php`
- `tests/Feature/Order/ModifierSelectionTest.php`
- `tests/Feature/Order/OrderPricingTest.php`
- `tests/Feature/Public/PublicBillRequestEligibilityTest.php`
- `tests/Feature/Public/PublicCsrfOriginTest.php`
- `tests/Feature/Public/PublicFeedbackTest.php`
- `tests/Feature/Public/PublicOrderBillRequestedTest.php`
- `tests/Feature/Public/PublicOrderIdempotencyTest.php`
- `tests/Feature/Public/PublicOrderRateLimitTest.php`
- `tests/Feature/Public/PublicOrderTest.php`
- `tests/Feature/Public/PublicTableEntryConcurrencyTest.php`
- `tests/Feature/Public/PublicTableEntryTest.php`
- `tests/Feature/Public/PublicTableRequestRateLimitTest.php`
- `tests/Feature/Public/PublicTableRequestTest.php`
- `tests/Feature/RestaurantSettings/FeatureTogglesTest.php`
- `tests/Feature/Security/PublicAbuseTest.php`
- `tests/Feature/Security/SecurityCloseoutTest.php`
- `tests/Feature/TableRequest/TableRequestLifecycleTest.php`
- `tests/Feature/TableSession/PaidSessionBlocksTest.php`
- `tests/Support/public-table-entry-worker.php`

### restaurants-web

- `docs/QR-auto-open-report.md`
- `src/components/public/PublicPostVisitView.vue`
- `src/composables/usePublicEntryState.ts`
- `src/composables/usePublicMenu.ts`
- `src/composables/usePublicTableResolution.ts`
- `src/i18n/locales/ca-ES-valencia.ts`
- `src/i18n/locales/en-GB.ts`
- `src/i18n/locales/es-ES.ts`
- `src/services/public-table.service.ts`
- `src/utils/error-message.ts`
- `src/utils/public-visit-context.ts`
- `src/views/public/PublicTableView.vue`
- `tests/frontend-qr-entry.browser.ts`
- `tests/frontend-qr-entry.html`
- `tests/frontend-qr-entry.mjs`

## Como confirmar manualmente

Em ambiente local com a migration aplicada e API/SPA apontando ao mesmo ambiente:

1. Escolha uma mesa ativa de teste, fechada, em restaurante ativo com carta publicada e aprovação de pedidos habilitada.
2. Abra seu QR em uma aba nova. Confirme boas-vindas, mesa ocupada e exatamente uma sessão ativa.
3. Adicione produto e confirme pedido. Verifique `waiting_approval` e a fila do garçom; a cozinha não deve aceitá-lo antes da aprovação vigente.
4. Abra o mesmo QR em outro contexto e atualize a primeira aba. Confirme mesma sessão/horário e ausência de nova auditoria de abertura.
5. Encerre a visita pelo fluxo existente após servir/pagar. A aba antiga deve permanecer em pós-visita; seus requests não devem operar numa sessão nova.
6. Inicie explicitamente uma nova visita. Confirme sessão vazia e pedido anterior preservado na sessão encerrada. Outra aba antiga deve continuar vinculada à visita anterior.
7. Confirme que QR revogado/inativo e token de outra mesa não abrem atendimento; uma entrada nova enquanto a sessão anterior ainda está paga/ativa não recebe sua conta.

## Estado Git final

### restaurants-api: git status --short

```text
 M app/Actions/Orders/CreatePublicOrderAction.php
 M app/Actions/Orders/OrderCreationService.php
 M app/Actions/Public/ResolvePublicTableAction.php
 M app/Actions/TableRequests/CreatePublicTableRequestAction.php
 M app/Actions/Tables/OpenTableAction.php
 M app/Http/Controllers/Api/V1/Public/PublicMenuController.php
 M app/Http/Controllers/Api/V1/Public/PublicOrderController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableRequestController.php
 M app/Http/Resources/Api/V1/Public/PublicSessionStateResource.php
 M app/Models/TableSession.php
 M app/Models/UserRole.php
 M app/OpenApi/ApiDocumentation.php
 M app/Providers/AppServiceProvider.php
 M bootstrap/app.php
 M package.json
 M routes/api.php
 M tests/Feature/Activity/ActivityRecordingTest.php
 M tests/Feature/AuditLog/AuditLogTableSessionAndRequestTest.php
 M tests/Feature/Order/ModifierSelectionTest.php
 M tests/Feature/Order/OrderPricingTest.php
 M tests/Feature/Public/PublicBillRequestEligibilityTest.php
 M tests/Feature/Public/PublicCsrfOriginTest.php
 M tests/Feature/Public/PublicFeedbackTest.php
 M tests/Feature/Public/PublicOrderBillRequestedTest.php
 M tests/Feature/Public/PublicOrderIdempotencyTest.php
 M tests/Feature/Public/PublicOrderRateLimitTest.php
 M tests/Feature/Public/PublicOrderTest.php
 M tests/Feature/Public/PublicTableRequestRateLimitTest.php
 M tests/Feature/Public/PublicTableRequestTest.php
 M tests/Feature/RestaurantSettings/FeatureTogglesTest.php
 M tests/Feature/Security/PublicAbuseTest.php
 M tests/Feature/Security/SecurityCloseoutTest.php
 M tests/Feature/TableRequest/TableRequestLifecycleTest.php
 M tests/Feature/TableSession/PaidSessionBlocksTest.php
?? .env.staging.example
?? .oh-my-sdd/
?? app/Actions/Public/EnterPublicTableAction.php
?? app/Exceptions/Public/PublicVisitInvalidException.php
?? database/migrations/2026_10_21_000001_allow_public_table_session_opening.php
?? docs/QR-auto-open-report.md
?? docs/pre-9.2-manager-permissions.md
?? package-lock.json
?? tests/Concerns/InteractsWithPublicVisits.php
?? tests/Feature/Public/PublicTableEntryConcurrencyTest.php
?? tests/Feature/Public/PublicTableEntryTest.php
?? tests/Support/
```

### restaurants-api: git diff --stat

```text
 app/Actions/Orders/CreatePublicOrderAction.php     | 12 ++++--
 app/Actions/Orders/OrderCreationService.php        |  3 +-
 app/Actions/Public/ResolvePublicTableAction.php    | 20 ++++++++-
 .../CreatePublicTableRequestAction.php             |  9 ++--
 app/Actions/Tables/OpenTableAction.php             | 19 +++++---
 .../Api/V1/Public/PublicMenuController.php         |  4 +-
 .../Api/V1/Public/PublicOrderController.php        |  3 +-
 .../Api/V1/Public/PublicTableController.php        | 35 +++++++++++++--
 .../Api/V1/Public/PublicTableRequestController.php |  4 +-
 .../Api/V1/Public/PublicSessionStateResource.php   | 17 ++++----
 app/Models/TableSession.php                        | 13 ++----
 app/Models/UserRole.php                            |  2 +-
 app/OpenApi/ApiDocumentation.php                   |  2 +-
 app/Providers/AppServiceProvider.php               |  7 +++
 bootstrap/app.php                                  |  6 +++
 package.json                                       |  3 ++
 routes/api.php                                     |  3 ++
 tests/Feature/Activity/ActivityRecordingTest.php   |  6 ++-
 .../AuditLogTableSessionAndRequestTest.php         |  4 +-
 tests/Feature/Order/ModifierSelectionTest.php      |  4 +-
 tests/Feature/Order/OrderPricingTest.php           | 10 +++--
 .../Public/PublicBillRequestEligibilityTest.php    | 10 +++--
 tests/Feature/Public/PublicCsrfOriginTest.php      |  8 ++--
 tests/Feature/Public/PublicFeedbackTest.php        |  6 +--
 .../Public/PublicOrderBillRequestedTest.php        | 12 +++---
 .../Feature/Public/PublicOrderIdempotencyTest.php  | 50 +++++++++++-----------
 tests/Feature/Public/PublicOrderRateLimitTest.php  |  8 ++--
 tests/Feature/Public/PublicOrderTest.php           | 32 +++++++-------
 .../Public/PublicTableRequestRateLimitTest.php     | 12 +++---
 tests/Feature/Public/PublicTableRequestTest.php    | 46 ++++++++++----------
 .../RestaurantSettings/FeatureTogglesTest.php      | 18 ++++----
 tests/Feature/Security/PublicAbuseTest.php         | 16 ++++---
 tests/Feature/Security/SecurityCloseoutTest.php    |  6 ++-
 .../TableRequest/TableRequestLifecycleTest.php     |  4 +-
 .../Feature/TableSession/PaidSessionBlocksTest.php | 24 ++++++-----
 35 files changed, 275 insertions(+), 163 deletions(-)
```

### restaurants-web: git status --short

```text
 M .env.example
 M docs/PR-2B-frontend-hardening-report.md
 M docs/PR-2B.1-frontend-hardening-correction-report.md
 M package-lock.json
 M package.json
 M src/api/errors.ts
 M src/api/http.ts
 M src/api/public-http.ts
 M src/api/retry-after.ts
 M src/api/session.ts
 M src/api/timeouts.ts
 M src/components/dashboard/table/TableDetailsDrawer.vue
 M src/components/dashboard/table/TableOrdersList.vue
 M src/components/public/PublicPostVisitView.vue
 M src/components/public/PublicProductFeed.vue
 M src/components/service/OrderDetailSheet.vue
 M src/composables/useOrderApprovals.ts
 M src/composables/usePublicEntryState.ts
 M src/composables/usePublicMenu.ts
 M src/composables/usePublicTableResolution.ts
 M src/config/env.ts
 M src/config/runtime.ts
 M src/i18n/locales/ca-ES-valencia.ts
 M src/i18n/locales/en-GB.ts
 M src/i18n/locales/es-ES.ts
 M src/main.ts
 M src/realtime/echo.ts
 M src/services/product.service.ts
 M src/services/public-table.service.ts
 M src/utils/error-message.ts
 M src/utils/table-public-url.ts
 M src/views/LoginView.vue
 M src/views/public/PublicTableView.vue
 M src/views/service/ServiceView.vue
 M tests/frontend-hardening-build.mjs
 M tests/frontend-hardening.mjs
 M tests/frontend-security.browser.ts
 M vite.config.ts
?? docs/QR-auto-open-report.md
?? docs/QR-waiter-approval-fix-report.md
?? src/assets/images/aforro-agency-restauramt-pexels-imgs.jpg:Zone.Identifier
?? "src/assets/images/ref/imagem 1.jpg:Zone.Identifier"
?? "src/assets/images/ref/imagem 2.webp:Zone.Identifier"
?? "src/assets/images/ref/imagem 3.png:Zone.Identifier"
?? src/components/public/PublicMenuCatalog.vue
?? src/utils/order-approval.ts
?? src/utils/public-visit-context.ts
?? src/views/aforo-dashboard.html:Zone.Identifier
?? t.txt
?? tests/frontend-approvals.browser.ts
?? tests/frontend-approvals.html
?? tests/frontend-approvals.mjs
?? tests/frontend-qr-entry.browser.ts
?? tests/frontend-qr-entry.html
?? tests/frontend-qr-entry.mjs
```

### restaurants-web: git diff --stat

```text
 .env.example                                       |   58 +-
 docs/PR-2B-frontend-hardening-report.md            |  452 +-
 ...PR-2B.1-frontend-hardening-correction-report.md |  306 +-
 package-lock.json                                  | 7033 ++++++++++----------
 package.json                                       |   78 +-
 src/api/errors.ts                                  |  206 +-
 src/api/http.ts                                    |   36 +-
 src/api/public-http.ts                             |   40 +-
 src/api/retry-after.ts                             |   16 +-
 src/api/session.ts                                 |   26 +-
 src/api/timeouts.ts                                |    4 +-
 .../dashboard/table/TableDetailsDrawer.vue         |    1 +
 src/components/dashboard/table/TableOrdersList.vue |   58 +-
 src/components/public/PublicPostVisitView.vue      |    4 +
 src/components/public/PublicProductFeed.vue        |   17 +-
 src/components/service/OrderDetailSheet.vue        |   18 +-
 src/composables/useOrderApprovals.ts               |   72 +-
 src/composables/usePublicEntryState.ts             |    7 +
 src/composables/usePublicMenu.ts                   |    5 +-
 src/composables/usePublicTableResolution.ts        |   44 +-
 src/config/env.ts                                  |   82 +-
 src/config/runtime.ts                              |    4 +-
 src/i18n/locales/ca-ES-valencia.ts                 | 3224 ++++-----
 src/i18n/locales/en-GB.ts                          | 3224 ++++-----
 src/i18n/locales/es-ES.ts                          | 3238 ++++-----
 src/main.ts                                        |   64 +-
 src/realtime/echo.ts                               |  144 +-
 src/services/product.service.ts                    |  148 +-
 src/services/public-table.service.ts               |   19 +-
 src/utils/error-message.ts                         |    1 +
 src/utils/table-public-url.ts                      |   70 +-
 src/views/LoginView.vue                            |  500 +-
 src/views/public/PublicTableView.vue               |  335 +-
 src/views/service/ServiceView.vue                  |   62 +-
 tests/frontend-hardening-build.mjs                 |  154 +-
 tests/frontend-hardening.mjs                       |  270 +-
 tests/frontend-security.browser.ts                 |    2 +-
 vite.config.ts                                     |   56 +-
 38 files changed, 10221 insertions(+), 9857 deletions(-)
```

