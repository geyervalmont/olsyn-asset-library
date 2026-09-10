<?php

namespace App\Library\Procedural;

use InvalidArgumentException;

/** UI-facing recipe catalog. The Rust schema remains the execution authority. */
final class ProceduralRecipes
{
    /** @return array<string, string> */
    public static function generators(): array
    {
        return [
            'paint' => 'Paint',
            'masonry' => 'Brick / masonry',
            'timber' => 'Timber boards',
            'terrazzo' => 'Terrazzo',
            'textile' => 'Textile',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(string $generator): array
    {
        return match ($generator) {
            'paint' => ['colour' => '#D4CABA', 'roughness' => 0.62, 'variation' => 0.02, 'texture_depth' => 0.1],
            'masonry' => ['unit_width_mm' => 230, 'unit_height_mm' => 76, 'joint_mm' => 10, 'bond' => 'running', 'unit_colours' => ['#B2593E', '#974530', '#C2704F'], 'joint_colour' => '#CDCAC0', 'roughness' => 0.68, 'edge_depth_mm' => 3, 'surface_detail' => 0.22, 'tone_variation' => 0.12],
            'timber' => ['board_width_mm' => 140, 'board_length_mm' => 1200, 'joint_mm' => 3, 'colours' => ['#9D6D40', '#B5844F', '#845835'], 'roughness' => 0.55, 'grain_strength' => 0.22, 'stagger' => true],
            'terrazzo' => ['matrix_colour' => '#BDBAB2', 'chip_colours' => ['#E4DCCF', '#686865', '#BC9777'], 'chip_size_mm' => 18, 'density' => 0.5, 'roughness' => 0.5, 'chip_depth_mm' => 0.5],
            'textile' => ['warp_colour' => '#A39C8D', 'weft_colour' => '#77746D', 'thread_mm' => 2, 'roughness' => 0.75, 'depth_mm' => 0.5, 'basket' => false],
            default => throw new InvalidArgumentException("Unknown procedural generator [{$generator}]."),
        };
    }

    /** @return array<string, list<string>> */
    public static function rules(string $generator): array
    {
        $colour = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $unit = ['required', 'numeric', 'between:0,1'];
        $positive = ['required', 'numeric', 'gt:0', 'max:100000'];

        return match ($generator) {
            'paint' => ['recipe.colour' => $colour, 'recipe.roughness' => $unit, 'recipe.variation' => $unit, 'recipe.texture_depth' => $unit],
            'masonry' => ['recipe.unit_width_mm' => $positive, 'recipe.unit_height_mm' => $positive, 'recipe.joint_mm' => ['required', 'numeric', 'gte:0'], 'recipe.bond' => ['required', 'in:stack,running,quarter'], 'recipe.unit_colours' => ['required', 'array', 'min:1', 'max:8'], 'recipe.unit_colours.*' => $colour, 'recipe.joint_colour' => $colour, 'recipe.roughness' => $unit, 'recipe.edge_depth_mm' => ['required', 'numeric', 'gte:0'], 'recipe.surface_detail' => $unit, 'recipe.tone_variation' => $unit],
            'timber' => ['recipe.board_width_mm' => $positive, 'recipe.board_length_mm' => $positive, 'recipe.joint_mm' => ['required', 'numeric', 'gte:0'], 'recipe.colours' => ['required', 'array', 'min:1', 'max:8'], 'recipe.colours.*' => $colour, 'recipe.roughness' => $unit, 'recipe.grain_strength' => $unit, 'recipe.stagger' => ['boolean']],
            'terrazzo' => ['recipe.matrix_colour' => $colour, 'recipe.chip_colours' => ['required', 'array', 'min:1', 'max:8'], 'recipe.chip_colours.*' => $colour, 'recipe.chip_size_mm' => $positive, 'recipe.density' => $unit, 'recipe.roughness' => $unit, 'recipe.chip_depth_mm' => ['required', 'numeric', 'gte:0']],
            'textile' => ['recipe.warp_colour' => $colour, 'recipe.weft_colour' => $colour, 'recipe.thread_mm' => $positive, 'recipe.roughness' => $unit, 'recipe.depth_mm' => ['required', 'numeric', 'gte:0'], 'recipe.basket' => ['boolean']],
            default => throw new InvalidArgumentException("Unknown procedural generator [{$generator}]."),
        };
    }
}
