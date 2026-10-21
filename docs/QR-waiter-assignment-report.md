# QR e staff — atribuição de garçom e notificação interna

21 de outubro de 2026 · Europe/Madrid. Sem commit, push ou deploy.

## Resultado e comportamento anterior

A responsabilidade já era `table_sessions.assigned_waiter_user_id`, alterada por `AssignWaiterAction`/`UnassignWaiterAction`, com auditoria e eventos after-commit. A transferência conserva a mesma sessão. Abertura por staff e POST público de entrada não invocavam atribuição; não havia seleção por demanda nem aviso durável de nova atribuição. O aviso de pedido pronto usava exclusivamente realtime. A central persistente `restaurant_activity_events`/`restaurant_activity_reads` já existia, mas seu acesso completo era restrito a `view_activity`.

Agora, um garçom elegível que abre uma sessão fica responsável por ela. Owner/manager/cashier sem papel operacional de garçom não viram responsáveis pelo simples ato de abrir: usam a distribuição. O POST de QR mantém o responsável válido ou seleciona um candidato; sem candidato, preserva atendimento e pendência. Pedidos e solicitações também reconciliam o responsável daquela sessão. GETs, carta e polling permanecem sem efeitos de atribuição.

O trabalho anterior de QR/visita opaca, aprovação no drawer, idempotência de pedidos, reconexão e fallback foi preservado. Aprovar continua sendo `waiting_approval → confirmed`; aceite da cozinha é outra ação. A validação manual anterior de clique normal → evento de status → cozinha segue pendente e não bloqueou esta tarefa.

## Arquitetura reaproveitada

- `WaiterAssignmentEligibility`, associação `organization_users`, vínculo `restaurant_users` e papel `waiter` por restaurante.
- `StaffShift` (`ended_at IS NULL`), presença operacional já representada no sistema.
- Ações existentes de atribuição, desatribuição e transferência, policies `assign_waiters`, `RestaurantScope` e tenant ativo.
- `RestaurantOperationalLock`, transações e locks de mesa/sessão; índice parcial existente de uma sessão ativa por mesa.
- `AuditLogger`, `RestaurantActivityRecorder`, catálogo de atividade, paginação, cursor de leitura por usuário/restaurante e central de notificações.
- Eventos `WaiterAssigned`, `WaiterReassigned`, `WaiterUnassigned`, `RestaurantActivityCreated` e canais privados já existentes. Nenhuma regra de autorização de canal/origem foi ampliada.
- Operations Live, alertas de sessão sem responsável/fora de turno/suspenso, drawer, AButton, i18n e navegação operacional por mesa.

Não existia outro distribuidor automático. Não foi criado segundo sistema de atribuição, tabela de notificações, permission, scheduler, push ou biblioteca de UI.

## Regras implementadas

### Elegibilidade, turno, demanda e desempate

Elegibilidade base: usuário não suspenso, associação **ativa** à organização, vínculo explícito ao restaurante e papel `waiter` nesse restaurante/organização. O helper anterior verificava existência, mas não status ativo da associação: essa lacuna foi corrigida tanto no caminho pontual como na leitura em lote.

Seleção automática no conjunto de funcionários: exige também turno existente aberto no restaurante. WebSocket, presença online e login recente não participam. Demanda é o número de sessões com `status != closed` atribuídas ao candidato naquele restaurante, incluindo uma sessão paga que ainda não foi encerrada. Pedidos, quantidade de clientes e outras métricas não são inventados.

Desempate: menor demanda; depois, quem nunca recebeu atribuição registrada ou cujo `MAX(audit_logs.created_at)` de atribuição/reatribuição no restaurante é mais antigo; depois, menor ID. São registros auditados existentes, inclusive atribuições manuais. Sem histórico ou com timestamps iguais, o ID torna o resultado determinístico; não se afirma rotação perfeita nesses empates.

