<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    /**
     * Player counts per side, keyed by format. For 2v1 either side may hold
     * the pair, so counts are compared as an unordered pair.
     */
    public const FORMATS = [
        '1v1' => [1, 1],
        '2v1' => [2, 1],
        '2v2' => [2, 2],
    ];

    protected $fillable = [
        'format',
        'status',
        'table_label',
        'break_side',
        'side_a_ball_group',
        'side_b_ball_group',
        'side_a_score',
        'side_b_score',
        'winner_side',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'side_a_score' => 'integer',
            'side_b_score' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function participations(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'game_players')
            ->withPivot('side')
            ->withTimestamps();
    }

    /**
     * Players on one side of the table. Expects the players relation to be loaded.
     */
    public function sidePlayers(string $side): Collection
    {
        return $this->players
            ->filter(fn (Player $player) => $player->pivot->side === $side)
            ->values();
    }

    public function ballGroup(string $side): ?string
    {
        return $side === 'a' ? $this->side_a_ball_group : $this->side_b_ball_group;
    }

    public function score(string $side): int
    {
        return (int) ($side === 'a' ? $this->side_a_score : $this->side_b_score);
    }

    public function sideLabel(string $side): string
    {
        $players = $this->sidePlayers($side);

        return $players->isEmpty()
            ? 'Side ' . strtoupper($side)
            : $players->pluck('short_name')->join(' & ');
    }

    public function loserSide(): ?string
    {
        if ($this->winner_side === null) {
            return null;
        }

        return $this->winner_side === 'a' ? 'b' : 'a';
    }

    public function isLive(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public static function otherSide(string $side): string
    {
        return $side === 'a' ? 'b' : 'a';
    }

    public static function otherBallGroup(?string $group): ?string
    {
        return match ($group) {
            'stripes' => 'solids',
            'solids' => 'stripes',
            default => null,
        };
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
