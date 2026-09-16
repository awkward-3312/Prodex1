<?php

namespace App\Services\DemoTenant;

use App\Http\Controllers\hrm\AttendancesController;
use App\Http\Controllers\hrm\DesignationsController;
use App\Http\Controllers\hrm\EmployeesController;
use App\Http\Controllers\hrm\HolidayController;
use App\Http\Controllers\hrm\LeaveController;
use App\Http\Controllers\hrm\OfficeShiftController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PromotionsController;
use App\Http\Controllers\PurchasesController;
use App\Http\Controllers\QuotationsController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferWorkflowController;
use App\Models\User;
use App\Models\RecruitApplication;
use App\Models\RecruitInterview;
use App\Services\UserOperationalAssignmentService;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Builds/backfills a realistic demo dataset inside the CURRENTLY INITIALIZED
 * tenant connection. Caller (the command) is responsible for
 * tenancy()->initialize()/end() — this class never touches tenant
 * resolution or the central DB.
 *
 * Idempotency: granular, per entity, not one global marker.
 *  - categories, products: looked up by their deterministic `code` column.
 *  - brands, units: looked up by exact `name` (unit also by `ShortName`)
 *    against the fixed persona list — see DemoPersonas for why that's safe.
 * A module method NEVER assumes a previous module fully succeeded; each
 * re-derives what already exists and only creates what's missing, so a
 * second run after a partial failure completes the gap without duplicating
 * anything already there.
 *
 * Every module returns a ModuleResult (created/existing/skipped/failed
 * counts) so the command can render the final summary table. Dry-run mode
 * NEVER calls a write method — plan() computes the same "what would be
 * created" answer via read-only counting, no INSERT/UPDATE/DELETE, no
 * files, no domain-service side effects.
 */
class DemoTenantSeeder
{
    private string $persona;

    private array $data;

    /** @var array<string,int> code => id, filled as modules run so later modules can reference earlier ones */
    private array $categoryIds = [];

    private array $brandIds = [];

    private array $unitIds = [];

    private array $clientIds = [];

    /** Phase D — filled as HR modules run. */
    private ?int $hrCompanyId = null;

    private array $hrDepartmentIds = [];

    private array $hrDesignationIds = [];

    private array $hrOfficeShiftIds = [];

    /** @var array<string,array{id:int,shift:string,basic_salary:float,hourly_rate:float,department:string}> keyed by "firstname lastname" */
    private array $hrEmployeeMeta = [];

    public function __construct(string $persona)
    {
        $this->persona = $persona;
        $this->data = DemoPersonas::get($persona);
    }

    public function personaLabel(): string
    {
        return $this->data['label'];
    }

    /**
     * Read-only plan for --dry-run: for each Phase-A entity, how many exist
     * already (matching this persona's fixed identifiers) vs. how many this
     * run would still need to create. Zero writes.
     */
    public function plan(): array
    {
        $plan = [];

        $unitExisting = 0;
        foreach ($this->data['units'] as $u) {
            if (DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->exists()) {
                $unitExisting++;
            }
        }
        $plan['Unidades'] = $this->row(count($this->data['units']), $unitExisting);

        $brandExisting = 0;
        foreach ($this->data['brands'] as $name) {
            if (DB::table('brands')->where('name', $name)->whereNull('deleted_at')->exists()) {
                $brandExisting++;
            }
        }
        $plan['Marcas'] = $this->row(count($this->data['brands']), $brandExisting);

        $categoryCodes = $this->categoryCodes();
        $catExisting = DB::table('categories')->whereIn('code', $categoryCodes)->whereNull('deleted_at')->count();
        $plan['Categorías'] = $this->row(count($categoryCodes), $catExisting);

        $productCodes = $this->productCodes();
        $prodExisting = DB::table('products')->whereIn('code', $productCodes)->whereNull('deleted_at')->count();
        $plan['Productos'] = $this->row(count($productCodes), $prodExisting);

        $provExisting = 0;
        foreach ($this->data['providers'] as $name) {
            if (DB::table('providers')->where('name', $name)->whereNull('deleted_at')->exists()) {
                $provExisting++;
            }
        }
        $plan['Proveedores'] = $this->row(count($this->data['providers']), $provExisting);

        $productIds = $this->allDemoProductIds();
        $warehouseIds = $this->allWarehouseIds();
        $targetStockRows = count($productIds) * count($warehouseIds);
        $existingStockRows = count($productIds) && count($warehouseIds)
            ? DB::table('product_warehouse')->whereIn('product_id', $productIds)->whereIn('warehouse_id', $warehouseIds)->whereNull('deleted_at')->count()
            : 0;
        $plan['Stock inicial (product_warehouse)'] = $this->row($targetStockRows, $existingStockRows);

        $purchaseExisting = DB::table('purchases')->where('notes', 'like', '%[DEMO2-PUR-%')->whereNull('deleted_at')->count();
        $plan['Compras'] = $this->row(10, $purchaseExisting);

        $transferExisting = DB::table('transfers')->where('notes', 'like', '%[DEMO2-TRF-%')->whereNull('deleted_at')->count();
        $plan['Transferencias'] = $this->row(10, $transferExisting);

        $clientExisting = DB::table('clients')->whereIn('name', $this->data['clients'])->whereNull('deleted_at')->count();
        $plan['Clientes'] = $this->row(count($this->data['clients']), $clientExisting);

        $saleExisting = DB::table('sales')->where('sale_uuid', 'like', self::SALE_UUID_PREFIX.'%')->whereNull('deleted_at')->count();
        $plan['Ventas'] = $this->row(10, $saleExisting);

        $quotationExisting = DB::table('quotations')->where('notes', 'like', '%[DEMO2-QUO-%')->whereNull('deleted_at')->count();
        $plan['Cotizaciones'] = $this->row(10, $quotationExisting);

        $promotionExisting = DB::table('promotions')->where('code', 'like', 'DEMO2-PROMO-%')->whereNull('deleted_at')->count();
        $plan['Promociones'] = $this->row(10, $promotionExisting);

        // Reservas: NO-GO — see seedReservasDecision() docblock. Reported as
        // target=10/existing=0/to_create=0 (never attempted) so the dry-run
        // table still surfaces the module instead of silently omitting it.
        $plan['Reservas'] = ['target' => 10, 'existing' => 0, 'to_create' => 0];

        $companyExisting = DB::table('companies')->where('name', $this->data['company_name'])->whereNull('deleted_at')->exists() ? 1 : 0;
        $plan['Empresa (HR)'] = $this->row(1, $companyExisting);

        $companyId = DB::table('companies')->where('name', $this->data['company_name'])->whereNull('deleted_at')->value('id');
        $deptExisting = $companyId ? DB::table('departments')->where('company_id', $companyId)->whereIn('department', array_keys($this->hrDepartments()))->whereNull('deleted_at')->count() : 0;
        $plan['Departamentos'] = $this->row(count($this->hrDepartments()), $deptExisting);

        $desigNames = collect($this->hrDepartments())->flatten(1)->values()->all();
        $desigExisting = $companyId ? DB::table('designations')->where('company_id', $companyId)->whereIn('designation', $desigNames)->whereNull('deleted_at')->count() : 0;
        $plan['Cargos'] = $this->row(count($desigNames), $desigExisting);

        $shiftExisting = $companyId ? DB::table('office_shifts')->where('company_id', $companyId)->whereIn('name', array_column($this->hrOfficeShifts(), 'name'))->whereNull('deleted_at')->count() : 0;
        $plan['Turnos'] = $this->row(count($this->hrOfficeShifts()), $shiftExisting);

        $empExisting = DB::table('employees')->where(function ($q) {
            foreach ($this->hrEmployees() as $e) {
                $q->orWhere(fn ($q2) => $q2->where('firstname', $e['firstname'])->where('lastname', $e['lastname']));
            }
        })->whereNull('deleted_at')->count();
        $plan['Empleados'] = $this->row(count($this->hrEmployees()), $empExisting);

        $attTarget = $this->attendancePlanCount();
        $attMarkerCount = DB::table('attendances')->whereIn('employee_id', DB::table('employees')->where(function ($q) {
            foreach ($this->hrEmployees() as $e) {
                $q->orWhere(fn ($q2) => $q2->where('firstname', $e['firstname'])->where('lastname', $e['lastname']));
            }
        })->pluck('id'))->whereNull('deleted_at')->count();
        $plan['Asistencias'] = $this->row($attTarget, $attMarkerCount);

        $ltExisting = DB::table('leave_types')->whereIn('title', $this->hrLeaveTypes())->whereNull('deleted_at')->count();
        $plan['Tipos de permiso'] = $this->row(count($this->hrLeaveTypes()), $ltExisting);

        $leaveExisting = DB::table('leaves')->where('reason', 'like', '%[DEMO2-LEAVE-%')->whereNull('deleted_at')->count();
        $plan['Solicitudes de permiso'] = $this->row(10, $leaveExisting);

        $holidayExisting = $companyId ? DB::table('holidays')->where('company_id', $companyId)->where('title', 'like', 'DEMO2 %')->whereNull('deleted_at')->count() : 0;
        $plan['Días festivos'] = $this->row(10, $holidayExisting);

        $payrollExisting = DB::table('payrolls')->where('Ref', 'like', '%')->where('receiver_account_number', 'DEMO2-PAYROLL')->whereNull('deleted_at')->count();
        $plan['Nómina'] = $this->row(10, $payrollExisting);

        // Phase E. The identifiers below are deliberately based on real,
        // unique columns: job/property slug, candidate/inquiry email and
        // asset tag. Categories do not have a code/slug, so their fixed name
        // is the documented identity. Applications use their database-unique
        // job_id+candidate_id pair; interviews use a stable DEMO token in
        // their nullable notes field because that table has no reference key.
        $recruitCategoryNames = array_column($this->recruitJobCategories(), 'name');
        $plan['Recruit Job Categories'] = $this->row(
            count($recruitCategoryNames),
            DB::table('recruit_job_categories')->whereIn('name', $recruitCategoryNames)->whereNull('deleted_at')->count()
        );
        $jobSlugs = array_column($this->recruitJobs(), 'slug');
        $plan['Recruit Jobs'] = $this->row(10, DB::table('recruit_jobs')->whereIn('slug', $jobSlugs)->whereNull('deleted_at')->count());
        $candidateEmails = array_column($this->recruitCandidates(), 'email');
        $plan['Candidates'] = $this->row(10, DB::table('recruit_candidates')->whereIn('email', $candidateEmails)->whereNull('deleted_at')->count());
        $plan['Applications'] = $this->row(10, $this->existingRecruitApplicationCount());
        $plan['Interviews'] = $this->row(10, DB::table('recruit_interviews')->where('notes', 'like', '%[DEMO2-INT-'.strtoupper($this->personaCode()).'-%')->whereNull('deleted_at')->count());

        $assetsEnabled = app(TenantLimitsService::class)->hasFeature('assets');
        if ($assetsEnabled) {
            $assetCategoryNames = array_column($this->assetCategories(), 'name');
            $plan['Asset Categories'] = $this->row(count($assetCategoryNames), DB::table('asset_categories')->whereIn('name', $assetCategoryNames)->whereNull('deleted_at')->count());
            $assetTags = array_column($this->assets(), 'tag');
            $plan['Assets'] = $this->row(10, DB::table('assets')->whereIn('tag', $assetTags)->whereNull('deleted_at')->count());
        } else {
            $plan['Asset Categories'] = ['target' => 5, 'existing' => 0, 'to_create' => 0, 'skipped' => 5];
            $plan['Assets'] = ['target' => 10, 'existing' => 0, 'to_create' => 0, 'skipped' => 10];
        }

        $propertyCategories = $this->propertyCategories();
        $propertyCategorySlugs = array_column($propertyCategories, 'slug');
        $plan['Property Categories'] = $this->row(count($propertyCategories), DB::table('property_categories')->whereIn('slug', $propertyCategorySlugs)->whereNull('deleted_at')->count());
        $propertySlugs = array_column($this->properties(), 'slug');
        $plan['Properties'] = $this->row(10, DB::table('properties')->whereIn('slug', $propertySlugs)->whereNull('deleted_at')->count());
        $inquiryEmails = array_column($this->propertyInquiries(), 'email');
        $plan['Property Inquiries'] = $this->row(10, DB::table('property_inquiries')->whereIn('email', $inquiryEmails)->whereNull('deleted_at')->count());

        // Phase F: store-facing data follows the online_orders feature. Orders
        // themselves are intentionally never seeded: Checkout is the live
        // stock/payment-aware workflow and a raw row would be unsafe.
        $onlineEnabled = app(TenantLimitsService::class)->hasFeature('online_orders');
        foreach ([
            'Store Collections' => ['collections', 'slug', $this->storeCollectionSlugs(), 5],
            'Store Banners' => ['store_banners', 'title', $this->storeBannerTitles(), 3],
            'Subscribers' => ['subscribers', 'email', $this->storeSubscriberEmails(), 10],
            'Invite Codes' => ['invite_codes', 'code', $this->storeInviteCodes(), 10],
        ] as $label => [$table, $column, $values, $target]) {
            $plan[$label] = $onlineEnabled
                ? $this->row($target, DB::table($table)->whereIn($column, $values)->count())
                : ['target' => $target, 'existing' => 0, 'to_create' => 0, 'skipped' => $target];
        }
        $plan['Online Orders'] = ['target' => 10, 'existing' => 0, 'to_create' => 0, 'skipped' => 10];
        $plan['Pending Customers'] = ['target' => 10, 'existing' => 0, 'to_create' => 0, 'skipped' => 10];
        $plan['Product Subscriptions'] = $this->row(10, $this->existingProductSubscriptionCount());
        $plan['WhatsApp Templates'] = $this->row(10, DB::table('whatsapp_templates')->where('key', 'like', 'demo2_wa_'.strtolower($this->personaCode()).'_%')->whereNull('deleted_at')->count());
        $plan['WhatsApp Logs'] = $this->row(10, DB::table('whatsapp_logs')->where('provider_message_id', 'like', 'DEMO2-WA-'.strtoupper($this->personaCode()).'-%')->count());

        return $plan;
    }

    /** Fixed 5-department structure, each with 1-2 designations. Shared across both personas — a generic, realistic org chart, not persona-varied. */
    private function hrDepartments(): array
    {
        return [
            'Ventas' => ['Ejecutivo de Ventas', 'Supervisor de Ventas'],
            'Almacén e Inventario' => ['Auxiliar de Bodega', 'Jefe de Bodega'],
            'Administración y Finanzas' => ['Analista Contable', 'Gerente Administrativo'],
            'Recursos Humanos' => ['Coordinador de RRHH'],
            'Logística' => ['Coordinador de Logística'],
        ];
    }

