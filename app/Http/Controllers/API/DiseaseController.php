<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Disease;
use App\Services\DiseaseMappingAmbiguity;

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
        $resolver = Disease::resolver();
        $resolution = $resolver->resolveDetailed($id);
        $advice = new \App\Services\DiseaseReplacementRecommendations($resolver);
        $contexts = ['submitted term' => $id, 'mapped MONDO' => $resolution instanceof \App\Services\DiseaseResolution ? $resolution->mondo->curie : null];
        $advice->preload(array_values($contexts));
        $metadata = ['submitted_id' => $id, 'disease_recommendations' => $advice->forContexts($contexts)];

        if ($resolution === null || $resolution instanceof DiseaseMappingAmbiguity)
            return response()->json($metadata + ['success' => 'false',
                'status_code' => 3001,
                'message' => $resolution instanceof DiseaseMappingAmbiguity ? $resolution->message($id) : 'Disease not found'],
                200);

        return $resolution->mondo->only(['curie', 'name', 'description']) + $metadata;
    }
}
