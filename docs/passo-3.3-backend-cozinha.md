# RELATÓRIO — PASSO 3.3 BACKEND COZINHA

Validação em 14/09/2026, no repositório `restaurants-api`, usando a aplicação local em `http://localhost:8080`, PostgreSQL e Reverb existentes. Nenhuma alteração em `restaurants-web`, commit ou push.

## 1. Estado inicial

Branch `develop`, sincronizada com `origin/develop`, árvore limpa. Executados `git status`, `git diff`, `git diff --stat` e `git log --oneline -10` antes de editar. HEAD: `f7b508c` (`test: cover sibling restaurant operational isolation`). Não havia `CLAUDE.md` ou `AGENTS.md` no repositório.

Inspecionados rotas, OpenAPI, seeders de permissions/roles/demo, policies, actions de criação/aprovação/rejeição/lifecycle, controllers/resources de pedidos/KDS/comanda, PrintRecord, RestaurantScope, eventos, channels e testes relacionados.

## 2. Status reais do pedido

Sete status: `waiting_approval`, `confirmed`, `accepted`, `preparing`, `ready`, `served`, `cancelled`. Prefixo dos endpoints: `/api/v1`.

| Origem | Action | Destino | Permission | Endpoint POST |
|---|---|---|---|---|
| Novo QR, exige aprovação | CreatePublicOrderAction | waiting_approval | Público, token da mesa | /public/tables/{publicToken}/orders |
| Novo QR, dispensa aprovação | CreatePublicOrderAction | confirmed | Público, token da mesa | /public/tables/{publicToken}/orders |
| Novo pedido manual | CreateStaffOrderAction | confirmed | create_orders | /tables/{table}/orders |
| waiting_approval, customer_qr | ApproveOrderAction | confirmed | approve_customer_orders | /orders/{order}/approve |
| waiting_approval, customer_qr | RejectOrderAction | cancelled | approve_customer_orders | /orders/{order}/reject |
| confirmed | TransitionOrderStatusAction::accept | accepted | update_kitchen_status | /orders/{order}/accept |
| accepted | TransitionOrderStatusAction::startPreparing | preparing | update_kitchen_status | /orders/{order}/preparing |
| preparing | TransitionOrderStatusAction::markReady | ready | update_kitchen_status | /orders/{order}/ready |
| ready | TransitionOrderStatusAction::serve | served | serve_orders | /orders/{order}/served |

Não existe transição direta `confirmed → preparing`. `served` e `cancelled` são terminais nesse lifecycle. A rejeição não cancela pedidos já confirmados. Owner/manager possuem as permissions da cadeia; waiter cria/aprova/rejeita/serve; kitchen aceita/prepara/marca pronto.

## 3. Kitchen permissions

HTTP login e context confirmaram `kitchen1@aforo.test`, usuário 18, role `kitchen`, organização 12 (`AFORO Demo`), restaurante 12 (`AFORO Malvarrosa`), exclusivamente `update_kitchen_status`. Sem permissions da plataforma ou atribuição organizacional ampla. `kitchen2@aforo.test` também autenticou para o teste simultâneo.

Nenhum seeder ou grant foi alterado. Kitchen não recebeu `serve_orders`, `manage_menu`, `manage_users`, `manage_organization`, `record_payments` ou `close_bill`.

## 4. Endpoint/listagem KDS

Reutilizado `GET /api/v1/kitchen/orders`. Retorna `data.orders`, filtros opcionais `restaurant_id` e `status`, ordem por `created_at` e `id` ascendentes, limite existente de 100 pedidos. Inclui apenas `confirmed`, `accepted`, `preparing`, `ready`; cada pedido aparece separadamente, mesmo na mesma sessão.

Dados: `id`, `order_number`, `status`, `origin`, restaurante, mesa/número, `order_note`, `created_at`, `elapsed_seconds`, itens/quantidades/notas/modifiers. Sem preços e identidades administrativas. Nenhum endpoint duplicado.

## 5. RestaurantScope

