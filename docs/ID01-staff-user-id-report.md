# ID01 — ID técnico do colaborador, sem código manual

Data: 2026-11-09 · Repos: `restaurants-api` (branch `fix/security-hardening`) e
`restaurants-web` (`main`). **Sem commit, push ou deploy. Sem migration.**

## Inspeção (antes de editar)

| Item | Achado |
|---|---|
| `users.id` | PK automática; já é o `id` do `StaffResource`, o `actor_user_id` da auditoria e o `resource_id` dos eventos `staff.*`. |
| `restaurant_users.sub_id` | String obrigatória, `unique(restaurant_id, sub_id)`, digitada pelo dono ("código de empleado"). Usada: payload de equipe (`restaurants[].sub_id`), snapshot de garçons (`assigned_waiter.sub_id`), convites (JSON `restaurant_assignments`) e reserva entre convites pendentes. **Nenhum vínculo/contrato técnico depende do valor** — só unicidade por restaurante. |
| Auditoria | `AuditLogger` já grava `actor_user_id` (quem executa) e `resource_type/id` (afetado). Filtro `actor_user_id` já existe em `GET /audit-logs`, dentro do escopo org/restaurante. `AuditLogResource` já expõe `actor.user {id, name}`. |
| Frontend | Não existe tela de consulta de logs nem perfil próprio do colaborador. O "perfil/detalhe" é o painel de «Equipo». |

## Decisões

1. **`users.id` preservado** como PK/identidade. Nenhum endpoint aceita `id`/`user_id` (validação ignora; testado).
2. **`sub_id` mantido** (contratos e unicidade por restaurante), agora **gerado no backend** por `App\Support\Staff\EmployeeCodeGenerator`: `E-{users.id}`. Não substitui o ID; é só um rótulo por restaurante.
   - **Concorrência:** cada aceite parte de um `users.id` distinto (sequência do banco), então não há corrida; `unique(restaurant_id, sub_id)` segue como garantia final. Colisão com código manual legado (ex.: alguém digitou `E-42`) → sufixo `-2`, `-3`…
   - **Valores existentes preservados:** `UpdateStaffAction` nunca altera `sub_id` de vínculo existente; só gera para vínculo novo.
   - Convites antigos ainda pendentes com `sub_id` no JSON: o valor é respeitado só se ainda estiver livre, senão é gerado (sem `INVITATION_ASSIGNMENT_CONFLICT` por código).
3. **Convites** (`StoreStaffInvitationRequest`, `StoreStaffRequest`, `UpdateStaffRequest`): sem regra/campo `sub_id`; qualquer `sub_id`/`id` enviado é descartado. Convite guarda só `restaurant_id`. `InvitationResource` não expõe mais `sub_id`. Removida a reserva de código entre convites pendentes (não faz mais sentido).
4. **Conta existente** (`AcceptInvitationAction`, modo `existing`): mesmo `users.id`; nome, senha e demais vínculos intactos; novo restaurante ganha `E-{id}`.
5. **Auditoria:** actor = executor (já era). `invitation.accepted` ganhou `metadata.user_id` (colaborador afetado) separado do actor (quem aceita). Filtro por ID exato reaproveitado (`actor_user_id`); ID de outra organização devolve página vazia idêntica à de ID inexistente.
6. **Frontend:** campo de código removido de convite e edição; texto explicando que o ID é automático; painel do colaborador mostra «ID del usuario: #N» com botão copiar (feedback inline + `role=status`); busca da equipe aceita `N`/`#N` com match **exato** (`parseStaffIdQuery`/`staffMatchesId`); código por restaurante some do card da lista (continua read-only no detalhe). i18n nos 3 idiomas.
7. **Carta pública:** nenhum recurso público expõe usuário/`sub_id` (teste de guarda adicionado).

## Arquivos

API: `app/Support/Staff/EmployeeCodeGenerator.php` (novo); `Actions/Staff/{Create,Update}StaffAction`; `Actions/Invitations/{Create,Accept}InvitationAction`; `Http/Requests/Api/V1/Staff/*`; `Http/Resources/Api/V1/InvitationResource`; `OpenApi/ApiDocumentation.php`; `storage/api-docs/api-docs.json` (regerado — ver pendências); testes (abaixo).

Web: `components/staff/{StaffForm,StaffDetailPanel}.vue`, `views/staff/StaffView.vue`, `utils/staff.ts`, `types/{staff,invitation}.ts`, `i18n/locales/*.ts` (só as chaves de equipe), `tests/frontend-staff-id.mjs` (novo), `tests/frontend-invitations.mjs`.

