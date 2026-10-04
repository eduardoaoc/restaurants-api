# PR-SEC1A.1 — RESTAURANT PERMISSION BOUNDARY FIX REPORT

Data: 2026-10-04 (Europe/Madrid). HEAD inicial: `4fdff01`, main/origin/main.
Precheck: somente os três artefatos conhecidos da PR-SEC1A estavam untracked;
nenhuma alteração inesperada. Os dois documentos históricos foram preservados.
As três reproduções originais mantêm seus asserts de negação e integridade.

## ROOT CAUSE

O helper antigo `User::hasPermission(permission, Organization)` agregava todas
as assignments da organização sem considerar `restaurant_id`. Reachability em
RestaurantScope não impedia que um grant manager de A1 fosse aplicado em A2,
onde o mesmo ator era waiter. AuthContextBuilder já projetava grants por alvo.

## HELPERS

- `hasOrganizationPermission`: user_id do ator + organization_id alvo +
  restaurant_id IS NULL + permission através da role. EXISTS no banco.
- `hasRestaurantPermission`: user_id do ator + organization_id do restaurante +
  (restaurant_id IS NULL OR restaurant_id = alvo) + permission através da role.
  EXISTS no banco; nunca consulta uma collection de roles carregada.
- Old hasPermission: REMOVIDO. Nenhum call site genérico em app/routes/tests.
- `hasPlatformPermission`: sem alteração de implementação.
- `RestaurantScope::restaurantIdsWithAnyPermission`: filtra uma coleção de
  restaurantes pela reachability existente e pela mesma regra null-or-target de
  grants. WHERE EXISTS correlacionado; nenhuma consulta de autorização por item
  da lista e nenhum cache de permissions.
- Os helpers respondem à pergunta sobre grants; membership ativa/suspensão e
  reachability continuam sendo controles separados, nos middleware/policies.

## CALL SITE AUDIT

Inventário completo: [pr-sec1a-1-authorization-call-sites.md](pr-sec1a-1-authorization-call-sites.md).

- Total old hasPermission usages em enforcement de aplicação: 40 (39 em
  policies/trait, 1 em channel). Um call site adicional em teste foi migrado.
- Organization-wide: 3 — OrganizationPolicy.update,
  RestaurantPolicy.create e ProductPolicy.canManage (catálogo global/mídia).
- Restaurant-scoped: 37, incluindo coleções cujo Organization era apenas
  o limite externo da query; seus resultados agora respeitam grants por alvo.
- Ambiguous resolved: 8, incluídos nos totais acima, não somados novamente.
- Menções antigas em comments/OpenAPI foram atualizadas; declaração antiga removida.
- Busca complementar cobriu Gate/authorize/can/allows/deny, RestaurantScope,
  userRoles e role.permissions em controllers, actions, support e channels.

## SEC-001 REPRODUCTION

Antes: 3 testes vermelhos / 7 assertions.

| Superfície A2 (manager A1, waiter A2) | Antes | Depois |
| --- | --- | --- |
| Settings | Alterava valor persistido | 403, nenhuma alteração |
| History | 200 | 403 |
| Activity channel | 200 | 403 |

Depois: três reproduções originais PASS, dentro da suíte Security final
(19 testes / 131 assertions).

## MATRIX

Paridade testada com auth/context, policy e chamadas HTTP diretas. A autorização
de close foi checada pela ability closeDay e endpoint de preview; a suíte DayClose
continua cobrindo o fechamento persistido e a matriz de roles aprovada.

| Ator / alvo | Settings | History | Activity | Close |
| --- | --- | --- | --- | --- |
| Manager A1 / A1 | ALLOW | ALLOW | ALLOW | ALLOW |
| Waiter A2 / A2 (mesmo ator) | DENY | DENY | DENY | ALLOW |
| Owner organization-wide / A1 | ALLOW | ALLOW | ALLOW | ALLOW |
| Owner organization-wide / A2 | ALLOW | ALLOW | ALLOW | ALLOW |
| Manager A1 e manager A2 independentes | ALLOW por alvo | grant por alvo | grant por alvo | grant por alvo |
| Grant da organização A / restaurante da B | DENY | DENY | DENY | DENY |

