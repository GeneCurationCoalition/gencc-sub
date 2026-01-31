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
                break;
            case 'profile':
                // Update profile information (name, title, email, phone)
                $user->add_name($request->input('name'));
                $user->title = $request->input('title');
                $user->email = $request->input('email');
                $user->phone = $request->input('phone');
                break;
            case 'passwd':
                $oldPassword = $request->input('old');
                $newPassword = $request->input('new');

                // Verify old password
                if (!Hash::check($oldPassword, $user->password))
                    return response()->json(['success' => 'false',
                        'status_code' => 3004,
                        'message' => 'The current password you entered is incorrect.'],
                        200);

                // Validate new password requirements
                $errors = [];
                if (strlen($newPassword) < 8) {
                    $errors[] = 'Password must be at least 8 characters';
                }
                if (!preg_match('/[A-Z]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one uppercase letter';
                }
                if (!preg_match('/[a-z]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one lowercase letter';
                }
                if (!preg_match('/[0-9]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one number';
                }
                if ($oldPassword === $newPassword) {
                    $errors[] = 'New password cannot be the same as old password';
                }

                if (!empty($errors)) {
                    return response()->json(['success' => 'false',
                        'status_code' => 3005,
                        'message' => implode('. ', $errors)],
                        200);
                }

                $user->password = $user->make_password($newPassword);
                $user->must_change_password = false;
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
