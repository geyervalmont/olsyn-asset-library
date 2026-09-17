<?php

namespace App\Library\QrCodes;

use App\Models\Material;
use App\Models\Variant;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

final class MaterialQrCodes
{
    /**
     * URLs embedded in the authenticated material record. Every URL is
     * permanently signed so it can be opened without an OPAL account while
     * remaining unguessable and resistant to parameter tampering.
     *
     * @param  Collection<int, Variant>  $variants
     * @return list<array{label: string, detail: string, image: string, download: string, target: string}>
     */
    public function options(Material $material, Collection $variants): array
    {
        $options = [[
            'label' => __('Material only'),
            'detail' => $material->code,
            'image' => $this->imageUrl($material),
            'download' => $this->imageUrl($material, download: true),
            'target' => $this->publicUrl($material),
        ]];

        foreach ($variants as $variant) {
            $this->assertVariantBelongsTo($material, $variant);
            $options[] = [
                'label' => __('Colourway — :name', ['name' => $variant->name]),
                'detail' => $variant->code,
                'image' => $this->imageUrl($material, $variant),
                'download' => $this->imageUrl($material, $variant, true),
                'target' => $this->publicUrl($material, $variant),
            ];
        }

        return $options;
    }

    public function publicUrl(Material $material, ?Variant $variant = null): string
    {
        $this->assertVariantBelongsTo($material, $variant);

        return URL::signedRoute('materials.public', array_filter([
            'material' => $material->getKey(),
            'variant' => $variant?->getKey(),
        ], fn (mixed $value): bool => $value !== null));
    }

    public function imageUrl(Material $material, ?Variant $variant = null, bool $download = false): string
    {
        $this->assertVariantBelongsTo($material, $variant);

        return URL::signedRoute('materials.public.qr', array_filter([
            'material' => $material->getKey(),
            'variant' => $variant?->getKey(),
            'download' => $download ? 1 : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function previewUrl(Material $material, Variant $variant): string
    {
        $this->assertVariantBelongsTo($material, $variant);

        return URL::signedRoute('materials.public.preview', [
            'material' => $material->getKey(),
            'variant' => $variant->getKey(),
        ]);
    }

    public function svg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(768, 4), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url, ecLevel: ErrorCorrectionLevel::M());
    }

    private function assertVariantBelongsTo(Material $material, ?Variant $variant): void
    {
        if ($variant !== null && (int) $variant->material_id !== (int) $material->getKey()) {
            throw new InvalidArgumentException('The colourway does not belong to this material.');
        }
    }
}
