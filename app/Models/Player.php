<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = ['name', 'nickname', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function participations(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_players')
            ->withPivot('side')
            ->withTimestamps();
    }

    public function queueEntries(): BelongsToMany
    {
        return $this->belongsToMany(QueueEntry::class, 'queue_entry_players');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Short name for the board. Falls back to the full name.
     */
    public function getShortNameAttribute(): string
    {
        return $this->nickname ?: $this->name;
    }

    /**
     * Two-letter marker used on the ball chips.
     */
    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return strtoupper(mb_substr($this->name, 0, 2));
    }
}
