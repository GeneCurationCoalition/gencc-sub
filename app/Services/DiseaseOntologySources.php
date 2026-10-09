<?php

namespace App\Services;

use App\Models\StaticFileHeader;

class DiseaseOntologySources
{
    public const MONDO = 'mondo_with_equivalents:replacements-v1';

    public const OMIM = 'omim_mimTitles:replacements-v1';

    public const ORPHANET = 'orphanet_product1:replacements-v1';

    public const LEGACY = [
        self::MONDO => 'mondo_with_equivalents',
        self::OMIM => 'omim_mimTitles',
        self::ORPHANET => 'orphanet_product1',
    ];

    public static function metadata(): array
    {
        $headers = StaticFileHeader::whereIn('file_identifier', array_merge(array_keys(self::LEGACY), array_values(self::LEGACY)))
            ->orderByDesc('id')->get()->unique('file_identifier')->keyBy('file_identifier');
        $result = [];
        foreach (self::LEGACY as $current => $legacy) {
            $header = $headers->get($current) ?? $headers->get($legacy);
            $result[] = [
                'importer_identity' => $current,
                'header_context' => $headers->has($current) ? 'current' : ($header ? 'legacy' : 'missing'),
                'replacement_import_confirmed' => false, // Headers alone never certify completion.
            ] + ($header?->only(['file_identifier', 'etag', 'last_modified', 'content_length', 'created_at'])
                ?? ['file_identifier' => $current]);
        }

        return $result;
    }

    /** Whether a source file was imported after an audit recorded $recorded from metadata(). */
    public static function changedSince(?array $recorded): bool
    {
        $identity = fn (array $sources) => collect($sources)
            ->map(fn ($source) => collect($source)->only(['importer_identity', 'file_identifier', 'etag', 'last_modified', 'content_length'])->all())
            ->sortBy('importer_identity')->values()->all();

        return $identity($recorded ?? []) != $identity(self::metadata());
    }
}
