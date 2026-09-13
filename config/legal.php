<?php

declare(strict_types=1);

/**
 * Centralized legal identity used across the public legal pages (Terms,
 * Privacy Policy, Refund Policy) so these values are edited in exactly one
 * place instead of being hardcoded per document/per language.
 *
 * These values are intentionally left unset (null) until a real, verifiable
 * legal identity is provided by the business owner. The legal pages render
 * safely without them (falling back to the confirmed public support
 * channel), but Paddle's Domain Review — and, more importantly, the
 * enforceability of the Terms as a contract — expects the brand owner to be
 * clearly identifiable. See the "legal identity" section of the
 * implementation report for exactly what is still missing.
 */
return [

    // Registered/legal name of the entity that owns and operates PRODEX
    // (e.g. "Prodex Software, S. de R.L." or the applicable legal form).
    // Leave null until confirmed — never invent this.
    'entity_name' => env('LEGAL_ENTITY_NAME'),

    // Tax identification number (RTN in Honduras). Leave null until confirmed.
    'tax_id' => env('LEGAL_TAX_ID'),

    // Full registered/legal address of the entity. Leave null until confirmed.
    'address' => env('LEGAL_ADDRESS'),

    // Dedicated legal/compliance contact address, if one exists.
    // Falls back to the confirmed, already-public support mailbox
    // (LandingFooter::contact_email, seeded as soporte@prodexhub.cloud) when
    // not set, so the legal pages never reference an unverified address.
    'legal_email' => env('LEGAL_EMAIL'),

];
