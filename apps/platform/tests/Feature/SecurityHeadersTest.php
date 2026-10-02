<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_baseline_security_headers_are_present_on_every_response(): void
    {
        $response = $this->getJson('/backoffice/v1/jurisdictions');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_security_headers_are_present_even_on_an_error_response(): void
    {
        $response = $this->postJson('/v1/auth/sign-in/complete', ['msisdn' => 'not-a-real-number']);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_cors_allows_backoffice_subdomain_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://backoffice.betplus.com.ng',
            'Access-Control-Request-Method' => 'GET',
        ])->json('OPTIONS', '/backoffice/v1/jurisdictions');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://backoffice.betplus.com.ng');
    }
}
