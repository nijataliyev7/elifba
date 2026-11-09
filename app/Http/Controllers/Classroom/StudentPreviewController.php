<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Data\NameListData;
use App\Domain\Classroom\Data\PastedNameData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Support\NameListParser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The paste preview (docs/architecture.md §7): suggestions for a pasted list
 * of names, against the class's active students. It writes nothing.
 *
 * The pasted text holds surnames and patronymics, so it never leaves this
 * request: it is a POST body (never a URL), the answer is JSON in both cases
 * — a validation error included — so no redirect flashes it into the
 * session, the input is validated before anything else touches it, and the
 * response is not cached.
 */
final class StudentPreviewController extends Controller
{
    public function __invoke(Request $request, SchoolClass $class, NameListParser $parser): JsonResponse
    {
        $validator = Validator::make($request->only('text'), NameListData::rules());

        if ($validator->fails()) {
            return $this->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        $data = NameListData::fromArray($validator->validated());

        return $this->json([
            'lines' => array_map(
                static fn (PastedNameData $line): array => $line->toArray(),
                $parser->parseForClass($class, $data->text),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store, private']);
    }
}
