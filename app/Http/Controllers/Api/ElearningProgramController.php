<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\ElearningProgram;
use App\Support\ApiListCache;
use App\Support\PlatformTenantScope;
use App\Services\CourseProgramAssignmentService;
use Illuminate\Http\Request;

class ElearningProgramController extends Controller
{
    public function index(Request $request)
    {
        $withCourses = $request->boolean('with_courses');
        $activeOnly = $request->boolean('active_only');
        $tenantId = PlatformTenantScope::resolveTenantId($request);
        $cacheKey = 'owned_' . ($tenantId !== null ? 'inst_' . $tenantId : 'hub')
            . ($withCourses ? '_with_courses' : ($activeOnly ? '_active' : '_all'));

        $programs = ApiListCache::remember('elearning_programs', $cacheKey, 120, function () use ($request, $withCourses, $activeOnly, $tenantId) {
            $query = ElearningProgram::query()
                ->orderBy('sort_order')
                ->orderBy('name');

            PlatformTenantScope::applyToQuery($query, $request);

            if ($activeOnly) {
                $query->where('status', 'Active');
            }

            if ($withCourses) {
                $query->with(['courses' => function ($q) use ($request, $activeOnly, $tenantId) {
                    PlatformTenantScope::applyToQuery($q, $request);
                    if ($activeOnly) {
                        $q->where('status', 'Active');
                    }
                    $q->orderBy('title');
                }]);
            }

            return $query->get();
        });

        return response()->json($programs, 200);
    }

    public function show(Request $request, ElearningProgram $elearningProgram)
    {
        PlatformTenantScope::assertCanAccess($request, $elearningProgram);
        $elearningProgram->load(['courses' => fn ($q) => $q->orderBy('title')]);

        return response()->json($elearningProgram, 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'image' => 'nullable',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $data['image'] = asset('storage/' . $path);
        }

        $data['status'] = $data['status'] ?? 'Active';
        $data['sort_order'] = $data['sort_order'] ?? 0;
        PlatformTenantScope::stampInstitutionId($request, $data);

        $program = ElearningProgram::create($data);

        $this->bumpCaches();

        return response()->json([
            'message' => 'Program created',
            'program' => $program,
        ], 201);
    }

    public function update(Request $request, ElearningProgram $elearningProgram)
    {
        PlatformTenantScope::assertCanAccess($request, $elearningProgram);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'image' => 'nullable',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $data['image'] = asset('storage/' . $path);
        }

        $elearningProgram->fill($data);
        $elearningProgram->save();

        $this->bumpCaches();

        return response()->json([
            'message' => 'Program updated',
            'program' => $elearningProgram,
        ]);
    }

    public function destroy(Request $request, ElearningProgram $elearningProgram)
    {
        PlatformTenantScope::assertCanAccess($request, $elearningProgram);
        if ($elearningProgram->courses()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a program that still has courses. Reassign or remove courses first.',
            ], 422);
        }

        $elearningProgram->delete();

        $this->bumpCaches();

        return response()->json(['message' => 'Program deleted']);
    }

    public function assignCourses(Request $request, ElearningProgram $elearningProgram, CourseProgramAssignmentService $assignment)
    {
        PlatformTenantScope::assertCanAccess($request, $elearningProgram);
        $data = $request->validate([
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        $tenantId = PlatformTenantScope::resolveTenantId($request);
        if ($tenantId !== null) {
            $foreignCount = Course::query()
                ->whereIn('id', $data['course_ids'])
                ->where(function ($q) use ($tenantId) {
                    $q->whereNull('platform_institution_id')
                        ->orWhere('platform_institution_id', '!=', $tenantId);
                })
                ->count();

            if ($foreignCount > 0) {
                return response()->json([
                    'message' => 'One or more courses belong to another institution.',
                ], 422);
            }
        }

        $result = $assignment->assignCoursesToProgram($elearningProgram->id, $data['course_ids']);
        $elearningProgram->load(['courses' => fn ($q) => $q->orderBy('title')]);

        return response()->json([
            'message' => "{$result['moved']} course(s) moved to {$elearningProgram->name}.",
            'moved' => $result['moved'],
            'program' => $elearningProgram,
        ]);
    }

    public function autoAssignCourses(Request $request, CourseProgramAssignmentService $assignment)
    {
        if (PlatformTenantScope::resolveTenantId($request) !== null) {
            return response()->json([
                'message' => 'Auto-assign is only available to the main platform administrator.',
            ], 403);
        }

        $data = $request->validate([
            'create_missing' => 'nullable|boolean',
            'force' => 'nullable|boolean',
        ]);

        $result = $assignment->autoAssignByKeywords(
            (bool) ($data['create_missing'] ?? true),
            (bool) ($data['force'] ?? false)
        );

        return response()->json([
            'message' => $result['assigned'] > 0
                ? "{$result['assigned']} course(s) assigned by keyword rules."
                : 'No courses needed reassignment.',
            'assigned' => $result['assigned'],
            'summary' => $result['summary'],
            'details' => $result['details'],
        ]);
    }

    protected function bumpCaches(): void
    {
        ApiListCache::bump('elearning_programs');
        ApiListCache::bump('courses');
    }
}
