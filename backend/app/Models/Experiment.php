<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An A/B test (SOW 2.18) on one placement of the site (an element marked data-exp="{key}"): each variant
 * has a weight and the text it shows. Only visitors who accepted cookies, and signed-in players, take part.
 */
class Experiment extends Model
{
    public const PLACEMENTS = [
        'home_hero_cta' => 'Home page: main button',
        'basket_upsell' => 'Basket: suggested competitions heading',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ExperimentAssignment::class);
    }

    /** Deterministic: the same subject always gets the same variant. */
    public function pick(string $subject): array
    {
        $variants = array_values(array_filter($this->variants ?? [], fn ($v) => ($v['weight'] ?? 0) > 0));
        $total = array_sum(array_column($variants, 'weight')) ?: 1;
        $n = hexdec(substr(hash('sha256', $this->key.'|'.$subject), 0, 8)) % $total;
        foreach ($variants as $v) {
            $n -= (int) $v['weight'];
            if ($n < 0) {
                return $v;
            }
        }

        return $variants[0] ?? ['key' => 'control', 'weight' => 1, 'text' => null];
    }
}
