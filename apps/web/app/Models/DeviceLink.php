<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A device-code link: a client shows a short code, a signed-in person
 * approves it in the browser, the client collects its token by polling.
 *
 * @property int $id
 * @property string $code
 * @property string $secret_hash
 * @property string $client
 * @property string|null $machine
 * @property string|null $app_version
 * @property int|null $user_id
 * @property int|null $token_id
 * @property string|null $token_plain
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $delivered_at
 * @property-read User|null $user
 */
#[Fillable(['code', 'secret_hash', 'client', 'machine', 'app_version', 'expires_at'])]
#[Hidden(['secret_hash', 'token_plain'])]
class DeviceLink extends Model
{
    /** Letters and digits that are hard to misread. */
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_plain' => 'encrypted',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A fresh, unused code in the form XXXX-XXXX.
     */
    public static function generateCode(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = substr($raw, 0, 4).'-'.substr($raw, 4);
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * Codes are case-insensitive and tolerate a missing dash.
     */
    public static function normaliseCode(string $input): string
    {
        $raw = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));

        return strlen($raw) === 8 ? substr($raw, 0, 4).'-'.substr($raw, 4) : $raw;
    }

    public static function findByCode(string $input): ?self
    {
        return self::query()->where('code', self::normaliseCode($input))->first();
    }

    public function secretMatches(string $secret): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $secret));
    }

    public function isExpired(): bool
    {
        return $this->claimed_at === null && $this->expires_at->isPast();
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * How the client will be labelled, e.g. "Revit on HARRISON-VM".
     */
    public function label(): string
    {
        $client = Str::of($this->client)->replace(['-', '_'], ' ')->title()->toString();

        return $this->machine ? $client.' on '.$this->machine : $client;
    }
}
