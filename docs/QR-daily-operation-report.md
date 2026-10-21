# Ciclo operacional diário — abertura do dia, operação e fechamento

Relatório único do ciclo diário (API + web), 2026-10-21/22, Claude Code.
Sem commit, push ou deploy. Migration **não** aplicada no ambiente habitual.
Correções de 2026-10-22 conduzidas via oh-my-sdd: spec/plan/tasks em
`.oh-my-sdd/specs/daily-operation-fixes/` (constitution da API em
`.oh-my-sdd/constitution.md`).

## Ponto de retomada

Estado em 2026-10-22: **implementação + correções A/B/C/D concluídas e
testadas (backend 2073/2073; web build + regressões verdes); 12/12 tarefas
SDD marcadas.** Próximo passo exato: **executar, em etapa própria e
aprovada, a "Preparação do ambiente habitual" (abaixo, passos 0-6)** e depois
a rodada manual. Nada disso foi executado.

Baselines desta tarefa: web em
`/tmp/claude-1000/-home-dudu-projects/6cf9119e-610e-426b-8e04-ddad0e603bae/scratchpad/web-baseline/src.tar`
(src + tests antes das edições web). Na API, os arquivos desta tarefa estão
listados em "Arquivos desta etapa" (vários já tinham alterações de tarefas
anteriores, preservadas).

## O que já existia (reutilizado)

- **Cierre Diario** (`CloseRestaurantDayAction`, `BuildDayClosePreviewAction`,
  `DayClosePeriodResolver`, `DayCloseReport`, `restaurant_day_closes`): lock
  operacional exclusivo (advisory 9101), idempotência por `Idempotency-Key`,
  períodos encadeados sem lacuna (`period_started_at` = fim do fechamento
  anterior), data de negócio no fuso do restaurante com
  `business_day_cutoff_time` (06:00 padrão; DST correto),
  `unique(restaurant_id, business_date)`, **bloqueio por qualquer sessão de
  mesa ativa (`CLOSE_BLOCKED`, sem fechamento forçado)**, relatório imutável
  com sha256, WhatsApp só após commit, precedência do fundo de caixa
  (sobra do fechamento anterior → padrão do restaurante → informado no fechamento).
- Permissões `close_daily_operation` (owner/manager/waiter/cashier) e
  `view_daily_closes` (owner/manager) — **não alteradas**.
- `RestaurantOperationalLock` (shared para escritores, exclusive para o fechamento).
- Disponibilidade de produtos (tarefa anterior) — reaproveitada na abertura.
- Snapshot `GET /operations/live` com `sales.received_today` (dia de calendário).
- Nenhuma entidade de dia/turno/operação existia (`StaffShift` é por funcionário).

## Lacunas encontradas e modelo escolhido

Lacunas: não havia abertura explícita do dia, nenhum guard impedia
sessões/pedidos sem dia aberto, nenhum vínculo sessão→dia, e as métricas
"de hoje" eram por calendário.

Modelo (mínimo, sem segundo módulo de fechamento/cálculo):

- Tabela `restaurant_daily_operations`: restaurante, `business_date`,
  timezone, moeda, `status` (`open`/`closed`), quem abriu/quando
  (`opened_by_user_id` + snapshot do nome, `opened_at`), `opening_float`,
  `suggested_opening_float` + fonte (auditoria), quem fechou/quando,
  `day_close_id` (FK única para o fechamento existente), chave de idempotência.
  Restrições: **um aberto por restaurante** (índice único parcial),
  `unique(restaurant_id, business_date)` (mesma chave do fechamento),
  checks de dinheiro não negativo e de ciclo de vida.
- `table_sessions.daily_operation_id` (nullable): sessões novas ficam
  vinculadas; pedidos e pagamentos herdam o vínculo pela sessão. Legado = NULL.
- O fechamento existente fecha a operação aberta **na mesma transação**.

## Contrato do dia operacional

- Abertura explícita (`POST /restaurants/{id}/daily-operations`, header
  `Idempotency-Key`, body `{opening_float}`); nunca por relógio ou polling.
