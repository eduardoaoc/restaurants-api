# PR-SEC1A.3 — FEEDBACK TENANT BOUNDARY FIX + SECURITY CLOSEOUT

Data: 2026-10-04 (Europe/Madrid).

## PRECHECK

HEAD inicial e final: `a3fad32` (`main`), contendo a correção SEC-001, um commit à frente de `origin/main`. `git status`, `git diff --check` e `git log --oneline --decorate -15` executados antes de editar. Working tree deliberadamente não limpa: 13 arquivos tracked modificados e 16 untracked da PR-SEC1A.2, correspondentes ao inventário do relatório anterior. Nenhuma alteração desconhecida observada. SEC-002/003/004/005, worker de concorrência, testes e documentos preservados. Relatórios históricos não reescritos.

Reprodução inicial: `./vendor/bin/sail artisan test tests/Feature/Security/FeedbackSummaryTenantBoundaryTest.php` — **FAIL**, 2 testes, 6 assertions, duas falhas COUNT (1 recebido, 0 esperado). Os asserts originais foram preservados; adicionadas assertions para todos os averages nulos.

## SEC-006 ROOT CAUSE

`RestaurantScope::accessibleRestaurantIds` retorna `null` como “todos os restaurantes da organização alvo”. O controller próprio passava esse valor diretamente ao resolver, que suprimia inteiramente o filtro de restaurantes e consultava somente `waiter_id`. Isso incluía histórico de organizações estrangeiras, mesmo com membership revogada. A ausência de linhas individuais/PII não torna legítima essa agregação.

**OLD RESOLVER CONTRACT:** `forWaiter(int $waiterId, ?array $restaurantIds = null)`; nenhum organization ID obrigatório.

**NEW RESOLVER CONTRACT:** `forWaiter(int $waiterId, int $organizationId, array $restaurantIds)`. Os três argumentos são obrigatórios. Docblock define a lista como restrição adicional dentro da organização e `[]` como zero feedback/averages null.

**MANDATORY QUERY SCOPE:** `where('waiter_id', $waiterId)`, `where('organization_id', $organizationId)` e `whereIn('restaurant_id', $restaurantIds)` sempre presentes. Eloquent usa bindings; COUNT/AVG continuam expressões internas constantes. Lista estrangeira não supera o filtro de organização. `[]` gera uma condição vazia de resultado, nunca wildcard.

**CALL SITES:** somente dois callers de aplicação, ambos em `CustomerFeedbackSummaryController`:

- `me`: resolve usuário e organização ativa; se RestaurantScope retorna null, materializa apenas IDs de Restaurant com `organization_id` da organização ativa; passa waiter, organization ID e lista concreta.
- `show`: mantém resolução de restaurante por tenant/scope, relação de staff e StaffPolicy; passa organização ativa e `[$restaurantModel->id]`.

RestaurantScope e TenantContext não foram alterados. Nenhum registro histórico foi removido ou reatribuído. Nenhuma migration/schema, frontend ou grant ampliado.

## SEC-006 TESTS

| Caso | Evidência |
| --- | --- |
| Inactive foreign membership | Reprodução original; count 0 e todos os averages null em A; recurso direto B continua 404 |
| Removed foreign membership + role/link | Reprodução original; mesmo resultado |
| Active A+B, tenant A | Um feedback rating 5 em A e rating 1 em B; somente A incluído |
| Active A+B, tenant B | Mesmo contraste; somente B incluído |
| Organization-wide A1+A2 | Dois feedbacks ratings 5/1; count 2 e todos os averages 3 |
| Restaurant-scoped A1 only | Histórico em A1/A2; removido acesso A2; count 1 e averages 5; dois registros históricos preservados |
| Empty restaurant set | Resolver retorna count 0 e quatro averages null apesar de feedback existente |
| Foreign restaurant ID defense | Organization A + IDs de B retorna zero/null; controle positivo em B conta o mesmo feedback |

ResolveTenant atualmente escolhe a primeira membership ativa; não há seletor HTTP de organização. Os dois testes A+B criam a organização que deve ficar ativa primeiro e verificam TenantContext após o request, além de confirmar duas memberships ativas. Não desativam a outra organização para simular o teste. Fixtures de histórico usam atribuição de waiter, serviço e pagamento pelas actions reais antes de criar o feedback.

## SIMILAR NULL-SCOPE AUDIT

Busca `rg "accessibleRestaurantIds" app`: **48 invocações diretas em 34 arquivos**, incluindo as duas chamadas internas do próprio RestaurantScope. `WaiterAssignmentEligibility` aparece apenas em documentação explicando por que não usa esse scope.

