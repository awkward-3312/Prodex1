<?php

/**
 * Default avatar filenames for a brand-new TENANT user who did not upload
 * their own photo. All live in public/images/avatar/ (the same global,
 * non-tenant-scoped folder as the legacy no_avatar.png placeholder, matching
 * how UserController / Organization\*AccessController already read/write
 * avatars — see app/Http/Controllers/UserController.php).
 *
 * These are SHARED static assets: many users can be assigned the same one,
 * so nothing that persists a filename returned here may ever unlink() the
 * file — see default_tenant_avatar_filenames() usage as the guard list
 * everywhere an avatar is replaced.
 */
if (! function_exists('default_tenant_avatar_filenames')) {
    function default_tenant_avatar_filenames(): array
    {
        return [
            'default_avatar_1.png',
            'default_avatar_2.png',
            'default_avatar_3.png',
            'default_avatar_4.png',
        ];
    }
}

/**
 * Picks ONE of the default avatars at random. Called once, at tenant user
 * creation time (never per-render) — the choice is persisted to
 * users.avatar like any other filename.
 */
if (! function_exists('random_default_tenant_avatar_filename')) {
    function random_default_tenant_avatar_filename(): string
    {
        $defaults = default_tenant_avatar_filenames();

        return $defaults[array_rand($defaults)];
    }
}

/**
 * True for the legacy placeholder AND any of the 4 random defaults — the
 * single guard every avatar-replace path must use before @unlink()'ing an
 * old file, so a shared default image assigned to other users is never
 * deleted from disk.
 */
if (! function_exists('is_default_tenant_avatar_filename')) {
    function is_default_tenant_avatar_filename(?string $filename): bool
    {
        if (! $filename) {
            return true;
        }

        return $filename === 'no_avatar.png' || in_array($filename, default_tenant_avatar_filenames(), true);
    }
}

/**
 * "Sin avatar propio" for the EXISTING-user backfill (prodex:backfill-default-avatars):
 * NULL, '' or the legacy 'no_avatar.png' placeholder — the only generic/default
 * value the codebase has ever written for "no avatar uploaded" before the 4
 * random defaults existed.
 *
 * Deliberately NARROWER than is_default_tenant_avatar_filename(): a user who
 * already has one of the 4 random defaults (default_avatar_1..4.png) must be
 * LEFT ALONE here — re-randomizing an already-assigned default would make
 * the backfill non-idempotent. is_default_tenant_avatar_filename() stays the
 * (broader) "never delete this file" guard; this is the (narrower) "does this
 * user still need one assigned" predicate.
 */
if (! function_exists('needs_default_tenant_avatar_assignment')) {
    function needs_default_tenant_avatar_assignment(?string $filename): bool
    {
        return ! $filename || $filename === 'no_avatar.png';
    }
}
