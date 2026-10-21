# Disponibilidade operacional de produtos (esgotado / disponível)

Relatório único desta tarefa (API + web). Iniciada pelo Codex em 2026-10-21
(~21:22–21:38), interrompida por limite de uso e concluída/validada por
Claude Code na mesma data. Sem commit, push ou deploy.

## Ponto de retomada

Estado em 2026-10-21 ~22:45: **implementação completa, testes afetados verdes,
apenas a rodada manual integrada do usuário pendente.** Se esta tarefa for
retomada, não reimplementar nada: executar o checklist manual abaixo e, se
algo falhar, corrigir pontualmente nos arquivos listados em "Arquivos desta
etapa".

## Onde o Codex havia parado (evidências)

- Baseline criado antes de editar: `/tmp/product-availability-baseline/{restaurants-api,restaurants-web}`
  (cópias dos arquivos, `hashes.json`, `status`, `stat`, às 21:22:28) e
  `task-files.json` (21:37:21) com a lista exata dos arquivos da tarefa.
- A comparação de hashes baseline × workspace atual retornou exatamente os
  arquivos de `task-files.json` (11 modificados na API, 17 no web, mais os
  novos) — nenhuma outra alteração ocorreu depois do baseline.
- Logs em `/tmp/availability-*.log`: PHPUnit focado 57/57 (21:30), regressão
  242/242 (21:33), build web (21:30 e 21:38:43), browser de fixture 19
  checks × 18 combinações (21:35 e 21:38:56), regressões web de QR, realtime,
  histórico e aprovações (21:36–21:37).
- Últimas edições às 21:38:24. Comparando com os diffs salvos às 21:37
  (`/tmp/availability-*-diff.txt`):
  - web: ajustes em `ServiceOrderComposer`, `useCategoryProducts`,
    `ServiceView` e locales — cobertos pelo build e browser final (21:38:43 /
    21:38:56);
  - API: só refactors em `OrderCreationService` (import em vez de FQCN) e
    `RestaurantProductController::updateAvailability` (reuso de
    `restaurantProductQuery()`) — **sem PHPUnit depois**. Este foi o único
    buraco de evidência; os testes foram executados novamente (ver abaixo).
- Nenhum relatório em `docs/` havia sido escrito para esta tarefa.
- Processos deixados pelo Codex, ainda ativos e isolados: Postgres temporário
  `/tmp/availability-pg` na porta 55443 (DBs `availability_testing` e
  `availability_validation`), `php artisan serve` na 55442 (config
  `/tmp/availability-validation-config.php`), Vite na 5174 e dois daemons
  `agent-browser` (as sessões continuam no polling público de acompanhamento).
  Não usam o Postgres do Sail (5432) nem o `.env`.

## O que já existia e foi preservado

Abertura QR/vínculo de visita, aprovação, realtime/fallback, atribuição de
garçons, notificações, histórico/status/conta, catálogo, segurança e
configuração de staging: nenhum desses arquivos foi tocado por esta retomada.
O diff acumulado do workspace pertence majoritariamente a tarefas anteriores.

Já existia e foi reutilizado: o campo `restaurant_products.available` (por
restaurante), as atividades `product.marked_available` /
`product.marked_unavailable` em `UpdateRestaurantProductAction`, o lock
`RestaurantOperationalLock`, o canal privado `private-restaurant.{id}`, o
`no-store` do menu público e o filtro do menu público que já oculta produtos
indisponíveis.

`storage/app/inspect_user.php` e `storage/app/seed_dev_scenario.php`
(formatação incidental registrada em tarefa anterior) não foram executados,
reformatados nem restaurados — mtime continua 2026-10-21 20:08:50.

## Implementado (Codex) e validado/completado (Claude Code)

### Regras de disponibilidade

- Três estados distintos: `products.status = active` (publicado no catálogo
  da organização), `restaurant_products.available = true` (disponível naquele
  restaurante) e `available = false` (esgotado temporariamente). Esgotar não
  altera `products`, preço, descrição, categoria nem exclui nada.
- O flag é por `restaurant_product`; um produto compartilhado esgota apenas
  no restaurante alterado (teste
  `test_other_restaurant_and_tenant_are_protected_and_shared_product_stays_local`).
- Sem estoque quantitativo e sem restauração automática diária.

### Permissão

- Nova permission restrita `manage_product_availability` (padrão de
  `PermissionSeeder`/`RolePermissionSeeder`): owner, manager e waiter. Kitchen
  e cashier não recebem.
- `RestaurantProductPolicy::updateAvailability` aceita
  `manage_product_availability` **ou** `manage_products`, sempre com acesso ao
  restaurante. `viewAny` (listar produtos do restaurante) passou a aceitar
  as mesmas duas permissions, para o garçom ver o painel; `create`/`update`
  continuam exigindo `manage_products`.
