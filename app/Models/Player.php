<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vm_player_id', 'name', 'position', 'training_bar', 'active', 'is_injured', 'isInjured'])]
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
            'is_injured' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->active()->where('is_injured', false);
    }

    protected function isInjured(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): bool => (bool) ($attributes['is_injured'] ?? $value ?? false),
            set: fn (mixed $value): array => ['is_injured' => (bool) $value],
        );
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
