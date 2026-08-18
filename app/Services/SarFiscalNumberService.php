<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\Sale;
use App\Models\SarAuthorization;
use App\Models\SarFiscalDocument;
use App\Models\SarFiscalProfile;
use Illuminate\Support\Facades\DB;

class SarFiscalNumberService
{
    public function issue(
        Sale $sale,
        int $authorizationId,
        array $customerSnapshot,
        array $saleSnapshot
    ): SarFiscalDocument {
        return DB::transaction(function () use ($sale, $authorizationId, $customerSnapshot, $saleSnapshot) {
            $existing = SarFiscalDocument::where('sale_id', $sale->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $profile = SarFiscalProfile::query()->lockForUpdate()->first();
            if (! $profile || ! $profile->enabled) {
                throw new SarFiscalException('La facturación SAR no está habilitada para este negocio.');
            }

            $authorization = SarAuthorization::with('pointOfIssue')
                ->whereKey($authorizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertUsable($authorization);

            $sequence = (int) $authorization->next_number;
            $fiscalNumber = $this->formatNumber(
                $authorization->pointOfIssue->establishment_code,
                $authorization->pointOfIssue->point_code,
                $authorization->document_type,
                $sequence
            );

            $document = SarFiscalDocument::create([
                'sale_id' => $sale->id,
                'authorization_id' => $authorization->id,
                'sequence' => $sequence,
                'fiscal_number' => $fiscalNumber,
                'cai' => $authorization->cai,
                'deadline' => $authorization->deadline,
                'status' => 'issued',
                'issued_at' => now(),
                'issuer_snapshot' => [
                    'rtn' => $profile->rtn,
                    'legal_name' => $profile->legal_name,
                    'trade_name' => $profile->trade_name,
                    'head_office_address' => $profile->head_office_address,
                    'point_of_issue_address' => $authorization->pointOfIssue->address,
                    'phone' => $profile->phone,
                    'email' => $profile->email,
                ],
                'customer_snapshot' => $customerSnapshot,
                'sale_snapshot' => $saleSnapshot,
            ]);

            $authorization->next_number = $sequence + 1;
            if ($sequence >= (int) $authorization->range_end) {
                $authorization->status = 'exhausted';
            }
            $authorization->save();

            return $document;
        }, 3);
    }

    public function void(SarFiscalDocument $document, string $reason, ?int $userId = null): SarFiscalDocument
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new SarFiscalException('Debe indicar el motivo de anulación.');
        }

        return DB::transaction(function () use ($document, $reason, $userId) {
            $locked = SarFiscalDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'voided') {
                return $locked;
            }

            $locked->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $userId,
            ]);

            return $locked->fresh();
        });
    }

    public function formatNumber(string $establishment, string $point, string $type, int $sequence): string
    {
        if (! preg_match('/^\d{3}$/', $establishment)
            || ! preg_match('/^\d{3}$/', $point)
            || ! preg_match('/^\d{2}$/', $type)
            || $sequence < 1
            || $sequence > 99999999) {
            throw new SarFiscalException('Los códigos o el correlativo SAR no tienen un formato válido.');
        }

        return sprintf('%s-%s-%s-%08d', $establishment, $point, $type, $sequence);
    }

    private function assertUsable(SarAuthorization $authorization): void
    {
        if ($authorization->status !== 'active') {
            throw new SarFiscalException('La autorización SAR no está activa.');
        }

        if (! $authorization->pointOfIssue || ! $authorization->pointOfIssue->active) {
            throw new SarFiscalException('El punto de emisión SAR no está activo.');
        }

        if ($authorization->deadline->isBefore(today())) {
            $authorization->update(['status' => 'expired']);
            throw new SarFiscalException('La fecha límite de emisión SAR ya venció.');
        }

        $next = (int) $authorization->next_number;
        if ($next < (int) $authorization->range_start || $next > (int) $authorization->range_end) {
            $authorization->update(['status' => 'exhausted']);
            throw new SarFiscalException('El rango autorizado por el SAR está agotado.');
        }
    }
}
