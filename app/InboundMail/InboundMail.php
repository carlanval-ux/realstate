<?php

declare(strict_types=1);

namespace App\InboundMail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Copy of one message read from the company mailbox (headers, text, sanitised HTML, attachments on the private disk)
 * and what triage did with it. Bodies are encrypted at rest; the mailbox itself is never modified.
 */
final class InboundMail extends Model
{
    public const DISK = 'local';

    /** status => label key */
    public const STATUSES = ['entrada', 'revisar', 'enrutado', 'archivado', 'atendido'];

    public const CATEGORIES = ['solicitud', 'propietario', 'colaborador', 'cliente', 'consulta', 'proveedor', 'administracion', 'publicidad', 'otros', 'automatico'];

    /** Areas of a new enquiry (consulta). */
    public const AREAS = ['inmobiliaria', 'alquiler', 'gestoria', 'mercantil', 'extranjeria', 'fiscal', 'otros'];

    /** Who gets the TARMIO task of an «Entrada» item: one owner per area, plus suppliers and official notices. */
    public const OWNER_KEYS = [...self::AREAS, 'proveedor', 'administracion'];

    protected $table = 'clarto_inbound_mails';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'text_body' => 'encrypted', 'html_body' => 'encrypted', 'attachments' => 'array', 'is_auto' => 'boolean',
            'sent_at' => 'datetime', 'reviewed_at' => 'datetime', 'confidence' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $mail): void {
            $paths = array_values(array_filter(array_column((array) $mail->attachments, 'path')));
            if ($paths !== []) {
                Storage::disk(self::DISK)->delete($paths);
            }
        });
    }

    public function categoryLabel(?string $category = null): string
    {
        $category ??= $this->category;

        return $category === null ? '—' : __('correo.categoria.'.$category);
    }

    public function areaLabel(?string $area = null): ?string
    {
        $area ??= $this->area;

        return $area === null ? null : __('correo.area.'.$area);
    }

    public function sender(): string
    {
        return trim(($this->from_name ? $this->from_name.' ' : '').'<'.$this->from_email.'>');
    }
}
