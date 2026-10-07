<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisionEvent extends Model
{
    protected $fillable = [
        'event_id',
        'game_id',
        'table_label',
        'type',
        'kind',
        'pocket',
        'kind_conf',
        'video_t',
        'applied',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'applied' => 'boolean',
            'kind_conf' => 'float',
            'video_t' => 'float',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
