# PR-SEC1A.1 — Authorization call-site audit

Baseline HEAD: `4fdff01`. All actual `->hasPermission(...)` invocations from `app`, `routes`, `tests` are listed. Method declaration and mentions in comments/OpenAPI are counted separately from enforcement.

Application calls: 40; organization-wide A: 3; restaurant-scoped B: 37. Initially ambiguous C: 8, all resolved. Test calls: 1.

A = organization-wide only. B = target restaurant grant + organization-wide grant. Collection operations classified B must also filter their results; an entry gate alone is insufficient. C indicates the old Organization argument obscured the actual domain target.

| Old call site (HEAD line) | Method | Classification | Required semantics |
| --- | --- | --- | --- |
| `app/Policies/AuditLogPolicy.php:20` | `viewAny` | C → B | Organization grant allows organization rows; otherwise list only authorized restaurant rows. |
| `app/Policies/Concerns/AuthorizesRestaurantScopedCatalog.php:52` | `userCanAccessRestaurantWithAnyPermission` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/CustomerFeedbackPolicy.php:35` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/FloorPolicy.php:55` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/FloorPolicy.php:56` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/FloorPolicy.php:63` | `canManage` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/OrderPolicy.php:77` | `create` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/OrderPolicy.php:142` | `hasAnyPermission` | C → B | Restaurant permission; collection entry gate and result filters handled separately. |
| `app/Policies/OrderPolicy.php:155` | `hasPermissionInRestaurantScope` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/OrganizationPolicy.php:23` | `update` | A | Organization-wide assignment only. |
| `app/Policies/ProductPolicy.php:34` | `canManage` | C → A | Organization catalog + media: organization-wide grant only; restaurant-specific pricing/availability uses RestaurantProductPolicy. |
| `app/Policies/RestaurantPolicy.php:39` | `create` | A | Organization-wide assignment only. |
| `app/Policies/RestaurantPolicy.php:49` | `update` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:64` | `viewReports` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:79` | `manageSettings` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:92` | `viewFloorPlan` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:93` | `viewFloorPlan` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:104` | `manageFloorPlan` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:118` | `viewOperations` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:133` | `viewActivity` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:146` | `closeDay` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/RestaurantPolicy.php:159` | `viewDayCloses` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/StaffPolicy.php:92` | `canManageUsers` | C → B | Staff collection: authorized restaurant subset; view: authorized overlap; update: every affected restaurant; explicit performance/review target: target grant. |
| `app/Policies/StaffPolicy.php:105` | `canAccessStaff` | C → B | Staff collection: authorized restaurant subset; view: authorized overlap; update: every affected restaurant; explicit performance/review target: target grant. |
| `app/Policies/StaffPolicy.php:131` | `canAccessStaffInRestaurant` | C → B | Staff collection: authorized restaurant subset; view: authorized overlap; update: every affected restaurant; explicit performance/review target: target grant. |
| `app/Policies/StaffShiftPolicy.php:32` | `viewAny` | C → B | List has an explicit Restaurant; policy/controller argument corrected. |
| `app/Policies/StaffShiftPolicy.php:47` | `start` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/StaffShiftPolicy.php:62` | `end` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/TablePolicy.php:91` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/TablePolicy.php:100` | `canManage` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/TablePolicy.php:111` | `canManageStructure` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/TableRequestPolicy.php:25` | `viewAny` | C → B | Restaurant collection entry gate plus result filtering by target grants. |
| `app/Policies/TableRequestPolicy.php:58` | `hasPermissionInRestaurantScope` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/TableSessionPolicy.php:97` | `hasAnyPermissionInRestaurantScope` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/WaiterCallPolicy.php:32` | `create` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/WaiterCallPolicy.php:48` | `acknowledge` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/ZonePolicy.php:47` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/ZonePolicy.php:48` | `canView` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `app/Policies/ZonePolicy.php:55` | `canManage` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `routes/channels.php:83` | `` | B | Organization-wide assignment or assignment of the actual target restaurant; retain membership and RestaurantScope. |
| `tests/Feature/Staff/StaffIndexTest.php:129` | `test_owner_authorization_comes_from_the_seeded_permission_not_a_role_bypass` | A | Organization-wide assignment only. |

## Policies without a direct old call

CategoryPolicy, MenuPolicy, RestaurantProductPolicy, ModifierGroupPolicy and ModifierOptionPolicy delegate to AuthorizesRestaurantScopedCatalog. Every read/write ability keeps its original permission OR set, now evaluated for the resolved restaurant. Nested CategoryProduct/Modifier endpoints retain their resource/relationship checks.

OrganizationPolicy.view and RestaurantPolicy.view/viewAny are membership/reachability reads, not administrative permission checks; unchanged. ProductMediaController authorizes ProductPolicy.update/view for its organization-owned Product. PlatformAuthServiceProvider and User.hasPlatformPermission are unchanged.

## Other enforcement and role consumers

Full searches included Gate/authorize/can/allows/deny, RestaurantScope, userRoles and role.permissions. Controller policy calls remain in place. Staff create now authorizes every proposed restaurant; update authorizes every existing/proposed restaurant affected by global fields, membership or uniform-role replacement. UpdateStaffAction limits its assignment reconciliation to the active organization.

Order/table-request collection filters now intersect reachability and target permission. Kitchen dashboard has a target-specific gate. Audit rows with null restaurant_id require the organization-wide grant. Staff relationships returned to scoped managers omit other restaurants.

WaiterAssignmentEligibility and StaffShiftEligibility already select role assignments by exact organization and restaurant. ResponsibleWaiterResolver checks active organization membership and exact restaurant membership. Their eligibility semantics remain unchanged. AuthContextBuilder retains its null-or-target grant projection and existing role names.

The operational restaurant channel still requires only reachability; user channel still requires the same user. Activity channel now checks the target grant. No permission cache was introduced.

Old generic helper removed; `rg "hasPermission\(" app routes tests` has no occurrences. The new security regression asserts that User has no generic hasPermission method.
