# QR e staff — histórico da sessão, acompanhamento e conta

21 de outubro de 2026 · Europe/Madrid. Sem commit, push ou deploy.

## Resultado e investigação

Implementado e testado automaticamente. Houve também validação por navegador contra a aplicação/API reais, exclusivamente em banco local novo. A rodada manual do usuário no ambiente habitual continua pendente.

O histórico operacional já existia: `GET /orders` aceitava `table_session_id`, incluía todos os estados e retornava snapshots de itens/adicionais; `GET /orders/{id}` fornecia o detalhe. Não foi encontrada remoção backend de pedidos por aprovação ou serviço. Fila de aprovação e fila de cozinha já eram visões separadas. Isso foi preservado e ganhou testes de regressão.

A conta já era calculada por `SessionBillCalculator`, com valores persistidos e aritmética em centavos via `Money`. Pagamentos parciais, saldo e impedimentos de fechamento já existiam. O garçom padrão já possuía permissão para consultar a conta; nenhuma capability ou matriz financeira nova foi necessária.

Lacunas comprovadas:

1. Histórico staff sem paginação: a consulta retornava tudo com `get()`, apenas por `created_at`, sem desempate. Não havia truncamento backend, mas também não havia carregamento progressivo utilizável.
2. Cliente recebia status somente no POST de criação; o resumo público existente era exclusivo da visita paga. Não havia leitura de status durante a visita.
3. A resolução pública procurava a visita apenas em `table.sessions()`. Depois de transferir a sessão, o vínculo no QR original deixava de resolver, inclusive no reload.
4. A conta mostrava totais/pagamentos, sem itens/adicionais; a falha não oferecia retry explícito. O rótulo de saldo zero podia sugerir quitação mesmo com `payment_status=unpaid`.
5. O botão de recibo usava a capability de consultar conta, mais ampla que a policy de recibo. Agora segue `record_payments OR close_bill`, conforme a policy existente.
6. O polling público existente não cobria os novos estados e não tratava offline/recuperação de foco/rede e Retry-After de forma completa. Uma falha transitória também apagava a resolução, eliminando a condição necessária para continuar reconciliando.

## Histórico staff e paginação

Reutilizados `OrderController`, `OrderResource`, `OrderItemResource`, `OrderItemModifierResource`, `ordersService`, `TableOrdersList` e `TableDetailsDrawer`.

O modo paginado é opt-in no endpoint existente:

`GET /api/v1/orders?restaurant_id=R&table_session_id=S&per_page=20&before_id=ID`

`restaurant_id` e `table_session_id` são obrigatórios nesse modo. `per_page` aceita 1–100; o drawer solicita 20. `meta.next_before_id` é o cursor seguinte ou null. A ordenação por ID decrescente é estável e o cursor não perde pedidos antigos quando um novo pedido chega entre páginas. A lista não filtra status/origem. O contrato legado das filas continua disponível; sua ordenação ganhou ID como desempate de timestamps iguais.

O botão de carregar anteriores mantém as linhas já consultadas. A reconciliação relê a profundidade carregada e deduplica por ID. Como em qualquer paginação de uma lista viva, novas entradas podem deslocar a fronteira da página; os pedidos anteriores continuam acessíveis pelo próximo cursor. Aprovação/serviço atualizam a linha com a resposta autoritativa; não removem o pedido do histórico.

`useSessionOrderHistory` concentra paginação, erro, carregamento, cancelamento e geração de contexto. Mudança de sessão/restaurante invalida leituras anteriores, inclusive A→B→A; desmontagem impede novas leituras. O drawer também reinicia seu estado quando muda a identidade da sessão, mesmo na mesma mesa. Detalhes continuam no popup existente, com quantidades, snapshots de nome/preço/adicionais, observações, status e valores. Não foi criado segundo detalhe ou histórico em Pinia.

## Visibilidade e status públicos

Não há token individual de pedido no domínio atual. `X-Table-Visit` é o token opaco da sessão, compartilhado pelo grupo da visita, persistido por QR em `sessionStorage` e em memória da aba. Não se declara isolamento por pessoa.

Foi acrescentado o GET correspondente à rota pública de pedidos já existente:

`GET /api/v1/public/tables/{publicToken}/orders`, com `X-Table-Visit` obrigatório e `before_id` opcional.

