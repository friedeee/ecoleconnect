<?php

namespace App\Http\Controllers;

use App\Enums\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SwitchSectionController extends Controller
{
    /**
     * Focus a whole-school user on one section, or on the whole school again.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->worksForWholeSchool(), 403);

        $validated = $request->validate([
            'section' => ['nullable', Rule::enum(Section::class)->only($request->user()->school->sections())],
        ]);

        $request->session()->put('section', $validated['section'] ?? null);

        return redirect()->route('dashboard');
    }
}
