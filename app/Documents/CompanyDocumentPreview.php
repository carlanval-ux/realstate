<?php

declare(strict_types=1);

namespace App\Documents;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The web viewer of «Documentación de la empresa»: what each file shows as (PDF and images as they are; Word, Excel and
 * OpenDocument converted to PDF on this server with LibreOffice headless, never by an outside service), the converted
 * copy cached on the private disk next to the expediente («vistas/»), and the order of the viewer (the expediente's
 * files, then its annotations' attachments). Without LibreOffice (or when a conversion fails) the file has no preview:
 * the viewer says so and offers the download. The original Word / Excel is never served inline.
 *
 * Bound in the container: the test battery swaps the converter for a simulated one.
 */
class CompanyDocumentPreview
{
    /** Seconds a conversion may take before it is given up (20 MB at most). */
    public const TIMEOUT = 90;

    /** @param  (Closure(string $input, string $outDir): ?string)|null  $converter  input file → PDF written in $outDir (its path), or null */
    public function __construct(private ?Closure $converter = null) {}

    /** pdf | image | office | null (no preview) */
    public static function kind(string $mime): ?string
    {
        return match (true) {
            $mime === 'application/pdf' => 'pdf',
            in_array($mime, CompanyDocument::INLINE_TYPES, true) => 'image',
            in_array($mime, CompanyDocument::OFFICE_TYPES, true) => 'office',
            default => null,
        };
    }

    /**
     * Every file the viewer goes through, in order: the expediente's files, then the attachments of its annotations.
     * The key is the URL segment: archivo-{index} / anotacion-{id}.
     *
     * @return array<string, array{path: string, name: string, mime: string, size: int, label: string, download: string, preview: string}>
     */
    public static function items(CompanyDocument $document): array
    {
        $items = [];
        foreach ((array) $document->files as $i => $file) {
            $route = ['companyDocument' => $document->getKey(), 'index' => $i];
            $items["archivo-{$i}"] = self::item($file, CompanyDocument::fileLabel($file), route('clarto.documentacion.archivo', $route), route('clarto.documentacion.archivo.vista', $route));
        }
        foreach ($document->annotations()->get() as $annotation) {
            if (filled($annotation->file['path'] ?? null)) {
                $route = ['companyDocument' => $document->getKey(), 'annotation' => $annotation->getKey()];
                $items["anotacion-{$annotation->getKey()}"] = self::item($annotation->file, '📎 '.$annotation->file['name'],
                    route('clarto.documentacion.anotacion', $route), route('clarto.documentacion.anotacion.vista', $route));
            }
        }

        return $items;
    }

    /** Where the converted copy of a file is kept: inside the expediente's own folder, so it goes with it. */
    public static function cachePath(CompanyDocument $document, string $sourcePath): string
    {
        return "gestor-documental/{$document->team_id}/{$document->getKey()}/vistas/".sha1($sourcePath).'.pdf';
    }

    /** Removes the converted copy of a file (the file was removed or replaced). */
    public static function forget(CompanyDocument $document, string $sourcePath): void
    {
        Storage::disk(CompanyDocument::DISK)->delete(self::cachePath($document, $sourcePath));
    }

    /** Removes every converted copy of an expediente (it was deleted). */
    public static function forgetAll(CompanyDocument $document): void
    {
        Storage::disk(CompanyDocument::DISK)->deleteDirectory("gestor-documental/{$document->team_id}/{$document->getKey()}/vistas");
    }

    /** LibreOffice is installed (or a converter was given): Word / Excel / OpenDocument can be previewed. */
    public function canConvert(): bool
    {
        return $this->converter !== null || $this->binary() !== null;
    }

    /**
     * The private-disk path of the PDF a Word / Excel / OpenDocument file shows as: the cached one, or converted now.
     * Null when it cannot be converted (no LibreOffice, a timeout, a broken file).
     *
     * @param  array{path: string, name: string, mime: string}  $file
     */
    public function pdfFor(CompanyDocument $document, array $file): ?string
    {
        $disk = Storage::disk(CompanyDocument::DISK);
        $cached = self::cachePath($document, $file['path']);
        if ($disk->exists($cached)) {
            return $cached;
        }
        if (self::kind($file['mime']) !== 'office' || ! $this->canConvert() || ! $disk->exists($file['path'])) {
            return null;
        }

        $work = sys_get_temp_dir().'/clarto-vista-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($work);
        try {
            // A neutral name (the extension tells LibreOffice the format; the original name never reaches the command).
            $extension = strtolower((string) (array_search($file['mime'], CompanyDocument::OFFICE_TYPES, true) ?: pathinfo($file['name'], PATHINFO_EXTENSION)));
            $input = "{$work}/documento.".preg_replace('/[^a-z]/', '', $extension);
            File::put($input, (string) $disk->get($file['path']));
            $pdf = ($this->converter ?? $this->libreOffice(...))($input, $work);
            if ($pdf === null || ! is_file($pdf) || (string) file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
                return null;
            }
            $disk->put($cached, (string) file_get_contents($pdf));

            return $cached;
        } catch (Throwable $e) {
            Log::warning('Documentación de la empresa: vista previa no disponible', ['document' => $document->getKey(), 'error' => $e->getMessage()]);

            return null;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * @param  array{path: string, name: string, mime: string, size?: int}  $file
     * @return array{path: string, name: string, mime: string, size: int, label: string, download: string, preview: string}
     */
    private static function item(array $file, string $label, string $url, string $preview): array
    {
        return ['path' => $file['path'], 'name' => $file['name'], 'mime' => $file['mime'], 'size' => (int) ($file['size'] ?? 0),
            'label' => $label, 'download' => $url.'?descargar=1', 'preview' => $preview];
    }

    private function binary(): ?string
    {
        $binary = (string) config('clarto.documentacion.soffice', 'soffice');

        return is_file($binary) && is_executable($binary) ? $binary : (new ExecutableFinder())->find($binary);
    }

    /**
     * LibreOffice headless, one conversion per call: its own throwaway profile (no shared state, no locks), no
     * restore / first-start dialogs, a time limit. Macros never run in a conversion, and files with macros were
     * already refused at upload.
     */
    private function libreOffice(string $input, string $outDir): ?string
    {
        $binary = $this->binary();
        if ($binary === null) {
            return null;
        }
        $profile = $outDir.'/perfil';
        $process = new Process([$binary, '--headless', '--norestore', '--nolockcheck', '--nodefault', '--nologo', '--nofirststartwizard',
            '-env:UserInstallation=file://'.$profile, '--convert-to', 'pdf', '--outdir', $outDir, $input], $outDir, ['HOME' => $outDir], null, self::TIMEOUT);
        $process->run();
        $pdf = $outDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.pdf';

        return $process->isSuccessful() && is_file($pdf) ? $pdf : null;
    }
}
