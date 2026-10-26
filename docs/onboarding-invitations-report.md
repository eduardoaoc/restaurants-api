# Cadastro manual de restaurante → convite do dono → convite de funcionários

> **Atualização posterior (2026-10-22):** emissão do token passou a ser por
> envio com job criptografado (retry não invalida mais o link), recuperação de
> senha implementada, OpenAPI regenerado e ambiente local ativado (migrations,
> provisioning, `.env`, worker de e-mail). Ver `docs/pre-manual-hardening-report.md`;
> pendências de §9/§15 abaixo resolvidas lá onde indicado.

Data: 2026-10-22. Repositórios: `restaurants-api` (branch `fix/security-hardening`,
HEAD `c195492`) e `restaurants-web` (branch `main`, HEAD `9ee3d46`). **Nada
commitado, nenhum push, nenhum deploy.** Spec SDD:
`.oh-my-sdd/specs/owner-staff-invitations/{spec,plan,tasks}.md` (registrada
manualmente sobre a instalação existente; os checkpoints humanos interativos
das skills oh-my-sdd **não foram executados**).

Legenda de status usada abaixo: **I** implementado · **T** testado
automaticamente · **R** integração real isolada (navegador + API + Postgres
isolados) · **M** manual pendente (rodada final do usuário).

## 1. Estado de partida (git e árvore)

- API: árvore **não estava limpa** antes da tarefa — `M package.json`
  (dependência `oh-my-sdd`), `?? .env.staging.example`, `?? package-lock.json`.
  Preservados, não tocados.
- Web: `M .env.example`, `?? t.txt`, `?? .claude/skills/ui-ux-pro-max/scripts/__pycache__/`,
  `?? *:Zone.Identifier` (5). Preservados, não tocados.

## 2. O que já existia (auditoria)

| Área | Situação | Evidência |
|---|---|---|
| Plataforma × tenant | existe e funciona | `platform_role_assignments`, `EnsurePlatformAdmin`, gates `platform.*` em `PlatformAuthServiceProvider` |
| Cadastro de org/restaurante pela plataforma | **faltava** | `/platform/*` só listava/suspendia/plano |
| Restaurante adicional (tenant) | existe | `POST /restaurants` + `RestaurantSettings::createDefaultsFor` (Europe/Madrid, EUR, es-ES) |
| Vínculo do dono | parcial | modelado como `user_roles` org-wide (`restaurant_id` null), criado só em seeders/testes |
| Convites | **faltava** | nenhuma tabela/rota/notificação |
| Recuperação de senha / verificação de e-mail | faltava (segue fora de escopo) | `password_reset_tokens` sem rotas; `MustVerifyEmail` comentado |
| Staff | existe, sem convite | `POST /staff` cria usuário com **senha definida pelo dono**; `users.email` único global impede conta existente de outra org |
| Proteções do staff | existem | escopo (404) antes de permission (403), sem auto-desativação, e-mail global não editável para identidade multi-org, `ALLOWED_ROLES` sem owner |
| Auth context | existe | `/auth/context` lista todas as orgs ativas; **mas** `ResolveTenant` sempre usava a 1ª membership → usuário de 2 orgs nunca operava a 2ª |
| E-mail / filas | infraestrutura só | nenhum Mailable/Notification; `MAIL_MAILER=log` local **e** em `.env.staging.example` |
| Front: plataforma | **faltava** | nenhuma rota/menu; admin sem org caía num shell que dava 403 |
| Front: aceite / convites de equipe | **faltava** | `StaffForm` pedia senha inicial |

## 3. Lacunas fechadas e decisões

1. **Tabela `invitations` única** (dono e staff), segredo só como SHA-256,
   gerado no momento de montar o e-mail (`InvitationTokens::mint`) — nem a
   linha, nem o payload do job, nem `failed_jobs`, nem auditoria guardam algo
   reutilizável. Índice único parcial impede dois convites pendentes para o
   mesmo e-mail na mesma organização.
