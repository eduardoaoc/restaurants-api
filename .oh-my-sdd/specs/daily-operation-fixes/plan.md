# Plan: Correções do ciclo operacional diário

Base: `spec.md` validado em 2026-10-22 (decisão B: recebido da última operação
encerrada calculado **por sessões da operação**, igual ao estado aberto).

## Decisões arquiteturais

### A — data do Cierre com operação aberta (API)

- Único ponto de verdade: `DayClosePeriodResolver::resolve()`. Hoje ele já
  carrega a operação aberta; passa a usar `operation->business_date` como
  `businessDate` quando ela existe; sem operação mantém `businessDateOf($endUtc)`.
- Consequências automáticas (mesmo resolver): preview == confirmação,
  `businessDateAlreadyClosed`, `business_date_from = min(prev+1, businessDate)`
  e o check `business_date_from <= business_date` da tabela.
- Período/encadeamento, `closed_at`, cálculo financeiro, idempotência, locks,
  relatório/hash: inalterados (nenhuma linha da Action de fechamento muda além
  de comentários, se necessário).
- `OpenDailyOperationAction` já calcula a data na abertura e recusa data já
  fechada — sem mudança; com A corrigido, a abertura do dia seguinte passa a
  funcionar após fechamento tardio.
- Dependentes da `business_date` (histórico, PDF, WhatsApp, anotações,
  `cash_left_for_next_day` → sugestão do próximo fundo) leem a coluna
  persistida: herdam o valor correto, sem mudança de código. Cierres
  existentes não são tocados.

### B — cartão operacional (API + web)

- API: `BuildRestaurantOperationsSnapshotAction` passa a montar
  `summary.sales.operation` com no máximo 2 consultas: operação aberta (já
  carregada) ou, senão, a última fechada (`orderByDesc('closed_at')->first()`),
  e um agregado `PaymentsSummary::forDailyOperation()` (já existente, uma query).
  `received_operation` é removido (contrato da tarefa anterior, não publicado).
- Web: tipo `OperationsSummary.sales.operation`; `QuickMetrics.vue` usa só esse
  bloco para o cartão: rótulos "Cobrado en la operación · {data}",
  "Última operación cerrada · {data}" e vazio "Sin día operativo" com valor
  "—". Erro do snapshot continua no estado de erro existente da tela.
- Realtime/fallback: inalterados — `daily_operation.changed` já força refetch
  do snapshot; troca de restaurante já é tratada por `useRestaurantOperations`.

### C — dinheiro com vírgula (web)

- Nova função `parseMoneyInput(raw): string | null` em `src/utils/price.ts`
  (mesmo módulo do `parsePriceInput` existente, que continua para o contrato
  numérico de preços). Regra: trim; aceita `^\d{1,8}([.,]\d{1,2})?$` (um único
  separador decimal); devolve string canônica com 2 decimais por manipulação de
  string (sem `parseFloat`); vazio/inválido → `null`.
- Uso: `DayCloseView` (payload: `counted_cash`, `cash_left_for_next_day`,
  `opening_float` quando `required`; preview com float digitado),
  `DayCloseCashCard` (diferença local e mensagem de erro do campo) e
  `DailyOperationCard` (substitui `Number().toFixed(2)`). CTA de revisão
  desabilitado com valor inválido; vazio continua "obrigatório", nunca zero.

### D — testes

- GD: runtime de teste temporário (sem tocar o sistema): extrair
  `php8.4-gd` (+ libs ausentes, se houver) em `/tmp`, carregar via
  `PHP_INI_SCAN_DIR` como já feito com BCMath; storage isolado via
  `LARAVEL_STORAGE_PATH`; banco isolado `127.0.0.1:55443` conferido antes.
- `ProvisionTest`: o `Process` recebe `APP_CONFIG_CACHE` apontando para um
  arquivo temporário inexistente — o subprocesso nunca carrega config em cache
  herdada e usa o sqlite `:memory:` explícito; assertions inalteradas.

### E — preparação local

- Somente leitura via `docker exec` no container Sail: `migrate:status`,
  identidade da conexão (driver/host/database, sem segredos), diff entre
  `RolePermissionSeeder::ROLE_PERMISSIONS`/`PermissionSeeder` e o banco,
  inspeção do código dos seeders chamados por `aforo:provision`, sessões
  ativas legadas. Resultado e comandos no relatório.

## Esquema de dados / API

- Sem migration nova.
- `GET /operations/live`: `summary.sales.operation` (ver spec §4); OpenAPI do
  snapshot atualizado se o campo estiver documentado.
- Cierre/abertura: payloads inalterados.

## Bibliotecas e dependências

Nenhuma nova. Ambiente de teste temporário com pacotes `.deb` extraídos em
`/tmp` (não instalados), como já registrado para BCMath.
