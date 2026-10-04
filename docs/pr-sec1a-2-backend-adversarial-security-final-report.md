# PR-SEC1A.2 — BACKEND ADVERSARIAL SECURITY FINAL REPORT

Data: 2026-10-04 (Europe/Madrid).

**SECURITY VERDICT: B) BLOCKED — SEC-006: escape de escopo entre organizações no agregado `/api/v1/me/feedback-summary`, inclusive após revogação da membership estrangeira.**

A retomada encontrou uma nova condição de parada. Este relatório finaliza **esta execução**, não aprova as áreas ainda pendentes da auditoria original. A reprodução revela somente COUNT/AVG de feedback do próprio usuário em B; não demonstra leitura de feedback individual, PII, grants de outro usuário, SQL injection ou aquisição de privilégio de plataforma. Classificação **MEDIUM**, com parada pela regra explícita de **cross-organization escape**, que não exige severity HIGH.

## PRECHECK

- HEAD inicial e final: `a3fad32` — fix: scope tenant permissions by restaurant.
- Branch: `main`, um commit à frente de `origin/main`.
- Initial clean: YES. `git status`, `git branch -vv`, `git log --oneline --decorate -15` executados antes de alterações.
- Commit de SEC-001 presente: YES, `a3fad32`.
- SEC-001 regression: **PASS**, os testes originais em `RestaurantPermissionBoundaryTest` e as regressões de escopo/coleção permanecem verdes. Não repetimos a investigação da causa antiga.
- Composer audit --locked inicial: PASS, zero advisories.
- Sem sistemas externos de aplicação, Meta, Supabase, Hostinger, deploy, push ou commit. Composer consultou somente o feed de advisories expressamente solicitado. Testes usam o banco **testing**, RefreshDatabase/DatabaseTruncation e fakes de storage/HTTP. `.env` e secrets reais não foram exibidos.

## FINDINGS SUMMARY

| Severity | Confirmados nesta retomada | Remanescentes |
| --- | ---: | ---: |
| Critical | 0 no escopo executado | Nenhum confirmado; auditoria parcial |
| High | 0 no escopo executado | Nenhum confirmado; auditoria parcial |
| Medium | 1 — SEC-006 | 1 — SEC-006 |
| Low | 4 — SEC-002 a SEC-005 | 0 desses quatro |
| Informational | Observações de contrato/limites abaixo, sem findings numerados | Não são aprovação de áreas pendentes |

SEC-001 continua encerrado e não é contado novamente.

## SEC-006 — MEDIUM — agregado próprio ultrapassa o tenant e a revogação

**Endpoint:** GET `/api/v1/me/feedback-summary`.

**Causa:** `CustomerFeedbackSummaryController::me` resolve a organização ativa, mas passa diretamente `RestaurantScope::accessibleRestaurantIds($user, $organization)` ao resolver. Para uma role organization-wide em A, o valor é `null`, com semântica “todos os restaurantes **de A**”. `CustomerFeedbackSummaryResolver::forWaiter` interpreta `null` como ausência total de filtro por restaurante/organização, aplicando apenas `waiter_id = user.id`. Assim, COUNT/AVG incluem registros de B sem respeitar tenant ou membership atual. `StaffPerformanceController::me` já converte o mesmo `null` em IDs explícitos da organização, exemplificando o limite que falta aqui.

**Pré-condições reais:** usuário é owner organization-wide em A e foi waiter legítimo em B. Há feedback histórico associado a esse waiter. Depois B torna sua membership inativa ou remove membership, restaurant link e role. Não exige grant em B atualmente, secret, frontend adulterado ou IDs arbitrários. O estado multiorganização é admitido pelo modelo; a fixture atribui waiter e registra serviço/pagamento pelas actions reais antes da revogação.

**Reprodução local:**

```bash
./vendor/bin/sail artisan test tests/Feature/Security/FeedbackSummaryTenantBoundaryTest.php
```

Duas reproduções, seis assertions, duas falhas:

1. Membership em B **inactive**: `/restaurants/{B1}/feedback` retorna 404; `/me/feedback-summary` retorna 200 e `feedback_count = 1`, embora A tenha zero feedback. Esperado: 0 e averages null.
2. Membership em B **removed**, links e role também removidos: mesmo vazamento do registro histórico de B.

