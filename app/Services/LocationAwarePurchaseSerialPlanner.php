<?php

namespace App\Services;

use App\Models\ProductSerial;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * MS6-B0 — Purchase / PurchaseReturn SERIAL PLANNER (INACTIVE: no productive
 * controller calls it yet).
 *
 * RESPONSIBILITY (only this):
 *   - normalise the explicit serial_numbers of a location-native document
 *   - reject a serial used twice ANYWHERE in the document (case-insensitive)
 *   - require quantity_base to be an INTEGER and count(serials) == quantity_base
 *     (NOT the legacy document-unit count)
 *   - resolve ProductSerial identity by serial_number (globally unique):
 *       receipt : a missing serial => create a `voided` PLACEHOLDER row (id
 *                 stable for the snapshot); a `voided` row of the SAME
 *                 product+variant => reusable; ANY other live status => 422
 *       return  : the serial MUST already be `available` at the exact location
 *                 with the exact product+variant; nothing is ever created
 *   - freeze a deterministic serial_allocation per line
 *       [{sidx, product_serial_id, serial_number}], sidx 0..N-1
 *
 * It NEVER mutates a serial status, NEVER writes a movement, NEVER touches
 * general stock. LocationAwareSerialNumberService's *Many set operations do
 * that later, composed by LocationAwarePurchaseStockService.
 *
 * batch + serial on the same line / product => 422 (no dual artifact tracking).
 *
 * MUST run inside the caller's business transaction.
 */
class LocationAwarePurchaseSerialPlanner
{
    private const EPS = 0.0005;

    // =====================================================================
    // PURCHASE RECEIPT
    // =====================================================================

    /**
     * @param  array  $validatedLines  from LocationAwarePurchaseStockService::validateAndLock (allow_serial)
     * @param  array  $rawLines        one entry per line, positionally aligned; each carries `serial_numbers`
     * @param  array{provider_id?:?int, source_purchase_id?:?int}  $context
     * @return array  $validatedLines each with `serial_allocation` ([] on a non-serial line)
     *
     * @throws \LogicException     no outer transaction
     * @throws ValidationException invalid input / identity / count
     */
    public function planPurchaseReceipt(int $warehouseId, int $inventoryLocationId, array $validatedLines, array $rawLines, array $context = []): array
    {
        $this->assertInTransaction();
        $rawLines = array_values($rawLines);

        // A cart with no is_imei line: a pure no-op (serial_allocation => []),
        // and the serial schema is not even required.
        if (! $this->hasSerialLine($validatedLines)) {
            return array_map(fn ($l) => ['serial_allocation' => []] + $l, array_values($validatedLines));
        }
        $this->assertSerialSchema();

        // 1 — document-wide serial map (case-insensitive), reject any repeat.
        $perLine = $this->collectDocumentSerials($validatedLines, $rawLines);

        // 2 — resolve every string to a stable product_serial_id (create voided
        //     placeholders for the unknown ones), deterministically.
        $idByKey = $this->resolveReceiptIdentities($perLine, $warehouseId, $inventoryLocationId, $context);

        // 3 — freeze the per-line allocation.
        $out = [];
        foreach (array_values($validatedLines) as $i => $line) {
            if (empty($line['requires_serial'])) {
                $out[] = ['serial_allocation' => []] + $line;

                continue;
            }
            $entries = [];
            foreach ($perLine[$i]['serials'] as $sidx => $serialNumber) {
                $entries[] = [
                    'sidx' => $sidx,
                    'product_serial_id' => (int) $idByKey[mb_strtolower($serialNumber)],
                    'serial_number' => $serialNumber,
                ];
            }
            $out[] = ['serial_allocation' => $entries] + $line;
        }

        return $out;
    }

    // =====================================================================
    // PURCHASE RETURN (issue to supplier)
    // =====================================================================

    /**
     * @param  array{require_source_purchase?:bool, source_purchase_id?:?int}  $context
     *         require_source_purchase=true + source_purchase_id set => the serial
     *         must also originate from that purchase (opt-in; the global engine
     *         does NOT impose the legacy "must belong to the linked purchase"
     *         rule by default — the goods may have moved / come from another
     *         valid receipt).
     * @return array  $validatedLines each with `serial_allocation`
     *
     * @throws \LogicException
     * @throws ValidationException
     */
    public function planPurchaseReturnIssue(int $warehouseId, int $inventoryLocationId, array $validatedLines, array $rawLines, array $context = []): array
    {
        $this->assertInTransaction();
        $rawLines = array_values($rawLines);

        if (! $this->hasSerialLine($validatedLines)) {
            return array_map(fn ($l) => ['serial_allocation' => []] + $l, array_values($validatedLines));
        }
        $this->assertSerialSchema();

        $perLine = $this->collectDocumentSerials($validatedLines, $rawLines);

        // Resolve every string against the EXISTING ledger — nothing is created.
        $allKeys = [];
        foreach ($perLine as $meta) {
            foreach ($meta['serials'] as $s) {
                $allKeys[mb_strtolower($s)] = $s;
            }
        }

        $rows = collect();
        if ($allKeys) {
            $rows = ProductSerial::whereIn('serial_number', array_values($allKeys))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($r) => mb_strtolower((string) $r->serial_number));
        }

