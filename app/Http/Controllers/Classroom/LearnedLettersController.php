<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\UpdateLearnedLetters;
use App\Domain\Classroom\Data\LearnedLettersData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Saves the letters a class has learned (docs/architecture.md §3).
 */
final class LearnedLettersController extends Controller
{
    public function __invoke(Request $request, SchoolClass $class, UpdateLearnedLetters $updateLearnedLetters): RedirectResponse
    {
        $updateLearnedLetters->handle($class, LearnedLettersData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.letters_saved')]);

        return to_route('classes.show', $class);
    }
}