A atribuição manual existente e a autoatribuição ao próprio garçom que abre a mesa usam a elegibilidade base. A regra anterior **não exige turno para atribuição explícita**: foi preservada. A sessão fora de turno continua com o alerta operacional existente; abrir mesa não fabrica/inicia turno. Uma atribuição válida não é removida apenas por terminar o turno. O conjunto de distribuição automática, porém, só considera turnos abertos.

### Abertura, repetição e nova ocupação

`OpenTableAction` atribui apenas na criação real. Reutilização de sessão não apropria a responsabilidade de outro funcionário, nem altera convidados/horário/pedidos. `EnterPublicTableAction` reconcilia a sessão recuperada pelo vínculo de visita e ignora sessão encerrada/paga para atribuição. O público não escolhe IDs de funcionário/restaurante; os dados vêm do QR validado e da sessão.

O responsável fica na sessão; nova ocupação não lê o garçom da sessão anterior. Não havia seleção explícita no formulário de abertura a preservar. PUT/DELETE de atribuição manual continuam com as permissões existentes.

`AssignWaiterAction` é o único escritor da atribuição efetiva, também para o automático. Registra `source` na auditoria e na atividade (`staff_open`, `qr_entry`, `order`, `table_request`, `transfer` ou `manual`). Relê e trava o usuário antes de validar, inclusive se o objeto recebido tiver ficado desatualizado. Atribuir novamente o mesmo responsável elegível não cria auditoria/notificação/evento duplicado.

### Responsável inválido e transferência

Vínculo inativo, remoção do restaurante, papel removido ou suspensão não aparecem como atendimento normal: a projeção retorna responsável nulo e `assignment_status=pending`; os alertas operacionais indicam intervenção. A projeção em lote evita consultas por sessão. Suspensão e ausência de turno mantêm os alertas existentes.

A próxima entrada, pedido ou solicitação reconcilia **somente a sessão afetada**: reatribui se houver candidato, ou desatribui com auditoria e registra pendência. Atualização de staff e início/fim de turno participam da proteção da distribuição. Não houve redistribuição global de mesas ao editar funcionário. A transferência conserva um responsável válido e reconcilia um inválido; a ação também impede destino de outro restaurante.

O link de notificação carrega identidade da sessão e mesa atual. Servicio resolve a sessão no snapshot atual; uma transferência entre listar a notificação e clicar não abre uma nova ocupação da mesa antiga.

## Concorrência e limites transacionais

Ordem: trava operacional compartilhada do restaurante (namespace existente 9101), trava transacional exclusiva de atribuição do mesmo restaurante (namespace 9102), locks de mesa/sessão e leitura travada dos candidatos. A trava de atribuição cobre **leitura de demanda e escrita**, até commit/rollback, de modo que duas aberturas em mesas diferentes não escolham pelo mesmo snapshot anterior. Chamadas aninhadas readquirem travas já detidas pela transação.

Abertura, POST de entrada, pedidos/solicitações que reconciliam, atribuição/desatribuição manual e transferência adquirem a trava antes das linhas. Mudanças de elegibilidade de staff adquirem as travas dos restaurantes da organização em ordem crescente antes de escrever usuário/vínculos; início/fim de turno adquirem a do restaurante. Isso pode serializar alterações de staff com distribuição em outros restaurantes da mesma organização, mas não redistribui nem atualiza seus funcionários.

A elegibilidade é consultada sob essa proteção; locks compartilhados de usuário também coordenam com suspensão global. Sessão única por mesa continua garantida pelo índice parcial anterior. A transação de domínio inclui auditoria e atividade durável. Todos os eventos continuam `ShouldDispatchAfterCommit`; rollback não publica nem deixa notificação.

Teste real PostgreSQL: quatro processos independentes aguardaram simultaneamente a trava; ao liberar, quatro mesas ficaram distribuídas **2 + 2**. A corrida da mesma mesa com público/staff também continua produzindo uma única sessão. Não se apresenta execução sequencial ou Event::fake como prova dessa concorrência.

## Notificação durável e ausência de candidato