Mantida a composição permission + RestaurantScope. Queries de pedidos e tickets restringem organização e restaurantes acessíveis. Tentativas por ID fora do escopo dão 404; filtro explícito de restaurante no KDS também dá 404. Sem filtro, pedidos externos são omitidos. Falta de permission dentro do escopo dá 403.

Teste HTTP com restaurante B 22, da mesma organização 12, pedido #1803: KDS filtrado, detalhe, ticket, accept/preparing/ready e print retornaram 404; canal privado retornou 403; pedido permaneceu `confirmed`. Restaurante 1, de outra organização, também foi bloqueado no filtro e canal. Cobertura automatizada complementa os dois cenários.

## 6. Kitchen transitions

Actions existentes preservadas. HTTP confirmou accept → preparing → ready. Tentativa de pular `accepted`, repetir etapa ou voltar de ready para accept deu 409. Kitchen tentando served deu 403. As respostas de transição agora usam a projeção KitchenOrder quando o usuário só possui acesso de cozinha.

## 7. Concorrência

A action já abre transação, relê a linha com `lockForUpdate()` e verifica o status dentro do lock. Nenhuma alteração no mecanismo.

HTTP simultâneo, duas sessões reais (`kitchen1` e `kitchen2`), ambos enviando `/preparing` para o mesmo pedido accepted: resultados `[200, 409]` para #1801 e novamente para #1802. Ambos persistiram uma única preparação e seguiram até ready. WebSocket confirmou somente um evento de preparação por pedido.

Novo teste automatizado passa uma instância obsoleta a um segundo cozinheiro: conflito, status/autor vencedor preservados e apenas um evento. Testes existentes cobrem repetições e transições inválidas.

## 8. Realtime

Reutilizados Laravel Reverb, canal `private-restaurant.{id}`, `order.created` e `order.status_changed`. São eventos `ShouldBroadcast` e `ShouldDispatchAfterCommit`: fila existente, publicação após commit, nenhum segundo sistema.

Conexão WebSocket real em localhost:8081; `/broadcasting/auth` autorizou kitchen e waiter no restaurante 12. Assinatura confirmada. Recebidos nove eventos dos pedidos #1801/#1802: duas criações, uma aprovação e seis etapas de cozinha. Os dois eventos ready chegaram pelo socket. Autorização negada para canal de restaurante irmão e outra organização. Testes cobrem rollback/after-commit e contrato dos payloads.

## 9. Waiter ready/served

Waiter consultou os pedidos reais e recebeu `status=ready` e `ready_at` persistido. O evento inclui order_id, table_id, table_session_id e status, suficientes para atualizar/refazer a consulta. Waiter marcou #1799 served via HTTP; o pedido deixou o KDS. Kitchen permaneceu impedida de usar a ação.

## 10. Comanda existente

Comanda por pedido. GET `/orders/{order}/kitchen-ticket` gera documento JSON, sem registro de impressão. POST no sufixo `/kitchen-ticket/print` devolve o documento e cria PrintRecord/auditoria. Não há HTML/PDF, job de impressão física, driver Epson/Star ou confirmação de papel impresso. PrintRecord registra solicitação/geração, não sucesso de impressora.

Pedidos imprimíveis: confirmed, accepted, preparing, ready e served. Waiting_approval/cancelled: 409 `ORDER_NOT_PRINTABLE`.

## 11. Mudanças necessárias na comanda

Adicionado `order.order_number`, no formato existente `#id`. KDS também recebeu `order_number`. Mantidos snapshots de nomes de produtos, grupos e opções, notas e quantidades; testes existentes confirmam que editar o catálogo não reescreve o documento.

Corrigida exposição excessiva fora da comanda: OrderResource consulta `OrderPolicy::viewDetails` e reutiliza KitchenOrderResource para acesso exclusivamente de cozinha, incluindo GET geral, detalhe e transições. Permissões operacionais completas mantêm OrderResource. OpenAPI atualizado e regenerado.

## 12. Impressão

Frontend pode renderizar o JSON e acionar impressão do browser. GET é read-only e não depende do toggle de impressão. POST exige `kitchen_ticket_printing_enabled=true`; retorna 201, `data.print_record_id` e `data.document`.

