<?php

namespace Tests\Feature\Configuration;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * aforo:deploy-check: a staging configuration that follows
 * docs/staging-runbook.md passes, each broken contract item fails, and
 * the output never contains a configured value.
 */
class DeployCheckTest extends TestCase
{
    private const SECRETS = [
        'base64:c2VjcmV0LWtleS1mb3ItZGVwbG95LWNoZWNrLXRlc3Q=', 'reverb-secret-value', 'db-password-value',
        'smtp-password-value', 'EAAGtoken-value', 'meta-app-secret-value', 'verify-token-value',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'app.key' => self::SECRETS[0],
            'app.url' => 'https://api-staging.acme-staging.net',
            'l5-swagger.enabled' => false,
            'cors.allowed_origins' => ['https://app-staging.acme-staging.net'],
            'cors.allowed_origins_patterns' => [],
            'cors.supports_credentials' => true,
            'sanctum.stateful' => ['app-staging.acme-staging.net'],
            'session.driver' => 'database',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.domain' => '.acme-staging.net',
            'session.cookie' => 'aforo_staging_session',
            'broadcasting.default' => 'reverb',
            'reverb.servers.reverb.host' => '127.0.0.1',
            'reverb.apps.apps.0.app_id' => 'staging-app',
            'reverb.apps.apps.0.key' => 'public-key',
            'reverb.apps.apps.0.secret' => self::SECRETS[1],
            'reverb.apps.apps.0.options.host' => 'ws-staging.acme-staging.net',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.scheme' => 'https',
            'reverb.apps.apps.0.allowed_origins' => ['app-staging.acme-staging.net'],
            'trustedproxy.proxies' => '127.0.0.1',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'aws-0-eu-west-2.pooler.supabase.com',
            'database.connections.pgsql.database' => 'postgres',
            'database.connections.pgsql.password' => self::SECRETS[2],
            'database.connections.pgsql.sslmode' => 'require',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 90,
            'broadcasting.queue' => 'realtime',
            'invitations.mail_queue' => 'mail',
            'whatsapp.queue' => 'whatsapp',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.acme-staging.net',
            'mail.mailers.smtp.username' => 'apikey',
            'mail.mailers.smtp.password' => self::SECRETS[3],
            'mail.from.address' => 'no-reply@acme-staging.net',
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.level' => 'info',
            'invitations.web_url' => 'https://app-staging.acme-staging.net',
            'whatsapp.web_url' => 'https://app-staging.acme-staging.net',
            'whatsapp.enabled' => true,
            'whatsapp.graph_api_version' => 'v25.0',
            'whatsapp.phone_number_id' => '106540352242922',
            'whatsapp.access_token' => self::SECRETS[4],
            'whatsapp.waba_id' => '102290129340398',
            'whatsapp.template_name' => 'aforo_cierre_diario',
            'whatsapp.template_language' => 'es_ES',
            'whatsapp.app_secret' => self::SECRETS[5],
            'whatsapp.webhook_verify_token' => self::SECRETS[6],
        ]);
    }

    public function test_a_configuration_following_the_runbook_passes_without_printing_values(): void
    {
        $exitCode = Artisan::call('aforo:deploy-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('0 FAIL', $output);
        $this->assertStringNotContainsString('[FAIL]', $output);
        $this->assertNoValues($output);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function brokenContracts(): array
    {
        return [
            'local env' => [['app.env' => 'local'], 'APP_ENV'],
            'debug on' => [['app.debug' => true], 'APP_DEBUG'],
            'no key' => [['app.key' => ''], 'APP_KEY'],
            'http app url' => [['app.url' => 'http://api-staging.acme-staging.net'], 'APP_URL'],
            'swagger exposed' => [['l5-swagger.enabled' => true], 'L5_SWAGGER_ENABLED'],
            'empty cors' => [['cors.allowed_origins' => []], 'CORS_ALLOWED_ORIGINS'],
            'localhost cors' => [['cors.allowed_origins' => ['https://app-staging.acme-staging.net', 'http://localhost:5174']], 'CORS_ALLOWED_ORIGINS'],
            'cors pattern' => [['cors.allowed_origins_patterns' => ['#.*#']], 'cors.allowed_origins_patterns'],
            'empty stateful' => [['sanctum.stateful' => []], 'SANCTUM_STATEFUL_DOMAINS'],
            'local stateful' => [['sanctum.stateful' => ['app-staging.acme-staging.net', 'localhost:5174']], 'SANCTUM_STATEFUL_DOMAINS'],
            'origin not stateful' => [['sanctum.stateful' => ['other.acme-staging.net']], 'SANCTUM_STATEFUL_DOMAINS'],
            'insecure cookie' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'same site none' => [['session.same_site' => 'none'], 'SESSION_SAME_SITE'],
            'host-only cookie across hosts' => [['session.domain' => null], 'SESSION_DOMAIN'],
            'cookie domain not covering spa' => [['session.domain' => '.other-site.net'], 'SESSION_DOMAIN'],
            'reverb http' => [['reverb.apps.apps.0.options.scheme' => 'http'], 'REVERB_SCHEME'],
            'reverb local host' => [['reverb.apps.apps.0.options.host' => 'localhost'], 'REVERB_HOST'],
            'reverb wildcard' => [['reverb.apps.apps.0.allowed_origins' => ['*']], 'REVERB_ALLOWED_ORIGINS'],
            'reverb empty origins' => [['reverb.apps.apps.0.allowed_origins' => ['']], 'REVERB_ALLOWED_ORIGINS'],
            'reverb no secret' => [['reverb.apps.apps.0.secret' => null], 'REVERB_APP_SECRET'],
            'local database name' => [['database.connections.pgsql.database' => 'laravel'], 'DB_DATABASE'],
            'remote db without tls' => [['database.connections.pgsql.sslmode' => 'prefer'], 'DB_SSLMODE'],
            'sync queue' => [['queue.default' => 'sync'], 'QUEUE_CONNECTION'],
            'retry_after too short' => [['queue.connections.database.retry_after' => 45], 'queue retry_after'],
            'local queue name' => [['whatsapp.queue' => 'realtime-local'], 'WHATSAPP_QUEUE'],
            'mail to common log' => [['mail.default' => 'log', 'mail.mailers.log.channel' => null], 'MAIL_LOG_CHANNEL'],
            'mail discarded' => [['mail.default' => 'array'], 'MAIL_MAILER'],
            'placeholder sender' => [['mail.from.address' => 'hello@example.com'], 'MAIL_FROM_ADDRESS'],
            'capture in common log' => [['logging.channels.stack.channels' => ['daily', 'mail_capture']], 'LOG_STACK'],
            'web url localhost' => [['invitations.web_url' => 'http://localhost:5174'], 'AFORO_WEB_URL'],
            'whatsapp incomplete' => [['whatsapp.access_token' => null], 'WHATSAPP_ACCESS_TOKEN'],
            'whatsapp graph version' => [['whatsapp.graph_api_version' => 'latest'], 'WHATSAPP_GRAPH_API_VERSION'],
            'webhook without app secret' => [['whatsapp.app_secret' => null], 'META_APP_SECRET'],
            'webhook without verify token' => [['whatsapp.webhook_verify_token' => null], 'WHATSAPP_WEBHOOK_VERIFY_TOKEN'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('brokenContracts')]
    public function test_each_broken_contract_item_fails(array $override, string $setting): void
    {
        config($override);

        $this->assertSame(1, Artisan::call('aforo:deploy-check'));
        $output = Artisan::output();

        $this->assertStringContainsString("[FAIL] {$setting}:", $output);
        $this->assertNoValues($output);
    }

    public function test_mail_capture_and_disabled_whatsapp_are_warnings_not_delivery(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'mail_capture',
            'whatsapp.enabled' => false,
            'trustedproxy.proxies' => null,
        ]);

        $this->assertSame(0, Artisan::call('aforo:deploy-check'));
        $output = Artisan::output();

        $this->assertStringContainsString('[WARN] MAIL_MAILER: log -> protected capture file only', $output);
        $this->assertStringContainsString('[WARN] WHATSAPP_CLOUD_ENABLED:', $output);
        $this->assertStringContainsString('[WARN] TRUSTED_PROXIES:', $output);
    }

    public function test_same_host_spa_needs_no_cookie_domain(): void
    {
        config([
            'app.url' => 'https://staging.acme-staging.net',
            'cors.allowed_origins' => ['https://staging.acme-staging.net'],
            'sanctum.stateful' => ['staging.acme-staging.net'],
            'reverb.apps.apps.0.allowed_origins' => ['staging.acme-staging.net'],
            'invitations.web_url' => 'https://staging.acme-staging.net',
            'whatsapp.web_url' => 'https://staging.acme-staging.net',
            'session.domain' => null,
        ]);

        $this->assertSame(0, Artisan::call('aforo:deploy-check'), Artisan::output());
    }

    private function assertNoValues(string $output): void
    {
        foreach ([...self::SECRETS, 'acme-staging.net', 'supabase', '106540352242922', 'aforo_cierre_diario'] as $value) {
            $this->assertStringNotContainsString($value, $output);
        }
    }
}
