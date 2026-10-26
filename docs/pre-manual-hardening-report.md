# Fechamento técnico antes da rodada manual integrada

Data: 2026-10-22. API `fix/security-hardening` (HEAD `c195492`), Web `main`
(HEAD `9ee3d46`). **Sem commit, push ou deploy. Staging/produção não
acessados.** Spec SDD: `.oh-my-sdd/specs/pre-manual-hardening/` (estende
`owner-staff-invitations`; checkpoints interativos das skills oh-my-sdd não
executados).

## 1. Estado de partida (reconstruído por código, banco e relatórios)

- Git: mudanças de onboarding da tarefa anterior ainda sem commit (API 42 /
  Web 30 linhas de status, idênticas ao fim daquela tarefa) + pendências
  pré-existentes (`package.json`, `.env.staging.example`, `package-lock.json`
  na API; `.env.example`, `t.txt`, `__pycache__`, `*:Zone.Identifier` no Web).
  Tudo preservado.
- Container local: `artisan serve` (supervisor), **worker já existente**
  `queue:work database --queue=realtime-local` (root) e `reverb:start` (root);
  `.env` já tinha `BROADCAST_QUEUE=realtime-local`.
- Filas habituais: `default` com **284 jobs antigos** de broadcast
  (2026-10-02 → 10-07), `realtime-local` vazia, 1 `failed_jobs` antigo; nenhum
  job de WhatsApp pendente.
- Banco habitual: 3 migrations pendentes (diária + 2 de onboarding),
  `manage_product_availability`/`open_daily_operation`/`create_platform_restaurants`
  não provisionadas, `AFORO_WEB_URL` ausente, `MAIL_MAILER=log` gravando no
  `laravel.log`.
- Frontend habitual: configurado para `http://localhost:5174`
  (`VITE_PUBLIC_APP_URL`, `SANCTUM_STATEFUL_DOMAINS`); **não estava rodando**.
- Sessão legada #490: `occupied/unpaid`, 5 pedidos, 0 pagamentos.

## 2. Correções desta tarefa

### 2.1 Convites — token em retry (bug real, corrigido)

O desenho anterior gerava o token em `toMail()`. Consequências confirmadas
por leitura do código: cada retry do job (ou execução duplicada) gerava um
link novo e **sobrescrevia o hash**, invalidando um e-mail que já tinha
saído. Agora:

- token emitido **uma vez por envio** (na criação e em cada reenvio), dentro
  da transação; banco guarda só o SHA-256;
- o job leva o token e é `ShouldBeEncrypted` → `jobs`/`failed_jobs` só têm
  ciphertext (APP_KEY); retries/duplicação mandam o **mesmo** link e não tocam
  o hash; reenvio invalida o anterior; job obsoleto continua descartado por
  `shouldSend` (sequência);
- parâmetros com segredo marcados `#[\SensitiveParameter]`;
  `zend.exception_ignore_args=On` conferido no PHP do WSL e do container;
- e-mails roteados para `config('invitations.mail_queue')` (`AFORO_MAIL_QUEUE`).

Aceite concorrente com revogação já era serializado pelo lock da linha (teste
de processos independentes mantido). Prévia continua POST sem consumo; página
continua com `referrer: no-referrer`.

### 2.2 Recuperação de senha (nova)

Broker nativo do Laravel (`passwords.users`: `password_reset_tokens`, token
com hash bcrypt, expira em 60 min, throttle de 60 s por conta, apagado no uso):

| Endpoint | Comportamento |
|---|---|
| `POST /auth/forgot-password` | mesmo 200 para conta existente, inexistente, suspensa, throttled ou falha de fila; e-mail só para conta ativa; limiter 5/min por IP e 5/h por e-mail (hash) |
| `POST /auth/reset-password/preview` | valida sem consumir; inválido/expirado/usado/conta errada → mesmo `422 PASSWORD_RESET_INVALID`; limiter 10/min IP e e-mail |
| `POST /auth/reset-password` | confirmação explícita, senha min 8 + confirmada (mesma política do projeto); troca senha, gira `remember_token`, apaga as sessões **daquela** conta (mesmo mecanismo da suspensão de plataforma; o `AuthenticateSession` do Sanctum derruba as demais por hash), broker apaga todos os tokens da conta; auditoria `auth.password_reset` sem segredo; **não autentica**, não dá acesso a organização, não aceita convite, não levanta suspensão |