- `business_date` = data local no fuso do restaurante aplicando o mesmo
  corte do fechamento (`DayClosePeriodResolver::businessDateOf`).
- A operação continua a mesma depois da meia-noite até o fechamento.
- No máximo uma aberta; uma por data de negócio.
- **Incompatibilidade concreta com o modelo anterior (documentada, aplicada):**
  como `restaurant_day_closes` só aceita um fechamento por data, não se pode
  abrir um novo dia numa data já fechada (`409 BUSINESS_DATE_ALREADY_CLOSED`)
  — reabrir depois de fechar só após a hora de corte. Antes, nada impedia
  atividade depois de um fechamento na mesma data (ela ia para o próximo
  fechamento); agora essa atividade não pode existir porque o dia está fechado.
  `DayClosePeriodTest::test_second_close_starts_exactly_at_the_prior_end`
  foi ajustado para ocorrer depois do corte (mesma asserção de encadeamento).
- **Corrigido em 2026-10-22 (regra aprovada):** com operação aberta, o
  Cierre pertence a ela e usa a `business_date` **persistida na abertura**
  (preview e confirmação usam o mesmo resolvedor). Passar da meia-noite ou da
  próxima hora de corte não muda a data; `closed_at` registra o instante
  real. Ex.: aberta 21, fechada 22 às 07:00 → Cierre e operação = 21,
  `closed_at` 22 07:00, e o dia 22 pode abrir em seguida. Encadeamento de
  períodos, cálculo, idempotência, locks e hashes inalterados; Cierres
  existentes não foram tocados. Cierre legado (sem operação) mantém a regra
  antiga (instante do fechamento + corte). Um dia aberto por vários dias é
  uma única operação com a data da abertura; "dias perdidos"
  (`business_date_from` < `business_date`) só ocorrem antes de um dia aberto.
- Fechar sem dia aberto continua possível (contrato existente; período só com
  caixa) — necessário para compatibilidade e para o primeiro fechamento legado.
  A UI só oferece o fechamento com dia aberto.

## Permissões e provisioning

- Nova `open_daily_operation` → owner e manager (`PermissionSeeder`,
  `RolePermissionSeeder`). Garçom, cozinha e caixa **não** recebem.
  `close_daily_operation` e `view_daily_closes` intocadas.
- `GET /restaurants/{id}/daily-operation`: qualquer membro da equipe com
  acesso ao restaurante vê o estado (aberto/não aberto, data, quem abriu);
  o bloco `cash` (fundo, sugestão, recebido) só para quem pode abrir **ou**
  fechar. O snapshot `/operations/live` ganha `daily_operation` sem dados
  financeiros.
- Outro tenant / restaurante sem acesso: 404 (mesmo padrão do Cierre).
- Provisioning mínimo no ambiente habitual: `php artisan aforo:provision`
  (já roda `PermissionSeeder` + `RolePermissionSeeder` com
  `syncWithoutDetaching`, aditivo e idempotente — validado no banco isolado).
  Também provisiona `manage_product_availability` da tarefa anterior, tornando
  `ProductAvailabilityPermissionSeeder` opcional. **Não executado no habitual.**

## Regra do dinheiro inicial (fundo de caixa)

- String decimal (nunca float): `^\d{1,8}(\.\d{1,2})?$`, zero válido,
  negativo/3 decimais/vírgula/número JSON recusados (422), limite
  `50000.00` (fundo de gaveta, não depósito). A UI aceita `12,50` e envia `12.50`.
- Prefill = precedência existente (sobra do último fechamento → padrão do
  restaurante); valor declarado diferente fica auditado (`suggested_*` +
  AuditLog `daily_operation.opened`).
- Não é venda nem pagamento: entra só no **efetivo esperado** do fechamento,
  como primeira fonte da precedência existente (`opening_float_source =
  daily_operation`); recebido da operação é exibido separado.
- Após abrir é **somente leitura** (não há ajuste auditável existente);
  diferenças aparecem no recuento do fechamento, como já era a regra.
- Duplo clique/retry: mesma chave → replay 200; segunda pessoa/aba → 409
  `DAILY_OPERATION_ALREADY_OPEN` com o estado (sem fundo); UI recarrega o estado.

