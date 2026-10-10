<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * T-01: Verificación de salud del sistema.
     */
    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/health');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }
}