    /** 4 shifts, canonical "H:i" strings only (or null = day off) — see OfficeShift::normalizeTime(). */
    private function hrOfficeShifts(): array
    {
        $admin = ['08:00', '17:00'];
        $morning = ['06:00', '14:00'];
        $afternoon = ['14:00', '22:00'];
        $weekend = ['09:00', '18:00'];

        return [
            [
                'name' => 'Turno Administrativo',
                'schedule' => [
                    'monday' => $admin, 'tuesday' => $admin, 'wednesday' => $admin, 'thursday' => $admin, 'friday' => $admin,
                    'saturday' => null, 'sunday' => null,
                ],
            ],
            [
                'name' => 'Turno Mañana',
                'schedule' => [
                    'monday' => $morning, 'tuesday' => $morning, 'wednesday' => $morning, 'thursday' => $morning, 'friday' => $morning, 'saturday' => $morning,
                    'sunday' => null,
                ],
            ],
            [
                'name' => 'Turno Tarde',
                'schedule' => [
                    'monday' => $afternoon, 'tuesday' => $afternoon, 'wednesday' => $afternoon, 'thursday' => $afternoon, 'friday' => $afternoon, 'saturday' => $afternoon,
                    'sunday' => null,
                ],
            ],
            [
                'name' => 'Turno Fin de Semana',
                'schedule' => [
                    'monday' => null, 'tuesday' => null, 'wednesday' => null, 'thursday' => null,
                    'friday' => $weekend, 'saturday' => $weekend, 'sunday' => $weekend,
                ],
            ],
        ];
    }

    /** 10 fixed DEMO employees — name, gender, department, designation, shift, salary (HNL), joining offset (days ago). Fictitious throughout, no real DNI/RTN/bank data. */
    private function hrEmployees(): array
    {
        return [
            ['firstname' => 'Carlos', 'lastname' => 'Martínez', 'gender' => 'Male', 'department' => 'Ventas', 'designation' => 'Ejecutivo de Ventas', 'shift' => 'Turno Administrativo', 'basic_salary' => 12000, 'joined_days_ago' => 420],
            ['firstname' => 'Ana', 'lastname' => 'Rodríguez', 'gender' => 'Female', 'department' => 'Ventas', 'designation' => 'Supervisor de Ventas', 'shift' => 'Turno Administrativo', 'basic_salary' => 18000, 'joined_days_ago' => 730],
            ['firstname' => 'Luis', 'lastname' => 'Fernández', 'gender' => 'Male', 'department' => 'Almacén e Inventario', 'designation' => 'Auxiliar de Bodega', 'shift' => 'Turno Mañana', 'basic_salary' => 9500, 'joined_days_ago' => 210],
            ['firstname' => 'María', 'lastname' => 'Gómez', 'gender' => 'Female', 'department' => 'Almacén e Inventario', 'designation' => 'Jefe de Bodega', 'shift' => 'Turno Mañana', 'basic_salary' => 16000, 'joined_days_ago' => 540],
            ['firstname' => 'José', 'lastname' => 'Pineda', 'gender' => 'Male', 'department' => 'Administración y Finanzas', 'designation' => 'Analista Contable', 'shift' => 'Turno Administrativo', 'basic_salary' => 15000, 'joined_days_ago' => 365],
            ['firstname' => 'Gabriela', 'lastname' => 'Cáceres', 'gender' => 'Female', 'department' => 'Administración y Finanzas', 'designation' => 'Gerente Administrativo', 'shift' => 'Turno Administrativo', 'basic_salary' => 25000, 'joined_days_ago' => 900],
            ['firstname' => 'Roberto', 'lastname' => 'Zelaya', 'gender' => 'Male', 'department' => 'Recursos Humanos', 'designation' => 'Coordinador de RRHH', 'shift' => 'Turno Administrativo', 'basic_salary' => 14000, 'joined_days_ago' => 300],
            ['firstname' => 'Daniela', 'lastname' => 'Reyes', 'gender' => 'Female', 'department' => 'Logística', 'designation' => 'Coordinador de Logística', 'shift' => 'Turno Tarde', 'basic_salary' => 13500, 'joined_days_ago' => 180],
            ['firstname' => 'Miguel', 'lastname' => 'Oseguera', 'gender' => 'Male', 'department' => 'Ventas', 'designation' => 'Ejecutivo de Ventas', 'shift' => 'Turno Tarde', 'basic_salary' => 11000, 'joined_days_ago' => 95],
            ['firstname' => 'Fátima', 'lastname' => 'Bardales', 'gender' => 'Female', 'department' => 'Almacén e Inventario', 'designation' => 'Auxiliar de Bodega', 'shift' => 'Turno Fin de Semana', 'basic_salary' => 9000, 'joined_days_ago' => 60],
        ];
    }

    private function hrLeaveTypes(): array
    {
        return ['Vacaciones', 'Enfermedad', 'Permiso Personal', 'Duelo', 'Maternidad', 'Paternidad', 'Permiso Sin Goce de Sueldo', 'Cita Médica', 'Estudio', 'Otro'];
    }

    /** How many attendance rows the fixed schedule below actually produces (needed by plan() for the dry-run target). */
    private function attendancePlanCount(): int
    {
        return count($this->attendancePlanRows());
    }

    /** Deterministic 36-char sale_uuid prefix, matched against the sales.sale_uuid unique column. */
    private const SALE_UUID_PREFIX = 'demo2-sale-';

    /** All demo product ids currently in the DB (Phase A codes), re-resolved fresh — never assumes Phase A ran in this same process. */
    private function allDemoProductIds(): array
    {
        return DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->pluck('id')->all();
    }

    private function allWarehouseIds(): array
    {
        return DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->all();
    }

