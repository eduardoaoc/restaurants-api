# Workers, filas e e-mail no ambiente local (Sail)

Estado conferido em 2026-10-22. Escopo: **somente o ambiente local de
desenvolvimento** (container `restaurants-api-laravel.test-1`). Staging e
produção não são configurados aqui.

## Filas e quem consome o quê

| Fila (`QUEUE_CONNECTION=database`) | Conteúdo | Worker local |
|---|---|---|
| `realtime-local` (`BROADCAST_QUEUE`) | broadcasts `App\Events\Realtime\*` (Reverb) | worker já existente (não criado por esta tarefa) |
| `mail-local` (`AFORO_MAIL_QUEUE`) | e-mails de convite e de recuperação de senha (`InvitationNotification`, `ResetPasswordNotification`, ambos `ShouldBeEncrypted`) | worker próprio desta tarefa (abaixo) |
| `default` | **backlog antigo** de broadcasts (≈284 jobs de 2026-10-02 a 2026-10-07) e, quando existir, `SendDayCloseWhatsApp` (envio externo à Meta) | **nenhum** — não consumir |

Regra: nunca rode `queue:work` sem `--queue=` explícito, nem `--queue=default`,
nem `queue:restart` (reinicia todos os workers). O backlog da `default` é
preservado de propósito; decidir apagá-lo/reprocessá-lo é do usuário.

## E-mail local (caixa de captura)

Não há serviço de captura (Mailpit etc.) no `compose.yaml`. Configuração local:

```
MAIL_MAILER=log
MAIL_LOG_CHANNEL=mail_capture      # canal em config/logging.php
```

Todo e-mail vai **somente** para `storage/logs/mail-capture.log` (permissão
0600, ignorado pelo Git) — nada sai da máquina e nada é escrito no
`laravel.log`. O arquivo contém links de uso único: não compartilhe, não cole
em relatórios. Para ler o último link durante a rodada manual:

```bash
grep -o 'http://localhost:5174/[a-z-]*#[^" ]*' storage/logs/mail-capture.log | tail -1
```

`AFORO_WEB_URL=http://localhost:5174` define a base dos links (convite e
recuperação). Sem essa variável o envio falha (`delivery_status=failed`,
recuperável por reenviar depois de configurar).

## Worker de e-mail (próprio desta tarefa)

Iniciar (uma instância só; conferir antes que não há outra):

```bash
docker exec restaurants-api-laravel.test-1 ps -eo pid,user,args | grep 'queue=mail-local' | grep -v grep
docker exec -d -u sail restaurants-api-laravel.test-1 \
  php artisan queue:work database --queue=mail-local --name=aforo-mail-local \
  --tries=3 --backoff=30 --timeout=30 --sleep=3
```

Identificar e parar (por PID, nunca `pkill` genérico):

```bash
docker exec restaurants-api-laravel.test-1 ps -eo pid,user,args | grep 'queue=mail-local' | grep -v grep
docker exec restaurants-api-laravel.test-1 kill <PID>
```

As notificações declaram `tries=3` e `backoff=[30,120]` (prevalecem sobre a
linha de comando); `timeout=30` evita worker preso. Reiniciar o worker depois
de alterar `.env` ou código de notificações.

## Worker de broadcast (já existente)

Rodando como `root`, iniciado fora do supervisor antes desta tarefa:
`php artisan queue:work database --queue=realtime-local --sleep=1 --tries=0 --backoff=10 --timeout=60`.
Não foi reiniciado (não precisa recarregar nada desta tarefa). Observação: por
rodar como `root`, arquivos que ele criar em `storage/` ficam com dono `root`
(origem provável da pasta `storage/framework/testing/disks/public/products`
`root:root 700`). Recomendação para depois: iniciá-lo com `-u sail`.

## Diagnóstico: Reverb conectado ≠ worker saudável

O navegador pode estar conectado ao Reverb (`:8081`) e mesmo assim nenhum
evento chegar, porque quem publica é o **worker** da fila `realtime-local`.

1. Worker vivo: `ps` acima com `queue=realtime-local`.
2. Fila andando: `select queue, count(*) from jobs group by queue;` — `realtime-local`
   deve voltar a 0 segundos depois de uma ação (pedido, aprovação…).
3. Falhas novas: `select queue, payload::json->>'displayName', failed_at from failed_jobs order by failed_at desc limit 5;`
   (nunca imprimir `payload` inteiro nem desserializar à mão).
4. Reverb vivo: processo `reverb:start` e porta 8081 escutando.
5. Comportamento esperado da tela com o worker parado (Reverb no ar): o
   indicador continua "conectado" — isso é correto; o frontend faz um
   reconcile REST de segurança a cada 60 s enquanto conectado (rearmado a cada
   evento recebido), então a tela atrasa no máximo ~60 s. Com o Reverb parado
   o indicador muda e o polling passa a ~15 s.
6. E-mail: `mail-local` volta a 0, `invitations.delivery_status` passa a `sent`
   e o link aparece em `mail-capture.log` — "sent" significa entregue ao
   transporte local, nunca à caixa de alguém.

## Staging/produção (não alterado)

`.env.staging.example` traz `MAIL_MAILER=log`: em staging isso gravaria links
de convite/recuperação no log da aplicação. Antes de ativar convites ou
recuperação em staging: SMTP real (credenciais do provedor, não inventadas)
ou serviço de captura de staging, `AFORO_WEB_URL` HTTPS do frontend, fila de
e-mail dedicada com worker supervisionado, e `zend.exception_ignore_args=On`.
