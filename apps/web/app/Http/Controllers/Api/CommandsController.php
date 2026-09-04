<?php

namespace App\Http\Controllers\Api;

use App\Enums\CommandStatus;
use App\Events\Realtime\CommandAcked;
use App\Events\Realtime\CommandCompleted;
use App\Models\ClientCommand;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandsController
{
    /**
     * Acknowledge a command: the client has it and is working on it.
     */
    public function ack(Request $request, int $command): JsonResponse
    {
        $command = $this->mine($request, $command);

        if ($command->status === CommandStatus::Queued) {
            $command->forceFill(['status' => CommandStatus::Acked, 'acked_at' => now()])->save();
            Realtime::publish(new CommandAcked($command));
        }

        return response()->json(['data' => $command->toBroadcast()]);
    }

    /**
     * Report the outcome of a command.
     */
    public function result(Request $request, int $command): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['done', 'failed'])],
            'result' => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $command = $this->mine($request, $command);

        $command->forceFill([
            'status' => CommandStatus::from($validated['status']),
            'result' => $validated['result'] ?? null,
            'message' => $validated['message'] ?? null,
            'acked_at' => $command->acked_at ?? now(),
            'finished_at' => now(),
        ])->save();

        Realtime::publish(new CommandCompleted($command));

        return response()->json(['data' => $command->toBroadcast()]);
    }

    private function mine(Request $request, int $id): ClientCommand
    {
        return ClientCommand::query()
            ->whereKey($id)
            ->whereHas('session', fn ($query) => $query->where('user_id', $request->user()->getKey()))
            ->firstOrFail();
    }
}
