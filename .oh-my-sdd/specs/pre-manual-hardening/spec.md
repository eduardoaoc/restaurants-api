# Spec: fechamento técnico antes da rodada manual integrada

Escopo: `restaurants-api` + `restaurants-web`. Relatório vivo:
`docs/pre-manual-hardening-report.md`. Estende
`specs/owner-staff-invitations` (não duplica). Registro manual sobre a
instalação oh-my-sdd existente; checkpoints interativos das skills não
executados.

## Requisitos (EARS)

### R — recuperação de senha (broker nativo)
- **[R-01]** **When** alguém pedir recuperação, o sistema **shall** responder o mesmo corpo/status para conta existente, inexistente ou suspensa.
- **[R-02]** O sistema **shall** limitar pedidos por IP e por e-mail (hash) e herdar o throttle por conta do broker.
- **[R-03]** O token **shall** ser guardado como hash, expirar (60 min), valer uma vez e ser invalidado após sucesso.
- **[R-04]** A prévia **shall not** consumir o token; a troca **shall** exigir confirmação explícita e a política de senha existente.
- **[R-05]** **When** a senha for trocada, o sistema **shall** revogar as sessões da conta (sem afetar terceiros), **shall not** autenticar, conceder acesso a organizações, aceitar convites ou levantar suspensões.
- **[R-06]** O link **shall** usar `AFORO_WEB_URL` com o segredo no fragmento; o frontend **shall** removê-lo do URL antes de carregar qualquer outro módulo.

### E — e-mails, convites e logs
- **[E-01]** O token de convite **shall** ser emitido uma vez por envio; retries/execução duplicada **shall** enviar o mesmo link e **shall not** sobrescrever o hash vigente.
- **[E-02]** Jobs de e-mail **shall** ser criptografados (`ShouldBeEncrypted`) e roteados para fila de e-mail configurável.
- **[E-03]** Localmente, e-mails **shall** ir só para a caixa de captura (`mail_capture`), nunca para `laravel.log`.
- **[E-04]** Execuções de teste **shall** usar banco/storage/config/logs isolados, reproduzíveis por script versionado.

### W — workers
- **[W-01]** Worker local de e-mail **shall** consumir só `mail-local`; backlog da `default` **shall** permanecer intacto.

### D — OpenAPI e ativação
- **[D-01]** O OpenAPI versionado **shall** ser regenerado pelo gerador existente e cobrir todos os endpoints implementados.
- **[D-02]** A ativação local **shall** ser precedida de confirmação de destino e backup verificado, aplicar só migrations revisadas por `--path` e provisioning aditivo.
