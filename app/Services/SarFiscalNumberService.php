<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\Sale;
use App\Models\SarAuthorization;
use App\Models\SarFiscalDocument;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;
use Illuminate\Support\Facades\DB;

/**
 * Atomic correlativo allocation. The counter belongs to the AUTHORISATION of a
 * fiscal series, not to a cash drawer or a branch.
 *
 * A number is assigned only here, and only when the sale is already being
 * committed (the caller runs inside the sale-creation transaction). There is no
 * reservation at POS open, sale start, or payment. If two cash drawers of the
 * same series confirm at the same instant, the row lock below serialises just
 * the counter of that one series and hands out consecutive numbers in
 * completion order.
 *
 * The lock scope is a single series' `active` + `prepared` authorisation rows —
 * never the branch, never the sales table.
 */
class SarFiscalNumberService
{
    /** Correlativos left before a series is flagged "por agotarse". */
    public const LOW_RANGE_THRESHOLD = 200;

    public function issue(
        Sale $sale,
        int $pointOfIssueId,
        string $documentType,
        array $customerSnapshot,
        array $saleSnapshot
    ): SarFiscalDocument {
        return DB::transaction(function () use ($sale, $pointOfIssueId, $documentType, $customerSnapshot, $saleSnapshot) {
            // 1) Idempotency fast path: a retried request for the same sale
            //    returns the number already issued and consumes nothing new.
            //    No row lock here — the unique index on sar_fiscal_documents.sale_id
            //    is the real guarantee (see the INSERT catch below), so we avoid a
            //    gap lock on a not-yet-existing row.
            $existing = SarFiscalDocument::where('sale_id', $sale->id)->first();
            if ($existing) {
                return $existing;
            }

            // Fiscal profile is read-only configuration — never lock it.
            $profile = SarFiscalProfile::first();
            if (! $profile || ! $profile->enabled) {
                throw new SarFiscalException('La facturación SAR no está habilitada para este negocio.');
            }

            $series = SarPointOfIssue::whereKey($pointOfIssueId)->first();
            if (! $series) {
                throw new SarFiscalException('La serie fiscal indicada no existe.');
            }
            if (! $series->active) {
                throw new SarFiscalException('La serie fiscal '.$this->seriesLabel($series, $documentType).' no está activa.');
            }

            // 2) Lock this series' relevant authorisations: the active one, the
            //    prepared "next" one, and the recently spent ones (so the
            //    current -> next transition can be recorded even when it happens
            //    on a later sale). Consistent order (by id) so two concurrent
            //    allocations of the same series never deadlock. The lock never
            //    spans another series, the branch, or the sales table.
            $live = SarAuthorization::forSeries($pointOfIssueId, $documentType)
                ->whereIn('status', ['active', 'prepared', 'exhausted', 'expired'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $authorization = $this->resolveUsableAuthorization($live, $series, $documentType);

            // 3) Hard cross-branch guard at the allocation point: a sale can never
            //    consume the CAI of a series that belongs to another branch.
            $seriesBranch = $series->branch_id;
            $saleBranch = $sale->branch_id;
            if ($seriesBranch !== null && $saleBranch !== null && (int) $seriesBranch !== (int) $saleBranch) {
                throw new SarFiscalException(
                    'La serie fiscal pertenece a otra sucursal. Una venta no puede consumir el CAI de una sucursal distinta.'
                );
            }

            // 4) Allocate the correlativo and advance the counter, atomically.
            $sequence = (int) $authorization->next_number;
            $fiscalNumber = $this->formatNumber(
                $series->establishment_code,
                $series->point_code,
                $authorization->document_type,
                $sequence
            );

            $invoiceSettings = SarInvoiceSettings::merge($profile->invoice_settings);

            try {
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
                        'point_of_issue_address' => $series->address,
                        'point_of_issue_name' => $series->name,
                        'establishment_code' => $series->establishment_code,
                        'point_code' => $series->point_code,
                        'phone' => $profile->phone,
                        'email' => $profile->email,
                        'invoice_settings' => $invoiceSettings,
                    ],
                    'customer_snapshot' => $customerSnapshot,
                    'sale_snapshot' => $saleSnapshot,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // A concurrent request already issued this sale's number. Honour
                // idempotency: return that document, consume nothing new.
                $already = SarFiscalDocument::where('sale_id', $sale->id)->first();
                if ($already) {
                    return $already;
                }
                throw $e;
            }

            $authorization->next_number = $sequence + 1;
            if ($sequence >= (int) $authorization->range_end) {
                $authorization->status = 'exhausted';
                $authorization->exhausted_at = now();
            }
            $authorization->save();

            return $document;
        }, 3);
    }

    /**
     * From a series' locked live authorisations, pick the one that will issue
     * this number — switching to the prepared "next" CAI atomically when the
     * active one has just run out. All mutations happen under the row lock held
     * by the caller's transaction.
     */
    private function resolveUsableAuthorization($live, SarPointOfIssue $series, string $documentType): SarAuthorization
    {
        $active = $live->firstWhere('status', 'active');
        $prepared = $live->firstWhere('status', 'prepared');

        if ($active && $active->isUsableNow()) {
            return $active;
        }

        // The active CAI is spent (or missing). Retire it precisely so the UI
        // and reports show the real reason.
        $activeProblem = null;
        if ($active) {
            if ($active->isExpired()) {
                $active->status = 'expired';
                $active->save();
                $activeProblem = 'expired';
            } elseif ($active->isRangeExhausted()) {
                $active->status = 'exhausted';
                $active->exhausted_at = $active->exhausted_at ?: now();
                $active->save();
                $activeProblem = 'exhausted';
            } else {
                $activeProblem = 'unusable';
            }
        }

        // Safe automatic transition to the prepared "next" authorisation.
        if ($prepared && $prepared->isUsableNow()) {
            // Record the transition on whichever authorisation this one replaces:
            // the active row if it just ran out here, otherwise the most recently
            // spent row that has no successor yet.
            $superseded = $active ?: $live
                ->whereIn('status', ['exhausted', 'expired'])
                ->whereNull('superseded_by_id')
                ->sortByDesc('id')
                ->first();
            if ($superseded) {
                $superseded->superseded_by_id = $prepared->id;
                $superseded->save();
            }

            $prepared->status = 'active';
            $prepared->activated_at = now();
            $prepared->save();

            return $prepared;
        }

        $label = $this->seriesLabel($series, $documentType);

        if ($activeProblem === 'expired') {
            throw new SarFiscalException(
                'El CAI de la serie '.$label.' venció y no hay una autorización siguiente preparada. '
                .'Registra la próxima autorización SAR de esta serie.'
            );
        }
        if ($activeProblem === 'exhausted') {
            throw new SarFiscalException(
                'El rango del CAI de la serie '.$label.' está agotado y no hay una autorización siguiente preparada. '
                .'Registra la próxima autorización SAR de esta serie.'
            );
        }

        throw new SarFiscalException(
            'No hay una autorización SAR activa para la serie '.$label.'. Registra y activa un CAI.'
        );
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

    public function seriesLabel(SarPointOfIssue $series, string $documentType): string
    {
        return trim(($series->establishment_code ?: '000').'-'.($series->point_code ?: '000').'-'.$documentType);
    }
}
