# Auditoria — envio do Cierre Diario por WhatsApp (Meta Cloud API)

Data: 2026-10-25. Base: código da API `fix/security-hardening` (HEAD `c195492`
+ mudanças não commitadas), Web `main` (`9ee3d46` + não commitadas), banco
local (somente leitura) e documentação oficial da Meta consultada em
**2026-10-25**. **Nenhuma mensagem enviada, nenhuma conta Meta tocada.**
Nenhum token, número completo ou conteúdo de relatório aparece aqui.

## 1. Veredito

A integração **existe e é real**: o cliente fala diretamente com a WhatsApp
Cloud API oficial (`graph.facebook.com/{versão}/{phone_number_id}/messages`,
mensagem de template), com webhook de status assinado. **Nunca foi
exercitada contra a Meta**: o usuário não criou app, WABA, número, token nem
template; todos os testes usam `Http::fake`. Logo, hoje: **implementada e
testada com transporte simulado; sem validação real; inteiramente dependente
de configuração externa.** Não há modo "simulado" escondido nem transporte
que apenas grava log: com `WHATSAPP_CLOUD_ENABLED=false` (padrão, e o estado
do `.env` local) nada é tentado.

Estado local em 2026-10-25 (consulta de leitura): 0 entregas registradas, 4
restaurantes com o envio desligado, 1 destinatário cadastrado, nenhuma
variável Meta configurada.

## 2. Fluxo reconstruído

```
POST /api/v1/restaurants/{r}/day-closes            (CloseRestaurantDayAction, 1 transação)
  ├─ RestaurantDayClose (snapshot imutável, report_sha256)
  └─ DayCloseDeliveryService::planAutomatic
       ├─ switch do restaurante off        → nenhuma linha
       ├─ config Meta incompleta           → delivery `skipped` configuration_incomplete
       ├─ sem destinatário / sem consentimento → `skipped` recipient_missing / consent_missing
       └─ ok → delivery `pending` + SendDayCloseWhatsApp::dispatch()->onQueue(WHATSAPP_QUEUE)->afterCommit()
COMMIT  (fechamento salvo independentemente do WhatsApp)
  └─ worker da fila → SendDayCloseWhatsApp
        claim (lock curto, attempts+1, sending_started_at) → COMMIT
        MetaWhatsAppCloudApi::sendTemplate  (sem transação; timeouts 5 s/10 s)
        record: accepted + provider_message_id | retry (códigos transitórios) | failed
Meta ──POST /api/v1/webhooks/whatsapp (X-Hub-Signature-256)──► DeliveryStatusUpdater
        accepted → sent → delivered → read  (só avança) | failed (antes de delivered)
Reenvio manual: POST /api/v1/day-closes/{id}/deliveries (Idempotency-Key, view_daily_closes)
UI: «Cierre diario» → detalhe/histórico mostra estado e falha sanitizada; Configuración → destinatário.
```

Gatilho: **automático** no fechamento + **manual** (reenvio). Não há
agendamento nem scheduler.

## 3. Classificação por parte

| Parte | Onde | Classificação |
|---|---|---|
| Variáveis/config, validação de presença e formato da versão | `config/whatsapp.php`, `WhatsAppConfig` | Implementada e testada |
| Provedor = WhatsApp Cloud API oficial (não BSP/terceiro) | `MetaWhatsAppCloudApi` | Implementada, testada com mock; **sem validação real** |
| Versão Graph | `WHATSAPP_GRAPH_API_VERSION` explícita, regex `vN.N`; doc sugere `v25.0` | Implementada; `v25.0` vale até 2028-07-29; v26.0 é a mais nova |
| Payload de template (parâmetros nomeados, `es_ES`) | `WhatsAppTemplateMessage`, `DayCloseWhatsAppPresenter` | Implementado e testado com mock; **template não existe na Meta** |
| Template aprovado | WhatsApp Manager | **Ausente / dependente de configuração externa** |
| Job, fila, tentativas, at-most-once | `SendDayCloseWhatsApp` | Implementado e testado (inclui concorrência com processos reais) |
| Fila dedicada | `WHATSAPP_QUEUE` (nesta tarefa) | Implementado e testado; worker em staging **preparado, não ativo** |
| Destinatários, consentimento, criptografia do telefone | `UpdateDayCloseWhatsAppSettingsAction`, `RestaurantReportRecipient` | Implementado e testado |
| Autorização (configurar: `manage_restaurants`; reenviar: `view_daily_closes`) + isolamento | policies + testes de tenant | Implementado e testado |
| Webhook (verificação + assinatura + filtro WABA/phone id + idempotência) | `WhatsAppWebhookController`, `DeliveryStatusUpdater` | Implementado e testado; **URL pública HTTPS e assinatura na Meta ausentes** |
| Estados na UI (Aceptado ≠ Entregado) | Web `DayCloseWhatsApp*` | Implementado; sem validação real |
| Auditoria | `day_close.whatsapp_resend_requested` (telefone mascarado) | Implementado e testado (só reenvio; o envio automático é rastreado pela linha de delivery) |
| Envio real, webhook real, entrega em aparelho | — | **Ausente (nunca executado)** |

