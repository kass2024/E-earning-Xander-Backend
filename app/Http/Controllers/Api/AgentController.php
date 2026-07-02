<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Agent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AgentController extends Controller
{
    public function index()
    {
        return response()->json(Agent::orderByDesc('id')->get(), 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:agents,email',
            'phone' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'password' => 'required|string|min:6',
        ]);
        $agent = Agent::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'password' => bcrypt($validated['password']),
        ]);
        return response()->json(['message' => 'Agent created', 'agent' => $agent], 201);
    }

    public function update(Request $request, $id)
    {
        $agent = Agent::findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:agents,email,' . $agent->id,
            'phone' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive',
            'students' => 'nullable|integer',
            'password' => 'nullable|string|min:6',
        ]);
        // Hash password if provided
        if (isset($validated['password']) && $validated['password']) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }
        $agent->update($validated);
        return response()->json(['message' => 'Agent updated', 'agent' => $agent]);
    }

    public function destroy($id)
    {
        Agent::findOrFail($id)->delete();
        return response()->json(['message' => 'Agent deleted']);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $agent = Agent::where('email', $data['email'])->first();
        if (!$agent || !$agent->password || !Hash::check($data['password'], $agent->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'agent' => $agent->makeHidden(['password']),
        ], 200);
    }

    public function uploadAvatar(Request $request, $id)
    {
        $agent = Agent::findOrFail($id);
        $validated = $request->validate([
            'avatar' => 'required|file|max:10240|mimes:png,jpg,jpeg',
        ]);
        $file = $validated['avatar'];
        $path = $file->store('uploads', 'public');
        $url = asset('storage/' . $path);
        $agent->avatar = $url;
        $agent->save();
        return response()->json(['message' => 'Avatar updated', 'url' => $url]);
    }
}
