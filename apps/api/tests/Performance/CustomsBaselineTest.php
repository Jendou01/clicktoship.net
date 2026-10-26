<?php

namespace Tests\Performance;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\CustomsTestCase;
use Tests\Support\MeasuresQa;

final class CustomsBaselineTest extends CustomsTestCase
{
    use DatabaseMigrations, MeasuresQa;

    public function test_customs_readiness_baseline(): void
    {
        $f = $this->customsFixture();
        $this->saveCustoms($f)->assertOk();
        $this->measure('customs_readiness', fn () => $this->bearer($f['token'], 'GET', '/api/v1/customs/shipments/'.$f['s']['id'])->assertOk());
        $this->emitMeasures();
    }
}
