<?php

namespace App\Actions\Files;

use App\Models\File;
use App\Models\Material;
use App\Models\PackageDerivativeFile;
use App\Models\RepresentationFile;
use App\Models\User;

class AuthorizeFileRead
{
    public function handle(File $file, ?User $user): void
    {
        abort_unless($user?->can('materials.view'), 403);

        // Browser previews and original downloads use the same material grants.
        // Publishers also need to inspect files that are not yet attached.
        $visible = Material::query()->visibleTo($user)->select('id');
        abort_unless($user->can('materials.publish')
            || RepresentationFile::query()->where('file_id', $file->id)
                ->whereHas('representation.variant', fn ($query) => $query->whereIn('material_id', clone $visible))->exists()
            || PackageDerivativeFile::query()->where('file_id', $file->id)
                ->whereHas('derivative.package.variant', fn ($query) => $query->whereIn('material_id', clone $visible))->exists(), 404);
    }
}
