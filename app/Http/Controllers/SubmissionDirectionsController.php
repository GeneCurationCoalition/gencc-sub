<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;

/**
 * SubmissionDirectionsController
 *
 * Handles the submission directions page which provides guidance
 * on how to submit data to GenCC.
 */
class SubmissionDirectionsController extends Controller
{
    /**
     * Display the submission directions page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Inertia\Response
     */
    public function index(Request $request)
    {
        return Inertia::render('SubmissionDirections');
    }
}