Link: `AFORO_WEB_URL/reset-password#token=…&email=…` (fragmento). Frontend:
`src/utils/url-secret.ts` é o **primeiro import** do `main.ts` e remove
`#token` de `/invitation` e `/reset-password` antes de qualquer outro módulo;
segredo só em memória. Telas `ForgotPasswordView` (mensagem genérica) e
`ResetPasswordView` (verificando/formulário/inválido-expirado-usado/sucesso
com "Volver a iniciar sesión" e aviso de que o convite ainda precisa ser
aceito); link "¿Olvidaste tu contraseña?" no login e no aceite com conta
existente; textos em es-ES, ca-ES-valencia, en-GB.

### 2.3 E-mails e logs

- Canal `mail_capture` (`config/logging.php`): `storage/logs/mail-capture.log`,
  permissão 0600, ignorado pelo Git — caixa local de captura (não há Mailpit no
  compose). `laravel.log` nunca recebe o corpo dos e-mails.
- Ocorrência anterior (§10 do relatório de onboarding) **não** foi apagada
  nem editada: o `laravel.log` habitual continua com as 4 ocorrências antigas
  (link já invalidado); baseline para a rodada: 4.
- Durante a comprovação desta tarefa, a primeira tentativa do e-mail de teste
  (token fictício) falhou por um defeito de robustez da notificação de reset
  com destinatário anônimo e registrou **um erro** no `laravel.log`
  habitual (mensagem/trace, sem argumentos, sem token). Corrigido
  (`emailOf()`), worker reiniciado, retry processado com sucesso.

### 2.4 Testes isolados reproduzíveis

- `scripts/test-sail.sh`: suíte no container Sail (PHP 8.5 com gd, bcmath,
  intl, pdo_pgsql, pdo_sqlite), banco `testing`, storage em
  `/tmp/aforo-testing-storage` do container recriado por execução, recusa
  config em cache. Documentado em `docs/testing-isolated.md`.
- `tests/TestCase.php`: aborta se o banco não for `testing`/`*_testing` ou
  `APP_ENV≠testing`.
- `DayCloseNoForceTest` lê o OpenAPI versionado via `base_path()` (fim da
  dependência de cópia manual em `/tmp`).
- `ProvisionTest`: subprocesso com `LOG_CHANNEL=null`.

### 2.5 Workers

Ver `docs/local-workers.md`. Worker **próprio** iniciado:
`queue:work database --queue=mail-local --name=aforo-mail-local --tries=3
--backoff=30 --timeout=30 --sleep=3` como `sail` (uma instância). Worker
`realtime-local` e Reverb existentes **não** foram reiniciados. Nenhum worker
na `default`.

### 2.6 OpenAPI

Gerador existente (`l5-swagger:generate`, no container) sobre o arquivo
**versionado** `storage/api-docs/api-docs.json` (estava de 2026-10-09).
Acrescentados: esquemas `DomainError`, `Invitation`, `InvitationPreview`,
`StaffInvitationRequest`, `OnboardingResult`, `active_organization_id` em
`AuthContext`; corpos/respostas/erros completos de convites (staff,
plataforma, prévia, aceite), onboarding (201/200 replay com
`Idempotency-Key`), recuperação de senha. QR/visita, aprovação/consultas,
disponibilidade e dia operacional já estavam anotados pelas tarefas
anteriores (conferido: `Idempotency-Key` em pedidos públicos e abertura do
dia, `opening_float` como string decimal). Validação: 165 operações = 165
rotas, 0 referências quebradas, 0 `operationId` duplicado, todas as rotas
autenticadas com `security`, sem segredos/exemplos reais.

## 3. Testes e resultados (código final, salvo a nota abaixo)

| Execução | Resultado |
|---|---|
| Linha de base Sail (Provisioning + DayCloseNoForce) | 12/12 |
| Convites + organização ativa (após correção do token) | 71 → 1 erro de teste (decrypt) corrigido → lifecycle 10/10 |
| `PasswordResetTest` (novo, 13) | 13/13 |
| **Suíte backend completa, sequencial, Sail** | **2157/2157, 9109 assertions, 0 erros ambientais** (9 min 47 s) |
| Após a correção `emailOf()` (posterior à suíte completa): Auth + Invitations + Tenancy | 170/170, 880 assertions |
| Pint `--test` nos arquivos PHP tocados | ok |
| Web `vue-tsc` / `npm run build` | ok / ok |
| `frontend-password-reset.mjs` (novo) | 27 verificações |
| `frontend-invitations.mjs` | 50 |
| security / hardening | 54 / 115 |
| approvals / realtime / daily-operation / assignment / qr-entry / session-history / availability | 85 / 35 / 55+18 / 12+36 / 37 / 22+144 / 19+18 — sem erros de navegador |

