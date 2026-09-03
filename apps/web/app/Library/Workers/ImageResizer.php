<?php

namespace App\Library\Workers;

use Imagick;
use RuntimeException;

/**
 * Deterministic downscaling of texture maps: fit the longest edge, keep the
 * format, Lanczos when Imagick is available.
 */
class ImageResizer
{
    public const VERSION = '1.0.0';

    public function tool(): string
    {
        return class_exists(Imagick::class) ? 'imagick lanczos' : 'gd bicubic';
    }

    /**
     * @return array{0: string, 1: int, 2: int} bytes, width, height
     */
    public function fit(string $bytes, int $longestEdge, string $mimeType): array
    {
        if (class_exists(Imagick::class)) {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            [$targetWidth, $targetHeight] = $this->dimensions($width, $height, $longestEdge);
            $image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
            $image->setImageFormat($this->format($mimeType));
            $result = $image->getImageBlob();
            $image->clear();

            return [$result, $targetWidth, $targetHeight];
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('The file is not a readable image.');
        }

        [$targetWidth, $targetHeight] = $this->dimensions(imagesx($image), imagesy($image), $longestEdge);
        $scaled = imagescale($image, $targetWidth, $targetHeight, IMG_BICUBIC);

        if ($scaled === false) {
            throw new RuntimeException('Resizing failed.');
        }

        ob_start();
        $mimeType === 'image/jpeg' ? imagejpeg($scaled, null, 92) : imagepng($scaled);

        return [(string) ob_get_clean(), $targetWidth, $targetHeight];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function dimensions(int $width, int $height, int $longestEdge): array
    {
        $scale = $longestEdge / max($width, $height, 1);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function format(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpeg',
            'image/webp' => 'webp',
            'image/tiff' => 'tiff',
            default => 'png',
        };
    }
}
