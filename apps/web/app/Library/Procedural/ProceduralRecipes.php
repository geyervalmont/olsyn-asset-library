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
            'paint' => ['colour' => '#E8E4DC', 'roughness' => 0.82, 'variation' => 0.012, 'texture_depth' => 0.06],
            'masonry' => ['unit_width_mm' => 230, 'unit_height_mm' => 76, 'joint_mm' => 10, 'bond' => 'running', 'unit_colours' => ['#A66F59', '#AE7862', '#98644F'], 'joint_colour' => '#BDB4A4', 'roughness' => 0.68, 'edge_depth_mm' => 3, 'surface_detail' => 0.22, 'tone_variation' => 0.06],
            'timber' => ['board_width_mm' => 140, 'board_length_mm' => 1200, 'joint_mm' => 3, 'colours' => ['#B69A74', '#BCA17D', '#AD906C'], 'roughness' => 0.48, 'grain_strength' => 0.35, 'stagger' => true],
            'terrazzo' => ['matrix_colour' => '#D8D0C2', 'chip_colours' => ['#EEE7DC', '#B7AA95', '#8E8A7F'], 'chip_size_mm' => 24, 'density' => 0.72, 'roughness' => 0.32, 'chip_depth_mm' => 0.05],
            'textile' => ['warp_colour' => '#BFB39C', 'weft_colour' => '#A89E8B', 'thread_mm' => 2, 'roughness' => 0.88, 'depth_mm' => 0.25, 'basket' => false],
            default => throw new InvalidArgumentException("Unknown procedural generator [{$generator}]."),
        };
    }

    /**
     * Physical coverage chosen to show detail and enough units to judge variation.
     *
     * @return array{float, float}
     */
    public static function dimensions(string $generator): array
    {
        return match ($generator) {
            'masonry' => [960.0, 688.0],
            'timber' => [2406.0, 858.0],
            'terrazzo' => [500.0, 500.0],
            'textile' => [128.0, 128.0],
            default => [1000.0, 1000.0],
        };
    }

    /**
     * Curated starting points; every parameter stays editable.
     *
     * @return array<string, array{label: string, recipe: array<string, mixed>}>
     */
    public static function presets(string $generator): array
    {
        return match ($generator) {
            'paint' => [
                'chalk' => ['label' => 'Chalk white', 'recipe' => ['colour' => '#E8E4DC', 'roughness' => 0.82, 'variation' => 0.012, 'texture_depth' => 0.06]],
                'clay' => ['label' => 'Soft clay', 'recipe' => ['colour' => '#B99883', 'roughness' => 0.76, 'variation' => 0.045, 'texture_depth' => 0.18]],
                'sage' => ['label' => 'Satin sage', 'recipe' => ['colour' => '#939C8B', 'roughness' => 0.38, 'variation' => 0.008, 'texture_depth' => 0.025]],
            ],
            'masonry' => [
                'clay' => ['label' => 'Fired clay', 'recipe' => ['unit_colours' => ['#A66F59', '#AE7862', '#98644F'], 'joint_colour' => '#BDB4A4', 'tone_variation' => 0.06, 'surface_detail' => 0.38]],
                'cream' => ['label' => 'Limewashed', 'recipe' => ['unit_colours' => ['#D8CDB9', '#CFC3AD', '#DED4C2'], 'joint_colour' => '#C4BCAA', 'roughness' => 0.84, 'tone_variation' => 0.035]],
                'charcoal' => ['label' => 'Charcoal stack', 'recipe' => ['bond' => 'stack', 'unit_colours' => ['#53514D', '#605B54', '#4D4B47'], 'joint_colour' => '#8D877D', 'edge_depth_mm' => 1.5, 'tone_variation' => 0.03]],
            ],
            'timber' => [
                'oak' => ['label' => 'Natural oak', 'recipe' => ['colours' => ['#B69A74', '#BCA17D', '#AD906C'], 'grain_strength' => 0.35, 'roughness' => 0.48]],
                'walnut' => ['label' => 'Oiled walnut', 'recipe' => ['colours' => ['#71533E', '#7B5C45', '#694C38'], 'grain_strength' => 0.42, 'roughness' => 0.34]],
                'ash' => ['label' => 'Pale ash', 'recipe' => ['colours' => ['#C9BDA5', '#D1C4AC', '#C3B69D'], 'grain_strength' => 0.26, 'roughness' => 0.57]],
            ],
            'terrazzo' => [
                'ivory' => ['label' => 'Ivory aggregate', 'recipe' => ['matrix_colour' => '#D8D0C2', 'chip_colours' => ['#EEE7DC', '#B7AA95', '#8E8A7F'], 'chip_size_mm' => 24, 'density' => 0.72, 'roughness' => 0.32, 'chip_depth_mm' => 0.05]],
                'rose' => ['label' => 'Rose terrazzo', 'recipe' => ['matrix_colour' => '#BD9B8C', 'chip_colours' => ['#E6D6BF', '#8E6657', '#C1B5A4'], 'chip_size_mm' => 32, 'density' => 0.65, 'roughness' => 0.38, 'chip_depth_mm' => 0.08]],
                'graphite' => ['label' => 'Graphite fine', 'recipe' => ['matrix_colour' => '#565651', 'chip_colours' => ['#CCC7BC', '#8B8A80', '#343A38'], 'chip_size_mm' => 12, 'density' => 0.8, 'roughness' => 0.3, 'chip_depth_mm' => 0.03]],
            ],
            'textile' => [
                'linen' => ['label' => 'Natural linen', 'recipe' => ['warp_colour' => '#BFB39C', 'weft_colour' => '#A89E8B', 'thread_mm' => 1, 'roughness' => 0.88, 'depth_mm' => 0.25]],
                'boucle' => ['label' => 'Basket weave', 'recipe' => ['warp_colour' => '#D1C7B4', 'weft_colour' => '#B7AC97', 'basket' => true, 'roughness' => 0.92]],
                'indigo' => ['label' => 'Indigo weave', 'recipe' => ['warp_colour' => '#455667', 'weft_colour' => '#89939B', 'thread_mm' => 1, 'roughness' => 0.78, 'depth_mm' => 0.2]],
            ],
            default => [],
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