A correção `emailOf()` (uma linha de fallback na notificação de reset) veio
**depois** da suíte completa; só as suítes afetadas foram repetidas (linha
acima). `laravel.log` habitual: mesmo tamanho antes e depois da suíte completa.

Cobertura explícita: recuperação sem enumeração; rate limit por IP e por
e-mail; token inválido/de outra conta/expirado/usado indistinguíveis; prévia
sem consumo; política de senha; revogação de sessões só da conta; não
autentica; suspensão preservada; conta com convite pendente continua
precisando aceitar e usuário errado não aceita; link pelo config e não pelo
`Host`; job de reset e de convite criptografados na fila de e-mail; retry e
execução duplicada mantêm o link válido; reenvio invalida o anterior; logs e
auditoria sem token/senha; caixa de captura 0600.

### Validação real isolada (agent-browser)

Ambiente isolado com config em cache **gerado do zero** por variáveis de
ambiente (banco `onboarding_validation`, logs/caixa no storage isolado, APP_KEY
aleatória): login → "¿Olvidaste tu contraseña?" → existente e inexistente com
**mensagem idêntica** e um único e-mail na caixa isolada → link num navegador
limpo (fragmento removido, `history.length` 2) → senhas divergentes →
sucesso → reuso do link "no es válido…" → senha antiga 401, nova 200 (fluxo
SPA) → dona convida pela UI → hash do link do e-mail = hash armazenado →
aceite. 375 escuro, 768 ca-ES-valencia, 1440 en-GB; sem rolagem horizontal,
sem erros de console. Servidores encerrados por PID.

Contra o ambiente habitual (sem criar dados): frontend em 5174 iniciado só
para smoke test → `/invitation#token=<falso>` remove o fragmento e mostra
"no es válido" (POST 404 tratado), `/forgot-password` renderiza; parado depois.

## 4. Backup local

- Arquivo: `/home/dudu/aforo-backups/laravel-local-pre-activation-20261022-112551.dump`
  (diretório 700, arquivo 600, fora do Git e do container).
- `pg_dump -Fc --no-owner` do banco `laravel`; 337 231 bytes; SHA-256
  `0b5ed7917727ac20a1591711b79b67a357daea310fd0e2428b6b970586e03148`.
- `pg_restore --list`: ok, formato CUSTOM, 57 entradas `TABLE DATA`.
  **Restauração não testada.**
- `.env` anterior: `/home/dudu/aforo-backups/restaurants-api.env.pre-activation-20261022-112624` (600).

## 5. Destino confirmado como local

Projeto compose `restaurants-api` (working dir deste repositório), rede
docker `restaurants-api_sail`; `pgsql` → `172.19.0.3` (`postgres:18-alpine`),
banco `laravel`, `APP_ENV=local`, sem config em cache.

## 6. Migrations aplicadas e provisioning

Aplicadas uma a uma por `--path` (SQL revisado com `--pretend`, todas
aditivas, sem backfill):

1. `2026_10_22_000001_create_restaurant_daily_operations_table` (batch 13)
2. `2026_10_23_000001_create_invitations_table` (batch 14)
3. `2026_10_23_000002_add_onboarding_idempotency_to_restaurants_table` (batch 15)

Nenhuma migration nova nesta tarefa (recuperação usa `password_reset_tokens`
existente). Depois: 0 pendentes; tabelas novas vazias; 258 sessões sem
`daily_operation_id`; 2 restaurantes sem chave de onboarding.

`aforo:provision` (diff conferido antes, aditivo):

| | antes | depois |
|---|---|---|
| permissions | 25 | 27 (+`manage_product_availability`, +`open_daily_operation`) |
| role_permissions | 63 | 68 (owner e manager +2; waiter +`manage_product_availability`) |
| platform_permissions | 7 | 8 (+`create_platform_restaurants`) |
| platform_role_permissions | 7 | 8 (super_admin) |
| roles / platform_roles / user_roles / platform_role_assignments / usuários ativos | 5 / 1 / 10 / 1 / 12 | iguais |