## Bloqueio com dia fechado

Guard central `DailyOperationGuard::requireOpen()` executado **depois** do
lock operacional compartilhado, em:

- `OpenTableAction` (staff e QR `enter`) — só quando cria sessão nova;
  reentrar numa sessão ativa existente não cria nada;
- `OrderCreationService` (staff e QR) — **depois** do replay idempotente;
- `CreatePublicTableRequestAction` (chamar garçom / pedir conta) — passou a
  tomar o lock operacional compartilhado antes do lock de atribuição (ordem
  9101 → 9102, a mesma dos demais escritores).

Erro de domínio `409 RESTAURANT_DAY_NOT_OPEN`, traduzido nos 3 idiomas.
Preservado: carta pública (GET) e `service_open:false` em
`PublicRestaurantResource`, históricos, pós-visita, leitura de pedidos,
catálogo e disponibilidade (inclusive antes de abrir), pagamentos e
fechamento de mesas legadas. GET não abre operação nem sessão.

QR: se a entrada falha com dia fechado, a página mostra aviso + carta só
leitura e **nunca** repete `enter` em segundo plano (evita criar sessão
sozinha quando o dia abrir); só o toque em "Comprobar de nuevo" (ou recarregar
a página, que é nova entrada explícita) tenta de novo.

## Fechamento e mesas abertas

O fechamento existente já bloqueia qualquer sessão ativa — reutilizado sem
mudança; nada é encerrado/pago/anulado automaticamente; a tela mostra as
mesas pendentes (com "Anular sesión vacía" só quando o fluxo existente
permite). A operação fica aberta enquanto o fechamento estiver bloqueado.

## Concorrência e locks

- Abertura: lock exclusivo 9101 (serializa com outro abridor e com o fechamento)
  + índice único parcial como rede de segurança.
- Fechamento: já exclusivo; fecha a operação na mesma transação.
- Escritores (sessão/pedido/solicitação): shared 9101 → guard. Se o fechamento
  commitou enquanto esperavam, leem o dia fechado e recusam.
- Eventos: `daily_operation.changed` (`DailyOperationChanged`, after-commit,
  canal privado do restaurante, sem valores financeiros) na abertura e no
  fechamento; documentado em `docs/realtime.md`.

## Métricas e histórico

- Relatório do fechamento continua imutável (sha256 preservado mesmo com
  pagamentos posteriores — testado); nada é apagado.
- Operação atual: `GET .../daily-operation` (`cash.received`,
  `payments_count`, `sessions_opened`) — por **identidade** (sessões da
  operação), começando do zero na nova operação. Fundo exibido separado.
- **Cartão do Dashboard (corrigido em 2026-10-22):** significado estável via
  `summary.sales.operation` no snapshot (substitui `received_operation`, que
  não chegou a ser publicado): `open` → cobrado da operação aberta;
  `closed` → cobrado da **última operação encerrada** (rotulado
  "Cobrado · último día cerrado", com data); nova abertura → zero; `none` →
  "Todavía no se ha abierto ningún día" com "—" (não zero). Erro do snapshot
  continua no estado de erro da tela. Rótulo "cobrado", nunca "ventas"; fundo
  fora. Até 2 consultas; mesmo público de `received_today` (quem tem
  `view_operations`). `received_today`, Analytics, Cocina e relatórios por
  intervalo **não** mudaram.
- Legado: pagamentos de sessões sem `daily_operation_id` não entram no
  cartão (entram no Cierre, que é por período, como antes).

## Timezone, meia-noite e DST

Instantes em UTC (padrão do projeto); só a data de negócio usa o fuso do
restaurante. Testados: operação aberta 20:00, venda 00:30 do dia seguinte e
fechamento 01:30 → mesma operação e data; fechamento 02:30 CET logo após a
mudança de horário (25/10) rotulado 24/10; reabertura recusada às 05:59 CET e
aceita às 06:00 CET.

## Dados legados e migrations

