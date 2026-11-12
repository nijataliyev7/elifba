<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\CreateClass;
use App\Domain\Classroom\Actions\DeleteClass;
use App\Domain\Classroom\Actions\UpdateClass;
use App\Domain\Classroom\Data\AddStudentsData;
use App\Domain\Classroom\Data\ClassData;
use App\Domain\Classroom\Data\NameListData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\AzCollator;
use App\Domain\Play\Support\PlayerVoice;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's classes and the class page (students, learned letters,
 * settings). Lists reach React already sorted: classes by `AzCollator`,
 * students by their position. An active student's row says whether the
 * board says the name aloud (`has_voice`, ADR 016 decision 10: null while
 * the account says no name), and how Elifba's name list spells a name typed
 * otherwise (`voice_spelling`, «Lale» → «Lalə», null when it is spelled so).
 */
final class ClassController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        $classes = SchoolClass::query()
            ->ownedBy($user)
            ->withCount(['students' => fn (Builder $query) => $query->whereNull('archived_at')])
            ->get();

        $rows = AzCollator::sortBy($classes, static fn (SchoolClass $class): string => $class->name);

        return Inertia::render('classes/index', [
            'classes' => $this->classRows(array_filter($rows, static fn (SchoolClass $class): bool => $class->archived_at === null)),
            'archivedClasses' => $this->classRows(array_filter($rows, static fn (SchoolClass $class): bool => $class->archived_at !== null)),
            'retentionDays' => Config::integer('elifba.retention_days'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, CreateClass $createClass): RedirectResponse
    {
        $class = $createClass->handle($user, ClassData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.created')]);

        return to_route('classes.show', $class);
    }

    public function show(SchoolClass $class, #[CurrentUser] User $teacher, PlayerVoice $voice): Response
    {
        $students = $class->students()->active()->ordered()->get();
        $archived = AzCollator::sortBy(
            $class->students()->archived()->get(),
            static fn (Student $student): string => $student->display_name,
        );

        return Inertia::render('classes/show', [
            'schoolClass' => [
                'id' => $class->id,
                'name' => $class->name,
                'grade' => $class->grade,
                'archived' => $class->archived_at !== null,
                'learned_letters' => $class->learned_letters ?? Alphabet::letters(),
            ],
            'students' => $students->map(fn (Student $student): array => [
                ...$this->studentRow($student),
                'has_voice' => $voice->says($teacher, $student->display_name),
                'voice_spelling' => $voice->spelling($teacher, $student->display_name),
            ])->values()->all(),
            'archivedStudents' => array_map($this->studentRow(...), $archived),
            'alphabet' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                Alphabet::letters(),
            ),
            'avatars' => Avatars::all(),
            'retentionDays' => Config::integer('elifba.retention_days'),
            'limits' => [
                'paste_length' => NameListData::MAX_LENGTH,
                'names' => AddStudentsData::MAX_NAMES,
            ],
        ]);
    }

    public function update(Request $request, SchoolClass $class, UpdateClass $updateClass): RedirectResponse
    {
        $updateClass->handle($class, ClassData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.updated')]);

        return to_route('classes.show', $class);
    }

    public function destroy(SchoolClass $class, DeleteClass $deleteClass): RedirectResponse
    {
        $deleteClass->handle($class);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.deleted')]);

        return to_route('classes.index');
    }

    /**
     * @param  iterable<SchoolClass>  $classes
     * @return list<array{id: string, name: string, grade: int|null, students_count: int}>
     */
    private function classRows(iterable $classes): array
    {
        $rows = [];

        foreach ($classes as $class) {
            $rows[] = [
                'id' => $class->id,
                'name' => $class->name,
                'grade' => $class->grade,
                'students_count' => (int) $class->getAttribute('students_count'),
            ];
        }

        return $rows;
    }

    /**
     * @return array{id: string, display_name: string, avatar: string}
     */
    private function studentRow(Student $student): array
    {
        return [
            'id' => $student->id,
            'display_name' => $student->display_name,
            'avatar' => $student->avatar,
        ];
    }
}