- Provisioning aditivo e idempotente para instalações existentes:
  `Database\Seeders\ProductAvailabilityPermissionSeeder` (`updateOrCreate` +
  `syncWithoutDetaching`; não remove grants). **Não foi executado no banco
  habitual.**

### Endpoint

`PATCH /api/v1/restaurant-products/{restaurantProduct}/availability`
body `{ "available": boolean }`; `price`, `product_id`, `restaurant_id` são
`prohibited` (422). Escopo por organização do tenant (404 fora dela), policy
por restaurante (403). Resposta `data.restaurant_product` (inclui `media`).
Repetir o mesmo valor é idempotente (sem nova atividade nem evento).

### Bloqueio de pedidos e concorrência

- `BuildOrderItemsAction` agora carrega todos os `restaurant_products` do
  pedido com `lockForUpdate()` em ordem de `id`, filtrando pelo restaurante,
  e valida `available` + `product.status = active` para **cada** item antes de
  persistir qualquer coisa. Vale para QR e staff (mesmo serviço). ID de outro
  restaurante/tenant ou arbitrário → `422 INVALID_ORDER_ITEM`. Pedido misto
  inválido não cria pedido nem item (rollback da transação).
- Ordem de locks respeitada: `RestaurantOperationalLock` (shared) →
  `WaiterAssignmentLock` → sessão → produtos. `UpdateRestaurantProductAction`
  toma o `RestaurantOperationalLock` e relê a linha com `lockForUpdate()`.
  Resultado: ou o esgotamento é visto pelo pedido, ou o pedido commita com o
  snapshot e o esgotamento acontece depois — provado com workers PHP
  independentes que realmente esperam no Postgres.
- Idempotência QR: dentro da transação, depois dos locks, um pedido já
  existente com a mesma chave é devolvido como replay **antes** das
  validações de sessão/disponibilidade. Replay legítimo funciona mesmo se o
  produto esgotou depois; payload diferente → `409 IDEMPOTENCY_KEY_REUSED`;
  chave nova com produto esgotado → 422.

### Pedidos existentes

- Nada é cancelado, editado, rejeitado ou recalculado. Snapshots de
  nome/descrição/preço e totais ficam intactos; a conta não usa o catálogo
  atual (teste compara a resposta da conta antes/depois de mudar preço e
  disponibilidade).
- **Regra vigente de `waiting_approval` (preservada, não alterada):**
  `ApproveOrderAction` não revalida disponibilidade. Um pedido QR criado
  antes do esgotamento continua aprovável e é aprovado com o snapshot
  original; a decisão operacional fica com o staff, que pode rejeitá-lo
  manualmente pelo fluxo existente. Nenhuma regra nova foi inventada.

### Eventos e cache

- `ProductAvailabilityChanged` (`product.availability_changed`) no canal
  privado existente `private-restaurant.{id}`; payload mínimo
  `restaurant_product_id` (só invalidação). Disparado apenas quando
  `available` muda; segue o envelope e o after-commit de `RealtimeEvent`
  (rollback não emite evento nem atividade — testado). Não há canal público.
- Menu público já é `Cache-Control: no-store` e lido do banco; não há cache
  de servidor a invalidar.
- `docs/realtime.md`: adicionada a linha do evento ao catálogo (feito nesta
  retomada).

### Frontend

- Garçom (`ServiceView` → `ProductAvailabilityPanel` +
  `useProductAvailability`): lista do restaurante atual com busca, status
  Disponível/Esgotado em texto, botão por produto com loading próprio,
  bloqueio de duplo clique, erro inline sem alterar o estado, estado
  aplicado somente a partir da resposta do backend, descarte de respostas
  obsoletas e limpeza imediata ao trocar de restaurante.
- Refresh: sem polling novo nem segundo Echo. `useCatalogRefresh` registra as
  leituras de catálogo no ciclo `useRestaurantRealtime` já existente da tela
  (Service, Dashboard, Menu), com o mesmo backoff/visibilidade.
- Composer staff: produto esgotado não entra no carrinho, linhas inválidas
  são marcadas, envio bloqueado; após `INVALID_ORDER_ITEM`/
  `INVALID_MODIFIER_SELECTION` recarrega produtos em background.
- Cliente QR: a carta segue o padrão existente (indisponível não aparece);
  o menu é relido em background pelo polling de acompanhamento já existente.
  Carrinho antigo mantém itens e observações, marca a linha
  ("No disponible. Puedes eliminar este artículo."), permite remover e
  bloqueia o envio enquanto houver item inválido. Rejeição final da API é
  traduzida, o carrinho não é limpo e não há reenvio automático (a chave de
  idempotência é renovada para a próxima tentativa explícita).
