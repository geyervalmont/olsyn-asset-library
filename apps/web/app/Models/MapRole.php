<?php

namespace App\Models;

use App\Models\Concerns\IsRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a file is inside a representation: base_color, normal, roughness, mdl…
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $colour_space
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'colour_space', 'description', 'sort_order'])]
class MapRole extends Model
{
    use IsRegistry;
}
