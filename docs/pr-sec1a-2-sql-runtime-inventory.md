# PR-SEC1A.2 — SQL runtime inventory

Data: 2026-10-04. HEAD: `a3fad32`. Busca estática em app/routes; linhas no working tree desta carta.

41 chamadas em 15 arquivos. Não inclui SQL de DDL em migrations nem o worker exclusivamente de testes.

| Arquivo | Linhas | Fluxo de entrada e limite estrutural |
| --- | --- | --- |
| `app/Actions/Kitchen/BuildKitchenDashboardAction.php` | 89 | COUNT/MIN constantes; restaurant_id e período em bindings. recent($column) recebe somente accepted_at/ready_at, definidos internamente. |
| `app/Support/Activity/RestaurantActivityReadState.php` | 61 | GREATEST constante em upsert; IDs/cursor/actor vêm de contexto autorizado e são bindings. |
| `app/Support/Analytics/KitchenAnalytics.php` | 90 | Colunas da closure $in são accepted_at/ready_at/served_at literais; datas em bindings. |
| `app/Support/Analytics/OccupancyAnalytics.php` | 73 | COUNT/AVG/EXTRACT fixos; restaurant_id/status/datas em bindings. |
| `app/Support/Analytics/OrderAnalytics.php` | 39 | COUNT fixo; origin/status e filtro de tenant por Query Builder. |
| `app/Support/Analytics/PeakHourAnalytics.php` | 27 | Timezone armazenada após validação timezone é binding de AT TIME ZONE; expressão constante. |
| `app/Support/Analytics/ProductAnalytics.php` | 84 | SUM e aliases fixos; produto/nome snapshot não entram em SQL estrutural. |
| `app/Support/Analytics/RevenueAnalytics.php` | 60 | Granularity validada pelo resolver e timezone validada entram como placeholders date_trunc(?) / AT TIME ZONE ?. |
| `app/Support/Billing/SessionBillCalculator.php` | 110, 118 | COALESCE/SUM fixos; session_id e enum billable via bindings. |
| `app/Support/DayClose/DayCloseSnapshotBuilder.php` | 64, 149, 163, 177, 243, 265, 266, 267, 356, 369, 447, 506 | $range recebe nomes de colunas literais; $inList contém apenas placeholders; $productKey é literal metadata->>restaurant_product_id; $condition recebe duas condições internas constantes; UNION/stage/colunas/limit internos; IDs, thresholds, datas e timezone são bindings. |
| `app/Support/Feedback/CustomerFeedbackSummaryResolver.php` | 27 | Agregações fixas, waiter_id/restaurant_ids em bindings. A falha SEC-006 é escopo de dados, não SQL injection. |
| `app/Support/Reports/RestaurantDashboardService.php` | 100, 181, 222, 260 | COUNT/SUM/FILTER constantes; origin/type enums e filtros de período/tenant em bindings. |
| `app/Support/Restaurants/RestaurantOperationalLock.php` | 43, 50 | SELECT pg_advisory_xact_lock(_shared) fixos; namespace constante + restaurant_id inteiro em bindings. |
| `app/Support/Restaurants/RestaurantScope.php` | 69, 75 | EXISTS correlacionados; tabelas/colunas fixas e user/org/permissões em bindings. |
| `app/Support/Staff/StaffPerformanceService.php` | 105, 108, 158, 168, 178, 188, 198, 209, 248 | COUNT/ROUND/AVG e aliases fixos; IDs de usuário, restaurantes e datas via Query Builder. |

Não foram encontradas chamadas runtime a orWhereRaw/havingRaw/groupByRaw/joinRaw/statement/unprepared, nem tabelas controladas pelo cliente. O único orderBy com coluna variável identificado no builder de kitchen recebe colunas internas constantes. Controllers usam nomes de colunas fixos e bindings; schemas/tabelas configurados para session/cache/queue são configuração de operador.

AdversarialCatalogTest persiste os quatro payloads SQL da carta como texto, valida ownership e captura SQL parametrizado. A aspas dupla, que também faz parte da sintaxe de identificadores PostgreSQL, é verificada por persistência exata; não se exige ausência desse caractere no SQL. Nenhum payload pg_sleep foi executado como SQL; ele foi gravado como dado. As agregações são traçadas estaticamente e também exercitadas pela suíte existente, sem alegar um payload em cada consulta individual.
