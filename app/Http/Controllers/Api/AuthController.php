<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Agent;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\StudentRegistrationEmailService;
use App\Support\PlatformUserService;
use App\Support\PlatformInstitutionHelper;
use App\Models\PlatformInstitution;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Services\EnrollmentStudyShiftService;
use App\Services\StudyShiftProvisioningService;
use App\Support\PlatformTenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    private function verifyPlainOrHashedPassword($record, string $password): bool
    {
        if (!$record) {
            return false;
        }

        $stored = (string) ($record->password ?? '');
        $defaultPassword = '12345678';

        // If no password set in DB, allow default only
        if (empty($stored)) {
            return $password === $defaultPassword;
        }

        // If hashed (bcrypt), attempt hash check; otherwise compare plain
        if (password_get_info($stored)['algo'] !== 0) {
            return password_verify($password, $stored);
        }

        return hash_equals($stored, $password);
    }

    private function resolveAccountByRole(string $role, string $email)
    {
        $normalized = strtolower(trim($role));

        if ($normalized === 'learner' || $normalized === 'student') {
            return Student::where('email', $email)->first();
        }

        if ($normalized === 'instructor' || $normalized === 'agent') {
            return Agent::where('email', $email)->first();
        }

        // admin / staff / other roles -> users table
        return User::where('email', $email)->first();
    }

    public function registerStudent(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:students,email',
            'password' => 'nullable|string|min:6',
            'country' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string',
            'selected_courses' => 'nullable|array',
            'selected_courses.*' => 'nullable|string|max:255',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
            'enrollments' => 'nullable|array',
            'enrollments.*.course_id' => 'required|integer|exists:courses,id',
            'enrollments.*.level' => 'nullable|string|max:255',
            'enrollments.*.study_shift_ids' => 'nullable|array',
            'enrollments.*.study_shift_ids.*' => 'integer|exists:study_shifts,id',
        ]);

        try {
            DB::beginTransaction();

            $student = new Student();
            $student->first_name = $data['first_name'];
            $student->last_name = $data['last_name'] ?? null;
            $student->email = $data['email'];
            $student->status = 'Pending';
            $student->phone = $data['phone'] ?? '';
            $student->country = $data['country'] ?? '';
            $student->primary_goal = $data['primary_goal'] ?? '';
            $plainPassword = !empty($data['password'])
                ? (string) $data['password']
                : Str::password(12, symbols: true);
            $student->password = $plainPassword;

            $institutionId = $data['platform_institution_id'] ?? null;
            if ($institutionId) {
                $institution = PlatformInstitution::query()
                    ->where('id', $institutionId)
                    ->where('status', 'active')
                    ->first();
                if (!$institution) {
                    DB::rollBack();
                    return response()->json(['message' => 'Selected institution is not available.'], 422);
                }
                $student->platform_institution_id = $institution->id;
            }

            $student->save();

            $enrollmentResult = $this->createSignupEnrollments($student, $data['enrollments'] ?? []);
            if ($enrollmentResult instanceof JsonResponse) {
                DB::rollBack();

                return $enrollmentResult;
            }

            $selectedCourses = $data['selected_courses'] ?? [];
            $emailSent = app(StudentRegistrationEmailService::class)->sendWelcomeEmail(
                $student,
                $plainPassword,
                $selectedCourses
            );

            DB::commit();

            $institution = PlatformInstitutionHelper::resolveForStudent($student);

            return response()->json([
                'message' => 'Student registered',
                'role' => 'learner',
                'user' => $student,
                'institution' => PlatformInstitutionHelper::institutionPayload($institution),
                'email_sent' => $emailSent,
                'pending_approval' => true,
                'enrollments_created' => $enrollmentResult,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Failed to register student or send email', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create account due to a server error. Please try again later.',
            ], 500);
        }
    }

    /**
     * @param  array<int, array{course_id: int, level?: string|null, study_shift_ids?: array<int, int>}>  $enrollments
     * @return int|JsonResponse
     */
    private function createSignupEnrollments(Student $student, array $enrollments): int|JsonResponse
    {
        if ($enrollments === []) {
            return 0;
        }

        $shiftService = app(EnrollmentStudyShiftService::class);
        $provisioning = app(StudyShiftProvisioningService::class);
        $created = 0;

        foreach ($enrollments as $item) {
            $course = Course::query()->find((int) $item['course_id']);
            if (!$course) {
                continue;
            }

            if (!PlatformTenantScope::studentCanAccessCourse($student, $course)) {
                continue;
            }

            $exists = CourseEnrollment::query()
                ->where('student_id', $student->id)
                ->where('course_id', $course->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $shiftIds = array_values(array_unique(array_filter(
                array_map('intval', $item['study_shift_ids'] ?? [])
            )));

            $requireShifts = $provisioning
                ->shiftsForCourseRegistration($course, $course->platform_institution_id)
                ->isNotEmpty();

            $shiftsResult = $shiftService->resolveStudyShiftsForCourse(
                $course,
                $shiftIds,
                null,
                $requireShifts
            );

            if ($shiftsResult instanceof JsonResponse) {
                return $shiftsResult;
            }

            $enrollment = CourseEnrollment::create([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'status' => 'enrolled',
                'level' => $item['level'] ?? null,
                'study_shift_id' => $shiftsResult !== [] ? $shiftsResult[0]->id : null,
            ]);

            if ($shiftsResult !== []) {
                $enrollment->studyShifts()->sync(collect($shiftsResult)->pluck('id')->all());
            }

            $created++;
        }

        return $created;
    }

    public function registerInstructor(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:255',
            'primary_goal' => 'nullable|string|max:500',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
        ]);

        try {
            $create = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'instructor',
                'status' => 'Pending',
                'phone' => $data['phone'] ?? '',
            ];

            if (!empty($data['platform_institution_id']) && Schema::hasColumn('users', 'platform_institution_id')) {
                $create['platform_institution_id'] = (int) $data['platform_institution_id'];
            }

            $user = User::create($create);

            return response()->json([
                'message' => 'Instructor application submitted',
                'role' => 'instructor',
                'user' => $user->makeHidden(['password']),
                'pending_approval' => true,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to register instructor', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to submit instructor application. Please try again later.',
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'platform_institution_id' => 'nullable|integer|exists:platform_institutions,id',
            'institution_slug' => 'nullable|string|max:255',
        ]);

        $username = PlatformUserService::normalizeEmail($data['username']);
        $password = $data['password'];
        $portalSlug = !empty($data['institution_slug'])
            ? strtolower(trim((string) $data['institution_slug']))
            : null;
        $portalInstitutionId = $this->resolvePortalInstitutionId($data);

        // Branded institution URL must resolve to a real institution — otherwise nobody can sign in here.
        if ($portalSlug !== null && $portalSlug !== '' && $portalInstitutionId === null) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $defaultPassword = '12345678';

        // helper to verify: if password column is empty => accept default; else verify against stored password
        $verify = function ($record) use ($password, $defaultPassword) {
            if (!$record) return false;
            $stored = $record->password ?? '';
            // If no password set in DB, allow default only
            if (empty($stored)) {
                return $password === $defaultPassword;
            }
            // If hashed (bcrypt), attempt hash check; otherwise compare plain
            if (password_get_info($stored)['algo'] !== 0) {
                return password_verify($password, $stored);
            }
            return $stored === $password;
        };

        // Try Students table first (treated as learners)
        try {
            $student = Student::where('email', $username)
                ->orWhere('first_name', $username)
                ->orWhere('last_name', $username)
                ->first();
        } catch (QueryException $e) {
            $student = null;
        }

        if ($student && $verify($student)) {
            $studentStatus = strtolower(trim((string) ($student->status ?? 'active')));
            if (in_array($studentStatus, ['pending', 'inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your account is pending admin approval. You will be able to sign in once an administrator approves your registration.',
                ], 403);
            }

            $institution = PlatformInstitutionHelper::resolveForStudent($student);
            if ($institution && !PlatformInstitutionHelper::canLoginInstitution($institution)) {
                return response()->json([
                    'message' => 'Your institution account is not active. Please contact your institution administrator.',
                ], 403);
            }

            if ($reject = $this->rejectIfWrongInstitutionPortal($portalInstitutionId, $institution)) {
                return $reject;
            }

            return response()->json([
                'message' => 'Login successful',
                'role' => 'learner',
                'user' => $student,
                'institution' => PlatformInstitutionHelper::institutionPayload($institution),
                'is_main_admin' => false,
            ]);
        }

        // Users table (admin, staff, instructors, partners, etc.)
        try {
            $user = User::query()
                ->where(function ($query) use ($username) {
                    $query->whereRaw('LOWER(TRIM(email)) = ?', [$username])
                        ->orWhere('name', $username);
                })
                ->first();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Database schema error. Run: php artisan schema:rebuild-corrupted --seed --force',
            ], 503);
        }

        if ($user && PlatformUserService::verifyPassword($user, $password)) {
            $userStatus = strtolower(trim((string) ($user->status ?? 'active')));
            $userRole = strtolower(trim((string) ($user->role ?? 'admin')));

            if ($userRole === 'instructor' && in_array($userStatus, ['pending', 'inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your instructor application is pending approval. We will email you when your account is activated.',
                ], 403);
            }

            if (in_array($userStatus, ['inactive', 'rejected'], true)) {
                return response()->json([
                    'message' => 'Your account is not active. Please contact support.',
                ], 403);
            }

            $institution = PlatformInstitutionHelper::resolveForUser($user);
            $user = PlatformInstitutionHelper::restorePartnerOwnerRole($user);
            $userRole = strtolower(trim((string) ($user->role ?? '')));
            $institution = PlatformInstitutionHelper::resolveForUser($user) ?? $institution;

            if ($userRole === 'partner_company') {
                if (!$institution) {
                    return response()->json(['message' => 'Partner institution not linked to this account.'], 403);
                }
                if ($institution->status === 'disabled') {
                    return response()->json(['message' => 'Your institution has been disabled. Contact platform support.'], 403);
                }
                if ($institution->status === 'pending_approval') {
                    return response()->json([
                        'message' => 'Your institution registration is pending main admin approval.',
                    ], 403);
                }
                if (PlatformInstitutionHelper::shouldBlockLoginForPayment($user, $institution)) {
                    return response()->json([
                        'message' => 'Your institution account has an outstanding payment. Check your email for the Stripe payment link.',
                    ], 403);
                }
                if ($institution->status === 'active' && in_array($userStatus, ['pending', 'unpaid'], true)) {
                    $user->status = 'Active';
                    $user->save();
                    $userStatus = 'active';
                }
            } elseif ($institution && !PlatformInstitutionHelper::canLoginInstitution($institution)) {
                return response()->json([
                    'message' => 'Your institution account is not active. Please contact support.',
                ], 403);
            }

            if ($reject = $this->rejectIfWrongInstitutionPortal($portalInstitutionId, $institution, $user)) {
                return $reject;
            }

            return response()->json([
                'message' => 'Login successful',
                'role' => $user->role ?? 'admin',
                'user' => $user,
                'institution' => PlatformInstitutionHelper::institutionPayload($institution),
                'is_main_admin' => PlatformInstitutionHelper::isMainPlatformAdmin($user),
            ]);
        }

        // Legacy agents table (instructors) — never valid on an institution-branded login portal.
        $agent = Agent::where('email', $username)->orWhere('name', $username)->first();
        if ($agent && $verify($agent)) {
            if ($portalInstitutionId !== null) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            return response()->json([
                'message' => 'Login successful',
                'role' => 'instructor',
                'user' => $agent,
            ]);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'role' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'new_email' => 'required|email|max:255',
            'current_password' => 'required|string|max:255',
        ]);

        $record = $this->resolveAccountByRole($data['role'], $data['email']);
        if (!$record) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if (!$this->verifyPlainOrHashedPassword($record, $data['current_password'])) {
            return response()->json(['message' => 'Invalid current password'], 422);
        }

        $newEmail = (string) $data['new_email'];

        // Validate unique email in the correct table
        $normalized = strtolower(trim($data['role']));
        if ($normalized === 'learner' || $normalized === 'student') {
            $exists = Student::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
            if ($exists) {
                return response()->json(['message' => 'Email is already taken'], 422);
            }

            $full = trim((string) $data['name']);
            $parts = preg_split('/\s+/', $full) ?: [];
            $first = (string) array_shift($parts);
            $last = trim(implode(' ', $parts));
            if ($first === '') {
                $first = $record->first_name ?? '';
            }

            $record->first_name = $first;
            $record->last_name = $last !== '' ? $last : null;
            $record->email = $newEmail;
            $record->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $record,
            ]);
        }

        if ($normalized === 'instructor' || $normalized === 'agent') {
            $exists = Agent::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
            if ($exists) {
                return response()->json(['message' => 'Email is already taken'], 422);
            }

            $record->name = (string) $data['name'];
            $record->email = $newEmail;
            $record->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $record,
            ]);
        }

        $exists = User::where('email', $newEmail)->where('id', '!=', $record->id)->exists();
        if ($exists) {
            return response()->json(['message' => 'Email is already taken'], 422);
        }

        $record->name = (string) $data['name'];
        $record->email = $newEmail;
        $record->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $record->makeHidden(['password']),
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'role' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'current_password' => 'required|string|max:255',
            'new_password' => 'required|string|min:8|max:255',
            'new_password_confirmation' => 'required|string|same:new_password',
        ]);

        $record = $this->resolveAccountByRole($data['role'], $data['email']);
        if (!$record) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        if (!$this->verifyPlainOrHashedPassword($record, $data['current_password'])) {
            return response()->json(['message' => 'Invalid current password'], 422);
        }

        $record->password = Hash::make($data['new_password']);
        $record->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    public function redirectToGoogle()
    {
        return response()->json([
            'message' => 'Google login redirect not yet fully configured on the server.',
        ], 501);
    }

    public function handleGoogleCallback(Request $request)
    {
        return response()->json([
            'message' => 'Google login callback not yet fully configured on the server.',
        ], 501);
    }

    /**
     * Resolve expected institution for a branded institution login portal.
     */
    private function resolvePortalInstitutionId(array $data): ?int
    {
        if (!empty($data['institution_slug'])) {
            $slug = strtolower(trim((string) $data['institution_slug']));
            // Resolve by slug regardless of status so the portal lock still applies.
            $institution = PlatformInstitution::query()
                ->whereRaw('LOWER(slug) = ?', [$slug])
                ->first();

            return $institution?->id ? (int) $institution->id : null;
        }

        if (!empty($data['platform_institution_id'])) {
            return (int) $data['platform_institution_id'];
        }

        return null;
    }

    /**
     * Institution login links are isolated: other institutions and main-platform accounts
     * are treated as non-existent on this portal (generic Invalid credentials).
     */
    private function rejectIfWrongInstitutionPortal(
        ?int $expectedInstitutionId,
        ?PlatformInstitution $userInstitution,
        ?User $user = null,
    ): ?JsonResponse {
        if ($expectedInstitutionId === null) {
            return null;
        }

        $userInstitutionId = $userInstitution?->id ? (int) $userInstitution->id : null;

        // Main hub operators / staff / any account without this exact institution link → not found here.
        if ($userInstitutionId === null || $userInstitutionId !== (int) $expectedInstitutionId) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Extra safety: main-platform admins never authenticate via partner portals.
        if ($user && PlatformInstitutionHelper::isMainPlatformAdmin($user)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return null;
    }
}
