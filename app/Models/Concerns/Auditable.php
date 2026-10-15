<?php

namespace App\Models\Concerns;

use App\Models\ControlRoom;
use App\Services\Audits;

/**
 * Writes created/updated/deleted audit rows for a model. The model must
 * define auditControlRoom() (ControlRoom returns itself). Attributes listed
 * in $auditRedacted are recorded as changed but never with their values.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            $changes = [];
            foreach ($model->auditableAttributes($model->getAttributes()) as $key => $value) {
                $changes[$key] = ['old' => null, 'new' => $value];
            }
            app(Audits::class)->record('created', $model, $changes, redact: $model->auditRedactedAttributes());
        });

        static::updated(function (self $model): void {
            $changes = [];
            foreach ($model->auditableAttributes($model->getChanges()) as $key => $value) {
                $changes[$key] = ['old' => $model->getOriginal($key), 'new' => $value];
            }
            if ($changes === []) {
                return;
            }
            app(Audits::class)->record('updated', $model, $changes, redact: $model->auditRedactedAttributes());
        });

        // Recorded before the row disappears so the control-room foreign key
        // is still valid at insert time; entity_id keeps the deleted key.
        static::deleting(function (self $model): void {
            app(Audits::class)->record('deleted', $model);
        });
    }

    public function auditControlRoom(): ?ControlRoom
    {
        return $this instanceof ControlRoom ? $this : $this->controlRoom;
    }

    /**
     * @return list<string>
     */
    public function auditRedactedAttributes(): array
    {
        return property_exists($this, 'auditRedacted') ? $this->auditRedacted : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        unset($attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }
}
