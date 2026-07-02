<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Destination;
use Illuminate\Support\Facades\Validator;

class DestinationController extends Controller
{
    public function index()
    {
        $destinations = Destination::orderBy('id', 'desc')->get();
        return response()->json($destinations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:destinations,name',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $destination = Destination::create($request->only('name', 'description'));

        return response()->json([
            'status' => 'success',
            'message' => 'Destination created successfully.',
            'data' => $destination,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $destination = Destination::find($id);
        if (!$destination) {
            return response()->json(['message' => 'Destination not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|unique:destinations,name,' . $id,
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $destination->update($request->only('name', 'description'));

        return response()->json([
            'status' => 'success',
            'message' => 'Destination updated successfully.',
            'data' => $destination,
        ]);
    }

    public function destroy($id)
    {
        $destination = Destination::find($id);
        if (!$destination) {
            return response()->json(['message' => 'Destination not found'], 404);
        }

        $destination->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Destination deleted successfully.',
        ]);
    }
}
