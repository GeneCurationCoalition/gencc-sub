<?php

// Read-only audit using the application's import parsers; no database or downloads.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Console\Commands\UpdateDiseases;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

$directory = $argv[1] ?? dirname(__DIR__).'/data';
$mondoPath = $directory.'/'.($argv[2] ?? 'mondo-with-equivalents.json');
$orphaPath = $directory.'/'.($argv[3] ?? 'en_product1.xml');
$command = new UpdateDiseases();
$mondoParser = new ReflectionMethod($command, 'x_mondo_xrefs_array');
$orphaParser = new ReflectionMethod($command, 'x_orphanet_xrefs_xml');
$mondo = [];
$inverse = ['omim_id' => [], 'orpha_id' => []];
$multiple = ['MONDO_to_OMIM' => [], 'MONDO_to_Orphanet' => [], 'Orphanet_to_MONDO' => [], 'Orphanet_to_OMIM' => []];
foreach (Items::fromFile($mondoPath, ['pointer' => '/graphs/0/nodes', 'decoder' => new ExtJsonDecoder(true)]) as $node) {
    $curie = str_replace('_', ':', basename($node['id']));
    if (!str_starts_with($curie, 'MONDO:')) {
        continue;
    }
    $refs = $mondoParser->invoke($command, $node['meta'] ?? []);
    $entry = ['curie' => $curie, 'name' => $node['lbl'] ?? '', 'deprecated' => $node['meta']['deprecated'] ?? false, 'xrefs' => $refs];
    $mondo[$curie] = $entry;
    foreach (['omim_id' => 'MONDO_to_OMIM', 'orpha_id' => 'MONDO_to_Orphanet'] as $field => $category) {
        if (count($refs[$field]) > 1) {
            $multiple[$category][] = $entry;
        }
        foreach ($refs[$field] as $target) {
            $inverse[$field][$target][] = $curie;
        }
    }
}
$orpha = [];
$reader = new XMLReader();
$reader->open($orphaPath);
$orphaDate = null;
$unvalidatedExact = [];
while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT) {
        continue;
    }
    if ($reader->name === 'JDBOR') {
        $orphaDate = $reader->getAttribute('date');
    }
    if ($reader->name !== 'Disorder') {
        continue;
    }
    $xml = simplexml_load_string($reader->readOuterXML());
    $curie = 'Orphanet:'.$xml->OrphaCode;
    $refs = $orphaParser->invoke($command, $xml->ExternalReferenceList);
    $entry = ['curie' => $curie, 'name' => (string) $xml->Name, 'xrefs' => $refs];
    $orpha[$curie] = $entry;
    foreach (['mondo_id' => 'Orphanet_to_MONDO', 'omim_id' => 'Orphanet_to_OMIM'] as $field => $category) {
        if (count($refs[$field]) > 1) {
            $multiple[$category][] = $entry;
        }
    }
    foreach ($xml->ExternalReferenceList->ExternalReference ?? [] as $ref) {
        if (in_array((string) $ref->Source, ['MONDO', 'OMIM'], true)
            && (string) $ref->DisorderMappingRelation['id'] === '21527'
            && (string) $ref->DisorderMappingValidationStatus['id'] !== '21611') {
            $unvalidatedExact[] = $curie;
        }
    }
}
$reader->close();
foreach ($multiple['Orphanet_to_MONDO'] as &$entry) {
    $number = explode(':', $entry['curie'])[1];
    $entry['mondo_asserted_targets'] = $inverse['orpha_id'][$number] ?? [];
    $entry['target_records'] = array_map(fn ($curie) => $mondo[$curie] ?? ['curie' => $curie, 'missing' => true], $entry['xrefs']['mondo_id']);
    $entry['omim_bridge_targets'] = [];
    foreach ($entry['xrefs']['omim_id'] as $omim) {
        $entry['omim_bridge_targets'][$omim] = $inverse['omim_id'][$omim] ?? [];
    }
}
unset($entry);
$collisions = [];
foreach ($inverse as $field => $targets) {
    $collisions[$field] = array_filter($targets, fn ($terms) => count($terms) > 1);
}
$bridgeMultiple = [];
$reachableAmbiguities = [];
$steps = ['mondo_exact_match' => 0, 'orphanet_exact_match' => 0, 'omim_bridge' => 0, 'unresolved' => 0, 'ambiguous' => 0];
foreach ($orpha as $curie => $entry) {
    $number = explode(':', $curie)[1];
    $bridge = [];
    foreach ($entry['xrefs']['omim_id'] as $omim) {
        $bridge = array_merge($bridge, $inverse['omim_id'][$omim] ?? []);
    }
    $bridge = array_values(array_unique($bridge));
    if (count($bridge) > 1) {
        $bridgeMultiple[] = $entry + ['bridge_targets' => $bridge];
    }
    $candidates = [
        'mondo_exact_match' => $inverse['orpha_id'][$number] ?? [],
        'orphanet_exact_match' => array_values(array_filter($entry['xrefs']['mondo_id'], fn ($id) => isset($mondo[$id]))),
        'omim_bridge' => $bridge,
    ];
    $outcome = 'unresolved';
    foreach ($candidates as $step => $targets) {
        if (count($targets) > 1) {
            $outcome = 'ambiguous';
            $reachableAmbiguities[] = $entry + ['step' => $step, 'targets' => $targets];
            break;
        }
        if (count($targets) === 1) {
            $outcome = $step;
            break;
        }
    }
    $steps[$outcome]++;
}
echo json_encode([
    'files' => ['mondo' => $mondoPath, 'orphanet' => $orphaPath],
    'sha256' => ['mondo' => hash_file('sha256', $mondoPath), 'orphanet' => hash_file('sha256', $orphaPath)],
    'orphanet_date' => $orphaDate,
    'records' => ['mondo' => count($mondo), 'orphanet' => count($orpha)],
    'multiple_exact_counts' => array_map('count', $multiple),
    'inverse_collision_counts' => array_map('count', $collisions),
    'unvalidated_exact_orphanet_reference_count' => count($unvalidatedExact),
    'orphanet_resolution_counts' => $steps,
    'multiple_exact_matches' => $multiple,
    'inverse_collisions' => $collisions,
    'multiple_bridge_targets' => $bridgeMultiple,
    'reachable_ambiguities' => $reachableAmbiguities,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
