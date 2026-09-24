<?php

namespace App\Http\Controllers\Api;

use App\Library\Drives\DriveNamespace;
use App\Library\Drives\HttpFileResponse;
use App\Models\Category;
use App\Models\File;
use App\Models\Material;
use App\Models\Package;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Small pages for interactive clients. Texture bytes are fetched separately. */
class ConsumerLibraryController
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'], 'category' => ['nullable', 'string', 'max:8'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $materials = Material::query()->visibleTo($request->user())->whereNotNull('current_version_id')
            ->search((string) ($input['q'] ?? ''))
            ->when(! empty($input['category']), fn ($query) => $query->whereRelation('category', 'code', strtoupper($input['category'])));
        $page = Variant::query()->whereIn('material_id', $materials->select('id'))
            ->with(['material.category', 'material.supplier', 'material.currentVersion'])
            ->orderBy('code')->paginate((int) ($input['per_page'] ?? 24));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Variant $variant): array => [
                'uuid' => $variant->uuid, 'material_uuid' => $variant->material->uuid,
                'code' => $variant->code, 'name' => $variant->name, 'material_name' => $variant->material->name,
                'description' => $variant->material->description,
                'category' => $variant->material->category->name, 'supplier' => $variant->material->supplier?->name,
                'material_version' => $variant->material->currentVersion?->number,
                'tile_width_mm' => $variant->effectiveTileWidthMm(), 'tile_height_mm' => $variant->effectiveTileHeightMm(),
                'preview_url' => route('api.library.preview', ['uuid' => $variant->uuid], false),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function facets(Request $request): JsonResponse
    {
        $visible = Material::query()->visibleTo($request->user())->whereNotNull('current_version_id');

        return response()->json(['data' => ['categories' => Category::whereIn('id', $visible->select('category_id'))->orderBy('name')->get(['code', 'name'])]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function resolve(Request $request, string $uuid, DriveNamespace $namespace): JsonResponse
    {
        $input = $request->validate([
            'target' => ['required', 'in:revit,omniverse'], 'version' => ['nullable', 'integer', 'min:1'],
        ]);
        $variant = $this->variant($request, $uuid);
        $entries = collect($namespace->entriesForUserVariant($request->user(), $variant, $input['version'] ?? null));
        // Consumer quality changes; the UUID, material version and source stay pinned.
        $targets = $input['target'] === 'revit' ? ['revit'] : ['pbr'];
        $chosen = collect();
        foreach ($targets as $target) {
            $candidates = $entries->where('target', $target)->groupBy('derivative_uuid');
            if ($input['target'] === 'revit') {
                $candidates = $candidates->filter(fn ($files) => in_array($files->first()['quality'], ['preview', '512'], true));
            }
            $chosen = $candidates->sortByDesc(fn ($files) => $this->pixels($files->first()['quality']))->first() ?? collect();
            if ($chosen->isNotEmpty()) {
                break;
            }
        }
        if ($chosen->isEmpty() && $input['target'] === 'omniverse') {
            return $this->canonical($variant, $input['version'] ?? null);
        }
        abort_if($chosen->isEmpty(), 409, $input['target'] === 'revit' ? 'Publish a 512 px Revit preview for this material first.' : 'Publish a PBR material for Omniverse first.');
        $first = $chosen->first();

        return response()->json(['data' => [
            'contract' => 'opal-material/1', 'material_uuid' => $variant->material->uuid, 'variant_uuid' => $variant->uuid,
            'code' => $variant->code, 'name' => $variant->name, 'material_name' => $variant->material->name,
            'material_version' => $first['material_version'], 'source_package_sha256' => $first['source_package_sha256'],
            'derivative_uuid' => $first['derivative_uuid'], 'target' => $first['target'], 'quality' => $first['quality'],
            'tile_width_mm' => $variant->effectiveTileWidthMm(), 'tile_height_mm' => $variant->effectiveTileHeightMm(),
            'files' => $chosen->map(fn ($entry) => [
                'role' => $entry['role'], 'path' => $entry['path'], 'sha256' => $entry['sha256'], 'bytes' => $entry['object']['size'],
                'url' => route('api.drive.files', ['derivative' => $entry['derivative_uuid'], 'file' => $entry['file_id']], false),
            ])->values(),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, string $uuid, DriveNamespace $namespace, HttpFileResponse $stream): Response
    {
        $entries = collect($namespace->entriesForUserVariant($request->user(), $this->variant($request, $uuid)))
            ->where('role', 'base_color')->sortBy(fn ($entry) => $this->pixels($entry['quality']));
        $entry = $entries->first();
        abort_if($entry === null, 404);

        return $stream->send($request, File::findOrFail((int) $entry['file_id']));
    }

    public function package(Request $request, int $package): Response
    {
        $package = Package::query()->whereKey($package)->whereHas('versions', fn ($query) => $query->whereNotNull('published_at'))
            ->whereHas('variant', fn ($query) => $query->whereIn('material_id', Material::query()->visibleTo($request->user())->select('id')))->firstOrFail();

        return Storage::disk(config('opal.packages_disk'))->response($package->object_key, $package->sha256.'.usdz', [
            'Content-Type' => 'model/vnd.usdz+zip', 'ETag' => '"'.$package->sha256.'"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canonical(Variant $variant, ?int $number): JsonResponse
    {
        $number ??= $variant->material->currentVersion?->number;
        $version = $variant->material->versions()->where('number', $number)->whereNotNull('published_at')->first();
        abort_if($version === null, 409, 'The requested published material version is unavailable.');
        $package = $version->packages()->where('packages.variant_id', $variant->id)->firstOrFail();
        $quality = collect($package->tiers)->sortByDesc(fn ($tier) => $this->pixels($tier))->first();

        return response()->json(['data' => [
            'contract' => 'opal-material/1', 'material_uuid' => $variant->material->uuid, 'variant_uuid' => $variant->uuid,
            'code' => $variant->code, 'name' => $variant->name, 'material_name' => $variant->material->name,
            'material_version' => $version->number, 'source_package_sha256' => $package->sha256,
            'target' => 'omniverse', 'quality' => $quality, 'format' => 'canonical-usdz',
            'tile_width_mm' => $variant->effectiveTileWidthMm(), 'tile_height_mm' => $variant->effectiveTileHeightMm(),
            'files' => [[
                'role' => 'package', 'path' => '/materials/by-id/'.$variant->material->uuid.'/'.$variant->uuid.'/v'.$version->number.'/canonical/'.$package->sha256.'.usdz',
                'sha256' => $package->sha256, 'bytes' => $package->bytes,
                'url' => route('api.library.package', ['package' => $package->id], false),
            ]],
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function variant(Request $request, string $uuid): Variant
    {
        return Variant::where('uuid', $uuid)->whereIn('material_id', Material::query()->visibleTo($request->user())->select('id'))
            ->with('material.currentVersion')->firstOrFail();
    }

    private function pixels(string $quality): int
    {
        return match ($quality) {
            'preview' => 512, '1k' => 1024, '2k' => 2048, '4k' => 4096, '8k' => 8192, default => (int) $quality
        };
    }
}