São 20 pedidos por página, com `meta.next_before_id`. Restaurante/sessão são derivados do QR validado e do vínculo. O endpoint só mostra pedidos `customer_qr` da visita autorizada, incluindo pendentes, confirmados, estados de serviço e cancelados. Não aceita IDs de sessão/restaurante/pedido para selecionar outro contexto. Pedidos lançados por staff ficam fora desse acompanhamento durante a visita.

A projeção `PublicOrderTrackingResource` reutiliza os snapshots públicos de `PublicVisitOrderItemResource`. Não retorna nome do cliente, observações pessoais/internas, identidades de staff, dados de pagamento, credenciais ou token da visita. A resposta usa `Cache-Control: no-store`. O resumo pago `/public/visits/{token}` permanece com seu contrato anterior de consumo billable da visita, inclusive pedidos staff; isso não foi ampliado nem recriado.

A carta oferece **Pedidos de la mesa / Comandes de la taula / Table orders**, em `ABottomSheet`, com estados legíveis, vazio, loading, erro/retry e carregar anteriores. `waiting_approval` permanece aguardando aprovação; `confirmed` não é apresentado como aceite de cozinha. A rejeição existente produz `cancelled`; não foi inventado estado backend `rejected`, etapa ou estimativa.

A criação continua usando o mesmo POST, idempotência, aprovação e contexto. Após criar, o acompanhamento faz leitura autoritativa. Após reload, a entrada retoma o mesmo vínculo e o GET recupera os pedidos; não depende de uma lista mantida apenas em memória.

## Polling, realtime e contexto

O acompanhamento público amplia o scheduler de resolução existente: um único timer e uma execução de reconciliação por vez. Cada ciclo aguarda a resolução e depois o histórico; páginas carregadas são lidas sequencialmente. Chamadas simultâneas compartilham a leitura pendente. Não há assinatura pública de canal staff ou novo canal com dados sensíveis.

Cadência base de 12 s, backoff limitado, respeito a Retry-After, pausa de novas leituras offline/em aba oculta e reconciliação ao recuperar foco/rede/visibilidade. Erros de autorização/contexto encerram as leituras daquele contexto. Retry manual também respeita Retry-After. Respostas transitórias preservam a resolução vinculada, permitindo retry sem abrir outra sessão. Timer/listeners são removidos na desmontagem; respostas tardias são descartadas.

Staff continua usando os eventos/fallback existentes, via `refreshKey` do snapshot operacional. Conta e histórico reconciliam a partir desse sinal. Echo, distribuição de garçons, filas, canais e eventos de atribuição não foram reescritos.

## Transferência, visita anterior e nova ocupação

A identidade da sessão e seus pedidos continuam iguais durante transferência. A resolução pública agora aceita o vínculo no QR original quando existe a associação atual **ou transferência auditada daquela sessão saindo daquela mesa, no mesmo restaurante**. Após isso, retorna a mesa atual da sessão. A reentrada revalida o QR efetivamente escaneado sob lock; não compara o QR original com o token da mesa de destino.

Não basta apresentar o token de outra visita do restaurante. QR inexistente/revogado ou restaurante/mesa sem disponibilidade pública continua negado. O vínculo antigo não segue a nova ocupação da mesa de origem. GET de acompanhamento não abre sessão, atribui garçom, backfilla token nem cria atividade. Pós-visita e `TABLE_SESSION_ALREADY_PAID` continuam preservados; a visita encerrada não volta a aceitar pedidos.

## Conta e regra canônica

Reutilizados `GET /api/v1/table-sessions/{id}/bill`, `TableSessionPolicy::viewBill`, `RestaurantScope`, `SessionBillCalculator`, `TableBillPanel` e `tableSessionsService`.

Estados cobrados: **confirmed, accepted, preparing, ready, served**. Excluídos: **waiting_approval e cancelled**, inclusive rejeição. O histórico completo conserva todos; os itens apresentados na conta são os que o backend sinaliza com `billable=true`, derivado de `Order::billableStatuses()`. Não há regra financeira nova no frontend.

O resource da conta acrescenta `items` com snapshots e `billable` a cada resumo de pedido, preservando os demais campos e a lista completa do contrato. O painel mostra itens/quantidades/adicionais cobrados, total, pagamentos registrados, saldo e estado de pagamento. Não há campos de descontos/taxas/ajustes separados nesse contrato; não foram inventados. Pagamento parcial já é suportado: permanece `payment_status=unpaid` até quitar, com `paid_total` e `balance` refletindo os registros.

