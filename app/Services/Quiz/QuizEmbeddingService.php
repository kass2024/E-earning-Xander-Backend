<?php

namespace App\Services\Quiz;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuizEmbeddingService
{
    protected string $model;

    public function __construct()
    {
        $this->model = (string) config('services.quiz_ai.embedding_model', 'text-embedding-004');
    }

    public function isAvailable(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * @return array<int, float>|null
     */
    public function embedText(string $text): ?array
    {
        $key = $this->apiKey();
        if (!$key || trim($text) === '') {
            return null;
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':embedContent?key=' . $key;

        try {
            $response = Http::timeout(30)->post($url, [
                'model' => 'models/' . $this->model,
                'content' => ['parts' => [['text' => Str::limit($text, 8000, '')]]],
            ]);

            if (!$response->successful()) {
                Log::warning('Embedding API error', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $values = data_get($response->json(), 'embedding.values');

            return is_array($values) ? array_map('floatval', $values) : null;
        } catch (\Throwable $e) {
            Log::warning('Embedding request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array{id: string, vector: array<int, float>}>
     */
    public function embedChunks(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $batched = $this->embedChunksBatch($chunks);
        if ($batched !== []) {
            return $batched;
        }

        $embedded = [];
        $batchSize = 8;
        $slice = array_values(array_filter($chunks, fn ($chunk) => trim((string) ($chunk['text'] ?? '')) !== ''));

        for ($offset = 0; $offset < count($slice); $offset += $batchSize) {
            $batch = array_slice($slice, $offset, $batchSize);
            $responses = Http::pool(function ($pool) use ($batch) {
                $requests = [];
                foreach ($batch as $index => $chunk) {
                    $key = $this->apiKey();
                    if (!$key) {
                        continue;
                    }
                    $text = Str::limit(trim((string) ($chunk['text'] ?? '')), 8000, '');
                    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':embedContent?key=' . $key;
                    $requests['chunk_' . $index] = $pool->as('chunk_' . $index)
                        ->timeout(30)
                        ->post($url, [
                            'model' => 'models/' . $this->model,
                            'content' => ['parts' => [['text' => $text]]],
                        ]);
                }

                return $requests;
            });

            foreach ($batch as $index => $chunk) {
                $response = $responses['chunk_' . $index] ?? null;
                if ($response === null || !$response->successful()) {
                    continue;
                }
                $values = data_get($response->json(), 'embedding.values');
                if (!is_array($values)) {
                    continue;
                }
                $embedded[] = [
                    'id' => (string) ($chunk['id'] ?? ''),
                    'vector' => array_map('floatval', $values),
                ];
            }
        }

        return $embedded;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array{id: string, vector: array<int, float>}>
     */
    protected function embedChunksBatch(array $chunks): array
    {
        $key = $this->apiKey();
        if (!$key) {
            return [];
        }

        $requests = [];
        $indexed = [];
        foreach ($chunks as $chunk) {
            $text = trim((string) ($chunk['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $indexed[] = $chunk;
        }

        if ($indexed === []) {
            return [];
        }

        $embedded = [];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':batchEmbedContents?key=' . $key;

        foreach (array_chunk($indexed, 100) as $batchChunks) {
            $batchRequests = [];
            foreach ($batchChunks as $chunk) {
                $batchRequests[] = [
                    'model' => 'models/' . $this->model,
                    'content' => ['parts' => [['text' => Str::limit(trim((string) ($chunk['text'] ?? '')), 8000, '')]]],
                ];
            }

            try {
                $response = Http::timeout(60)->post($url, ['requests' => $batchRequests]);
                if (!$response->successful()) {
                    Log::info('Batch embedding unavailable, falling back to parallel requests', [
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                $embeddings = data_get($response->json(), 'embeddings', []);
                if (!is_array($embeddings)) {
                    return [];
                }

                foreach ($embeddings as $i => $row) {
                    $values = data_get($row, 'values');
                    $chunk = $batchChunks[$i] ?? null;
                    if (!is_array($values) || !is_array($chunk)) {
                        continue;
                    }
                    $embedded[] = [
                        'id' => (string) ($chunk['id'] ?? ''),
                        'vector' => array_map('floatval', $values),
                    ];
                }
            } catch (\Throwable $e) {
                Log::info('Batch embedding failed', ['error' => $e->getMessage()]);

                return [];
            }
        }

        return $embedded;
    }

    /**
     * @param  array<int, array{id: string, vector: array<int, float>}>  $stored
     * @param  array<int, array<string, mixed>>  $chunks
     * @return array<int, array<string, mixed>>
     */
    public function semanticRetrieve(array $stored, array $chunks, string $query, int $limit): array
    {
        $queryVector = $this->embedText($query);
        if ($queryVector === null || $stored === []) {
            return [];
        }

        $chunkMap = [];
        foreach ($chunks as $chunk) {
            $chunkMap[(string) ($chunk['id'] ?? '')] = $chunk;
        }

        $scored = [];
        foreach ($stored as $row) {
            $id = (string) ($row['id'] ?? '');
            $vector = $row['vector'] ?? null;
            if (!is_array($vector) || !isset($chunkMap[$id])) {
                continue;
            }
            $scored[] = [
                'chunk' => $chunkMap[$id],
                'score' => $this->cosineSimilarity($queryVector, $vector),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values(array_map(fn ($r) => $r['chunk'], array_slice($scored, 0, $limit)));
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    protected function apiKey(): ?string
    {
        foreach (['GOOGLE_AI_API_KEY', 'GEMINI_API_KEY'] as $name) {
            $value = env($name);
            if (is_string($value) && trim($value, " \t\"'") !== '') {
                return trim($value, " \t\"'");
            }
        }

        return null;
    }
}
