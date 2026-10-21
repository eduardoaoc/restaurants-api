# Pré-9.2 — auditoria das permissões do manager

Data: 2026-10-03. Ambiente local; `main`, HEAD `d05f3bd`; árvore inicialmente limpa.

## Causa

Não foi encontrado drift em `role_permissions`: as cinco roles correspondem
integralmente a `RolePermissionSeeder::ROLE_PERMISSIONS`, sem grants faltantes
ou extras. As permissions `close_daily_operation` e `view_daily_closes` existem.

A conta `manager@aforo.test` (user 4) estava ativa, mas sua associação à
organização AFORO Demo (organization 1, organization_users 2) estava `inactive`,
com updated_at em 2026-09-30 15:43:42. A role manager continuava atribuída ao
restaurante 1. `AuthContextBuilder` omite organizações com associação inativa;
`ResolveTenant` bloqueia acesso quando não há organização ativa. Assim, o login
retornava 200, o contexto não continha organizações e os endpoints retornavam
403. Não é possível atribuir a desativação a um workflow específico apenas
com esses dados.

## Correção e verificação real

Com autorização explícita do usuário, o owner local autenticado executou
`PATCH /api/v1/staff/4` com `{"status":"active"}`: HTTP 200.
Nenhum SQL manual de escrita foi utilizado. Nenhum seeder foi executado no
banco local, pois a matriz já estava sincronizada. Nenhum dado de QA foi apagado.

| Manager | Código antes | Banco antes | Banco depois | Contexto real depois, restaurante 1 |
| --- | --- | --- | --- | --- |
| manage_restaurants | true | present | present | true |
| close_daily_operation | true | present | present | true |
| view_daily_closes | true | present | present | true |

As permissões da organização e do restaurante são escopos distintos: o manager
restaurant-scoped recebe suas capabilities em `organizations[].restaurants[].permissions`.

Logins reais via Sanctum, cookies de sessão e CSRF, usando as contas existentes:

| Conta | History | Detail | WhatsApp settings |
| --- | --- | --- | --- |
| manager@aforo.test, após reativação | 200 | 200 | 200 |
| owner@aforo.test | 200 | 200 | 200 |
| waiter1@aforo.test | 403 | 403 | 403 |
| cashier@aforo.test | 403 | 403 | 403 |
| kitchen1@aforo.test | 403 | 403 | 403 |

Endpoints: `GET /api/v1/restaurants/1/day-closes`,
`GET /api/v1/day-closes/2` (fechamento existente de 2026-10-02),
`GET /api/v1/restaurants/1/day-close-whatsapp-settings`.
Owner recebeu `available=false`, aceitável sem Meta configurado.

A matriz também foi conferida para owner (três grants), waiter e cashier
(close_daily_operation, sem view_daily_closes), kitchen (nenhuma das três).

## Regressão

Já existem testes de autorização e projeção em `DayCloseHistoryTest`.
Acrescentado `DailyClosePermissionSeederTest` para verificar diretamente as
três capabilities nas cinco roles e a execução repetida de PermissionSeeder
mais RolePermissionSeeder após o provisioning inicial com RoleSeeder.
Nenhum comportamento runtime ou policy foi alterado.

## DemoRestaurantSeeder e produção

DemoRestaurantSeeder não foi alterado. DatabaseSeeder provisiona as roles e
permissions globais, mas não chama DemoRestaurantSeeder. O demo seeder é
independente e pressupõe roles existentes; seu teste também provisiona as
roles/permissions antes de executá-lo. Não há evidência no repositório de qual
comando é executado com maior frequência no ambiente local. Neste diagnóstico,
a causa foi associação inativa, não permissions adicionadas após o seed.

Pendência para Production Readiness: garantir explicitamente após migrations a
sincronização idempotente de PermissionSeeder e RolePermissionSeeder, ou outro
provisioning equivalente. Migrations mantêm a estrutura; esses seeders mantêm
a matriz de capabilities. Pipeline/deploy não foi implementado nesta correção.

Commit: NO. Push: NO. Frontend, policies, Meta e implementação 9.2 inalterados.

## Resultado dos testes

- Permissions, Auth, DayClose e WhatsApp: 209 testes passaram, 1.579 assertions.
- Suíte completa (`sail artisan test`): 1.842 testes passaram, 6.757 assertions.
- Pint (`sail bin pint --dirty --test`): passou.
- `git diff --check`: passou.

A primeira rodada teve interferência porque duas execuções compartilharam o
banco `testing`. A execução sobreposta foi interrompida; os testes selecionados
e depois a suíte completa foram repetidos em sequência e passaram. Nenhuma
alteração foi necessária para resolver falhas no código de produto.

Arquivos adicionados: este relatório e o teste focado de permissões.
`git diff --stat` permanece vazio porque os dois arquivos são novos e não
foram staged; estatísticas incluindo arquivos novos: 2 arquivos, 137 linhas.

Veredito: matriz correta, associação do manager reativada e ambiente validado
para CARTA 9.2. Nenhuma implementação de 9.2 foi feita.
