<?php

namespace App\Services;

use App\Models\Central\LandingCta;
use App\Models\Central\LandingFaq;
use App\Models\Central\LandingFeature;
use App\Models\Central\LandingFeaturesSection;
use App\Models\Central\LandingFooter;
use App\Models\Central\LandingHero;
use App\Models\Central\LandingHowItWorksSection;
use App\Models\Central\LandingHowItWorksStep;
use App\Models\Central\LandingPricing;
use App\Models\Central\LandingPrivacyPolicy;
use App\Models\Central\LandingSeo;
use App\Models\Central\LandingStat;
use App\Models\Central\LandingTermsConditions;
use App\Models\Central\LandingTestimonial;
use Illuminate\Support\Facades\DB;

class LandingCmsService
{
    protected string $connection = 'central';

    /** Map section key → Eloquent model class (used for translated reads). */
    protected array $modelMap = [
        'hero'    => LandingHero::class,
        'pricing' => LandingPricing::class,
        'cta'     => LandingCta::class,
        'footer'         => LandingFooter::class,
        'seo'            => LandingSeo::class,
        'privacy_policy' => LandingPrivacyPolicy::class,
        'terms_conditions' => LandingTermsConditions::class,
    ];

    /**
     * Get a single-record section via its Eloquent model (translation-aware).
     */
    public function getSection(string $section): ?object
    {
        $modelClass = $this->modelMap[$section] ?? null;

        try {
            if ($modelClass) {
                return $modelClass::first();
            }

            // Fallback for unknown section keys
            return DB::connection($this->connection)->table("landing_{$section}")->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The features section header lives in `landing_features_section`.
     */
    public function getFeaturesSection(): ?LandingFeaturesSection
    {
        try {
            return LandingFeaturesSection::first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getFeatureItems(): \Illuminate\Support\Collection
    {
        try {
            return LandingFeature::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function getTestimonials(): \Illuminate\Support\Collection
    {
        try {
            return LandingTestimonial::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function getFaqs(): \Illuminate\Support\Collection
    {
        try {
            return LandingFaq::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function getStats(): \Illuminate\Support\Collection
    {
        try {
            return LandingStat::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function getHowItWorksSection(): ?LandingHowItWorksSection
    {
        try {
            return LandingHowItWorksSection::first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getHowItWorksSteps(): \Illuminate\Support\Collection
    {
        try {
            return LandingHowItWorksStep::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public function updateSection(string $section, array $data): bool
    {
        $table = "landing_{$section}";

        try {
            $existing = DB::connection($this->connection)->table($table)->first();

            if ($existing) {
                DB::connection($this->connection)->table($table)->where('id', $existing->id)->update($data);
            } else {
                DB::connection($this->connection)->table($table)->insert($data);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
