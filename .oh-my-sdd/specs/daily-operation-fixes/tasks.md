# Tasks: Correções do ciclo operacional diário

## Tasks

- [x] 1. Ambiente de teste: runtime temporário com GD (+ BCMath) via `PHP_INI_SCAN_DIR`, storage isolado, banco isolado `daily_operation_testing` conferido; reproduzir os 19 erros de GD e o problema do `ProvisionTest` antes da correção.
- [x] 2. Testes de A em `tests/Feature/DailyOperation/DailyOperationLifecycleTest.php`: fechamento tardio (21 → 22 07:00), preview == confirmação, abertura do dia 22 em seguida, variante com DST, sem dupla contagem, Cierre anterior intacto (devem falhar antes da correção).
- [x] 3. Corrigir `app/Support/DayClose/DayClosePeriodResolver.php`: usar `business_date` da operação aberta; legado inalterado; atualizar docblock.
- [x] 4. Rodar DailyOperation + DayClose + WhatsApp (que dependem da data) e concorrência.
- [x] 5. API B: `summary.sales.operation` em `BuildRestaurantOperationsSnapshotAction.php` (remove `received_operation`); OpenAPI se aplicável; testes de snapshot (aberta / encerrada / nova abertura zerada / nenhuma / fundo fora / outro restaurante).
- [x] 6. Web B: tipos (`src/types/operations.ts`), `QuickMetrics.vue` com os três estados e rótulos; i18n es-ES/ca-ES-valencia/en-GB.
- [x] 7. Web C: `parseMoneyInput` em `src/utils/price.ts`; usar em `DayCloseView.vue`, `DayCloseCashCard.vue`, `DailyOperationCard.vue` e `DayCloseCashMovementDialog.vue` (mesmo contrato decimal); mensagem de erro traduzida.
- [x] 8. Testes web: estender `tests/frontend-daily-operation.browser.ts` (cartão em 3 estados, vírgula/ponto no Cierre enviando o mesmo decimal, inválidos recusados, vazio não vira zero); build + regressões (daily-operation, qr-entry, availability, realtime, approvals, session-history, assignment, hardening, security).
- [x] 9. `tests/Feature/Provisioning/ProvisionTest.php`: isolar `APP_CONFIG_CACHE` do subprocesso; rodar o teste com e sem `APP_CONFIG_CACHE` herdado.
- [x] 10. Suíte backend completa sequencial no ambiente de teste com GD.
- [x] 11. Preparação local somente leitura (migrations pendentes, identidade do banco, efeito de `aforo:provision`, sessões legadas) e comandos propostos.
- [x] 12. Revisão SDD (requisito → implementação → evidência → pendência), atualizar `docs/QR-daily-operation-report.md` e notas de verificação; git status/diff final.
