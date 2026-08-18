<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Restaurants API',
    description: 'REST API for the Restaurants SaaS platform.'
)]
#[OA\Server(
    url: 'http://localhost',
    description: 'Local development'
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    description: 'Enter token in format: Bearer <token>',
    name: 'Authorization',
    in: 'header'
)]
class ApiDocumentation
{
}