Totais continuam em strings decimais calculadas pelo backend em centavos, a partir dos pedidos e PaymentRecords persistidos. Não se somam valores no frontend nem se recalcula usando catálogo atual. Total zero carregado é distinto de loading/falha; saldo zero sozinho não vira rótulo de conta paga. A leitura usa AbortSignal, impede resposta de outra sessão e oferece retry.

Consultar não solicita conta, registra pagamento, marca pago, fecha mesa ou muda pedido. Controles de pagamento existentes continuam exclusivamente atrás de `record_payments`; consultar e cobrar permanecem capacidades diferentes. O teste negativo remove apenas essa permissão na fixture e comprova GET permitido/POST negado. Não se limitou o garçom a suas próprias mesas.

## Segurança e preservação

Consultas staff mantêm tenant ativo, RestaurantScope e policy por restaurante. O modo paginado acrescenta filtros obrigatórios; trocar IDs não contorna o backend. O público exige vínculo válido no QR/contexto correto e nunca seleciona sessão pelo ID enviado pelo visitante. Foram testados sessão/restaurante estrangeiros, mesa de outra visita, token inválido, QR inexistente e tentativas de IDs extras.

Branches preservadas: API `fix/security-hardening`, web `main`. Snapshot inicial e patches específicos: `/tmp/session-history-baseline`. Os arquivos preexistentes fora do manifesto desta etapa mantiveram seus hashes. Não houve reset, clean, restore, stash, staging, commit, push ou deploy. Nenhuma migration nova; a migration QR anterior foi preservada e aplicada apenas como parte das migrations normais dos bancos novos.

Não foram editados .env, staging, Docker, manifests/lockfiles ou infraestrutura. Nenhum backlog foi consumido/apagado, worker iniciado ou mensagem externa enviada. Testes usaram exclusivamente PostgreSQL local loopback 127.0.0.1:55441 e DB_URL vazio/configuração temporária isolada. Configurações temporárias não alteraram drivers/domínios/origens do projeto ou do ambiente habitual.

**Ocorrência anterior preservada:** `storage/app/inspect_user.php` e `storage/app/seed_dev_scenario.php` já tinham a formatação incidental registrada no relatório de atribuição. Não foram executados, reformatados nem restaurados nesta etapa. Seus hashes continuam iguais aos do início desta tarefa. Isso não recupera os bytes anteriores ao incidente, que continuam sem fonte confiável.

## Testes automatizados

Banco novo `session_history_testing`, sem suítes backend simultâneas no mesmo banco. Relatórios de execução em `/tmp/session-history-*.log`.

- Rodada ampliada: **551 testes, 550 aprovados, 2.083 assertions e um erro** em `OrderDetailContractTest::test_complex_order_detail_returns_the_full_contract_with_correct_math`: função preexistente `bcadd()` indisponível porque o PHP local não carrega BCMath. Não foi alterado o teste nem instalada extensão para ocultar a limitação. Esta rodada não é declarada totalmente verde.
- Regressão após a correção de transferência: **145 testes aprovados, 602 assertions**, abrangendo entrada QR, idempotência, menu, pedidos, solicitações, elegibilidade de conta, transferência e novos testes. A flag billable adicionada depois foi coberta pela rodada focada final.
- Rodada focada final, com contrato final da conta: **23 testes aprovados, 143 assertions**. Histórico em todos os estados, paginação com inserção entre páginas, nova ocupação, transferência/reload, acesso público autorizado, conta sem mutações, snapshots após editar preço/adicional, pagamento parcial, permissions e negativos de tenant/restaurante. As rodadas se sobrepõem; não se somam seus totais como cenários distintos.
- **12 arquivos PHP exatos** passaram em sintaxe e Pint; nenhum formatador recebeu diretórios ou scripts de storage. Diff check no escopo passa reconhecendo CRLF preexistente como fim de linha (`cr-at-eol`, somente flag da invocação, sem editar configuração git ou normalizar arquivos inteiros).
- `npm run build` passou com TypeScript (`vue-tsc -b`) e Vite no código final.
- `frontend-session-history.mjs`: **22 checks funcionais** com componentes/composables/services reais e HTTP controlado; **144 combinações** de público/conta × sucesso/erro/loading/vazio × três idiomas × Light/Dark × 375/768/1440. Sem overflow, alvos novos menores que 44 px, chaves de tradução ausentes ou erros de página.
- `frontend-approvals.mjs`: **85 checks funcionais + 48 responsivos**, sem erros de página. Preserva aprovação e as superfícies da fila/drawer.
- `frontend-qr-entry.mjs`: **37 checks + 18 combinações responsivas**, sem erros de página. A fixture distingue agora o novo GET de acompanhamento do POST de pedido; as verificações de não criar pedidos continuam inspecionando POSTs.