2. **Onboarding de plataforma** (`POST /platform/restaurants`) com
   `Idempotency-Key` + hash do payload (padrão já usado no projeto), em
   transação; e-mail enfileirado só depois do commit.
3. **Staff por convite** (`/staff/invitations`), reutilizando `StaffPolicy`,
   `RestaurantScope`, `ALLOWED_ROLES` e o vínculo de `CreateStaffAction`
   (extraído para `attach()` sem mudar o contrato de `execute()`).
4. **Organização ativa por sessão** (`PUT /auth/active-organization`): o
   `ResolveTenant` honra a preferência só com membership ativa; senão mantém a
   regra antiga (menor id). Necessário para a conta existente convidada para
   uma segunda organização.
5. **Decisão registrada para o usuário:** `POST /staff` (senha definida pelo
   dono) **continua existindo** por compatibilidade; o frontend não o usa mais.
   Remover é decisão de produto (quebra contrato e ~15 testes).
6. Prévia do convite é **POST** com o token no corpo (não GET com token na
   URL), para o segredo nunca ir a log de acesso, histórico ou Referer. O
   requisito "prévia não consome" está coberto por teste.

## 4. Fluxo Super Admin → dono → funcionários

1. Super Admin (permissão nova `create_platform_restaurants`) abre
   `/platform/onboarding`: organização nova (nome/identificador) **ou**
   existente (exige também `manage_platform_organizations`; org suspensa
   recusada), restaurante (nome/identificador, fuso, idioma; moeda EUR),
   e-mail/nome do dono (só org nova). **I T R**
2. Backend cria organização (`free`/`active`, sem cobrança), restaurante,
   configurações padrão e convite de dono — nada de produtos, mesas, staff,
   dia operacional ou membership do admin. **I T R**
3. Resumo na tela com estado do convite (`Pendente` / `Caducada` / `Aceptada`
   / `Cancelada`) e entrega (`En cola` / `Entregada al servidor de correo` /
   `Error de envío`), reenviar (com contagem regressiva do cooldown) e
   cancelar (com confirmação). Lista lateral de convites de donos; para
   convite cancelado, "Invitar de nuevo" (só enquanto a org não tem dono
   ativo). **I T R**
4. Dono abre `/invitation#token=…`: o fragmento é lido uma vez e removido da
   barra/histórico. Prévia → conta nova (nome + senha + confirmação) ou conta
   existente (senha atual). Ao aceitar: sessão iniciada, organização do
   convite vira a ativa, dashboard do restaurante (dia operacional fechado,
   checklist de configuração). **I T R**
5. Dono em Equipo → "Invitar persona" (sem senha): nome, e-mail, função
   (encargado/camarero/cocina/caja), restaurantes + código de empleado.
   Convites pendentes listados com reenviar/cancelar; aceitos passam ao
   roster. **I T R**

## 5. Contratos conta nova × existente

| | Conta nova | Conta existente |
|---|---|---|
| Endpoint | `POST /invitations/accept` `{token,name,password,password_confirmation}` | `POST /invitations/accept-existing` `{token,password}` |
| Quem é a conta | sempre o e-mail do convite (cliente nunca envia e-mail) | idem |
| Senha | definida pela pessoa (mín. 8, confirmada) | senha atual exigida; **não alterada** |
| Nome/identidade | escolhido pela pessoa | **não alterado** (verificado: "Marta Multi" manteve o nome) |
| E-mail verificado | sim, no aceite (provou a caixa) | inalterado |
| Sessão de outra pessoa | `409 INVITATION_ACCOUNT_MISMATCH` (front mostra "Cerrar sesión") | idem |
| Caminho errado | `409 INVITATION_ACCOUNT_EXISTS` | `409 INVITATION_ACCOUNT_REQUIRED` |
| Conta suspensa / de plataforma | — | `403 INVITATION_ACCOUNT_SUSPENDED` / `409 INVITATION_NOT_ALLOWED_FOR_ACCOUNT` |
| Vínculo inativo pré-existente | — | `409 INVITATION_MEMBERSHIP_CONFLICT` (nunca reativa) |