## Testes

Backend (banco `testing` do Sail via `scripts/test-sail.sh`): linha de base Staff+Invitations+AuditLog 254 testes verdes antes; depois, suítes afetadas verdes (Staff, Invitations, AuditLog, Tenancy, Security: 447 testes) + suíte completa (resultado na seção "Suíte completa").
Novos/alterados: geração automática; `sub_id`/`id` do cliente ignorados; colisão com código legado (unitário); aceite concorrente de 3 convites em **3 processos PHP independentes** → 3 identidades, 3 códigos distintos; conta existente preserva `users.id`/vínculos; actor ≠ afetado em `staff.updated`; actor do `invitation.accepted` + `metadata.user_id`; filtro `actor_user_id` exato, sem vazar usuário de outra organização, sem ampliar escopo de gerente; ID conhecido de outra org/restaurante → 404; carta pública sem IDs.

Frontend: `vue-tsc -b` limpo; `npm run build` ok; `frontend-staff-id` (20 checks), `frontend-invitations` (50), `frontend-security` (54), `frontend-hardening` (115), `frontend-shell`, `frontend-primitives`, `frontend-dashboard`, `frontend-service` passam. `frontend-design-tokens.mjs` falha **antes e depois** da mudança (lê `docs/design/DESIGN_SYSTEM.md`, que não existe) — pré-existente, não tocado.

Navegador real (agent-browser, API + Vite em portas próprias, banco isolado `id01_browser_testing` migrado/seedado do zero e **descartado** no fim): convite de pessoa nova sem código (201, JSON do convite só com `restaurant_id`); aceite → «ID del usuario: #8», cópia mostra «ID copiado», código `E-8`; convite de conta existente (id 5, com vínculo legado `LEGACY-7` na outra org) → aceite com senha, mesmo id 5, `LEGACY-7` intacto, novo `E-5`; busca `#8`/`8` acha só a pessoa, `7` (outra org) e `88` não retornam ninguém, `6` acha o legado; edição sem campo de código; 375 px sem rolagem horizontal; auditoria no banco: `invitation.created` actor = dono (3), `invitation.accepted` actor = aceitante (8 e 5) com `metadata.user_id`.

## Incidente de isolamento (registrar)

Na primeira tentativa de subir a API de navegador usei `php artisan serve`, que **descarta variáveis de ambiente que também existem no `.env`** — por ~1 minuto o servidor ignorou o `DB_DATABASE` isolado e apontaria para a config habitual. Requisições recebidas: `/up`, `/sanctum/csrf-cookie`, um `OPTIONS` e um `GET /auth/me` (401). Nenhuma migration/seed/escrita de domínio foi feita nele. Há 2 linhas de `sessions` recentes no banco habitual que não consigo atribuir (o seu app dev também roda); **nada foi apagado**. Troquei para `php -S` com `server.php` (variáveis respeitadas; confirmei `DB_DATABASE` do processo e o CORS do ambiente) e migrate/seed/fixtures/tinker sempre checaram o nome do banco antes (`id01_browser_testing`).

## Pendências / riscos

- **Dados:** nenhum backfill. Códigos legados continuam como estão (ex.: `W-023`); só novos vínculos usam `E-{id}`. Sem migration — o banco habitual não precisa de nada.
- **Perfil próprio do colaborador:** não existe tela; o ID aparece para quem gerencia equipe («Equipo»). Se quiser que cada pessoa veja o próprio ID, é uma etapa nova.
- **Tela de logs:** não existe no frontend; o ID já vem em `actor.user.id` da API. Quando a tela existir, exibir `Nome (#id)` e usar o filtro `actor_user_id`.
- **`storage/api-docs/api-docs.json`** foi regerado e, além do ajuste de `sub_id`, incluiu o endpoint `floor-layout` que estava ausente do arquivo versionado (defasagem anterior). Revisar o diff antes de commitar.
- Snapshot de garçons (`assigned_waiter.sub_id`) segue expondo o código por restaurante a quem já via o snapshot; sem mudança de autorização.
- As alterações pré-existentes do shell em `restaurants-web` (AAppShell, ShellNavigation, useShellLayout, tokens-v2, trechos de i18n) foram preservadas e não fazem parte do ID01.
- Checklist manual: seção «ID01» em `docs/manual-checklist-local.md` (todos PENDENTES para a sua rodada).
