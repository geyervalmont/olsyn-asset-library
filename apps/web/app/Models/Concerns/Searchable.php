<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Full-text plus fuzzy search over a maintained search_text column.
 *
 * On PostgreSQL the generated search_vector column and the trigram index carry
 * the query; elsewhere it degrades to a case-insensitive contains match.
 */
trait Searchable
{
    abstract public function buildSearchText(): string;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim(preg_replace('/\s+/', ' ', $term) ?? '');

        if ($term === '') {
            return $query;
        }

        $table = $this->getTable();

        if (DB::getDriverName() !== 'pgsql') {
            return $query->where("{$table}.search_text", 'ILIKE', '%'.$term.'%');
        }

        return $query->where(function (Builder $query) use ($table, $term): void {
            $query->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$term])
                ->orWhereRaw('word_similarity(?, search_text) > 0.45', [$term])
                ->orWhere("{$table}.search_text", 'ILIKE', '%'.$term.'%');
        });
    }

    public function refreshSearchText(): void
    {
        $this->search_text = $this->buildSearchText();

        if ($this->isDirty('search_text')) {
            $this->saveQuietly();
        }
    }

    /**
     * @param  iterable<mixed>  $parts
     */
    protected function joinSearchParts(iterable $parts): string
    {
        $text = collect($parts)
            ->flatten()
            ->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')
            ->implode(' ');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
