<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['pushed_to_source' => 'boolean'];

    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