Respostas públicas descrevem só o próprio convite (nomes da org/restaurantes,
função, e-mail do convite, expiração, `account_exists` do próprio e-mail);
nunca outras organizações da conta. Senha errada é 422 no campo `password`
(não 401, para não disparar a expiração de sessão do SPA).

## 6. Permissions e isolamento

- Plataforma: `create_platform_restaurants` (nova, só `super_admin`);
  organização existente também exige `manage_platform_organizations`. Tenant
  nunca alcança `/platform/*` (403). **T**
- Payload não injeta tenant/grants/plano/status/permissões (chaves
  explícitas; `owner`, `role`, `permissions`, `organization_id` extras
  ignorados ou recusados). **T**
- Staff: papel só em `ALLOWED_ROLES`; owner/super_admin/qualquer outro → 422;
  restaurante de outro tenant → 422; fora do escopo do gerente → 404;
  sem `manage_users` → 403; listar/reenviar/cancelar exige `manage_users` em
  **todos** os restaurantes do convite; convites de dono invisíveis na API de
  staff e convites de staff invisíveis na API de plataforma. **T R**
- Conta de outra org convidada: resposta idêntica a e-mail novo; papel em A
  não herda o de B (gerente em B segue camarera em A). **T R**
- Contexto: troca de organização só para membership ativa (404 caso
  contrário; suspensa → 409); preferência obsoleta cai no padrão; valor de
  sessão nunca concede org alheia; front limpa restaurante/realtime antes de
  recarregar. **T R**
- Último dono: proteções existentes preservadas (sem auto-desativação; não
  há transferência de propriedade; plataforma só reconvida dono se a org não
  tem nenhum dono ativo).

## 7. Expiração, reenvio, revogação

- Expiração: 72 h (`AFORO_INVITATION_EXPIRES_HOURS`); estado `expired`
  derivado; aceite/prévia → `410 INVITATION_EXPIRED`. **T**
- Reenvio: mesmo registro, novo segredo (link anterior morre na hora),
  expiração renovada, `delivery_sequence` avança (job antigo na fila é
  descartado por `shouldSend`), cooldown 60 s → `429 INVITATION_RESEND_TOO_SOON`
  com `Retry-After`; limiter de rota adicional. Nunca duplica usuário,
  restaurante ou grant. **T R**
- Revogação: lock da linha; hash substituído; aceite concorrente resolve em
  exatamente um resultado (teste com processo PHP independente esperando o
  lock). **T**
- Uso único e aceite concorrente: dois aceites simultâneos → 1 conta, 1
  vínculo, 1 auditoria; o perdedor recebe `410 INVITATION_ALREADY_ACCEPTED`. **T**
- Rate limit público: prévia/aceite 30/min por IP e 10/min por token;
  aceite com conta existente 10/min por IP e 5/min por token. **T**

## 8. Estados de entrega de e-mail

| Estado | Significa | Não significa |
|---|---|---|
| `queued` | job criado (ou envio ainda não processado) | que algo saiu |
| `sent` | o transporte de e-mail aceitou a mensagem deste envio (evento `NotificationSent`) | que chegou à caixa do destinatário |
| `failed` | última tentativa falhou (`failed()` do job ou falha ao enfileirar) | que o cadastro falhou — fica íntegro e recuperável por reenviar |
| `state=accepted` | a pessoa aceitou | — (eixo separado da entrega) |

Falha atrasada de um job antigo não sobrescreve um envio mais novo (filtro
por sequência). **T**. Na integração isolada, o "e-mail" foi capturado pelo
mailer `log` no storage isolado — **nenhum e-mail real enviado**.

## 9. Migrations / provisioning necessários (NÃO aplicados no habitual)

