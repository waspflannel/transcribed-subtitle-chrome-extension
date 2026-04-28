<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_route_returns_ok(): void
    {
        $response = $this->get('/health');

        $response
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
