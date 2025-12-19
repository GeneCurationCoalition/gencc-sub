<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

use Auth;

use App\Models\User;

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
 * UserController supplies the user data to Inertia/Vue.
 *
 * */
class UserController extends Controller
{
    /**
     * Pass user and submitter information to the profile page
     */
    public function show()
    {
        $user = Auth::user();

        $submitter = $user->submitter;

        return Inertia::render('Profile', [
                'user' => $user->only(['id', 'ident', 'name', 'credentials', 'email', 'preferences', 'clingen_id', 'api_token', 'api_token_renewed_at']),
                'submitter' => $submitter->only(['ident', 'curie', 'name'])
        ]);
    }

    /**
     * Set selected submitter for GenCC Administrator users
     */
    public function setSelectedSubmitter(Request $request)
    {
        $user = Auth::user();

        // Verify user is GenCC Administrator
        if (!$user || !$user->isGenccAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'submitter_id' => 'nullable|integer|exists:submitters,id',
            'redirect_to' => 'nullable|string',
            'clear' => 'nullable|string'
        ]);

        // Store selected submitter in session or clear it
        if ($request->has('clear') && $request->clear) {
            // Clear selection
            $request->session()->forget('selected_submitter_id');
        } elseif ($request->submitter_id) {
            // Set selection
            $request->session()->put('selected_submitter_id', $request->submitter_id);
        } else {
            // Clear if no submitter_id provided
            $request->session()->forget('selected_submitter_id');
        }

        // If redirect_to is provided, use it; otherwise go back
        if ($request->redirect_to) {
            return redirect($request->redirect_to);
        }

        return back();
    }
}