`RestaurantActivityRecorder` grava `table_session.waiter_assigned` com `recipient_user_id`, responsável anterior e origem. A gravação ocorre junto à atribuição. `RestaurantActivityCreated` usa o canal administrativo de atividade já autorizado e o canal privado **do próprio destinatário**, `user.{id}`. Publicação é um sinal; não comprova leitura/entrega.

A mesma API de atividade aceita `scope=assignments`: `serve_orders` permite ler **somente as próprias atribuições**; `assign_waiters` também permite ler pendências. Lista, unread-count e marcação de leitura usam o mesmo filtro de destinatário/tenant. Tentar marcar um evento de outro destinatário retorna 422. Sem scope, o feed completo continua exigindo `view_activity`; waiter não ganha acesso ao canal administrativo.

Sem candidato, a sessão permanece sem responsável, continua consultável e aceita pedidos segundo as regras anteriores. É gravada uma ocorrência `table_session.assignment_pending` no feed interno, uma vez até haver atribuição e uma nova ocorrência de pendência. Owner/manager com acesso vigente encontram o aviso no feed; usuários com `assign_waiters` o encontram no scope de atendimento. A sessão sem responsável permanece na fila/alertas de Operations Live. Sem destinatário elegível agora, tanto a sessão como o aviso continuam no banco e o próximo responsável autorizado encontra a necessidade de intervenção. Não há alegação de entrega confirmada.

O histórico da central registra ocorrências passadas; a fila de pendências atuais é a projeção operacional da sessão. Uma atribuição posterior remove o estado pendente da operação, sem apagar histórico.

## Frontend

A campainha/central existente atende também o waiter com leitura restrita. Mostra nova atribuição, badge e botão **Abrir mesa**. O drawer mostra o responsável atual ou **Pendiente de asignación**, com traduções em es-ES, ca-ES-valencia e en-GB. Aprovação continua obedecendo às permissions anteriores e não foi limitada a “minhas mesas”.

Realtime usa o mesmo singleton Echo. O canal de usuário é retido por referência entre a central e os avisos de pedidos prontos: desmontar um consumidor não desconecta o outro. Callbacks de domínio, assinatura e conexão são removidos individualmente. Troca de restaurante/perda de acesso limpa estado e descarta respostas/eventos antigos, inclusive A→B→A. Avisos de pedidos prontos também limpam contexto e ignoram restaurante diferente.

A central recupera REST ao assinar/reassinar, abrir painel ou recuperar foco/rede. Sem conexão, usa fallback de 15 s com backoff, Retry-After, suspensão de leituras em aba oculta/offline e interrupção após negação de autorização. IDs persistidos deduplicam linhas/badges; respostas de unread/page anteriores a um evento novo não sobrescrevem o estado mais recente. Não foi introduzido outro toast de nova mesa.

Foi aplicada a skill local `.claude/skills/ui-ux-pro-max/SKILL.md`, exigida por CLAUDE.md, para revisão de touch targets, contraste/estados e uso de tokens/componentes existentes. Capturas reais e montadas foram inspecionadas fora dos repositórios.

## Testes executados

Somente PostgreSQL local novo, loopback **127.0.0.1:55441**, sem DB_URL externo, banco `testing`. Nenhuma suíte backend compartilhou o banco em execução simultânea.

