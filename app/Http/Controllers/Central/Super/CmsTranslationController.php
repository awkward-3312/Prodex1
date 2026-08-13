<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\LandingCta;
use App\Models\Central\LandingFaq;
use App\Models\Central\LandingFeature;
use App\Models\Central\LandingFeaturesSection;
use App\Models\Central\LandingFooter;
use App\Models\Central\LandingHero;
use App\Models\Central\LandingPricing;
use App\Models\Central\LandingPrivacyPolicy;
use App\Models\Central\LandingSeo;
use App\Models\Central\LandingStat;
use App\Models\Central\LandingTermsConditions;
use App\Models\Central\LandingTestimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CmsTranslationController extends Controller
{
    /** Map section key → [model, label, mode] */
    protected array $sections = [
        'hero'             => ['model' => LandingHero::class,            'label' => 'Hero',             'mode' => 'single'],
        'features_section' => ['model' => LandingFeaturesSection::class, 'label' => 'Features Section', 'mode' => 'single'],
        'features'         => ['model' => LandingFeature::class,         'label' => 'Features',         'mode' => 'multi'],
        'pricing'          => ['model' => LandingPricing::class,         'label' => 'Pricing',          'mode' => 'single'],
        'testimonials'     => ['model' => LandingTestimonial::class,     'label' => 'Testimonials',     'mode' => 'multi'],
        'faqs'             => ['model' => LandingFaq::class,             'label' => 'FAQ',              'mode' => 'multi'],
        'stats'            => ['model' => LandingStat::class,            'label' => 'Stats',            'mode' => 'multi'],
        'cta'              => ['model' => LandingCta::class,             'label' => 'CTA',              'mode' => 'single'],
        'footer'           => ['model' => LandingFooter::class,          'label' => 'Footer',           'mode' => 'single'],
        'seo'              => ['model' => LandingSeo::class,             'label' => 'SEO',              'mode' => 'single'],
        'privacy_policy'   => ['model' => LandingPrivacyPolicy::class,  'label' => 'Privacy Policy',   'mode' => 'single'],
        'terms_conditions' => ['model' => LandingTermsConditions::class, 'label' => 'Terms & Conditions', 'mode' => 'single'],
    ];

    /**
     * Show the translations dashboard with all sections.
     */
    public function index(Request $request): View
    {
        $languages = CentralLanguage::active();
        $locale = $request->get('locale', $languages->firstWhere('is_default', false)?->locale ?? 'fr');

        $sectionData = [];
        foreach ($this->sections as $key => $cfg) {
            $modelClass = $cfg['model'];

            if ($cfg['mode'] === 'single') {
                $record = $modelClass::first();
                $sectionData[$key] = [
                    'label'  => $cfg['label'],
                    'mode'   => 'single',
                    'items'  => $record ? [['record' => $record, 'fields' => $record->getTranslatableFields()]] : [],
                ];
            } else {
                $records = $modelClass::orderBy('sort_order')->get();
                $items = [];
                foreach ($records as $r) {
                    $items[] = ['record' => $r, 'fields' => $r->getTranslatableFields()];
                }
                $sectionData[$key] = [
                    'label' => $cfg['label'],
                    'mode'  => 'multi',
                    'items' => $items,
                ];
            }
        }

        return view('central.super.cms.translations', compact('languages', 'locale', 'sectionData'));
    }

    /**
     * Save translations for a given locale.
     */
    public function update(Request $request): RedirectResponse
    {
        $locale = $request->input('locale');
        $translations = $request->input('translations', []);

        foreach ($translations as $sectionKey => $items) {
            $cfg = $this->sections[$sectionKey] ?? null;
            if (! $cfg) continue;

            $modelClass = $cfg['model'];

            foreach ($items as $recordId => $fields) {
                $record = $modelClass::find($recordId);
                if (! $record) continue;

                foreach ($fields as $field => $value) {
                    if (in_array($field, $record->getTranslatableFields(), true)) {
                        $record->setTranslation($field, $locale, $value);
                    }
                }
                $record->save();
            }
        }

        return redirect()
            ->route('super.cms.translations', ['locale' => $locale])
            ->with('success', __('super.cms_translations.saved', ['locale' => $locale]));
    }
}
