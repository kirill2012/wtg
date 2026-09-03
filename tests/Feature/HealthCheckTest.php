<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_the_health_endpoint_reports_the_application_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
