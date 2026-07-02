<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CourseProgramAssignmentService;
use App\Services\DatabaseSchemaService;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function health(DatabaseSchemaService $schema, \App\Services\PCloudService $pcloud)
    {
        $status = $schema->status();
        $pcloudStatus = $pcloud->status();

        $http = ($status['database_connected'] && $status['schema_ready']) ? 200 : 503;

        return response()->json([
            'status' => $status['schema_ready'] ? 'ok' : 'degraded',
            'message' => $status['schema_ready']
                ? 'Database schema is ready.'
                : 'Database connected but schema is incomplete. Run migrations.',
            ...$status,
            'pcloud' => $pcloudStatus,
        ], $http);
    }

    public function pcloudHealth(\App\Services\PCloudService $pcloud)
    {
        $status = $pcloud->status();

        return response()->json($status, ($status['ok'] ?? false) ? 200 : 503);
    }

    public function migrate(Request $request, DatabaseSchemaService $schema)
    {
        $token = config('app.migrate_token');
        if ($token) {
            $provided = $request->header('X-Migrate-Token') ?? $request->query('token');
            if (!hash_equals((string) $token, (string) $provided)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        if (!$schema->databaseConnected()) {
            return response()->json([
                'message' => 'Database is not reachable.',
            ], 503);
        }

        $result = $schema->runMigrations();
        $status = $schema->status();
        $programs = null;

        if ($status['schema_ready']) {
            $programs = app(CourseProgramAssignmentService::class)->assignAllToGeneral();
        }

        return response()->json([
            'message' => $result['pending_after'] === 0
                ? 'Migrations complete. Schema is up to date.'
                : 'Migrations ran but some items may still be pending.',
            'migration' => $result,
            'schema_ready' => $status['schema_ready'],
            'schema' => $status['schema'],
            'programs' => $programs,
        ], $status['schema_ready'] ? 200 : 207);
    }

    public function setupPrograms(Request $request, DatabaseSchemaService $schema, CourseProgramAssignmentService $assignment)
    {
        $token = config('app.migrate_token');
        if ($token) {
            $provided = $request->header('X-Migrate-Token') ?? $request->query('token');
            if (!hash_equals((string) $token, (string) $provided)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        if (!$schema->databaseConnected()) {
            return response()->json(['message' => 'Database is not reachable.'], 503);
        }

        $migration = $schema->runMigrations();
        $status = $schema->status();
        $force = $request->boolean('force');
        $programs = $status['schema_ready'] ? $assignment->assignAllToGeneral($force) : null;

        return response()->json([
            'message' => $programs
                ? "General program ready. {$programs['assigned']} course(s) assigned."
                : 'Schema not ready. Check migration output.',
            'migration' => $migration,
            'schema_ready' => $status['schema_ready'],
            'programs' => $programs,
        ], $status['schema_ready'] ? 200 : 207);
    }
}
