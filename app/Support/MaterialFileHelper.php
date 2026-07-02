<?php

namespace App\Support;

class MaterialFileHelper
{
    /**
     * @return 'images'|'videos'|'audio'|'documents'
     */
    public static function categoryFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) {
            return 'images';
        }

        if (in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'mkv', 'wmv', 'flv', 'm4v'], true)) {
            return 'videos';
        }

        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'], true)) {
            return 'audio';
        }

        return 'documents';
    }

    public static function typeFromCategory(string $category): string
    {
        return match ($category) {
            'images' => 'image',
            'videos' => 'video',
            'audio' => 'audio',
            default => 'document',
        };
    }

    public static function pcloudFileId(?array $metadata): ?int
    {
        if (!is_array($metadata)) {
            return null;
        }

        $raw = $metadata['pcloud_file_id'] ?? $metadata['fileid'] ?? $metadata['file_id'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    public static function isPCloudMaterial(?array $metadata): bool
    {
        return self::pcloudFileId($metadata) !== null
            && (
                ($metadata['storage'] ?? '') === 'pcloud'
                || isset($metadata['pcloud_file_id'])
                || isset($metadata['fileid'])
            );
    }

    public static function mimeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
    }

    public static function isPdfFilename(string $filename): bool
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
    }
}