As suítes montadas controlam HTTP/transporte; não demonstram broadcast real. Screenshots foram inspecionadas em `/tmp`, incluindo conta e histórico público em Light/Dark e o drawer real em mobile/desktop. A skill local `.claude/skills/ui-ux-pro-max/SKILL.md`, obrigatória pelo CLAUDE.md, foi aplicada; componentes/tokens existentes foram reutilizados. O status público usa texto com contraste de superfície e não depende apenas de cor.

## Validação real local, separada das fixtures HTTP

Vue real em localhost:5174, API real em localhost:55442 e banco novo **session_history_validation** na instância isolada. Usuários, restaurante, sessões, catálogo e pedidos foram criados apenas nesse banco. Broadcast null apenas na configuração temporária; nenhum worker foi iniciado. Não houve escrita no banco habitual.

Com agent-browser e sessões staff/anônima separadas:

1. Entrada anônima pelo QR real e clique em Ver la carta; a tela Pedidos de la mesa recuperou 20 pedidos QR e carregar anteriores chegou a **22**, sem o pedido staff. Reload conservou o vínculo e recuperou novamente o acompanhamento.
2. Login real do garçom e abertura do drawer de History 1 em Servicio. Histórico: **23 pedidos**, incluindo o servido lançado por staff, após carregar anteriores.
3. Ver cuenta mostrou somente o pedido billable servido e seu adicional: **11,75 €**, pagamento registrado **1,00 €**, saldo **10,75 €**, estado unpaid. A consulta não acionou pagamento/fechamento.
4. Aprovação por clique normal de um pedido QR da fixture, **waiting_approval → confirmed**. O pedido continuou no histórico; a conta reconciliou para **23,50 €** e saldo **22,50 €**. O banco continuou com 23 pedidos e um pagamento; a sessão continuou occupied/unpaid.
5. O navegador anônimo passou a mostrar **Confirmado** para esse pedido pelo polling REST, sem reload manual. Nenhum /accept, preparo, pagamento novo ou fechamento foi acionado nessa interação.
6. Drawer real inspecionado em 375/1440 e acompanhamento público em 375; conta/histórico sem overflow e sem erros de página. Requests relevantes de conta responderam 200.

Essas verificações reais foram automatizadas por navegador em ambiente isolado. **Não equivalem à rodada manual do usuário no ambiente habitual nem validam WebSocket real ou fluxo completo até a cozinha.** Transferência, erros de rede/Retry-After e negativos de autorização foram comprovados pelas suítes, não por uma rodada manual real desta etapa.

## Checklist manual pendente

- No ambiente habitual, enviar QR com aprovação exigida, abrir histórico da sessão no drawer, aprovar e conferir que o pedido permanece, que a conta atualiza e que o cliente mostra o estado correto após polling/reload.
- Percorrer cozinha até servido e rejeitar outro pedido, conferindo estados traduzidos, histórico e exclusão financeira do cancelado/pendente. Não presumir aceite de cozinha ao aprovar.
- Com mais de uma página de pedidos, carregar anteriores; trocar mesa/restaurante durante leitura; repetir em mobile e nos idiomas usados pela equipe.
- Conferir conta, pagamentos existentes e saldo sem clicar em pagar/fechar. Simular falha e recuperar por retry; conta zero real não deve ser confundida com erro.
- Transferir a sessão e reabrir o QR original: histórico/vínculo devem seguir a sessão e mostrar a mesa atual. Encerrar normalmente e abrir nova ocupação: visita antiga deve manter seu pós-visita e não enxergar/fazer pedidos na nova sessão.
- Recuperar rede/foco e testar o realtime habitual de staff/reconexão; cliente continua usando REST público.

Pendências anteriores preservadas: nova atribuição/notificação por WebSocket real, reatribuição/reconexão; aprovação por clique normal → **evento real de status → cozinha** no ambiente habitual; revisão/recuperação dos dois scripts ignorados pelo histórico local, se houver fonte confiável. O clique local com polling desta etapa não encerra essas pendências. Produtos esgotados, abertura diária/caixa inicial, regras financeiras novas, cadastros e deploy não foram iniciados.