O fixture de B tem rating 1. O filtro faltante também agrega os ratings; o teste para após a primeira discrepância COUNT, mantendo a assertion posterior de average null sem enfraquecê-la. Não alegamos duas falhas independentes de COUNT e AVG por caso.

**Impacto/limite:** exposição de estatísticas históricas do próprio usuário em tenant não autorizado, além de contaminação do agregado exibido em A. Não revela customers/comments/contact/IDs ou feedback de outro waiter. Por isso MEDIUM, sem inflar para HIGH auth bypass. A carta manda parar também para qualquer cross-organization escape.

**Correção:** NÃO APLICADA. Preservadas as reproduções vermelhas. Proposta para carta posterior: transformar wildcard organization-wide em lista explícita dos restaurantes do tenant, ou tornar `organization_id` obrigatório na query do resolver; revisar todos seus callers e revogação sem ampliar grants. Não mudar `RestaurantScope` globalmente para resolver apenas esse call site.

## SEC-002 — LOW — write false destrói a substituição segura de mídia — FIXED

`StoreOrReplaceProductMediaAction` aceitava retorno `false` de `UploadedFile::storeAs`, atualizava o registro e apagava a mídia anterior. Reprodução primeiro: `MediaWriteFailureTest` falhou porque a action retornava em vez de abortar. Guarda mínima agora lança `RuntimeException('Failed to store product media.')` antes de tocar o registro. Teste confirma exception, path/row anteriores e arquivo antigo preservados. Nenhuma mudança de schema, disk ou contrato HTTP de sucesso.

## SEC-003 — LOW — normalização de entrada não escalar causa 500 — FIXED

Email como array/object causava Array to string conversion no login limiter antes da validação. Slug como array em organization/restaurant/category e email/sub_id de staff reproduziram cinco 500 adicionais. Agora normalização só atua sobre strings; tipos inválidos chegam à validação e retornam 422. Mesma guarda aplicada a store/update correspondentes. Login ganhou max 255 para email e max 4096 para password; email de 100k antes não tinha bound específico. Regressões em InputBoundaryTest/NormalizationInputTest. Não é bypass de autenticação.

## SEC-004 — LOW — payment amount sem bound causa overflow/500 — FIXED

String positiva enorme passava regex `\d+`, chegava à conversão em cents e gerava TypeError (float em retorno int). Request passa a aceitar no máximo oito dígitos inteiros, consistente com `payment_records.amount DECIMAL(10,2)` e o request de cash. O teste rejeita antes de persistir; negativos/zero/float/precisão indevida/arrays/exponentes também não gravam. Limite de balance permanece na action.

## SEC-005 — LOW — MIME JPEG reconhecido sem dimensões válidas — FIXED

Arquivo **real**, temporário e content-sniffed, contendo cabeçalho JPEG + lixo, passava `mimetypes` com 200. O teste não usa getMimeType fictício para essa reprodução. Request de image ganhou `image` e `dimensions:min_width=1,min_height=1`; agora 422 e nenhum product_media criado. Não é arbitrary file execution: a extensão já era canônica/gerada no servidor. Validação completa/decodificação de vídeo não foi adicionada nem declarada comprovada.

## SQL INJECTION

Revisão estática das **41 chamadas em 15 arquivos** de SQL raw/runtime identificadas, mais coluna dinâmica do kitchen builder. Inventário e rastreamento: [SQL runtime inventory](pr-sec1a-2-sql-runtime-inventory.md). Expressões/colunas/tabelas internas constantes; valores externos usam bindings. UNION/day-close usa colunas/stages/limits internos e placeholders. Timezone/granularity são validadas e parametrizadas. Nenhum statement/unprepared runtime ou coluna/tabela fornecida pelo cliente foi localizado.

Os quatro payloads da carta foram enviados no catálogo, persistidos como texto e comparados aos queries parametrizados. `pg_sleep` foi dado literal, não comando SQL. Não houve SQL destrutivo. Nenhuma SQL injection explorável confirmada; não alegamos payload dinâmico aplicado individualmente em cada agregação.

## XSS