| Callers revisados | Limite que permanece quando a lista é null |
| --- | --- |
| RestaurantController, RestaurantSettingsController, DayCloseWhatsAppSettingsController, CashMovementController, FloorPlanController, RestaurantActivityController | Query Restaurant com organization_id obrigatório |
| RestaurantAnalyticsController, RestaurantDashboardController, RestaurantOperationsController, KitchenController | Restaurante resolvido com organization_id antes de analytics/dashboard/operação/cozinha |
| FloorController, ZoneController | Restaurant com organization_id; detalhe floor/zone via relação restaurant com organization_id |
| TableController, TableSessionController, OrderController | Table/order via relação restaurant com organization_id; queries de restaurante também limitadas |
| TableSessionBillController, BillReceiptController, TableSessionTransferController, TableSessionVoidController, TableSessionWaiterController, WaiterCallController, KitchenTicketController | Sessão/order via relação restaurant com organization_id |
| TableRequestController | Queries de request e validação de table via relação restaurant limitada à organização |
| DayCloseController, DayCloseDeliveryController | organization_id obrigatório em closes/deliveries, também restaurante alvo limitado |
| CustomerFeedbackController | Query feedback com organization_id e restaurante alvo limitado |
| CustomerFeedbackSummaryController | Ambos os callers corrigidos conforme contrato acima |
| StaffController, StaffShiftController, StaffReviewController | Staff via restaurantes da organização, shifts via relação restaurant, reviews via restaurante já resolvido; eager loads de roles/restaurantes filtrados |
| StaffPerformanceController | me materializa IDs da organização; show resolve restaurante limitado; serviço exige listas concretas, sem wildcard null |
| AuditLogController | organization_id obrigatório e validação explícita de restaurant_id dentro da organização, além do scope |
| AuthContextBuilder | Cada organização constrói sua própria lista de restaurantes com organization_id obrigatório |
| RestaurantScope::restaurantIdsWithAnyPermission / canAccessRestaurant | Primeiro materializa restaurantes com organization_id + grants dessa organização; segundo consulta scope da organização do restaurante concreto, com membership/tenant checados pelos callers |

**Potential escapes found:** SEC-006 era o único wildcard global observado nesses fluxos. **New findings:** nenhum cross-organization escape adicional confirmado. Isso é revisão dos callers encontrados, não auditoria de todas as internals dos pacotes.

## REMAINING ADVERSARIAL CHECKS

Retomada direcionada, sem reiniciar a auditoria inteira:

- **Tenant nested IDs:** matriz A1→A2/A1→B1 expandida com floors, zones, floor-plan, feedback-summary de staff e writes floor/zone/category placement; novo teste combina parent acessível com staff/product estrangeiros e product não relacionado à categoria. Falhas de autorização mantêm dados intactos. Matriz ampla existente de Tenancy/Permissions também executada.
- **Mass assignment:** novo PATCH staff injeta organization_id/user_id/restaurant_id/is_admin/platform_role/role_id/permission_id; nome permitido muda, roles e links permanecem, nenhuma membership estrangeira aparece e acesso de plataforma continua 403. Amostras anteriores de produto/translations/layout/order/payment preservadas.
- **Privilege:** owner/manager/waiter/cashier/kitchen em chamadas diretas e canais; regressões SEC-001 em Permissions/Staff e full suite. Sem nova interpretação de capabilities que amplie grants.
- **Idempotency malformed:** payment/cash com 101 caracteres e espaços, public order com chave longa e chave de body como array. Longas retornam 422 sem writes. Payment/order aceitam espaços como string opaca, seguindo contrato nullable; cash required rejeita string só de espaços. Body key é substituída pela fonte header. Missing/empty/long cash e mismatch/replay existentes mantidos. Não se exige UUID nem se declara inválida uma string opaca aceita pelo contrato.
- **Input boundaries:** profundidade moderada de 20 níveis em campo string, translations scalar, locale/name/description como arrays e allergens aninhados retornam 422 sem products. Não representa fuzzing ilimitado nem prova limites de proxy/PHP em produção.
- **WebSocket:** membership removida adicionada aos casos de revogação; activity e canal operacional base exercitados. Remoção apenas de role nega activity, mas mantém canal base quando membership/link ainda válidos, conforme contrato operacional explícito em channels.php. Inactive/removed membership, link removido, user/organization suspended negam ambos. Canal próprio continua definido por identidade, não por membership de tenant.
- **Information disclosure:** testes anteriores APP_DEBUG=false de 403/404/422/429/500 mantidos; novo file response 404 não expõe PathTraversalDetected/storage path; resource de agregado continua somente count/averages e testes de PII anteriores executados.
- **Upload/path:** uploads reais, filenames traversal/double encoding e mídia antiga preservada nos testes anteriores; novo teste de filename PDF usa traversal, backslash, CRLF, encoding e Unicode longo na snapshot, exigindo nome ASCII limitado. HTTP de PDF/safe headers/rendering também executado no grupo DayClosePdf. Novo HTTP em rota efêmera de teste invoca o ServeFile instalado sem modificá-lo: arquivo privado unsigned retorna 404, arquivo assinado válido 200, traversal assinado/encoded retorna 404. A chave de assinatura é local de teste; nenhum caminho real sensível foi consultado. Sem decoder de vídeo, corrida de mídia ou infra externa nesta carta.

