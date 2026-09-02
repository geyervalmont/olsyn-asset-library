<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * @property int $tenant_id
 */
class Media extends BaseMedia
{
    use BelongsToTenant;
}