- Rodada ampliada final: **744 testes passaram, 2.718 assertions**, incluindo sessão/mesa, Public, Operations, Activity, Realtime, auditoria de atribuição, edição/desativação/transação de staff, TableRequest e aviso de pedido pronto.
- Após adicionar a verificação específica de solicitação pública com responsável que perdeu restaurante: **1 teste passou, 4 assertions**. Total de cenários distintos dessas duas rodadas: **745**.
- Concorrência está incluída na rodada ampliada: **2 testes reais, 47 assertions**, processos PHP e conexões PostgreSQL independentes; inclui commit/rollback de notificação.
- **31 arquivos PHP** da tarefa passaram em sintaxe e Pint. `git diff --check` no escopo passa; o whitespace preexistente em `app/Models/UserRole.php` foi preservado e ainda é apontado ao verificar todo `app`.
- Frontend: `npm run build` passou com `vue-tsc -b` e Vite.
- `frontend-assignment.mjs`: **12 checks funcionais**, incluindo fallback sem evento, deduplicação, destinatário, restaurante, A→B→A e remoção de listeners; **36 combinações** de estado, viewport 375/768/1440, tema e idioma, alvo mínimo 44 px, sem overflow/erros de página.
- Regressão `frontend-approvals.mjs`: **85 checks funcionais + 48 responsivos**, sem erros de página.
- Regressão `frontend-qr-entry.mjs`: **37 checks + 18 combinações responsivas**, sem erros de página.
- Regressão `frontend-realtime.mjs`: **35 checks**, máximo de uma leitura concorrente no scheduler, sem erros de página.
- `npm run test:security`: **54 checks adversariais**, transporte/doubles locais e guards placeholder conforme a suíte existente.

As suítes de navegador montadas usam componentes/composables/services reais com HTTP/Echo controlados. Elas não comprovam broadcast real.

A primeira rodada ampliada teve seis falhas de login porque a configuração temporária herdada de uma tarefa anterior não continha o domínio stateful usado pelos testes. A configuração **somente temporária** foi alinhada à lista habitual existente, sem ampliar origens/domínios no projeto. Os 21 testes de status de staff e a rodada ampliada final passaram. As fixtures novas que alternam usuários limpam o guard/sessão para representar clientes independentes.

## Validação real local, separada dos mocks

Aplicação Vue real em servidor temporário localhost:5174, API real em localhost:55442, banco novo **waiter_validation** na mesma instância isolada. Configuração copiada para arquivo temporário com destino exclusivo nesse banco; origens/canais/domínios stateful habituais preservados. Contas novas de fixture, sem alterar contas existentes. Broadcast null somente nessa aplicação temporária; conexão de fila habitual do projeto preservada e nenhum worker iniciado.

1. W1 entrou pela tela real e abriu Validation 3 por clique normal. Banco: sessão atribuída ao próprio W1 (ID 2); UI mostrou seu nome no drawer.
2. Dois contextos anônimos abriram QR de Validation 1 e Validation 2. Banco: atribuições a W2 (ID 3) e W1 (ID 2), respectivamente, considerando a sessão que W1 já atendia. As boas-vindas/carta continuaram acessíveis; nenhum dado pessoal do garçom foi retornado ao público.
3. Refresh dos dois contextos manteve **3 sessões e 3 notificações de atribuição**, sem duplicação.
4. W2 entrou depois da atribuição, em outro navegador. Encontrou a notificação durável pela leitura REST apesar de não ter recebido evento online; abriu a central e o botão **Abrir mesa** levou a Servicio com o drawer de **Validation 1 / Waiter W2**.
5. W1 também abriu a mesa por sua notificação. Nenhum erro de página foi observado nas sessões staff.

Não foram criados/aprovados pedidos nessa validação real nem acionados kitchen/WhatsApp/e-mail. Sem teste manual novo de aprovação. WebSocket real da nova atribuição não foi exercitado; after-commit e canais foram testados no backend, transporte no frontend e persistência por HTTP real.

## Migrations e arquivos

**Nenhuma migration nova.** O responsável já pertence à sessão; origem e destinatário cabem nos metadados whitelisted da auditoria/atividade existente. Pendência atual é derivada da sessão. Reutilizar essas estruturas evita tabela de notificações ou estado paralelo. A migration pública nullable de abertura por QR anterior foi preservada e aplicada apenas como parte das migrations normais dos bancos isolados novos.

Novos arquivos de implementação: `EnsureSessionWaiterAction.php` (reconciliação/distribuição que chama o escritor existente) e `WaiterAssignmentLock.php` (serialização por restaurante). Novo teste `AutomaticWaiterAssignmentTest.php`, novos três arquivos de testes montados frontend e estes relatórios. A prova de concorrência foi acrescentada ao teste público já existente.