Três payloads da carta cobertos em JSON de catálogo e no HTML real que alimenta o PDF. Restaurant/staff/product/category/description/modifiers/feedback/cash reason/day-close notes/annotation/recipient name foram persistidos na fixture; o menu público mantém textos como dados JSON. PDF usa `{{ }}` e os cinco raw outputs usam `nl2br(e(...))`; nenhum `<script`, `<img` ou `<svg` de entrada virou markup. Dompdf tem PHP/JavaScript/remotes desabilitados. Swagger raw outputs vêm de configuração interna, não do catálogo/input HTTP; docs são gated por configuração. Não há template/email de negócio adicional localizado. Não avaliamos o frontend consumidor de JSON.

## TENANT / IDOR

Nova matriz A1→A2 e A1→B1: nega recursos existentes de restaurant/tables/session bill/receipt/orders/kitchen ticket/catalog/category/modifiers/staff/shifts/feedback/activity/day closes/PDF/WhatsApp/settings e pagamentos/media write. Nested staff/restaurant e category/product foram exercitados pela matriz e testes existentes; os grupos restantes são executados na full suite, sem alegar uma matriz adversarial nova por cada verb.

**FAIL em SEC-006.** A consulta do agregado foi revisada após a matriz e escapou ao teste por recurso direto: o endpoint próprio retorna 200, mas mistura dados de B. Order items/payments estão acessíveis por suas projeções de sessão/order, não endpoints autônomos de detalhe. Visits são por token público, não por tenant ID. Matriz integral de todos os verbs/IDs aninhados interrompida após a confirmação.

## MASS ASSIGNMENT

Inventariados os 46 models e seus Fillable attributes e os requests/controllers de gravação: ownership em Fillable não é autorização; a fronteira é validated + relação/whitelist/action. Novas injeções abrangem organization_id/restaurant_id/user_id/role_id/permission_id/is_admin/platform_role/preço/status/created_by/session_id/currency/recorded_by/closed_by, incluindo translation.product_id e tables.*.restaurant_id/public_token/status. Dados de ownership/tokens/privilege não alteraram os registros. Status/preço são editáveis em endpoints de management quando autorizados, não universalmente proibidos. Matriz campo × todos os requests não concluída após parada.

## PRIVILEGE ESCALATION

Owner/manager/waiter/cashier/kitchen testados por chamadas diretas a settings, staff, category update, history, activity, feedback, day-close preview, catálogo organizacional e plataforma. Contracts respeitados, incluindo waiter/cashier close_daily_operation e manager restaurant-scoped sem escrita no catálogo organization-wide. SEC-001 PASS. Novas coberturas complementam permissões já existentes de pagamentos/structure/orders; expansão API de todos os endpoints de capabilities interrompida.

## AUTH / CSRF

CSRF real no harness: subclass altera **somente** runningUnitTests() para false; Sanctum/session/CSRF stack mantida. Login sem token/invalid token e authenticated POST logout sem token retornam 419. Login com token válido muda session id; logout muda id/token, limpa auth e /me posterior retorna 401. Auth::forgetGuards é usado para simular requests separados, sem desabilitar autorização. Suspended user, inactive membership, restaurant membership removal, role removal e suspended organization são observados durante sessão pelos endpoints e channels. O novo bound de password no login rejeita strings acima de 4096 caracteres; não foi inventariado se existe alguma conta histórica com senha além desse limite. Replays do cookie/session id antigo em servidor HTTP/browser separado não foram executados; a mudança de ID/invalidation foi verificada no session store de testes.

## CORS

