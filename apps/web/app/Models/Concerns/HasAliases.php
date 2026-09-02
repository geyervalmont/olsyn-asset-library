<?php

namespace App\Models\Concerns;

use App\Models\Alias;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAliases
{
    /**
     * @return MorphMany<Alias, $this>
     */
    public function aliases(): MorphMany
    {
        return $this->morphMany(Alias::class, 'aliasable');
    }

    public function addAlias(string $code, ?string $reason = null): Alias
    {
        return $this->aliases()->create(['code' => $code, 'reason' => $reason]);
    }
}
