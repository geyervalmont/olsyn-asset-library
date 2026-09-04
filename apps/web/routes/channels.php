<?php

use App\Models\ClientSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A person's own browser tabs: session and command updates.
Broadcast::channel('user.{id}', fn (User $user, int $id): bool => $user->getKey() === $id);

// A client session (Revit) listening for its commands.
Broadcast::channel('revit-session.{id}', fn (User $user, int $id): bool => ClientSession::query()
    ->whereKey($id)
    ->where('user_id', $user->getKey())
    ->exists());