- Migration nova: `2026_10_22_000001_create_restaurant_daily_operations_table.php`
  (tabela + `table_sessions.daily_operation_id`). Justificativa: não existia
  entidade equivalente. Validada no banco isolado com up → down → up.
- Nenhum backfill: nenhuma operação é criada automaticamente, nenhum usuário
  ou fundo é inventado, sessões antigas ficam com `daily_operation_id` NULL.
- **Ambiente habitual (consulta somente leitura em 2026-10-21):** 2
  restaurantes ativos, **1 sessão de mesa ativa sem operação**, 5 fechamentos,
  migration não aplicada, `open_daily_operation` e
  `manage_product_availability` não provisionadas.
- **Não está pronto.** Ver "Preparação do ambiente habitual" abaixo.

## Preparação do ambiente habitual (lida em 2026-10-22; nada executado)

Leituras feitas (transações `read_only`, `migrate:status`, `migrate --pretend`):

- Conexão da API Sail (sem segredos): `env=local`, `pgsql`, host `pgsql:5432`,
  database `laravel`, `APP_URL=http://localhost:8080`; `/up` → 200.
- Migrations pendentes: **somente** `2026_10_22_000001_create_restaurant_daily_operations_table`
  (SQL conferido com `--pretend`: tabela nova + `table_sessions.daily_operation_id`
  nullable, FK `restrict`, índice; sem backfill, sem update de linhas).
- `aforo:provision` (transação única; `updateOrCreate` por slug +
  `syncWithoutDetaching`): diff exato contra o banco —
  cria `manage_product_availability` e `open_daily_operation`; adiciona 5
  grants (owner e manager: as duas; waiter: `manage_product_availability`);
  **nenhum nome alterado, nenhum grant removido**, nenhum dado de plataforma
  alterado (roles/permissões de plataforma já idênticos).
- Restaurantes ativos: 1 AFORO Malvarrosa (último Cierre 2026-10-08, sem
  fundo padrão/sobra → o encarregado declara o fundo); 2 AFORO Ruzafa
  (último Cierre 2026-10-02, sobra 100,00 → sugestão 100,00).
- Sessão legada sem operação: **#490, AFORO Malvarrosa, Mesa 11**, aberta por
  QR em 2026-10-21 07:31 UTC, `occupied`/`unpaid`, 5 pedidos todos ainda não
  servidos/cancelados, 0 pago.

Ordem proposta (para a etapa própria):

0. Backup: `docker exec restaurants-api-pgsql-1 pg_dump -U <usuario> -d laravel -Fc -f /tmp/laravel-pre-daily-operation.dump`
   (copiar para fora do container) — opcional, recomendado.
1. `./vendor/bin/sail artisan migrate --path=database/migrations/2026_10_22_000001_create_restaurant_daily_operations_table.php`
   (nunca `migrate` genérico); conferir `migrate:status`.
2. `./vendor/bin/sail artisan aforo:provision`; conferir as 2 permissões e os 5 grants.
3. A partir daqui, sem dia aberto, novas sessões/pedidos/solicitações recebem
   `RESTAURANT_DAY_NOT_OPEN` (carta visível); a sessão #490 continua legível,
   reentrada QR funciona, mas novos pedidos nela ficam bloqueados.
4. Owner/manager abre o dia em cada restaurante pela tela Cierre diario,
   declarando o fundo real (Malvarrosa sem sugestão; Ruzafa sugere 100,00).
5. Sessão #490: decisão humana (servir/cancelar os 5 pedidos, cobrar, fechar
   ou anular pelos fluxos existentes). Nunca automática; ela não é vinculada
   à operação e seus pagamentos não aparecem no cartão "Cobrado", só no Cierre.
6. Primeiro Cierre de cada restaurante cobre o período desde o último Cierre
   (Malvarrosa desde 2026-10-08; Ruzafa desde 2026-10-02) — esperado pelo
   encadeamento existente.

## Frontend

