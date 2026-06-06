<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vm_player_id', 'name', 'position', 'training_bar', 'active'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vm_player_id' => 'integer',
            'position' => PlayerPosition::class,
            'training_bar' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function remainingTrainingCapacity(): int
    {
        return max(0, 100 - $this->training_bar);
    }

    public function maxTrainingGainPerMatch(): int
    {
        return min(50, $this->remainingTrainingCapacity());
    }

    public function projectedTrainingBar(int $playedActions): int
    {
        return min(100, $this->training_bar + min($playedActions, $this->maxTrainingGainPerMatch()));
    }

    public function wastedTrainingActions(int $playedActions): int
    {
        return max(0, $playedActions - $this->maxTrainingGainPerMatch());
    }
}
