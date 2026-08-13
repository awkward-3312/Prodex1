@extends('central.super.layout')

@section('title', __('super.hiw.title'))

@section('content')
<div class="page-header">
    <h1><i class="bi bi-diagram-3 me-2"></i>{{ __('super.hiw.title') }}</h1>
    <p class="page-subtitle">{{ __('super.hiw.subtitle') }}</p>
</div>

{{-- Current mode indicator --}}
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="content-card h-100 {{ $hostingMode === 'vps' ? 'hiw-mode-active' : '' }}">
            <div class="card-body-custom text-center py-4">
                <div class="hiw-mode-icon mb-3" style="background: {{ $hostingMode === 'vps' ? '#ecfdf5' : '#f8fafc' }}; color: {{ $hostingMode === 'vps' ? '#10b981' : '#94a3b8' }};">
                    <i class="bi bi-hdd-rack"></i>
                </div>
                <h3 class="hiw-mode-title">{{ __('super.hiw.vps_title') }}</h3>
                <p class="text-muted mb-2 fs-sm2">{{ __('super.hiw.vps_desc') }}</p>
                @if($hostingMode === 'vps')
                    <span class="badge hiw-badge-success">
                        <i class="bi bi-check-circle-fill me-1"></i>{{ __('super.hiw.current_mode') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="content-card h-100 {{ $hostingMode === 'shared' ? 'hiw-mode-active' : '' }}">
            <div class="card-body-custom text-center py-4">
                <div class="hiw-mode-icon mb-3" style="background: {{ $hostingMode === 'shared' ? '#eff6ff' : '#f8fafc' }}; color: {{ $hostingMode === 'shared' ? '#3b82f6' : '#94a3b8' }};">
                    <i class="bi bi-globe2"></i>
                </div>
                <h3 class="hiw-mode-title">{{ __('super.hiw.shared_title') }}</h3>
                <p class="text-muted mb-2 fs-sm2">{{ __('super.hiw.shared_desc') }}</p>
                @if($hostingMode === 'shared')
                    <span class="badge hiw-badge-info">
                        <i class="bi bi-check-circle-fill me-1"></i>{{ __('super.hiw.current_mode') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- VPS MODE FLOW --}}
{{-- ================================================================ --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-hdd-rack me-2 text-muted"></i>{{ __('super.hiw.vps_section_title') }}</h2>
        @if($hostingMode === 'vps')
            <span class="badge hiw-badge-success-sm">{{ __('super.hiw.active') }}</span>
        @endif
    </div>
    <div class="card-body-custom">
        <p class="text-muted mb-4 fs-sm4">
            {{ __('super.hiw.vps_section_desc') }}
        </p>

        {{-- Free/Trial flow --}}
        <h5 class="hiw-flow-title"><i class="bi bi-gift me-2"></i>{{ __('super.hiw.free_trial_flow') }}</h5>
        <div class="hiw-timeline mb-4">
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-1-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_free_step1_title') }}</h6>
                    <p>{{ __('super.hiw.vps_free_step1_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-2-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_free_step2_title') }}</h6>
                    <p>{!! __('super.hiw.vps_free_step2_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-3-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_free_step3_title') }}</h6>
                    <p>{!! __('super.hiw.vps_free_step3_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-4-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_free_step4_title') }}</h6>
                    <p>{!! __('super.hiw.vps_free_step4_desc') !!}</p>
                </div>
            </div>
        </div>

        {{-- Paid flow --}}
        <h5 class="hiw-flow-title"><i class="bi bi-credit-card me-2"></i>{{ __('super.hiw.paid_flow') }}</h5>
        <div class="hiw-timeline">
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-1-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_paid_step1_title') }}</h6>
                    <p>{{ __('super.hiw.vps_paid_step1_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-2-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_paid_step2_title') }}</h6>
                    <p>{{ __('super.hiw.vps_paid_step2_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-3-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_paid_step3_title') }}</h6>
                    <p>{{ __('super.hiw.vps_paid_step3_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-4-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.vps_paid_step4_title') }}</h6>
                    <p>{!! __('super.hiw.vps_paid_step4_desc') !!}</p>
                </div>
            </div>
        </div>

        <div class="hiw-note hiw-note-info mt-3">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <strong>{{ __('super.hiw.vps_admin_note_label') }}</strong> {{ __('super.hiw.vps_admin_note') }}
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- SHARED HOSTING FLOW --}}
{{-- ================================================================ --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-globe2 me-2 text-muted"></i>{{ __('super.hiw.shared_section_title') }}</h2>
        @if($hostingMode === 'shared')
            <span class="badge hiw-badge-info-sm">{{ __('super.hiw.active') }}</span>
        @endif
    </div>
    <div class="card-body-custom">
        <p class="text-muted mb-4 fs-sm4">
            {{ __('super.hiw.shared_section_desc') }}
        </p>

        {{-- Free/Trial flow --}}
        <h5 class="hiw-flow-title"><i class="bi bi-gift me-2"></i>{{ __('super.hiw.free_trial_flow') }}</h5>
        <div class="hiw-timeline mb-4">
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-1-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step1_title') }}</h6>
                    <p>{{ __('super.hiw.sh_free_step1_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-2-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step2_title') }}</h6>
                    <p>{!! __('super.hiw.sh_free_step2_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-notify">
                <div class="hiw-step-dot"><i class="bi bi-3-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step3_title') }}</h6>
                    <p>{{ __('super.hiw.sh_free_step3_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-user">
                <div class="hiw-step-dot"><i class="bi bi-4-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step4_title') }}</h6>
                    <p>{{ __('super.hiw.sh_free_step4_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-manual">
                <div class="hiw-step-dot"><i class="bi bi-5-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step5_title') }}</h6>
                    <p>{!! __('super.hiw.sh_free_step5_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-manual">
                <div class="hiw-step-dot"><i class="bi bi-6-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step6_title') }}</h6>
                    <p>{!! __('super.hiw.sh_free_step6_desc', ['tenants_url' => route('super.tenants.index'), 'dashboard_url' => route('super.dashboard')]) !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-manual">
                <div class="hiw-step-dot"><i class="bi bi-7-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step7_title') }}</h6>
                    <p>{{ __('super.hiw.sh_free_step7_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-8-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_free_step8_title') }}</h6>
                    <p>{!! __('super.hiw.sh_free_step8_desc') !!}</p>
                </div>
            </div>
        </div>

        {{-- Paid flow --}}
        <h5 class="hiw-flow-title"><i class="bi bi-credit-card me-2"></i>{{ __('super.hiw.paid_flow') }}</h5>
        <div class="hiw-timeline mb-4">
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-1-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step1_title') }}</h6>
                    <p>{{ __('super.hiw.sh_paid_step1_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-2-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step2_title') }}</h6>
                    <p>{!! __('super.hiw.sh_paid_step2_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-3-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step3_title') }}</h6>
                    <p>{!! __('super.hiw.sh_paid_step3_desc') !!}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-user">
                <div class="hiw-step-dot"><i class="bi bi-4-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step4_title') }}</h6>
                    <p>{{ __('super.hiw.sh_paid_step4_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-manual">
                <div class="hiw-step-dot"><i class="bi bi-5-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step5_title') }}</h6>
                    <p>{{ __('super.hiw.sh_paid_step5_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-manual">
                <div class="hiw-step-dot"><i class="bi bi-6-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step6_title') }}</h6>
                    <p>{{ __('super.hiw.sh_paid_step6_desc') }}</p>
                </div>
            </div>
            <div class="hiw-step hiw-step-auto">
                <div class="hiw-step-dot"><i class="bi bi-7-circle-fill"></i></div>
                <div class="hiw-step-content">
                    <h6>{{ __('super.hiw.sh_paid_step7_title') }}</h6>
                    <p>{{ __('super.hiw.sh_paid_step7_desc') }}</p>
                </div>
            </div>
        </div>

        <div class="hiw-note hiw-note-warning mt-3">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <strong>{{ __('super.hiw.shared_note_label') }}</strong> {!! __('super.hiw.shared_note') !!}
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- DATABASE SECURITY --}}
{{-- ================================================================ --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-shield-lock me-2 text-muted"></i>{{ __('super.hiw.db_security_title') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="hiw-security-item">
                    <div class="hiw-security-icon icon-amber"><i class="bi bi-key-fill"></i></div>
                    <div>
                        <h6>{{ __('super.hiw.db_sec_encrypt_title') }}</h6>
                        <p>{{ __('super.hiw.db_sec_encrypt_desc') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="hiw-security-item">
                    <div class="hiw-security-icon icon-purple"><i class="bi bi-database-lock"></i></div>
                    <div>
                        <h6>{{ __('super.hiw.db_sec_isolate_title') }}</h6>
                        <p>{{ __('super.hiw.db_sec_isolate_desc') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="hiw-security-item">
                    <div class="hiw-security-icon icon-green"><i class="bi bi-plug-fill"></i></div>
                    <div>
                        <h6>{{ __('super.hiw.db_sec_test_title') }}</h6>
                        <p>{{ __('super.hiw.db_sec_test_desc') }}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="hiw-security-item">
                    <div class="hiw-security-icon icon-red"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <h6>{{ __('super.hiw.db_sec_migrate_title') }}</h6>
                        <p>{{ __('super.hiw.db_sec_migrate_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- TENANT LIFECYCLE --}}
{{-- ================================================================ --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-arrow-left-right me-2 text-muted"></i>{{ __('super.hiw.lifecycle_title') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="hiw-lifecycle">
            <div class="hiw-lifecycle-item">
                <span class="badge badge-pending hiw-lifecycle-badge">{{ __('super.hiw.status_pending') }}</span>
                <span class="hiw-lifecycle-arrow"><i class="bi bi-arrow-right"></i></span>
                <span class="badge badge-provisioning hiw-lifecycle-badge">{{ __('super.hiw.status_provisioning') }}</span>
                <span class="hiw-lifecycle-arrow"><i class="bi bi-arrow-right"></i></span>
                <span class="badge badge-active hiw-lifecycle-badge">{{ __('super.hiw.status_active') }}</span>
            </div>
        </div>
        <div class="row g-3 mt-3">
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-pending mb-2">{{ __('super.hiw.status_pending') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_pending_desc') }}</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-provisioning mb-2">{{ __('super.hiw.status_provisioning') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_provisioning_desc') }}</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-active mb-2">{{ __('super.hiw.status_active') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_active_desc') }}</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-suspended mb-2">{{ __('super.hiw.status_suspended') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_suspended_desc') }}</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-cancelled mb-2">{{ __('super.hiw.status_cancelled') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_cancelled_desc') }}</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded-3 hiw-status-card">
                    <span class="badge badge-failed">{{ __('super.hiw.status_failed') }}</span>
                    <p class="mb-0 fs-sm2 text-slate">{{ __('super.hiw.status_failed_desc') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- FREQUENTLY ASKED QUESTIONS --}}
{{-- ================================================================ --}}
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-question-circle me-2 text-muted"></i>{{ __('super.hiw.faq_title') }}</h2>
    </div>
    <div class="card-body-custom">
        <p class="text-muted mb-4 fs-sm4">
            {{ __('super.hiw.faq_subtitle') }}
        </p>

        <div class="hiw-faq-list">

            {{-- Q1 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-puzzle"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q1') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a1') !!}</p>
                </div>
            </div>

            {{-- Q2 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-cash-stack"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q2') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a2') !!}</p>
                </div>
            </div>

            {{-- Q3 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-trash3"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q3') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a3') !!}</p>
                    <div class="hiw-note hiw-note-info mt-2">
                        <i class="bi bi-lightbulb-fill"></i>
                        <div><strong>{{ __('super.hiw.tip') }}</strong> {!! __('super.hiw.faq_a3_tip') !!}</div>
                    </div>
                </div>
            </div>

            {{-- Q4 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-arrow-up-circle"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q4') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a4_p1') !!}</p>
                    <p>{!! __('super.hiw.faq_a4_p2') !!}</p>
                </div>
            </div>

            {{-- Q5 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q5') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{{ __('super.hiw.faq_a5_intro') }}</p>
                    <ul>
                        <li>{!! __('super.hiw.faq_a5_li1') !!}</li>
                        <li>{!! __('super.hiw.faq_a5_li2') !!}</li>
                        <li>{!! __('super.hiw.faq_a5_li3') !!}</li>
                        <li>{!! __('super.hiw.faq_a5_li4') !!}</li>
                    </ul>
                </div>
            </div>

            {{-- Q6 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q6') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{{ __('super.hiw.faq_a6_intro') }}</p>
                    <ul>
                        <li>{!! __('super.hiw.faq_a6_li1') !!}</li>
                        <li>{!! __('super.hiw.faq_a6_li2') !!}</li>
                        <li>{!! __('super.hiw.faq_a6_li3') !!}</li>
                    </ul>
                    <p>{{ __('super.hiw.faq_a6_outro') }}</p>
                </div>
            </div>

            {{-- Q7 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-pause-circle"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q7') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a7_p1') !!}</p>
                    <p>{{ __('super.hiw.faq_a7_p2') }}</p>
                </div>
            </div>

            {{-- Q8 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-hourglass-split"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q8') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{{ __('super.hiw.faq_a8_intro') }}</p>
                    <ul>
                        <li>{!! __('super.hiw.faq_a8_li1') !!}</li>
                        <li>{!! __('super.hiw.faq_a8_li2') !!}</li>
                        <li>{!! __('super.hiw.faq_a8_li3') !!}</li>
                        <li>{!! __('super.hiw.faq_a8_li4') !!}</li>
                    </ul>
                </div>
            </div>

            {{-- Q9 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-x-circle"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q9') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{{ __('super.hiw.faq_a9_intro') }}</p>
                    <ul>
                        <li>{!! __('super.hiw.faq_a9_li1') !!}</li>
                        <li>{!! __('super.hiw.faq_a9_li2') !!}</li>
                        <li>{!! __('super.hiw.faq_a9_li3') !!}</li>
                    </ul>
                </div>
            </div>

            {{-- Q10 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-person-slash"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q10') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{{ __('super.hiw.faq_a10_intro') }}</p>
                    <ul>
                        <li>{!! __('super.hiw.faq_a10_li1') !!}</li>
                        <li>{!! __('super.hiw.faq_a10_li2') !!}</li>
                        <li>{!! __('super.hiw.faq_a10_li3') !!}</li>
                    </ul>
                    <p>{!! __('super.hiw.faq_a10_outro') !!}</p>
                </div>
            </div>

            {{-- Q11 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-arrow-down-up"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q11') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a11') !!}</p>
                </div>
            </div>

            {{-- Q12 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-database"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q12') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a12') !!}</p>
                </div>
            </div>

            {{-- Q13 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-calendar-check"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q13') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a13') !!}</p>
                </div>
            </div>

            {{-- Q14 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-eye-slash"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q14') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a14') !!}</p>
                </div>
            </div>

            {{-- Q15 --}}
            <div class="hiw-faq-item">
                <button class="hiw-faq-toggle" type="button">
                    <span class="hiw-faq-icon"><i class="bi bi-credit-card-2-front"></i></span>
                    <span class="hiw-faq-question">{{ __('super.hiw.faq_q15') }}</span>
                    <i class="bi bi-chevron-down hiw-faq-chevron"></i>
                </button>
                <div class="hiw-faq-answer">
                    <p>{!! __('super.hiw.faq_a15') !!}</p>
                </div>
            </div>

        </div>
    </div>
</div>

{{-- Quick links --}}
<div class="content-card">
    <div class="card-header-custom">
        <h2><i class="bi bi-lightning-charge me-2 text-muted"></i>{{ __('super.hiw.quick_actions') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="d-flex flex-wrap gap-3">
            <a href="{{ route('super.tenants.index', ['status' => 'pending']) }}" class="hiw-quick-link">
                <i class="bi bi-hourglass-split"></i>
                <span>{{ __('super.hiw.quick_pending') }}</span>
            </a>
            <a href="{{ route('super.settings.general') }}" class="hiw-quick-link">
                <i class="bi bi-sliders"></i>
                <span>{{ __('super.hiw.quick_hosting') }}</span>
            </a>
            <a href="{{ route('super.tenants.index') }}" class="hiw-quick-link">
                <i class="bi bi-building"></i>
                <span>{{ __('super.hiw.quick_tenants') }}</span>
            </a>
            <a href="{{ route('super.email-templates.index') }}" class="hiw-quick-link">
                <i class="bi bi-envelope-paper"></i>
                <span>{{ __('super.hiw.quick_emails') }}</span>
            </a>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('assets_super/js/how-it-works.js') }}"></script>
@endpush

@endsection
