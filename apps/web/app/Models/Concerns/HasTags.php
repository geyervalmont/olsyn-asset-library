<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * @param  list<string>  $names
     */
    public function syncTagNames(array $names): void
    {
        $ids = collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => Str::slug($name))
            ->map(fn (string $name): int => (int) Tag::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->getKey())
            ->all();

        $this->tags()->sync($ids);
        $this->unsetRelation('tags');
    }
}