Leitura somente (2026-10-22, `sail artisan migrate:status` e config):
pendentes no habitual `2026_10_22_000001_create_restaurant_daily_operations_table`
(anterior), **`2026_10_23_000001_create_invitations_table`** e
**`2026_10_23_000002_add_onboarding_idempotency_to_restaurants_table`**;
`mail=log`, `queue=database`, `AFORO_WEB_URL` **não definido**, env `local`.

- `2026_10_23_000001`: tabela nova `invitations` + índice único parcial.
  Não toca dados existentes.
- `2026_10_23_000002`: duas colunas nulas em `restaurants`
  (`onboarding_idempotency_key` único, `onboarding_payload_hash`). Sem backfill;
  contas/vínculos históricos intactos.
- Provisioning: `aforo:provision` (já existente) cria a permissão de
  plataforma `create_platform_restaurants` e o grant ao `super_admin`
  (`updateOrCreate` + `syncWithoutDetaching`, nada removido).
- Configuração: `AFORO_WEB_URL` (já usada pelo WhatsApp) precisa apontar para o
  frontend; sem ela o convite fica `failed` (recuperável por reenvio depois de
  configurar). Worker de fila consumindo a fila padrão para os e-mails saírem
  com `QUEUE_CONNECTION=database`.

**Ambiente habitual não está pronto** para este fluxo até: migrations acima,
`aforo:provision`, `AFORO_WEB_URL`, worker, e mailer adequado.

Ordem proposta (etapa própria, aprovada): backup → `sail artisan migrate
--path=…2026_10_22_000001…` (se ainda não) → `--path=…2026_10_23_000001…` →
`--path=…2026_10_23_000002…` → `sail artisan aforo:provision` → definir
`AFORO_WEB_URL` → worker. Nunca `migrate` genérico.

**Alerta para staging:** `.env.staging.example` traz `MAIL_MAILER=log`. Com
isso o corpo do e-mail (com o link de uso único) vai para o log da
aplicação. Aceitável só como caixa local; staging precisa de SMTP/caixa de
captura antes de ativar convites. Nada foi alterado em `.env*`.

## 10. Ocorrência durante a validação (registrada, contida)

O config cache herdado das rodadas anteriores (usado como base dos ambientes
isolados) tem o **caminho absoluto do log** apontando para
`restaurants-api/storage/logs/laravel.log` (o habitual). Na primeira tentativa
de integração isolada, **um** e-mail de teste (4 ocorrências do link: texto e
HTML) de um convite que só existia no banco isolado `onboarding_validation`
foi escrito nesse log. Contenção: o convite foi reenviado (o token antigo
passou a responder 404 — verificado) e depois aceito; os caminhos de log dos
dois configs isolados foram redirecionados ao storage isolado; nenhum envio
posterior chegou ao log habitual (verificado por contagem). O arquivo de log
habitual **não foi editado**. Pelo mesmo motivo, a suíte de testes desta e
das rodadas anteriores pode ter escrito exceções reportadas (sem segredos) no
log habitual. Recomendação: regerar o config cache isolado em vez de copiar o
antigo.

## 11. Arquivos alterados

### restaurants-api

Modificados: `app/Actions/Staff/CreateStaffAction.php` (extrai `attach()`),
`app/Http/Controllers/Api/V1/Auth/AuthController.php` (organização ativa),
`app/Http/Middleware/ResolveTenant.php`, `app/Support/Auth/AuthContextBuilder.php`
(`active_organization_id`), `app/Models/AuditLog.php` (eventos/recurso),
`app/Providers/AppServiceProvider.php` (listener de entrega + limiters),
`app/Providers/PlatformAuthServiceProvider.php`, `bootstrap/app.php`
(renderer), `routes/api.php`, `database/seeders/PlatformPermissionSeeder.php`,
`database/seeders/PlatformRolePermissionSeeder.php`.

