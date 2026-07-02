<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\Destination;
use Illuminate\Support\Facades\Validator;

class InstitutionController extends Controller
{
    public function index()
    {
        $institutions = Institution::with('destination')->orderBy('id', 'desc')->get();
        return response()->json($institutions);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:institutions,name',
            'destination_id' => 'required|exists:destinations,id',
            // extras
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

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }
        $data = $request->only([
            'name', 'destination_id', 'city', 'tuition', 'application_fee', 'duration', 'can_take_loan',
            'success_chance', 'success_details'
        ]);
        $data['tags'] = $request->input('tags');

        // handle optional logo upload
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $data['logo_path'] = $path;
            $data['logo_url'] = asset('storage/' . $path);
        }

        $institution = Institution::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Institution created successfully.',
            'data' => $institution,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $institution = Institution::find($id);
        if (!$institution) {
            return response()->json(['message' => 'Institution not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:institutions,name,' . $id,
            'destination_id' => 'required|exists:destinations,id',
            // extras
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

        if ($validator->fails()) {
            return response()->json(['status' => 'error','errors'=>$validator->errors()],422);
        }
        $data = $request->only([
            'name', 'destination_id', 'city', 'tuition', 'application_fee', 'duration', 'can_take_loan',
            'success_chance', 'success_details'
        ]);
        $data['tags'] = $request->input('tags');

        // optional logo update
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads', 'public');
            $data['logo_path'] = $path;
            $data['logo_url'] = asset('storage/' . $path);
        }

        $institution->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Institution updated successfully.',
            'data' => $institution,
        ]);
    }

    public function destroy($id)
    {
        $institution = Institution::find($id);
        if (!$institution) {
            return response()->json(['message' => 'Institution not found'], 404);
        }

        $institution->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Institution deleted successfully.',
        ]);
    }
}
