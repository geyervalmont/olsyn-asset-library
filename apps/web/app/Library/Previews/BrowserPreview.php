<?php

namespace App\Library\Previews;

use App\Models\File;
use Illuminate\Support\Facades\Cache;

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
            abort_unless($info[0] * $info[1] <= 16777216, 413);
            $source = @imagecreatefromstring($bytes);
            abort_unless($source !== false, 415);

            $scale = min(1, $size / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $scale));
            $height = max(1, (int) round($info[1] * $scale));
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            ob_start();
            imagewebp($image, null, 80);

            return (string) ob_get_clean();
        });
    }
}
