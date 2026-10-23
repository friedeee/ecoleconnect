<?php

namespace App\Http\Controllers;

use App\Models\Homework;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class HomeworkFileController extends Controller
{
    /**
     * Download the worksheet attached to an exercise. Route binding keeps it within the user's school and section.
     */
    public function __invoke(Homework $homework): Response
    {
        abort_if($homework->attachment_path === null, 404);

        return Storage::disk('local')->download($homework->attachment_path, $homework->attachment_name);
    }
}
