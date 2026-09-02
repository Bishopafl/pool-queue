<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class QueueEntry extends Model
{
    protected $fillable = ['position', 'label'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'queue_entry_players');
    }

    public function displayName(): string
    {
        if ($this->label) {
            return $this->label;
        }

        return $this->players->pluck('short_name')->join(' & ') ?: 'Empty slot';
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
