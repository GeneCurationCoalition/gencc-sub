<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

use App\Models\Nodal;

class SubmissionImport implements ToCollection, WithHeadingRow
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        $rownum = 0;

        foreach ($collection as $row) 
            // because the header is not really a header, we have to skip over a few more lines
            if ($rownum++ > 5)
                return new Nodal($row->toArray());
    }


    /**
     * Set the line that contains the header row.  
     * 
     * NOTE:  Lines start at 0.
     * 
     */
    public function headingRow(): int
    {
        return 6;
    }
}
