<?php

namespace App\Http\Controllers;

use App\Library\Studio\DraftStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class StudioPhotoController
{
    public function __invoke(Request $request, DraftStore $store): RedirectResponse
    {
        abort_unless($request->user()?->can('materials.contribute'), 403);
        $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:20480|dimensions:max_width=8192,max_height=8192', 'name' => 'required|string|max:120']);
        /** @var UploadedFile $photo */
        $photo = $request->file('photo');
        $info = getimagesize($photo->getRealPath());
        if ($info === false || $info[0] * $info[1] > 24000000) {
            throw ValidationException::withMessages(['photo' => 'Use a photo with at most 24 megapixels.']);
        }
        // Handle the upload and persist the draft in one request. This works
        // across web replicas without public temporary storage or sticky sessions.
        $draft = $store->fromPhoto($photo, (string) $request->input('name'));

        return redirect()->route('materials.studio.photos', ['draft' => $draft->uuid]);
    }
}