    /**
     * Units/brands (and providers, Phase B) have no `code`/`slug` column in
     * this schema — see class docblock. Before writing anything, surface
     * which of the fixed DEMO names ALREADY exist in this tenant: those get
     * reused (never duplicated, never modified), but a name match can never
     * be technically distinguished from an unrelated normal record sharing
     * the same name. This is a visibility report only — it never blocks
     * execution, per the explicit design decision.
     */
    public function nameCollisionReport(): array
    {
        $report = [];

        $report['Units'] = array_map(function ($u) {
            $row = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->first();

            return ['label' => $u['name'].' ('.$u['short'].')', 'existing_id' => $row->id ?? null];
        }, $this->data['units']);

        $report['Brands'] = array_map(function ($name) {
            $id = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['brands']);

        $report['Providers'] = array_map(function ($name) {
            $id = DB::table('providers')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['providers']);

        $report['Clients'] = array_map(function ($name) {
            $id = DB::table('clients')->where('name', $name)->whereNull('deleted_at')->value('id');

            return ['label' => $name, 'existing_id' => $id];
        }, $this->data['clients']);

        return $report;
    }

    private function row(int $target, int $existing): array
    {
        return [
            'target' => $target,
            'existing' => $existing,
            'to_create' => max(0, $target - $existing),
            'skipped' => 0,
        ];
    }

    /** Deterministic category codes, stable across runs: CAT-DEMO2-01..10 */
    private function categoryCodes(): array
    {
        $codes = [];
        $i = 1;
        foreach (array_keys($this->data['categories']) as $name) {
            $codes[] = sprintf('CAT-DEMO2-%02d', $i++);
        }

        return $codes;
    }

    /** Deterministic product codes, stable across runs: PR-DEMO2-001..100 */
    private function productCodes(): array
    {
        $codes = [];
        $n = 1;
        foreach ($this->data['categories'] as $items) {
            foreach ($items as $ignored) {
                $codes[] = sprintf('PR-DEMO2-%03d', $n++);
            }
        }

        return $codes;
    }

    // ------------------------------------------------------------------
    // Phase A — catalog + products. Each returns ['created'=>n,'existing'=>n,'skipped'=>n,'failed'=>n].
    // ------------------------------------------------------------------

    public function seedUnits(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['units'] as $u) {
            $row = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->first();
            if ($row) {
                $this->unitIds[$u['short']] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('units')->insertGetId([
                'name' => $u['name'],
                'ShortName' => $u['short'],
                'operator' => '*',
                'operator_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->unitIds[$u['short']] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedBrands(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['brands'] as $name) {
            $row = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $this->brandIds[$name] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('brands')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->brandIds[$name] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedCategories(): array
    {
        $created = 0;
        $existing = 0;
        $i = 1;
        foreach (array_keys($this->data['categories']) as $name) {
            $code = sprintf('CAT-DEMO2-%02d', $i++);
            $row = DB::table('categories')->where('code', $code)->whereNull('deleted_at')->first();
            if ($row) {
                $this->categoryIds[$code] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('categories')->insertGetId([
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->categoryIds[$code] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * Requires seedUnits()/seedBrands()/seedCategories() to have run first in
     * THIS invocation OR to already exist in the DB from a prior run — this
     * method re-resolves ids by the same deterministic keys rather than
     * trusting in-memory state alone, so it is safe to call standalone.
     */
    public function seedProducts(): array
    {
        $this->reresolveCatalogIds();

        $created = 0;
        $existing = 0;
        $failed = 0;
        $n = 0;
        $catIndex = 0;
        $categoryCodesByIndex = $this->categoryCodes();

        foreach ($this->data['categories'] as $catName => $items) {
            $categoryCode = $categoryCodesByIndex[$catIndex++];
            $categoryId = $this->categoryIds[$categoryCode] ?? null;

            foreach ($items as $j => $productName) {
                $n++;
                $code = sprintf('PR-DEMO2-%03d', $n);

                $existingRow = DB::table('products')->where('code', $code)->whereNull('deleted_at')->first();
                if ($existingRow) {
                    $existing++;

                    continue;
                }

                if (! $categoryId) {
                    $failed++;

                    continue;
                }

                $brandName = $this->data['brands'][($n - 1) % count($this->data['brands'])];
                $brandId = $this->brandIds[$brandName] ?? null;
                $unit = $this->data['units'][($n - 1) % count($this->data['units'])];
                $unitId = $this->unitIds[$unit['short']] ?? null;

                // Deterministic variety (same seed => same values on re-run):
                // cost/price/margin vary by position instead of every product
                // sharing identical numbers.
                $tier = $n % 5; // 0..4
                $baseCost = 40 + ($tier * 35) + (($n * 7) % 60); // HNL
                $margin = 1.35 + ($tier * 0.08);
                $cost = round($baseCost, 2);
                $price = round($cost * $margin, 2);
                $wholesale = round($cost * ($margin - 0.12 > 1.05 ? $margin - 0.12 : 1.1), 2);
                $minPrice = round($cost * 1.05, 2);
                $stockAlert = 5 + ($n % 10);

                try {
                    DB::table('products')->insert([
                        'code' => $code,
                        'gtin' => null,
                        'Type_barcode' => 'CODE128',
                        'name' => $productName,
                        'type' => 'is_single',
                        'cost' => $cost,
                        'price' => $price,
                        'wholesale_price' => $wholesale,
                        'min_price' => $minPrice,
                        'category_id' => $categoryId,
                        'brand_id' => $brandId,
                        'unit_id' => $unitId,
                        'unit_sale_id' => $unitId,
                        'unit_purchase_id' => $unitId,
                        'stock_alert' => $stockAlert,
                        'TaxNet' => 15,
                        'tax_method' => '1',
                        'is_active' => 1,
                        'is_variant' => 0,
                        'is_imei' => 0,
                        'not_selling' => 0,
                        'is_featured' => $n % 10 === 0 ? 1 : 0,
                        'hide_from_online_store' => 0,
                        'points' => 0,
                        'warranty_period' => null,
                        'has_guarantee' => 0,
                        'guarantee_period' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $failed++;
                }
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed];
    }

    private function reresolveCatalogIds(): void
    {
        foreach ($this->data['units'] as $u) {
            if (! isset($this->unitIds[$u['short']])) {
                $id = DB::table('units')->where('name', $u['name'])->where('ShortName', $u['short'])->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->unitIds[$u['short']] = $id;
                }
            }
        }
        foreach ($this->data['brands'] as $name) {
            if (! isset($this->brandIds[$name])) {
                $id = DB::table('brands')->where('name', $name)->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->brandIds[$name] = $id;
                }
            }
        }
        $i = 1;
        foreach (array_keys($this->data['categories']) as $ignored) {
            $code = sprintf('CAT-DEMO2-%02d', $i++);
            if (! isset($this->categoryIds[$code])) {
                $id = DB::table('categories')->where('code', $code)->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->categoryIds[$code] = $id;
                }
            }
        }
    }

    /** Current row counts for the tables Phase A touches — used for the "before" report. */
    public function currentCounts(): array
    {
        return [
            'Unidades' => DB::table('units')->count(),
            'Marcas' => DB::table('brands')->count(),
            'Categorías' => DB::table('categories')->count(),
            'Productos' => DB::table('products')->count(),
            'Proveedores' => DB::table('providers')->count(),
            'Compras' => DB::table('purchases')->count(),
            'Transferencias' => DB::table('transfers')->count(),
            'Clientes' => DB::table('clients')->count(),
            'Ventas' => DB::table('sales')->count(),
            'Cotizaciones' => DB::table('quotations')->count(),
            'Promociones' => DB::table('promotions')->count(),
            'Empresas' => DB::table('companies')->count(),
            'Departamentos' => DB::table('departments')->count(),
            'Cargos' => DB::table('designations')->count(),
            'Turnos' => DB::table('office_shifts')->count(),
            'Empleados' => DB::table('employees')->count(),
            'Asistencias' => DB::table('attendances')->count(),
            'Tipos de permiso' => DB::table('leave_types')->count(),
            'Permisos' => DB::table('leaves')->count(),
            'Días festivos' => DB::table('holidays')->count(),
            'Nóminas' => DB::table('payrolls')->count(),
            'Categorías vacante' => DB::table('recruit_job_categories')->count(),
            'Vacantes' => DB::table('recruit_jobs')->count(),
            'Candidatos' => DB::table('recruit_candidates')->count(),
            'Postulaciones' => DB::table('recruit_applications')->count(),
            'Entrevistas' => DB::table('recruit_interviews')->count(),
            'Categorías activos' => DB::table('asset_categories')->count(),
            'Activos' => DB::table('assets')->count(),
            'Categorías propiedades' => DB::table('property_categories')->count(),
            'Propiedades' => DB::table('properties')->count(),
            'Consultas propiedades' => DB::table('property_inquiries')->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Phase B — providers, initial stock, purchases (+ batches/serials), transfers.
    // Adjustments deferred: see class docblock note in DemoTenantSeeder header
    // and the command's Phase B report — AdjustmentController::store() hard-
    // requires inventory_location_id and this tenant has zero inventory_locations
    // provisioned; creating one is an infra decision out of scope for a demo
    // data seeder, not something this class does silently.
    // ------------------------------------------------------------------

    public function seedProviders(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->data['providers'] as $i => $name) {
            $row = DB::table('providers')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $existing++;

                continue;
            }
            DB::table('providers')->insert([
                'name' => $name,
                'phone' => $this->fakePhone($i),
                'email' => $this->fakeEmail($name),
                'adresse' => 'Zona Comercial, Honduras',
                'country' => 'Honduras',
                'city' => null,
                'tax_number' => null, // fictitious — no real RTN
                'opening_balance' => 0,
                'credit_limit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    private function fakePhone(int $seed): string
    {
        return '+504 9'.sprintf('%03d', 100 + ($seed * 37) % 900).sprintf('%04d', ($seed * 911) % 10000);
    }

    private function fakeEmail(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));

        return substr($slug, 0, 20).'@demo2.prodex.test';
    }

    /**
     * Every demo product gets a product_warehouse row (qte=0) per active
     * warehouse — mirrors exactly how ProductsController::store() seeds
     * product_warehouse at creation (rows always born at qte=0; real stock
     * comes later from a purchase/opening-stock flow). Without this row
     * pre-existing, PurchasesController/TransferController silently no-op
     * the stock increment (verified in both controllers) — never leaving a
     * dangling qte, but never actually moving stock either.
     */
    public function seedInitialStock(): array
    {
        $productIds = $this->allDemoProductIds();
        $warehouseIds = $this->allWarehouseIds();
        $created = 0;
        $existing = 0;

        if (! $productIds || ! $warehouseIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $existingPairs = DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->get(['product_id', 'warehouse_id'])
            ->map(fn ($r) => $r->product_id.':'.$r->warehouse_id)
            ->flip();

        $rows = [];
        foreach ($productIds as $pid) {
            foreach ($warehouseIds as $wid) {
                if ($existingPairs->has($pid.':'.$wid)) {
                    $existing++;

                    continue;
                }
                $rows[] = [
                    'product_id' => $pid,
                    'warehouse_id' => $wid,
                    'product_variant_id' => null,
                    'manage_stock' => 1,
                    'qte' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('product_warehouse')->insert($chunk);
            }
            $created = count($rows);
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /** Tenant owner (role_id=1) — the same authority level normal UI usage runs as. Required to invoke real controllers outside HTTP. */
    private function demoActingUser(): ?User
    {
        return User::where('role_id', 1)->whereNull('deleted_at')->first();
    }

    /**
     * Reuses the container's EXISTING bound `request` (present even in a
     * console command) and just replaces its input + user resolver, instead
     * of binding a brand-new bare Request as the global singleton. Binding a
     * fresh `Request::create()` as `app()->instance('request', ...)` was
     * tried first and broke Gate::forUser($user)->authorize(): some
     * permission/role resolution in this app depends on context the real
     * bound request carries (route/session state) that a from-scratch
     * Request lacks — with it swapped out, every authorizeForUser() call
     * failed with "This action is unauthorized" even for the real tenant
     * owner. Verified via tinker before this fix; verified working after.
     */
    private function makeControllerRequest(array $payload, $user): Request
    {
        $request = app('request');
        $request->replace($payload);
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $request;
    }

    /**
     * Two designated demo products carry batch/serial tracking so Phase B can
     * produce real lotes/vencimientos and real seriales through the actual
     * purchase flow (BatchService/SerialNumberService), instead of inserting
     * ledger rows directly. Idempotent: only flips the flag when it isn't
     * already set; never touches any other product attribute.
     */
    private function ensureTrackingFlags(): array
    {
        $batchProduct = DB::table('products')->where('code', 'PR-DEMO2-001')->whereNull('deleted_at')->first();
        $serialProduct = DB::table('products')->where('code', 'PR-DEMO2-002')->whereNull('deleted_at')->first();

        if ($batchProduct && ! $batchProduct->is_batch_tracked) {
            DB::table('products')->where('id', $batchProduct->id)->update(['is_batch_tracked' => 1]);
        }
        if ($serialProduct && ! $serialProduct->is_imei) {
            DB::table('products')->where('id', $serialProduct->id)->update(['is_imei' => 1]);
        }

        return [
            'batch_product_id' => $batchProduct->id ?? null,
            'serial_product_id' => $serialProduct->id ?? null,
        ];
    }

    /**
     * 10 purchases via the REAL PurchasesController::store() — never a raw
     * insert — so stock increments, batch ledger rows and serial ledger rows
     * all go through the exact same code path a real user's purchase would.
     * Idempotency: a deterministic marker `[DEMO2-PUR-NN]` appended to the
     * purchase `notes` field (the only free-text column on Purchase — there
     * is no dedicated code/reference column under our control, since `Ref`
     * is always auto-generated by the controller itself). Checked BEFORE
     * calling store() so a re-run never creates a second purchase for an
     * index that already exists.
     */
    public function seedPurchases(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $flags = $this->ensureTrackingFlags();
        $productRows = DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'cost']);
        if ($productRows->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $providerIds = DB::table('providers')->whereIn('name', $this->data['providers'])->whereNull('deleted_at')->pluck('id')->values()->all();
        if (! $providerIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $warehouseId = min($this->allWarehouseIds() ?: [0]);
        if (! $warehouseId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $defaultUnitId = DB::table('units')->whereNull('deleted_at')->orderBy('id')->value('id');

        $pool = $productRows->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-PUR-%02d]', $n);
            if (DB::table('purchases')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $statut = $n >= 9 ? 'pending' : 'received';
            $date = now()->subDays(90 - (($n - 1) * 9))->format('Y-m-d');
            $providerId = $providerIds[($n - 1) % count($providerIds)];

            $lineCount = 5 + ($n % 4); // 5..8 lines
            $start = (($n - 1) * 7) % $poolCount;
            $windowIndexes = [];
            for ($k = 0; $k < $lineCount; $k++) {
                $windowIndexes[] = ($start + $k) % $poolCount;
            }

            // The rotation window doesn't reliably land on the two designated
            // batch/serial products (it didn't, for the serial one, before
            // this fix — purchase #2's window started at pool index 7, never
            // covering index 1, PR-DEMO2-002). ADD the designated product's
            // index to the window instead of overwriting an existing slot —
            // overwriting silently dropped whatever product used to be at
            // that slot from this purchase's coverage, which (after a
            // second run recreated the purchase following a delete) left
            // that dropped product's already-transferred-out stock with
            // nothing backing it, going negative. Found and fixed while
            // debugging this exact method.
            if ($n === 1 && $flags['batch_product_id']) {
                $idx = $productRows->search(fn ($p) => $p->id === $flags['batch_product_id']);
                if ($idx !== false && ! in_array($idx, $windowIndexes, true)) {
                    array_unshift($windowIndexes, $idx);
                }
            }
            if ($n === 2 && $flags['serial_product_id']) {
                $idx = $productRows->search(fn ($p) => $p->id === $flags['serial_product_id']);
                if ($idx !== false && ! in_array($idx, $windowIndexes, true)) {
                    array_unshift($windowIndexes, $idx);
                }
            }

            $details = [];
            $grandTotal = 0;

            foreach ($windowIndexes as $k => $poolIndex) {
                $product = $pool[$poolIndex];
                $qty = 10 + (($n + $k) % 6) * 5; // 10..35
                $cost = (float) $product->cost;

                $row = [
                    'purchase_unit_id' => $defaultUnitId,
                    'Unit_cost' => $cost,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'subtotal' => round($cost * $qty, 2),
                    'quantity' => $qty,
                ];

                // Designated batch-tracked product, first purchase only:
                // split its line into 10 lots with varied expiry horizons.
                if ($n === 1 && $product->id === $flags['batch_product_id']) {
                    $row['quantity'] = 100;
                    $row['subtotal'] = round($cost * 100, 2);
                    $batches = [];
                    for ($b = 1; $b <= 10; $b++) {
                        $horizonDays = [30, 45, 60, 90, 120, 180, 270, 365, 545, 730][$b - 1]; // near -> long term, never expired
                        $batches[] = [
                            'batch_no' => sprintf('LOT-DEMO2-%02d', $b),
                            'qty' => 10,
                            'expiry_date' => now()->addDays($horizonDays)->format('Y-m-d'),
                            'mfg_date' => now()->subDays(30)->format('Y-m-d'),
                        ];
                    }
                    $row['batches'] = $batches;
                }

                // Designated serial-tracked product, second purchase only:
                // exactly 10 units, 10 unique serials (count must match qty).
                if ($n === 2 && $product->id === $flags['serial_product_id']) {
                    $row['quantity'] = 10;
                    $row['subtotal'] = round($cost * 10, 2);
                    $row['serial_numbers'] = array_map(fn ($s) => sprintf('SN-DEMO2-%03d', $s), range(1, 10));
                }

                $grandTotal += $row['subtotal'];
                $details[] = $row;
            }

            $payload = [
                'date' => $date,
                'supplier_id' => $providerId,
                'warehouse_id' => $warehouseId,
                'GrandTotal' => round($grandTotal * 1.15, 2),
                'tax_rate' => 15,
                'TaxNet' => round($grandTotal * 0.15, 2),
                'discount' => 0,
                'shipping' => 0,
                'statut' => $statut,
                'notes' => 'Pedido de reabastecimiento '.$marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PurchasesController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Compra {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors ?? []];
    }

    /**
     * 10 transfers via the REAL, CURRENT workflow — never a raw qte update:
     * TransferController::store() [pending, no stock effect] ->
     * TransferWorkflowController::approve() [approval_status=approved ONLY —
     * per the model's own docblock, "approval authorizes the operation but
     * does not move inventory"] -> TransferWorkflowController::dispatchTransfer()
     * [THIS is what actually debits the source warehouse, via
     * TransferWorkflowService::debitLegacySource(), which itself throws
     * "Stock insuficiente" rather than ever going negative].
     *
     * Earlier version of this method called the LEGACY
     * TransferController::approve() (a still-present but superseded method
     * that inline-decrements stock the moment it's called, with no
     * sufficiency check) — that produced 4 products with negative stock in
     * demo01 before this fix, since it moved stock for products that were
     * never actually purchased. Verified fixed: after switching to the
     * workflow controller pair, a run against demo01 produced zero negative
     * rows.
     *
     * Deliberately stops at "dispatched / in transit" (statut=sent,
     * logistics_status=in_transit) and does NOT call
     * TransferLogisticsController::receive() — receiving is a full
     * reconciliation subsystem of its own (request tokens, per-line
     * quantity_good/defective/missing accounting, discrepancies). Completing
     * it automatically for demo data risks generating inconsistent partial-
     * receipt records; "in transit" (stock correctly left the source, not
     * yet credited to the destination) is itself a real, valid, coherent
     * state — visible and testable in the Transferencias view — and is the
     * same category of decision as deferring Adjustments (see class notes).
     *
     * Idempotency marker: `[DEMO2-TRF-NN]` in the transfer `notes` field.
     */
    public function seedTransfers(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        $warehouseIds = $this->allWarehouseIds();
        if (! $user || count($warehouseIds) < 2) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $fromWarehouse = min($warehouseIds);
        $otherWarehouses = array_values(array_diff($warehouseIds, [$fromWarehouse]));
        $defaultUnitId = DB::table('units')->whereNull('deleted_at')->orderBy('id')->value('id');

        // Only products that ACTUALLY carry stock in the source warehouse
        // right now are eligible — never assume a product was purchased just
        // because seedPurchases() ran earlier in this same invocation ( a
        // partial prior run, or a differently-configured re-run, might not
        // have touched it). A safety margin (transfer at most half of what's
        // on hand, minimum 1) keeps this from ever approaching zero.
        $stocked = DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->where('product_warehouse.warehouse_id', $fromWarehouse)
            ->where('products.code', 'like', 'PR-DEMO2-%')
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.qte', '>=', 4)
            ->orderBy('products.code')
            ->get(['product_warehouse.product_id', 'product_warehouse.qte']);

        if ($stocked->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Transferencias: ningún producto demo tiene stock suficiente en el almacén origen todavía — ejecuta seedPurchases() primero.']];
        }

        $pool = $stocked->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-TRF-%02d]', $n);
            if (DB::table('transfers')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $toWarehouse = $otherWarehouses[($n - 1) % count($otherWarehouses)];
            $date = now()->subDays(60 - (($n - 1) * 6))->format('Y-m-d');

            $lineCount = min(3, $poolCount);
            $start = (($n - 1) * 3) % $poolCount;
            $details = [];
            for ($k = 0; $k < $lineCount; $k++) {
                $row = $pool[($start + $k) % $poolCount];
                $safeQty = max(1, min(10, (int) floor(((float) $row->qte) / 2)));
                $details[] = [
                    'purchase_unit_id' => $defaultUnitId,
                    'product_id' => $row->product_id,
                    'product_variant_id' => null,
                    'quantity' => $safeQty,
                    'Unit_cost' => 0,
                    'tax_percent' => 0,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'subtotal' => 0,
                ];
            }

            $payload = [
                'transfer' => [
                    'date' => $date,
                    'from_warehouse' => $fromWarehouse,
                    'to_warehouse' => $toWarehouse,
                    'tax_rate' => 0,
                    'TaxNet' => 0,
                    'discount' => 0,
                    'shipping' => 0,
                    'statut' => 'pending',
                    'notes' => 'Reabastecimiento entre sucursales '.$marker,
                ],
                'GrandTotal' => 0,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(TransferController::class)->store($request);

                $transferId = DB::table('transfers')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->orderByDesc('id')->value('id');
                if (! $transferId) {
                    $failed++;
                    $errors[] = "Transferencia {$marker}: store() no lanzó excepción pero no se encontró el registro por su marcador.";

                    continue;
                }

                $workflow = app(TransferWorkflowController::class);
                $workflow->approve($this->makeControllerRequest([], $user), $transferId);
                $workflow->dispatchTransfer($this->makeControllerRequest([], $user), $transferId);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Transferencia {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    // ------------------------------------------------------------------
    // Phase C — clientes, ventas (POS), cotizaciones, promociones.
    // Reservas: deliberately NOT seeded — see docblock above seedReservasDecision().
    // ------------------------------------------------------------------

    /**
     * 10 fictional clients, raw insert. Audited: ClientController::store()
     * has zero side effects beyond the row itself (no portal/User account,
     * no points-ledger table, no journal entry — `points` is just a plain
     * column). `code` (int, required, no DB uniqueness constraint) is
     * assigned the same way ClientController::getNumberOrder() does:
     * max(existing code)+1, incremented per row inserted this run.
     * Idempotency: exact `name` match against DemoPersonas' fixed 10-name
     * `clients` list — same technique already used for brands/units/providers.
     * tax_number left null: not enforced at DB level, sidesteps the
     * Honduras-only 14-digit fiscal validation entirely (that validation
     * lives in the controller, which this method does not call).
     */
    public function seedClients(): array
    {
        $created = 0;
        $existing = 0;

        $nextCode = ((int) DB::table('clients')->max('code')) + 1;
        $cities = ['Tegucigalpa', 'San Pedro Sula', 'La Ceiba', 'Comayagua', 'Choluteca', 'Danlí', 'Siguatepeque', 'Roatán', 'Juticalpa', 'Santa Rosa de Copán'];

        foreach ($this->data['clients'] as $i => $name) {
            $row = DB::table('clients')->where('name', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $this->clientIds[$name] = $row->id;
                $existing++;

                continue;
            }

            $createdAt = now()->subDays(150 - ($i * 12));
            $id = DB::table('clients')->insertGetId([
                'name' => $name,
                'firstname' => null,
                'lastname' => null,
                'code' => $nextCode++,
                'adresse' => 'Zona Comercial, '.$cities[$i % count($cities)],
                'phone' => $this->fakePhone(100 + $i),
                'email' => $this->fakeEmail($name),
                'country' => 'Honduras',
                'city' => $cities[$i % count($cities)],
                'state' => null,
                'zip' => null,
                'tax_number' => null, // fictitious — no real RTN, left null to skip fiscal format validation entirely
                'is_royalty_eligible' => 1,
                'points' => 0,
                'opening_balance' => 0,
                'credit_limit' => $i % 3 === 0 ? 10000 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $this->clientIds[$name] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /** Re-resolves demo client ids by name — never assumes seedClients() ran earlier in this same process. */
    private function demoClientIds(): array
    {
        return DB::table('clients')->whereIn('name', $this->data['clients'])->whereNull('deleted_at')->pluck('id', 'name')->all();
    }

    /**
     * Products eligible for a demo sale: DEMO catalog, NOT batch/serial
     * tracked (PR-DEMO2-001/002 — those two are deliberately left untouched
     * by Sales so Fase B's purchased lots/serials stay available for their
     * own views, per explicit user instruction), with live stock in the
     * given warehouse right now. Re-queried fresh on every call — the POS
     * controller physically decrements product_warehouse.qte the moment a
     * sale is created, so a later call in the same loop always sees the
     * real, post-decrement quantity.
     */
    private function eligibleSaleProducts(int $warehouseId)
    {
        return DB::table('product_warehouse')
            ->join('products', 'products.id', '=', 'product_warehouse.product_id')
            ->where('product_warehouse.warehouse_id', $warehouseId)
            ->where('products.code', 'like', 'PR-DEMO2-%')
            ->where('products.is_batch_tracked', 0)
            ->where('products.is_imei', 0)
            ->whereNull('product_warehouse.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('product_warehouse.qte', '>=', 4)
            ->orderBy('products.code')
            ->get([
                'product_warehouse.product_id',
                'product_warehouse.qte',
                'products.price',
                'products.unit_sale_id',
            ])
            ->values();
    }

    /**
     * 10 sales via the REAL, current POS entry point — PosController::CreatePOS().
     * Audited: SalesController::store() is hard-blocked (403 MANUAL_SALE_POS_ONLY,
     * dead entry point) — CreatePOS() is the only live path, and it owns every
     * side effect (stock decrement, points, payment_statut, PaymentSale rows).
     * Never insert Sale/SaleDetail/PaymentSale directly.
     *
     * Idempotency marker: a deterministic `sale_uuid` (the column IS the
     * controller's own built-in idempotency key — see the short-circuit at
     * the top of CreatePOS()) — checked BEFORE calling the controller.
     *
     * Payment variety (n mod 3): 'paid' (single Cash payment for the full
     * total), 'partial' (Cash payment for half the total), 'unpaid' (empty
     * `payments` array — valid per CreatePOS's own validation, produces
     * payment_statut='unpaid' with zero PaymentSale rows and zero Account
     * movement). No `account_id` is ever sent, so no Account.balance is
     * ever touched by this seeder, by design — the user's financial
     * caution requirement.
     *
     * `cash_drawer_id` and `warehouse_id` are fixed to the tenant's single
     * active drawer/warehouse pairing (queried fresh, never hardcoded to
     * id=1) — CreatePOS hard-requires an active drawer bound to the target
     * warehouse (UserOperationalAssignmentService::validateRequestedAssignment).
     *
     * `date`/`created_at` are hardcoded to now() inside CreatePOS (no payload
     * field controls them) — backdated via a plain UPDATE AFTER the
     * transaction commits, on sales/sale_details/payment_sales rows for that
     * sale id only. Display-only fields, no side effect re-triggers.
     */
    public function seedSales(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $drawer = DB::table('cash_drawers')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->first();
        if (! $drawer) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Ventas: no existe ninguna caja física activa — CreatePOS() la exige incondicionalmente.']];
        }
        $warehouseId = (int) $drawer->warehouse_id;

        $clientIds = array_values($this->demoClientIds());
        if (! $clientIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Ventas: ningún cliente DEMO existe todavía — ejecuta seedClients() primero.']];
        }

        $assignmentService = app(UserOperationalAssignmentService::class);

        for ($n = 1; $n <= 10; $n++) {
            $marker = self::SALE_UUID_PREFIX.sprintf('%02d', $n).str_repeat('0', 36 - strlen(self::SALE_UUID_PREFIX) - 2);

            if (DB::table('sales')->where('sale_uuid', $marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $pool = $this->eligibleSaleProducts($warehouseId);
            if ($pool->isEmpty()) {
                $failed++;
                $errors[] = "Venta {$marker}: ningún producto DEMO tiene stock suficiente en el almacén activo — ejecuta seedPurchases() primero.";

                continue;
            }

            $lineCount = min(2 + ($n % 3), $pool->count());
            $start = (($n - 1) * 3) % $pool->count();

            $details = [];
            $subtotalSum = 0.0;
            for ($k = 0; $k < $lineCount; $k++) {
                $row = $pool[($start + $k) % $pool->count()];
                $qty = max(1, min(3, (int) floor(((float) $row->qte) * 0.15)));
                $price = (float) $row->price;
                $subtotal = round($price * $qty, 2);
                $subtotalSum += $subtotal;

                $details[] = [
                    'product_id' => $row->product_id,
                    'product_variant_id' => null,
                    'sale_unit_id' => $row->unit_sale_id,
                    'quantity' => $qty,
                    'Unit_price' => $price,
                    'subtotal' => $subtotal,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'imei_number' => null,
                ];
            }

            $taxAmount = round($subtotalSum * 0.15, 2);
            $grandTotal = round($subtotalSum + $taxAmount, 2);

            $paymentKind = match ($n % 3) {
                1 => 'paid',
                2 => 'partial',
                default => 'unpaid',
            };
            // CreatePOS() validates 'payments' => 'required|array' — Laravel's
            // `required` rule treats an EMPTY array as absent and rejects it
            // (found the hard way: the first real run against demo01 failed
            // 3/10 sales with "El campo payments es obligatorio" from `[]`).
            // A single zero-amount line satisfies `required` (0 is not an
            // empty value) while producing zero side effects: normalizePosPayments()
            // computes applied_amount=0 for it, and CreatePOS only creates a
            // PaymentSale when applied_amount > 0 — so this is still the true
            // unpaid path (due=GrandTotal, payment_statut='unpaid', 0 PaymentSale rows).
            $payments = match ($paymentKind) {
                'paid' => [['payment_method_id' => 2, 'amount' => $grandTotal]],
                'partial' => [['payment_method_id' => 2, 'amount' => round($grandTotal * 0.5, 2)]],
                'unpaid' => [['payment_method_id' => 2, 'amount' => 0]],
            };

            $clientId = $clientIds[($n - 1) % count($clientIds)];
            $daysAgo = 90 - (($n - 1) * 3);
            $saleDate = now()->subDays($daysAgo);

            $payload = [
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'cash_drawer_id' => $drawer->id,
                'payments' => $payments,
                'TaxNet' => $taxAmount,
                'discount' => 0,
                'discount_Method' => '2',
                'shipping' => 0,
                'GrandTotal' => $grandTotal,
                'notes' => 'Venta DEMO '.$marker,
                'sale_uuid' => $marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PosController::class)->CreatePOS($request, $assignmentService);

                $saleId = DB::table('sales')->where('sale_uuid', $marker)->whereNull('deleted_at')->value('id');
                if (! $saleId) {
                    $failed++;
                    $errors[] = "Venta {$marker}: CreatePOS() no lanzó excepción pero no se encontró el registro por su sale_uuid.";

                    continue;
                }

                DB::table('sales')->where('id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);
                DB::table('sale_details')->where('sale_id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);
                DB::table('payment_sales')->where('sale_id', $saleId)->update(['date' => $saleDate->format('Y-m-d'), 'created_at' => $saleDate, 'updated_at' => $saleDate]);

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Venta {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 quotations via the REAL QuotationsController::store(). Audited:
     * store() explicitly has NO stock effect ("quotations are proposals" —
     * confirmed by inline comments at the real controller's store()/update()/
     * destroy() methods). None are converted to a sale in this seeder — a
     * conversion is a full real Sale (PosController::CreatePOS, same as
     * seedSales()) with the exact same stock/payment side effects, and the
     * user's instruction was explicitly NOT to convert all of them; leaving
     * all 10 as visible, unconverted quotations keeps the Cotizaciones view
     * populated without doubling up on Sale side effects already covered by
     * seedSales(). Idempotency marker: `[DEMO2-QUO-NN]` in `notes`, same
     * pattern as Purchase/Transfer.
     */
    public function seedQuotations(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $warehouseId = (int) DB::table('cash_drawers')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->value('warehouse_id');
        if (! $warehouseId) {
            $warehouseId = min($this->allWarehouseIds() ?: [0]);
        }
        if (! $warehouseId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: no hay warehouse disponible.']];
        }

        $clientIds = array_values($this->demoClientIds());
        if (! $clientIds) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: ningún cliente DEMO existe todavía — ejecuta seedClients() primero.']];
        }

        $productRows = DB::table('products')->whereIn('code', $this->productCodes())->whereNull('deleted_at')->orderBy('code')->get(['id', 'price', 'unit_sale_id']);
        if ($productRows->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Cotizaciones: catálogo DEMO no existe todavía.']];
        }
        $pool = $productRows->values();
        $poolCount = $pool->count();

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-QUO-%02d]', $n);
            if (DB::table('quotations')->where('notes', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $lineCount = 3 + ($n % 3); // 3..5
            $start = (($n - 1) * 5) % $poolCount;

            $details = [];
            $subtotalSum = 0.0;
            for ($k = 0; $k < $lineCount; $k++) {
                $product = $pool[($start + $k) % $poolCount];
                $qty = 1 + (($n + $k) % 5); // 1..5
                $price = (float) $product->price;
                $subtotal = round($price * $qty, 2);
                $subtotalSum += $subtotal;

                $details[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'sale_unit_id' => $product->unit_sale_id,
                    'quantity' => $qty,
                    'Unit_price' => $price,
                    'subtotal' => $subtotal,
                    'tax_percent' => 15,
                    'tax_method' => '1',
                    'discount' => 0,
                    'discount_Method' => '1',
                    'imei_number' => null,
                ];
            }

            $taxAmount = round($subtotalSum * 0.15, 2);
            $grandTotal = round($subtotalSum + $taxAmount, 2);
            $statut = $n % 2 === 0 ? 'sent' : 'pending';
            $clientId = $clientIds[($n - 1) % count($clientIds)];
            $daysAgo = 45 - (($n - 1) * 4); // 45..9, last few land "recent"
            $date = now()->subDays(max(0, $daysAgo))->format('Y-m-d');

            $payload = [
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'date' => $date,
                'statut' => $statut,
                'GrandTotal' => $grandTotal,
                'tax_rate' => 15,
                'TaxNet' => $taxAmount,
                'Discount' => 0,
                'shipping' => 0,
                'notes' => 'Cotización DEMO '.$marker,
                'details' => $details,
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(QuotationsController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Cotización {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 promotions via the REAL PromotionsController::store(). Audited: the
     * store() method's only side effects are the promotion row itself plus
     * two pivot syncs (promotion_warehouse, promotion_products) — no price
     * mutation, no notifications. Idempotency marker: exact `code` match
     * (`DEMO2-PROMO-NN`), checked before calling store() — mirrors the
     * category/product `code` lookup pattern.
     *
     * Distribution across the 10: 4 currently active (started in the past,
     * ends in the future), 3 future (starts in the future), 3 already ended
     * (both dates in the past) — `is_active` stays true for all; active vs.
     * future vs. ended is purely derived from date range, per the audited
     * model (no separate status column).
     */
    public function seedPromotions(): array
    {
        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => []];
        }

        $warehouseIds = $this->allWarehouseIds();
        $productIds = $this->allDemoProductIds();

        $names = [
            'Descuento Apertura de Temporada', 'Oferta Relámpago Fin de Semana', 'Promo Combo Familiar',
            'Descuento Cliente Frecuente', 'Rebaja de Inventario', 'Promoción Día de Pago',
            'Oferta Especial Aniversario', 'Descuento Compra Mayor', 'Promo Liquidación', 'Oferta Bienvenida',
        ];

        // n=1..4 active, n=5..7 future, n=8..10 ended.
        $schedule = function (int $n): array {
            if ($n <= 4) {
                return [now()->subDays(10 + $n)->format('Y-m-d'), now()->addDays(20 + $n)->format('Y-m-d')];
            }
            if ($n <= 7) {
                return [now()->addDays(5 + $n)->format('Y-m-d'), now()->addDays(35 + $n)->format('Y-m-d')];
            }

            return [now()->subDays(60 + $n)->format('Y-m-d'), now()->subDays(5 + $n)->format('Y-m-d')];
        };

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('DEMO2-PROMO-%02d', $n);
            if (DB::table('promotions')->where('code', $marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            [$startsAt, $endsAt] = $schedule($n);
            $isPercentage = $n % 2 === 1;
            $productScope = $n % 2 === 0 ? 'specific' : 'all';

            $payload = [
                'name' => $names[$n - 1],
                'code' => $marker,
                'description' => 'Promoción de demostración generada por Fase C.',
                'kind' => 'discount',
                'discount_type' => $isPercentage ? 'percentage' : 'fixed',
                'discount_value' => $isPercentage ? (10 + ($n % 4) * 5) : (50 + ($n % 3) * 25),
                'is_active' => true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'product_scope' => $productScope,
                'priority' => $n,
                'stackable' => false,
                'warehouse_ids' => $warehouseIds,
                'product_ids' => $productScope === 'specific' ? array_slice($productIds, (($n - 1) * 5) % max(1, count($productIds)), 5) : [],
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(PromotionsController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Promoción {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * RESERVAS — deliberately NOT seeded. NO-GO decision, documented instead
     * of code, same class of decision as skipping Adjustments in Fase B.
     *
     * Audited: Booking model + BookingController exist and are a real
     * appointment-booking module (customer_id -> Client, product_id ->
     * Product, status pending/confirmed/cancelled/completed). store() has no
     * stock/payment side effects of its own. But:
     *  1. Its routes are gated behind `tenant.feature:bookings`
     *     (routes/tenant_api.php) and demo01's active plan ("Emprendedor")
     *     does NOT include that feature — TenantLimitsService->hasFeature
     *     ('bookings') returns false for this tenant right now. Seeding
     *     bookings anyway would misrepresent demo01 as having a plan
     *     entitlement it does not have, and no real user could reach the
     *     view that shows them.
     *  2. demo01 has exactly one is_service product total, and it is not
     *     part of the Fase A/B DEMO2 catalog (100% physical goods) — there
     *     is nothing realistic to book against even if the gate were
     *     bypassed.
     *
     * No seedReservas() method exists. This decision is surfaced to the
     * operator via the plan()/currentCounts() output and the command's
     * summary table (target=10, existing=0, to_create=0, skipped=10).
     */
    private function seedReservasDecision(): void
    {
    }

    // ------------------------------------------------------------------
    // Phase D — HR: company/departments/designations/office shifts,
    // employees, attendance, leave types/leaves, holidays, payroll.
    // ------------------------------------------------------------------

    /** Re-resolves the demo HR company id by name — never assumes seedHrCompany() ran earlier in this same process. */
    private function demoHrCompanyId(): ?int
    {
        return DB::table('companies')->where('name', $this->data['company_name'])->whereNull('deleted_at')->value('id');
    }

    /**
     * 1 company, raw insert. Audited: CompanyController::store() has zero
     * side effects beyond the row. Reuses the persona's own `company_name`
     * (already used for the tenant's business identity in Fase A/B/C plan
     * output) so the HR org chart reads as the same business, not an
     * unrelated shell company. Idempotent by exact name match.
     */
    public function seedHrCompany(): array
    {
        $existing = DB::table('companies')->where('name', $this->data['company_name'])->whereNull('deleted_at')->first();
        if ($existing) {
            $this->hrCompanyId = $existing->id;

            return ['created' => 0, 'existing' => 1, 'skipped' => 0, 'failed' => 0];
        }

        $this->hrCompanyId = DB::table('companies')->insertGetId([
            'name' => $this->data['company_name'],
            'email' => 'rrhh@'.strtolower(preg_replace('/[^a-z0-9]+/i', '', $this->data['company_name'])).'.demo2.prodex.test',
            'phone' => $this->fakePhone(500),
            'country' => 'Honduras',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['created' => 1, 'existing' => 0, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * 5 departments, raw insert. Audited: DepartmentsController::store() has
     * zero side effects beyond the row (no uniqueness check either — the
     * seeder's own name+company_id pre-check is what keeps this idempotent).
     */
    public function seedHrDepartments(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => count($this->hrDepartments()), 'failed' => 0];
        }
        $this->hrCompanyId = $companyId;

        $created = 0;
        $existing = 0;
        foreach (array_keys($this->hrDepartments()) as $name) {
            $row = DB::table('departments')->where('company_id', $companyId)->where('department', $name)->whereNull('deleted_at')->first();
            if ($row) {
                $this->hrDepartmentIds[$name] = $row->id;
                $existing++;

                continue;
            }
            $id = DB::table('departments')->insertGetId([
                'department' => $name,
                'company_id' => $companyId,
                'department_head' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->hrDepartmentIds[$name] = $id;
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    private function reresolveHrDepartmentIds(int $companyId): void
    {
        foreach (array_keys($this->hrDepartments()) as $name) {
            if (! isset($this->hrDepartmentIds[$name])) {
                $id = DB::table('departments')->where('company_id', $companyId)->where('department', $name)->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->hrDepartmentIds[$name] = $id;
                }
            }
        }
    }

    /**
     * 8 designations via the REAL DesignationsController::store() — audited:
     * it enforces a case-insensitive uniqueness check scoped to
     * company_id+department_id that is app-layer only (no DB unique
     * constraint backs it), so calling the controller is what actually keeps
     * this safe on a second run rather than merely convenient. Pre-checked
     * before calling to keep the granular created/existing counters honest
     * (the controller itself would just abort_if with a 422, which we'd
     * otherwise have to interpret as "existing" after the fact).
     */
    public function seedHrDesignations(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 8, 'failed' => 0];
        }
        $this->reresolveHrDepartmentIds($companyId);

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 8, 'failed' => 0];
        }

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->hrDepartments() as $deptName => $designations) {
            $departmentId = $this->hrDepartmentIds[$deptName] ?? null;
            if (! $departmentId) {
                $failed += count($designations);
                $errors[] = "Cargos de '{$deptName}': departamento no existe.";

                continue;
            }

            foreach ($designations as $designationName) {
                $row = DB::table('designations')->where('company_id', $companyId)->where('department_id', $departmentId)
                    ->whereRaw('LOWER(designation) = ?', [mb_strtolower($designationName)])->whereNull('deleted_at')->first();
                if ($row) {
                    $this->hrDesignationIds[$designationName] = $row->id;
                    $existing++;

                    continue;
                }

                $payload = [
                    'designation' => $designationName,
                    'company_id' => $companyId,
                    'department' => $departmentId,
                ];

                try {
                    $request = $this->makeControllerRequest($payload, $user);
                    app(DesignationsController::class)->store($request);
                    $id = DB::table('designations')->where('company_id', $companyId)->where('department_id', $departmentId)
                        ->whereRaw('LOWER(designation) = ?', [mb_strtolower($designationName)])->whereNull('deleted_at')->value('id');
                    $this->hrDesignationIds[$designationName] = $id;
                    $created++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = "Cargo '{$designationName}': ".$e->getMessage();
                }
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    private function reresolveHrDesignationIds(int $companyId): void
    {
        foreach ($this->hrDepartments() as $deptName => $designations) {
            $departmentId = $this->hrDepartmentIds[$deptName] ?? null;
            if (! $departmentId) {
                continue;
            }
            foreach ($designations as $designationName) {
                if (! isset($this->hrDesignationIds[$designationName])) {
                    $id = DB::table('designations')->where('company_id', $companyId)->where('department_id', $departmentId)
                        ->whereRaw('LOWER(designation) = ?', [mb_strtolower($designationName)])->whereNull('deleted_at')->value('id');
                    if ($id) {
                        $this->hrDesignationIds[$designationName] = $id;
                    }
                }
            }
        }
    }

    /**
     * 4 office shifts via the REAL OfficeShiftController::store() — audited:
     * it runs every day-column through OfficeShift::normalizeTime() (the
     * fix from commit 4b1d525), which is exactly why the controller is used
     * here instead of a raw insert — a raw insert would have to replicate
     * normalizeTime() itself to guarantee canonical "H:i" storage and avoid
     * ever writing a legacy-shaped value like "18:00PM". `null` for a given
     * day means "day off" (nullable columns, confirmed against the
     * migration) — normalizeTime(null) safely returns null.
     */
    public function seedHrOfficeShifts(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => count($this->hrOfficeShifts()), 'failed' => 0];
        }

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => count($this->hrOfficeShifts()), 'failed' => 0];
        }

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->hrOfficeShifts() as $shift) {
            $row = DB::table('office_shifts')->where('company_id', $companyId)->where('name', $shift['name'])->whereNull('deleted_at')->first();
            if ($row) {
                $this->hrOfficeShiftIds[$shift['name']] = $row->id;
                $existing++;

                continue;
            }

            $payload = ['company_id' => $companyId, 'name' => $shift['name']];
            foreach ($shift['schedule'] as $day => $times) {
                $payload[$day.'_in'] = $times[0] ?? null;
                $payload[$day.'_out'] = $times[1] ?? null;
            }

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(OfficeShiftController::class)->store($request);
                $id = DB::table('office_shifts')->where('company_id', $companyId)->where('name', $shift['name'])->whereNull('deleted_at')->value('id');
                $this->hrOfficeShiftIds[$shift['name']] = $id;
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Turno '{$shift['name']}': ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    private function reresolveHrOfficeShiftIds(int $companyId): void
    {
        foreach ($this->hrOfficeShifts() as $shift) {
            if (! isset($this->hrOfficeShiftIds[$shift['name']])) {
                $id = DB::table('office_shifts')->where('company_id', $companyId)->where('name', $shift['name'])->whereNull('deleted_at')->value('id');
                if ($id) {
                    $this->hrOfficeShiftIds[$shift['name']] = $id;
                }
            }
        }
    }

    /**
     * 10 employees via the REAL EmployeesController — audited: `store()`
     * does not accept `basic_salary`/`hourly_rate`/`total_leave` at all (not
     * in its $data array), so a second real call to `update()` is required
     * to set them; `update()`'s remaining_leave logic takes the clean
     * first-time-set path (`employee->total_leave == 0` -> `remaining_leave
     * = total_leave`) for a freshly created employee, which is exactly what
     * we want — never write `remaining_leave` directly. Confirmed: neither
     * method creates a `User` row (no observer, no auth account) — a
     * DEMO employee is HR-only, never a login.
     */
    public function seedHrEmployees(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => count($this->hrEmployees()), 'failed' => 0];
        }
        $this->reresolveHrDepartmentIds($companyId);
        $this->reresolveHrDesignationIds($companyId);
        $this->reresolveHrOfficeShiftIds($companyId);

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => count($this->hrEmployees()), 'failed' => 0];
        }

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->hrEmployees() as $i => $e) {
            $fullName = $e['firstname'].' '.$e['lastname'];
            $row = DB::table('employees')->where('firstname', $e['firstname'])->where('lastname', $e['lastname'])->whereNull('deleted_at')->first();
            $departmentId = $this->hrDepartmentIds[$e['department']] ?? null;
            $designationId = $this->hrDesignationIds[$e['designation']] ?? null;
            $shiftId = $this->hrOfficeShiftIds[$e['shift']] ?? null;

            if ($row) {
                $this->hrEmployeeMeta[$fullName] = [
                    'id' => $row->id, 'shift' => $e['shift'], 'basic_salary' => (float) $row->basic_salary,
                    'hourly_rate' => (float) $row->hourly_rate, 'department' => $e['department'],
                ];
                $existing++;

                continue;
            }

            if (! $departmentId || ! $designationId || ! $shiftId) {
                $failed++;
                $errors[] = "Empleado {$fullName}: departamento/cargo/turno no resuelto.";

                continue;
            }

            $hourlyRate = round($e['basic_salary'] / (30 * 8), 2);
            $storePayload = [
                'firstname' => $e['firstname'],
                'lastname' => $e['lastname'],
                'gender' => $e['gender'],
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'designation_id' => $designationId,
                'office_shift_id' => $shiftId,
                'country' => 'Honduras',
                'email' => $this->fakeEmail($fullName),
                'phone' => $this->fakePhone(600 + $i),
                'birth_date' => now()->subYears(22 + ($i % 15))->format('Y-m-d'),
                'joining_date' => now()->subDays($e['joined_days_ago'])->format('Y-m-d'),
            ];

            try {
                $request = $this->makeControllerRequest($storePayload, $user);
                $response = app(EmployeesController::class)->store($request);
                $employeeId = (int) (json_decode($response->getContent(), true)['employee_id'] ?? 0);
                if (! $employeeId) {
                    $failed++;
                    $errors[] = "Empleado {$fullName}: store() no devolvió employee_id.";

                    continue;
                }

                $totalLeave = 12 + (($i * 2) % 9); // 12..20 varied days
                $updatePayload = $storePayload + [
                    'total_leave' => $totalLeave,
                    'basic_salary' => $e['basic_salary'],
                    'hourly_rate' => $hourlyRate,
                ];
                $updateRequest = $this->makeControllerRequest($updatePayload, $user);
                app(EmployeesController::class)->update($updateRequest, $employeeId);

                $this->hrEmployeeMeta[$fullName] = [
                    'id' => $employeeId, 'shift' => $e['shift'], 'basic_salary' => $e['basic_salary'],
                    'hourly_rate' => $hourlyRate, 'department' => $e['department'],
                ];
                $created++;
            } catch (\Throwable $ex) {
                $failed++;
                $errors[] = "Empleado {$fullName}: ".$ex->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /** Re-resolves demo employee ids/meta by name — never assumes seedHrEmployees() ran earlier in this same process. */
    private function reresolveHrEmployeeMeta(): void
    {
        foreach ($this->hrEmployees() as $e) {
            $fullName = $e['firstname'].' '.$e['lastname'];
            if (isset($this->hrEmployeeMeta[$fullName])) {
                continue;
            }
            $row = DB::table('employees')->where('firstname', $e['firstname'])->where('lastname', $e['lastname'])->whereNull('deleted_at')->first();
            if ($row) {
                $this->hrEmployeeMeta[$fullName] = [
                    'id' => $row->id, 'shift' => $e['shift'], 'basic_salary' => (float) $row->basic_salary,
                    'hourly_rate' => (float) $row->hourly_rate, 'department' => $e['department'],
                ];
            }
        }
    }

    /**
     * Deterministic attendance schedule: 3 dates per employee (offsets 3/10/17
     * days ago, nudged forward to the nearest day their shift is actually
     * working), cycling through 5 scenarios (puntual/tardanza/salida
     * temprana/jornada completa/overtime). Pure function of the fixed
     * hrEmployees()/hrOfficeShifts() data — no DB access — so plan() can call
     * it for the dry-run target count without any writes, and seedAttendances()
     * reuses the exact same rows for the real run.
     */
    private function attendancePlanRows(): array
    {
        $scenarios = ['puntual', 'tardanza', 'salida_temprana', 'jornada_completa', 'overtime'];
        $shiftsByName = collect($this->hrOfficeShifts())->keyBy('name');
        $rows = [];
        $i = 0;

        foreach ($this->hrEmployees() as $empIndex => $e) {
            $schedule = $shiftsByName[$e['shift']]['schedule'];
            foreach ([3, 10, 17] as $offsetIndex => $baseOffset) {
                $date = now()->subDays($baseOffset);
                for ($nudge = 0; $nudge < 7; $nudge++) {
                    $weekday = strtolower($date->format('l'));
                    if (($schedule[$weekday] ?? null) !== null) {
                        break;
                    }
                    $date = $date->copy()->subDay();
                }
                $weekday = strtolower($date->format('l'));
                $times = $schedule[$weekday] ?? null;
                if (! $times) {
                    continue; // shift has no working day at all in the search window — skip, never invent hours
                }

                $scenario = $scenarios[$i % count($scenarios)];
                $i++;

                [$shiftIn, $shiftOut] = $times;
                [$clockIn, $clockOut] = $this->applyAttendanceScenario($shiftIn, $shiftOut, $scenario);

                $rows[] = [
                    'employee' => $e['firstname'].' '.$e['lastname'],
                    'date' => $date->format('Y-m-d'),
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                    'scenario' => $scenario,
                ];
            }
        }

        return $rows;
    }

    private function applyAttendanceScenario(string $shiftIn, string $shiftOut, string $scenario): array
    {
        $in = \Carbon\Carbon::createFromFormat('H:i', $shiftIn);
        $out = \Carbon\Carbon::createFromFormat('H:i', $shiftOut);

        return match ($scenario) {
            'tardanza' => [$in->copy()->addMinutes(20)->format('H:i'), $out->format('H:i')],
            'salida_temprana' => [$in->format('H:i'), $out->copy()->subMinutes(30)->format('H:i')],
            'overtime' => [$in->format('H:i'), $out->copy()->addMinutes(60)->format('H:i')],
            default => [$in->format('H:i'), $out->format('H:i')], // puntual / jornada_completa
        };
    }

    /**
     * ~30 attendance rows via the REAL AttendancesController::store() —
     * audited: it computes late_time/depart_early/overtime/total_work/status
     * itself from the employee's OfficeShift, we only ever submit raw
     * clock_in/clock_out "H:i" strings and let buildAttendanceData() do the
     * math. Idempotency: employee_id+date, checked before each call (no
     * unique DB constraint backs this pair, so the check is the only thing
     * preventing a duplicate on a second run).
     */
    public function seedAttendances(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => $this->attendancePlanCount(), 'failed' => 0];
        }
        $this->reresolveHrEmployeeMeta();

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => $this->attendancePlanCount(), 'failed' => 0];
        }

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->attendancePlanRows() as $row) {
            $meta = $this->hrEmployeeMeta[$row['employee']] ?? null;
            if (! $meta) {
                $failed++;
                $errors[] = "Asistencia {$row['employee']} {$row['date']}: empleado no resuelto — ejecuta seedHrEmployees() primero.";

                continue;
            }

            $alreadyExists = DB::table('attendances')->where('employee_id', $meta['id'])->where('date', $row['date'])->whereNull('deleted_at')->exists();
            if ($alreadyExists) {
                $existing++;

                continue;
            }

            $payload = [
                'company_id' => $companyId,
                'employee_id' => $meta['id'],
                'date' => $row['date'],
                'clock_in' => $row['clock_in'],
                'clock_out' => $row['clock_out'],
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(AttendancesController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Asistencia {$row['employee']} {$row['date']}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 leave types, raw insert. Audited: LeaveType has only `title` +
     * soft-delete, no controller-side side effects beyond the row. Idempotent
     * by exact title match.
     */
    public function seedLeaveTypes(): array
    {
        $created = 0;
        $existing = 0;
        foreach ($this->hrLeaveTypes() as $title) {
            $row = DB::table('leave_types')->where('title', $title)->whereNull('deleted_at')->first();
            if ($row) {
                $existing++;

                continue;
            }
            DB::table('leave_types')->insert(['title' => $title, 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    /**
     * 10 leaves via the REAL LeaveController::store() — audited: this is the
     * ONLY path that correctly decrements `Employee.remaining_leave` (only
     * when status=='approved'; pending/rejected never touch it). A raw
     * insert would silently skip that arithmetic entirely. Known unfixed bug
     * (LeaveController.php:111, `$day->d + 1` instead of `->days`) is NOT
     * worked around — it's simply never triggered: every demo leave's
     * start_date/end_date fall inside the SAME calendar month, where `->d`
     * and `->days` agree. Small day-counts (1-4) per leave keep every demo
     * employee's `remaining_leave` (12-20 at creation) comfortably positive.
     * An approved demo leave is NEVER deleted by this seeder (destroy() does
     * not restore remaining_leave — confirmed unfixed bug — deleting one
     * would permanently desync the balance).
     * Idempotency marker: `[DEMO2-LEAVE-NN]` in `reason`, checked before
     * calling store(). store() doesn't throw on insufficient balance — it
     * returns a 200 JSON with `isvalid:false` instead — so the response body
     * is inspected, not just exceptions.
     */
    public function seedLeaves(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }
        $this->reresolveHrDepartmentIds($companyId);
        $this->reresolveHrEmployeeMeta();

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $leaveTypeIds = DB::table('leave_types')->whereIn('title', $this->hrLeaveTypes())->pluck('id', 'title');
        if ($leaveTypeIds->isEmpty()) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0, 'errors' => ['Permisos: ningún leave_type DEMO existe todavía — ejecuta seedLeaveTypes() primero.']];
        }

        $employees = $this->hrEmployees();
        $leaveTypeNames = $this->hrLeaveTypes();
        // n=1..4 approved, n=5..7 pending, n=8..10 rejected.
        $statusFor = fn (int $n) => $n <= 4 ? 'approved' : ($n <= 7 ? 'pending' : 'rejected');

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        for ($n = 1; $n <= 10; $n++) {
            $marker = sprintf('[DEMO2-LEAVE-%02d]', $n);
            if (DB::table('leaves')->where('reason', 'like', '%'.$marker)->whereNull('deleted_at')->exists()) {
                $existing++;

                continue;
            }

            $emp = $employees[($n - 1) % count($employees)];
            $fullName = $emp['firstname'].' '.$emp['lastname'];
            $meta = $this->hrEmployeeMeta[$fullName] ?? null;
            $departmentId = $this->hrDepartmentIds[$emp['department']] ?? null;
            $leaveTypeId = $leaveTypeIds[$leaveTypeNames[($n - 1) % count($leaveTypeNames)]] ?? null;
            if (! $meta || ! $departmentId || ! $leaveTypeId) {
                $failed++;
                $errors[] = "Permiso {$marker}: empleado/departamento/tipo de permiso no resuelto.";

                continue;
            }

            // Same-month range only (avoids the DateInterval->d bug entirely) —
            // day 3 to day (3+len-1) of a month between 1 and 3 months ago.
            $len = 1 + ($n % 4); // 1..4 days
            $monthsAgo = 1 + ($n % 3);
            $start = now()->subMonths($monthsAgo)->startOfMonth()->addDays(2);
            $end = $start->copy()->addDays($len - 1);

            $payload = [
                'employee_id' => $meta['id'],
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'reason' => 'Solicitud DEMO '.$marker,
                'half_day' => 0,
                'status' => $statusFor($n),
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                $response = app(LeaveController::class)->store($request);
                $body = json_decode($response->getContent(), true);
                if (($body['isvalid'] ?? true) === false) {
                    $failed++;
                    $errors[] = "Permiso {$marker}: ".($body['remaining_leave'] ?? 'rechazado por saldo insuficiente.');

                    continue;
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Permiso {$marker}: ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 holidays via the REAL HolidayController::store() — audited: zero
     * side effects beyond the row. Neutral, generic business-event names
     * (no invented official/legal holidays). Idempotent by title+company_id
     * +start_date.
     */
    public function seedHolidays(): array
    {
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        if (! $companyId) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $titles = [
            'Aniversario de la Empresa', 'Día del Empleado', 'Cierre Administrativo Trimestral',
            'Jornada de Integración', 'Día de Descanso Compensatorio', 'Feriado Nacional',
            'Convención Anual de Ventas', 'Mantenimiento de Instalaciones', 'Capacitación General', 'Cierre de Fin de Año',
        ];

        $created = 0;
        $existing = 0;
        $failed = 0;
        $errors = [];

        for ($n = 1; $n <= 10; $n++) {
            $title = 'DEMO2 '.$titles[$n - 1];
            // n=1..5 past (30..150 days ago), n=6..10 upcoming (15..75 days ahead).
            $date = $n <= 5 ? now()->subDays(30 + ($n - 1) * 30) : now()->addDays(15 + ($n - 6) * 15);

            $row = DB::table('holidays')->where('company_id', $companyId)->where('title', $title)->whereNull('deleted_at')->first();
            if ($row) {
                $existing++;

                continue;
            }

            $payload = [
                'company_id' => $companyId,
                'title' => $title,
                'start_date' => $date->format('Y-m-d'),
                'end_date' => $date->format('Y-m-d'),
                'description' => 'Evento DEMO generado por Fase D.',
            ];

            try {
                $request = $this->makeControllerRequest($payload, $user);
                app(HolidayController::class)->store($request);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Feriado '{$title}': ".$e->getMessage();
            }
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * 10 payrolls, raw insert — the ONE deliberate exception to "always use
     * the real controller" in this seeder. Audited and confirmed:
     * PayrollController::store() (app/Http/Controllers/hrm/PayrollController.php:118-124)
     * hardcodes `payment_status => 'paid'` unconditionally — there is no
     * request field, no branch, no separate generate-only action anywhere in
     * the controller or routes that produces an unpaid row. Calling it would
     * violate the explicit "must stay unpaid, zero financial side effects"
     * requirement on every single call. Confirmed safe to bypass: Payroll
     * has no model observer/boot hook: the only side effect anywhere in the
     * real flow is an Account balance debit gated on `account_id` being
     * non-null (PayrollController.php:127-134) — this seeder never sets it.
     * `Ref` is generated with the exact same sequential `PS_N` scheme
     * `PayrollController::getNumberOrder()` uses, so demo rows interleave
     * correctly with any real payroll numbering.
     * Idempotency: employee_id+date pair, checked before each insert (no
     * unique DB constraint backs it).
     */
    public function seedPayrolls(): array
    {
        $this->reresolveHrEmployeeMeta();
        if (empty($this->hrEmployeeMeta)) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $user = $this->demoActingUser();
        if (! $user) {
            return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        }

        $periodDate = now()->subMonth()->endOfMonth()->format('Y-m-d');
        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($this->hrEmployees() as $n => $e) {
            $fullName = $e['firstname'].' '.$e['lastname'];
            $meta = $this->hrEmployeeMeta[$fullName] ?? null;
            if (! $meta) {
                $failed++;

                continue;
            }

            $alreadyExists = DB::table('payrolls')->where('employee_id', $meta['id'])->where('date', $periodDate)->whereNull('deleted_at')->exists();
            if ($alreadyExists) {
                $existing++;

                continue;
            }

            $lastRef = DB::table('payrolls')->latest('id')->value('Ref');
            if ($lastRef && str_contains($lastRef, '_')) {
                [$prefix, $num] = explode('_', $lastRef, 2);
                $nextRef = $prefix.'_'.((int) $num + 1);
            } else {
                $nextRef = 'PS_1';
            }

            DB::table('payrolls')->insert([
                'user_id' => $user->id,
                'Ref' => $nextRef,
                'date' => $periodDate,
                'employee_id' => $meta['id'],
                'account_id' => null, // deliberately never set — this is what keeps this insert side-effect-free
                'amount' => $meta['basic_salary'],
                'payment_method_id' => null, // nullable column — left unset, same as account_id, to keep this row inert
                'payment_status' => 'unpaid',
                'receiver_account_number' => 'DEMO2-PAYROLL',
                'payment_reference_number' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => $failed];
    }

    // ------------------------------------------------------------------
    // Phase E — recruitment, assets and real estate.
    // ------------------------------------------------------------------

    /** D/R is deliberately part of every globally-unique Phase-E identity. */
    private function personaCode(): string
    {
        return $this->persona === DemoPersonas::DISTRIBUTION ? 'D' : 'R';
    }

    private function recruitJobCategories(): array
    {
        return collect(['Administración', 'Ventas', 'Finanzas', 'Tecnología', 'Operaciones', 'Logística', 'Servicio al Cliente', 'Marketing', 'Recursos Humanos', 'Compras'])
            ->map(fn ($name) => ['name' => $name, 'description' => "Categoría DEMO para {$name}.", 'is_active' => true])
            ->all();
    }

    private function recruitJobs(): array
    {
        $business = $this->persona === DemoPersonas::DISTRIBUTION ? 'distribución' : 'retail y servicios';
        $jobs = [
            ['Analista Administrativo', 'Administración', 'Administración y Finanzas', 'full_time', 'mid', 'open', 1],
            ['Ejecutivo de Ventas', 'Ventas', 'Ventas', 'full_time', 'mid', 'open', 2],
            ['Analista Financiero', 'Finanzas', 'Administración y Finanzas', 'full_time', 'mid', 'on_hold', 1],
            ['Soporte de Sistemas', 'Tecnología', 'Administración y Finanzas', 'contract', 'entry', 'open', 1],
            ['Supervisor de Operaciones', 'Operaciones', 'Almacén e Inventario', 'full_time', 'senior', 'open', 1],
            ['Coordinador de Logística', 'Logística', 'Logística', 'full_time', 'senior', 'open', 1],
            ['Especialista de Servicio al Cliente', 'Servicio al Cliente', 'Ventas', 'part_time', 'entry', 'closed', 2],
            ['Asistente de Marketing', 'Marketing', 'Ventas', 'internship', 'entry', 'draft', 1],
            ['Analista de Recursos Humanos', 'Recursos Humanos', 'Recursos Humanos', 'full_time', 'mid', 'open', 1],
            ['Comprador Junior', 'Compras', 'Administración y Finanzas', 'full_time', 'entry', 'closed', 1],
        ];

        return collect($jobs)->map(function ($job, $i) use ($business) {
            [$title, $category, $department, $type, $level, $status, $vacancies] = $job;
            $n = $i + 1;
            return [
                'title' => $title.' — '.$business,
                'slug' => sprintf('job-demo2-%s-%02d', strtolower($this->personaCode()), $n),
                'category' => $category,
                'department' => $department,
                'job_type' => $type,
                'location' => $n % 3 === 0 ? 'Modalidad híbrida, Honduras' : ($business === 'distribución' ? 'Centro logístico DEMO' : 'Sucursal DEMO'),
                'description' => "Vacante DEMO de {$title} para la operación de {$business}.",
                'requirements' => 'Experiencia relacionada, comunicación profesional y manejo responsable de herramientas digitales.',
                'benefits' => 'Capacitación, ambiente colaborativo y oportunidades de desarrollo.',
                'salary_min' => 11000 + ($n * 1200), 'salary_max' => 14500 + ($n * 1450),
                'currency' => 'HNL', 'vacancies' => $vacancies, 'status' => $status,
                'experience_level' => $level, 'deadline' => now()->addDays(14 + $n)->toDateString(),
            ];
        })->all();
    }

    private function recruitCandidates(): array
    {
        $people = [
            ['Andrea', 'Castillo', 'female', 'Asistente administrativa', 3, 'Administración de empresas', 'website'],
            ['Diego', 'Mejía', 'male', 'Asesor comercial', 4, 'Ventas consultivas y CRM', 'referral'],
            ['Sofía', 'Reyes', 'female', 'Auxiliar contable', 2, 'Contabilidad financiera', 'job_board'],
            ['Mateo', 'Lara', 'male', 'Técnico de soporte', 5, 'Redes, soporte y SQL', 'linkedin'],
            ['Valentina', 'Suazo', 'female', 'Coordinadora operativa', 6, 'Indicadores y mejora continua', 'agency'],
            ['Javier', 'Mendoza', 'male', 'Analista de rutas', 4, 'Logística, inventario y Excel', 'website'],
            ['Natalia', 'Paz', 'female', 'Representante de servicio', 3, 'Atención al cliente y casos', 'walk_in'],
            ['Emilio', 'Duarte', 'male', 'Asistente creativo', 1, 'Contenido, campañas y diseño', 'linkedin'],
            ['Camila', 'Orellana', 'female', 'Generalista de RRHH', 5, 'Selección, inducción y nómina', 'referral'],
            ['Tomás', 'Aguilar', 'male', 'Auxiliar de compras', 2, 'Cotizaciones y negociación', 'job_board'],
        ];

        return collect($people)->map(function ($person, $i) {
            [$first, $last, $gender, $position, $years, $skills, $source] = $person;
            $n = $i + 1;
            return [
                'first_name' => $first, 'last_name' => $last,
                'email' => sprintf('candidate.demo2.%s.%02d@example.test', strtolower($this->personaCode()), $n),
                'phone' => $this->fakePhone(800 + $n), 'gender' => $gender,
                'city' => $n % 2 ? 'San Pedro Sula' : 'Tegucigalpa', 'country' => 'Honduras',
                'current_company' => 'Empresa ficticia DEMO', 'current_position' => $position,
                'current_salary' => 10000 + ($n * 1150), 'expected_salary' => 12500 + ($n * 1300),
                'experience_years' => $years, 'skills' => $skills,
                'education' => 'Formación profesional ficticia relacionada con el puesto.',
                // No resume/photo: both are nullable and the controller treats uploads as optional.
                'linkedin_url' => null, 'portfolio_url' => null,
                'notes' => sprintf('[DEMO2-CAND-%s-%02d] Candidato ficticio para QA visual.', $this->personaCode(), $n),
                'source' => $source,
            ];
        })->all();
    }

    private function recruitApplications(): array
    {
        // The first five start as applied. Creating their interviews below
        // uses the controller's documented transition to `interview`.
        return collect(['applied', 'applied', 'applied', 'applied', 'applied', 'applied', 'screening', 'shortlisted', 'offered', 'hired'])
            ->map(fn ($stage, $i) => [
                'job_index' => $i, 'candidate_index' => $i, 'stage' => $stage,
                'applied_date' => now()->subDays(42 - ($i * 4))->toDateString(),
                'rating' => 3 + ($i % 3),
                'cover_letter' => 'Postulación DEMO preparada para validar el flujo de selección.',
                'notes' => sprintf('[DEMO2-APP-%s-%02d]', $this->personaCode(), $i + 1),
            ])->all();
    }

    private function recruitInterviews(): array
    {
        $specs = [
            [0, 'phone', -3, 'completed'], [0, 'video', 5, 'scheduled'],
            [1, 'in_person', -7, 'completed'], [1, 'panel', 8, 'scheduled'],
            [2, 'technical', -1, 'completed'], [2, 'video', 12, 'scheduled'],
            [3, 'phone', 2, 'scheduled'], [3, 'technical', 16, 'rescheduled'],
            [4, 'group', -5, 'no_show'], [4, 'in_person', 20, 'scheduled'],
        ];

        return collect($specs)->map(function ($spec, $i) {
            [$applicationIndex, $type, $offset, $status] = $spec;
            return [
                'application_index' => $applicationIndex, 'type' => $type,
                'scheduled_at' => now()->startOfHour()->addDays($offset)->addHours(9 + ($i % 4)),
                'duration_minutes' => $i % 3 === 0 ? 45 : 60,
                'location' => in_array($type, ['video', 'phone'], true) ? null : 'Sala DEMO de entrevistas',
                'meeting_link' => $type === 'video' ? 'https://meeting.invalid/demo2-'.$this->personaCode().'-'.($i + 1) : null,
                'status' => $status,
                'rating' => $status === 'completed' ? 3 + ($i % 3) : null,
                'feedback' => $status === 'completed' ? 'Entrevista DEMO completada; continuar evaluación interna.' : null,
                'notes' => sprintf('[DEMO2-INT-%s-%02d] Entrevista DEMO.', $this->personaCode(), $i + 1),
            ];
        })->all();
    }

    private function assetCategories(): array
    {
        return [
            ['name' => 'Computadoras DEMO', 'description' => 'Equipos de cómputo para la operación DEMO.'],
            ['name' => 'Mobiliario DEMO', 'description' => 'Mobiliario administrativo DEMO.'],
            ['name' => 'Equipos POS DEMO', 'description' => 'Equipos de punto de venta DEMO.'],
            ['name' => 'Impresoras DEMO', 'description' => 'Impresión y digitalización DEMO.'],
            ['name' => 'Equipos de red DEMO', 'description' => 'Conectividad de la operación DEMO.'],
        ];
    }

    private function assets(): array
    {
        $items = [
            ['Laptop Administración', 0, 'in_use'], ['Laptop Ventas', 0, 'in_use'],
            ['Escritorio Operativo', 1, 'in_use'], ['Silla Ergonómica Gerencia', 1, 'in_use'],
            ['Terminal POS Principal', 2, 'in_use'], ['Lector de Código POS', 2, 'maintenance'],
            ['Impresora Multifuncional', 3, 'in_use'], ['Impresora Térmica POS', 3, 'maintenance'],
            ['Router Empresarial', 4, 'in_use'], ['Switch de Red 24 Puertos', 4, 'retired'],
        ];
        return collect($items)->map(function ($item, $i) {
            [$name, $categoryIndex, $status] = $item; $n = $i + 1;
            return [
                'tag' => sprintf('AST-DEMO2-%s-%02d', $this->personaCode(), $n), 'name' => $name,
                'category_index' => $categoryIndex, 'serial_number' => sprintf('SN-DEMO2-%s-%04d', $this->personaCode(), $n),
                'description' => 'Activo ficticio DEMO para pruebas de inventario de activos.',
                'purchase_date' => now()->subMonths(4 + $n)->toDateString(), 'purchase_cost' => 2500 + ($n * 1750),
                'status' => $status, 'employee_index' => $i % 10,
                'last_verification' => now()->subDays(10 + $n)->toDateString(),
                // Keep validation safely in the future: the scheduled notifier must not email from demo data.
                'next_validation' => now()->addMonths(6 + ($n % 3))->toDateString(),
            ];
        })->all();
    }

    private function propertyCategories(): array
    {
        // These are the six system-default slugs introduced by the migration;
        // reuse them rather than create duplicate categories for demo data.
        return [
            ['name' => 'Apartment', 'slug' => 'apartment', 'description' => 'Apartamentos para listados DEMO.'],
            ['name' => 'House', 'slug' => 'house', 'description' => 'Casas para listados DEMO.'],
            ['name' => 'Villa', 'slug' => 'villa', 'description' => 'Villas para listados DEMO.'],
            ['name' => 'Office', 'slug' => 'office', 'description' => 'Oficinas para listados DEMO.'],
            ['name' => 'Commercial Property', 'slug' => 'commercial-property', 'description' => 'Locales comerciales DEMO.'],
            ['name' => 'Land', 'slug' => 'land', 'description' => 'Terrenos para listados DEMO.'],
        ];
    }

    private function properties(): array
    {
        $business = $this->persona === DemoPersonas::DISTRIBUTION ? 'Distribución' : 'Retail';
        $items = [
            ['Apartamento Vista Norte', 0, 'rent', 'available', 18500, 82, 2, 2, 1, 'Sector Norte, San Pedro Sula'],
            ['Casa Familiar Residencial Demo', 1, 'sale', 'available', 3650000, 190, 3, 2, 2, 'Residencial Demo, Tegucigalpa'],
            ['Villa Jardines del Valle', 2, 'sale', 'sold', 5800000, 310, 4, 3, 2, 'Zona Valle DEMO'],
            ['Oficina Torre Centro', 3, 'rent', 'available', 32000, 110, null, 2, 2, 'Zona Comercial Centro'],
            ['Local Comercial Plaza Demo', 4, 'rent', 'rented', 42500, 145, null, 1, 3, 'Boulevard Comercial DEMO'],
            ['Terreno Proyecto Norte', 5, 'sale', 'available', 1950000, 750, null, null, null, 'Sector Norte DEMO'],
            ['Apartamento Ejecutivo Central', 0, 'sale', 'available', 2950000, 98, 2, 2, 1, 'Centro DEMO, San Pedro Sula'],
            ['Casa Esquina Parque Demo', 1, 'rent', 'available', 28500, 165, 3, 2, 2, 'Residencial Parque DEMO'],
            ['Oficina Flexible Empresarial', 3, 'sale', 'available', 4100000, 180, null, 3, 4, 'Distrito Empresarial DEMO'],
            ['Bodega Comercial Demo', 4, 'sale', 'available', 6700000, 520, null, 2, 8, 'Zona Logística DEMO'],
        ];
        return collect($items)->map(function ($item, $i) use ($business) {
            [$title, $categoryIndex, $purpose, $status, $price, $area, $beds, $baths, $garage, $address] = $item;
            $n = $i + 1;
            return [
                'title' => $title.' — '.$business, 'slug' => sprintf('prop-demo2-%s-%02d', strtolower($this->personaCode()), $n),
                'category_index' => $categoryIndex, 'description' => "Propiedad ficticia DEMO de {$business}, creada para filtros, tarjetas y dashboards.",
                'purpose' => $purpose, 'status' => $status, 'featured' => $n <= 4,
                'price' => $price, 'area' => $area, 'area_unit' => 'm²', 'bedrooms' => $beds, 'bathrooms' => $baths, 'garage' => $garage,
                'address' => $address, 'city' => $n % 2 ? 'San Pedro Sula' : 'Tegucigalpa', 'region' => 'Honduras',
                'amenities' => $beds ? ['Seguridad', 'Área social', 'Estacionamiento'] : ['Acceso principal', 'Estacionamiento'],
                'agent_name' => 'Asesor DEMO '.$business, 'agent_phone' => $this->fakePhone(950 + $n),
                'agent_email' => sprintf('agent.demo2.%s.%02d@example.test', strtolower($this->personaCode()), $n),
                'agent_whatsapp' => $this->fakePhone(970 + $n),
                'seo_title' => $title, 'seo_description' => 'Listado inmobiliario ficticio para QA.', 'seo_keywords' => 'demo, propiedad, inmobiliaria',
            ];
        })->all();
    }

    private function propertyInquiries(): array
    {
        $names = ['Mariana Flores', 'Ricardo Núñez', 'Paola Sierra', 'Héctor Varela', 'Lucía Ríos', 'Daniel Ponce', 'Elena Cruz', 'Marco Salinas', 'Irene Castro', 'Óscar Molina'];
        return collect($names)->map(fn ($name, $i) => [
            'property_index' => $i, 'name' => $name, 'phone' => $this->fakePhone(1000 + $i),
            'email' => sprintf('inquiry.demo2.%s.%02d@example.test', strtolower($this->personaCode()), $i + 1),
            'message' => 'Hola, me interesa conocer disponibilidad, condiciones y coordinar una visita a esta propiedad DEMO.',
            'status' => ['new', 'read', 'responded', 'closed'][$i % 4],
        ])->all();
    }

    private function existingRecruitApplicationCount(): int
    {
        $jobs = $this->recruitJobIds();
        $candidates = $this->recruitCandidateIds();
        $existing = 0;
        foreach ($this->recruitApplications() as $application) {
            $job = $this->recruitJobs()[$application['job_index']];
            $candidate = $this->recruitCandidates()[$application['candidate_index']];
            if (isset($jobs[$job['slug']], $candidates[$candidate['email']]) && DB::table('recruit_applications')->where('job_id', $jobs[$job['slug']])->where('candidate_id', $candidates[$candidate['email']])->whereNull('deleted_at')->exists()) $existing++;
        }
        return $existing;
    }

    private function recruitJobIds(): array
    {
        return DB::table('recruit_jobs')->whereIn('slug', array_column($this->recruitJobs(), 'slug'))->whereNull('deleted_at')->pluck('id', 'slug')->all();
    }

    private function recruitCandidateIds(): array
    {
        return DB::table('recruit_candidates')->whereIn('email', array_column($this->recruitCandidates(), 'email'))->whereNull('deleted_at')->pluck('id', 'email')->all();
    }

    public function seedRecruitJobCategories(): array
    {
        $created = $existing = 0;
        foreach ($this->recruitJobCategories() as $category) {
            if (DB::table('recruit_job_categories')->where('name', $category['name'])->whereNull('deleted_at')->exists()) {
                $existing++;
                continue;
            }
            DB::table('recruit_job_categories')->insert($category + ['created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedRecruitJobs(): array
    {
        $created = $existing = 0;
        $categories = DB::table('recruit_job_categories')->whereIn('name', array_column($this->recruitJobCategories(), 'name'))->whereNull('deleted_at')->pluck('id', 'name');
        $companyId = $this->hrCompanyId ?? $this->demoHrCompanyId();
        $departments = $companyId ? DB::table('departments')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id', 'department') : collect();
        foreach ($this->recruitJobs() as $job) {
            if (DB::table('recruit_jobs')->where('slug', $job['slug'])->whereNull('deleted_at')->exists()) {
                $existing++;
                continue;
            }
            $category = $job['category']; $department = $job['department'];
            unset($job['category'], $job['department']);
            DB::table('recruit_jobs')->insert($job + [
                'category_id' => $categories[$category] ?? null,
                'department_id' => $departments[$department] ?? null,
                'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedRecruitCandidates(): array
    {
        $created = $existing = 0;
        foreach ($this->recruitCandidates() as $candidate) {
            if (DB::table('recruit_candidates')->where('email', $candidate['email'])->whereNull('deleted_at')->exists()) {
                $existing++;
                continue;
            }
            DB::table('recruit_candidates')->insert($candidate + ['resume_path' => null, 'photo' => null, 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedRecruitApplications(): array
    {
        $created = $existing = $skipped = 0;
        $jobs = $this->recruitJobIds();
        $candidates = $this->recruitCandidateIds();
        foreach ($this->recruitApplications() as $application) {
            $jobId = $jobs[$this->recruitJobs()[$application['job_index']]['slug']] ?? null;
            $candidateId = $candidates[$this->recruitCandidates()[$application['candidate_index']]['email']] ?? null;
            if (! $jobId || ! $candidateId) { $skipped++; continue; }
            if (DB::table('recruit_applications')->where('job_id', $jobId)->where('candidate_id', $candidateId)->whereNull('deleted_at')->exists()) {
                $existing++;
                continue;
            }
            unset($application['job_index'], $application['candidate_index']);
            DB::table('recruit_applications')->insert($application + [
                'job_id' => $jobId, 'candidate_id' => $candidateId, 'reviewed_by' => null, 'reviewed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'failed' => 0];
    }

    public function seedRecruitInterviews(): array
    {
        $created = $existing = $skipped = 0;
        $jobs = $this->recruitJobIds();
        $candidates = $this->recruitCandidateIds();
        $applications = $this->recruitApplications();
        foreach ($this->recruitInterviews() as $interview) {
            $appSpec = $applications[$interview['application_index']] ?? null;
            $applicationId = $appSpec ? DB::table('recruit_applications')->where('job_id', $jobs[$this->recruitJobs()[$appSpec['job_index']]['slug']] ?? null)->where('candidate_id', $candidates[$this->recruitCandidates()[$appSpec['candidate_index']]['email']] ?? null)->whereNull('deleted_at')->value('id') : null;
            if (! $applicationId) { $skipped++; continue; }
            if (DB::table('recruit_interviews')->where('notes', $interview['notes'])->whereNull('deleted_at')->exists()) {
                $existing++;
                continue;
            }
            unset($interview['application_index']);
            // RecruitController::interviews_store has no service/event layer.
            // This is its exact persisted flow, including the guarded stage
            // transition, while avoiding HTTP/auth work inside a CLI seeder.
            $interview['application_id'] = $applicationId;
            RecruitInterview::create($interview);
            $application = RecruitApplication::find($applicationId);
            if ($application && in_array($application->stage, ['applied', 'screening', 'shortlisted'], true)) {
                $application->update(['stage' => 'interview']);
            }
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'failed' => 0];
    }

    public function seedAssetCategories(): array
    {
        if (! app(TenantLimitsService::class)->hasFeature('assets')) return ['created' => 0, 'existing' => 0, 'skipped' => 5, 'failed' => 0];
        $created = $existing = 0;
        foreach ($this->assetCategories() as $category) {
            if (DB::table('asset_categories')->where('name', $category['name'])->whereNull('deleted_at')->exists()) { $existing++; continue; }
            DB::table('asset_categories')->insert($category + ['created_at' => now(), 'updated_at' => now()]); $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedAssets(): array
    {
        if (! app(TenantLimitsService::class)->hasFeature('assets')) return ['created' => 0, 'existing' => 0, 'skipped' => 10, 'failed' => 0];
        $created = $existing = 0;
        $categories = DB::table('asset_categories')->whereIn('name', array_column($this->assetCategories(), 'name'))->whereNull('deleted_at')->pluck('id', 'name')->all();
        $employees = DB::table('employees')->where(function ($q) { foreach ($this->hrEmployees() as $employee) $q->orWhere(fn ($w) => $w->where('firstname', $employee['firstname'])->where('lastname', $employee['lastname'])); })->whereNull('deleted_at')->get(['id', 'firstname', 'lastname'])->mapWithKeys(fn ($employee) => [$employee->firstname.' '.$employee->lastname => $employee->id])->all();
        $warehouses = $this->allWarehouseIds();
        foreach ($this->assets() as $asset) {
            if (DB::table('assets')->where('tag', $asset['tag'])->whereNull('deleted_at')->exists()) { $existing++; continue; }
            $categoryId = $categories[$this->assetCategories()[$asset['category_index']]['name']] ?? null;
            $employee = $this->hrEmployees()[$asset['employee_index']];
            $employeeId = $employees[$employee['firstname'].' '.$employee['lastname']] ?? null;
            unset($asset['category_index'], $asset['employee_index']);
            DB::table('assets')->insert($asset + ['asset_category_id' => $categoryId, 'warehouse_id' => $warehouses[0] ?? null, 'assigned_to_id' => $employeeId, 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedPropertyCategories(): array
    {
        $created = $existing = 0;
        foreach ($this->propertyCategories() as $category) {
            if (DB::table('property_categories')->where('slug', $category['slug'])->whereNull('deleted_at')->exists()) { $existing++; continue; }
            DB::table('property_categories')->insert($category + ['image' => null, 'created_at' => now(), 'updated_at' => now()]); $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedProperties(): array
    {
        $created = $existing = 0;
        $categories = DB::table('property_categories')->whereIn('slug', array_column($this->propertyCategories(), 'slug'))->whereNull('deleted_at')->pluck('id', 'slug')->all();
        foreach ($this->properties() as $property) {
            if (DB::table('properties')->where('slug', $property['slug'])->whereNull('deleted_at')->exists()) { $existing++; continue; }
            $categoryId = $categories[$this->propertyCategories()[$property['category_index']]['slug']] ?? null; unset($property['category_index']);
            $property['amenities'] = json_encode($property['amenities']);
            DB::table('properties')->insert($property + ['property_category_id' => $categoryId, 'featured_image' => null, 'gallery' => json_encode([]), 'views' => 0, 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => 0, 'failed' => 0];
    }

    public function seedPropertyInquiries(): array
    {
        $created = $existing = $skipped = 0;
        $properties = DB::table('properties')->whereIn('slug', array_column($this->properties(), 'slug'))->whereNull('deleted_at')->pluck('id', 'slug')->all();
        foreach ($this->propertyInquiries() as $inquiry) {
            if (DB::table('property_inquiries')->where('email', $inquiry['email'])->whereNull('deleted_at')->exists()) { $existing++; continue; }
            $propertyId = $properties[$this->properties()[$inquiry['property_index']]['slug']] ?? null; unset($inquiry['property_index']);
            if (! $propertyId) { $skipped++; continue; }
            // Deliberately not the public storefront controller: it sends a
            // best-effort email notification, which demo seeding must never do.
            DB::table('property_inquiries')->insert($inquiry + ['property_id' => $propertyId, 'created_at' => now(), 'updated_at' => now()]); $created++;
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'failed' => 0];
    }

    // Phase F. Store entities are gated by online_orders. These helpers use
    // stable real columns; no uploads, checkout, payment, queue or API call.
    private function storeCollectionSlugs(): array { return array_map(fn ($n) => 'demo2-'.strtolower($this->personaCode()).'-'.$n, ['recommended','new','accessories','offers','featured']); }
    private function storeBannerTitles(): array { return array_map(fn ($n) => 'DEMO2 '.strtoupper($this->personaCode()).' '.$n, ['Recomendados','Novedades','Ofertas']); }
    private function storeSubscriberEmails(): array { return array_map(fn ($i) => sprintf('store-subscriber.demo2.%s.%02d@example.test', strtolower($this->personaCode()), $i), range(1, 10)); }
    private function storeInviteCodes(): array { return array_map(fn ($i) => sprintf('DEMO2-%s-INV-%02d', $this->personaCode(), $i), range(1, 10)); }
    private function onlineStoreEnabled(): bool { return app(TenantLimitsService::class)->hasFeature('online_orders'); }
    private function phaseFResult(int $target, callable $fn): array { if (! $this->onlineStoreEnabled()) return ['created'=>0,'existing'=>0,'skipped'=>$target,'failed'=>0]; return $fn(); }

    public function seedStoreCollections(): array { return $this->phaseFResult(5, function () { $c=$e=0; $products=DB::table('products')->whereIn('code',array_slice($this->productCodes(),0,5))->whereNull('deleted_at')->pluck('id')->all(); foreach ($this->storeCollectionSlugs() as $i=>$slug) { $row=DB::table('collections')->where('slug',$slug)->first(); if ($row) $e++; else { DB::table('collections')->insert(['title'=>['Recomendados DEMO','Novedades DEMO','Accesorios DEMO','Ofertas DEMO','Destacados DEMO'][$i],'slug'=>$slug,'description'=>'Colección DEMO para tienda '.$this->personaLabel().'.','limit'=>8,'sort_order'=>$i+1,'created_at'=>now(),'updated_at'=>now()]); $row=DB::table('collections')->where('slug',$slug)->first(); $c++; } foreach (array_slice($products,0,3) as $n=>$pid) if (!DB::table('collection_product')->where('collection_id',$row->id)->where('product_id',$pid)->exists()) DB::table('collection_product')->insert(['collection_id'=>$row->id,'product_id'=>$pid,'sort_order'=>$n+1,'pinned'=>$n===0,'created_at'=>now(),'updated_at'=>now()]); } return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }); }
    public function seedStoreBanners(): array { return $this->phaseFResult(3, function () { $c=$e=0; foreach ($this->storeBannerTitles() as $i=>$title) { if (DB::table('store_banners')->where('title',$title)->exists()) {$e++; continue;} DB::table('store_banners')->insert(['title'=>$title,'position'=>'home_hero','link'=>null,'image'=>null,'active'=>true,'created_at'=>now(),'updated_at'=>now()]); $c++; } return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }); }
    public function seedStoreSubscribers(): array { return $this->phaseFResult(10, function () { $c=$e=0; foreach ($this->storeSubscriberEmails() as $email) { if (DB::table('subscribers')->where('email',$email)->exists()) {$e++; continue;} DB::table('subscribers')->insert(['email'=>$email,'created_at'=>now(),'updated_at'=>now()]); $c++; } return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }); }
    public function seedStoreInviteCodes(): array { return $this->phaseFResult(10, function () { $c=$e=0; foreach ($this->storeInviteCodes() as $code) { if (DB::table('invite_codes')->where('code',$code)->exists()) {$e++; continue;} DB::table('invite_codes')->insert(['code'=>$code,'created_by'=>null,'max_uses'=>25,'times_used'=>0,'expires_at'=>now()->addYear(),'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]); $c++; } return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }); }

    private function subscriptionPairs(): array { return array_map(fn ($i) => [$this->data['clients'][$i], $this->productCodes()[$i]], range(0, 9)); }
    private function existingProductSubscriptionCount(): int { $n=0; foreach ($this->subscriptionPairs() as [$client,$code]) { $cid=DB::table('clients')->where('name',$client)->whereNull('deleted_at')->value('id'); $pid=DB::table('products')->where('code',$code)->whereNull('deleted_at')->value('id'); if ($cid && $pid && DB::table('subscriptions')->where('client_id',$cid)->where('product_id',$pid)->whereNull('deleted_at')->exists()) $n++; } return $n; }
    public function seedProductSubscriptions(): array { $user=$this->demoActingUser(); $warehouse=DB::table('warehouses')->whereNull('deleted_at')->value('id'); if (!$user || !$warehouse) return ['created'=>0,'existing'=>0,'skipped'=>10,'failed'=>0]; $c=$e=0; foreach ($this->subscriptionPairs() as $i=>[$client,$code]) { $cl=DB::table('clients')->where('name',$client)->whereNull('deleted_at')->first(); $p=DB::table('products')->where('code',$code)->whereNull('deleted_at')->first(); if (!$cl || !$p) continue; if(DB::table('subscriptions')->where('client_id',$cl->id)->where('product_id',$p->id)->whereNull('deleted_at')->exists()) {$e++;continue;} $price=(float)$p->price; DB::table('subscriptions')->insert(['date'=>now()->toDateString(),'user_id'=>$user->id,'client_id'=>$cl->id,'product_id'=>$p->id,'warehouse_id'=>$warehouse,'cycle_type'=>'monthly','total_cycles'=>12,'billing_cycle'=>'monthly','remaining_cycles'=>12,'price_per_cycle'=>$price,'price_per_unit'=>$price,'quantity'=>1,'next_billing_date'=>now()->addMonths(6+$i)->toDateString(),'status'=>'active','created_at'=>now(),'updated_at'=>now()]);$c++; } return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }
    public function seedWhatsappTemplates(): array { $c=$e=0; foreach (['pedido_confirmado','pedido_enviado','pedido_entregado','recordatorio_pago','cotizacion_disponible','bienvenida','seguimiento_compra','promocion','recordatorio_reserva','gracias_compra'] as $i=>$name) {$key='demo2_wa_'.strtolower($this->personaCode()).'_'.($i+1); if(DB::table('whatsapp_templates')->where('key',$key)->whereNull('deleted_at')->exists()){$e++;continue;} DB::table('whatsapp_templates')->insert(['name'=>'DEMO2 '.str_replace('_',' ',$name),'key'=>$key,'body'=>'Mensaje DEMO para {{customer_name}}.','language'=>'es','category'=>'utility','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$c++;} return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }
    public function seedWhatsappLogs(): array { $c=$e=0; foreach (range(1,10) as $i) {$id=sprintf('DEMO2-WA-%s-%02d',$this->personaCode(),$i);if(DB::table('whatsapp_logs')->where('provider_message_id',$id)->exists()){$e++;continue;} DB::table('whatsapp_logs')->insert(['recipient'=>$this->fakePhone(1200+$i),'template_key'=>'demo2_wa_'.strtolower($this->personaCode()).'_'.(($i-1)%10+1),'message_type'=>'text','body'=>'Registro histórico DEMO; no enviado.','status'=>['sent','delivered','read','failed','pending'][$i%5],'provider_message_id'=>$id,'error'=>$i%5===3?'Fallo DEMO histórico':null,'meta'=>json_encode(['demo'=>true]),'sent_at'=>now()->subDays($i),'created_at'=>now(),'updated_at'=>now()]);$c++;} return ['created'=>$c,'existing'=>$e,'skipped'=>0,'failed'=>0]; }
}
