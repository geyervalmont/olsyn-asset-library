<?php

namespace App\Library\Conversion;

use App\Library\FileStore;
use App\Models\File;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use RuntimeException;

/**
 * Produces the image set Revit's appearance assets expect: base colour as is,
 * the normal map as the bump input, and glossiness as inverted roughness.
 */
class RevitImageSetConverter implements Converter
{
    public const VERSION = '1.0.0';

    public function __construct(private readonly FileStore $files) {}

    public function supports(Target $target): bool
    {
        return $target->slug === 'revit';
    }

    public function tool(): string
    {
        return 'opal revit image-set converter';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @return array<string, File>
     */
    public function convert(Representation $canonical, Target $target, QualityTier $quality): array
    {
        $textures = $canonical->filesByRole();
        $output = [];

        if (isset($textures['base_color'])) {
            $output['base_color'] = $textures['base_color'];
        }

        if (isset($textures['normal'])) {
            $output['bump'] = $textures['normal'];
        }

        if (isset($textures['roughness'])) {
            $output['glossiness'] = $this->invert($textures['roughness'], $canonical->variant->code.'_glossiness.png');
        }

        if ($output === []) {
            throw new RuntimeException('The canonical representation has no maps Revit can use.');
        }

        return $output;
    }

    private function invert(File $roughness, string $name): File
    {
        $image = @imagecreatefromstring($roughness->contents());

        if ($image === false) {
            throw new RuntimeException("Roughness file [{$roughness->sha256}] is not a readable image.");
        }

        imagefilter($image, IMG_FILTER_NEGATE);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return $this->files->store($png, $name, 'image/png');
    }
}
