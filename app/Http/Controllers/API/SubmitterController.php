<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Submitter;
use Auth;
use DB;

class SubmitterController extends Controller
{
    // Logo specifications
    const LOGO_WIDTH = 800;
    const LOGO_HEIGHT = 400;
    const LOGO_MAX_SIZE = 512000; // 500KB in bytes

    /**
     * Update the specified submitter.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Unauthorized'
            ], 200);
        }

        $submitter = Submitter::find($id);

        if ($submitter === null) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3003,
                'message' => 'Submitter not found'
            ], 200);
        }

        // Check if user belongs to this submitter or is GenCC admin
        if ($user->submitter_id != $submitter->id && !$user->isGenccAdmin()) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Unauthorized'
            ], 200);
        }

        // Validate request
        $validated = $request->validate([
            'name' => 'required|string|max:248',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:500',
            'assertion' => 'nullable|url|max:500',
            'logo' => 'nullable|file|max:500',
            'remove_logo' => 'nullable',
            'contact_id' => 'nullable|integer|exists:users,id',
            'allow_submissions' => 'nullable',
            'downloadable' => 'nullable',
        ]);

        // Update submitter fields
        $submitter->name = $validated['name'];
        $submitter->description = $validated['description'] ?? null;
        $submitter->website = $validated['website'] ?? null;
        $submitter->assertion = $validated['assertion'] ?? null;

        // Handle logo removal
        if ($request->has('remove_logo') && $request->remove_logo) {
            $submitter->logo = null;
            $submitter->logo_contents = null;
            $submitter->logo_mime_type = null;
        }
        // Handle logo upload - store binary in database
        elseif ($request->hasFile('logo')) {
            $file = $request->file('logo');

            // Validate PNG format
            $mimeType = $file->getMimeType();
            if ($mimeType !== 'image/png') {
                return response()->json([
                    'success' => 'false',
                    'status_code' => 3006,
                    'message' => 'Logo must be a PNG image'
                ], 200);
            }

            // Validate dimensions
            $imageInfo = getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                return response()->json([
                    'success' => 'false',
                    'status_code' => 3006,
                    'message' => 'Could not read image dimensions'
                ], 200);
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width !== self::LOGO_WIDTH || $height !== self::LOGO_HEIGHT) {
                return response()->json([
                    'success' => 'false',
                    'status_code' => 3006,
                    'message' => "Logo must be exactly " . self::LOGO_WIDTH . "x" . self::LOGO_HEIGHT . " pixels. Uploaded image is {$width}x{$height} pixels."
                ], 200);
            }

            // Read file contents and encode as base64
            $contents = file_get_contents($file->getRealPath());
            $base64 = base64_encode($contents);

            // Store in database
            $submitter->logo_contents = $base64;
            $submitter->logo_mime_type = $mimeType;

            // Clear the old path-based logo field
            $submitter->logo = null;
        }

        // Handle contact update
        if ($request->has('contact_id')) {
            // Clear existing contacts for this submitter
            DB::table('submitter_user')
                ->where('submitter_id', $submitter->id)
                ->update(['is_contact' => false]);

            // Set new contact if provided
            if ($request->contact_id) {
                DB::table('submitter_user')
                    ->where('submitter_id', $submitter->id)
                    ->where('user_id', $request->contact_id)
                    ->update(['is_contact' => true]);
            }
        }

        // Handle allow_submissions and downloadable flags (admin-only)
        if ($user->isGenccAdmin()) {
            if ($request->has('allow_submissions')) {
                $submitter->allow_submissions = filter_var($request->allow_submissions, FILTER_VALIDATE_BOOLEAN);
            }
            if ($request->has('downloadable')) {
                $submitter->downloadable = filter_var($request->downloadable, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $submitter->save();

        return response()->json([
            'success' => 'true',
            'status_code' => 200,
            'message' => 'Submitter Updated'
        ], 200);
    }
}
