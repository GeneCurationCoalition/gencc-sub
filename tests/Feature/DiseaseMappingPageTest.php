<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DiseaseMappingPageTest extends TestCase
{
    public function test_disease_mapping_page_is_public(): void
    {
        $this->get(route('help.disease-mapping'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Help/DiseaseMapping')
            );
    }
}
