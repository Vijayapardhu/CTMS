<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Records security- and operations-relevant events to the audit trail.
 *
 * Auditing must never break the operation it is auditing: every write is
 * best-effort and a failure is logged rather than thrown. The caller's
 * transaction is not put at risk by a bad audit row.
 */
class AuditLogger
{
    /**
     * Attribute names that must never be written to the audit trail.
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'secret',
    ];

    /**
     * Record an arbitrary action.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        string $table,
        ?string $recordId = null,
        ?array $old = null,
        ?array $new = null,
        ?User $actor = null,
    ): void {
        try {
            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId,
                'old_values' => $old === null ? null : $this->redact($old),
                'new_values' => $new === null ? null : $this->redact($new),
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to write audit log', [
                'action' => $action,
                'table' => $table,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record the creation of a model.
     */
    public function created(Model $model, ?User $actor = null): void
    {
        $this->log(
            action: 'CREATE',
            table: $model->getTable(),
            recordId: (string) $model->getKey(),
            new: $model->getAttributes(),
            actor: $actor,
        );
    }

    /**
     * Record an update, capturing only what actually changed.
     */
    public function updated(Model $model, array $before, ?User $actor = null): void
    {
        $after = $model->getAttributes();
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $after),
            array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $before),
        ));

        if ($changed === []) {
            return; // Nothing changed; an empty audit row is noise.
        }

        $this->log(
            action: 'UPDATE',
            table: $model->getTable(),
            recordId: (string) $model->getKey(),
            old: array_intersect_key($before, array_flip($changed)),
            new: array_intersect_key($after, array_flip($changed)),
            actor: $actor,
        );
    }

    /**
     * Record a deletion.
     */
    public function deleted(Model $model, ?User $actor = null): void
    {
        $this->log(
            action: 'DELETE',
            table: $model->getTable(),
            recordId: (string) $model->getKey(),
            old: $model->getAttributes(),
            actor: $actor,
        );
    }

    /**
     * Strip secrets before anything reaches persistent storage.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $values[$key] = '[REDACTED]';
            }
        }

        return $values;
    }

    private function clientIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null; // No request context (queue worker, console command).
        }
    }

    private function userAgent(): ?string
    {
        try {
            $agent = Request::userAgent();

            return $agent === null ? null : mb_substr($agent, 0, 255);
        } catch (\Throwable) {
            return null;
        }
    }
}