## 4. Respostas às perguntas da auditoria

- **Cloud API ou outro provedor?** Cloud API oficial, chamada direta, um
  remetente central AFORO (uma WABA); restaurantes só escolhem destinatário.
- **Modo simulado / transporte só de log?** Não existe. Desligado = nada.
  Testes usam `Http::fake` (nunca a rede).
- **Fila `default`?** Era. Agora `WHATSAPP_QUEUE` (nulo = comportamento
  anterior). Separação segura: jobs já enfileirados ficam onde estão (o
  backlog local de 284 broadcasts antigos na `default` não foi tocado); o
  `release()` de retry permanece na mesma fila; staging usa `whatsapp` com
  **um** worker. Recomendação local: só defina `WHATSAPP_QUEUE` quando
  houver worker para ela.
- **Retry ou clique repetido duplica?** Projetado para não duplicar: claim
  com lock antes do HTTP; claim recente → outra execução sai sem tocar;
  claim antigo → `failed unknown_outcome`, nunca reenviado; com
  `provider_message_id` nunca reenvia; timeout/5xx sem corpo/2xx sem id →
  `failed`, sem retry; retry só para códigos transitórios documentados
  (`4, 80007, 130429, 131000, 131016, 131056`) e falha de conexão que nunca
  chegou à Meta (cURL 6/7). Clique repetido: `Idempotency-Key` (a UI
  reutiliza a chave após erro incerto), 409 enquanto houver entrega
  `pending`, limite 3/min. **Corrigido nesta tarefa**: o job não tinha
  `timeout` próprio — um worker com timeout maior que `retry_after` poderia
  entregar o mesmo job a outro worker; agora `timeout=30`,
  `failOnTimeout=true` (falha → `job_failed`, sem reenvio) e o
  `aforo:deploy-check` exige `retry_after` > 60 s (janela de claim).
  **Limite residual**: se o job for perdido (fila apagada) a entrega fica
  `pending` e bloqueia o reenvio com 409; detectável com
  `aforo:queue-status --queue=whatsapp --max-age=300`; não corrigido (exigiria
  decisão de produto sobre expirar `pending`).
- **HTTP 200 = entrega?** Não. 200 com `messages[0].id` = **aceito** pela
  Meta (`accepted`, rótulo "Aceptado por WhatsApp"). `sent`/`delivered`/`read`
  só via webhook. Sem webhook configurado, tudo para em `accepted`.
- **Falha preserva o fechamento?** Sim: entrega criada na transação do
  fechamento, envio só depois do commit, nenhuma exceção do job toca o
  `RestaurantDayClose` (testado).
- **Tokens protegidos?** Só no ambiente; nunca no banco, respostas, logs,
  frontend. Cliente nunca usa `->throw()` (que embute o corpo); logs só com
  códigos; motivo de falha com telefone redigido e cortado em 255 chars;
  teste dedicado verifica logs e respostas. O job carrega só o id da
  entrega (não o telefone).
- **Webhook autêntico e sem duplicação?** `hub.verify_token` comparado em
  tempo constante; `X-Hub-Signature-256` HMAC-SHA256 do corpo **bruto** com o
  app secret, antes do parse; filtra `phone_number_id` e WABA; status só
  avança; duplicados e fora de ordem não regridem; ids desconhecidos
  recebem 200 (evita 7 dias de retry da Meta). Sem `META_APP_SECRET` todo
  POST é 403 (fail-closed).
- **Relatório só do restaurante autorizado?** Parâmetros vêm do snapshot
  daquele fechamento + snapshot do destinatário daquele restaurante; sem
  dados de clientes, comentários ou equipe. Teste de isolamento por tenant.
- **Links/documentos?** A mensagem leva só o link
  `AFORO_WEB_URL/app/day-close/{id}`, que **exige login** com permissão no
  restaurante; não há URL pública nem PDF anexado, então não há expiração a
  gerir. Exposição residual: o resumo financeiro do template fica no
  WhatsApp do destinatário (por desenho, com consentimento declarado).

## 5. Requisitos Meta (documentação oficial, consultada em 2026-10-25)

