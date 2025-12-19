<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Auth;
use Hash;
use Carbon\Carbon;

class UserController extends Controller
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
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();

        if ($user === null || $user->id != $id)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);


        switch ($request->input('type'))
        {
            case "refresh_token":
                //$user->add_api_token();
                $user->api_token_renewed_at = Carbon::now();
            case 'name':
                $user->add_name($request->input('name'));
                $user->credentials = $request->input('credentials');
                break;
            case 'notify':
                if ($user->preferences === null)
                    $preferences = $user->initialize_preferences();
                else
                    $preferences = $user->preferences;
                $preferences['notify'] = $request->input('new');
                $user->update(['preferences' => $preferences]);
                break;
            case 'passwd':
                if (!Hash::check($request->input('old'), $user->password))
                    return response()->json(['success' => 'false',
                        'status_code' => 3004,
                        'message' => 'Unauthorized'],
                        200);
                $user->password = $user->make_password($request->input('new'));
                break;
            default:
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);
        }

        $user->save();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'User Updated'],
                200);
                        
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
