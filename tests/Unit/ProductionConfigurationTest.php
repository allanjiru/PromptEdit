<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    public function test_composer_declares_application_platform_extensions(): void
    {
        $composer = json_decode(
            file_get_contents(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('*', $composer['require']['ext-bcmath'] ?? null);
        $this->assertSame('*', $composer['require']['ext-pdo'] ?? null);
        $this->assertSame('*', $composer['require']['ext-pdo_pgsql'] ?? null);
    }

    public function test_private_local_disk_has_no_generic_serving_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.local.root')
        );
    }
}
