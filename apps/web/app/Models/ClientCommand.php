<?php

namespace App\Models;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something the web asked a client session to do.
 *
 * @property int $id
 * @property int $client_session_id
 * @property int $issued_by
 * @property CommandType $type
 * @property array<string, mixed> $payload
 * @property CommandStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $message
 * @property Carbon|null $acked_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read ClientSession $session
 * @property-read User $issuer
 */
#[Fillable(['client_session_id', 'issued_by', 'type', 'payload', 'status', 'result', 'message', 'acked_at', 'finished_at'])]
class ClientCommand extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CommandType::class,
            'status' => CommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'acked_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClientSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ClientSession::class, 'client_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->getKey(),
            'session_id' => $this->client_session_id,
            'type' => $this->type->value,
            'payload' => $this->payload,
            'status' => $this->status->value,
            'result' => $this->result,
            'message' => $this->message,
        ];
    }
}
