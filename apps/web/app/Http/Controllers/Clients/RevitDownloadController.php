<?php

namespace App\Http\Controllers\Clients;

use App\Support\ClientReleaseCatalog;
use Illuminate\Http\RedirectResponse;

class RevitDownloadController
{
    public function __invoke(string $channel, string $asset, ClientReleaseCatalog $releases): RedirectResponse
    {
        return redirect()->away($releases->downloadUrl('revit', $channel, $asset));
    }
}
