<?php

namespace App\Library\Previews;

use Imagick;
use InvalidArgumentException;

/**
 * A lit sphere swatch from PBR maps, in plain PHP.
 *
 * The base colour is wrapped around the sphere with an equirectangular
 * mapping and tiled so repeating textures read as a pattern; a normal map
 * perturbs the surface normal; roughness scales a Blinn-Phong highlight and
 * metallic tints it. Lighting is a soft key from the upper left, a low fill,
 * a rim, and an ambient term. Pixels outside the sphere are transparent.
 */
class SphereRenderer
{
    public const VERSION = '1.0.0';

    public const RIG = [
        'key' => ['dir' => [-0.45, 0.6, 0.66], 'intensity' => 1.05],
        'fill' => ['dir' => [0.6, 0.15, 0.55], 'intensity' => 0.28],
        'ambient' => 0.22,
        'rim' => 0.18,
        'tiling' => 2.0,
        'gamma' => 2.2,
    ];

    /** Inputs are resampled to this edge before shading. */
    public const SAMPLE_EDGE = 256;

    public function tool(): string
    {
        return class_exists(Imagick::class) ? 'opal sphere renderer (imagick)' : 'opal sphere renderer (gd)';
    }

    /**
     * Render a PNG swatch. Maps are raw image bytes keyed by role; base_color is required.
     *
     * @param  array{base_color: string, normal?: string|null, roughness?: string|null, metallic?: string|null}  $maps
     */
    public function render(array $maps, int $size = 512): string
    {
        if ($size < 8) {
            throw new InvalidArgumentException('A swatch needs at least 8 pixels.');
        }

        $edge = min(self::SAMPLE_EDGE, max(8, $size));
        $albedo = $this->decode($maps['base_color'], $edge);
        $normal = isset($maps['normal']) && $maps['normal'] !== '' ? $this->decode($maps['normal'], $edge) : null;
        $roughness = isset($maps['roughness']) && $maps['roughness'] !== '' ? $this->decode($maps['roughness'], $edge) : null;
        $metallic = isset($maps['metallic']) && $maps['metallic'] !== '' ? $this->decode($maps['metallic'], $edge) : null;

        $rig = self::RIG;
        $key = $this->normalize($rig['key']['dir']);
        $fill = $this->normalize($rig['fill']['dir']);
        $keyHalf = $this->normalize([$key[0], $key[1], $key[2] + 1.0]);
        $fillHalf = $this->normalize([$fill[0], $fill[1], $fill[2] + 1.0]);
        $gamma = $rig['gamma'];
        $tiling = $rig['tiling'];

        $centre = ($size - 1) / 2;
        $radius = $size * 0.47;
        $pixels = array_fill(0, $size * $size * 4, 0);
        $lut = [];

        for ($i = 0; $i < 256; $i++) {
            $lut[$i] = ($i / 255) ** $gamma;
        }

        for ($y = 0; $y < $size; $y++) {
            $ny = ($centre - $y) / $radius;

            for ($x = 0; $x < $size; $x++) {
                $nx = ($x - $centre) / $radius;
                $d = $nx * $nx + $ny * $ny;

                if ($d > 1.0) {
                    continue;
                }

                $nz = sqrt(1.0 - $d);
                $coverage = min(1.0, ($radius - sqrt($d) * $radius + 0.5));

                $theta = atan2($nx, $nz);
                $phi = asin($ny);
                $u = 0.5 + $theta / (2 * M_PI) * $tiling;
                $v = 0.5 - $phi / M_PI * $tiling;

                [$r, $g, $b] = $this->sample($albedo, $edge, $u, $v);
                $ar = $lut[$r];
                $ag = $lut[$g];
                $ab = $lut[$b];

                $rough = $roughness === null ? 0.65 : $this->sample($roughness, $edge, $u, $v)[0] / 255;
                $metal = $metallic === null ? 0.0 : $this->sample($metallic, $edge, $u, $v)[0] / 255;

                $n = [$nx, $ny, $nz];

                if ($normal !== null) {
                    [$mr, $mg, $mb] = $this->sample($normal, $edge, $u, $v);
                    $tx = $mr / 127.5 - 1.0;
                    $ty = $mg / 127.5 - 1.0;
                    $tz = max(0.0, $mb / 127.5 - 1.0);
                    $tangent = [cos($theta), 0.0, -sin($theta)];
                    $bitangent = $this->cross($n, $tangent);
                    $n = $this->normalize([
                        $tangent[0] * $tx + $bitangent[0] * $ty + $n[0] * $tz,
                        $tangent[1] * $tx + $bitangent[1] * $ty + $n[1] * $tz,
                        $tangent[2] * $tx + $bitangent[2] * $ty + $n[2] * $tz,
                    ]);
                }

                $gloss = 1.0 - $rough;
                $shininess = 6.0 + 180.0 * $gloss * $gloss;
                $specularStrength = 0.04 + 0.96 * $metal;

                $diffuse = $rig['ambient'];
                $specular = 0.0;

                foreach ([[$key, $keyHalf, $rig['key']['intensity']], [$fill, $fillHalf, $rig['fill']['intensity']]] as [$light, $half, $intensity]) {
                    $ndl = max(0.0, $n[0] * $light[0] + $n[1] * $light[1] + $n[2] * $light[2]);
                    $diffuse += $ndl * $intensity;
                    $ndh = max(0.0, $n[0] * $half[0] + $n[1] * $half[1] + $n[2] * $half[2]);
                    $specular += ($ndh ** $shininess) * $intensity * $ndl * $gloss * ($gloss + 0.25);
                }

                $rim = ((1.0 - max(0.0, $n[2])) ** 3) * $rig['rim'];
                $diffuse *= 1.0 - $metal * 0.85;

                $sr = $specular * ($specularStrength * ((1 - $metal) + $metal * $ar)) + $rim;
                $sg = $specular * ($specularStrength * ((1 - $metal) + $metal * $ag)) + $rim;
                $sb = $specular * ($specularStrength * ((1 - $metal) + $metal * $ab)) + $rim;

                $offset = ($y * $size + $x) * 4;
                $pixels[$offset] = $this->encode($ar * $diffuse + $sr, $gamma);
                $pixels[$offset + 1] = $this->encode($ag * $diffuse + $sg, $gamma);
                $pixels[$offset + 2] = $this->encode($ab * $diffuse + $sb, $gamma);
                $pixels[$offset + 3] = (int) round(255 * max(0.0, $coverage));
            }
        }

        return $this->toPng($pixels, $size);
    }