## Estado final do ambiente

Os navegadores reais, API/Vite temporários e PostgreSQL isolado foram encerrados. Bancos, fixtures, configurações e logs permanecem em `/tmp/session-history-*` e no cluster temporário parado `/tmp/waiter-isolated-pg`. Serviços preexistentes em 5432/5173/8080/8081 não foram parados. O banco habitual não foi modificado ou recriado.

## Arquivos desta etapa

Lista comparada ao snapshot inicial, incluindo arquivos antes untracked. Os status/diffs git completos ao final também contêm trabalho anterior e não devem ser atribuídos integralmente a esta etapa.

### restaurants-api

- `app/Actions/Public/EnterPublicTableAction.php`
- `app/Actions/Public/ResolvePublicTableAction.php`
- `app/Http/Controllers/Api/V1/OrderController.php`
- `app/Http/Controllers/Api/V1/Public/PublicOrderController.php`
- `app/Http/Controllers/Api/V1/TableSessionBillController.php`
- `app/Http/Resources/Api/V1/BillOrderSummaryResource.php`
- `app/Http/Resources/Api/V1/Public/PublicOrderTrackingResource.php`
- `app/OpenApi/ApiDocumentation.php`
- `routes/api.php`
- `tests/Feature/Order/SessionOrderHistoryTest.php`
- `tests/Feature/Public/PublicOrderTrackingTest.php`
- `tests/Feature/TableSession/BillTest.php`
- `docs/QR-session-history-bill-report.md`

### restaurants-web

- `src/components/dashboard/table/TableBillPanel.vue`
- `src/components/dashboard/table/TableDetailsDrawer.vue`
- `src/components/dashboard/table/TableOrdersList.vue`
- `src/components/public/PublicOrderHistory.vue`
- `src/composables/usePublicTableResolution.ts`
- `src/composables/useSessionOrderHistory.ts`
- `src/i18n/locales/ca-ES-valencia.ts`
- `src/i18n/locales/en-GB.ts`
- `src/i18n/locales/es-ES.ts`
- `src/services/orders.service.ts`
- `src/services/public-table.service.ts`
- `src/services/table-sessions.service.ts`
- `src/types/public-menu.ts`
- `src/types/table-sessions.ts`
- `src/views/public/PublicTableView.vue`
- `tests/frontend-qr-entry.browser.ts`
- `tests/frontend-session-history.browser.ts`
- `tests/frontend-session-history.html`
- `tests/frontend-session-history.mjs`
- `docs/QR-session-history-bill-report.md`

## Git final dos dois projetos

`git diff --stat` não inclui arquivos novos untracked. Saídas completas, com alterações anteriores preservadas.

### restaurants-api

`git status --short`

