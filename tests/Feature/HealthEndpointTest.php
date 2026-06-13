<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok_status(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_response_includes_correlation_id_header(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID');
    }

    public function test_provided_correlation_id_is_echoed_back(): void
    {
        $correlationId = 'test-correlation-abc123';

        $response = $this->withHeader('X-Correlation-ID', $correlationId)
            ->getJson('/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID', $correlationId);
    }
}
