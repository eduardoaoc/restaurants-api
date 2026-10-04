<?php

namespace Tests\Feature\Configuration;

use Tests\TestCase;

class ExposureTest extends TestCase
{
    public function test_disabled_swagger_blocks_every_documentation_route(): void
    {
        config(['l5-swagger.enabled' => false]);
        foreach (['/api/docs', '/docs', '/docs/api-docs.json', '/docs/api-docs.yaml', '/docs/asset/swagger-ui.css', '/api/oauth2-callback'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_local_swagger_and_health_remain_available(): void
    {
        config(['l5-swagger.enabled' => true]);
        $this->get('/api/docs')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_cors_grants_only_configured_origins_with_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://app.staging.example.test', 'https://app.example.test']]);
        $this->withHeaders(['Origin' => 'https://app.staging.example.test', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/auth/context')->assertHeader('Access-Control-Allow-Origin', 'https://app.staging.example.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->withHeaders(['Origin' => 'https://untrusted.example.test', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/auth/context')->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
