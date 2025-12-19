<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Pubmed;
use Illuminate\Http\Request;

/**
 * PubMed API Controller
 *
 * Provides API endpoints for retrieving PubMed article information
 * for use by external systems like gencc-search
 */
class PubmedController extends Controller
{
    /**
     * Get PubMed article(s) by PMID(s)
     *
     * Accepts either a single PMID or comma-separated list of PMIDs
     * Returns basic article information (title, authors, abstract, etc.)
     *
     * @param string $pmids Comma-separated PubMed IDs (e.g., "12345,67890")
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($pmids)
    {
        // Split comma-separated PMIDs
        $pmidArray = array_map('trim', explode(',', $pmids));

        // Limit to 200 PMIDs per request (same as NCBI batch limit)
        if (count($pmidArray) > 200) {
            return response()->json([
                'error' => 'Maximum 200 PMIDs allowed per request',
                'requested' => count($pmidArray)
            ], 400);
        }

        // Query for the PMIDs - include ACTIVE, SUMMARY_COMPLETE, and INITIALIZING statuses
        // All these statuses have the basic required data (title, authors, pubdate)
        $articles = Pubmed::whereIn('pmid', $pmidArray)
            ->whereIn('status', [Pubmed::STATUS_ACTIVE, Pubmed::STATUS_SUMMARY_COMPLETE, Pubmed::STATUS_INITIALIZING])
            ->get()
            ->map(function ($article) {
                // Extract first author and year
                $firstAuthor = '';
                $year = '';

                if (!empty($article->authors)) {
                    // Authors can be stored as JSON array or comma-separated string
                    if (is_string($article->authors) && str_starts_with(trim($article->authors), '[')) {
                        // Parse JSON format: [{"name":"Vissers LE",...},...]
                        $authorsArray = json_decode($article->authors, true);
                        if (!empty($authorsArray) && isset($authorsArray[0]['name'])) {
                            $firstAuthor = $authorsArray[0]['name'];
                        }
                    } else {
                        // Parse comma-separated format
                        $authorParts = explode(',', $article->authors);
                        $firstAuthor = trim($authorParts[0]);
                    }
                }

                if (!empty($article->pubdate)) {
                    // Extract year from pubdate (format varies: "2015", "2015 Jan", "2015 Jan 15", etc.)
                    preg_match('/(\d{4})/', $article->pubdate, $matches);
                    $year = $matches[1] ?? '';
                }

                return [
                    'pmid' => $article->pmid,
                    'title' => $article->title,
                    'authors' => $article->authors,
                    'firstAuthor' => $firstAuthor,
                    'lastauthor' => $article->lastauthor,
                    'abstract' => $article->abstract,
                    'pubdate' => $article->pubdate,
                    'year' => $year,
                    'source' => $article->source,
                    'volume' => $article->volume,
                    'issue' => $article->issue,
                    'pages' => $article->pages,
                    'fulljournalname' => $article->fullfournalname,
                ];
            });

        // Return 404 if no articles found
        if ($articles->isEmpty()) {
            return response()->json([
                'error' => 'No articles found',
                'requested_pmids' => $pmidArray
            ], 404);
        }

        // If single PMID requested, return object; otherwise return array
        if (count($pmidArray) === 1) {
            return response()->json($articles->first());
        }

        return response()->json([
            'count' => $articles->count(),
            'articles' => $articles
        ]);
    }

    /**
     * Search PubMed articles by title or author
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $limit = min((int) $request->input('limit', 10), 100); // Max 100 results

        if (empty($query)) {
            return response()->json([
                'error' => 'Search query required (use ?q=search+terms)'
            ], 400);
        }

        $articles = Pubmed::whereIn('status', [Pubmed::STATUS_ACTIVE, Pubmed::STATUS_SUMMARY_COMPLETE])
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('authors', 'like', "%{$query}%");
            })
            ->limit($limit)
            ->get()
            ->map(function ($article) {
                return [
                    'pmid' => $article->pmid,
                    'title' => $article->title,
                    'authors' => $article->authors,
                    'pubdate' => $article->pubdate,
                    'source' => $article->source,
                ];
            });

        return response()->json([
            'query' => $query,
            'count' => $articles->count(),
            'results' => $articles
        ]);
    }
}
