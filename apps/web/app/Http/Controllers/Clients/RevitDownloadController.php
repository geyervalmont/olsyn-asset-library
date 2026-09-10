<?php

namespace App\Http\Controllers\Clients;

use App\Support\ClientReleaseCatalog;
use Illuminate\Http\RedirectResponse;

class RevitDownloadController
{
    public function show(string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return redirect()->away($releases->downloadUrl('revit', $asset));
    }

    public function showVersion(int $revitVersion, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return redirect()->away($releases->downloadUrl('revit', $asset, $revitVersion));
    }

    public function legacyChannel(string $channel, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return $this->show($asset, $releases);
    }

    public function legacyVersionChannel(int $revitVersion, string $channel, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return $this->showVersion($revitVersion, $asset, $releases);
    }
}
