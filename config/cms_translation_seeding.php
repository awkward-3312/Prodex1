<?php

/**
 * Default CMS landing translation seeder (central DB).
 *
 * Base English content lives on model columns; JSON `translations` holds overrides.
 * Locales listed here are filled only for fields that are not already present (idempotent).
 * Omit `en` — it is the default column locale and is not stored in `translations`.
 */
return [
    // Example — only French and Arabic: ['fr', 'ar']
    'locales' => ['fr', 'ar', 'es', 'de', 'pt', 'tr', 'hi', 'bn'],
];