Arquivos desta tarefa, comparados ao snapshot inicial, incluindo arquivos anteriormente untracked:

### restaurants-api

- `app/Actions/Operations/BuildRestaurantOperationsSnapshotAction.php`
- `app/Actions/Orders/OrderCreationService.php`
- `app/Actions/Public/EnterPublicTableAction.php`
- `app/Actions/Staff/EndStaffShiftAction.php`
- `app/Actions/Staff/StartStaffShiftAction.php`
- `app/Actions/Staff/UpdateStaffAction.php`
- `app/Actions/TableRequests/CreatePublicTableRequestAction.php`
- `app/Actions/Tables/AssignWaiterAction.php`
- `app/Actions/Tables/EnsureSessionWaiterAction.php`
- `app/Actions/Tables/OpenTableAction.php`
- `app/Actions/Tables/TransferTableSessionAction.php`
- `app/Actions/Tables/UnassignWaiterAction.php`
- `app/Events/Realtime/RestaurantActivityCreated.php`
- `app/Http/Controllers/Api/V1/RestaurantActivityController.php`
- `app/Http/Resources/Api/V1/RestaurantActivityEventResource.php`
- `app/Http/Resources/Api/V1/TableSessionResource.php`
- `app/Models/RestaurantActivityEvent.php`
- `app/OpenApi/ApiDocumentation.php`
- `app/Support/Activity/RestaurantActivityReadState.php`
- `app/Support/Activity/RestaurantActivityType.php`
- `app/Support/Operations/OperationAlertBuilder.php`
- `app/Support/Tables/ResponsibleWaiterResolver.php`
- `app/Support/Tables/WaiterAssignmentEligibility.php`
- `app/Support/Tables/WaiterAssignmentLock.php`
- `tests/Feature/Activity/ActivityRecordingTest.php`
- `tests/Feature/Activity/ActivityTimelineTest.php`
- `tests/Feature/Activity/RestaurantActivityToneTest.php`
- `tests/Feature/AuditLog/AuditLogWaiterAssignmentTest.php`
- `tests/Feature/Public/PublicTableEntryConcurrencyTest.php`
- `tests/Feature/Public/PublicTableEntryTest.php`
- `tests/Feature/TableSession/AutomaticWaiterAssignmentTest.php`

### restaurants-web

- `src/components/dashboard/table/TableDetailsDrawer.vue`
- `src/components/shared/ActivityBell.vue`
- `src/components/shared/ActivityEventItem.vue`
- `src/components/shared/ActivityPanel.vue`
- `src/composables/useOperationalTableDeepLink.ts`
- `src/composables/useReadyOrderNotifications.ts`
- `src/composables/useRestaurantActivity.ts`
- `src/i18n/locales/ca-ES-valencia.ts`
- `src/i18n/locales/en-GB.ts`
- `src/i18n/locales/es-ES.ts`
- `src/realtime/echo.ts`
- `src/services/restaurant-activity.service.ts`
- `src/types/activity.ts`
- `src/types/operations.ts`
- `src/types/table-sessions.ts`
- `src/utils/activity-presentation.ts`
- `src/views/service/ServiceView.vue`
- `tests/frontend-assignment.browser.ts`
- `tests/frontend-assignment.html`
- `tests/frontend-assignment.mjs`

## Preservação e ocorrência fora do escopo

Branches preservadas: API `fix/security-hardening`; web `main`. O snapshot inicial está em `/tmp/waiter-assignment-baseline`. Os arquivos preexistentes versionados/untracked fora da lista acima mantiveram seus hashes. .env, templates, manifests/lockfiles e staging não foram editados por esta tarefa. Não houve reset, clean, restore, stash, commit, push ou deploy; nenhum serviço habitual, conta existente, driver de fila ou infraestrutura habitual foi alterado. O backlog antigo de default não foi consumido/apagado nem processado manualmente. Nenhuma notificação externa foi enviada.