Reimpressão é repetível, não idempotente no histórico: cada POST cria um registro. Não duplica nem modifica o pedido. Teste automatizado compara todos os atributos persistidos antes/depois de três chamadas e conta um único pedido. No HTTP, #1801 produziu registros 3 e 4 e continuou ready.

## 13. Teste kitchen real

Sanctum via cookie e CSRF, com Origin `http://localhost:5174`; login 200 e context 200 usando as credenciais demo solicitadas. Operações executadas com sessões separadas, sem `actingAs` no teste manual. Não foi necessário reseedar/resetar o banco local.

## 14. Cliente → Waiter → Kitchen

Mesa 03, ID 50, sessão ativa 1447. Configuração de aprovação temporariamente ligada por owner para o cenário e restaurada ao valor anterior `false` em finally.

QR criou #1801 em waiting_approval, ausente do KDS; waiter1 aprovou; cozinha passou a vê-lo confirmed; accept/preparing/ready bem-sucedidos; banco e consulta posterior confirmaram ready. Origin preservado como customer_qr.

## 15. Waiter → Kitchen

Waiter1 criou #1802 na mesma mesa com POST `/tables/50/orders`, diretamente confirmed, mesmo com aprovação pública ligada. Apareceu no mesmo KDS e percorreu as mesmas etapas até ready, com origin waiter.

## 16. Ticket/comanda real

Ticket #1801: AFORO Malvarrosa; Mesa 03/número 3; horário `2026-09-14T16:21:44.000000Z`; origin customer_qr; nota geral `PASSO 3.3 - comanda de validación`.

- 1 × Croquetas de jamón.
- 2 × Hamburguesa AFORO; nota `Sin cebolla`.
- Grupo obrigatório Punto de la carne: Al punto.
- Extras: Queso extra e Bacon.

Todos esses campos foram conferidos no JSON real. Sem preço ou identificação do cliente/cozinheiro no ticket.

## 17. Permissions negativas

HTTP e/ou Feature tests confirmam 403 para cozinha em Carta administrativa, Staff, Settings, editar organização, criar/editar restaurante, registrar pagamento, fechar mesa/conta e served. Tentativas negativas não geraram pagamentos ou fecharam sessões.

Regra existente preservada: GET `/organization` permite a qualquer membro consultar identificação básica (id, nome, slug, status, timestamps); PATCH continua proibido. Isso não concede administração.

## 18. Testes específicos

**321 testes passaram, 1.093 assertions, 225,13 s.**

Comando: `docker compose exec -T laravel.test php artisan test --compact tests/Feature/Kitchen tests/Feature/Printing tests/Feature/Order tests/Feature/Public tests/Feature/Realtime`.

Novo KitchenOperationalAccessTest cobre dados mínimos em seis respostas, bloqueios administrativos, isolamento completo de ticket/transições entre restaurantes irmãos e tentativa obsoleta de segundo cozinheiro. Testes de contrato KDS/ticket e de reimpressão foram ajustados/ampliados. Pint e geração OpenAPI passaram.

## 19. Suíte completa

**1.263 testes passaram, 3.761 assertions, 251,47 s.** Sem falhas, sem skips ocultos.

Comando: `docker compose exec -T laravel.test php artisan test --compact` (suíte inteira, sem filtro de diretório). Executado após a inspeção do diff pendente da sessão anterior; nenhuma alteração de código foi necessária — a suíte fechou verde na primeira execução completa desta retomada.

Pint (`./vendor/bin/pint --test`) reporta 2 problemas de estilo (`routes/api.php`, `tests/Feature/Permissions/RestaurantScopedCatalogWriteTest.php`); confirmado via `git status --porcelain` que nenhum dos dois arquivos foi tocado por este passo — pré-existentes, fora do escopo.

## 20. Arquivos criados/modificados

