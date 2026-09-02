<?php

namespace App\Library\Provenance;

use App\Enums\FileDirection;
use App\Models\File;
use App\Models\ProvenanceEvent;
use App\Models\Source;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Walks provenance backwards from a file: the events that produced it, the
 * files those events consumed, and so on to the original sources.
 */
class Lineage
{
    public const MAX_DEPTH = 100;

    /**
     * Events that led to the file, nearest first, each visited once.
     *
     * @return Collection<int, ProvenanceEvent>
     */
    public function eventsFor(File $file): Collection
    {
        /** @var Collection<int, ProvenanceEvent> $events */
        $events = new Collection;
        $seenFiles = [$file->getKey() => true];
        $seenEvents = [];
        $frontier = [$file];

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; $depth++) {
            $ids = array_map(fn (File $file): int => (int) $file->getKey(), $frontier);
            $frontier = [];

            $producing = ProvenanceEvent::query()
                ->whereHas('files', function ($query) use ($ids): void {
                    $query->whereIn('files.id', $ids)
                        ->where('provenance_event_files.direction', FileDirection::Output->value);
                })
                ->with(['inputs', 'source', 'actor'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get();

            foreach ($producing as $event) {
                if (isset($seenEvents[$event->getKey()])) {
                    continue;
                }

                $seenEvents[$event->getKey()] = true;
                $events->push($event);

                foreach ($event->inputs as $input) {
                    if (! isset($seenFiles[$input->getKey()])) {
                        $seenFiles[$input->getKey()] = true;
                        $frontier[] = $input;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @return Collection<int, File>
     */
    public function ancestorsOf(File $file): Collection
    {
        /** @var Collection<int, File> $ancestors */
        $ancestors = new Collection;

        foreach ($this->eventsFor($file) as $event) {
            foreach ($event->inputs as $input) {
                if (! $ancestors->contains(fn (File $seen): bool => $seen->is($input))) {
                    $ancestors->push($input);
                }
            }
        }

        return $ancestors;
    }

    /**
     * @return Collection<int, Source>
     */
    public function sourcesFor(File $file): Collection
    {
        /** @var Collection<int, Source> $sources */
        $sources = new Collection;

        foreach ($this->eventsFor($file) as $event) {
            $source = $event->source;

            if ($source !== null && ! $sources->contains(fn (Source $seen): bool => $seen->is($source))) {
                $sources->push($source);
            }
        }

        return $sources;
    }

    /**
     * The full timeline of a subject (material, variant, …), oldest first.
     *
     * @return Collection<int, ProvenanceEvent>
     */
    public function timelineFor(Model $subject): Collection
    {
        return ProvenanceEvent::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->with(['inputs', 'outputs', 'source', 'actor'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }
}