As duas falhas intermediárias de assertions sobre canal base/whitespace refletiam expectativas que não correspondiam aos contratos existentes, não autorização indevida. Ajustadas para testar o contrato documentado; nenhum assert original de SEC-006 foi relaxado. Erros intermediários de fixture (role duplicada e cache de nomes da rota efêmera) foram corrigidos somente nos testes.

## PRESERVED FIXES

SEC-001: preservado no HEAD, regressões executadas.

- SEC-002: write false aborta antes de substituir registro/apagar mídia antiga.
- SEC-003: guards de tipo na normalização e bounds de login preservados.
- SEC-004: amount com oito dígitos inteiros antes de conversão/persistência.
- SEC-005: image/dimensions rejeitam JPEG corrupto sem criar product_media.

## QUALITY GATE

- **Security suite: PASS**, `./vendor/bin/sail artisan test tests/Feature/Security`: **121 testes / 912 assertions**, zero failures/errors, 29,324 segundos.
- **Grupos focados: PASS**, Security + CustomerFeedback + Tenancy + Permissions + os três StaffPerformance + DayClosePdf: **255 testes / 1.331 assertions**, zero failures/errors, 66,417 segundos.
- **Full suite: PASS**, `./vendor/bin/sail artisan test`: **1.974 testes / 7.733 assertions**, todos PASS, **zero failures/errors**, 428,337 segundos.
- **PINT: PASS**, `./vendor/bin/sail bin pint --dirty --test`. Pint aplicado antes dos testes finais; somente formatação dos testes indicados nesta execução.
- **COMPOSER AUDIT: PASS**, `./vendor/bin/sail composer audit --locked`: zero security advisories.
- **COMPOSER VALIDATE: PASS**, `./vendor/bin/sail composer validate`: composer.json válido.
- **DIFF CHECK: PASS**, `git diff --check`; whitespace/final newline dos untracked também conferidos.

Testes usam banco testing e fixtures/fakes locais. Composer consultou o feed de advisories autorizado pela carta. Nenhuma conexão de aplicação externa, Meta, Supabase ou Hostinger; nenhum secret real exibido. HEAD permanece `a3fad32` e nada foi staged.

## FILES CHANGED IN THIS EXECUTION

- `app/Support/Feedback/CustomerFeedbackSummaryResolver.php`
- `app/Http/Controllers/Api/V1/CustomerFeedbackSummaryController.php`
- `tests/Feature/CustomerFeedback/CustomerFeedbackSummaryTest.php` — apenas comentário da fixture owner sem waiter role; assertions preservadas.
- `tests/Feature/Security/FeedbackSummaryTenantBoundaryTest.php`
- `tests/Feature/Security/TenantResourceMatrixTest.php`
- `tests/Feature/Security/CapabilityAndRevocationTest.php`
- `tests/Feature/Security/FinancialInputTest.php`
- `tests/Feature/Security/AdversarialCatalogTest.php`
- `tests/Feature/Security/SecurityCloseoutTest.php` — novo.
- Este relatório — novo.

Arquivos da PR-SEC1A.2 seguem locais e unstaged. `git diff --stat` não inclui untracked.

## FINDINGS REMAINING

| Severity | Findings confirmados remanescentes |
| --- | ---: |
| Critical | 0 |
| High | 0 |
| Medium | 0 — SEC-006 corrigido e regressões verdes |
| Low | 0 — SEC-002/003/004/005 preservados e verdes |
| Informational | Limites de cobertura/infra descritos acima e nos relatórios anteriores; nenhum novo finding numerado |

## SECURITY VERDICT

**A) NO CRITICAL/HIGH OR CROSS-TENANT FINDINGS REMAIN. LOCAL BACKEND SECURITY GATE APPROVED. READY FOR SUPABASE SECURITY VALIDATION.**

SEC-006 encerrado nesta execução. O relatório PR-SEC1A.2 permanece como histórico da execução bloqueada; este relatório registra a correção e o closeout local posterior. A retomada é direcionada aos checks baratos/relevantes pedidos pela carta, sem declarar concluídas todas as matrizes integrais/fuzzing/infra anteriormente limitadas. “Ready” autoriza a próxima etapa de validação, não atesta Supabase/hosting já validado ou configurado.

COMMIT: NO. PUSH: NO. DEPLOY: NO. SUPABASE/HOSTINGER: NÃO CONFIGURADOS.