Igual ao plano anterior; nenhum grant revogado, nenhum usuário reativado.

## 7. Configuração local alterada

`.env` da API (valores não secretos), só estas linhas acrescentadas:

```
AFORO_WEB_URL=http://localhost:5174
AFORO_MAIL_QUEUE=mail-local
MAIL_LOG_CHANNEL=mail_capture
```

`MAIL_MAILER` continua `log`; nenhum SMTP configurado; `APP_KEY` não
alterada. `artisan serve` recarregou sozinho (workers PHP com PIDs novos);
config efetiva conferida. Processos ao final: `artisan serve`, Reverb `:8081`
(responde), worker `realtime-local` (existente), worker `mail-local` (PID
932, `sail`). Frontend **parado** — iniciar com
`npm run dev -- --port 5174` em `restaurants-web`.

Comprovações no ambiente habitual (sem dados novos de negócio):

- broadcast novo (`DailyOperationChanged` num canal sem assinantes,
  restaurante inexistente) → enfileirado em `realtime-local` e processado
  (fila voltou a 0, `failed_jobs` inalterado);
- e-mail de teste (token fictício, destinatário `.invalid`) → `mail-local` →
  apenas `mail-capture.log`, com base `localhost:5174`; `laravel.log` não
  cresceu no envio bem-sucedido;
- backlog `default` = 284 antes e depois.

## 8. Sessão legada

#490 (AFORO Malvarrosa, Mesa 11): `occupied/unpaid`, 5 pedidos, 0 pagamentos,
sem `daily_operation_id` — **não alterada**. Decisão operacional sua.
Nenhum dia operacional aberto; nenhum fundo escolhido; nenhum
restaurante/usuário de teste criado no banco habitual.

## 9. Checklist manual único

`docs/manual-checklist-local.md` — 20 cenários (preparação, plataforma,
convites, recuperação, funcionários, permissões/troca de organização,
dia/fundo, disponibilidade, QR, atribuição, aprovação por clique normal,
cozinha sem refresh, histórico/conta, queda/fallback, pagamento/encerramento
em dados de teste, fechamento/métricas, nova operação, negativos,
mobile/idiomas), todos **PENDENTE**.

## 10. Pendências

Staging/produção (não tocados):
- SMTP real ou captura de staging; `.env.staging.example` ainda com
  `MAIL_MAILER=log` (gravaria links no log).
- `AFORO_WEB_URL` HTTPS, `AFORO_MAIL_QUEUE` com worker supervisionado, workers
  como usuário não-root, `zend.exception_ignore_args=On`.

Locais:
- Restauração do backup não testada.
- Worker `realtime-local` e Reverb rodam como `root` fora do supervisor
  (provável origem da pasta `storage/.../products` `root:root 700`);
  recomendação: reiniciá-los como `sail` quando conveniente.
- Backlog de 284 broadcasts antigos na `default` e 1 `failed_jobs` antigo:
  preservados, decisão sua.
- `POST /staff` com senha definida pelo dono continua ativo (decisão de produto).
- Erro único da sonda no `laravel.log` (§2.3) e 4 ocorrências antigas
  preservados.

Anteriores mantidas: rodada manual de realtime/atribuição; runtime efêmero em
`/tmp` do WSL (substituído por `scripts/test-sail.sh`, mas o Postgres 55443
temporário segue ativo); sessão #490.

## 11. Prontidão

**Pronto para a rodada manual local**, com uma ação sua antes de começar:
iniciar o frontend (`npm run dev -- --port 5174` em `restaurants-web`) e
confirmar as credenciais reais das contas por papel. **Não está pronto para
produção** (ver §10).

## 12. Git

`git status --short` e `git diff --stat` (rastreados) ao final:

- API: modificados `app/Actions/Staff/CreateStaffAction.php`,
  `app/Http/Controllers/Api/V1/Auth/AuthController.php`,
  `app/Http/Middleware/ResolveTenant.php`, `app/Models/AuditLog.php`,
  `app/OpenApi/ApiDocumentation.php`, `app/Providers/AppServiceProvider.php`,
  `app/Providers/PlatformAuthServiceProvider.php`,
  `app/Support/Auth/AuthContextBuilder.php`, `bootstrap/app.php`,
  `config/logging.php`, `database/seeders/PlatformPermissionSeeder.php`,
  `database/seeders/PlatformRolePermissionSeeder.php`,
  `docs/QR-daily-operation-report.md`, `package.json` (pré-existente),
  `routes/api.php`, `storage/api-docs/api-docs.json` (gerado),
  `tests/Feature/DayClose/DayCloseNoForceTest.php`,
  `tests/Feature/Provisioning/ProvisionTest.php`, `tests/TestCase.php` —
  **19 files changed, 5503 insertions(+), 3145 deletions(-)** (a maior parte é
  o OpenAPI gerado). Não rastreados: os da tarefa de onboarding +
  `PasswordResetController`, `Forgot/ResetPasswordRequest`,
  `app/Notifications/ResetPasswordNotification.php`, `scripts/test-sail.sh`,
  `docs/{local-workers,testing-isolated,manual-checklist-local,pre-manual-hardening-report}.md`,
  `tests/Feature/Auth/PasswordResetTest.php`, spec `pre-manual-hardening`;
  pré-existentes `.env.staging.example`, `package-lock.json`. `.env` é
  ignorado pelo Git.
- Web: modificados `.env.example` (pré-existente), `src/api/session.ts`,
  `src/components/shared/UserMenu.vue`, `src/components/staff/StaffForm.vue`,
  `src/i18n/locales/*` (3), `src/main.ts`, `src/router/index.ts`,
  `src/services/auth.service.ts`, `src/stores/auth.ts`,
  `src/types/auth-context.ts`, `src/utils/error-message.ts`,
  `src/views/LoginView.vue`, `src/views/staff/StaffView.vue` —
  **15 files changed, 1181 insertions(+), 373 deletions(-)**. Não rastreados:
  os da tarefa de onboarding + `src/utils/url-secret.ts`, `src/views/auth/`,
  `tests/frontend-password-reset.mjs`; pré-existentes `t.txt`,
  `__pycache__`, `*:Zone.Identifier` (5).

---

## 13. Continuação — 2026-10-23 (preparação do roteiro manual do zero)

Sem commit, push ou deploy. Staging/produção não acessados. Nenhum dado de
negócio criado; nenhum dia aberto; nenhum fundo escolhido; #490 intocada;
nenhuma migration, provisioning ou seeder; nenhum e-mail enviado.

### 13.1 Ponto de parada recuperado

- API: nenhum arquivo alterado depois deste relatório (2026-10-22 11:30);
  status idêntico ao §12.
- Web: a execução interrompida (2026-10-22 20:12–20:17) alterou, **sem
  validação nem documentação**:
  - `src/composables/useRestaurantRealtime.ts` + `tests/frontend-realtime.browser.ts`
    — reconcile REST de segurança a cada 60 s com socket conectado;
  - `src/services/staff.service.ts`, `src/composables/useOrganizationStaff.ts`,
    `src/components/staff/StaffDetailPanel.vue`, `src/views/staff/StaffView.vue`,
    `src/i18n/locales/{es-ES,en-GB,ca-ES-valencia}.ts` — iniciar/terminar
    turno pela «Equipo». (StaffView e locales já tinham diff do onboarding;
    só as partes de turno são dessa execução.)
- Checklist e relatórios não tinham sido tocados; nenhum resultado manual
  estava registrado (20 cenários PENDENTE).

### 13.2 O que já estava feito (verificado no código, não só em relatório)

- **Atribuição automática** (`EnsureSessionWaiterAction`): garçom que abre a
  mesa fica responsável (`preferActor`, independe de turno); QR escolhe entre
  garçons **com turno ativo** no restaurante, por menor demanda → nunca
  atribuído → atribuição mais antiga → id; responsável válido é preservado
  (mesmo fora de turno); sem elegível → `table_session.assignment_pending`
  durável (sino de quem tem `assign_waiters`, inclusive quem entra depois);
  reatribuição manual e isolamento por restaurante/organização cobertos por
  `tests/Feature/TableSession/AutomaticWaiterAssignmentTest.php` (15 testes).
  Nenhuma divergência do requisito → backend não alterado.
- **Dependência real encontrada pela execução anterior**: antes dela não
  havia interface para iniciar turno (só leitura de turnos ativos), então a
  atribuição por QR e a lista de reatribuição ficavam sempre vazias num
  restaurante novo. A correção usa os endpoints existentes
  (`POST /restaurants/{id}/staff-shifts`, `POST /staff-shifts/{id}/end`;
  409 já ativo / 422 inelegível) e aparece só com `manage_staff_shifts` para
  pessoa ativa vinculada ao restaurante atual.