- `DayCloseView` (Cierre diario): card "Día operativo" no topo. Sem dia
  aberto: formulário de fundo (prefill da sugestão, validação, loading,
  duplo clique bloqueado, erros traduzidos) + painel de disponibilidade
  existente para revisar esgotados (nada é restaurado automaticamente). Com
  dia aberto: estado (data, quem, fundo e recebido separados, fundo só
  leitura) + fluxo de fechamento existente. Abas com alvo ≥ 44 px.
  Rota/menu agora também liberados por `open_daily_operation`.
- Serviço e Dashboard: `DailyOperationBanner` a partir do snapshot já
  sincronizado (sem request próprio): "El día no está abierto" + link para
  quem pode abrir/fechar; staff sem permissão só vê a orientação.
- QR: aviso "Todavía no estamos atendiendo" + carta só leitura (sem carrinho,
  sem chamar garçom/conta, sem "Volver a la mesa").
- Realtime: `daily_operation.changed` entra na lista existente (refresh
  coalescido); Cierre usa o mesmo `useRestaurantRealtime`/fallback; troca de
  restaurante descarta respostas antigas. Nenhum timer/Echo novo.
- Card de caixa do fechamento conhece a nova fonte ("Declarado al abrir el día").
- Textos em `es-ES`, `ca-ES-valencia`, `en-GB` (CRLF dos locales preservado).

## Testes e resultados

### Rodada de correções (2026-10-22)

Runtime de teste temporário (nada instalado no sistema): `php8.4-gd` e
`php8.4-sqlite3` extraídos em `/tmp/claude-1000/php-gd` e
`/tmp/claude-1000/php-sqlite`, carregados com BCMath via `PHP_INI_SCAN_DIR`;
storage isolado (`LARAVEL_STORAGE_PATH`); banco `daily_operation_testing@127.0.0.1:55443`
conferido antes de cada execução; uma suíte por vez.

- **Suíte backend completa sequencial: 2073 testes, 2073 ok, 8465 assertions.**
  Os 20 erros anteriores: 19 de GD resolvidos pelo runtime com GD (nenhum
  teste suprimido); `ProvisionTest` corrigido no teste — o subprocesso recebe
  `APP_CONFIG_CACHE` apontando para um arquivo inexistente e nunca herda
  config em cache (antes, com config herdada, ele **provisionava o banco
  dessa config**); com o isolamento ele falha como esperado em sqlite
  (`no such table: roles`), o que exige `pdo_sqlite` no runtime.
- Novos testes: fechamento tardio 21→22 07:00 (data, `closed_at`, preview ==
  confirmação, abertura do dia 22, sem dupla contagem, hash intacto), mesma
  coisa cruzando o DST de 25/10, e cartão do Dashboard em
  nenhuma/aberta/encerrada/nova zerada/outro restaurante/cozinha 403.
- `DayClosePeriodTest`: 2 testes adaptados à regra aprovada (atividade antes
  do corte e no dia perdido passa a ser entrada de caixa, já que venda exige
  dia aberto); intenção e asserções de período preservadas.
- Pint ok nos arquivos tocados nesta rodada.
- Web: build verde; `frontend-daily-operation` 55 verificações + 18
  combinações (tabela de formatos de dinheiro, `100,00` e `100.00` enviam
  `"100.00"`, inválidos/vazio recusados, cartão nos 4 estados); regressões
  QR 37, disponibilidade 19 (+18), realtime 35, histórico 22 (+144),
  atribuição 12 (+36), aprovações 85, hardening 115 + 16 builds, security 54.
- Integração real: não repetida nesta rodada (manual pendente).

### Rodada original (2026-10-21)

Backend — Postgres temporário isolado `127.0.0.1:55443`, DB
`daily_operation_testing` (nome conferido antes), storage isolado via
`LARAVEL_STORAGE_PATH` (scratchpad, executor `dudu`, sem chmod/sudo),
BCMath temporário via `PHP_INI_SCAN_DIR`, uma suíte por vez:

- `DailyOperationLifecycleTest` 13/13 e `DailyOperationConcurrencyTest` 3/3
  (workers PHP independentes que esperam de fato no lock: fechamento vs
  abertura de mesa, mesa aberta antes do fechamento, dois abridores + replay).
