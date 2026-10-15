<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionEvent extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<OperationalException, $this> */
    public function exception(): BelongsTo
    {
        return $this->belongsTo(OperationalException::class, 'operational_exception_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
