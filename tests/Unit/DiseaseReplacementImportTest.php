<?php

namespace Tests\Unit;

use App\Services\DiseaseReplacementImport as Import;
use PHPUnit\Framework\TestCase;

class DiseaseReplacementImportTest extends TestCase
{
    public function test_omim_parses_complete_forms_and_preserves_gene_destinations(): void
    {
        $entries = Import::omimEntries("# copyright\nAsterisk\t123456\tGENE\nNumber Sign\t654321\tDISEASE\n");
        foreach ([
            'MOVED TO 123456' => ['OMIM:123456'],
            'MOVED TO {123456}' => ['OMIM:123456'],
            'MOVED TO 123456 AND 654321' => ['OMIM:123456', 'OMIM:654321'],
            'MOVED TO 123456, 654321, AND 111111' => ['OMIM:123456', 'OMIM:654321', 'OMIM:111111'],
        ] as $title => $expected) {
            $parsed = Import::omim('Caret', $title, $entries);
            $this->assertSame($expected, $parsed['replaced_by']);
            $this->assertSame('Asterisk', $parsed['replacement_details']['OMIM:123456']['prefix']);
            $this->assertSame('parsed', $parsed['replacement_parse_status']);
        }
        $this->assertSame('unrecognized', Import::omim('Caret', 'MOVED TO 123456 OR 654321', $entries)['replacement_parse_status']);
        $this->assertSame([], Import::omim('Caret', 'REMOVED', $entries)['replaced_by']);
        $this->assertSame([], Import::omim('Number Sign', 'MOVED TO 123456', $entries)['replaced_by']);
    }

    public function test_orphanet_uses_xml_identity_not_root_code_or_reverse_associations(): void
    {
        $xml = '<Disorder id="17609"><OrphaCode>166068</OrphaCode><DisorderDisorderAssociationList>'
            .'<DisorderDisorderAssociation><RootDisorder id="17609" cycle="true"/><TargetDisorder id="17"><OrphaCode>166063</OrphaCode></TargetDisorder><DisorderDisorderAssociationType id="21471"/></DisorderDisorderAssociation>'
            .'<DisorderDisorderAssociation><RootDisorder id="18"><OrphaCode>555</OrphaCode></RootDisorder><TargetDisorder id="17609"/><DisorderDisorderAssociationType id="21471"/></DisorderDisorderAssociation>'
            .'<DisorderDisorderAssociation><RootDisorder id="17609"/><TargetDisorder><OrphaCode>777</OrphaCode></TargetDisorder><DisorderDisorderAssociationType id="27341"/></DisorderDisorderAssociation>'
            .'</DisorderDisorderAssociationList></Disorder>';
        $this->assertSame(['replaced_by' => ['Orphanet:166063']], Import::orphanet(simplexml_load_string($xml)));
        $this->assertSame(['replaced_by' => []], Import::orphanet(simplexml_load_string('<Disorder id="1"/>')));
    }
}
