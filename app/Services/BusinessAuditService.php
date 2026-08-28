<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BusinessAuditService
{
    /**
     * Keep the first version intentionally focused on business-critical records.
     * This avoids flooding the audit table with framework/internal model events.
     */
    private const AUDITED_MODELS = [
        'Adjustment',
        'CashRegister',
        'Client',
        'Expense',
        'PaymentSale',
        'Product',
        'Purchase',
        'Sale',
        'Transfer',
        'User',
    ];

    private const IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    private const SENSITIVE_FIELDS = [
        'password',
        'remember_token',
        'api_token',
        'token',
        'secret',
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    public function record(string $event, Model $model): void
    {
        if (! in_array($event, ['created', 'updated', 'deleted'], true)) {
            return;
        }

        if (! in_array(class_basename($model), self::AUDITED_MODELS, true)) {
            return;
        }

        // Tenant migrations may not have run yet. Audit logging must never break
        // the business operation it is observing.
        try {
            if (! Schema::hasTable('business_audit_logs')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        [$before, $after] = $this->changeSet($event, $model);

        if ($event === 'updated' && $before === [] && $after === []) {
            return;
        }

        $user = $this->resolveUser();
        $request = app()->bound('request') ? request() : null;
        $attributes = $model->getAttributes();

        try {
            DB::table('business_audit_logs')->insert([
                'user_id' => $user?->id,
                'actor_name' => $this->actorName($user),
                'event' => $event,
                'auditable_type' => class_basename($model),
                'auditable_id' => $model->getKey() !== null ? (string) $model->getKey() : null,
                'reference' => $this->reference($attributes),
                'branch_id' => $this->contextId($attributes, 'branch_id', $request?->input('branch_id')),
                'inventory_location_id' => $this->contextId($attributes, 'inventory_location_id', $request?->input('inventory_location_id')),
                'cash_drawer_id' => $this->contextId($attributes, 'cash_drawer_id', $request?->input('cash_drawer_id')),
                'before_values' => $before === [] ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_values' => $after === [] ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'http_method' => $request?->method(),
                'request_path' => $request?->path(),
                'ip_address' => $request?->ip(),
                'user_agent' => $this->limitString($request?->userAgent(), 768),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is deliberately fail-open: a logging problem must not roll
            // back or block a sale, purchase, transfer or other ERP operation.
            report($e);
        }
    }

    private function changeSet(string $event, Model $model): array
    {
        if ($event === 'created') {
            return [[], $this->sanitize($model->getAttributes())];
        }

        if ($event === 'deleted') {
            return [$this->sanitize($model->getOriginal()), []];
        }

        $changes = $model->getChanges();
        foreach (self::IGNORED_FIELDS as $field) {
            unset($changes[$field]);
        }

        if ($changes === []) {
            return [[], []];
        }

        $original = $model->getOriginal();
        $before = [];
        $after = [];

        foreach (array_keys($changes) as $field) {
            $before[$field] = $original[$field] ?? null;
            $after[$field] = $changes[$field] ?? null;
        }

        return [$this->sanitize($before), $this->sanitize($after)];
    }

    private function sanitize(array $values): array
    {
        foreach (self::IGNORED_FIELDS as $field) {
            unset($values[$field]);
        }

        foreach ($values as $key => $value) {
            $normalized = Str::lower((string) $key);
            foreach (self::SENSITIVE_FIELDS as $sensitive) {
                if ($normalized === $sensitive || Str::contains($normalized, $sensitive)) {
                    $values[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            if (is_string($value)) {
                $values[$key] = $this->limitString($value, 2000);
            } elseif (is_object($value) || is_array($value)) {
                $values[$key] = $this->limitString(
                    json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    2000
                );
            }
        }

        return array_slice($values, 0, 100, true);
    }

    private function resolveUser(): ?User
    {
        try {
            $user = Auth::guard('api')->user();
            if ($user instanceof User) {
                return $user;
            }

            $fallback = Auth::user();
            return $fallback instanceof User ? $fallback : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function actorName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $name = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));
        return $name !== '' ? $name : ($user->username ?: ('User #'.$user->id));
    }

    private function reference(array $attributes): ?string
    {
        foreach (['Ref', 'ref', 'reference', 'code', 'name', 'username'] as $field) {
            if (! empty($attributes[$field])) {
                return $this->limitString((string) $attributes[$field], 191);
            }
        }

        return null;
    }

    private function contextId(array $attributes, string $field, mixed $fallback): ?int
    {
        $value = $attributes[$field] ?? $fallback;
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function limitString(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return Str::limit($value, $length, '');
    }
}