- `app/Http/Controllers/Api/V1/OrderController.php`: schemas das respostas com projeção condicionada.
- `app/Http/Resources/Api/V1/Kitchen/KitchenOrderResource.php`: order_number.
- `app/Http/Resources/Api/V1/OrderResource.php`: projeção operacional conforme autorização.
- `app/Http/Resources/Api/V1/Printing/KitchenTicketResource.php`: order_number.
- `app/Policies/OrderPolicy.php`: viewDetails, sem novos grants.
- `app/OpenApi/ApiDocumentation.php`: contratos KDS/ticket.
- `storage/api-docs/api-docs.json`: documentação regenerada.
- `tests/Feature/Kitchen/KitchenOperationalAccessTest.php`: novo.
- `tests/Feature/Kitchen/KitchenQueueTest.php`: contrato.
- `tests/Feature/Printing/KitchenTicketTest.php`: contrato e ausência de mutação na reimpressão.
- `docs/passo-3.3-backend-cozinha.md`: este relatório/contrato de integração.

## 21. Git final

Branch `develop`, sincronizada com `origin/develop`. Nenhum commit, nenhum push. Árvore de trabalho idêntica ao início desta retomada (9 arquivos modificados, 2 novos — ver seção 20); apenas este documento foi editado para preencher os resultados pendentes.

```
M app/Http/Controllers/Api/V1/OrderController.php
M app/Http/Resources/Api/V1/Kitchen/KitchenOrderResource.php
M app/Http/Resources/Api/V1/OrderResource.php
M app/Http/Resources/Api/V1/Printing/KitchenTicketResource.php
M app/OpenApi/ApiDocumentation.php
M app/Policies/OrderPolicy.php
M storage/api-docs/api-docs.json
M tests/Feature/Kitchen/KitchenQueueTest.php
M tests/Feature/Printing/KitchenTicketTest.php
?? docs/passo-3.3-backend-cozinha.md
?? tests/Feature/Kitchen/KitchenOperationalAccessTest.php
```

## 22. Bugs/Pendências

Corrigidos: ausência de order_number no KDS/ticket; dados financeiros/identidades desnecessárias devolvidos à cozinha pelos endpoints gerais/transições.

Limitações existentes: KDS limitado aos 100 pedidos mais antigos por filtro, sem paginação; elapsed_seconds conta desde criação (inclui espera por aprovação); realtime é best-effort, deve haver refetch; impressão não confirma dispositivo físico. Não foi acrescentada funcionalidade fora do passo.

Dados locais de validação mantidos para inspeção: #1799 served, #1800/#1801/#1802 ready no restaurante 12; #1803 confirmed no restaurante B 22 (`PASSO 3.3 - Scope B`), mesma organização. #1799/#1800 vieram da primeira execução manual, interrompida por uma expectativa incorreta do teste sobre GET organization, depois corrigida. Quatro PrintRecords (duas chamadas por #1799 e #1801). Configuração de aprovação restaurada. Nenhuma credencial/cookie foi incluída neste relatório.

## 23. Contrato que o frontend deve usar

### Autenticação

`GET /sanctum/csrf-cookie`, cookies com credentials; `POST /api/v1/auth/login` com `{"email":"kitchen1@aforo.test","password":"password"}` e `X-XSRF-TOKEN`; `GET /api/v1/auth/context` retorna `data.user`, `data.platform`, `data.organizations[].restaurants[].roles/permissions`. Use os IDs e permissions do context, sem presumir que todo membro da organização acessa todos os restaurantes.

### GETs operacionais

- `/api/v1/kitchen/orders?restaurant_id={id}&status={status}` → 200 `{"data":{"orders":[KitchenOrder]}}`; ambos os filtros opcionais, status restrito à fila ativa.
- `/api/v1/orders/{id}` → 200 `{"data":{"order":KitchenOrder}}` para cozinha; Order completo para demais permissions operacionais.
- `/api/v1/orders?restaurant_id={id}&status=ready` → 200 `{"data":{"orders":[Order]}}` para waiter; também aceita table_id e table_session_id. Para cozinha, cada objeto usa KitchenOrder.
- `/api/v1/orders/{id}/kitchen-ticket` → 200 `{"data":KitchenTicket}`.

KitchenOrder:

