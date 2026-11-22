<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A winner's photo and draw video (SOW 2.9). Shown publicly only with the winner's consent. */
class WinnerMedia extends Model
{
    protected $table = 'winner_media';

    protected $fillable = ['verity_winner_id', 'photo_path', 'video_url', 'consent'];

    protected function casts(): array
    {
        return ['consent' => 'boolean'];
    }

    public function photoUrl(): ?string
    {
        return $this->consent && $this->photo_path ? '/storage/'.ltrim($this->photo_path, '/') : null;
    }

    /** Only https links are shown. */
    public function safeVideoUrl(): ?string
    {
        $u = (string) $this->video_url;

        return str_starts_with($u, 'https://') && filter_var($u, FILTER_VALIDATE_URL) ? $u : null;
    }

    /** @param  array<int>  $ids  @return \Illuminate\Support\Collection<int, self> */
    public static function forWinners(array $ids): \Illuminate\Support\Collection
    {
        return $ids ? self::query()->whereIn('verity_winner_id', $ids)->get()->keyBy('verity_winner_id') : collect();
    }
}
