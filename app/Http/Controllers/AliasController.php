<?php

namespace App\Http\Controllers;
use Inertia\Inertia;

use Illuminate\Http\Request;

use Auth;

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
 * AliasController supplies the alias data to Inertia/Vue.  
 *
 * */
class AliasController extends Controller
{

    /**
     * List all the aliases for the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $aliases = $this->getEffectiveSubmitterQuery($request, 'aliases')->get();

        return Inertia::render('Aliases', [
            'aliases' => $aliases
        ]);
    }


    /**
     * Show a particular alias for the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {

        //
    }
}
