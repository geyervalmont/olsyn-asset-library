<?php

namespace App\Library\Previews;

use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Imagick;

class BrowserPreview
{
    public function key(File $file, int $size): string
    {
        return 'browser-preview-v1-'.$file->sha256.'-'.$size;
    }

    public function contents(File $file, int $size): string
    {
        // Private, disposable disk cache. Original files and published packages
        // remain immutable; resizing is paid once per file/size on each server.
        return Cache::store('file')->remember($this->key($file, $size), now()->addDays(30), function () use ($file, $size): string {
            abort_unless($file->bytes <= 20 * 1024 * 1024, 413);
            $bytes = $file->contents();
            $info = @getimagesizefromstring($bytes);
            abort_unless($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true), 415);
            $pixels = $info[0] * $info[1];
            abort_unless($pixels <= 67108864, 413);

            $scale = min(1, $size / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $scale));
            $height = max(1, (int) round($info[1] * $scale));

            // 8K source maps are common. Keep their large decoded buffers out
            // of GD/PHP memory; ImageMagick can spill its pixel cache to disk.
            if ($pixels > 16777216) {
                return $this->largeImage($bytes, $width, $height);
            }

            $source = @imagecreatefromstring($bytes);
            abort_unless($source !== false, 415);
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            ob_start();
            imagewebp($image, null, 80);

            return (string) ob_get_clean();
        });
    }

    private function largeImage(string $bytes, int $width, int $height): string
    {
        abort_unless(class_exists(Imagick::class), 413);
        $limits = [
            Imagick::RESOURCETYPE_MEMORY => 64 * 1024 * 1024,
            Imagick::RESOURCETYPE_MAP => 128 * 1024 * 1024,
            Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
            Imagick::RESOURCETYPE_THREAD => 1,
        ];
        $previous = [];
        $image = new Imagick;

        try {
            foreach ($limits as $resource => $limit) {
                $previous[$resource] = Imagick::getResourceLimit($resource);
                Imagick::setResourceLimit($resource, min($limit, $previous[$resource]));
            }

            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->thumbnailImage($width, $height);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(80);

            return $image->getImageBlob();
        } finally {
            $image->clear();
            foreach ($previous as $resource => $limit) {
                Imagick::setResourceLimit($resource, $limit);
            }
        }
    }
}