| Requisito | Tipo | Situação AFORO |
|---|---|---|
| Business portfolio + app com o caso de uso WhatsApp | **Obrigatório** | ausente |
| WhatsApp Business Account (WABA) e `phone_number_id` | **Obrigatório** | ausente |
| Número de teste (criado pelo quickstart; limites relaxados, sem forma de pagamento para templates; destinatários adicionados no painel) | Condicional (validação) | ausente — caminho recomendado para o 1º teste |
| Número definitivo: verificado, **registrado via API** (`POST /{phone_number_id}/register` com PIN de 2 etapas); 2 números por portfolio novo | Obrigatório para produção | ausente |
| Token de **system user** (temporário expira em horas) com `whatsapp_business_messaging` e `whatsapp_business_management` (+ `business_management` segundo o guia), app e WABA atribuídos com controle total | **Obrigatório** | ausente |
| Template aprovado (categoria, idioma, exemplos para cada parâmetro; corpo ≤ 1024 chars; só `APPROVED` envia; revisão até 24 h) | **Obrigatório** fora da janela de 24 h | ausente; contrato em `docs/whatsapp-daily-close.md` |
| Janela de atendimento de 24 h (mensagem livre só dentro dela) | Informativo | irrelevante: AFORO só envia template |
| Opt-in: informar que a pessoa aceita receber mensagens e o nome do negócio; respeitar opt-out; recomendado opt-in por categoria | **Obrigatório** (política) | parcialmente: admin declara o consentimento (texto v1); **não há registro do opt-in da própria pessoa nem fluxo de opt-out** além de o admin trocar/desativar |
| Categoria utility para alertas/atualizações de conta; uso indevido pode reclassificar como marketing | Condicional | template ainda não submetido; risco de reclassificação |
| Limite inicial de 250 destinatários únicos/24 h por portfolio novo; verificação do negócio eleva | Informativo | irrelevante para staging |
| Ritmo por par: ~1 msg/6 s ao mesmo usuário (erro 131056) | Informativo | já tratado como transitório |
| Webhook: HTTPS com certificado válido (**autoassinado não é aceito**), GET de verificação com `hub.challenge`, POST assinado `X-Hub-Signature-256`, assinatura do campo `messages`, retries por até 7 dias, lotes até 1000, payload até 3 MB; mTLS opcional; IPs da Meta mudam (não use allowlist) | **Obrigatório** para status | código pronto; URL pública ausente |
| Assinatura do app na WABA (`POST /{WABA_ID}/subscribed_apps`) | **Obrigatório** para receber webhooks | ausente (o guia do webhook só cita o painel; a doc local mantém a chamada da API) |
| Versão da Graph API explícita (v25.0 até 2028-07-29; v26.0 lançada 2026-07-29) | Recomendação | atendida |
| Verificação do negócio, display name | Recomendação / produção | ausente |

Fontes oficiais (consultadas em 2026-10-25):

- Get started: https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started
- Access tokens: https://developers.facebook.com/documentation/business-messaging/whatsapp/access-tokens/
- Registro de número: https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/registration
- Números de telefone: https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/phone-numbers
- Templates (fundamentos): https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview
- Componentes de template: https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/components
- Categorização: https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/template-categorization
- Opt-in: https://developers.facebook.com/documentation/business-messaging/whatsapp/getting-opt-in
- Limites de mensagens: https://developers.facebook.com/documentation/business-messaging/whatsapp/messaging-limits
- Webhooks (visão geral): https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/overview/
- Criar endpoint de webhook: https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint
- Webhook de status: https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/messages/status
- Versões da Graph API: https://developers.facebook.com/docs/graph-api/changelog/versions/
- Política do WhatsApp Business: https://business.whatsapp.com/policy

Pontos **não confirmados** na documentação consultada (não presumir): número
máximo de destinatários de teste do número de teste; regras de densidade de
variáveis e de variável no início/fim do corpo (o template AFORO tem 16
variáveis — risco real de rejeição na revisão; prepare uma versão com menos
variáveis caso seja recusado).

## 6. Plano para a primeira validação real (quando você autorizar)

Sem mudar código. Em staging, com número de **teste**:

1. Meta: app (caso de uso WhatsApp) no business portfolio → anotar WABA id e
   test `phone_number_id`; adicionar **seu** número como destinatário de teste.
2. System user com app + WABA atribuídos (controle total) → token permanente
   com `whatsapp_business_messaging` + `whatsapp_business_management`.
3. Template `es_ES`, parâmetros **nomeados**, corpo do contrato →
   aguardar `APPROVED`.
4. `.env` de staging: variáveis WhatsApp + `WHATSAPP_QUEUE=whatsapp` +
   `WHATSAPP_CLOUD_ENABLED=true` → `config:cache` → `aforo:deploy-check` sem
   FAIL → reiniciar grupo.
5. Webhook: Callback `https://<API_HOST>/api/v1/webhooks/whatsapp`, verify
   token, campo `messages`; `POST /{WABA_ID}/subscribed_apps`.
6. No AFORO: Configuración → destinatário = seu número + consentimento →
   ligar o envio → fechar um dia de **teste** → observar `pending` →
   `accepted` → `sent` → `delivered` → `read` no histórico do Cierre e no
   aparelho; depois um reenvio manual e um clique duplo (deve gerar uma só
   mensagem).
7. Negativos: template com nome errado (`132001` → `failed`), token
   revogado (`190`), webhook com app secret errado (403, status fica
   `accepted`).
