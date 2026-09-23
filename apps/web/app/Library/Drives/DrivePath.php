<?php

namespace App\Library\Drives;

use Illuminate\Validation\ValidationException;

/** A portable, relative Windows/POSIX path, never a storage object key. */
final class DrivePath
{
    public static function validate(string $path): string
    {
        $valid = $path !== '' && strlen($path) <= 512 && ! preg_match('/[\\\\\x00-\x1f\x7f<>:"|?*]/u', $path);
        foreach (explode('/', $path) as $part) {
            $valid = $valid && $part !== '' && ! in_array($part, ['.', '..'], true)
                && ! preg_match('/[. ]$/u', $part) && strlen($part) <= 200
                && ! preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part);
        }
        if (! $valid) {
            throw ValidationException::withMessages(['path' => 'Use a relative path with ordinary folder and file names.']);
        }

        return $path;
    }
}
