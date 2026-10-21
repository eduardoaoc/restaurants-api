# QR — validação no ambiente local habitual

21 de outubro de 2026. Validação executada na API e no banco habituais, após a correção inicial de abertura automática. Sem commit, push ou deploy; aprovação no popup não foi implementada nem exercitada.

## Correção mínima do .env

Na linha 37 de `restaurants-api/.env`, o prefixo inválido `SANCTUM_STATEFUL_DOMAIN:` foi corrigido para `SANCTUM_STATEFUL_DOMAINS=localhost:`. A linha anterior não era uma atribuição válida: faltava `=`, o nome da variável estava incompleto e o primeiro hostname precisava ser restaurado. O restante da lista de origens e todos os outros bytes foram preservados. Nenhuma credencial foi impressa.

Antes, `http://localhost:8080/up` respondia 500. Após a correção, o bootstrap padrão do Laravel passou e `/up` respondeu 200. Nenhum arquivo de staging foi alterado.

## Confirmação técnica do destino, antes da migration

- O Vite carrega o `.env` habitual em modo development: frontend `http://localhost:5174`, API `http://localhost:8080`, target `local`. Sem overrides de URL no processo.
- A API em 8080 é o container Sail `restaurants-api-laravel.test-1`, do projeto Compose local `restaurants-api`, com bind mount deste workspace em `/var/www/html`. O conteúdo do `.env`, das rotas, da ação pública e da migration coincide com o checkout atual.
- A configuração habitual define `APP_ENV=local`, driver `pgsql`, host `pgsql`, porta 5432 e banco `laravel`. Não há `DB_URL` alternativa nem cache de configuração substituindo o destino.
- O hostname `pgsql` resolve para `172.19.0.3`, endereço do container local `restaurants-api-pgsql-1`, na mesma rede Docker `restaurants-api_sail`, com volume local `restaurants-api_sail-pgsql`.
- A própria conexão PostgreSQL confirmou banco `laravel`, endereço do servidor `172.19.0.3` e porta 5432. Após corrigir o `.env`, a mesma identidade foi conferida novamente pelo Laravel carregado normalmente.

Essa confirmação foi técnica, antes da alteração de schema. Não foi inferida apenas do nome do banco ou de `APP_ENV`. O PostgreSQL temporário da tarefa anterior não foi usado. Nenhuma alteração de Docker, Supabase, Redis, Reverb ou configuração de staging foi necessária.

## Migration aplicada somente nesse banco local

Antes: a migration `2026_10_21_000001_allow_public_table_session_opening` não constava em `migrations`; `table_sessions.opened_by_user_id` tinha `is_nullable=NO`. Era a única migration pendente.

Comando executado no container habitual, sem configuração alternativa:

```sh
docker exec restaurants-api-laravel.test-1 php artisan migrate \
  --path=database/migrations/2026_10_21_000001_allow_public_table_session_opening.php \
  --no-interaction
```

Depois: migration registrada no lote **12**, contendo somente essa migration; `opened_by_user_id` com `is_nullable=YES`. Nenhuma outra migration foi aplicada. Não houve refresh, rollback, truncation ou recriação do banco habitual.

## Validação real no navegador

O frontend foi iniciado por `npm run dev -- --host 127.0.0.1`, usando os arquivos habituais. O servidor ficou disponível em `http://localhost:5174`. O navegador confirmou o target `local` e as URLs reais; as chamadas foram para `http://localhost:8080`.

Foi usada a **Mesa 11**, do restaurante **1 / AFORO Malvarrosa**, inicialmente sem sessão ativa. A configuração existente já habilitava pedidos públicos e aprovação obrigatória; nenhuma configuração de negócio foi alterada.

1. Abertura do QR pela tela pública: `POST .../enter` respondeu **200** e criou a sessão **490**, `occupied`, `unpaid`.
2. Banco confirmou exatamente **uma sessão ativa**. Total de sessões da mesa passou de 19 para 20.
3. Boas-vindas e carta carregaram; `GET .../menu` respondeu **200**.
4. Pelo navegador, foi adicionado um **Hamburguesa AFORO TEST**, quantidade 1, total **13,50 €**, com nota **“PRUEBA LOCAL QR — no preparar.”**.
5. Confirmação do carrinho: `POST .../orders` respondeu **201**. A tela mostrou **Pedido #654 — Esperando la confirmación del restaurante**.
6. Consulta ao banco habitual confirmou pedido **654**, origem **customer_qr**, estado **waiting_approval**, `approved_at=null`, vinculado à sessão **490**.
7. Os **27 pedidos anteriores** da mesa continuam vinculados às sessões anteriores. A sessão nova tem somente o pedido de teste.

Nenhum erro de página foi observado. A captura de confirmação foi inspecionada em 375×812 e ficou em arquivo temporário fora do repositório. Não foram usados mocks/adapters HTTP, banco temporário ou configuração alternativa para declarar o ambiente habitual validado.

## Estado deixado para revisão

- API habitual: `http://localhost:8080`, respondendo normalmente.
- Frontend de desenvolvimento: `http://localhost:5174`, em execução com o `.env` habitual.
- Mesa **11** aberta na sessão **490**.
- Pedido de teste **#654**, **waiting_approval**, sem aprovação/rejeição/pagamento/encerramento.
- Nada foi enviado manualmente à cozinha; o fluxo de aprovação permanece pendente para a próxima etapa.

Para confirmar, abra o frontend habitual e consulte a Mesa 11 e o pedido #654 na fila de aprovação. A implementação/validação da aprovação no popup continua fora desta etapa.

Alterações de arquivo desta etapa: somente a correção de uma linha no `.env` local, este relatório e referências nos relatórios anteriores. Código, testes e demais alterações existentes foram preservados. Sem commit, push ou deploy.
