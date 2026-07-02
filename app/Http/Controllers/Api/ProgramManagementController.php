<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\{
    Destination,
    Institution,
    ProgramLevel,
    ProgramLevelCategory,
    FieldOfStudy,
    Intake,
    Agent,
    Student,
    Application,
    MeetingRegistration
};

class ProgramManagementController extends Controller
{
    /*** ---------------- DESTINATIONS ---------------- ***/
    
    public function getDestinations()
    {
        return response()->json(Destination::all(), 200);
    }

    public function createDestination(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $destination = Destination::create($request->only(['name', 'description']));
        return response()->json(['message' => 'Destination created', 'destination' => $destination], 201);
    }

    public function updateDestination(Request $request, $id)
    {
        $destination = Destination::findOrFail($id);
        $destination->update($request->only(['name', 'description']));
        return response()->json(['message' => 'Destination updated', 'destination' => $destination]);
    }

    public function deleteDestination($id)
    {
        Destination::findOrFail($id)->delete();
        return response()->json(['message' => 'Destination deleted']);
    }

    /*** ---------------- INSTITUTIONS ---------------- ***/

    public function getInstitutions()
    {
        return response()->json(Institution::with('destination', 'programLevels')->get(), 200);
    }

    public function createInstitution(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string|max:255',
            'tuition' => 'nullable|string|max:255',
            'application_fee' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:255',
            'can_take_loan' => 'nullable|string|in:Yes,No',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'success_chance' => 'nullable|string|in:High,Medium,Low',
            'success_details' => 'nullable|string|max:255',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,gif,webp|max:5120',
        ]);

        $data = $request->only(['name','destination_id','city','tuition','application_fee','duration','can_take_loan','success_chance','success_details']);
        $data['tags'] = $request->input('tags');
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $data['logo_path'] = $path;
            $data['logo_url'] = asset('storage/'.$path);
        }

        $institution = Institution::create($data);
        return response()->json(['message' => 'Institution created', 'institution' => $institution], 201);
    }

    public function updateInstitution(Request $request, $id)
    {
        $institution = Institution::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string|max:255',
            'tuition' => 'nullable|string|max:255',
            'application_fee' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:255',
            'can_take_loan' => 'nullable|string|in:Yes,No',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'success_chance' => 'nullable|string|in:High,Medium,Low',
            'success_details' => 'nullable|string|max:255',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,gif,webp|max:5120',
        ]);

        $data = $request->only(['name','destination_id','city','tuition','application_fee','duration','can_take_loan','success_chance','success_details']);
        $data['tags'] = $request->input('tags');
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $data['logo_path'] = $path;
            $data['logo_url'] = asset('storage/'.$path);
        }

        $institution->update($data);
        return response()->json(['message' => 'Institution updated', 'institution' => $institution]);
    }

    public function deleteInstitution($id)
    {
        Institution::findOrFail($id)->delete();
        return response()->json(['message' => 'Institution deleted']);
    }

    public function assignProgramLevelsToInstitution(Request $request, $id)
    {
        $request->validate([
            'program_level_ids' => 'array',
            'program_level_ids.*' => 'integer|exists:program_levels,id',
        ]);

        $institution = Institution::findOrFail($id);
        $institution->programLevels()->sync($request->input('program_level_ids', []));

        $institution->load('destination', 'programLevels');
        return response()->json(['message' => 'Program levels assigned', 'institution' => $institution]);
    }

    /**
     * Get fields of study assigned for a specific Institution + Program Level pair
     */
    public function getFieldsForInstitutionProgramLevel($institutionId, $programLevelId)
    {
        // Validate that institution and program level exist
        $institution = Institution::findOrFail($institutionId);
        $programLevel = ProgramLevel::findOrFail($programLevelId);

        // Ensure the program level is assigned to the institution
        if (!$institution->programLevels()->where('program_level_id', $programLevelId)->exists()) {
            return response()->json(['fields' => [], 'message' => 'Program level not assigned to institution'], 200);
        }

        $fieldIds = DB::table('institution_program_level_fields')
            ->where('institution_id', $institutionId)
            ->where('program_level_id', $programLevelId)
            ->pluck('field_id')
            ->toArray();

        return response()->json(['fields' => $fieldIds], 200);
    }

    /**
     * Assign fields of study for a specific Institution + Program Level pair
     */
    public function assignFieldsForInstitutionProgramLevel(Request $request, $institutionId, $programLevelId)
    {
        $request->validate([
            'field_ids' => 'array',
            'field_ids.*' => 'integer|exists:fields_of_study,id',
        ]);

        $institution = Institution::findOrFail($institutionId);
        ProgramLevel::findOrFail($programLevelId);

        // Ensure the program level is assigned to the institution
        if (!$institution->programLevels()->where('program_level_id', $programLevelId)->exists()) {
            return response()->json(['message' => 'Program level not assigned to institution'], 422);
        }

        DB::transaction(function () use ($institutionId, $programLevelId, $request) {
            DB::table('institution_program_level_fields')
                ->where('institution_id', $institutionId)
                ->where('program_level_id', $programLevelId)
                ->delete();

            $fieldIds = $request->input('field_ids', []);
            if (!empty($fieldIds)) {
                $now = now();
                $rows = array_map(function ($fieldId) use ($institutionId, $programLevelId, $now) {
                    return [
                        'institution_id' => $institutionId,
                        'program_level_id' => $programLevelId,
                        'field_id' => $fieldId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $fieldIds);
                DB::table('institution_program_level_fields')->insert($rows);
            }
        });

        return response()->json(['message' => 'Fields assigned for institution program level'], 200);
    }

    /**
     * Get intakes assigned for Institution + Program Level + Field
     */
    public function getIntakesForInstitutionProgramLevelField($institutionId, $programLevelId, $fieldId)
    {
        $institution = Institution::findOrFail($institutionId);
        ProgramLevel::findOrFail($programLevelId);
        FieldOfStudy::findOrFail($fieldId);

        // Ensure the program level is assigned to the institution and field is assigned to that pair
        $plAssigned = $institution->programLevels()->where('program_level_id', $programLevelId)->exists();
        if (!$plAssigned) {
            return response()->json(['intakes' => [], 'message' => 'Program level not assigned to institution'], 200);
        }

        $fieldAssigned = DB::table('institution_program_level_fields')
            ->where('institution_id', $institutionId)
            ->where('program_level_id', $programLevelId)
            ->where('field_id', $fieldId)
            ->exists();
        if (!$fieldAssigned) {
            return response()->json(['intakes' => [], 'message' => 'Field not assigned to institution program level'], 200);
        }

        $intakeIds = DB::table('institution_program_level_field_intakes')
            ->where('institution_id', $institutionId)
            ->where('program_level_id', $programLevelId)
            ->where('field_id', $fieldId)
            ->pluck('intake_id')
            ->toArray();

        return response()->json(['intakes' => $intakeIds], 200);
    }

    /**
     * Assign intakes for Institution + Program Level + Field
     */
    public function assignIntakesForInstitutionProgramLevelField(Request $request, $institutionId, $programLevelId, $fieldId)
    {
        $request->validate([
            'intake_ids' => 'array',
            'intake_ids.*' => 'integer|exists:intakes,id',
        ]);

        $institution = Institution::findOrFail($institutionId);
        ProgramLevel::findOrFail($programLevelId);
        FieldOfStudy::findOrFail($fieldId);

        // Ensure relationships exist first
        if (!$institution->programLevels()->where('program_level_id', $programLevelId)->exists()) {
            return response()->json(['message' => 'Program level not assigned to institution'], 422);
        }
        if (!DB::table('institution_program_level_fields')
            ->where('institution_id', $institutionId)
            ->where('program_level_id', $programLevelId)
            ->where('field_id', $fieldId)
            ->exists()) {
            return response()->json(['message' => 'Field not assigned to institution program level'], 422);
        }

        DB::transaction(function () use ($institutionId, $programLevelId, $fieldId, $request) {
            DB::table('institution_program_level_field_intakes')
                ->where('institution_id', $institutionId)
                ->where('program_level_id', $programLevelId)
                ->where('field_id', $fieldId)
                ->delete();

            $intakeIds = $request->input('intake_ids', []);
            if (!empty($intakeIds)) {
                $now = now();
                $rows = array_map(function ($intakeId) use ($institutionId, $programLevelId, $fieldId, $now) {
                    return [
                        'institution_id' => $institutionId,
                        'program_level_id' => $programLevelId,
                        'field_id' => $fieldId,
                        'intake_id' => $intakeId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $intakeIds);
                DB::table('institution_program_level_field_intakes')->insert($rows);
            }
        });

        return response()->json(['message' => 'Intakes assigned for institution program level field'], 200);
    }

    /*** ---------------- PROGRAM LEVELS ---------------- ***/

    public function getProgramLevels()
    {
        return response()->json(ProgramLevel::with('institutions', 'fields', 'categories', 'intakes')->get(), 200);
    }

    public function createProgramLevel(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $programLevel = ProgramLevel::create($request->only('name'));

        // Optionally attach relations
        if ($request->has('institution_ids')) {
            $programLevel->institutions()->sync($request->institution_ids);
        }
        if ($request->has('field_ids')) {
            $programLevel->fields()->sync($request->field_ids);
        }
        if ($request->has('category_ids')) {
            $programLevel->categories()->sync($request->category_ids);
        }
        if ($request->has('intake_ids')) {
            $programLevel->intakes()->sync($request->intake_ids);
        }

        return response()->json(['message' => 'Program Level created', 'programLevel' => $programLevel], 201);
    }

    public function updateProgramLevel(Request $request, $id)
    {
        $programLevel = ProgramLevel::findOrFail($id);
        $programLevel->update($request->only('name'));

        // Update relationships if provided
        if ($request->has('institution_ids')) {
            $programLevel->institutions()->sync($request->institution_ids);
        }
        if ($request->has('field_ids')) {
            $programLevel->fields()->sync($request->field_ids);
        }
        if ($request->has('category_ids')) {
            $programLevel->categories()->sync($request->category_ids);
        }
        if ($request->has('intake_ids')) {
            $programLevel->intakes()->sync($request->intake_ids);
        }

        return response()->json(['message' => 'Program Level updated', 'programLevel' => $programLevel]);
    }

    public function deleteProgramLevel($id)
    {
        ProgramLevel::findOrFail($id)->delete();
        return response()->json(['message' => 'Program Level deleted']);
    }

    /*** ---------------- PROGRAM LEVEL CATEGORIES ---------------- ***/

    public function getProgramLevelCategories()
    {
        return response()->json(ProgramLevelCategory::all(), 200);
    }

    public function createProgramLevelCategory(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $category = ProgramLevelCategory::create($request->only('name'));
        return response()->json(['message' => 'Category created', 'category' => $category], 201);
    }

    public function updateProgramLevelCategory(Request $request, $id)
    {
        $category = ProgramLevelCategory::findOrFail($id);
        $category->update($request->only('name'));
        return response()->json(['message' => 'Category updated', 'category' => $category]);
    }

    public function deleteProgramLevelCategory($id)
    {
        ProgramLevelCategory::findOrFail($id)->delete();
        return response()->json(['message' => 'Category deleted']);
    }

    /*** ---------------- FIELDS OF STUDY ---------------- ***/

    public function getFields()
    {
        return response()->json(FieldOfStudy::all(), 200);
    }

    public function createField(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $field = FieldOfStudy::create($request->only('name'));
        return response()->json(['message' => 'Field created', 'field' => $field], 201);
    }

    public function updateField(Request $request, $id)
    {
        $field = FieldOfStudy::findOrFail($id);
        $field->update($request->only('name'));
        return response()->json(['message' => 'Field updated', 'field' => $field]);
    }

    public function deleteField($id)
    {
        FieldOfStudy::findOrFail($id)->delete();
        return response()->json(['message' => 'Field deleted']);
    }

    /*** ---------------- INTAKES ---------------- ***/

    public function getIntakes()
    {
        return response()->json(Intake::all(), 200);
    }

    public function createIntake(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $intake = Intake::create($request->only('name'));
        return response()->json(['message' => 'Intake created', 'intake' => $intake], 201);
    }

    public function updateIntake(Request $request, $id)
    {
        $intake = Intake::findOrFail($id);
        $intake->update($request->only('name'));
        return response()->json(['message' => 'Intake updated', 'intake' => $intake]);
    }

    public function deleteIntake($id)
    {
        Intake::findOrFail($id)->delete();
        return response()->json(['message' => 'Intake deleted']);
    }

    /*** ---------------- DASHBOARD METRICS ---------------- ***/
    public function getDashboardMetrics()
    {
        $totalAgents = Agent::count();
        $totalStudents = Student::count();
        // Consider these as active; adjust status filter when available
        $activeApplications = Application::count();
        $totalPrograms = ProgramLevel::count();
        $meetingRegistrations = MeetingRegistration::count();

        // Placeholder change percentages; replace with real comparisons if desired
        return response()->json([
            'totalAgents' => $totalAgents,
            'totalAgentsChange' => '+0.0%',
            'totalAgentsTrend' => 'up',
            'totalStudents' => $totalStudents,
            'totalStudentsChange' => '+0.0%',
            'totalStudentsTrend' => 'up',
            'activeApplications' => $activeApplications,
            'activeApplicationsChange' => '+0.0%',
            'activeApplicationsTrend' => 'up',
            'totalPrograms' => $totalPrograms,
            'totalProgramsChange' => '+0.0%',
            'totalProgramsTrend' => 'up',
            'meetingRegistrations' => $meetingRegistrations,
            'meetingRegistrationsChange' => '+0.0%',
            'meetingRegistrationsTrend' => 'up',
        ], 200);
    }
}
