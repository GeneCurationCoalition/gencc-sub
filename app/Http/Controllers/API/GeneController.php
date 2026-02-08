<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Gene;

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
 * API/GeneController allows the user to look up a gene by HGNC ID or
 * symbol name and return relevant properties.  
 *
 * */
class GeneController extends Controller
{
    /**
     * Display the specified gene by HGNC ID or symbol.
     */
    public function show(string $id)
    {
        if (strpos($id, 'HGNC:') === 0)
            $gene = Gene::hgnc_id($id)->first();
        else
            $gene = Gene::lookup($id);
        
        if ($gene === null)
            return response()->json(['success' => 'false',
                'status_code' => 3001,
                'message' => 'Gene not found'],
                200);

        return $gene->only(['hgnc_id', 'symbol', 'description']);
    }
}
