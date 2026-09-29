<?php

namespace App\Services;

/** Explicit source assertions only; none of these are exact-match equivalences. */
class DiseaseReplacementImport
{
    public static function omimEntries(string $data): array
    {
        $entries = [];
        foreach (explode("\n", $data) as $line) {
            $columns = explode("\t", trim($line));
            if (count($columns) >= 3 && preg_match('/^[0-9]{6}$/D', $columns[1])) {
                $entries['OMIM:'.$columns[1]] = ['prefix' => $columns[0], 'title' => $columns[2]];
            }
        }

        return $entries;
    }

    public static function omim(string $prefix, string $title, array $entries): array
    {
        $result = ['replaced_by' => [], 'replacement_details' => [], 'replacement_parse_status' => 'none'];
        if ($prefix !== 'Caret' || ! str_contains($title, 'MOVED TO')) {
            return $result;
        }
        $title = trim(preg_replace('/\s+/', ' ', $title));
        // Whole recognized titles only: do not silently accept a partial/misleading move.
        if (! preg_match('/^MOVED TO (?:[0-9]{6}|\{[0-9]{6}\}|[0-9]{6} AND [0-9]{6}|[0-9]{6}, [0-9]{6}, AND [0-9]{6})$/D', $title)) {
            $result['replacement_parse_status'] = 'unrecognized';

            return $result;
        }
        preg_match_all('/[0-9]{6}/', $title, $matches);
        $result['replaced_by'] = array_values(array_unique(array_map(fn ($id) => 'OMIM:'.$id, $matches[0])));
        foreach ($result['replaced_by'] as $curie) {
            if (isset($entries[$curie])) {
                $result['replacement_details'][$curie] = $entries[$curie];
            }
        }
        $result['replacement_parse_status'] = 'parsed';

        return $result;
    }

    public static function orphanet(\SimpleXMLElement $node): array
    {
        $targets = [];
        foreach ($node->DisorderDisorderAssociationList->DisorderDisorderAssociation ?? [] as $association) {
            if ((string) $association->DisorderDisorderAssociationType['id'] !== '21471'
                || (string) $node['id'] === ''
                || (string) $association->RootDisorder['id'] !== (string) $node['id']) {
                continue;
            }
            $code = (string) $association->TargetDisorder->OrphaCode;
            if (preg_match('/^[0-9]+$/D', $code)) {
                $targets[] = 'Orphanet:'.$code;
            }
        }

        return ['replaced_by' => array_values(array_unique($targets))];
    }
}
