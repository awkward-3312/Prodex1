<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralLanguage;
use App\Models\Central\GeneralSetting;
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
use App\Services\LandingCmsService;
use App\Support\LandingPageTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingCmsController extends Controller
{
    private function storeImage(Request $request, string $field, string $folder = 'super'): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }
        $file = $request->file($field);
        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $uploadDir = public_path('images/' . $folder);
        $file->move($uploadDir, $filename);

        return 'images/' . $folder . '/' . $filename;
    }

    /** Move an already-resolved uploaded file into public/images/{folder} and return its relative path. */
    private function moveUpload(\Illuminate\Http\UploadedFile $file, string $folder = 'super'): string
    {
        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $file->move(public_path('images/' . $folder), $filename);

        return 'images/' . $folder . '/' . $filename;
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            $fullPath = public_path($path);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    private function clearLandingCache(): void
    {
        // No-op: LandingCmsService does not use caching yet.
    }

    public function index(): View
    {
        return view('central.super.cms.index');
    }

    // ── Hero ─────────────────────────────────────────────────────────────
    public function hero(): View
    {
        $hero = LandingHero::firstOrCreate([], [
            'title' => 'Run your business smarter with Stocky',
            'subtitle' => 'Inventory & POS in one platform',
            'description' => 'Manage inventory, point of sale, orders, and reports from one dashboard.',
            'primary_button_text' => 'Start Free Trial',
            'primary_button_url' => route('central.register'),
            'secondary_button_text' => 'Sign Up',
            'secondary_button_url' => route('central.register'),
            'is_active' => true,
        ]);

        $languages = CentralLanguage::active();
        $baseLocale = config('app.fallback_locale', 'en');

        return view('central.super.cms.hero', compact('hero', 'languages', 'baseLocale'));
    }

    public function heroUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'primary_button_text' => ['nullable', 'string', 'max:100'],
            'primary_button_url' => ['nullable', 'string', 'max:500'],
            'secondary_button_text' => ['nullable', 'string', 'max:100'],
            'secondary_button_url' => ['nullable', 'string', 'max:500'],
            'hero_image' => ['nullable', 'array'],
            'hero_image.*' => ['nullable', 'image', 'max:2048'],
            'background_image' => ['nullable', 'array'],
            'background_image.*' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
        ]);

        $hero = LandingHero::firstOrCreate([]);

        // Per-language hero / background images. The base columns hold the
        // default-language image; other locales are stored in the translations JSON.
        $baseLocale = config('app.fallback_locale', 'en');
        foreach (CentralLanguage::active() as $lang) {
            $this->syncHeroImage($request, $hero, 'hero_image', $lang->locale, $baseLocale);
            $this->syncHeroImage($request, $hero, 'background_image', $lang->locale, $baseLocale);
        }

        // Text fields hold the default-language content (per-language text is
        // managed in the Translations screen).
        $hero->fill([
            'title'                 => $validated['title'],
            'subtitle'              => $validated['subtitle'] ?? null,
            'description'           => $validated['description'] ?? null,
            'primary_button_text'   => $validated['primary_button_text'] ?? null,
            'primary_button_url'    => $validated['primary_button_url'] ?? null,
            'secondary_button_text' => $validated['secondary_button_text'] ?? null,
            'secondary_button_url'  => $validated['secondary_button_url'] ?? null,
        ]);
        $hero->is_active = $request->boolean('is_active');
        $hero->save();
        $this->clearLandingCache();

        return redirect()->route('super.cms.hero')->with('success', 'Hero section updated successfully.');
    }

    /**
     * Upload / remove an image field for a single locale.
     * Default locale → base column; other locales → translations JSON.
     */
    private function syncHeroImage(Request $request, LandingHero $hero, string $field, string $locale, string $baseLocale): void
    {
        $remove  = (bool) $request->input("remove_{$field}.{$locale}");
        $hasFile = $request->hasFile("{$field}.{$locale}");

        if (! $remove && ! $hasFile) {
            return; // nothing changed for this locale
        }

        // Delete the file currently owned by this locale (no base fallback).
        $this->deleteImage($hero->ownTranslation($field, $locale));

        $newPath = ($hasFile && ! $remove)
            ? $this->moveUpload($request->file("{$field}.{$locale}"))
            : null;

        if ($locale === $baseLocale) {
            $hero->setAttribute($field, $newPath);
        } else {
            $hero->setTranslation($field, $locale, $newPath);
        }
    }

    // ── Features ─────────────────────────────────────────────────────────
    public function features(): View
    {
        $section = LandingFeaturesSection::firstOrCreate([], [
            'section_title' => 'Everything you need to grow',
            'section_subtitle' => 'One platform for inventory, sales, and insights.',
            'is_active' => true,
        ]);
        $features = LandingFeature::orderBy('sort_order')->orderBy('id')->get();

        return view('central.super.cms.features.index', compact('section', 'features'));
    }

    public function featuresSectionUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'section_title' => ['nullable', 'string', 'max:255'],
            'section_subtitle' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        LandingFeaturesSection::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.features')->with('success', 'Features section updated successfully.');
    }

    public function featureCreate(): View
    {
        return view('central.super.cms.features.create', ['feature' => null]);
    }

    public function featureStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? LandingFeature::max('sort_order') + 1);
        $validated['is_active'] = $request->boolean('is_active', true);
        if ($request->hasFile('image')) {
            $validated['image'] = $this->storeImage($request, 'image');
        }

        LandingFeature::create($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.features')->with('success', 'Feature added successfully.');
    }

    public function featureEdit(LandingFeature $feature): View
    {
        return view('central.super.cms.features.edit', compact('feature'));
    }

    public function featureUpdate(Request $request, LandingFeature $feature): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');
        if ($request->hasFile('image')) {
            $this->deleteImage($feature->image);
            $validated['image'] = $this->storeImage($request, 'image');
        }

        $feature->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.features')->with('success', 'Feature updated successfully.');
    }

    public function featureDestroy(LandingFeature $feature): RedirectResponse
    {
        $this->deleteImage($feature->image);
        $feature->delete();
        $this->clearLandingCache();

        return redirect()->route('super.cms.features')->with('success', 'Feature deleted successfully.');
    }

    public function featureReorder(Request $request): RedirectResponse
    {
        $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer', 'exists:landing_features,id']]);
        foreach ($request->order as $i => $id) {
            LandingFeature::where('id', $id)->update(['sort_order' => $i]);
        }

        $this->clearLandingCache();

        return redirect()->route('super.cms.features')->with('success', 'Order updated successfully.');
    }

    // ── Pricing ────────────────────────────────────────────────────────────
    public function pricing(): View
    {
        $pricing = LandingPricing::firstOrCreate([], [
            'section_title' => 'Simple, transparent pricing',
            'section_subtitle' => 'Start free. Scale when you\'re ready.',
            'show_monthly_pricing' => true,
            'show_yearly_pricing' => false,
            'load_plans_from_database' => true,
            'is_active' => true,
        ]);

        return view('central.super.cms.pricing', compact('pricing'));
    }

    public function pricingUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'section_title' => ['nullable', 'string', 'max:255'],
            'section_subtitle' => ['nullable', 'string'],
            'show_monthly_pricing' => ['boolean'],
            'show_yearly_pricing' => ['boolean'],
            'load_plans_from_database' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
        $validated['show_monthly_pricing'] = $request->boolean('show_monthly_pricing', true);
        $validated['show_yearly_pricing'] = $request->boolean('show_yearly_pricing');
        $validated['load_plans_from_database'] = $request->boolean('load_plans_from_database', true);
        $validated['is_active'] = $request->boolean('is_active');

        LandingPricing::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.pricing')->with('success', 'Pricing section updated successfully.');
    }

    // ── Testimonials ──────────────────────────────────────────────────────
    public function testimonials(): View
    {
        $testimonials = LandingTestimonial::orderBy('sort_order')->orderBy('id')->get();

        return view('central.super.cms.testimonials.index', compact('testimonials'));
    }

    public function testimonialCreate(): View
    {
        return view('central.super.cms.testimonials.create', ['testimonial' => null]);
    }

    public function testimonialStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'review' => ['required', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? LandingTestimonial::max('sort_order') + 1);
        $validated['is_active'] = $request->boolean('is_active', true);
        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $this->storeImage($request, 'avatar');
        }

        LandingTestimonial::create($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.testimonials')->with('success', 'Testimonial added successfully.');
    }

    public function testimonialEdit(LandingTestimonial $testimonial): View
    {
        return view('central.super.cms.testimonials.edit', compact('testimonial'));
    }

    public function testimonialUpdate(Request $request, LandingTestimonial $testimonial): RedirectResponse
    {
        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'review' => ['required', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');
        if ($request->hasFile('avatar')) {
            $this->deleteImage($testimonial->avatar);
            $validated['avatar'] = $this->storeImage($request, 'avatar');
        }

        $testimonial->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.testimonials')->with('success', 'Testimonial updated successfully.');
    }

    public function testimonialDestroy(LandingTestimonial $testimonial): RedirectResponse
    {
        $this->deleteImage($testimonial->avatar);
        $testimonial->delete();
        $this->clearLandingCache();

        return redirect()->route('super.cms.testimonials')->with('success', 'Testimonial deleted successfully.');
    }

    // ── FAQ ───────────────────────────────────────────────────────────────
    public function faqs(): View
    {
        $faqs = LandingFaq::orderBy('sort_order')->orderBy('id')->get();

        return view('central.super.cms.faq.index', compact('faqs'));
    }

    public function faqCreate(): View
    {
        return view('central.super.cms.faq.create', ['faq' => null]);
    }

    public function faqStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'answer' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? LandingFaq::max('sort_order') + 1);
        $validated['is_active'] = $request->boolean('is_active', true);

        LandingFaq::create($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.faqs')->with('success', 'FAQ added successfully.');
    }

    public function faqEdit(LandingFaq $faq): View
    {
        return view('central.super.cms.faq.edit', compact('faq'));
    }

    public function faqUpdate(Request $request, LandingFaq $faq): RedirectResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string'],
            'answer' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $faq->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.faqs')->with('success', 'FAQ updated successfully.');
    }

    public function faqDestroy(LandingFaq $faq): RedirectResponse
    {
        $faq->delete();
        $this->clearLandingCache();

        return redirect()->route('super.cms.faqs')->with('success', 'FAQ deleted successfully.');
    }

    // ── Stats (Trust Bar) ──────────────────────────────────────────────────
    public function stats(): View
    {
        $stats = LandingStat::orderBy('sort_order')->orderBy('id')->get();

        return view('central.super.cms.stats.index', compact('stats'));
    }

    public function statCreate(): View
    {
        return view('central.super.cms.stats.create', ['stat' => null]);
    }

    public function statStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? LandingStat::max('sort_order') + 1);
        $validated['is_active'] = $request->boolean('is_active', true);

        LandingStat::create($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.stats')->with('success', 'Stat added successfully.');
    }

    public function statEdit(LandingStat $stat): View
    {
        return view('central.super.cms.stats.edit', compact('stat'));
    }

    public function statUpdate(Request $request, LandingStat $stat): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $stat->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.stats')->with('success', 'Stat updated successfully.');
    }

    public function statDestroy(LandingStat $stat): RedirectResponse
    {
        $stat->delete();
        $this->clearLandingCache();

        return redirect()->route('super.cms.stats')->with('success', 'Stat deleted successfully.');
    }

    // ── CTA ────────────────────────────────────────────────────────────────
    public function cta(): View
    {
        $cta = LandingCta::firstOrCreate([], [
            'title' => 'Ready to get started?',
            'subtitle' => 'Create your workspace in under a minute. No credit card required.',
            'button_text' => 'Create your workspace',
            'button_url' => route('central.register'),
            'is_active' => true,
        ]);

        return view('central.super.cms.cta', compact('cta'));
    }

    public function ctaUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string'],
            'button_text' => ['nullable', 'string', 'max:100'],
            'button_url' => ['nullable', 'string', 'max:500'],
            'background_image' => ['nullable', 'image', 'max:2048'],
            'is_active' => ['boolean'],
        ]);
        $cta = LandingCta::firstOrCreate([]);
        if ($request->hasFile('background_image')) {
            $this->deleteImage($cta->background_image);
            $validated['background_image'] = $this->storeImage($request, 'background_image');
        }
        $validated['is_active'] = $request->boolean('is_active');

        $cta->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.cta')->with('success', 'CTA section updated successfully.');
    }

    // ── Footer ─────────────────────────────────────────────────────────────
    public function footer(): View
    {
        $footer = LandingFooter::firstOrCreate([], [
            'footer_about' => 'Inventory, POS & more for modern businesses.',
            'copyright_text' => '© ' . date('Y') . ' Stocky. All rights reserved.',
        ]);

        return view('central.super.cms.footer', compact('footer'));
    }

    public function footerUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'footer_about' => ['nullable', 'string'],
            'copyright_text' => ['nullable', 'string', 'max:500'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'facebook' => ['nullable', 'string', 'max:500'],
            'twitter' => ['nullable', 'string', 'max:500'],
            'linkedin' => ['nullable', 'string', 'max:500'],
            'instagram' => ['nullable', 'string', 'max:500'],
            'youtube' => ['nullable', 'string', 'max:500'],
        ]);

        LandingFooter::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.footer')->with('success', 'Footer updated successfully.');
    }

    // ── SEO ───────────────────────────────────────────────────────────────
    public function seo(): View
    {
        $seo = LandingSeo::firstOrCreate([], [
            'meta_title' => 'Stocky SaaS – Inventory & POS for Modern Businesses',
            'meta_description' => 'Manage inventory, point of sale, orders, and reports from one dashboard. Start your free trial today.',
        ]);

        return view('central.super.cms.seo', compact('seo'));
    }

    public function seoUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],
            'og_image' => ['nullable', 'image', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,jpg,jpeg,svg,webp', 'max:512'],
        ]);
        $seo = LandingSeo::firstOrCreate([]);
        if ($request->hasFile('og_image')) {
            $this->deleteImage($seo->og_image);
            $validated['og_image'] = $this->storeImage($request, 'og_image');
        }
        if ($request->hasFile('favicon')) {
            $this->deleteImage($seo->favicon);
            $validated['favicon'] = $this->storeImage($request, 'favicon');
        }

        $seo->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.seo')->with('success', 'SEO settings updated successfully.');
    }

    // ── Privacy Policy ────────────────────────────────────────────────────

    public function privacyPolicy(): View
    {
        $privacy = LandingPrivacyPolicy::firstOrCreate([], [
            'introduction'    => __('landing.privacy_intro_text'),
            'data_collection' => __('landing.privacy_collect_text'),
            'data_usage'      => __('landing.privacy_use_text'),
            'cookies_usage'   => __('landing.privacy_cookies_text'),
            'third_party'     => __('landing.privacy_third_party_text'),
            'data_protection' => __('landing.privacy_protection_text'),
            'user_rights'     => __('landing.privacy_rights_text'),
            'contact_info'    => __('landing.privacy_contact_text'),
            'last_updated'    => now()->toDateString(),
            'is_active'       => true,
        ]);

        return view('central.super.cms.privacy-policy', compact('privacy'));
    }

    public function privacyPolicyUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'introduction'    => ['nullable', 'string'],
            'data_collection' => ['nullable', 'string'],
            'data_usage'      => ['nullable', 'string'],
            'cookies_usage'   => ['nullable', 'string'],
            'third_party'     => ['nullable', 'string'],
            'data_protection' => ['nullable', 'string'],
            'user_rights'     => ['nullable', 'string'],
            'contact_info'    => ['nullable', 'string'],
            'last_updated'    => ['nullable', 'date'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        LandingPrivacyPolicy::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.privacy-policy')->with('success', __('super.privacy.updated'));
    }

    // ── Terms & Conditions ────────────────────────────────────────────────

    public function termsConditions(): View
    {
        $terms = LandingTermsConditions::firstOrCreate([], [
            'acceptance'            => __('landing.terms_acceptance_text'),
            'use_license'           => __('landing.terms_license_text'),
            'user_accounts'         => __('landing.terms_accounts_text'),
            'payments'              => __('landing.terms_payments_text'),
            'prohibited'            => __('landing.terms_prohibited_text'),
            'intellectual_property' => __('landing.terms_ip_text'),
            'liability'             => __('landing.terms_liability_text'),
            'governing_law'         => __('landing.terms_law_text'),
            'last_updated'          => now()->toDateString(),
            'is_active'             => true,
        ]);

        return view('central.super.cms.terms-conditions', compact('terms'));
    }

    public function termsConditionsUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'acceptance'            => ['nullable', 'string'],
            'use_license'           => ['nullable', 'string'],
            'user_accounts'         => ['nullable', 'string'],
            'payments'              => ['nullable', 'string'],
            'prohibited'            => ['nullable', 'string'],
            'intellectual_property' => ['nullable', 'string'],
            'liability'             => ['nullable', 'string'],
            'governing_law'         => ['nullable', 'string'],
            'last_updated'          => ['nullable', 'date'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        LandingTermsConditions::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.terms-conditions')->with('success', __('super.terms.updated'));
    }

    // ── How It Works ──────────────────────────────────────────────────────

    public function howItWorks(): View
    {
        $section = LandingHowItWorksSection::firstOrCreate([], [
            'section_label' => 'How It Works',
            'section_title' => 'Transparent & fair for every customer',
            'section_subtitle' => 'Here is how plans, pricing, and your data are handled — no surprises.',
            'is_active' => true,
        ]);
        $steps = LandingHowItWorksStep::orderBy('sort_order')->orderBy('id')->get();

        return view('central.super.cms.how-it-works.index', compact('section', 'steps'));
    }

    public function howItWorksSectionUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'section_label'    => ['nullable', 'string', 'max:255'],
            'section_title'    => ['nullable', 'string', 'max:255'],
            'section_subtitle' => ['nullable', 'string'],
            'is_active'        => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        LandingHowItWorksSection::firstOrCreate([])->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.how-it-works')->with('success', __('super.how_it_works.section_updated'));
    }

    public function howItWorksStepCreate(): View
    {
        return view('central.super.cms.how-it-works.create', ['step' => null]);
    }

    public function howItWorksStepStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:100'],
            'icon_color'  => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? LandingHowItWorksStep::max('sort_order') + 1);
        $validated['is_active'] = $request->boolean('is_active', true);

        LandingHowItWorksStep::create($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.how-it-works')->with('success', __('super.how_it_works.step_added'));
    }

    public function howItWorksStepEdit(LandingHowItWorksStep $step): View
    {
        return view('central.super.cms.how-it-works.edit', compact('step'));
    }

    public function howItWorksStepUpdate(Request $request, LandingHowItWorksStep $step): RedirectResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:100'],
            'icon_color'  => ['nullable', 'string', 'max:50'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'is_active'   => ['boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $step->update($validated);
        $this->clearLandingCache();

        return redirect()->route('super.cms.how-it-works')->with('success', __('super.how_it_works.step_updated'));
    }

    public function howItWorksStepDestroy(LandingHowItWorksStep $step): RedirectResponse
    {
        $step->delete();
        $this->clearLandingCache();

        return redirect()->route('super.cms.how-it-works')->with('success', __('super.how_it_works.step_deleted'));
    }

    public function landingPages(): View
    {
        return view('central.super.cms.landing-pages', [
            'current' => GeneralSetting::resolvedLandingTemplateKey(),
            'options' => LandingPageTemplate::options(),
        ]);
    }

    public function landingPagesUpdate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'landing_template' => ['required', 'string', 'in:'.implode(',', LandingPageTemplate::keys())],
        ]);

        GeneralSetting::instance()->update([
            'landing_template' => $validated['landing_template'],
        ]);

        return redirect()
            ->route('super.cms.landing-pages')
            ->with('success', __('super.landing_templates.saved'));
    }
}