- Suíte completa final: **2070 testes, 2050 ok**. Os 20 restantes são
  ambientais e não relacionados: 19 `GD extension is not installed`
  (`ProductMediaTest` 11, `AdversarialCatalogTest` 7, `MediaWriteFailureTest` 1 —
  o PHP 8.4 do WSL não tem GD; o Sail 8.5 tem) e 1
  `ProvisionTest::test_cli_returns_nonzero_when_provisioning_fails` (o
  subprocesso herda `APP_CONFIG_CACHE` do ambiente isolado e ignora o sqlite
  do teste). Os 24 erros de permissão em
  `storage/framework/testing/disks/public/products` (`root:root 700`)
  **não ocorrem** com o storage isolado; a pasta original não foi alterada.
- Testes existentes ajustados ao contrato: `PublicTableResolutionTest`
  (nova chave `service_open`), `DayCloseCashTest` (o caminho `required` só
  existe em fechamento sem dia aberto; agora usa uma entrada de caixa em vez
  de uma venda), `DayClosePeriodTest` (atividade após o corte).
- Fixture de teste: `createTable()`/`openSession()` abrem um dia
  (`InteractsWithDailyOperations`) com o fundo sugerido pela precedência
  existente; testes do próprio dia usam `withoutDailyOperationFixture()`.
- Pint: arquivos novos ok. `OrderCreationService.php` e
  `BuildRestaurantOperationsSnapshotAction.php` já falhavam no Pint por
  alterações de tarefas anteriores (verificado sem as linhas desta tarefa);
  não formatados.

Frontend:

- `npm run build` (vue-tsc + vite) verde.
- `tests/frontend-daily-operation.mjs`: 28 verificações (permissões, prefill,
  validação, duplo clique, retry com a mesma chave, conflito, sem PATCH de
  disponibilidade, fundo e recebido separados, eventos coalescidos, troca de
  restaurante com resposta atrasada, banner, métrica, QR fechado sem
  re-entrada automática) + 18 combinações 375/768/1440 × claro/escuro × 3 idiomas
  sem overflow e com alvos ≥ 44 px. Componentes reais, HTTP de fixture.
- Regressões: QR 37, disponibilidade 19 (+18), realtime 35, aprovações 85,
  histórico 22 (+144), atribuição 12 (+36), hardening 115 + 16 builds,
  security 54 — todos ok, sem erros de console.
- Chrome do agent-browser precisa de
  `LD_LIBRARY_PATH=/tmp/qr-browser-libs/extracted/usr/lib/x86_64-linux-gnu`
  neste WSL (bibliotecas extraídas por tarefa anterior).

Integração real isolada (não é a rodada manual): API própria na 55452 com DB
`daily_operation_validation` (provisionado com `aforo:provision`) + Vite 5175:
QR com dia fechado (aviso + carta real) → login da dona → abrir com 100,00 €
→ QR entra (sessão com `daily_operation_id`) → Cierre bloqueado pela mesa →
anular sessão vazia → fechar → operação `closed` ligada ao fechamento
(`opening_float_source = daily_operation`, esperado 100,00, vendas 0,00) →
Serviço mostra "El día no está abierto". Servidores encerrados por PID depois.

Observação da rodada original — **corrigida em 2026-10-22**: "Efectivo
contado" com `100,00` dava 422. Agora `parseMoneyInput` (`src/utils/price.ts`)
gera a string decimal canônica para efectivo contado, sobra para amanhã,
fundo "required", fundo de abertura e entradas/retiradas de caixa; preview,
diferença exibida e envio usam o mesmo valor; vazio nunca vira zero;
milhares, dois separadores, negativos e >2 decimais são recusados.

## Checklist manual (rodada final)

1. Ambiente habitual: passos 0-6 da "Preparação do ambiente habitual".
2. Owner/manager: Cierre diario → card "Día operativo" → conferir sugestão de
   fundo, revisar esgotados, abrir com 0 e com valor; duplo clique.