    /**
     * Decode image bytes into an RGB byte array resampled to edge×edge.
     *
     * @return list<int>
     */
    private function decode(string $bytes, int $edge): array
    {
        if (class_exists(Imagick::class)) {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->resizeImage($edge, $edge, Imagick::FILTER_TRIANGLE, 1, false);

            /** @var list<int> $pixels */
            $pixels = $image->exportImagePixels(0, 0, $edge, $edge, 'RGB', Imagick::PIXEL_CHAR);
            $image->clear();

            return $pixels;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new InvalidArgumentException('Unreadable image bytes.');
        }

        $scaled = imagescale($source, $edge, $edge, IMG_BILINEAR_FIXED);

        if ($scaled === false) {
            throw new InvalidArgumentException('Unable to resample the image.');
        }

        $pixels = [];

        for ($y = 0; $y < $edge; $y++) {
            for ($x = 0; $x < $edge; $x++) {
                $rgb = imagecolorat($scaled, $x, $y);
                $pixels[] = ($rgb >> 16) & 0xFF;
                $pixels[] = ($rgb >> 8) & 0xFF;
                $pixels[] = $rgb & 0xFF;
            }
        }

        return $pixels;
    }

    /**
     * Nearest-pixel lookup with wrap-around, so tiles repeat seamlessly.
     *
     * @param  list<int>  $pixels
     * @return array{0: int, 1: int, 2: int}
     */
    private function sample(array $pixels, int $edge, float $u, float $v): array
    {
        $x = ((int) floor($u * $edge)) % $edge;
        $y = ((int) floor($v * $edge)) % $edge;
        $x = $x < 0 ? $x + $edge : $x;
        $y = $y < 0 ? $y + $edge : $y;
        $offset = ($y * $edge + $x) * 3;

        return [$pixels[$offset], $pixels[$offset + 1], $pixels[$offset + 2]];
    }

    private function encode(float $linear, float $gamma): int
    {
        return (int) round(255 * (min(1.0, max(0.0, $linear)) ** (1 / $gamma)));
    }

    /**
     * @param  array<int, int>  $rgba
     */
    private function toPng(array $rgba, int $size): string
    {
        if (class_exists(Imagick::class)) {
            $image = new Imagick;
            $image->newImage($size, $size, 'none', 'png');
            $image->importImagePixels(0, 0, $size, $size, 'RGBA', Imagick::PIXEL_CHAR, $rgba);
            $image->setImageFormat('png');
            $blob = $image->getImageBlob();
            $image->clear();

            return $blob;
        }

        $image = imagecreatetruecolor(max(1, $size), max(1, $size));
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, (int) $clear);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $offset = ($y * $size + $x) * 4;
                /** @var int<0, 127> $alpha */
                $alpha = max(0, min(127, 127 - (int) round($rgba[$offset + 3] / 255 * 127)));
                imagesetpixel($image, $x, $y, (int) imagecolorallocatealpha($image, $this->clamp($rgba[$offset]), $this->clamp($rgba[$offset + 1]), $this->clamp($rgba[$offset + 2]), $alpha));
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return int<0, 255>
     */
    private function clamp(int $value): int
    {
        /** @var int<0, 255> $clamped */
        $clamped = max(0, min(255, $value));

        return $clamped;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $v
     * @return array{0: float, 1: float, 2: float}
     */
    private function normalize(array $v): array
    {
        $length = sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]) ?: 1.0;

        return [$v[0] / $length, $v[1] / $length, $v[2] / $length];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $a
     * @param  array{0: float, 1: float, 2: float}  $b
     * @return array{0: float, 1: float, 2: float}
     */
    private function cross(array $a, array $b): array
    {
        return [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];
    }
}
