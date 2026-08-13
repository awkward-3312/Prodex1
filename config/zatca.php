<?php

/*
 * ZATCA (Fatoora) Phase 2 integration endpoints and CSR certificate
 * template names per environment, following the official ZATCA
 * "E-Invoicing Integration Sandbox / Simulation / Core" API specification.
 */
return [

    // Default environment for new tenants: sandbox (developer portal).
    'default_environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    'environments' => [
        'sandbox' => [
            'label' => 'Sandbox (Developer Portal)',
            'base_url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            // Certificate template name used inside the CSR (1.3.6.1.4.1.311.20.2)
            'csr_template' => 'TSTZATCA-Code-Signing',
        ],
        'simulation' => [
            'label' => 'Simulation',
            'base_url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            'csr_template' => 'PREZATCA-Code-Signing',
        ],
        'production' => [
            'label' => 'Production',
            'base_url' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
            'csr_template' => 'ZATCA-Code-Signing',
        ],
    ],

    // HTTP timeout (seconds) for calls to the Fatoora gateway.
    'timeout' => (int) env('ZATCA_HTTP_TIMEOUT', 30),

    // Hash of "0" — the Previous Invoice Hash (PIH) mandated by ZATCA for
    // the first invoice in a chain.
    'initial_pih' => 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
];