```text
 M app/Actions/Operations/BuildRestaurantOperationsSnapshotAction.php
 M app/Actions/Orders/CreatePublicOrderAction.php
 M app/Actions/Orders/OrderCreationService.php
 M app/Actions/Public/ResolvePublicTableAction.php
 M app/Actions/Staff/EndStaffShiftAction.php
 M app/Actions/Staff/StartStaffShiftAction.php
 M app/Actions/Staff/UpdateStaffAction.php
 M app/Actions/TableRequests/CreatePublicTableRequestAction.php
 M app/Actions/Tables/AssignWaiterAction.php
 M app/Actions/Tables/OpenTableAction.php
 M app/Actions/Tables/TransferTableSessionAction.php
 M app/Actions/Tables/UnassignWaiterAction.php
 M app/Events/Realtime/RealtimeEvent.php
 M app/Events/Realtime/RestaurantActivityCreated.php
 M app/Http/Controllers/Api/V1/OrderController.php
 M app/Http/Controllers/Api/V1/Public/PublicMenuController.php
 M app/Http/Controllers/Api/V1/Public/PublicOrderController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableRequestController.php
 M app/Http/Controllers/Api/V1/RestaurantActivityController.php
 M app/Http/Controllers/Api/V1/TableSessionBillController.php
 M app/Http/Resources/Api/V1/BillOrderSummaryResource.php
 M app/Http/Resources/Api/V1/Public/PublicSessionStateResource.php
 M app/Http/Resources/Api/V1/RestaurantActivityEventResource.php
 M app/Http/Resources/Api/V1/TableSessionResource.php
 M app/Models/RestaurantActivityEvent.php
 M app/Models/TableSession.php
 M app/Models/UserRole.php
 M app/OpenApi/ApiDocumentation.php
 M app/Providers/AppServiceProvider.php
 M app/Support/Activity/RestaurantActivityReadState.php
 M app/Support/Activity/RestaurantActivityType.php
 M app/Support/Operations/OperationAlertBuilder.php
 M app/Support/Tables/ResponsibleWaiterResolver.php
 M app/Support/Tables/WaiterAssignmentEligibility.php
 M bootstrap/app.php
 M config/broadcasting.php
 M package.json
 M routes/api.php
 M tests/Feature/Activity/ActivityRecordingTest.php
 M tests/Feature/Activity/ActivityTimelineTest.php
 M tests/Feature/Activity/RestaurantActivityToneTest.php
 M tests/Feature/AuditLog/AuditLogTableSessionAndRequestTest.php
 M tests/Feature/AuditLog/AuditLogWaiterAssignmentTest.php
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
 M tests/Feature/Realtime/EventPayloadContractTest.php
 M tests/Feature/RestaurantSettings/FeatureTogglesTest.php
 M tests/Feature/Security/PublicAbuseTest.php
 M tests/Feature/Security/SecurityCloseoutTest.php
 M tests/Feature/TableRequest/TableRequestLifecycleTest.php
 M tests/Feature/TableSession/BillTest.php
 M tests/Feature/TableSession/PaidSessionBlocksTest.php
?? .env.staging.example
?? .oh-my-sdd/
?? app/Actions/Public/EnterPublicTableAction.php
?? app/Actions/Tables/EnsureSessionWaiterAction.php
?? app/Exceptions/Public/PublicVisitInvalidException.php
?? app/Http/Resources/Api/V1/Public/PublicOrderTrackingResource.php
?? app/Support/Tables/WaiterAssignmentLock.php
?? database/migrations/2026_10_21_000001_allow_public_table_session_opening.php
?? docs/QR-auto-open-report.md
?? docs/QR-local-validation-report.md
?? docs/QR-session-history-bill-report.md
?? docs/QR-waiter-assignment-report.md
?? docs/pre-9.2-manager-permissions.md
?? package-lock.json
?? tests/Concerns/InteractsWithPublicVisits.php
?? tests/Feature/Order/SessionOrderHistoryTest.php
?? tests/Feature/Public/PublicOrderTrackingTest.php
?? tests/Feature/Public/PublicTableEntryConcurrencyTest.php
?? tests/Feature/Public/PublicTableEntryTest.php
?? tests/Feature/TableSession/AutomaticWaiterAssignmentTest.php
?? tests/Support/
```

`git diff --stat`

