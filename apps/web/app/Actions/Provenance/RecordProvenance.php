<?php

namespace App\Actions\Provenance;

use App\Enums\ActorType;
use App\Enums\FileDirection;
use App\Models\File;
use App\Models\ProvenanceEvent;
use App\Models\Source;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RecordProvenance
{
    /**
     * Record one event on a subject.
     *
     * The actor is a User, a worker name (string), or null with an explicit
     * actor type for external origins. Inputs and outputs are Files, or
     * [File, role] pairs.
     *
     * @param  list<File|array{0: File, 1: string|null}>  $inputs
     * @param  list<File|array{0: File, 1: string|null}>  $outputs
     * @param  array<string, mixed>|null  $parameters
     */
    public function handle(
        Model $subject,
        string $action,
        User|string|null $actor = null,
        ?ActorType $actorType = null,
        array $inputs = [],
        array $outputs = [],
        ?string $tool = null,
        ?string $toolVersion = null,
        ?string $model = null,
        ?array $parameters = null,
        ?Source $source = null,
        ?string $sourceUrl = null,
        ?string $externalRef = null,
        ?string $jobId = null,
        ?ProvenanceEvent $parent = null,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
    ): ProvenanceEvent {
        return DB::transaction(function () use (
            $subject, $action, $actor, $actorType, $inputs, $outputs, $tool, $toolVersion, $model,
            $parameters, $source, $sourceUrl, $externalRef, $jobId, $parent, $notes, $occurredAt,
        ): ProvenanceEvent {
            $event = new ProvenanceEvent([
                'action' => $action,
                'actor_type' => $actorType ?? $this->inferActorType($actor),
                'actor_id' => $actor instanceof User ? $actor->getKey() : null,
                'actor_name' => is_string($actor) ? $actor : null,
                'tool' => $tool,
                'tool_version' => $toolVersion,
                'model' => $model,
                'parameters' => $parameters,
                'source_id' => $source?->getKey(),
                'source_url' => $sourceUrl,
                'external_ref' => $externalRef,
                'job_id' => $jobId,
                'parent_event_id' => $parent?->getKey(),
                'notes' => $notes,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $event->subject()->associate($subject);
            $event->save();

            $this->attach($event, $inputs, FileDirection::Input);
            $this->attach($event, $outputs, FileDirection::Output);

            return $event->load(['inputs', 'outputs']);
        });
    }

    private function inferActorType(User|string|null $actor): ActorType
    {
        return match (true) {
            $actor instanceof User => ActorType::User,
            is_string($actor) => ActorType::Worker,
            default => ActorType::External,
        };
    }

    /**
     * @param  list<File|array{0: File, 1: string|null}>  $files
     */
    private function attach(ProvenanceEvent $event, array $files, FileDirection $direction): void
    {
        foreach ($files as $entry) {
            [$file, $role] = $entry instanceof File ? [$entry, null] : $entry;

            $event->files()->attach($file->getKey(), [
                'direction' => $direction->value,
                'role' => $role,
            ]);
        }
    }
}
