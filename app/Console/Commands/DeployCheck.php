<?php

namespace App\Console\Commands;

use App\Jobs\SendDayCloseWhatsApp;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Console\Command;

/**
 * Deployment gate for staging/production: checks the EFFECTIVE (cached)
 * configuration against docs/staging-production-contract.md and
 * docs/staging-runbook.md before the release receives traffic.
 *
 * Read-only: no database, queue, mail or network access. Prints only
 * setting names and the reason of each finding — never a value (no URLs,
 * hosts, keys, tokens or passwords), so its output can be pasted into a
 * report. Exit code 1 when any FAIL is found; WARN never blocks.
 */
class DeployCheck extends Command
{
    protected $signature = 'aforo:deploy-check';

    protected $description = 'Validate the effective staging/production configuration without printing values';

    /** @var array<int, array{string, string, string}> */
    private array $findings = [];

    public function handle(WhatsAppConfig $whatsApp): int
    {
        $this->checkApplication();
        $apiHost = $this->host((string) config('app.url'));
        $origins = $this->checkCors();
        $stateful = $this->checkSanctum($origins);
        $this->checkSession($apiHost, $stateful);
        $this->checkRealtime($stateful);
        $this->checkProxies();
        $this->checkDatabase();
        $this->checkQueues();
        $this->checkMail();
        $this->checkLogging();
        $this->checkWhatsApp($whatsApp, $origins);

        $this->findings = array_values(array_unique($this->findings, SORT_REGULAR));
        $failures = 0;
        foreach ($this->findings as [$level, $setting, $reason]) {
            $failures += $level === 'FAIL' ? 1 : 0;
            $this->line(sprintf('[%s] %s: %s', $level, $setting, $reason));
        }

        $this->line(sprintf('%d FAIL, %d WARN.', $failures, count($this->findings) - $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkApplication(): void
    {
        if (! in_array(config('app.env'), ['staging', 'production'], true)) {
            $this->failing('APP_ENV', 'must be staging or production');
        }
        if (config('app.debug')) {
            $this->failing('APP_DEBUG', 'must be false');
        }
        if (blank(config('app.key'))) {
            $this->failing('APP_KEY', 'missing (generate ONCE and keep it in the secret store; never per deploy)');
        }
        $this->requirePublicHttps('APP_URL', (string) config('app.url'));
        if (! $this->laravel->configurationIsCached()) {
            $this->warning('config:cache', 'configuration is not cached; run php artisan config:cache after every .env change');
        }
        if (ini_get('zend.exception_ignore_args') !== '1') {
            $this->warning('zend.exception_ignore_args', 'Off in this PHP CLI; set On (CLI and FPM) so stack traces never carry arguments');
        }
        if (config('l5-swagger.enabled')) {
            $this->failing('L5_SWAGGER_ENABLED', 'API documentation routes are exposed');
        }
    }

    /**
     * @return array<int, string> allowed CORS origins
     */
    private function checkCors(): array
    {
        $origins = (array) config('cors.allowed_origins');

        if ($origins === []) {
            $this->failing('CORS_ALLOWED_ORIGINS', 'empty: the SPA cannot call the API');
        }
        foreach ($origins as $origin) {
            if (! $this->isPublicHttps((string) $origin)) {
                $this->failing('CORS_ALLOWED_ORIGINS', 'contains an entry that is not a public https origin');
            }
        }
        if ((array) config('cors.allowed_origins_patterns') !== []) {
            $this->failing('cors.allowed_origins_patterns', 'patterns are not allowed with credentials');
        }
        if (! config('cors.supports_credentials')) {
            $this->failing('cors.supports_credentials', 'must be true for the Sanctum SPA session');
        }

        return $origins;
    }

    /**
     * @param  array<int, string>  $origins
     * @return array<int, string> stateful hosts without port
     */
    private function checkSanctum(array $origins): array
    {
        $stateful = array_values(array_filter((array) config('sanctum.stateful')));
        $hosts = array_map(fn (string $entry) => strtolower((string) preg_replace('/:\d+$/', '', trim($entry))), $stateful);

        if ($hosts === []) {
            $this->failing('SANCTUM_STATEFUL_DOMAINS', 'empty: SPA requests would never be authenticated');
        }
        foreach ($hosts as $host) {
            if ($this->isLocalHost($host) || str_contains($host, '*') || str_contains($host, '/')) {
                $this->failing('SANCTUM_STATEFUL_DOMAINS', 'contains a local, wildcard or scheme-qualified entry');
            }
        }
        foreach ($origins as $origin) {
            if (! in_array($this->host($origin), $hosts, true)) {
                $this->failing('SANCTUM_STATEFUL_DOMAINS', 'a CORS origin host is missing from the stateful list');
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<int, string>  $stateful
     */
    private function checkSession(string $apiHost, array $stateful): void
    {
        if (config('session.secure') !== true) {
            $this->failing('SESSION_SECURE_COOKIE', 'must be true over HTTPS');
        }
        if (config('session.http_only') !== true) {
            $this->failing('SESSION_HTTP_ONLY', 'must be true');
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $this->failing('SESSION_SAME_SITE', 'must be lax (or strict); SPA and API share one site');
        }
        if (in_array(config('session.driver'), ['array', 'cookie'], true)) {
            $this->failing('SESSION_DRIVER', 'array/cookie drivers are not valid for a deployed SPA session');
        }
        if (config('session.cookie') === 'aforo-session') {
            $this->warning('SESSION_COOKIE', 'same cookie name as local/other environments; use an environment-specific name');
        }

        // The SPA reads XSRF-TOKEN from document.cookie (axios withXSRFToken):
        // when SPA and API hosts differ, the cookie must be scoped to a
        // parent domain shared by both, or every POST ends in 419.
        $domain = ltrim(strtolower((string) config('session.domain')), '.');
        foreach ($stateful as $spaHost) {
            if ($spaHost === $apiHost) {
                continue;
            }
            if ($domain === '') {
                $this->failing('SESSION_DOMAIN', 'SPA and API hosts differ; set the shared parent domain (leading dot) or the SPA cannot read XSRF-TOKEN');
            } elseif (! $this->within($spaHost, $domain) || ! $this->within($apiHost, $domain)) {
                $this->failing('SESSION_DOMAIN', 'does not cover both the SPA host and the API host');
            }
        }
    }

    /**
     * @param  array<int, string>  $stateful
     */
    private function checkRealtime(array $stateful): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            $this->warning('BROADCAST_CONNECTION', 'not reverb: realtime is disabled and screens rely on polling');

            return;
        }

        $app = (array) config('reverb.apps.apps.0');
        foreach (['REVERB_APP_ID' => 'app_id', 'REVERB_APP_KEY' => 'key', 'REVERB_APP_SECRET' => 'secret'] as $name => $key) {
            if (blank($app[$key] ?? null)) {
                $this->failing($name, 'missing');
            }
        }
        if (($app['options']['scheme'] ?? null) !== 'https') {
            $this->failing('REVERB_SCHEME', 'must be https (browsers connect with wss)');
        }
        if ($this->isLocalHost(strtolower((string) ($app['options']['host'] ?? '')))) {
            $this->failing('REVERB_HOST', 'missing or local');
        }
        if ((int) ($app['options']['port'] ?? 0) !== 443) {
            $this->warning('REVERB_PORT', 'not 443; the public wss endpoint normally goes through the TLS proxy on 443');
        }

        $allowed = array_values(array_filter(array_map('trim', (array) ($app['allowed_origins'] ?? []))));
        if ($allowed === []) {
            $this->failing('REVERB_ALLOWED_ORIGINS', 'empty: every WebSocket handshake is refused');
        }
        foreach ($allowed as $host) {
            if (str_contains($host, '*') || str_contains($host, '/') || str_contains($host, ':') || $this->isLocalHost(strtolower($host))) {
                $this->failing('REVERB_ALLOWED_ORIGINS', 'contains a wildcard, scheme/port or local entry (bare public hostnames only)');
            }
        }
        foreach ($stateful as $spaHost) {
            if (! in_array($spaHost, array_map('strtolower', $allowed), true)) {
                $this->warning('REVERB_ALLOWED_ORIGINS', 'an SPA host is not allowed to open the WebSocket');
            }
        }
        if (! in_array(config('reverb.servers.reverb.host'), ['127.0.0.1', '::1', 'localhost'], true)) {
            $this->warning('REVERB_SERVER_HOST', 'Reverb listens beyond loopback; keep it on 127.0.0.1 behind the TLS proxy unless a firewall blocks the port');
        }
    }

    private function checkProxies(): void
    {
        $proxies = config('trustedproxy.proxies');

        if (blank($proxies)) {
            $this->warning('TRUSTED_PROXIES', 'empty: correct only when PHP-FPM gets the client address directly (runbook topology); behind a CDN/load balancer set its ranges');
        } elseif ($proxies === '*' || $proxies === '**') {
            $this->warning('TRUSTED_PROXIES', 'trusts any peer; acceptable only if the app port is reachable exclusively through the proxy');
        }
    }

    private function checkDatabase(): void
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}");

        if (($connection['driver'] ?? null) !== 'pgsql') {
            $this->failing('DB_CONNECTION', 'must be pgsql');

            return;
        }
        if (in_array($connection['database'] ?? null, ['laravel', 'testing'], true) || str_ends_with((string) ($connection['database'] ?? ''), '_testing')) {
            $this->failing('DB_DATABASE', 'uses the local development/testing database name');
        }
        if ($this->isLocalHost(strtolower((string) ($connection['host'] ?? '')))) {
            $this->warning('DB_HOST', 'local database server: confirm it is the staging instance, never a development one');
        } elseif (! in_array($connection['sslmode'] ?? null, ['require', 'verify-ca', 'verify-full'], true)) {
            $this->failing('DB_SSLMODE', 'remote database without require/verify-ca/verify-full');
        }
    }

    private function checkQueues(): void
    {
        $name = (string) config('queue.default');

        if (in_array($name, ['sync', 'null', 'deferred', 'background'], true)) {
            $this->failing('QUEUE_CONNECTION', 'must be a persistent queue (database or redis)');

            return;
        }

        $retryAfter = (int) config("queue.connections.{$name}.retry_after", 0);
        $floor = max((new SendDayCloseWhatsApp(0))->timeout, SendDayCloseWhatsApp::CLAIM_STALE_AFTER_SECONDS);
        if ($retryAfter <= $floor) {
            $this->failing('queue retry_after', 'must be greater than the WhatsApp job timeout and its stale-claim window (see docs/staging-runbook.md)');
        }

        foreach (['BROADCAST_QUEUE' => 'broadcasting.queue', 'AFORO_MAIL_QUEUE' => 'invitations.mail_queue', 'WHATSAPP_QUEUE' => 'whatsapp.queue'] as $env => $key) {
            $queue = (string) config($key);
            if ($queue === '') {
                $this->warning($env, 'unset: jobs go to the connection default queue; use a dedicated queue with its own worker');
            } elseif (str_ends_with($queue, '-local')) {
                $this->failing($env, 'uses a local development queue name');
            }
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        if ($transport === 'array') {
            $this->failing('MAIL_MAILER', 'array discards every e-mail');
        } elseif ($transport === 'log') {
            if (config("mail.mailers.{$mailer}.channel") !== 'mail_capture') {
                $this->failing('MAIL_LOG_CHANNEL', 'MAIL_MAILER=log without mail_capture writes invitation/reset links to the common log');
            } else {
                $this->warning('MAIL_MAILER', 'log -> protected capture file only: nothing is DELIVERED to any inbox');
            }
        } elseif ($transport === 'smtp') {
            if ($this->isLocalHost(strtolower((string) config("mail.mailers.{$mailer}.host")))) {
                $this->failing('MAIL_HOST', 'missing or local');
            }
            foreach (['MAIL_USERNAME' => 'username', 'MAIL_PASSWORD' => 'password'] as $env => $key) {
                if (blank(config("mail.mailers.{$mailer}.{$key}"))) {
                    $this->warning($env, 'empty; most providers require SMTP authentication');
                }
            }
        }

        $from = strtolower((string) config('mail.from.address'));
        if ($from === '' || preg_match('/@(example\.(com|org|net)|[^@]*\.(test|invalid|local))$/', $from)) {
            $this->failing('MAIL_FROM_ADDRESS', 'placeholder or non-deliverable domain');
        }
    }

    private function checkLogging(): void
    {
        $channel = (string) config('logging.default');
        $channels = $channel === 'stack' ? (array) config('logging.channels.stack.channels') : [$channel];

        if (in_array('mail_capture', $channels, true)) {
            $this->failing('LOG_STACK', 'the common log must never include the mail capture channel');
        }
        foreach ($channels as $name) {
            if (config("logging.channels.{$name}.level", 'debug') === 'debug') {
                $this->warning('LOG_LEVEL', 'debug in a deployed environment; use info');

                break;
            }
        }
    }

    /**
     * @param  array<int, string>  $origins
     */
    private function checkWhatsApp(WhatsAppConfig $whatsApp, array $origins): void
    {
        foreach (['AFORO_WEB_URL' => (string) config('invitations.web_url')] as $name => $url) {
            $this->requirePublicHttps($name, $url);
            if ($origins !== [] && ! in_array(rtrim($url, '/'), $origins, true)) {
                $this->warning($name, 'is not one of the CORS origins; links in e-mails/WhatsApp would open another site');
            }
        }

        if (! $whatsApp->enabled()) {
            $this->warning('WHATSAPP_CLOUD_ENABLED', 'false: no Cierre Diario is sent (restaurants with the switch on get `skipped`)');

            return;
        }

        foreach ($whatsApp->missingForSending() as $name) {
            $this->failing($name, 'missing or invalid while WHATSAPP_CLOUD_ENABLED=true');
        }
        if ($whatsApp->verifyToken() === null) {
            $this->failing('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'missing: Meta cannot verify the webhook');
        }
        if ($whatsApp->appSecret() === null) {
            $this->failing('META_APP_SECRET', 'missing: every status webhook is refused (403), statuses stay `accepted`');
        }
        if ($whatsApp->wabaId() === null) {
            $this->warning('WHATSAPP_WABA_ID', 'unset: webhooks from any WABA with the same phone number id are processed');
        }
    }

    private function requirePublicHttps(string $name, string $url): void
    {
        if (! $this->isPublicHttps($url)) {
            $this->failing($name, 'must be a public https URL (no localhost/loopback)');
        }
    }

    private function isPublicHttps(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && ! $this->isLocalHost($this->host($url));
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function isLocalHost(string $host): bool
    {
        $host = trim($host, '[]');

        return $host === ''
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_starts_with($host, '127.') || $host === '::1' || $host === '0.0.0.0'
            || in_array($host, ['host.docker.internal', 'pgsql', 'redis', 'mailpit'], true);
    }

    private function within(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    private function failing(string $setting, string $reason): void
    {
        $this->findings[] = ['FAIL', $setting, $reason];
    }

    private function warning(string $setting, string $reason): void
    {
        $this->findings[] = ['WARN', $setting, $reason];
    }
}
