<?php

namespace App\Http\Controllers\Clients;

use App\Support\ClientReleaseCatalog;
use Illuminate\Http\RedirectResponse;

class RevitDownloadController
{
    public function show(int $revitVersion, string $channel, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return redirect()->away($releases->downloadUrl('revit', $channel, $asset, $revitVersion));
    }

    public function legacy(string $channel, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return redirect()->away($releases->downloadUrl('revit', $channel, $asset));
    }
}