        $requireSource = (bool) ($context['require_source_purchase'] ?? false);
        $sourcePurchaseId = isset($context['source_purchase_id']) && $context['source_purchase_id'] !== null
            ? (int) $context['source_purchase_id'] : null;

        $idByKey = [];
        foreach ($perLine as $i => $meta) {
            $productId = (int) $meta['product_id'];
            $variantId = $meta['variant_id'];
            foreach ($meta['serials'] as $serialNumber) {
                $key = mb_strtolower($serialNumber);
                $row = $rows->get($key);
                if (! $row) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no existe.",
                    ]);
                }
                if ((int) $row->product_id !== $productId) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no corresponde al producto de la línea.",
                    ]);
                }
                $rowVariant = $row->product_variant_id !== null ? (int) $row->product_variant_id : null;
                if ($rowVariant !== $variantId) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no corresponde a la variante de la línea.",
                    ]);
                }
                if ((string) $row->status !== ProductSerial::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no está disponible (estado: {$row->status}).",
                    ]);
                }
                if ((int) $row->inventory_location_id !== $inventoryLocationId) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no está en la ubicación de inventario seleccionada.",
                    ]);
                }
                if ($requireSource && $sourcePurchaseId !== null && (int) $row->purchase_id !== $sourcePurchaseId) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$serialNumber}' no proviene de la compra referenciada.",
                    ]);
                }
                $idByKey[$key] = (int) $row->id;
            }
        }

        $out = [];
        foreach (array_values($validatedLines) as $i => $line) {
            if (empty($line['requires_serial'])) {
                $out[] = ['serial_allocation' => []] + $line;

                continue;
            }
            $entries = [];
            foreach ($perLine[$i]['serials'] as $sidx => $serialNumber) {
                $entries[] = [
                    'sidx' => $sidx,
                    'product_serial_id' => (int) $idByKey[mb_strtolower($serialNumber)],
                    'serial_number' => $serialNumber,
                ];
            }
            $out[] = ['serial_allocation' => $entries] + $line;
        }

        return $out;
    }

    // =====================================================================
    // Shared
    // =====================================================================

    /**
     * Per requires_serial line: normalised serial list + product/variant, and a
     * GLOBAL case-insensitive dedup across the whole document.
     *
     * @return array<int,array{product_id:int, variant_id:?int, serials:array<int,string>}>
     */
    private function collectDocumentSerials(array $validatedLines, array $rawLines): array
    {
        $seen = [];   // lowercase serial => first line index
        $perLine = [];

        foreach (array_values($validatedLines) as $i => $line) {
            if (empty($line['requires_serial'])) {
                continue;
            }

            // batch + serial fence.
            if (! empty($line['requires_batch'])) {
                throw ValidationException::withMessages([
                    'serial_tracking' => "La línea ".($i + 1)." es de lote Y de serie/IMEI a la vez. La combinación lote+serie no está soportada.",
                ]);
            }

            $lineBase = round((float) ($line['quantity_base'] ?? 0), 3);
            if (abs($lineBase - round($lineBase)) > self::EPS) {
                throw ValidationException::withMessages([
                    "details.$i.quantity" => "La línea ".($i + 1)." usa serie/IMEI y sólo admite una cantidad base entera (recibida: {$lineBase}).",
                ]);
            }
            $base = (int) round($lineBase);

            $serials = $this->normalizeSerials($rawLines[$i]['serial_numbers'] ?? null, $i);
            if (count($serials) !== $base) {
                throw ValidationException::withMessages([
                    "details.$i.serial_numbers" => "La línea ".($i + 1)." necesita exactamente {$base} número(s) de serie (recibidos: ".count($serials).").",
                ]);
            }

            foreach ($serials as $s) {
                $key = mb_strtolower($s);
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages([
                        "details.$i.serial_numbers" => "El serial '{$s}' está repetido en el documento.",
                    ]);
                }
                $seen[$key] = $i;
            }

            $perLine[$i] = [
                'product_id' => (int) $line['product_id'],
                'variant_id' => $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null,
                'serials' => array_values($serials),
            ];
        }

        return $perLine;
    }

    /**
     * Resolve (or create as a `voided` placeholder) a stable product_serial_id
     * for every string in the document. Deterministic: existing rows locked in
     * id order, new rows inserted in sorted order; a concurrent duplicate INSERT
     * (ps_serial_number_uq) is translated to a clean 422.
     *
     * @return array<string,int>  lowercase serial_number => product_serial_id
     */
    private function resolveReceiptIdentities(array $perLine, int $warehouseId, int $inventoryLocationId, array $context): array
    {
        // productKey per string (validate product/variant of a reused row).
        $wantByKey = [];
        foreach ($perLine as $meta) {
            foreach ($meta['serials'] as $s) {
                $wantByKey[mb_strtolower($s)] = [
                    'serial_number' => $s,
                    'product_id' => (int) $meta['product_id'],
                    'variant_id' => $meta['variant_id'],
                ];
            }
        }
        if (! $wantByKey) {
            return [];
        }

        $existing = ProductSerial::whereIn('serial_number', array_column($wantByKey, 'serial_number'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($r) => mb_strtolower((string) $r->serial_number));

        $idByKey = [];
        $toCreate = [];
        foreach ($wantByKey as $key => $want) {
            $row = $existing->get($key);
            if (! $row) {
                $toCreate[$key] = $want;

                continue;
            }
            if ((string) $row->status !== ProductSerial::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'serial_numbers' => "El serial '{$want['serial_number']}' ya existe y está activo (estado: {$row->status}). No se adopta un serial en uso.",
                ]);
            }
            if ((int) $row->product_id !== $want['product_id']) {
                throw ValidationException::withMessages([
                    'serial_numbers' => "El serial anulado '{$want['serial_number']}' pertenece a otro producto.",
                ]);
            }
            $rowVariant = $row->product_variant_id !== null ? (int) $row->product_variant_id : null;
            if ($rowVariant !== $want['variant_id']) {
                throw ValidationException::withMessages([
                    'serial_numbers' => "El serial anulado '{$want['serial_number']}' pertenece a otra variante.",
                ]);
            }
            $idByKey[$key] = (int) $row->id;
        }

        if ($toCreate) {
            // deterministic insert order.
            ksort($toCreate);
            $providerId = isset($context['provider_id']) && $context['provider_id'] !== null ? (int) $context['provider_id'] : null;
            $now = now();
            foreach ($toCreate as $key => $want) {
                try {
                    $id = (int) DB::table('product_serials')->insertGetId([
                        'serial_number' => $want['serial_number'],
                        'product_id' => $want['product_id'],
                        'product_variant_id' => $want['variant_id'],
                        'warehouse_id' => $warehouseId,
                        'inventory_location_id' => null,
                        'status' => ProductSerial::STATUS_VOIDED,
                        'provider_id' => $providerId,
                        'notes' => 'MS6 placeholder (voided) — pendiente de recepción location-native.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (QueryException $e) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial '{$want['serial_number']}' fue creado por otra operación simultánea. Reintenta.",
                    ]);
                }
                $idByKey[$key] = $id;
            }
        }

        return $idByKey;
    }

    /**
     * Normalise a raw serial value into a clean list. Accepts an array or a
     * newline/comma/semicolon/tab-separated string. Trims + drops blanks.
     * Rejects a duplicate WITHIN this line (case-insensitive), same as
     * SerialNumberService::normalizeSerials.
     *
     * @return array<int,string>
     */
    private function normalizeSerials($raw, int $lineIndex): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,;\t]+/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $s) {
            $s = trim((string) $s);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "details.$lineIndex.serial_numbers" => "Número de serie duplicado en la misma línea: '{$s}'.",
                ]);
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return array_values($out);
    }

    private function hasSerialLine(array $validatedLines): bool
    {
        foreach ($validatedLines as $line) {
            if (! empty($line['requires_serial'])) {
                return true;
            }
        }

        return false;
    }

    private function assertSerialSchema(): void
    {
        if (! Schema::hasTable('product_serials') || ! Schema::hasTable('product_serial_movements')) {
            throw ValidationException::withMessages([
                'serial_tracking' => 'El esquema de series/IMEI no está disponible en este tenant.',
            ]);
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw new \LogicException(
                'LocationAwarePurchaseSerialPlanner must run inside the caller\'s business transaction.'
            );
        }
    }
}