Origins trusted/untrusted/null/*/evil.example/subdomain confusion/host com @/path/malformed testadas com credentials. Somente origin explicitamente configurada permite match browser com ACAO; nenhum wildcard refletido. Particularidade do Fruitcake instalado: com **uma** origin configurada pode emitir ACAO constante dessa origin para outra Origin solicitante; não coincide com a origin atacante, logo não lhe concede acesso CORS. Não classificamos mera presença desse header como bypass. Teste existente com múltiplas origins também permanece na suíte.

## RATE LIMITING / SPAM

Login testado com cinco tentativas e headers X-Forwarded-For/Forwarded mutáveis; sexta retorna 429, inclusive email normalizado/case. Limites reais públicos (menu 60, orders/requests/feedback 10) e webhook 600 testados com contadores pré-preenchidos + HTTP, evitando 600 chamadas. Retry-After presente; IP spoof por headers não troca buckets na configuração local sem proxies trusted explícitos. Testes existentes cobrem resend 3/user/day-close e os POST públicos com contexto válido na full suite. Token/account/user diferentes têm buckets distintos por design; não são limite global de spray/DoS. Não houve load test, ataque externo ou validação de proxy de produção.

## QR / PUBLIC TOKENS

Token table server-side random 48 caracteres, unique no banco, não derivado do ID; feedback token também random 48 e único. Invalid/random/truncated/10k/URL encoded/case mutation/sequential-like rejeitados com 404 sem data; o token público não autentica o resolver admin (401). Replay GET permitido, table inactive/restaurant inactive bloqueados. Casos closed/paid session, feedback/visit eligibility e replay após close estão na suíte existente executada. Não foi concluída nova expansão adversarial de todos os estados de tokens após SEC-006.

## ORDER ABUSE

Quantidade negativa/zero/51/PHP_INT_MAX/decimal/array/10k rejeitada; desconhecido/foreign product/disabled product/unavailable/foreign modifier/unknown modifier rejeitados sem orders. Preço/status/restaurant/session/created_by injetados ignorados: total 20.00 e unit_price 10.00 para quantity 2 vêm do servidor. Same key/payload repete o mesmo pedido sem item duplicado. Duplicar sem key não promete deduplicação: contratos existentes permitem novo pedido. Testes existentes de modifiers/approval/pricing/replay executados na full suite.

## PAYMENT / CASH ABUSE

Negativo/zero/overflow/float/decimals/exponent/array/10k rejeitados sem side effect. Currency/restaurant/session/status/payment_status/actor/closed_by não são aceitos como authority. Replay de payment entre dois actors autorizados à mesma sessão retorna o registro original e mantém recorded_by do primeiro; mismatch retorna 409. Cash required/long key rejeitados. Parent session foreign retorna 404. Checks existentes de overbalance/paid/closed/idempotency complementam os casos novos.

## UPLOAD SECURITY

SEC-002/SEC-005 reproduzidos e corrigidos minimamente. Fake JPEG/PHP disguised/HTML/SVG/corrupt JPEG/fake MP4 enviados como arquivos reais, sem malware. Traversal/double extension/1000-char/unicode filename em imagem válida vira path server-side `products/{org}/{product}/image/{UUID}.jpg`. MIME real via fileinfo, extensão canônica, limits image 10MB/video 50MB; exposição omite disk/path. Tenant/grant checados. Write false preserva old media. Não foi feita validação por decoder de vídeo nem uma corrida de duas substituições de mídia; não declarar segurança completa de conteúdo multimídia/infra de servidor.

## PATH TRAVERSAL

../, ..\\, %2e%2e/ e double encoding em filename não controlam storage path. PDF filename é slug ASCII limitado + data, nunca caminho do usuário. ServeFile framework exige assinatura relativa no disk privado, chama Flysystem e converte PathTraversalDetected em 404. Não há endpoint custom de download por caminho localizado. Payloads HTTP diretos nos file responses framework e filenames PDF ficaram sem nova execução adversarial após parada; escaping PDF e safe filename existentes são verificados na suíte.

## SSRF

**NONE identificado na revisão estática de URL controlada por usuário.** A única chamada HTTP server-side de aplicação localizada é `MetaWhatsAppCloudApi::sendTemplate`: messagesUrl deriva de config interna graph_base_url/version/phone_number_id. Google review URL é validada/exposta como link, não fetched pelo servidor; reportUrl compõe link interno de config, não request HTTP. PDF remoto desabilitado. Não foram feitos probes a localhost/private/internal, pois não apareceu sink user-controlled e chamadas externas são proibidas.

## WEBHOOK WHATSAPP

Assinatura missing/invalid/body modificado retorna 403 antes de parse/mutação; invalid JSON assinado e corpo 100k desconhecido retornam 200/ack sem mutação; provider id desconhecido ignorado. HMAC sobre raw body. Testes existentes validam duplicates, status regressions/out of order, sender/WABA estrangeiros e failure masking. Http preventStrayRequests/fakes usados. Shape fuzzing integral de JSON assinado e timestamp extremes não concluído após parada; assinatura exige secret do provider.

## IDEMPOTENCY / REPLAY

Novos testes de order same key/payload, payments same/different actor/same payload/mismatch, required/empty/long cash keys e paralelo para orders/payment/cash. Contratos: payment/cash são por recurso+key, permitindo replay por outro actor autorizado sem trocar autoria; WhatsApp inclui actor hash e retorna 409 para outro actor; order público tem token de mesa como credential, sem actor autenticado. Key é string opaca max 100: caracteres de SQL não são executados por ser binding; não é obrigatório UUID. Payment/order key opcional vazia segue o contrato nullable, não uma garantia de dedupe. Testes antigos de mismatch/close/settings estão na full suite. Matriz completa de malformed keys por endpoint ficou interrompida.

## CONCURRENCY

Novos testes com duas conexões/processos reais e barreira cobrem order/payment/cash same key, duas aberturas e dois closes de sessão. IDs iguais no replay; exatamente um write ou uma operação accepted e outra refused. Banco testing truncado antes/depois dos testes, sem workers contra banco dev. DayCloseConcurrencyTest e SendDayCloseWhatsAppConcurrencyTest existentes são executados na full suite. Evidência estática: unique keys, partial active-session index, row locks, advisory transaction locks compartilhados para writers/exclusivo para day close. Uma corrida nova específica do endpoint WhatsApp resend não foi escrita após a parada.

## WEBSOCKET AUTH

Reverb configurado com credentials fictícias somente para assinatura local; closures reais carregadas. Activity exige permission correta; user.X→user.Y retorna 403; suspended user, inactive membership, removed restaurant link/role e suspended organization retornam 403 durante sessão. A1/A2/B1 e SEC-001 cobertos também pelas regressões existentes. Não foi aberto socket externo/servidor Reverb público.

## INFORMATION DISCLOSURE

APP_DEBUG=false: controlled 403/404/422/429/500 não contêm SQL/trace/path/DB host/APP_KEY/Meta token sentinel. São rotas efêmeras exclusivas do teste; nenhuma debug route foi adicionada à aplicação. Recipient phone completo não aparece na resposta de settings; resources omitem provider_message_id/credentials, e failure text passa por PhoneNumber::redact. A resposta do provider não é repassada inteira, mas code/reason limitado é persistido/exposto; não alegamos remover todo texto derivado de provider. SEC-006 é divulgação agregada específica ainda aberta.

## INPUT LIMITS

10k/100k inputs moderados, array/object no email, nested ownership arrays, quantidade extrema e dinheiro extremo testados. Normalização não escalar corrigida; public orders limit 50 items e quantity 50, text notes/name têm bounds. Unicode/RTL preservados como dados; descrição de produto max 500. Limites de todos os arrays internos, strings sem max em category/modifier description, deep JSON/scalars de query-period e control chars não foram totalmente testados após condição de parada. Não houve DoS nem validação de limites de proxy/PHP de produção.

## OPEN REDIRECT

Nenhum redirect()/away()/next/returnUrl/callback user-controlled localizado no código de aplicação. Health/root retornam resposta/view. Callback OAuth Swagger pertence ao pacote e recebe documentação gated/config estática; não identificado redirect backend arbitrário. Não foi concluída expansão de payloads nos endpoints de pacote após parada.

## COMMAND / CODE INJECTION

Busca em app/routes não encontrou exec/shell_exec/system/passthru/proc_open/Process/eval/unserialize ou include/require derivados de input HTTP. Requires de bootstrap/channels são caminhos constantes; worker Process fica exclusivamente em testes e usa argv array de cenários internos. PDF PHP/JavaScript disabled. Nenhuma RCE localizada na superfície estática revista.

## DESERIALIZATION

Session serialization **json** em config e assertion runtime. Cache serializable_classes=false, array store serialize=false. Não há unserialize de input cliente em app/routes. Queues framework serializam jobs internos, sem endpoint que receba job PHP serializado arbitrário. Não houve auditoria de todas as internals de pacote/driver como se fossem input HTTP.

## ENUMERATION

Login existente/inexistente com senha errada mantém 401 `Invalid credentials.` nos testes existentes; não fizemos benchmark de timing. Tokens inexistentes devolvem 404 padronizado por recurso sem tenant data; foreign resources na matriz devolvem 403/404 segundo contrato (alguns catalog policies resolvem tenant primeiro e negam scope com 403). Não afirmamos uniformidade byte-for-byte global de todos os IDORs. SEC-006 revelou um agregado não autorizado mesmo sem enumeração de ID.

## DB PRIVILEGE PLAN

Produzido [plano concreto para PR-3](pr-sec1a-2-db-runtime-privilege-plan.md): todas as 56 tabelas declaradas, tabela migrations adicional, DML por grupo, sequences USAGE, funções advisory, catálogos somente SELECT, session/cache/queue e separação migration/runtime/operator. Sem grants/roles/users criados. Não foi validado contra role runtime restrita ou Supabase. O plano não desbloqueia SEC-006.

## FIXES APPLIED

Quatro correções locais menores: write false de mídia; guards de tipo na normalização/login bounds; bound de amount de pagamento; dimensões de imagem. Nenhuma alteração para SEC-001 ou SEC-006. Sem migrações/schema/frontend/hosting.

## SECURITY TESTS / QUALITY

- Security targeted final: **FAIL**, 107 testes / 803 assertions; 105 PASS e somente as duas reproduções de SEC-006 FAIL.
- Relevant feature groups antes da reprodução de SEC-006: **PASS**, 508 testes / 1.971 assertions; Security/Auth/Product/TableSession/Organization/Category/Restaurant/Staff. Essa rodada já continha as quatro correções e suas regressões; Pint posterior alterou somente formatação.
- Full suite: **FAIL**, `./vendor/bin/sail artisan test`: **1.960 testes, 1.958 PASS, duas falhas somente em SEC-006**, em 426,520 segundos. **Assertions: 7.625.** Nenhum error adicional. As reproduções seguras de SEC-006 ficam vermelhas deliberadamente, sem skip/xfail.
- Pint: PASS, `./vendor/bin/sail bin pint --dirty --test`.
- Composer audit: PASS inicial e final, `./vendor/bin/sail composer audit --locked`, zero advisories.
- Composer validate: PASS, `./vendor/bin/sail composer validate`.
- Diff check: **PASS**, `git diff --check` após as alterações finais; arquivos novos também conferidos quanto a whitespace.

## FILES CHANGED

Aplicação: StoreOrReplaceProductMediaAction; LoginRequest; Store/UpdateCategoryRequest; UpdateOrganizationRequest; StoreProductMediaRequest; Store/UpdateRestaurantRequest; Store/UpdateStaffRequest; StorePaymentRequest; AppServiceProvider. Worker: `tests/Concurrency/worker.php`, novos cenários internos de segurança.

Novos arquivos, sem stage:

- `tests/Feature/Security/AdversarialCatalogTest.php`
- `tests/Feature/Security/CapabilityAndRevocationTest.php`
- `tests/Feature/Security/ConcurrentWritesTest.php`
- `tests/Feature/Security/FeedbackSummaryTenantBoundaryTest.php`
- `tests/Feature/Security/FinancialInputTest.php`
- `tests/Feature/Security/HttpSecurityBoundaryTest.php`
- `tests/Feature/Security/InputBoundaryTest.php`
- `tests/Feature/Security/MediaWriteFailureTest.php`
- `tests/Feature/Security/NormalizationInputTest.php`
- `tests/Feature/Security/PublicAbuseTest.php`
- `tests/Feature/Security/StoredTextRenderingTest.php`
- `tests/Feature/Security/TenantResourceMatrixTest.php`
- `tests/Feature/Security/WebhookAndThrottleTest.php`
- `docs/pr-sec1a-2-backend-adversarial-security-final-report.md`
- `docs/pr-sec1a-2-sql-runtime-inventory.md`
- `docs/pr-sec1a-2-db-runtime-privilege-plan.md`

Relatórios históricos da PR-SEC1A/SEC1A.1 preservados. `git diff --stat` não contabiliza untracked.

## SECURITY VERDICT

**B) BLOCKED — SEC-006, cross-organization aggregate escape após revogação.** Precisa de correção dedicada e retomada das matrizes interrompidas; não READY FOR SUPABASE SECURITY VALIDATION. A ausência de HIGH confirmado nesta rodada parcial não elimina a condição de parada explícita.

COMMIT: NO. PUSH: NO. DEPLOY: NO. SUPABASE/HOSTINGER: NÃO CONFIGURADOS.
