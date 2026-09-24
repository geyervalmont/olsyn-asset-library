<?php

namespace App\Library\Drives;

use App\Models\File;
use App\Models\FileAccess;
use App\Models\Package;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Same-origin authenticated reads, including single HTTP byte ranges. */
final class HttpFileResponse
{
    public function sendPackage(Request $request, Package $package): Response
    {
        // A virtual file descriptor reuses the bounded range stream. The audit path identifies the package.
        return $this->send($request, new File([
            'disk' => config('opal.packages_disk'), 'object_key' => $package->object_key,
            'bytes' => $package->bytes, 'sha256' => $package->sha256, 'mime_type' => 'model/vnd.usdz+zip',
        ]));
    }

    public function send(Request $request, File $file): Response
    {
        $size = $file->bytes;
        $etag = '"'.$file->sha256.'"';
        $headers = ['Content-Type' => $file->mime_type, 'Accept-Ranges' => 'bytes', 'ETag' => $etag,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $file->sha256, 'Content-Length' => (string) $size];
        // Caller has already authorized the exact published derivative before any 304/HEAD.
        if (in_array($etag, $request->getETags(), true) || in_array('*', $request->getETags(), true)) {
            return response('', 304, array_diff_key($headers, ['Content-Length' => true]));
        }
        $start = 0;
        $end = $size - 1;
        $partial = false;
        $range = $request->header('Range');
        if (! $request->isMethod('HEAD') && $range !== null && (! $request->hasHeader('If-Range') || $request->header('If-Range') === $etag)) {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) || ($matches[1] === '' && $matches[2] === '') || $size < 1) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size, 'Cache-Control' => 'private, no-store']);
            }
            if ($matches[1] === '') {
                $suffix = min($size, (int) $matches[2]);
                $start = $size - $suffix;
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : min($end, (int) $matches[2]);
            }
            if ($start > $end || $start < 0 || $start >= $size) {
                return response('', 416, ['Content-Range' => 'bytes */'.$size, 'Cache-Control' => 'private, no-store']);
            }
            $partial = true;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$size;
        }
        $length = max(0, $end - $start + 1);
        $headers['Content-Length'] = (string) $length;
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }
        $disk = Storage::disk($file->disk);
        if ($disk instanceof AwsS3V3Adapter) {
            $options = ['@http' => ['stream' => true], 'Bucket' => config('filesystems.disks.'.$file->disk.'.bucket'), 'Key' => $file->object_key];
            if ($partial) {
                $options['Range'] = 'bytes='.$start.'-'.$end;
            }
            $stream = $disk->getClient()->getObject($options)['Body'];
        } else {
            $resource = $disk->readStream($file->object_key);
            abort_unless(is_resource($resource), 503, 'Material storage is unavailable.');
            $stream = Utils::streamFor($resource);
            if ($start > 0) {
                $stream->seek($start);
            }
        }
        FileAccess::create(['file_id' => $file->id, 'channel' => 'drive_https', 'action' => 'read', 'result' => 'allow',
            'user_id' => $request->user()->id, 'path' => $request->path(), 'bytes' => $length, 'accessed_at' => now()]);

        return response()->stream(function () use ($stream, $length): void {
            $remaining = $length;
            try {
                while ($remaining > 0 && ! $stream->eof()) {
                    $chunk = $stream->read(min(65536, $remaining));
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                $stream->close();
            }
        }, $partial ? 206 : 200, $headers);
    }
}
