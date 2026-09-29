<?php

namespace Tests\Feature;

use App\Console\Traits\CachesFileHeaders;
use App\Models\StaticFileHeader;
use App\Services\DiseaseOntologySources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiseaseOntologySourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_cache_refresh_keeps_legacy_history_and_then_skips(): void
    {
        $headers = ['etag' => 'pinned', 'content-length' => '100', 'last-modified' => null];
        $adapter = new class($headers)
        {
            use CachesFileHeaders;

            public function __construct(private array $headers)
            {
            }

            protected function fetchHeaders($url)
            {
                return $this->headers;
            }

            public function info($text)
            {
            }

            public function warn($text)
            {
            }

            public function needed($id)
            {
                return $this->shouldUpdateFile($id, 'offline');
            }

            public function record($id)
            {
                $this->updateCachedHeaders($id, 'offline');
            }
        };
        $adapter->record(DiseaseOntologySources::LEGACY[DiseaseOntologySources::MONDO]);
        $this->assertSame('legacy', DiseaseOntologySources::metadata()[0]['header_context']);
        $this->assertTrue($adapter->needed(DiseaseOntologySources::MONDO));
        $adapter->record(DiseaseOntologySources::MONDO);
        $this->assertFalse($adapter->needed(DiseaseOntologySources::MONDO));
        $this->assertSame('current', DiseaseOntologySources::metadata()[0]['header_context']);
        $this->assertSame(2, StaticFileHeader::count());
    }
}
