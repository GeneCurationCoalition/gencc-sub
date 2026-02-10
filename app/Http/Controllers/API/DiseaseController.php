<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Disease;

/**
 *
 * @category   Controller
 * @package    GenCC
 * @author     P. Weller <pweller1@geisinger.edu>
 * @copyright  2024 Geisinger, GenCC, ClinGen
 * @license    
 * @version    Release: @package_version@
 * @link       
 * @see        
 * @since      Class available since Release 1.0.0
 * 
 * The API\DiseaseController handles user requests to lookup a disease entity and return
 * relevant properties.
 *
 * */
class DiseaseController extends Controller
{
    /**
     * Display the specified disease by CURIE (MONDO, OMIM, etc).
     */
    public function show(string $id)
    {
        $disease = Disease::rosetta($id);

        if ($disease === null)
            return response()->json(['success' => 'false',
                'status_code' => 3001,
                'message' => 'Disease not found'],
                200);

        return $disease->only(['curie', 'name', 'description']);
    }
}
