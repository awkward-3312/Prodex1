<?php

declare(strict_types=1);

/**
 * Tenant routes are loaded from RouteServiceProvider::mapTenantRoutes()
 * (tenant_web.php, tenant_api.php, portal.php) when host is a tenant domain.
 * This file is kept for stancl/tenancy compatibility; add tenant-only routes here if needed.
 */

use Illuminate\Support\Facades\Route;

// Tenant app routes are in tenant_web.php, tenant_api.php, portal.php (loaded by RouteServiceProvider).
