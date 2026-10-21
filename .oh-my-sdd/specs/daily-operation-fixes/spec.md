# Spec: Correções do ciclo operacional diário

Escopo: `restaurants-api` + `restaurants-web`. Relatório vivo da feature:
`docs/QR-daily-operation-report.md` (único; esta spec não o duplica).

## 1. Intenção e visão geral

O ciclo abrir dia → operar → Cierre Diario já existe. Esta correção fecha
inconsistências comprovadas, sem reimplementar abertura/fechamento:

- A) o fechamento de uma operação aberta deve pertencer a ela e usar a data de
  negócio persistida na abertura, mesmo se fechar depois da próxima hora de corte;
- B) o cartão operacional do Dashboard deve ter significado estável
  (operação aberta → última encerrada → vazio explícito);
- C) "Efectivo contado" (e demais valores do Cierre) com vírgula decimal;
- D) eliminar os erros ambientais da suíte sem suprimir testes, e isolar o
  subprocesso do `ProvisionTest` para nunca alcançar config/banco habitual;
- E) preparação concreta (somente leitura) para ativar migration/provisioning
  no ambiente habitual em etapa própria.

### Problemas comprovados (estado em 2026-10-22, git sem mudanças desde o último relatório)

| Id | Evidência |
|---|---|
| A | `DayClosePeriodResolver::resolve` calcula `businessDate = businessDateOf($endUtc)` (linha 54) mesmo com `dailyOperation` aberta (linha 74); a operação aberta dia 21 e fechada dia 22 às 07:00 (após corte 06:00) gera Cierre com `business_date` 22, e a abertura seguinte do dia 22 é recusada (`BUSINESS_DATE_ALREADY_CLOSED`) até o corte do dia 23. |
| B | `QuickMetrics.vue:46-47`: `received_operation ?? received_today` — o mesmo cartão muda de semântica (operação ↔ calendário) quando o dia fecha; rótulo cai para "Ventas hoy" embora o valor seja de pagamentos. |
| C | `DayCloseView.vue:165` envia `countedCash.value.trim()` cru; `100,00` → 422 (validado em integração real: POST 422, depois 201 com `100.00`). `DayCloseCashCard.vue:72` usa `Number(countedCash)` → `NaN` com vírgula (diferença exibida errada). |
| D1 | 19 erros `GD extension is not installed` (PHP 8.4 do WSL sem GD). |
| D2 | `ProvisionTest::test_cli_returns_nonzero_when_provisioning_fails` lança `artisan aforo:provision` herdando o ambiente do pai; com `APP_CONFIG_CACHE` (ou um `bootstrap/cache/config.php`) o subprocesso ignora o sqlite do teste e **provisionou o banco da config em cache** (ocorreu no banco isolado na última rodada). |
| E | Ambiente habitual (leitura): migration `2026_10_22_000001` pendente, `open_daily_operation`/`manage_product_availability` não provisionadas, 1 sessão ativa legada. |

## 2. Requisitos funcionais (EARS)

### A — data do fechamento

- **[A-01]** **While** existir operação diária aberta, o sistema **shall** usar a `business_date` persistida dessa operação como `business_date` do preview e do Cierre, sem recalculá-la pelo instante do fechamento.
- **[A-02]** **When** o Cierre for confirmado, o sistema **shall** registrar `closed_at` (Cierre e operação) com o instante real do fechamento.
- **[A-03]** **When** a operação é fechada após a próxima hora de corte, o sistema **shall** permitir abrir a operação seguinte para a data de negócio corrente, se não estiver fechada.
- **[A-04]** **If** a `business_date` da operação aberta já tiver Cierre, o sistema **shall** recusar com `409 BUSINESS_DATE_ALREADY_CLOSED`, sem alterar dados existentes.
- **[A-05]** **While** não houver operação aberta (Cierre legado), o sistema **shall** manter o cálculo de data atual (instante do fechamento + corte).
- **[A-06]** O sistema **shall** manter o encadeamento de períodos (`period_started_at` = fim do Cierre anterior), cálculos financeiros, idempotência, locks e relatórios/hashes imutáveis existentes.

### B — cartão operacional do Dashboard