Novos: `app/Models/Invitation.php`, `app/Support/Invitations/{InvitationTokens,InvitationLink,InvitationMailer}.php`,
`app/Support/Tenancy/ActiveOrganization.php`, `app/Notifications/InvitationNotification.php`,
`app/Exceptions/Invitations/InvitationException.php`,
`app/Actions/Invitations/{Create,Resend,Revoke,Accept}InvitationAction.php`,
`app/Actions/Platform/OnboardRestaurantAction.php`,
`app/Http/Controllers/Api/V1/{InvitationController,StaffInvitationController}.php`,
`app/Http/Controllers/Api/V1/Platform/{PlatformOnboardingController,PlatformInvitationController}.php`,
`app/Http/Requests/Api/V1/Auth/SelectActiveOrganizationRequest.php`,
`app/Http/Requests/Api/V1/Invitations/{Preview,Accept,AcceptExisting}InvitationRequest.php`,
`app/Http/Requests/Api/V1/Platform/{OnboardPlatformRestaurant,StoreOwnerInvitation,IndexPlatformInvitations}Request.php`,
`app/Http/Requests/Api/V1/Staff/StoreStaffInvitationRequest.php`,
`app/Http/Resources/Api/V1/InvitationResource.php`, `config/invitations.php`,
2 migrations, `tests/Concerns/InteractsWithInvitations.php`,
`tests/Feature/Invitations/{PlatformOnboarding,InvitationAcceptance,StaffInvitation,InvitationLifecycle,InvitationConcurrency}Test.php`,
`tests/Feature/Tenancy/ActiveOrganizationTest.php`,
`tests/Support/invitation-accept-worker.php`, spec SDD, este relatório,
adendo em `docs/QR-daily-operation-report.md`.

### restaurants-web

Modificados: `src/api/session.ts` (endpoints de convite liberados no guard de
sessão encerrada), `src/router/index.ts` (`/invitation`, `/platform`,
redirecionamento do admin sem org), `src/stores/auth.ts`
(`switchOrganization`, `adoptAcceptedSession`), `src/services/auth.service.ts`,
`src/types/auth-context.ts`, `src/utils/error-message.ts` (códigos novos,
inclusive em 403/429), `src/components/shared/UserMenu.vue` (troca de
organização + link de plataforma), `src/components/staff/StaffForm.vue`
(criação = convite, sem senha), `src/views/staff/StaffView.vue` (convites
pendentes, formulário também com equipe vazia), `src/i18n/locales/*` (CRLF
preservado).

Novos: `src/types/invitation.ts`, `src/services/{invitations,platform}.service.ts`,
`src/composables/useStaffInvitations.ts`, `src/components/invitations/InvitationItem.vue`,
`src/layouts/PlatformLayout.vue`, `src/views/platform/PlatformOnboardingView.vue`,
`src/views/invitations/InvitationAcceptView.vue`, `tests/frontend-invitations.mjs`,
`docs/onboarding-invitations.md` (ponteiro).

## 12. Testes e resultados

Ambiente: Postgres temporário `127.0.0.1:55443`, DB **`onboarding_testing`**
(nome conferido antes de cada execução), storage e config isolados no
scratchpad, PHP 8.4 com GD/pdo_sqlite/BCMath temporários via
`PHP_INI_SCAN_DIR` (extensões presentes conferidas), uma suíte por vez.

- Linha de base antes de mexer: Auth+Platform+Staff+Tenancy **256/256**.
- Novos: `PlatformOnboardingTest` 18, `InvitationAcceptanceTest` 18,
  `StaffInvitationTest` 16, `InvitationLifecycleTest` 9,
  `InvitationConcurrencyTest` 2 (processos independentes esperando lock real),
  `ActiveOrganizationTest` 7 — **70/70**.
- Regressão ampliada (Auth, Platform, Staff, Tenancy, Provisioning, Security,
  Permissions, AuditLog, Organization, Restaurant, RestaurantSettings,
  Invitations, Realtime): **712/712, 3055 assertions**.
