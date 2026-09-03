<?php

namespace App\Models;

use App\Enums\FileDirection;
use App\Library\Provenance\Lineage;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * Immutable, content-addressed bytes in object storage. A file never changes;
 * a modified image is a new file linked to the old one through provenance.
 *
 * @property int $id
 * @property string $sha256
 * @property string $disk
 * @property string $object_key
 * @property string $kind
 * @property string $mime_type
 * @property string|null $extension
 * @property string|null $original_name
 * @property int $bytes
 * @property int|null $width_px
 * @property int|null $height_px
 * @property string|null $colour_space
 * @property int|null $bit_depth
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable([
    'sha256', 'disk', 'object_key', 'kind', 'mime_type', 'extension', 'original_name', 'bytes',
    'width_px', 'height_px', 'colour_space', 'bit_depth', 'metadata',
])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (File $file): never {
            throw new LogicException(sprintf('File [%s] is immutable; store a new file instead.', $file->sha256));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'bytes' => 'integer',
        ];
    }

    /**
     * The in-app URL that streams this file to a signed-in user.
     */
    public function url(): string
    {
        return route('files.show', ['file' => $this, 'name' => $this->original_name ?? $this->sha256.'.'.($this->extension ?? 'bin')]);
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }

    public function contents(): string
    {
        $contents = Storage::disk($this->disk)->get($this->object_key);

        if ($contents === null || $contents === '') {
            throw new RuntimeException(sprintf('File [%s] has no readable bytes on disk [%s] at [%s].', $this->sha256, $this->disk, $this->object_key));
        }

        return $contents;
    }

    /**
     * @return HasMany<FileAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(FileAccess::class)->orderByDesc('accessed_at');
    }

    /**
     * @return BelongsToMany<ProvenanceEvent, $this>
     */
    public function provenanceEvents(): BelongsToMany
    {
        return $this->belongsToMany(ProvenanceEvent::class, 'provenance_event_files')
            ->withPivot(['direction', 'role'])
            ->orderBy('provenance_events.occurred_at');
    }

    /**
     * Events this file came out of. Usually one; more when the same bytes
     * were produced independently.
     *
     * @return BelongsToMany<ProvenanceEvent, $this>
     */
    public function producedBy(): BelongsToMany
    {
        return $this->provenanceEvents()->wherePivot('direction', FileDirection::Output->value);
    }

    /**
     * Events this file was fed into.
     *
     * @return BelongsToMany<ProvenanceEvent, $this>
     */
    public function consumedBy(): BelongsToMany
    {
        return $this->provenanceEvents()->wherePivot('direction', FileDirection::Input->value);
    }

    /**
     * The events that led to this file, nearest first.
     *
     * @return Collection<int, ProvenanceEvent>
     */
    public function lineage(): Collection
    {
        return app(Lineage::class)->eventsFor($this);
    }

    /**
     * The files this one was derived from, walking the whole chain.
     *
     * @return Collection<int, File>
     */
    public function ancestors(): Collection
    {
        return app(Lineage::class)->ancestorsOf($this);
    }

    /**
     * Every source that contributed to this file, root sources included.
     *
     * @return Collection<int, Source>
     */
    public function sources(): Collection
    {
        return app(Lineage::class)->sourcesFor($this);
    }
}
