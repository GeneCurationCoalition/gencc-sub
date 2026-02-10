<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class GenccImport implements ToCollection, WithHeadingRow
{
    /**
     * Required by ToCollection interface but not used when using Excel::toCollection()
     * The collection is returned directly by that method.
     *
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        // No-op: data is accessed via Excel::toCollection() return value
    }
}
