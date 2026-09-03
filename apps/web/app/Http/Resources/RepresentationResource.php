<?php

namespace App\Http\Resources;

use App\Models\Representation;
use App\Models\RepresentationFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Representation
 */
class RepresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'target' => $this->target->slug,
            'quality' => $this->quality->slug,
            'pixels' => $this->quality->pixels,
            'kind' => $this->kind,
            'review_state' => $this->review_state->value,
            'files' => $this->whenLoaded('representationFiles', fn () => $this->representationFiles->map(fn (RepresentationFile $representationFile): array => [
                'role' => $representationFile->role->slug,
                'url' => $representationFile->file->url(),
                'mime_type' => $representationFile->file->mime_type,
                'bytes' => $representationFile->file->bytes,
                'width_px' => $representationFile->file->width_px,
                'height_px' => $representationFile->file->height_px,
                'sha256' => $representationFile->file->sha256,
            ])->values()->all()),
        ];
    }
}
