<?php

namespace App\Actions\Versions;

use App\Enums\VersionStatus;
use App\Models\Material;
use App\Models\MaterialVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Make a version current. The previously current version is superseded;
 * nothing else changes, so a rollback is the same operation in reverse.
 */
class PublishVersion
{
    public function handle(MaterialVersion $version, ?User $publishedBy = null): MaterialVersion
    {
        return DB::transaction(function () use ($version, $publishedBy): MaterialVersion {
            $version = MaterialVersion::query()->lockForUpdate()->whereKey($version->getKey())->firstOrFail();
            $material = Material::query()->lockForUpdate()->findOrFail($version->material_id);
            $version->setRelation('material', $material);

            if ($version->representations()->count() === 0) {
                throw new LogicException("Version {$version->number} of [{$material->code}] is empty.");
            }

            if ($material->current_version_id !== null && (string) $material->current_version_id !== (string) $version->getKey()) {
                MaterialVersion::query()
                    ->whereKey($material->current_version_id)
                    ->update(['status' => VersionStatus::Superseded->value]);
            }

            $version->forceFill([
                'status' => VersionStatus::Published,
                'published_by_user_id' => $publishedBy?->getKey() ?? $version->published_by_user_id,
                'published_at' => $version->published_at ?? now(),
            ])->save();

            $material->forceFill(['current_version_id' => $version->getKey()])->save();
            $material->unsetRelation('currentVersion');

            return $version->refresh();
        });
    }
}