- **Suíte backend completa sequencial: 2143 testes, 2142 ok + 1 erro
  ambiental** (`DayCloseNoForceTest` lia `storage/api-docs/api-docs.json`,
  ausente no storage isolado novo); após copiar o arquivo gerado existente
  (só leitura do repo) o teste passa (6/6). Nenhum teste suprimido.
- Pint `--test` nos 47 arquivos PHP exatos da tarefa: ok (5 formatados).
- Web: `vue-tsc` ok; `npm run build` ok; `tests/frontend-invitations.mjs`
  **50 verificações** (guard de sessão, token só no corpo, Idempotency-Key,
  mapa de erros, troca de organização com ordem PUT→contexto→lista e falha
  preservando contexto, anti-duplo-envio e resposta atrasada descartada,
  guards de rota). Regressões: security 54, hardening 115, approvals 85,
  realtime 35, daily-operation 55 + 18, assignment 12 + 36, qr-entry 37,
  session-history 22 + 144, availability 19 + 18 — todas ok, sem erros de
  navegador.

### Integração real isolada (agent-browser) — não é a rodada manual

API própria `127.0.0.1:55462` (DB `onboarding_validation`, provisionado com
`aforo:provision`, `mail=log` no storage isolado, fila `sync`) + Vite 5175:

admin → redirecionado a `/platform/onboarding` → validação vazia → cadastro
com duplo clique (1 org/1 restaurante/1 convite no banco) → resumo
("Entregada al servidor de correo", não "recebido") → reenviar (contador
"Reenviar en 58 s"; token antigo 404) → **dono em outro navegador**: prévia
(fragmento removido do URL), senhas divergentes recusadas, aceite → dashboard
do restaurante, sem dia aberto → Equipo → convite da Marta (conta existente
de outra org) → sessão da Lola abrindo o link da Marta mostra "otra cuenta" →
Marta em outro navegador: senha errada recusada, correta aceita, nome
original preservado, menu de camarera → troca para a org própria (menu de
dona, restaurante trocado, roster sem dados da Casa Lola) → volta (camarera)
→ convite de cozinha em ca-ES-valencia/escuro/375 → aceite cai em Cocina;
`/app/staff` e `/platform` bloqueados → plataforma en-GB/escuro: restaurante
adicional em org existente sem convite → org com e-mail digitado errado →
cancelar (confirmação) → "Invitar de nuevo" com o e-mail certo (mesma org).

Problemas encontrados e corrigidos na integração: (1) prévia dava 419 — falta
do cookie CSRF antes do POST; (2) o guard de sessão encerrada cancelava as
chamadas de convite ("Session ended") — endpoints de convite liberados;
(3) erros de campo não sumiam ao corrigir; (4) item de convite espremido na
coluna lateral — container query; (5) nota de entrega aparecia sem convite.

Viewports/temas vistos: 1440 claro/escuro, 768 claro, 375 claro/escuro;
es-ES, ca-ES-valencia, en-GB; sem rolagem horizontal; sem erros de console.
Servidores isolados encerrados por PID ao final (sem `pkill`).

## 13. Rastreabilidade (requisito → implementação → teste → pendência)

