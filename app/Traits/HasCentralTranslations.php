<?php

namespace App\Traits;

use Illuminate\Support\Facades\App;

/**
 * Provides locale-aware attribute access for landing CMS models.
 *
 * The base columns hold the default (English) content.
 * The `translations` JSON column stores overrides per locale:
 *   {"fr": {"title": "…"}, "ar": {"title": "…", "description": "…"}}
 *
 * Usage in a model:
 *   use HasCentralTranslations;
 *   protected array $translatable = ['title', 'description'];
 *
 * Then $model->title automatically returns the translated value for
 * the current app locale, falling back to the base column.
 */
trait HasCentralTranslations
{
    /** Boot: ensure `translations` is cast to array. */
    public function initializeHasCentralTranslations(): void
    {
        $this->mergeCasts(['translations' => 'array']);
        $this->mergeFillable(['translations']);
    }

    /**
     * Get a translated value for a given field + locale.
     * Falls back to the base column value.
     */
    public function trans(string $field, ?string $locale = null): mixed
    {
        $locale = $locale ?: App::getLocale();
        $defaultLocale = config('app.fallback_locale', 'en');

        // If requesting the default locale, return base column
        if ($locale === $defaultLocale) {
            return $this->getOriginalAttribute($field);
        }

        $translations = $this->translations ?? [];

        return $translations[$locale][$field]
            ?? $this->getOriginalAttribute($field);
    }

    /**
     * Set a translated value for a given field + locale.
     */
    public function setTranslation(string $field, string $locale, ?string $value): static
    {
        $translations = $this->translations ?? [];
        $translations[$locale][$field] = $value;
        $this->translations = $translations;

        return $this;
    }

    /**
     * Get all translations for a given locale.
     */
    public function getTranslationsForLocale(string $locale): array
    {
        return ($this->translations ?? [])[$locale] ?? [];
    }

    /**
     * Override getAttribute to auto-translate translatable fields.
     * Covers both text fields (`$translatable`) and image-path fields
     * (`$translatableImages`) so reads are locale-aware everywhere.
     */
    public function getAttribute($key)
    {
        if ($key !== 'translations'
            && ($this->isTranslatableField($key) || $this->isTranslatableImageField($key))
        ) {
            return $this->trans($key);
        }

        return parent::getAttribute($key);
    }

    /** Whether the given key is a translatable text field. */
    protected function isTranslatableField(string $key): bool
    {
        return property_exists($this, 'translatable') && in_array($key, $this->translatable, true);
    }

    /** Whether the given key is a translatable image-path field. */
    protected function isTranslatableImageField(string $key): bool
    {
        return property_exists($this, 'translatableImages') && in_array($key, $this->translatableImages, true);
    }

    /**
     * Access the raw (default-locale) attribute value, bypassing translation.
     */
    public function getOriginalAttribute(string $key): mixed
    {
        return parent::getAttribute($key);
    }

    /**
     * Get the translatable text fields defined on this model.
     * (Image fields are intentionally excluded so the text-translation UI
     * does not render them as text inputs.)
     */
    public function getTranslatableFields(): array
    {
        return property_exists($this, 'translatable') ? $this->translatable : [];
    }

    /**
     * Get the translatable image-path fields defined on this model.
     */
    public function getTranslatableImageFields(): array
    {
        return property_exists($this, 'translatableImages') ? $this->translatableImages : [];
    }

    /**
     * Read the raw per-locale value for a field WITHOUT falling back to the
     * base column. Returns null when this locale has no own override.
     * For the default locale, returns the base column value.
     */
    public function ownTranslation(string $field, string $locale): mixed
    {
        $defaultLocale = config('app.fallback_locale', 'en');

        if ($locale === $defaultLocale) {
            return $this->getOriginalAttribute($field);
        }

        return ($this->translations ?? [])[$locale][$field] ?? null;
    }
}