**Ocorrência de preservação:** a seleção inicial do formatador incluiu indevidamente dois scripts ignorados porque `storage/app` também contém o segmento `app`: `storage/app/inspect_user.php` (imports/linhas em branco) e `storage/app/seed_dev_scenario.php` (remoção de imports não usados). Eles **não foram executados**; não houve mudança funcional ou acesso ao banco por esses scripts. O inventário do formatador foi corrigido para aceitar apenas os prefixos `app/` e `tests/`.

Não havia cópia dos bytes desses dois arquivos ignorados no snapshot inicial, e não foi encontrada cópia anterior para uma reversão exata e segura. Portanto, a preservação byte a byte deles **não pode ser declarada**. A formatação incidental permanece e exige revisão/recuperação pelo histórico local, se necessário. Os relatórios e o git não devem ocultar essa exceção, embora arquivos ignorados não apareçam no status.

## Estado final do ambiente

Os quatro navegadores de validação, API/Vite temporários e PostgreSQL temporário foram encerrados. Bancos/fixtures/configurações/logs temporários permanecem em `/tmp/waiter-*`, com a instância parada. Serviços preexistentes em 5173/8080/8081 não foram parados. Não foi modificado ou recriado o banco habitual. A API habitual usa o checkout atual sem precisar de migration desta tarefa.

## Pendências separadas

### Desta tarefa

- Rodada manual do usuário no ambiente habitual: nova atribuição/notificação por WebSocket real, reatribuição e reconexão; os testes não atestam entrega real dessa nova ocorrência.
- Revisar a formatação incidental dos dois scripts ignorados descritos acima; os bytes originais não puderam ser recuperados com segurança.
- Atribuição explícita/autoatribuição ao opener fora de turno continua permitida pela regra existente, sinalizada como fora de turno. Essa distinção está documentada, sem criar disponibilidade fictícia.

### Teste manual anterior de realtime

**Continua pendente:** aprovação por clique normal → evento real de status → cozinha. Preservados QR-popup-approval-validation-report e QR-realtime-validation-report; não se declarou esse trecho aprovado nesta tarefa.

### Próximos itens do checklist

Primeiro, a rodada manual do usuário e revisão deste diff. Histórico/conta, esgotados e abertura diária não foram iniciados nem implementados.

## Git final dos dois projetos

As saídas abaixo incluem alterações anteriores. `git diff --stat` não inclui novos arquivos untracked; não atribuir todo o diff a esta tarefa.

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
 M app/Http/Controllers/Api/V1/Public/PublicMenuController.php
 M app/Http/Controllers/Api/V1/Public/PublicOrderController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableController.php
 M app/Http/Controllers/Api/V1/Public/PublicTableRequestController.php
 M app/Http/Controllers/Api/V1/RestaurantActivityController.php
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
 M tests/Feature/TableSession/PaidSessionBlocksTest.php
