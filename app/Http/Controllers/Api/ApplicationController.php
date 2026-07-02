<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function index(Request $request)
    {
        $query = Application::with(['student','institution','intake']);

        if ($request->filled('first_name')) {
            $query->where('first_name', 'like', '%'.$request->input('first_name').'%');
        }

        if ($request->filled('last_name')) {
            $query->where('last_name', 'like', '%'.$request->input('last_name').'%');
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('apply_date_from')) {
            $query->whereDate('created_at', '>=', $request->input('apply_date_from'));
        }

        if ($request->filled('apply_date_to')) {
            $query->whereDate('created_at', '<=', $request->input('apply_date_to'));
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required','exists:students,id'],
            'institution_id' => ['required','exists:institutions,id'],
            'program_level_id' => ['nullable','integer'],
            'field_id' => ['nullable','integer'],
            'intake_id' => ['nullable','integer'],
            'program_title' => ['nullable','string','max:255'],
            'status' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);

        // default status if not provided
        $data['status'] = $data['status'] ?? 'Submitted';

        // We keep database normalized and rely on foreign keys (student_id, institution_id, intake_id)
        // to access related information instead of duplicating student data on the applications table.
        $app = Application::create($data);
        return response()->json($app->load(['student','institution','intake']), 201);
    }

    public function update(Request $request, Application $application)
    {
        $data = $request->validate([
            'student_id' => ['sometimes','exists:students,id'],
            'institution_id' => ['sometimes','exists:institutions,id'],
            'program_level_id' => ['nullable','integer'],
            'field_id' => ['nullable','integer'],
            'intake_id' => ['nullable','integer'],
            'program_title' => ['nullable','string','max:255'],
            'status' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
        ]);

        $application->update($data);
        return response()->json($application);
    }

    public function destroy(Application $application)
    {
        $application->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