- **[B-01]** **While** houver operação aberta, o cartão **shall** mostrar o recebido (pagamentos) dessa operação, rotulado como recebido da operação com sua data.
- **[B-02]** **While** não houver operação aberta e existir operação encerrada, o cartão **shall** mostrar o recebido da última operação encerrada, rotulado como encerrada com sua data.
- **[B-03]** **When** uma nova operação abrir, o cartão **shall** começar em zero para ela.
- **[B-04]** **If** nunca houve operação, o cartão **shall** mostrar estado vazio explícito (não zero).
- **[B-05]** **If** a leitura do snapshot falhar, o sistema **shall** manter o tratamento de erro existente e nunca exibir zero no lugar do erro.
- **[B-06]** O fundo inicial **shall not** compor esse valor; o rótulo **shall** dizer "cobrado/recebido", nunca "vendas".
- **[B-07]** O backend **shall** ser a autoridade (bloco no snapshot `operations/live`, mesmo público de `received_today`), sem N+1; `received_today`, Analytics e relatórios por período **shall not** mudar.

### C — valores do Cierre com vírgula

- **[C-01]** **When** o usuário digitar `100,00` ou `100.00`, a interface **shall** enviar a mesma string decimal canônica (`"100.00"`), nunca float.
- **[C-02]** **If** o valor for negativo, tiver mais de 2 decimais, separador de milhar, os dois separadores, espaços internos ou texto, a interface **shall** recusá-lo com mensagem e não enviar.
- **[C-03]** **If** o campo estiver vazio, a interface **shall not** convertê-lo em zero (zero só quando digitado).
- **[C-04]** Preview de `opening_float` (fonte `required`), diferença exibida e envio final **shall** usar o mesmo valor normalizado.

### D — suíte de testes

- **[D-01]** Os testes de mídia **shall** rodar num runtime de teste com GD, sem pular/suprimir testes.
- **[D-02]** O subprocesso do `ProvisionTest` **shall** ignorar qualquer config em cache herdada (não pode alcançar outro banco), mantendo todas as assertions.

### E — preparação local (sem ativar)

- **[E-01]** O sistema/processo **shall** produzir, por leitura, o estado do banco habitual, migrations pendentes, o efeito exato de `aforo:provision` e as sessões legadas, com comandos propostos — sem executar migrate/provision/abertura nem tocar sessões.

## 3. Critérios de aceite

- [ ] Operação aberta dia 21, Cierre real dia 22 07:00 (Europe/Madrid) → Cierre e operação `business_date` 21; `closed_at` = 22 07:00 local.
- [ ] Preview e confirmação retornam a mesma `business_date`/período.
- [ ] Abertura seguinte para o dia 22 funciona após esse fechamento tardio.
- [ ] Mesmo cenário cruzando a mudança de horário de verão (25/10/2026).
- [ ] Pagamentos não são contados em dois Cierres (encadeamento preservado).
- [ ] Cierres históricos (linhas e `report_sha256`) intactos.
- [ ] Idempotência e testes de concorrência existentes continuam passando.
- [ ] Dashboard: aberta → recebido da operação; após fechar → última encerrada rotulada; nova abertura → zero; sem operações → vazio explícito; erro ≠ zero.
- [ ] Fundo inicial fora do recebido.
- [ ] `100,00` e `100.00` enviam `"100.00"`; formatos inválidos recusados; vazio não vira zero.
- [ ] Permissões e isolamento entre restaurantes preservados; respostas antigas não contaminam outro restaurante.
- [ ] Regressões de abertura, QR e fechamento passam; TypeScript/build verdes.
- [ ] 19 erros de GD e o `ProvisionTest` resolvidos sem suprimir testes; suíte completa sequencial em banco isolado.
- [ ] Preparação local documentada com comandos e ordem, sem execução.

## 4. Requisitos não-funcionais e contratos

- **Contrato snapshot (`GET /restaurants/{id}/operations/live`):** `summary.sales.operation` =
  `{state: "open", business_date, received}` | `{state: "closed", business_date, closed_at, received}` | `{state: "none"}`.
  Substitui `received_operation` (introduzido na tarefa anterior, ainda não publicado). `received_today` inalterado.
- **Contrato Cierre:** nenhuma mudança de payload; só a regra de `business_date` com operação aberta.
- **Dinheiro:** string decimal `^\d{1,8}(\.\d{1,2})?$` (contrato existente da API); a UI aceita também vírgula como separador decimal único.
- **Segurança:** sem dados financeiros novos em eventos; mesmo público do snapshot.
- **Proibido:** editar Cierres históricos, migrations novas para esta correção, alterar `.env`/infra, executar migrate/provision no banco habitual, suprimir testes.
