<?php

namespace App\Http\Controllers\Family;

use App\Domain\Family\Actions\UpdateChildLetters;
use App\Domain\Family\Data\ChildLettersData;
use App\Domain\Family\Models\Child;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Saves the letters a child has learned (docs/architecture.md §3 "Learned
 * letters", ADR 011).
 */
final class ChildLettersController extends Controller
{
    public function __invoke(Request $request, Child $child, UpdateChildLetters $updateChildLetters): RedirectResponse
    {
        $updateChildLetters->handle($child, ChildLettersData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('children.flash.letters_saved')]);

        return to_route('children.show', $child);
    }
}