Kitchen não recebe close/view; cashier e waiter continuam close-only no
restaurante em que possuem os grants. Role/permission slugs não foram alterados.

## ORGANIZATION OPERATIONS

- Restaurant creation: exige grant organizacional; manager scoped não pode criar
  restaurantes, mesmo sendo manager independente em todos os restaurantes atuais.
- Organization update: manage_organization somente organization-wide.
- Product global e product media: manage_products somente organization-wide,
  pois alteram dados compartilhados pela organização. RestaurantProduct e modifiers
  continuam aceitando grants do restaurante para preço/availability/Carta local.
- Staff: listas e relações retornadas filtradas pelos restaurantes com manage_users.
  Show mantém 403 quando o staff é alcançável mas falta permission, e 404 fora da
  reachability. Leitura de staff compartilhado não expõe restaurantes sem grant.
- Staff create: autoriza cada restaurante proposto, não apenas o primeiro.
- Staff update: campos globais do staff, membership da organização e replacement
  de assignments/role afetam o conjunto completo; exige grant em todos os restaurantes
  existentes e propostos da organização. Sem essa cobertura, 403 antes de mutation.
- UpdateStaffAction: reconciliação limitada à organização ativa, preservando
  memberships/roles do mesmo usuário em outras organizações.
- Nenhum manager/assignment foi promovido automaticamente a organization-wide.

## POLICIES REVIEWED

| Policy / conjunto de abilities | Target / decisão |
| --- | --- |
| OrganizationPolicy | View por membership; update organizational grant |
| RestaurantPolicy | Create organization-only; update/reports/settings/floor-plan/operations/activity/day-close por alvo; view/list reachability preservada |
| ProductPolicy / ProductMediaController | Product da organização, organization-only |
| CategoryPolicy | Restaurant do menu, read/manage grant do alvo via trait |
| MenuPolicy | Restaurant alvo, grant local ou org-wide |
| RestaurantProductPolicy | Restaurant da oferta/preço/availability |
| ModifierGroupPolicy / ModifierOptionPolicy | Restaurant da oferta ancestral; read/write OR sets preservados |
| FloorPolicy / ZonePolicy / TablePolicy | Restaurant do recurso; structural toggle preservado; fallback lê valor atual do banco |
| TableSessionPolicy | Restaurant da sessão; close/void/bill/payment/receipt/assign/transfer por alvo |
| OrderPolicy | Restaurant do order/table; read/detail/create/transitions/ticket por alvo; listas filtradas |
| TableRequestPolicy | Restaurant do request; list/action grants por alvo |
| StaffShiftPolicy | Restaurant explícito em list/start; Restaurant do shift em end; self-operation preservada |
| StaffPolicy | Restaurant explícito em performance/review/feedback; staff read por overlap autorizado; write em todo conjunto afetado |
| CustomerFeedbackPolicy | Restaurant do feedback/consulta |
| AuditLogPolicy | Grant org-wide para linhas organizacionais; grants locais apenas para linhas dos respectivos restaurantes |
| WaiterCallPolicy | Restaurant da sessão/call; self acknowledgement preservado |

Nested CategoryProduct IDs continuam resolvidos pelo relacionamento da categoria;
ModifierGroup/Option derivam o restaurante da cadeia de recursos. Não foi afrouxado
qualquer filtro tenant/nested para acomodar o novo enforcement.

## CHANNELS

- restaurant.{id}.activity: reachability + hasRestaurantPermission(view_activity, alvo).
- restaurant.{id}: reachability existente, sem nova permission administrativa.
- user.{id}: identidade própria existente; sem alteração.
- Credenciais Reverb de testes são fictícias; auth signing é local, sem broadcast externo.