```text
 .../BuildRestaurantOperationsSnapshotAction.php    | 12 +++++-
 app/Actions/Orders/CreatePublicOrderAction.php     | 12 ++++--
 app/Actions/Orders/OrderCreationService.php        |  8 +++-
 app/Actions/Public/ResolvePublicTableAction.php    | 40 ++++++++++++++++-
 app/Actions/Staff/EndStaffShiftAction.php          |  2 +
 app/Actions/Staff/StartStaffShiftAction.php        |  2 +
 app/Actions/Staff/UpdateStaffAction.php            |  6 +++
 .../CreatePublicTableRequestAction.php             | 14 ++++--
 app/Actions/Tables/AssignWaiterAction.php          | 30 ++++++++++---
 app/Actions/Tables/OpenTableAction.php             | 25 ++++++++---
 app/Actions/Tables/TransferTableSessionAction.php  |  8 +++-
 app/Actions/Tables/UnassignWaiterAction.php        | 11 +++--
 app/Events/Realtime/RealtimeEvent.php              |  5 +++
 app/Events/Realtime/RestaurantActivityCreated.php  |  8 +++-
 app/Http/Controllers/Api/V1/OrderController.php    | 26 ++++++++++-
 .../Api/V1/Public/PublicMenuController.php         |  4 +-
 .../Api/V1/Public/PublicOrderController.php        | 47 +++++++++++++++++++-
 .../Api/V1/Public/PublicTableController.php        | 35 +++++++++++++--
 .../Api/V1/Public/PublicTableRequestController.php |  4 +-
 .../Api/V1/RestaurantActivityController.php        | 29 +++++++++----
 .../Api/V1/TableSessionBillController.php          |  2 +-
 .../Resources/Api/V1/BillOrderSummaryResource.php  |  5 ++-
 .../Api/V1/Public/PublicSessionStateResource.php   | 17 ++++----
 .../Api/V1/RestaurantActivityEventResource.php     |  4 ++
 app/Http/Resources/Api/V1/TableSessionResource.php |  9 +++-
 app/Models/RestaurantActivityEvent.php             |  5 +++
 app/Models/TableSession.php                        | 13 ++----
 app/Models/UserRole.php                            |  2 +-
 app/OpenApi/ApiDocumentation.php                   | 10 +++--
 app/Providers/AppServiceProvider.php               |  7 +++
 .../Activity/RestaurantActivityReadState.php       | 27 +++++++++---
 app/Support/Activity/RestaurantActivityType.php    | 10 +++++
 app/Support/Operations/OperationAlertBuilder.php   |  6 +++
 app/Support/Tables/ResponsibleWaiterResolver.php   |  2 +-
 app/Support/Tables/WaiterAssignmentEligibility.php | 16 ++++++-
 bootstrap/app.php                                  |  6 +++
 config/broadcasting.php                            |  4 ++
 package.json                                       |  3 ++
 routes/api.php                                     |  4 ++
 tests/Feature/Activity/ActivityRecordingTest.php   |  7 ++-
 tests/Feature/Activity/ActivityTimelineTest.php    |  5 ++-
 .../Activity/RestaurantActivityToneTest.php        |  2 +
 .../AuditLogTableSessionAndRequestTest.php         |  4 +-
 .../AuditLog/AuditLogWaiterAssignmentTest.php      |  3 ++
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
 .../Feature/Realtime/EventPayloadContractTest.php  | 16 +++++++
 .../RestaurantSettings/FeatureTogglesTest.php      | 18 ++++----
 tests/Feature/Security/PublicAbuseTest.php         | 16 ++++---
 tests/Feature/Security/SecurityCloseoutTest.php    |  6 ++-
 .../TableRequest/TableRequestLifecycleTest.php     |  4 +-
 tests/Feature/TableSession/BillTest.php            |  2 +-
 .../Feature/TableSession/PaidSessionBlocksTest.php | 24 ++++++-----
 62 files changed, 581 insertions(+), 204 deletions(-)
```

### restaurants-web

`git status --short`

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
 M src/components/dashboard/table/TableBillPanel.vue
 M src/components/dashboard/table/TableDetailsDrawer.vue
 M src/components/dashboard/table/TableOrdersList.vue
 M src/components/public/PublicPostVisitView.vue
 M src/components/public/PublicProductFeed.vue
 M src/components/service/OrderDetailSheet.vue
 M src/components/shared/ActivityBell.vue
 M src/components/shared/ActivityEventItem.vue
 M src/components/shared/ActivityPanel.vue
 M src/composables/useOperationalTableDeepLink.ts
 M src/composables/useOrderApprovals.ts
 M src/composables/usePublicEntryState.ts
 M src/composables/usePublicMenu.ts
 M src/composables/usePublicTableResolution.ts
 M src/composables/useReadyOrderNotifications.ts
 M src/composables/useRestaurantActivity.ts
 M src/composables/useRestaurantOperations.ts
 M src/composables/useRestaurantRealtime.ts
 M src/config/env.ts
 M src/config/runtime.ts
 M src/i18n/locales/ca-ES-valencia.ts
 M src/i18n/locales/en-GB.ts
 M src/i18n/locales/es-ES.ts
 M src/main.ts
 M src/realtime/echo.ts
 M src/services/orders.service.ts
 M src/services/product.service.ts
 M src/services/public-table.service.ts
 M src/services/restaurant-activity.service.ts
 M src/services/table-sessions.service.ts
 M src/types/activity.ts
 M src/types/operations.ts
 M src/types/public-menu.ts
 M src/types/table-sessions.ts
 M src/utils/activity-presentation.ts
 M src/utils/error-message.ts
 M src/utils/table-public-url.ts
 M src/views/DashboardView.vue
 M src/views/LoginView.vue
 M src/views/kitchen/KitchenView.vue
 M src/views/public/PublicTableView.vue
 M src/views/service/ServiceView.vue
 M tests/frontend-hardening-build.mjs
 M tests/frontend-hardening.mjs
 M tests/frontend-security.browser.ts
 M vite.config.ts
