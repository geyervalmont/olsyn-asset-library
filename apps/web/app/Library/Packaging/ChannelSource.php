<?php

namespace App\Library\Packaging;

/**
 * One image, at one tier, for one map role — with the two things about it that
 * cannot be recovered from the pixels.
 */
readonly class ChannelSource
{
    public function __construct(
        public string $sha256,
        public string $objectKey,
        public int $bytes,
        public ?string $colourSpace,
        public ?int $widthPx = null,
        public ?int $heightPx = null,
        public ?string $normalConvention = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sha256' => $this->sha256,
            'object_key' => $this->objectKey,
            'bytes' => $this->bytes,
            'colour_space' => $this->colourSpace,
            'width_px' => $this->widthPx,
            'height_px' => $this->heightPx,
            'normal_convention' => $this->normalConvention,
        ];
    }
}
