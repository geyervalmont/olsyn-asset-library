<?php

namespace App\Library\Embeddings;

use App\Models\Material;
use Illuminate\Support\Str;

/**
 * Produces the stable, provider-independent description used to index a
 * material. Reordering JSON keys or changing an image reference cannot make
 * an unchanged document look stale accidentally.
 */
final class MaterialEmbeddingDocuments
{
    public const PROFILE = 'material-semantic-v2';

    public const KIND = 'material_similarity';

    public function for(Material $material): MaterialEmbeddingDocument
    {
        $material->loadMissing(['category', 'supplier', 'tags']);
        $specifications = $material->specifications ?? [];
        ksort($specifications);

        $specificationText = collect($specifications)
            ->map(fn (mixed $value, string|int $key): string => Str::headline((string) $key).': '.$this->scalar($value))
            ->filter(fn (string $value): bool => ! str_ends_with($value, ': '))
            ->implode('; ');

        $lines = array_filter([
            'Material: '.$material->name,
            'Category: '.$material->category->name,
            $material->material_type ? 'Type: '.$material->material_type : null,
            $material->form ? 'Form: '.$material->form : null,
            $material->tags->isNotEmpty() ? 'Tags: '.$material->tags->pluck('name')->implode(', ') : null,
            $material->install_pattern ? 'Installation: '.$material->install_pattern : null,
            $material->repeat_type ? 'Repeat: '.$material->repeat_type : null,
            $material->thickness_mm ? 'Thickness: '.(float) $material->thickness_mm.' mm' : null,
            $specificationText !== '' ? 'Specifications: '.$specificationText : null,
            $material->description ? 'Description: '.$material->description : null,
        ]);

        // Type similarity is deliberately text-only. Appearance belongs to
        // per-variant image vectors, so colourways cannot pull a semantically
        // unrelated material into these results. Keep safely below Titan's
        // 256-token ceiling and never truncate in the middle of a word.
        $text = Str::limit(Str::words(implode("\n", $lines), 200, ''), 1000, '');
        $digest = hash('sha256', json_encode([
            'profile' => self::PROFILE,
            'text' => $text,
        ], JSON_THROW_ON_ERROR));

        return new MaterialEmbeddingDocument($text, $digest, null);
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            return collect($value)->flatten()->filter(fn (mixed $part): bool => is_scalar($part))->implode(', ');
        }

        return '';
    }
}