?? .claude/skills/ui-ux-pro-max/scripts/__pycache__/
?? docs/QR-auto-open-report.md
?? docs/QR-popup-approval-validation-report.md
?? docs/QR-realtime-validation-report.md
?? docs/QR-session-history-bill-report.md
?? docs/QR-waiter-approval-fix-report.md
?? docs/QR-waiter-assignment-report.md
?? src/assets/images/aforro-agency-restauramt-pexels-imgs.jpg:Zone.Identifier
?? "src/assets/images/ref/imagem 1.jpg:Zone.Identifier"
?? "src/assets/images/ref/imagem 2.webp:Zone.Identifier"
?? "src/assets/images/ref/imagem 3.png:Zone.Identifier"
?? src/components/public/PublicMenuCatalog.vue
?? src/components/public/PublicOrderHistory.vue
?? src/composables/useSessionOrderHistory.ts
?? src/utils/order-approval.ts
?? src/utils/public-visit-context.ts
?? src/views/aforo-dashboard.html:Zone.Identifier
?? t.txt
?? tests/frontend-approvals.browser.ts
?? tests/frontend-approvals.html
?? tests/frontend-approvals.mjs
?? tests/frontend-assignment.browser.ts
?? tests/frontend-assignment.html
?? tests/frontend-assignment.mjs
?? tests/frontend-qr-entry.browser.ts
?? tests/frontend-qr-entry.html
?? tests/frontend-qr-entry.mjs
?? tests/frontend-realtime.browser.ts
?? tests/frontend-realtime.html
?? tests/frontend-realtime.mjs
?? tests/frontend-session-history.browser.ts
?? tests/frontend-session-history.html
?? tests/frontend-session-history.mjs
```

`git diff --stat`

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
 src/components/dashboard/table/TableBillPanel.vue  |   37 +-
 .../dashboard/table/TableDetailsDrawer.vue         |   10 +-
 src/components/dashboard/table/TableOrdersList.vue |  102 +-
 src/components/public/PublicPostVisitView.vue      |    4 +
 src/components/public/PublicProductFeed.vue        |   17 +-
 src/components/service/OrderDetailSheet.vue        |   18 +-
 src/components/shared/ActivityBell.vue             |   16 +-
 src/components/shared/ActivityEventItem.vue        |   11 +
 src/components/shared/ActivityPanel.vue            |    1 +
 src/composables/useOperationalTableDeepLink.ts     |   12 +-
 src/composables/useOrderApprovals.ts               |  122 +-
 src/composables/usePublicEntryState.ts             |    7 +
 src/composables/usePublicMenu.ts                   |    5 +-
 src/composables/usePublicTableResolution.ts        |   55 +-
 src/composables/useReadyOrderNotifications.ts      |   37 +-
 src/composables/useRestaurantActivity.ts           |  152 +-
 src/composables/useRestaurantOperations.ts         |    9 +-
 src/composables/useRestaurantRealtime.ts           |  349 +-
 src/config/env.ts                                  |   82 +-
 src/config/runtime.ts                              |    4 +-
 src/i18n/locales/ca-ES-valencia.ts                 | 3243 ++++-----
 src/i18n/locales/en-GB.ts                          | 3243 ++++-----
 src/i18n/locales/es-ES.ts                          | 3257 ++++-----
 src/main.ts                                        |   64 +-
 src/realtime/echo.ts                               |  188 +-
 src/services/orders.service.ts                     |    7 +
 src/services/product.service.ts                    |  148 +-
 src/services/public-table.service.ts               |   30 +-
 src/services/restaurant-activity.service.ts        |   12 +-
 src/services/table-sessions.service.ts             |    4 +-
 src/types/activity.ts                              |    7 +
 src/types/operations.ts                            |    1 +
 src/types/public-menu.ts                           |   11 +
 src/types/table-sessions.ts                        |    3 +
 src/utils/activity-presentation.ts                 |    2 +
 src/utils/error-message.ts                         |    1 +
 src/utils/table-public-url.ts                      |   70 +-
 src/views/DashboardView.vue                        |    8 +-
 src/views/LoginView.vue                            |  500 +-
 src/views/kitchen/KitchenView.vue                  |    9 +-
 src/views/public/PublicTableView.vue               |  432 +-
 src/views/service/ServiceView.vue                  |   94 +-
 tests/frontend-hardening-build.mjs                 |  154 +-
 tests/frontend-hardening.mjs                       |  270 +-
 tests/frontend-security.browser.ts                 |    2 +-
 vite.config.ts                                     |   56 +-
 57 files changed, 10946 insertions(+), 10175 deletions(-)
```
