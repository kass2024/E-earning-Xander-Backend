<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_study_shift')) {
            Schema::create('course_study_shift', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id');
                $table->unsignedBigInteger('study_shift_id');
                $table->timestamps();

                $table->unique(['course_id', 'study_shift_id'], 'course_study_shift_unique');
                $table->index('study_shift_id');
            });
        }

        if (Schema::hasTable('study_shifts') && Schema::hasTable('course_study_shift')) {
            $rows = DB::table('study_shifts')
                ->whereNotNull('course_id')
                ->select(['id', 'course_id'])
                ->get();

            foreach ($rows as $row) {
                $exists = DB::table('course_study_shift')
                    ->where('course_id', $row->course_id)
                    ->where('study_shift_id', $row->id)
                    ->exists();

                if (!$exists) {
                    DB::table('course_study_shift')->insert([
                        'course_id' => $row->course_id,
                        'study_shift_id' => $row->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_study_shift');
    }
};