?? .env.staging.example
?? .oh-my-sdd/
?? app/Actions/Public/EnterPublicTableAction.php
?? app/Actions/Tables/EnsureSessionWaiterAction.php
?? app/Exceptions/Public/PublicVisitInvalidException.php
?? app/Support/Tables/WaiterAssignmentLock.php
?? database/migrations/2026_10_21_000001_allow_public_table_session_opening.php
?? docs/QR-auto-open-report.md
?? docs/QR-local-validation-report.md
?? docs/QR-waiter-assignment-report.md
?? docs/pre-9.2-manager-permissions.md
?? package-lock.json
?? tests/Concerns/InteractsWithPublicVisits.php
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
 app/Actions/Public/ResolvePublicTableAction.php    | 20 ++++++++-
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
 .../Api/V1/Public/PublicMenuController.php         |  4 +-
 .../Api/V1/Public/PublicOrderController.php        |  3 +-
 .../Api/V1/Public/PublicTableController.php        | 35 +++++++++++++--
 .../Api/V1/Public/PublicTableRequestController.php |  4 +-
 .../Api/V1/RestaurantActivityController.php        | 29 +++++++++----
 .../Api/V1/Public/PublicSessionStateResource.php   | 17 ++++----
 .../Api/V1/RestaurantActivityEventResource.php     |  4 ++
 app/Http/Resources/Api/V1/TableSessionResource.php |  9 +++-
 app/Models/RestaurantActivityEvent.php             |  5 +++
 app/Models/TableSession.php                        | 13 ++----
 app/Models/UserRole.php                            |  2 +-
 app/OpenApi/ApiDocumentation.php                   |  6 ++-
 app/Providers/AppServiceProvider.php               |  7 +++
 .../Activity/RestaurantActivityReadState.php       | 27 +++++++++---
 app/Support/Activity/RestaurantActivityType.php    | 10 +++++
 app/Support/Operations/OperationAlertBuilder.php   |  6 +++
 app/Support/Tables/ResponsibleWaiterResolver.php   |  2 +-
 app/Support/Tables/WaiterAssignmentEligibility.php | 16 ++++++-
 bootstrap/app.php                                  |  6 +++
 config/broadcasting.php                            |  4 ++
 package.json                                       |  3 ++
 routes/api.php                                     |  3 ++
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
 .../Feature/TableSession/PaidSessionBlocksTest.php | 24 ++++++-----
 58 files changed, 483 insertions(+), 198 deletions(-)
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
 M src/services/product.service.ts
 M src/services/public-table.service.ts
 M src/services/restaurant-activity.service.ts
 M src/types/activity.ts
 M src/types/operations.ts
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
?? docs/QR-waiter-approval-fix-report.md
?? docs/QR-waiter-assignment-report.md
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
?? tests/frontend-assignment.browser.ts
?? tests/frontend-assignment.html
?? tests/frontend-assignment.mjs
?? tests/frontend-qr-entry.browser.ts
?? tests/frontend-qr-entry.html
?? tests/frontend-qr-entry.mjs
?? tests/frontend-realtime.browser.ts
?? tests/frontend-realtime.html
?? tests/frontend-realtime.mjs
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
 .../dashboard/table/TableDetailsDrawer.vue         |    3 +-
 src/components/dashboard/table/TableOrdersList.vue |   62 +-
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
 src/composables/usePublicTableResolution.ts        |   44 +-
 src/composables/useReadyOrderNotifications.ts      |   37 +-
 src/composables/useRestaurantActivity.ts           |  152 +-
 src/composables/useRestaurantOperations.ts         |    9 +-
 src/composables/useRestaurantRealtime.ts           |  349 +-
 src/config/env.ts                                  |   82 +-
 src/config/runtime.ts                              |    4 +-
 src/i18n/locales/ca-ES-valencia.ts                 | 3228 ++++-----
 src/i18n/locales/en-GB.ts                          | 3228 ++++-----
 src/i18n/locales/es-ES.ts                          | 3242 ++++-----
 src/main.ts                                        |   64 +-
 src/realtime/echo.ts                               |  188 +-
 src/services/product.service.ts                    |  148 +-
 src/services/public-table.service.ts               |   19 +-
 src/services/restaurant-activity.service.ts        |   12 +-
 src/types/activity.ts                              |    7 +
 src/types/operations.ts                            |    1 +
 src/types/table-sessions.ts                        |    1 +
 src/utils/activity-presentation.ts                 |    2 +
 src/utils/error-message.ts                         |    1 +
 src/utils/table-public-url.ts                      |   70 +-
 src/views/DashboardView.vue                        |    8 +-
 src/views/LoginView.vue                            |  500 +-
 src/views/kitchen/KitchenView.vue                  |    9 +-
 src/views/public/PublicTableView.vue               |  335 +-
 src/views/service/ServiceView.vue                  |   94 +-
 tests/frontend-hardening-build.mjs                 |  154 +-
 tests/frontend-hardening.mjs                       |  270 +-
 tests/frontend-security.browser.ts                 |    2 +-
 vite.config.ts                                     |   56 +-
 53 files changed, 10740 insertions(+), 10109 deletions(-)
```
