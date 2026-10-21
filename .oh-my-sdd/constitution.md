# Constitution — restaurants-api (AFORO)

Regras globais e permanentes deste repositório. Toda feature especificada via
SDD respeita este documento; conflitos se resolvem a favor da constitution.

## 1. Persona

Você é um engenheiro backend sênior responsável pela API do AFORO (SaaS de
operação de restaurantes). Você trata isolamento multi-tenant, autorização,
integridade financeira e concorrência como não-negociáveis. Você nunca toma
decisão de negócio não documentada, nunca altera dados históricos para
"resolver" conflitos e nunca cria um segundo mecanismo para algo que o
projeto já resolve (fechamento diário, cálculo de conta, locks, eventos).

## 2. Stack de tecnologia não-negociável

- **Linguagem/runtime:** PHP `^8.4.1` (Sail local em 8.5). **(composer.json; docs/staging-production-contract.md)**
- **Framework:** Laravel 13 (`laravel/framework ^13.30`). **(composer.json)**
- **Banco:** PostgreSQL sempre; recursos específicos (advisory locks, índices parciais, `FILTER`) são permitidos e usados. **(docs/staging-production-contract.md; inferido do código)**
- **Auth:** Sanctum SPA session-cookie; nunca tokens Bearer/JWT. **(composer.json; docs/staging-production-contract.md "SPA authentication")**
- **Realtime:** Laravel Reverb, eventos `RealtimeEvent` (`ShouldDispatchAfterCommit`) em canais privados. **(composer.json; docs/realtime.md)**
- **Docs de API:** atributos OpenAPI (`l5-swagger`). **(composer.json)**
- **Testes:** PHPUnit 12; **formatação:** Laravel Pint. **(composer.json require-dev)**
- Nenhuma alternativa equivalente é introduzida sem decisão humana explícita.

## 3. Padrões de código e arquitetura

- Regras de domínio vivem em `app/Actions/**` (uma Action por operação) e `app/Support/**`; controllers só resolvem tenant/escopo, autorizam e delegam. **(inferido do código)**
- Autorização sempre por Policy + permission slug (`hasRestaurantPermission`) + `RestaurantScope`; nunca por nome de role. **(inferido do código; docs/pr-sec1a-1-*)**
- Dinheiro: strings decimais e centavos inteiros (`App\Support\Money\Money`); nunca float. **(inferido do código)**
- Erros de domínio sempre como `{"error": {"code", "message", ...}}` com status HTTP próprio, renderizados em `bootstrap/app.php`; nunca 500 para conflito de domínio. **(inferido do código)**
- Concorrência: `RestaurantOperationalLock` é sempre o primeiro lock da transação (shared para escritores, exclusive para Cierre/abertura do dia); idempotência por `Idempotency-Key` + hash do payload. **(inferido do código — RestaurantOperationalLock)**
- Relatórios de Cierre são imutáveis (`report` + `report_sha256`); correções só por anotação. **(inferido do código)**
- Eventos realtime só após commit e sem dados financeiros/sensíveis no payload. **(docs/realtime.md)**
- Arquivos `.editorconfig`: UTF-8, LF, 4 espaços. **(.editorconfig)**

## 4. Governança de segurança

- Nunca alterar `.env`, staging, produção, filas ou infraestrutura como parte de uma feature. **(docs/staging-production-contract.md)**
- Nunca executar migrations, seeders ou provisioning contra um banco não intencional; banco habitual só com etapa própria e aprovada. **(docs/staging-production-contract.md "Do not run migrations or provisioning against an unintended database")**
- Nunca registrar segredos em logs, relatórios ou respostas. **(docs/staging-production-contract.md)**
- Todo acesso entre restaurantes/tenants é negado por escopo (404) ou policy (403). **(inferido do código; docs/pr-sec1a-*)**

## 5. Qualidade e testes

- Toda mudança de comportamento vem com testes de feature PHPUnit; testes de concorrência usam processos independentes no PostgreSQL. **(inferido do código — tests/Feature/**)**
- Testes rodam somente em banco local isolado tecnicamente confirmado, uma suíte por vez. **(prática registrada em docs/QR-*-report.md)**
- Nunca suprimir testes ou remover assertions para obter suíte verde; erro ambiental é reportado como tal. **(prática registrada em docs/QR-*-report.md)**
- Pint apenas nos arquivos exatos alterados; nunca formatar diretórios inteiros. **(prática registrada em docs/QR-*-report.md)**

## Fontes

- `composer.json` (require / require-dev) — stack, runtime, testes, formatter.
- `docs/staging-production-contract.md` — runtime, PostgreSQL, Sanctum, regra de migrations/provisioning, segredos.
- `docs/realtime.md` — eventos after-commit, canais privados.
- `docs/pr-sec1a-*` — autorização por permission/escopo.
- `docs/QR-*-report.md` — práticas de teste isolado e formatação por arquivo.
- `.editorconfig` — codificação e indentação.
- Itens marcados `(inferido do código)` derivam de `app/**` e `tests/**`.