- Textos novos em `es-ES`, `ca-ES-valencia`, `en-GB` (bloco `availability`;
  CRLF original dos arquivos preservado). Botões de quantidade/remover dos
  carrinhos passaram de 36 px para 44 px.

## BCMath — diagnóstico

- Teste afetado: `Tests\Feature\Order\OrderDetailContractTest::test_complex_order_detail_returns_the_full_contract_with_correct_math`
  usa `bcadd()` diretamente na assertion (código de app não usa BCMath).
- Runtime habitual da API (Sail `sail-8.5/app`, PHP 8.5.9): **BCMath presente**
  (verificado agora via `docker exec ... extension_loaded('bcmath')`).
- PHP CLI do WSL (8.4.24), usado para os testes isolados: **sem BCMath**.
  Reproduzido agora: sem a extensão o teste erra com "Call to undefined
  function bcadd"; com a extensão passa (34 assertions).
- Ambiente de testes temporário (Codex): `php8.4-bcmath` baixado e extraído
  em `/tmp/availability-php` (sem instalar no sistema), carregado via
  `PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d:/tmp/availability-php/conf.d`.
- Definição reproduzível: `composer.json` `require-dev` ganhou
  `"ext-bcmath": "*"` e o `composer.lock` só teve `platform-dev` e
  `content-hash` atualizados (nenhum pacote mudou). `composer validate` ok e
  `check-platform-reqs` ok no container. Consequência: `composer install` com
  dev num PHP sem BCMath passa a falhar explicitamente, em vez de o teste
  falhar em runtime. Nada de float, nenhuma assertion removida.

## Testes

### Executados nesta retomada (Postgres isolado 55443, DB `availability_testing`)

Comando: `APP_CONFIG_CACHE=/tmp/availability-config.php PHP_INI_SCAN_DIR=... vendor/bin/phpunit ...`
(nome do banco conferido antes: `availability_testing`, porta 55443; nenhuma
outra suíte rodando).

- `tests/Feature/RestaurantProduct` + `tests/Feature/Order` +
  `PublicOrderIdempotencyTest` + `PublicOrderTest`: **108/108, 405 assertions**.
- `OrderDetailContractTest` (BCMath): falha sem a extensão, passa com ela.
- `tests/Feature/{Public,TableSession,Security,Realtime,TableRequest}`:
  **635 testes, 611 ok, 24 erros**, todos em `AdversarialCatalogTest` (23) e
  `MediaWriteFailureTest` (1) com
  `FilesystemIterator ... storage/framework/testing/disks/public/products: Permission denied`
  — diretório `root:root 700` criado em 2026-10-09 (provável execução como
  root no container). Ambiental, anterior e não relacionado; não foi
  alterado. Para limpar: `sudo chown -R dudu:dudu storage/framework/testing`
  (decisão do usuário).

### Do Codex, ainda válidos (sem edição posterior relevante)

- `ProductAvailabilityTest` / `ProductAvailabilityConcurrencyTest`: permissão
  garçom/kitchen/cashier, outro restaurante/tenant, produto compartilhado,
  endpoint antigo, carrinho antigo e pedido misto, IDs arbitrários no staff,
  replay + aprovação + conta, provisioning idempotente, concorrência real
  (sell-first, order-first, replay sobreposto), evento after-commit/rollback.
- Web: `npm run build` (vue-tsc + vite) ok às 21:38:43;
  `tests/frontend-availability.mjs` 19 checks × 18 combinações
  (375/768/1440 × claro/escuro × 3 idiomas) às 21:38:56 — componentes reais
  com HTTP de fixture (mocks). Regressões de QR (37), realtime (35,
  maxInFlight 1), histórico (22 × 144) e aprovações (85) às 21:36–21:37.

### Integração real (parcial)

API isolada (55442) + Vite (5174) com fixture próprio: o log da API registra
`PATCH /restaurant-products/1/availability` e pedidos QR/staff reais às
21:33–21:35; capturas `/tmp/availability-real-*.png` mostram o painel do
garçom e a carta vazia com produto esgotado. Não é a rodada manual.

## Migrations

Nenhuma migration nesta tarefa. (`2026_10_21_000001_allow_public_table_session_opening.php`
é de tarefa anterior.) No ambiente habitual é preciso apenas provisionar a
permission, quando o usuário decidir:
`php artisan db:seed --class=Database\\Seeders\\ProductAvailabilityPermissionSeeder`
(aditivo; não executado aqui).

## Checklist manual (rodada final do usuário)

1. Provisionar `manage_product_availability` no banco habitual com o seeder acima.
2. Garçom: Serviço → "Disponibilidad de productos" → marcar esgotado e voltar;
   conferir loading só no item, duplo clique sem efeito, preço/descrição intactos.
