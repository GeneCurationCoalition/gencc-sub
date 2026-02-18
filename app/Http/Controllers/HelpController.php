<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class HelpController extends Controller
{
    /**
     * Display the Help & Documentation page.
     */
    public function index()
    {
        $user = Auth::user();
        $isAdmin = $user && $user->isGenccAdmin();

        return Inertia::render('Help', [
            'isAdmin' => $isAdmin,
            'documentMeta' => $this->getDocumentMeta(),
        ]);
    }

    /**
     * Get metadata for all managed documents.
     */
    private function getDocumentMeta(): array
    {
        $documents = [
            'user-guide' => 'UserGuide.pdf',
            'api-guide' => 'APIGuide.pdf',
            'spreadsheet' => 'GenCC Submission Spreadsheet.xlsx',
        ];

        $meta = [];

        foreach ($documents as $key => $filename) {
            $path = public_path('documents/' . $filename);

            if (file_exists($path)) {
                $mtime = filemtime($path);
                $meta[$key] = [
                    'filename' => $filename,
                    'size' => filesize($path),
                    'sizeFormatted' => $this->formatFileSize(filesize($path)),
                    'lastModified' => date('Y-m-d H:i:s', $mtime),
                    'cacheBuster' => $mtime, // Unix timestamp for cache-busting URLs
                ];
            } else {
                $meta[$key] = null;
            }
        }

        return $meta;
    }

    /**
     * Format file size in human-readable format.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
