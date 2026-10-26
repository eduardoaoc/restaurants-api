# Spec: Cadastro manual de restaurante → convite do dono → convite de funcionários

Escopo: `restaurants-api` + `restaurants-web`. Relatório vivo:
`docs/onboarding-invitations-report.md` (API) — esta spec não o duplica.

> Registro SDD feito manualmente sobre a instalação existente (constitution
> preservada). Os checkpoints humanos interativos das skills
> `oh-my-sdd-specify`/`plan`/`tasks` **não foram executados**; a validação
> humana acontece na rodada manual final.

## 1. Intenção

Um Super Admin cadastra um restaurante (em organização nova ou existente), o
dono recebe um convite seguro de uso único e define a própria senha (ou aceita
com a conta que já tem), e o dono/gerente convida funcionários com papéis
operacionais. Tudo sobre organizações/usuários/papéis/escopos existentes, sem
um segundo sistema de onboarding.

### Estado auditado (2026-10-22)

| Item | Estado | Evidência |
|---|---|---|
| Plataforma × tenant | existe | `platform_role_assignments`, `EnsurePlatformAdmin`, gates `platform.*` |
| Cadastro de org/restaurante pela plataforma | **falta** | `routes/api.php` `/platform/*` só lista/suspende |
| Restaurante adicional (tenant) | existe | `POST /restaurants` + `RestaurantSettings::createDefaultsFor` |
| Vínculo do dono | parcial | dono = `user_roles` org-wide (`restaurant_id` null); só em seeders/testes |
| Convites | **falta** | nenhuma tabela/rota/notificação |
| Recuperação de senha / verificação de e-mail | **falta** | `password_reset_tokens` existe sem rotas; `MustVerifyEmail` comentado |
| Staff | existe (sem convite) | `POST /staff` cria usuário com senha definida pelo dono; e-mail único global impede conta existente |
| Troca de organização | **falta** | `ResolveTenant` sempre usa a 1ª membership ativa por id |
| Notificações/filas de e-mail | **falta** | nenhum Mailable/Notification; `MAIL_MAILER=log` local e no exemplo de staging |
| UI de plataforma | **falta** | `restaurants-web` não tem rota/menu de plataforma |
| UI de aceite | **falta** | — |

## 2. Requisitos (EARS)

### P — cadastro pela plataforma
- **[P-01]** **When** um admin com `create_platform_restaurants` enviar organização nova + restaurante + e-mail do dono, o sistema **shall** criar organização, restaurante, configurações padrão (timezone/moeda/idioma) e convite de dono numa única transação.
- **[P-02]** **When** o modo for organização existente, o sistema **shall** exigir também `manage_platform_organizations`, recusar organização suspensa e criar só restaurante + configurações (donos org-wide já alcançam o novo restaurante).
- **[P-03]** O sistema **shall not** criar produtos, mesas, funcionários, dia operacional ou assinatura paga; plano fica no padrão existente (`free`/`active`).
- **[P-04]** **When** a mesma `Idempotency-Key` for reenviada com o mesmo payload, o sistema **shall** devolver o mesmo cadastro (200) sem duplicar; com payload diferente **shall** responder `409 IDEMPOTENCY_KEY_REUSED`.
- **[P-05]** **If** o e-mail do dono pertencer a uma conta de plataforma, o sistema **shall** recusar (Super Admin nunca vira dono operacional).
- **[P-06]** **If** o envio do e-mail falhar, o cadastro **shall** permanecer íntegro com o convite em `delivery_status=failed`, recuperável por reenvio sem novo cadastro.
- **[P-07]** O sistema **shall** auditar o cadastro sem token/hash/e-mail.

### I — convite (núcleo comum dono/funcionário)
- **[I-01]** O segredo **shall** ser gerado no envio, guardado só como SHA-256 e transportado só no corpo do e-mail (link com `#token=` na URL confiável `AFORO_WEB_URL`).
- **[I-02]** A prévia **shall not** consumir o convite.
- **[I-03]** **When** a conta não existir, o aceite **shall** exigir nome + senha confirmada, criar o usuário (e-mail verificado), o vínculo e iniciar sessão, em transação com lock da linha do convite.
- **[I-04]** **When** a conta existir, o aceite **shall** exigir a senha dessa conta, **shall not** alterar nome/senha e **shall** criar só o vínculo do convite.
- **[I-05]** **If** houver sessão de outro usuário, o aceite **shall** responder `409 INVITATION_ACCOUNT_MISMATCH`.
- **[I-06]** **If** o convite estiver aceito/revogado/expirado, o sistema **shall** responder `410` com código próprio; token desconhecido `404`.
- **[I-07]** **When** dois aceites concorrerem, exatamente um **shall** vencer.
- **[I-08]** **When** reenviado, o sistema **shall** gerar novo segredo (o anterior deixa de valer), renovar expiração, respeitar cooldown (`429 INVITATION_RESEND_TOO_SOON`) e não duplicar usuário/vínculo.
- **[I-09]** O sistema **shall** distinguir `queued` / `sent` (aceito pelo transporte) / `failed` / aceito; nunca afirmar entrega na caixa.
- **[I-10]** Conta suspensa, organização suspensa, vínculo inativo existente e conta de plataforma **shall** ser recusados no aceite.

### S — funcionários
- **[S-01]** Quem tem `manage_users` em cada restaurante escolhido **shall** poder convidar com papel em `manager|waiter|kitchen|cashier` e código por restaurante.
- **[S-02]** O sistema **shall** recusar owner/plataforma/permissões arbitrárias e restaurantes fora do escopo (404) ou de outro tenant (422).
- **[S-03]** **If** o e-mail já for membro ativo/inativo da organização ou tiver convite pendente, o sistema **shall** responder 409 sem revelar outras organizações.
- **[S-04]** Listar/reenviar/revogar **shall** exigir `manage_users` em todos os restaurantes do convite.
- **[S-05]** Vínculo desativado **shall not** ser reativado por convite.

### C — contexto
- **[C-01]** Após aceite, a organização do convite **shall** ser a organização ativa da sessão.
- **[C-02]** **When** o usuário escolher outra organização em que tem membership ativa, `ResolveTenant` **shall** usá-la; caso contrário **shall** manter o comportamento atual.
- **[C-03]** A troca **shall** limpar restaurante/dados anteriores no frontend.

### W — frontend
- **[W-01]** Área de plataforma: formulário (nova/existente), validação, loading, anti-duplo-clique (mesma chave no retry), resumo, estado do convite, reenviar/revogar.
- **[W-02]** Página `/invitation`: prévia, fluxo conta nova/existente, estados expirado/revogado/usado, sessão divergente, sem token na URL após leitura.
- **[W-03]** Equipe: convidar (sem senha), convites com estado, reenviar/revogar.
- **[W-04]** Troca de organização quando houver mais de uma.
- **[W-05]** Textos es-ES / ca-ES-valencia / en-GB; 375/768/1440; claro/escuro.

## 3. Fora de escopo
Cobrança/Stripe, mudança de plano, transferência de propriedade, login social,
envio real de e-mail, cadastro público, deploy, ativação no banco habitual,
recuperação de senha genérica.
