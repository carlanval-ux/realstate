<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Liberu\Foundation\Files\Support\UploadPolicy;

/**
 * Image, video or file of a version, on the private disk at procesos/{team}/{process}/{sha256}.{ext} (content
 * addressed: copies of a draft share the file). Served only by route clarto.procesos.adjunto (ProcessAccess::canDownload).
 */
final class ProcessAttachment extends Model
{
    public const DISK = 'local';

    /** kind => [mime => extension], max bytes */
    public const TYPES = [
        'image' => [['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 10 * 1024 * 1024],
        'video' => [['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'], 60 * 1024 * 1024],
        'file' => [[
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.oasis.opendocument.text' => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        ], 20 * 1024 * 1024],
    ];

    /** Served inline (the rest as a download). */
    public const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf'];

    protected $table = 'clarto_process_attachments';

    protected $guarded = ['id'];

    protected $casts = ['size' => 'integer'];

    protected static function booted(): void
    {
        // The physical file goes only when no row references it any more.
        self::deleted(function (self $attachment): void {
            if (! self::query()->where('disk', $attachment->disk)->where('path', $attachment->path)->exists()) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return BelongsTo<ProcessVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcessVersion::class, 'process_version_id');
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** Upload folder of a user on the private disk (the editor's FileUpload writes here). */
    public static function uploadDirectory(int|string $userId): string
    {
        return "procesos/subidas/{$userId}";
    }

    /**
     * Stores a file of the private disk as an attachment of $version, checked by content (finfo) and size with the
     * Files module UploadPolicy. $path must be under the user's upload folder (or $trusted, for the demo seeder).
     * The upload is removed afterwards, accepted or not.
     */
    public static function store(User $by, ProcessVersion $version, string $path, string $originalName, ?string $kind = null, ?string $caption = null, bool $trusted = false): self
    {
        $disk = Storage::disk(self::DISK);
        if (! $trusted && (! str_starts_with($path, self::uploadDirectory($by->getKey()).'/') || str_contains($path, '..'))) {
            throw ValidationException::withMessages(['attachment' => __('procesos.errores.adjunto')]);
        }
        if (! $disk->exists($path)) {
            throw ValidationException::withMessages(['attachment' => __('procesos.errores.adjunto')]);
        }
        $stream = $disk->readStream($path);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) fread($stream, 65536));
        fclose($stream);
        $size = (int) $disk->size($path);
        $kind ??= collect(self::TYPES)->search(fn (array $t): bool => isset($t[0][$mime])) ?: 'file';
        // finfo may report Office/OpenDocument files as a generic zip: accept them only by an allowed extension.
        if ($kind === 'file' && in_array($mime, ['application/zip', 'application/octet-stream'], true)) {
            $mime = array_search(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), self::TYPES['file'][0], true) ?: $mime;
        }
        [$allowed, $max] = self::TYPES[$kind] ?? self::TYPES['file'];
        try {
            app(UploadPolicy::class)->assert($mime, $size, array_keys($allowed), $max);
        } catch (InvalidArgumentException) {
            if (! $trusted) {
                $disk->delete($path);
            }
            throw ValidationException::withMessages(['attachment' => __('procesos.errores.tipo_adjunto', ['tipos' => strtoupper(implode(', ', array_unique($allowed))), 'mb' => $max / 1024 / 1024])]);
        }

        $sha = hash('sha256', (string) $disk->get($path));
        $target = "procesos/{$version->team_id}/{$version->process_id}/{$sha}.{$allowed[$mime]}";
        if (! $disk->exists($target)) {
            $disk->copy($path, $target);
        }
        if (! $trusted) {
            $disk->delete($path);
        }

        return self::query()->create([
            'team_id' => $version->team_id, 'process_id' => $version->process_id, 'process_version_id' => $version->getKey(),
            'kind' => $kind, 'disk' => self::DISK, 'path' => $target, 'original_name' => mb_substr($originalName, 0, 200),
            'mime' => $mime, 'size' => $size, 'sha256' => $sha, 'caption' => $caption, 'created_by' => $by->getKey(),
        ]);
    }
}
