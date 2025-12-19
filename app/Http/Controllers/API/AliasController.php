<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\AliasRequest;

use Auth;

use App\Models\Alias;

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
 * The API\AliasController handles all user requests for creating, updating, and removing
 * an alias.  Users may modify their own aliases or that of any other member associated 
 * with the same submitter.
 *
 * */
class AliasController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }


    /**
     * Update the specified resource in storage.
     * 
     * @param string $is
     * @return json
     */
    public function update(AliasRequest $request, string $id = null)
    {
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // confirm authentication or ownership (CREATE means new)
        $alias = ($id == 'CREATE' ? new Alias(['shared' => true,
                                               'user_id' => Auth::user()->id,
                                              'submitter_id' => $effectiveSubmitterId,
                                              'status' => 1]) :
                                        $this->getEffectiveSubmitterQuery($request, 'aliases')
                                            ->where('ident', $id)
                                            ->first());

        if ($alias === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // currently, only the modify action is recognized
        switch ($request->input('type'))
        {
            case 'modify':
                // make sure the type-key combo is not already used
                if ($this->getEffectiveSubmitterQuery($request, 'aliases')->where('type', $request->input('class'))
                                ->where('key', $request->input('name'))
                                ->where('ident', '!=', $id)->exists())
                            return response()->json(['success' => 'false',
                                    'status_code' => 3001,
                                    'message' => 'Alias already used'],
                                    200);
                break;
        }

        // set the new values and save
        $alias->type = $request->input('class');
        $alias->key = $request->input('name');
        $alias->value = $request->input('value');
        $alias->save();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Alias Updated'],
                200);
    }


    /**
     * Remove the specified resource from storage.
     * 
     * @param string $is
     * @return json
     */
    public function destroy(Request $request, string $id)
    {
        // confirm authentication and ownership
        $alias = $this->getEffectiveSubmitterQuery($request, 'aliases')
                        ->where('ident', $id)->first();

        if ($alias === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // now remove it
        $alias->delete();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Alias Removed'],
                200);
    }
}