## AUTH CONTEXT PARITY / REVOCATION / PERFORMANCE

A projeção de roles/permissions no builder não mudou. As consultas dos helpers
seguem exatamente organization-wide ou null-or-target, conforme aquela projeção.
Testes usam actors separados por caso para preservar o contrato de sessão do
harness; não desabilitam middleware de auth para obter as permissões esperadas.

Revogação de assignment e de permission pivot é observada imediatamente, mesmo
com userRoles/roles/permissions pré-carregados. Staff authorization também usa
relations como queries. Table structure passa a ler o toggle atual do banco,
em vez de uma relation potencialmente stale, ao aplicar o fallback do waiter.

Coleções filtram grants em consultas correlacionadas, sem loop de EXISTS por
recurso. Helpers fazem EXISTS por authorization call; nenhum permission cache
ou sistema de ACL novo foi introduzido. Guardas de performance existentes de
AuthContext estão incluídas nos targeted.

## TENANT CROSS-ORG

Helpers rejeitam grant de A para B, inclusive com restaurant membership em B.
A API continua resolvendo o tenant ativo antes dos controllers; restaurante de
outra organização é ocultado com 404. Teste de reconciliação de staff verifica
que atualizar assignments em A preserva links/roles de B.

## TESTS

- RestaurantPermissionBoundaryTest: PASS, três regressões originais sem enfraquecer asserts.
- Security scope/collection regressions: PASS — total Security 19 testes, 131 assertions.
- AuthContext, Permissions, Staff, Restaurant, Daily Close, Broadcast, StaffShift:
  PASS, incluídos na rodada ampliada de 802 testes / 2.948 assertions.
- Other targeted: Order, TableRequest, AuditLog, Product, Tenancy, Category,
  CategoryProduct, ModifierGroup, ModifierOption, Menu, RestaurantProduct, Kitchen:
  PASS, incluídos na mesma rodada ampliada.
- Full suite: PASS — 1.872 testes, em 446,799 segundos (Sail/PostgreSQL).
- Assertions: 6.965 na suíte completa.
- Pint: PASS (`./vendor/bin/sail bin pint --dirty --test`).
- Composer audit: PASS, zero advisories conhecidos.
- Composer validate: PASS.
- Diff check: PASS.

## FILES CHANGED

- `app/Actions/Staff/CreateStaffAction.php`
- `app/Actions/Staff/UpdateStaffAction.php`
- `app/Http/Controllers/Api/V1/AuditLogController.php`
- `app/Http/Controllers/Api/V1/Auth/AuthController.php`
- `app/Http/Controllers/Api/V1/KitchenController.php`
- `app/Http/Controllers/Api/V1/OrderController.php`
- `app/Http/Controllers/Api/V1/StaffController.php`
- `app/Http/Controllers/Api/V1/StaffShiftController.php`
- `app/Http/Controllers/Api/V1/TableRequestController.php`
- `app/Models/User.php`
- `app/OpenApi/ApiDocumentation.php`
- `app/Policies/AuditLogPolicy.php`
- `app/Policies/Concerns/AuthorizesRestaurantScopedCatalog.php`
- `app/Policies/CustomerFeedbackPolicy.php`
- `app/Policies/FloorPolicy.php`
- `app/Policies/OrderPolicy.php`
- `app/Policies/OrganizationPolicy.php`
- `app/Policies/ProductPolicy.php`
- `app/Policies/RestaurantPolicy.php`
- `app/Policies/StaffPolicy.php`
- `app/Policies/StaffShiftPolicy.php`
- `app/Policies/TablePolicy.php`
- `app/Policies/TableRequestPolicy.php`
- `app/Policies/TableSessionPolicy.php`
- `app/Policies/WaiterCallPolicy.php`
- `app/Policies/ZonePolicy.php`
- `app/Support/Auth/AuthContextBuilder.php`
- `app/Support/Restaurants/RestaurantScope.php`
- `routes/channels.php`
- `tests/Feature/Permissions/RestaurantScopedCatalogWriteTest.php`
- `tests/Feature/Staff/StaffIndexTest.php`
- `tests/Feature/Security/RestaurantPermissionBoundaryTest.php`
- `tests/Feature/Security/AuthorizationScopeTest.php`
- `tests/Feature/Security/CollectionPermissionBoundaryTest.php`
- `docs/pr-sec1a-1-authorization-call-sites.md`
- `docs/pr-sec1a-1-permission-boundary-fix-report.md`

