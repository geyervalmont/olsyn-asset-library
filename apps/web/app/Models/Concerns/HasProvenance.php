<?php

namespace App\Models\Concerns;

use App\Models\ProvenanceEvent;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Anything that can be the subject of provenance events: materials and
 * variants now, other library assets later.
 */
trait HasProvenance
{
    /**
     * @return MorphMany<ProvenanceEvent, $this>
     */
    public function provenanceEvents(): MorphMany
    {
        return $this->morphMany(ProvenanceEvent::class, 'subject')->orderBy('occurred_at')->orderBy('id');
    }
}
