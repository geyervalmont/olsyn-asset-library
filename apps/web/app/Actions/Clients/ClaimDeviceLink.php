<?php

namespace App\Actions\Clients;

use App\Models\DeviceLink;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in person approves a device code: a token is minted for the
 * client and parked on the link until the client polls it.
 */
class ClaimDeviceLink
{
    public function handle(DeviceLink $link, User $user): DeviceLink
    {
        if ($link->isClaimed()) {
            throw ValidationException::withMessages(['code' => 'This code has already been used.']);
        }

        if ($link->isExpired()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Start the link again in the client.']);
        }

        $token = $user->createToken($link->label());

        $link->forceFill([
            'user_id' => $user->getKey(),
            'token_id' => $token->accessToken->getKey(),
            'token_plain' => $token->plainTextToken,
            'claimed_at' => now(),
        ])->save();

        return $link;
    }
}
