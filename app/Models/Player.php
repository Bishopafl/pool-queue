<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /**
     * Lineup wedge colours, drawn from the room palette in public/css/app.css.
     * Cool tones lead (typical Side A), warm tones follow (typical Side B).
     */
    public const COLOR_SWATCHES = [
        '#5e9bc7', // chalk blue
        '#3a6f96', // deep blue
        '#2d63a8', // two ball
        '#2e8b57', // six ball green
        '#6b4a8f', // four ball purple
        '#f0c14b', // nine ball gold
        '#d97b2e', // five ball orange
        '#c1483f', // three ball red
        '#7b3a34', // seven ball maroon
        '#7a5335', // rail timber
    ];

    protected $fillable = ['name', 'nickname', 'is_active', 'side_a_color', 'side_b_color'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Distinct default swatches for a newly added player, spread around the
     * palette so the roster doesn't come out all one colour.
     *
     * @return array{side_a_color: string, side_b_color: string}
     */
    public static function defaultColorsFor(int $existingCount): array
    {
        $count = count(self::COLOR_SWATCHES);

        return [
            'side_a_color' => self::COLOR_SWATCHES[$existingCount % $count],
            'side_b_color' => self::COLOR_SWATCHES[($existingCount + intdiv($count, 2)) % $count],
        ];
    }

    public function colorForSide(string $side): string
    {
        return $side === 'b' ? $this->side_b_color : $this->side_a_color;
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
