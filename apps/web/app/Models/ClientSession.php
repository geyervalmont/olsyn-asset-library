<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A running material consumer and the commands it supports.
 *
 * @property string|null $drive_status
 * @property string|null $mount_path
 * @property string|null $drive_error
 * @property int $id
 * @property int $user_id
 * @property string $platform
 * @property string $machine
 * @property string|null $app_version
 * @property string|null $document
 * @property list<string>|null $capabilities
 * @property int|null $token_id
 * @property Carbon $last_seen_at
 * @property Carbon|null $ended_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'platform', 'machine', 'app_version', 'document', 'capabilities', 'token_id', 'last_seen_at', 'ended_at', 'drive_status', 'mount_path', 'drive_error'])]
class ClientSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'ended_at' => 'datetime', 'capabilities' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ClientCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(ClientCommand::class);
    }

    /**
     * Sessions still heartbeating.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('last_seen_at', '>=', now()->subSeconds(self::liveSeconds()));
    }

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->last_seen_at->gte(now()->subSeconds(self::liveSeconds()));
    }

    public static function liveSeconds(): int
    {
        return (int) config('opal.sessions.live_seconds', 90);
    }

    public function channel(): string
    {
        return 'revit-session.'.$this->getKey();
    }

    /** @return list<string> */
    public function supportedCapabilities(): array
    {
        // Existing Revit releases already consume both command payloads.
        // Older Omniverse releases only announce presence, so offer no actions.
        return $this->capabilities ?? ($this->platform === 'revit' ? ['material.apply', 'draft.apply'] : []);
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function platformLabel(): string
    {
        return Str::headline($this->platform);
    }

    /**
     * "Revit 2027 · HARRISON-VM · Tower A.rvt"
     */
    public function label(): string
    {
        $parts = [trim($this->platformLabel().' '.($this->app_version ?? '')), $this->machine];

        if ($this->document) {
            $parts[] = $this->document;
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->getKey(),
            'platform' => $this->platform,
            'platform_label' => $this->platformLabel(),
            'capabilities' => $this->supportedCapabilities(),
            'machine' => $this->machine,
            'app_version' => $this->app_version,
            'document' => $this->document,
            'label' => $this->label(),
            'live' => $this->isLive(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
        ];
    }
}