3. Kitchen/cashier: painel não aparece; PATCH direto → 403.
4. Restaurante B com o mesmo produto continua disponível.
5. QR: com o carrinho aberto contendo o produto, esgotar no staff; em até um
   ciclo de acompanhamento o item fica marcado, envio bloqueado, remover o item
   libera o restante.
6. QR: enviar com carrinho antigo antes do refresh → erro traduzido, carrinho intacto.
7. Staff composer: produto esgotado não adiciona; pedido misto rejeitado sem criar nada.
8. Pedido QR `waiting_approval` criado antes do esgotamento: ainda aprovável,
   nome/preço/total originais; conta não muda ao alterar preço depois.
9. Realtime (com Reverb + worker): segundo dispositivo atualiza o painel após o
   toggle; sem Reverb, o fallback existente atualiza.
10. Trocar de restaurante com o painel aberto: lista limpa e recarrega.
11. Claro/escuro, 3 idiomas, 375/768/1440, foco visível.

## Pendências mantidas

- Rodada manual integrada (acima) e as rodadas manuais pendentes das
  tarefas QR anteriores.
- Permissões de `storage/framework/testing` (24 erros ambientais).
- Processos isolados do Codex ainda ativos (Postgres 55443, API 55442,
  Vite 5174, `agent-browser`). Para encerrar quando não forem mais úteis:
  `pkill -f "port=55442"; pkill -f "port 5174"; pkill -f agent-browser-linux-x64`
  e `/usr/lib/postgresql/16/bin/pg_ctl -D /tmp/availability-pg/data stop`.
- `storage/app/inspect_user.php` e `storage/app/seed_dev_scenario.php`:
  formatação incidental de tarefa anterior, sem cópia confiável dos originais.

## Arquivos desta etapa

### restaurants-api

Modificados: `app/Actions/Catalog/UpdateRestaurantProductAction.php`,
`app/Actions/Orders/BuildOrderItemsAction.php`,
`app/Actions/Orders/CreatePublicOrderAction.php`,
`app/Actions/Orders/OrderCreationService.php`,
`app/Http/Controllers/Api/V1/RestaurantProductController.php`,
`app/Policies/RestaurantProductPolicy.php`, `composer.json`, `composer.lock`,
`database/seeders/PermissionSeeder.php`,
`database/seeders/RolePermissionSeeder.php`, `routes/api.php`,
`docs/realtime.md` (retomada).

Novos: `app/Events/Realtime/ProductAvailabilityChanged.php`,
`app/Http/Requests/Api/V1/RestaurantProduct/UpdateProductAvailabilityRequest.php`,
`database/seeders/ProductAvailabilityPermissionSeeder.php`,
`tests/Feature/RestaurantProduct/ProductAvailabilityTest.php`,
`tests/Feature/RestaurantProduct/ProductAvailabilityConcurrencyTest.php`,
`tests/Support/product-availability-worker.php`,
`docs/QR-product-availability-report.md` (este).

Observação: vários desses arquivos já tinham alterações de tarefas
anteriores; o diff desta tarefa é o delta contra
`/tmp/product-availability-baseline`.

### restaurants-web

Modificados: `src/components/public/PublicCartSheet.vue`,
`src/components/service/ServiceCartSheet.vue`,
`src/components/service/ServiceOrderComposer.vue`,
`src/composables/useCategoryProducts.ts`, `src/composables/usePublicMenu.ts`,
`src/composables/useRestaurantProducts.ts`, `src/i18n/locales/{es-ES,ca-ES-valencia,en-GB}.ts`,
`src/services/restaurant-product.service.ts`, `src/types/auth-context.ts`,
`src/types/realtime.ts`, `src/utils/error-message.ts`,
`src/views/DashboardView.vue`, `src/views/menu/MenuView.vue`,
`src/views/public/PublicTableView.vue`, `src/views/service/ServiceView.vue`.

Novos: `src/components/service/ProductAvailabilityPanel.vue`,
`src/composables/useProductAvailability.ts`,
`src/composables/useCatalogRefresh.ts`,
`tests/frontend-availability.{browser.ts,html,mjs}`.

## Git final (2026-10-21 ~22:45)

- restaurants-api (`fix/security-hardening`): `git diff --stat` →
  71 files changed, 654 insertions(+), 221 deletions(-); 97 entradas em
  `git status --short` (inclui este relatório como não rastreado).
- restaurants-web (`main`): `git diff --stat` → 66 files changed,
  11096 insertions(+), 10205 deletions(-) (acumulado, incluindo conversões
  CRLF de tarefas anteriores); 105 entradas em `git status --short`.
