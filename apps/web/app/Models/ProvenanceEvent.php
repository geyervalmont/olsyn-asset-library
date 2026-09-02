<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\FileDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One thing that happened to a subject: who or what did it, with which tool
 * and model, from which source, and which files went in and came out.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $action
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $tool
 * @property string|null $tool_version
 * @property string|null $model
 * @property array<string, mixed>|null $parameters
 * @property int|null $source_id
 * @property string|null $source_url
 * @property string|null $external_ref
 * @property string|null $job_id
 * @property int|null $parent_event_id
 * @property string|null $notes
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read Source|null $source
 * @property-read User|null $actor
 */
#[Fillable([
    'action', 'actor_type', 'actor_id', 'actor_name', 'tool', 'tool_version', 'model', 'parameters',
    'source_id', 'source_url', 'external_ref', 'job_id', 'parent_event_id', 'notes', 'occurred_at',
])]
class ProvenanceEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::saving(function (ProvenanceEvent $event): void {
            if (! ProvenanceAction::exists($event->action)) {
                throw new LogicException(sprintf('Unknown provenance action [%s].', $event->action));
            }

            $event->occurred_at ??= Carbon::now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'parameters' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * A short human line: "Harrison edited with Photoshop 26".
     */
    public function describe(): string
    {
        $who = match ($this->actor_type) {
            ActorType::User => $this->actor_id === null ? 'a user' : $this->actor->name,
            ActorType::Worker => $this->actor_name ?? 'a worker',
            ActorType::External => $this->actor_name ?? ($this->source_id === null ? 'an external source' : $this->source->name),
        };

        $with = array_filter([
            $this->tool !== null ? trim($this->tool.' '.($this->tool_version ?? '')) : null,
            $this->model,
        ]);

        return trim(sprintf('%s %s%s', $who, $this->action, $with === [] ? '' : ' with '.implode(', ', $with)));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<ProvenanceEvent, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    /**
     * @return HasMany<ProvenanceEvent, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id');
    }

    /**
     * @return BelongsToMany<File, $this>
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'provenance_event_files')->withPivot(['direction', 'role']);
    }

    /**
     * @return BelongsToMany<File, $this>
     */
    public function inputs(): BelongsToMany
    {
        return $this->files()->wherePivot('direction', FileDirection::Input->value);
    }

    /**
     * @return BelongsToMany<File, $this>
     */
    public function outputs(): BelongsToMany
    {
        return $this->files()->wherePivot('direction', FileDirection::Output->value);
    }
}
