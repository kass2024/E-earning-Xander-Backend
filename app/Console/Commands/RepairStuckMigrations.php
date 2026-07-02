<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairStuckMigrations extends Command
{
    protected $signature = 'schema:repair-stuck-migrations';

    protected $description = 'Mark already-applied schema changes as migrated, then run remaining migrations';

    /** @var array<string, list<string>|array<string, list<string>>> */
    private array $knownStuck = [
        '2026_06_09_000002_quiz_phase2_embeddings_and_integrity' => [
            'quiz_material_analyses' => ['chunk_embeddings', 'embedding_model'],
        ],
        '2026_06_09_200000_create_quiz_attempts_table' => [
            'quiz_attempts' => ['marking_provider', 'tab_switch_count', 'integrity_flags', 'delivered_question_ids'],
        ],
        '2026_06_09_140000_livezoom_cohort_queue' => [
            'livezoom_cohort' => ['session_status'],
            'livezoom_cohort_queue_entries' => ['id'],
        ],
    ];

    public function handle(): int
    {
        if (!Schema::hasTable('migrations')) {
            $this->error('migrations table not found.');

            return self::FAILURE;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $batch = (int) DB::table('migrations')->max('batch') + 1;
        $marked = 0;

        foreach ($this->knownStuck as $migration => $tables) {
            if (DB::table('migrations')->where('migration', $migration)->exists()) {
                continue;
            }

            $alreadyApplied = true;
            foreach ($tables as $table => $columns) {
                if (!Schema::hasTable($table)) {
                    $alreadyApplied = false;
                    break;
                }

                foreach ($columns as $column) {
                    $count = DB::selectOne(
                        'SELECT COUNT(*) AS total FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$database, $table, $column]
                    );

                    if (((int) ($count->total ?? 0)) === 0) {
                        $alreadyApplied = false;
                        break 2;
                    }
                }
            }

            if (!$alreadyApplied) {
                continue;
            }

            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => $batch,
            ]);
            $marked++;
            $this->info("Marked as migrated: {$migration}");
        }

        if ($marked === 0) {
            $this->line('No stuck migrations needed marking.');
        }

        $this->line('Running php artisan migrate --force ...');
        Artisan::call('migrate', ['--force' => true]);
        $this->output->write(Artisan::output());

        return self::SUCCESS;
    }
}