3. Segunda aba/pessoa tenta abrir → mensagem de dia já aberto, sem segundo fundo.
4. Garçom/cozinha/caixa: sem formulário de abertura; banner no Serviço.
5. Antes de abrir: QR mostra aviso + carta; mesa staff/pedido recusados.
6. Depois de abrir: QR "Comprobar de nuevo" entra; pedidos normais.
7. Passar da meia-noite com mesa aberta: mesmo dia operativo.
8. Cierre com mesa aberta: bloqueado com a mesa listada; resolver e fechar.
9. Depois do Cierre: QR/staff recusados; cartão do Dashboard mostra
   "Cobrado · último día cerrado" com a data e o valor da operação encerrada;
   reabrir só para a próxima data de negócio.
10. Novo dia: recebido começa em 0; fechamento anterior intacto no Historial.
11. Realtime (Reverb + worker) entre dois dispositivos; sem Reverb, fallback.
12. Claro/escuro, 3 idiomas, 375/768/1440.
13. Fechar depois da hora de corte do dia seguinte (ex.: 07:00): Cierre e
    Historial mostram a data da abertura; abrir o novo dia logo em seguida.
14. Cierre e entradas/retiradas com `100,00` e `100.00`; `1.000,00` e campo
    vazio recusados no formulário.
15. Restaurante sem nenhum dia aberto: cartão com "—" e texto de vazio.

## Pendências anteriores mantidas

- 24 erros de acesso a `storage/framework/testing/disks/public/products`
  (`root:root 700`, de 2026-10-09) no storage padrão — contornados aqui com
  storage isolado; a pasta não foi alterada. Para eliminar no ambiente
  habitual: o dono `root` precisa ajustar só essa pasta (decisão do usuário).
- Provisioning local de `ProductAvailabilityPermissionSeeder` — agora coberto
  por `aforo:provision`.
- Testes manuais de realtime e atribuição.
- `storage/app/inspect_user.php` e `storage/app/seed_dev_scenario.php`:
  formatação incidental anterior; não executados, não reformatados, não restaurados.
- Processos isolados deixados pelo Codex (Postgres 55443, API 55442, Vite 5174)
  continuam ativos e não foram encerrados (instrução de não usar pkill genérico).
- Runtime de teste com GD/pdo_sqlite é temporário (`/tmp/claude-1000/php-*`),
  some num reboot; o Sail 8.5 já tem as extensões. Não é configuração do projeto.
- Integração real (servidor isolado) das correções A/B/C não foi repetida —
  coberta por testes automáticos; fica para a rodada manual (itens 13-15).
- Preparação do ambiente habitual: lida e documentada, **não executada**.

## Arquivos desta etapa

### restaurants-api

Novos: `app/Models/RestaurantDailyOperation.php`,
`app/Support/DailyOperation/{DailyOperationGuard,DailyOperationState}.php`,
`app/Exceptions/DailyOperation/DailyOperationException.php`,
`app/Events/Realtime/DailyOperationChanged.php`,
`app/Actions/DailyOperation/OpenDailyOperationAction.php`,
`app/Http/Controllers/Api/V1/DailyOperationController.php`,
`app/Http/Requests/Api/V1/DailyOperation/OpenDailyOperationRequest.php`,
`database/migrations/2026_10_22_000001_create_restaurant_daily_operations_table.php`,
`tests/Concerns/InteractsWithDailyOperations.php`,
`tests/Feature/DailyOperation/{DailyOperationLifecycleTest,DailyOperationConcurrencyTest}.php`,
`tests/Support/daily-operation-worker.php`, este relatório.

Modificados: `app/Actions/Tables/OpenTableAction.php`,
`app/Actions/Orders/OrderCreationService.php`,
`app/Actions/TableRequests/CreatePublicTableRequestAction.php`,
`app/Actions/DayClose/{CloseRestaurantDayAction,BuildDayClosePreviewAction}.php`,
`app/Support/DayClose/{DayClosePeriod,DayClosePeriodResolver,DayCloseReport}.php`,
`app/Support/Billing/PaymentsSummary.php`,
`app/Actions/Operations/BuildRestaurantOperationsSnapshotAction.php`,
`app/Http/Resources/Api/V1/Public/PublicRestaurantResource.php`,
`app/Models/{AuditLog,Restaurant,TableSession}.php`,
`app/Policies/RestaurantPolicy.php`, `bootstrap/app.php`, `routes/api.php`,
`database/seeders/{PermissionSeeder,RolePermissionSeeder}.php`,
`docs/realtime.md`, `tests/Concerns/{InteractsWithTenants,InteractsWithOrders}.php`,
`tests/Feature/DayClose/{DayCloseCashTest,DayClosePeriodTest}.php`,
`tests/Feature/Public/PublicTableResolutionTest.php`.