| Req | Implementação | Teste | Status |
|---|---|---|---|
| P-01 | `OnboardRestaurantAction`, `PlatformOnboardingController` | `PlatformOnboardingTest::test_super_admin_onboards…` | I T R |
| P-02 | idem + gate `platform.organizations.manage` | `…existing_organization…`, `…suspended…` | I T R |
| P-03 | Action não cria nada operacional | asserts de tabelas vazias | I T R |
| P-04 | `onboarding_idempotency_key` + hash | `…retry…replays`, `…different_payload_conflicts` | I T R |
| P-05 | `isPlatformAccount` + aceite | `…platform_admin_account_cannot…` (2 testes) | I T |
| P-06 | `InvitationMailer` pós-commit | `…mail_failure_keeps_onboarding…` | I T |
| P-07 | `PlatformAuditLogger` sem segredo | `…audit…without_secrets` | I T |
| I-01 | `InvitationTokens`, `InvitationLink`, notificação | `…link_uses_the_trusted_url…`, `…job_never_carries_the_token` | I T R |
| I-02 | `preview` | `…does_not_consume_it` | I T R |
| I-03/I-04 | `AcceptInvitationAction` | acceptance tests | I T R |
| I-05 | mismatch | `…different_signed_in_user…` | I T R |
| I-06 | `assertUsable` | expired/revoked/single-use | I T |
| I-07 | lock de linha | `InvitationConcurrencyTest` | I T |
| I-08 | `ResendInvitationAction` | lifecycle tests | I T R |
| I-09 | listener + `failed()` | `…delivery_states…distinct` | I T R |
| I-10 | checks no aceite | suspended/org/platform/membership | I T |
| S-01..S-05 | `StaffInvitationController` | `StaffInvitationTest` (16) | I T R |
| C-01..C-03 | `ActiveOrganization`, store | `ActiveOrganizationTest`, front §5 | I T R |
| W-01..W-05 | views/composables/i18n | `frontend-invitations.mjs` + agent-browser | I T R |

## 14. Checklist manual (rodada final)

1. Ambiente habitual: §9 (migrations com `--path`, `aforo:provision`,
   `AFORO_WEB_URL`, worker, mailer de captura). Confirmar que existe um
   usuário com `super_admin` real.
2. Super Admin: login → cai em Plataforma; cadastrar org nova; duplo clique;
   ver resumo e estado de entrega; reenviar (cooldown); cancelar.
3. Abrir o e-mail capturado (log/caixa local) num navegador sem sessão:
   conferir que o URL perde o `#token`; conta nova → senha própria → dashboard.
4. Repetir o link usado → "ya se ha usado"; esperar expiração (ou ajustar
   `AFORO_INVITATION_EXPIRES_HOURS` num ambiente isolado) → "caducada" → reenviar.
5. Dono: Equipo → convidar camarero/cocina/caja/encargado; conta existente de
   outra org; aceitar com senha atual; trocar de organização no menu.
6. Gerente restrito a um restaurante tentando convidar para outro → bloqueado.
7. Suspender org/usuário pela plataforma durante convite pendente → aceite recusado.
8. Restaurante adicional em org existente → dono vê no seletor.
9. Claro/escuro, 3 idiomas, 375/768/1440.
10. Itens pendentes das rodadas anteriores (abaixo).

## 15. Pendências

Novas:
- Migrations/provisioning/config do habitual (§9) — **não executados**.
- `POST /staff` com senha definida pelo dono continua ativo (decisão de produto).
- OpenAPI gerado (`storage/api-docs/api-docs.json`) não regenerado; atributos
  `OA` dos endpoints novos estão no código.
- Staging: `MAIL_MAILER=log` no exemplo (§9).
- Recuperação de senha genérica não existe (fora de escopo); conta existente
  que esqueceu a senha não consegue aceitar sem ela.
- Ocorrência do log (§10) e config cache isolado a regerar.
- E-mail real e entrega em caixa: não testados (proibido nesta tarefa).

Anteriores preservadas (de `docs/QR-daily-operation-report.md`):
- 24 erros de acesso a `storage/framework/testing/disks/public/products`
  (`root:root 700`) no storage padrão — contornados com storage isolado.
- Testes manuais de realtime e atribuição.
- `storage/app/inspect_user.php` e `storage/app/seed_dev_scenario.php`:
  formatação incidental anterior; não tocados.
- Processos isolados do Codex: Postgres 55443 continua ativo (usado aqui);
  API 55442 e Vite 5174 não estavam mais escutando nesta sessão.
- Runtime de teste com GD/pdo_sqlite/BCMath é temporário (`/tmp/…`).
- Migration diária e provisioning no banco habitual pendentes.
- Integração real das correções A/B/C do ciclo diário — manual pendente.

