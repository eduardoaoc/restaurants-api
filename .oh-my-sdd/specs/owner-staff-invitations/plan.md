# Plan: owner-staff-invitations

Constitution respeitada: Actions em `app/Actions/**`, Policy/permission slug,
erros `{"error":{code,message}}`, idempotência por `Idempotency-Key` + hash,
auditoria sem segredos, testes PHPUnit em banco isolado.

## Dados (indispensável)
- `invitations` (nova): organização, tipo `owner|staff`, e-mail normalizado,
  nome sugerido, `role_id`, `restaurant_assignments` jsonb (staff), `locale`,
  `token_hash` (sha256, único), `status pending|accepted|revoked`,
  `expires_at`, `delivery_status queued|sent|failed`, `delivery_sequence`,
  `send_count`, carimbos de envio/falha/aceite/revogação, quem convidou/aceitou.
  Índice único parcial `(organization_id, email) WHERE status='pending'`.
- `restaurants.onboarding_idempotency_key` (único, nulo) +
  `onboarding_payload_hash` — dedupe de retry do cadastro de plataforma.
- Permissão de plataforma `create_platform_restaurants` (super_admin) via
  `PlatformPermissionSeeder`/`aforo:provision` (sem migration).

## Backend
- `App\Support\Invitations\InvitationTokens` (mint/hash/lookup) e
  `InvitationLink` (URL confiável `AFORO_WEB_URL` + `/invitation#token=`).
- `App\Notifications\InvitationNotification` (queued, mail, `shouldSend`
  descarta envio obsoleto; token gerado no `toMail`, nunca no payload do job;
  `failed()` → `delivery_status=failed`). Listener de `NotificationSent` →
  `sent`.
- Actions: `OnboardRestaurantAction`, `CreateInvitationAction`,
  `DispatchInvitationAction`, `ResendInvitationAction`,
  `RevokeInvitationAction`, `AcceptInvitationAction`. Vínculo de staff reusa
  `CreateStaffAction::attach()` (extraído do `execute()` sem mudar contrato).
- `InvitationException` renderizada em `bootstrap/app.php`.
- Rotas: `POST /platform/restaurants`, `GET /platform/invitations`,
  `POST /platform/organizations/{organization}/owner-invitations`,
  `POST /platform/invitations/{invitation}/{resend|revoke}`;
  `GET|POST /staff/invitations`, `POST /staff/invitations/{invitation}/{resend|revoke}`;
  `POST /invitations/{preview|accept|accept-existing}`;
  `PUT /auth/active-organization`.
- `ResolveTenant` + `AuthContextBuilder` leem a organização preferida da
  sessão via `ActiveOrganization` (fallback = comportamento atual).
- Limiters: `invitation-public`, `invitation-accept`, `platform-onboarding`.

## Frontend
- `services/invitations.service.ts`, `services/platform.service.ts`, tipos.
- `views/platform/PlatformOnboardingView.vue` + `layouts/PlatformLayout.vue`,
  rota `/platform` (guard `canPlatform('create_platform_restaurants')`).
- `views/invitations/InvitationAcceptView.vue` (rota `/invitation`, fora do
  guard de convidado).
- Equipe: `StaffForm` em modo convite (sem senha), `StaffInvitationsPanel`.
- `OrganizationSwitcher` no `UserMenu` (só com >1 organização).

## Riscos
- `MAIL_MAILER=log` grava o corpo do e-mail (com link) no log: aceitável só
  como caixa local; staging precisa de mailer real/captura antes de ativar.
- Fila `database` sem worker: convite fica `queued` (honesto).
