<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status(): void
    {
        // Bu test health endpoint'ine istek atar.
        // 200 ve status=ok dönüyorsa test geçer.
        $this->getJson('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_response_includes_correlation_id_header(): void
    {
        // Bu test response header'ında correlation ID üretildiğini doğrular.
        // X-Correlation-ID header'ı varsa test geçer.
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID');
    }

    public function test_provided_correlation_id_is_echoed_back(): void
    {
        // Bu test request'te verilen correlation ID'nin response'a aynen yansıtıldığını kontrol eder.
        // Header değeri birebir aynıysa test geçer.
        $correlationId = 'test-correlation-abc123';

        $response = $this->withHeader('X-Correlation-ID', $correlationId)
            ->getJson('/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID', $correlationId);
    }
}