- **Realtime**: com o worker parado o socket segue conectado (correto, sem
  indicador falso); o reconcile de 60 s limita o atraso; evento recebido
  rearma o timer (sem leituras extras); perda de conexão troca para o
  fallback de 15 s. Lógica revisada linha a linha — correta.

### 13.3 Concluído agora

- `StaffDetailPanel.vue`: `shiftErrorText` movido para depois de
  `useI18n()` (antes usava `t` acima da declaração; funcionava por ser
  `computed` preguiçoso, mas ficava frágil).
- **Próxima data operacional** (`OpenDailyOperationAction` +
  `DayClosePeriodResolver::businessDateOf`): data local do restaurante,
  recuada um dia antes do corte (padrão **06:00**, sem campo na UI); abre
  só se a data for posterior ao último cierre e ainda sem operação. Abrindo
  hoje após 06:00 e fechando, a próxima abertura é a partir de
  **2026-10-24 06:00 Europe/Madrid**. Cenário 8.5 fica parcialmente
  pendente por data; nada simulado.
- Restaurante novo nasce com «Los pedidos de clientes requieren aprobación»
  **desligado** — etapa explícita no roteiro (3.2).
- `docs/manual-checklist-local.md` reescrito em 8 fases por dependência
  (preparação → cadastro → configuração → equipe/turnos → abertura →
  operação → resiliência/segurança → encerramento), com papel, pré,
  caminho real (rótulos es-ES conferidos nos locales e no router), passos,
  esperado, evidência e estado; todos os cenários anteriores preservados;
  "Como começar" no fim.
- `docs/local-workers.md`: comportamento esperado com worker parado.
- Spec existente `pre-manual-hardening/tasks.md`: itens 10–16 (sem spec nova).

### 13.4 Verificações

| Verificação | Resultado |
|---|---|
| `npm run build` (inclui `vue-tsc -b`) no código final | ok |
| `frontend-realtime.mjs`, `frontend-assignment.mjs` | **não executados**: Chrome do agent-browser no WSL não inicia (`libnspr4.so`, `libnss3.so`, `libnssutil3.so`, `libsmime3.so`, `libasound.so.2` ausentes) |
| Chrome headless do Windows via CDP | negado pela política de permissões (expõe porta local); não contornado |
| `frontend-invitations.mjs`, `frontend-hardening*.mjs` (sobem Vite) | negados pela mesma política; não executados |
| Backend | não alterado nesta continuação → suíte anterior (2157/2157 + 170/170) segue valendo; Docker indisponível no WSL hoje |
| Validação visual agent-browser dos botões de turno | **pendente** (mesmo bloqueio) — coberta na rodada manual (4.6 + transversal) |

### 13.5 Bloqueios

1. **Docker**: `docker` inexistente no WSL e nenhuma porta 8080/8081/5174
   escutando em 2026-10-23 08:56 → abrir o Docker Desktop (integração WSL).
   Reverb e workers foram iniciados à mão antes; precisam ser reiniciados
   (roteiro "Como começar", passos 2–5).
2. **Testes de navegador do frontend**: instalar as bibliotecas do Chrome no
   WSL (`sudo apt install libnss3 libnspr4 libasound2t64`) ou rodar a partir
   do Windows; depois `node tests/frontend-realtime.mjs` e
   `node tests/frontend-assignment.mjs` em `restaurants-web`.
3. Garçom não tem botão para iniciar o próprio turno (backend permite);
   Dono/Manager iniciam pela «Equipo». Registrado como limite, não corrigido.
4. Suspensão de organização pela plataforma não tem interface (só existe
   «Alta de restaurante») → parte de 7.3 provavelmente `BLOQUEADO`.

### 13.6 Arquivos alterados nesta continuação

- API: `docs/manual-checklist-local.md`, `docs/local-workers.md`,
  `docs/pre-manual-hardening-report.md`,
  `.oh-my-sdd/specs/pre-manual-hardening/tasks.md` (todos não rastreados).
- Web: `src/components/staff/StaffDetailPanel.vue` (reordenação).
  Herdados da execução interrompida e mantidos: ver 13.1.
- Git ao final: API 58 linhas de status, diff rastreado inalterado
  (19 arquivos); Web 40 linhas, 20 arquivos rastreados alterados.