Os dois documentos da PR-SEC1A permanecem untracked e preservados, sem alterações
nesta carta. Os arquivos novos não aparecem no diff stat padrão.

```text
 app/Actions/Staff/CreateStaffAction.php            |  6 +--
 app/Actions/Staff/UpdateStaffAction.php            |  4 +-
 app/Http/Controllers/Api/V1/AuditLogController.php |  4 +-
 .../Controllers/Api/V1/Auth/AuthController.php     |  2 +-
 app/Http/Controllers/Api/V1/KitchenController.php  |  9 ++--
 app/Http/Controllers/Api/V1/OrderController.php    |  4 +-
 app/Http/Controllers/Api/V1/StaffController.php    | 22 +++++---
 .../Controllers/Api/V1/StaffShiftController.php    |  2 +-
 .../Controllers/Api/V1/TableRequestController.php  |  3 +-
 app/Models/User.php                                | 24 +++++----
 app/OpenApi/ApiDocumentation.php                   |  2 +-
 app/Policies/AuditLogPolicy.php                    |  4 +-
 .../Concerns/AuthorizesRestaurantScopedCatalog.php | 17 ++----
 app/Policies/CustomerFeedbackPolicy.php            |  2 +-
 app/Policies/FloorPolicy.php                       |  6 +--
 app/Policies/OrderPolicy.php                       | 39 ++++++++++----
 app/Policies/OrganizationPolicy.php                |  2 +-
 app/Policies/ProductPolicy.php                     |  2 +-
 app/Policies/RestaurantPolicy.php                  | 22 ++++----
 app/Policies/StaffPolicy.php                       | 60 +++++++++-------------
 app/Policies/StaffShiftPolicy.php                  | 10 ++--
 app/Policies/TablePolicy.php                       |  8 +--
 app/Policies/TableRequestPolicy.php                |  5 +-
 app/Policies/TableSessionPolicy.php                |  2 +-
 app/Policies/WaiterCallPolicy.php                  |  4 +-
 app/Policies/ZonePolicy.php                        |  6 +--
 app/Support/Auth/AuthContextBuilder.php            | 10 +---
 app/Support/Restaurants/RestaurantScope.php        | 30 +++++++++++
 routes/channels.php                                |  2 +-
 .../RestaurantScopedCatalogWriteTest.php           |  7 +--
 tests/Feature/Staff/StaffIndexTest.php             |  2 +-
 31 files changed, 185 insertions(+), 137 deletions(-)
```

## SECURITY FINDINGS REMAINING

SEC-001: FIXED; todas as regressões e a suíte completa passaram.
As demais áreas da PR-SEC1A interrompida permanecem pendentes; esta correção não
constitui veredito de segurança global, autorização de staging ou validação Supabase.
O candidato de disk write false em upload continua fora desta carta de autorização.

COMMIT: NO.
PUSH: NO.
DEPLOY: NO.
MIGRATIONS: NONE.
FRONTEND/SUPABASE: NÃO ALTERADOS.

VERDICT: A) SEC-001 FIXED — PERMISSION ENFORCEMENT MATCHES RESTAURANT SCOPE.
READY TO RESUME PR-SEC1A.