### restaurants-web

Novos: `src/types/daily-operation.ts`, `src/services/daily-operation.service.ts`,
`src/composables/useDailyOperation.ts`,
`src/components/day-close/DailyOperationCard.vue`,
`src/components/shared/DailyOperationBanner.vue`,
`tests/frontend-daily-operation.{browser.ts,html,mjs}`.

Modificados: `src/views/day-close/DayCloseView.vue`,
`src/views/service/ServiceView.vue`, `src/views/DashboardView.vue`,
`src/views/public/PublicTableView.vue`,
`src/components/dashboard/operation/QuickMetrics.vue`,
`src/components/day-close/DayCloseCashCard.vue`,
`src/components/public/PublicMenuCatalog.vue` (prop opcional `hideBackToTable`),
`src/composables/usePublicTableResolution.ts`, `src/layouts/AppShellLayout.vue`,
`src/router/index.ts`, `src/types/{auth-context,day-close,operations,public-menu,realtime}.ts`,
`src/utils/error-message.ts`, `src/i18n/locales/{es-ES,ca-ES-valencia,en-GB}.ts`.

### Correções de 2026-10-22 (SDD `daily-operation-fixes`)

API: `app/Support/DayClose/DayClosePeriodResolver.php` (A),
`app/Support/DailyOperation/DailyOperationState.php` (`salesCard`),
`app/Actions/Operations/BuildRestaurantOperationsSnapshotAction.php` (B),
`app/OpenApi/ApiDocumentation.php` (campo `sales.operation`),
`tests/Feature/DailyOperation/DailyOperationLifecycleTest.php`,
`tests/Feature/DayClose/DayClosePeriodTest.php`,
`tests/Feature/Provisioning/ProvisionTest.php` (isolamento do subprocesso),
`.gitignore` (`.oh-my-sdd/runtime/`, exigido pela skill),
`.oh-my-sdd/constitution.md`, `.oh-my-sdd/specs/daily-operation-fixes/{spec,plan,tasks}.md`,
este relatório.

Web: `src/utils/price.ts` (`parseMoneyInput`, `moneyToCents`),
`src/views/day-close/DayCloseView.vue`, `src/components/day-close/{DayCloseCashCard,DailyOperationCard,DayCloseCashMovementDialog}.vue`,
`src/components/dashboard/operation/QuickMetrics.vue`,
`src/types/{operations,daily-operation}.ts`,
`src/i18n/locales/{es-ES,ca-ES-valencia,en-GB}.ts` (CRLF preservado),
`tests/frontend-daily-operation.browser.ts`,
`tests/frontend-daily-operation.preview.json` (preview real do banco isolado, ajustado para dia aberto).

## Git final (2026-10-22, após as correções)

- restaurants-api (`fix/security-hardening`): `git diff --stat` → 88 files
  changed, 884 insertions(+), 237 deletions(-); 126 entradas em `git status --short`.
- restaurants-web (`main`): `git diff --stat` → 74 files changed,
  11489 insertions(+), 10235 deletions(-); 122 entradas em `git status --short`.
- Ambos acumulam várias tarefas.

### Git anterior (2026-10-21 ~23:50)

- restaurants-api (`fix/security-hardening`): `git diff --stat` → 86 files
  changed, 845 insertions(+), 231 deletions(-); 123 entradas em `git status --short`
  (acumulado de várias tarefas).
- restaurants-web (`main`): `git diff --stat` → 72 files changed,
  11387 insertions(+), 10217 deletions(-); 119 entradas em `git status --short`
  (acumulado, incluindo conversões CRLF de tarefas anteriores).
