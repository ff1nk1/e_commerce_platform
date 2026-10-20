<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property Carbon|null $created_at
 * @property Carbon $last_activity_at
 * @property Carbon $expires_at
 * @property-read Collection<int, Message> $messages
 */
class Conversation extends Model
{
    use HasUuids;

    public const HARD_TTL_HOURS = 24;

    public const IDLE_TTL_MINUTES = 30;

    protected $fillable = [
        'user_id',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public static function computeExpiresAt(CarbonInterface $createdAt, CarbonInterface $lastActivityAt): Carbon
    {
        $hardCap = (new Carbon($createdAt))->addHours(self::HARD_TTL_HOURS);
        $idleCap = (new Carbon($lastActivityAt))->addMinutes(self::IDLE_TTL_MINUTES);

        return $hardCap->lt($idleCap) ? $hardCap : $idleCap;
    }

    public function touchActivity(?CarbonInterface $at = null): void
    {
        $at = new Carbon($at ?? now());
        $this->last_activity_at = $at;
        $this->expires_at = self::computeExpiresAt($this->created_at ?? $at, $at);
        $this->save();
    }
}
