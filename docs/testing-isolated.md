# Testes backend isolados e reproduzíveis

## Caminho padrão: `scripts/test-sail.sh`

```bash
scripts/test-sail.sh                                  # suíte completa
scripts/test-sail.sh tests/Feature/Invitations        # parte
scripts/test-sail.sh --filter PasswordResetTest
```

Roda dentro do container Sail de desenvolvimento já existente (PHP 8.5 com
`gd`, `bcmath`, `intl`, `pdo_pgsql`, `pdo_sqlite`) — sem extensões temporárias
em `/tmp` do WSL.

| Recurso | Para onde aponta | Garantia |
|---|---|---|
| Banco | `testing` (banco de testes do Sail, criado por `create-testing-database.sql`) via `phpunit.xml` | `tests/TestCase.php` aborta se o banco não se chamar `testing`/`*_testing` ou se `APP_ENV≠testing` |
| Config em cache | nenhuma | o script recusa rodar se `bootstrap/cache/config.php` existir e aponta `APP_CONFIG_CACHE` para arquivo inexistente |
| Storage (logs, cache, sessões, discos fake) | `/tmp/aforo-testing-storage` **dentro do container**, recriado a cada execução | o `storage/logs/laravel.log` habitual e pastas `root:root` não são tocados |
| E-mail / fila / broadcast / cache / sessão | `array` / `sync` / `null` / `array` / `array` (`phpunit.xml`) | nenhum envio, nenhum worker |
| Subprocessos | herdam o ambiente acima (workers de concorrência); `ProvisionTest` força sqlite em memória, sem config em cache e `LOG_CHANNEL=null` | nunca alcançam o banco/log habituais |
| OpenAPI | `DayCloseNoForceTest` lê o arquivo **versionado** `storage/api-docs/api-docs.json` via `base_path()` | não depende de cópia manual |

Regerar o OpenAPI (arquivo versionado):

```bash
docker compose exec -u sail laravel.test php artisan l5-swagger:generate
```

## Caminho alternativo (Postgres temporário do WSL)

As rodadas anteriores usaram um Postgres em `127.0.0.1:55443` com config em
cache **copiada** de outra sessão. Problema encontrado: esse cache tinha o
caminho absoluto do log habitual (ver `docs/onboarding-invitations-report.md`
§10). Se esse caminho for usado de novo, gere o cache do zero com variáveis
de ambiente (nunca copie um cache antigo):

```bash
export LARAVEL_STORAGE_PATH=<dir isolado>  APP_CONFIG_CACHE=<dir isolado>/config.php
APP_ENV=testing APP_KEY=base64:$(openssl rand -base64 32) DB_HOST=127.0.0.1 DB_PORT=55443 \
DB_DATABASE=<algo>_testing MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array \
SESSION_DRIVER=array BROADCAST_CONNECTION=null php artisan config:cache
```

e confira `config('logging.channels.single.path')` antes de rodar.
