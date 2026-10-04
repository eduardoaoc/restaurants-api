<?php

namespace Tests\Feature\Configuration;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\Env;
use Tests\TestCase;

class EnvironmentContractTest extends TestCase
{
    public function test_environment_defaults_and_explicit_cors_list(): void
    {
        $this->withEnvironment(['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => null, 'L5_SWAGGER_ENABLED' => null], function (): void {
            $this->assertSame([], (require config_path('cors.php'))['allowed_origins']);
            $this->assertFalse((require config_path('l5-swagger.php'))['enabled']);
        });
        $this->withEnvironment(['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => null, 'L5_SWAGGER_ENABLED' => null], function (): void {
            $this->assertSame(['http://localhost:5173', 'http://localhost:5174'], (require config_path('cors.php'))['allowed_origins']);
            $this->assertTrue((require config_path('l5-swagger.php'))['enabled']);
        });
        $this->withEnvironment(['APP_ENV' => 'staging', 'CORS_ALLOWED_ORIGINS' => ' *, https://app.example.test , https://*.example.test, null, https://example.test/path, http://localhost:5173', 'L5_SWAGGER_ENABLED' => 'false'], function (): void {
            $cors = require config_path('cors.php');
            $this->assertSame(['https://app.example.test', 'http://localhost:5173'], $cors['allowed_origins']);
            $this->assertTrue($cors['supports_credentials']);
            $this->assertFalse((require config_path('l5-swagger.php'))['enabled']);
        });
    }

    public function test_session_and_sanctum_environment_contract(): void
    {
        $this->withEnvironment(['SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax', 'SESSION_DOMAIN' => '.example.test', 'SANCTUM_STATEFUL_DOMAINS' => 'app.example.test,app.staging.example.test:443'], function (): void {
            $session = require config_path('session.php');
            $this->assertTrue($session['secure']);
            $this->assertTrue($session['http_only']);
            $this->assertSame('lax', $session['same_site']);
            $this->assertSame('.example.test', $session['domain']);
            $this->assertSame(['app.example.test', 'app.staging.example.test:443'], (require config_path('sanctum.php'))['stateful']);
        });
    }

    private function withEnvironment(array $values, callable $callback): void
    {
        $property = new \ReflectionProperty(Env::class, 'repository');
        $previous = Env::getRepository();
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(ArrayAdapter::class)->make();
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $repository->set($key, $value);
            }
        }
        $property->setValue(null, $repository);
        try {
            $callback();
        } finally {
            $property->setValue(null, $previous);
        }
    }
}