## 16. Git

### restaurants-api (`git status --short`)
```
 M app/Actions/Staff/CreateStaffAction.php
 M app/Http/Controllers/Api/V1/Auth/AuthController.php
 M app/Http/Middleware/ResolveTenant.php
 M app/Models/AuditLog.php
 M app/Providers/AppServiceProvider.php
 M app/Providers/PlatformAuthServiceProvider.php
 M app/Support/Auth/AuthContextBuilder.php
 M bootstrap/app.php
 M database/seeders/PlatformPermissionSeeder.php
 M database/seeders/PlatformRolePermissionSeeder.php
 M package.json            (pré-existente)
 M routes/api.php
?? .env.staging.example     (pré-existente)
?? package-lock.json        (pré-existente)
?? .oh-my-sdd/specs/owner-staff-invitations/
?? app/Actions/Invitations/  app/Actions/Platform/  app/Exceptions/Invitations/
?? app/Http/Controllers/Api/V1/{InvitationController,StaffInvitationController}.php
?? app/Http/Controllers/Api/V1/Platform/{PlatformInvitationController,PlatformOnboardingController}.php
?? app/Http/Requests/Api/V1/{Auth/SelectActiveOrganizationRequest.php,Invitations/,Platform/*Invitation*,Platform/OnboardPlatformRestaurantRequest.php,Staff/StoreStaffInvitationRequest.php}
?? app/Http/Resources/Api/V1/InvitationResource.php  app/Models/Invitation.php
?? app/Notifications/  app/Support/Invitations/  app/Support/Tenancy/ActiveOrganization.php
?? config/invitations.php  database/migrations/2026_10_23_00000{1,2}_*.php
?? tests/Concerns/InteractsWithInvitations.php  tests/Feature/Invitations/
?? tests/Feature/Tenancy/ActiveOrganizationTest.php  tests/Support/invitation-accept-worker.php
?? docs/onboarding-invitations-report.md
```
`git diff --stat` (rastreados, antes deste relatório e do adendo): 12 files
changed, 220 insertions(+), 33 deletions(-) — inclui as 3 linhas
pré-existentes de `package.json`.

### restaurants-web (`git status --short`)
```
 M .env.example             (pré-existente)
 M src/api/session.ts
 M src/components/shared/UserMenu.vue
 M src/components/staff/StaffForm.vue
 M src/i18n/locales/{ca-ES-valencia,en-GB,es-ES}.ts
 M src/router/index.ts
 M src/services/auth.service.ts
 M src/stores/auth.ts
 M src/types/auth-context.ts
 M src/utils/error-message.ts
 M src/views/staff/StaffView.vue
?? (pré-existentes) .claude/skills/ui-ux-pro-max/scripts/__pycache__/, 5 × *:Zone.Identifier, t.txt
?? src/components/invitations/  src/composables/useStaffInvitations.ts
?? src/layouts/PlatformLayout.vue  src/services/{invitations,platform}.service.ts
?? src/types/invitation.ts  src/views/invitations/  src/views/platform/
?? tests/frontend-invitations.mjs  docs/onboarding-invitations.md
```
`git diff --stat`: 13 files changed, 769 insertions(+), 91 deletions(-) —
inclui as 58 linhas pré-existentes de `.env.example`.

## 17. Ponto de retomada

Tarefa concluída neste bloco. Para retomar: `source` do
`testenv.sh` no scratchpad da sessão (ou recriar: config cache isolado com DB
`onboarding_testing@55443`, storage isolado com `api-docs/api-docs.json`,
logging apontando para o storage isolado) e rodar
`vendor/bin/phpunit tests/Feature/Invitations tests/Feature/Tenancy/ActiveOrganizationTest.php`;
no web, `node tests/frontend-invitations.mjs`. Próximo passo natural é a
etapa aprovada do §9 e a rodada manual do §14.