```json
{
  "id": 1801,
  "order_number": "#1801",
  "status": "ready",
  "origin": "customer_qr",
  "restaurant": {"id": 12, "name": "AFORO Malvarrosa"},
  "table": {"id": 50, "name": "Mesa 03", "number": 3},
  "order_note": "PASSO 3.3 - comanda de validación",
  "created_at": "2026-09-14T16:21:44.000000Z",
  "elapsed_seconds": 5,
  "items": [{
    "id": 3607,
    "name": "Hamburguesa AFORO",
    "quantity": 2,
    "note": "Sin cebolla",
    "modifiers": [{"group_name": "Punto de la carne", "name": "Al punto"}]
  }]
}
```

O exemplo abrevia os itens/modifiers; arrays reais incluem todos os selecionados. Notas e table.number podem ser null. KitchenTicket contém exatamente `document_type`, `restaurant`, `order:{id,order_number,status,origin,created_at}`, `table`, `order_note`, `items` (mesmo contrato KitchenOrderItem) e `generated_at`.

### POST/PATCH

- POST `/api/v1/orders/{id}/accept`, `/preparing`, `/ready`: sem campos obrigatórios, corpo `{}`; 200 `{"message":"...","data":{"order":KitchenOrder}}` para kitchen. Ordem obrigatória conforme tabela do lifecycle.
- POST `/api/v1/orders/{id}/served`: waiter; `{}`; 200 `{"message":"Order marked as served.","data":{"order":Order}}`.
- POST `/api/v1/orders/{id}/approve` ou `/reject`: waiter/owner/manager com approve_customer_orders; `{}`; 200 `{"message":"...","data":{"order":Order}}`.
- POST `/api/v1/orders/{id}/kitchen-ticket/print`: `{}`; 201 `{"data":{"print_record_id":3,"document":KitchenTicket}}`. Cada clique é uma solicitação registrada; não representa confirmação física.
- Nenhum PATCH novo para status. Backend decide a transição; não envie um status arbitrário.

Criação manual: POST `/api/v1/tables/{id}/orders`; criação QR: POST `/api/v1/public/tables/{token}/orders`. Payload usado na validação:

```json
{
  "locale": "es-ES",
  "note": "PASSO 3.3 - comanda de validación",
  "items": [
    {"restaurant_product_id": 3, "quantity": 1},
    {"restaurant_product_id": 8, "quantity": 2, "modifier_option_ids": [8, 10, 11], "note": "Sin cebolla"}
  ]
}
```

IDs são dados demo: em uso, obtê-los no catálogo. Locale explícito deve constar em enabled_locales (`es-ES` neste demo). Manual retorna 201 `data.order`; público retorna 201 `data` diretamente como PublicOrder, incluindo id/order_number/status/valores/itens. Público aceita Idempotency-Key e retorna 200 em replay válido.

Erros: 401 sem sessão, 403 sem permission, 404 fora do escopo, 409 transição inválida/repetida com `{"message":"This order cannot transition to '...'."}`, 422 filtro/payload inválido. Em 409, refazer GET antes de oferecer nova ação. Ticket não imprimível/toggle desativado usam o contrato `error.code/message` existente.

### Realtime

POST `/broadcasting/auth` (fora de /api/v1), mesma sessão/CSRF, payload `{"socket_id":"1234.5678","channel_name":"private-restaurant.12"}`; sucesso retorna `auth`. Em Laravel Echo, `private('restaurant.12')` e listeners `.order.created` / `.order.status_changed`.

Envelope: `event_id` (UUID), `schema_version:1`, `occurred_at` (ISO UTC), `restaurant_id`.

- `order.created`: envelope + `table_id`, `table_session_id`, `order_id`, `origin`, `status`, `created_at`.
- `order.status_changed`: envelope + `table_id`, `table_session_id`, `order_id`, `previous_status`, `status`, `changed_at`.

Entrada na cozinha: created com status confirmed, ou status_changed para confirmed após aprovação. Pronto: status_changed com status ready. Served remove o pedido da fila. Subscrever e refazer GET do KDS ao confirmar a assinatura; refazer GET ao receber eventos, reconectar e periodicamente como fallback. Kitchen deve usar KDS, não operations/live (não possui view_operations). Deduplicar eventos por event_id; REST é autoritativo.

## VEREDITO

BACKEND PASSO 3.3 APROVADO — FLUXO DE COZINHA E COMANDA PRONTO
