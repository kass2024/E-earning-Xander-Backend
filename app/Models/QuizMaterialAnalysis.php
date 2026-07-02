<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizMaterialAnalysis extends Model
{
    protected $fillable = [
        'course_material_id',
        'content_hash',
        'knowledge_map',
        'chunks',
        'chunk_embeddings',
        'embedding_model',
        'word_count',
        'analysis_provider',
        'analyzed_at',
    ];

    protected $casts = [
        'knowledge_map' => 'array',
        'chunks' => 'array',
        'chunk_embeddings' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }
}
