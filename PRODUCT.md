# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Primary: the tenant business owner / administrator.** The owner or store manager of a
small-to-mid-sized business who runs the operation day to day from a desk or laptop —
configuring the business, watching dashboards, and working through purchasing, inventory,
sales, accounting, HR and reports. The redesign is anchored on this person; every other
role follows from that anchor.

Secondary roles that share the same product:

- **POS cashiers and floor staff** — front-line users on the POS and operational screens
  during a shift, who need speed, keyboard operation, and offline resilience.
- **Warehouse / branch operations staff** — receiving, transfers between locations,
  adjustments, damages, quarantine.
- **Accountants / bookkeepers** — accounting v2, fiscal invoicing, reconciliation, reports.
- **Platform operators (Super Admin)** — the team running the PRODEX platform itself:
  tenant provisioning, plans, billing, default branding, reserved subdomains, system health.
  They sell the tenant app; they do not live in it.
- **Clients of a tenant** — limited self-service portal to view invoices and payments and
  download PDFs.

## Product Purpose

PRODEX is a **Central America–native business operating system**: a Spanish-first ERP + POS
SaaS that gives small and mid-sized businesses one platform for multi-branch inventory and
operations, point of sale, purchasing, sales, accounting, HR, and reporting — plus local
fiscal and compliance adaptations by country.

It exists because generic US/EU-centric SaaS forces Central American businesses into a model
that does not match their operational reality (multi-branch, multi-warehouse, cash-heavy POS,
local tax authorities) or their regulatory obligations. Success is a business that can run
its whole operation — and meet its country's fiscal requirements — without leaving the
product or bolting on a separate compliance tool.

Multi-tenant SaaS: a central marketing/onboarding domain plus per-tenant workspaces on
subdomains or connected custom domains.

## Positioning

The defensible combination a neighboring product (plain Stocky, a generic POS SaaS, a
US/EU all-in-one) cannot truthfully claim:

- **Serious multi-branch inventory and operations** — an explicit model that separates
  branch (`branches`), warehouse / distribution center (`warehouses`), inventory location
  (`inventory_locations`: sales floor, internal stockroom, quarantine), and stock quantity;
  quarantine is never sellable; only branch locations can be default-sales. Transfers carry
  logistics, idempotency, and per-location overrides.
- **Local fiscal / compliance adaptations by country, as modular capabilities** — not the
  product's identity. Honduras SAR fiscal invoicing is the module implemented today
  (`app/Services/Sar*`, `app/Models/Sar*`): CAI, authorized ranges, correlativos, points of
  issue, immutable per-invoice snapshots of issuer/customer/lines/taxes/totals, and
  anulación. The architecture treats this as one country module within a regional product,
  not a Honduras-only build.
- **One platform, Spanish-first** — sales, purchasing, inventory, accounting, HR, assets,
  commissions, and reporting in a single system localized for the region, rather than an
  English-first tool with a translation layer.

## Operating Context

- **Deployment shape:** central domain (marketing, registration, checkout, Super Admin) +
  isolated tenant workspaces on subdomains or verified custom domains (DNS + SSL).
- **The workday:** owners/admins at a desk; cashiers on POS terminals (keyboard-driven,
  ESC/POS and network receipt printing, optional cash drawer via QZ Tray, offline-capable,
  operational locks); warehouse staff moving stock between locations; accountants closing
  books and issuing fiscal documents.
- **Fiscal ritual (Honduras today):** configuration lives under Contabilidad → Facturación
  SAR (system-configuration permission required); current issuer/content/authorization
  settings apply only to future invoices; an issued fiscal document is a frozen snapshot and
  its fiscal fields (número fiscal, CAI, rango, correlativo, fecha) are never hand-edited.
- **Reference documentation in-repo:** `docs/FACTURACION_SAR_PRODEX.md`,
  `docs/INVENTORY_ARCHITECTURE_PHASE1.md`–`PHASE_3.md`, `docs/user-guides/` (Spanish),
  `stocky_saas_documentation/` (legacy screenshots and setup guide).
- **Lineage:** built on the Stocky SaaS codebase (CodeCanyon); PRODEX is a divergent
  product direction, and Stocky parity is not a goal.

## Capabilities and Constraints

**Capabilities in the product surface** (~40 top-level admin sections, ~345 SPA routes):
dashboard and real-time sales counter; POS (locations, registers, offline, keyboard
shortcuts, kitchen/customer displays); products with batches/expiry, serials, multi-image,
multi-category, internal rack/shelf locations; purchases and quotations; transfers with
logistics; adjustments, damages, quarantine; accounting v2 and auto journal entries; SAR
fiscal invoicing; expenses, deposits, accounts, pending payments; assets with verification
dates; sales commissions (programs, agents, receipts); HRM and recruiting; online store
(Stripe / Mollie / COD, price visibility rules), WooCommerce / Shopify; client portal;
knowledge base; support system; marketing, promotions, contracts, projects, bookings;
webhooks; PWA; per-tenant custom domains.

**Durable constraints:**

- **Stack is fixed:** the tenant admin is a Vue 2 (2.7) SPA on Bootstrap 4 + BootstrapVue,
  built with Laravel Mix / webpack. The redesign works within this stack — it is not a
  rewrite or a framework migration. Tailwind stays scoped to the storefront only
  (`tailwind.config.js` content globs); the admin does not adopt Tailwind.
- **Tenant appearance customization must keep working:** tenants override primary color,
  fonts, logo, and light/dark theme at runtime. The design system hooks into the existing
  `--primary-color` / `--primary-color-darker` / `--primary-color-soft` runtime variables
  and the `--px-*` semantic token layer in
  `resources/src/assets/styles/sass/prodex/_tokens.scss`; `.dark-theme` is the supported
  dark mode. New work uses these hooks rather than hard-coded values.
- **Regional framing, not Honduras framing:** the UI must not visually or structurally
  present PRODEX as Honduras-only. Country-specific fiscal features are modular adaptations
  surfaced within a Central America–wide product.
- **Spanish-first, multilingual, bidirectional:** default locale `es`; 11 shipped locales
  (`resources/lang/`: ar, bn, de, en, es, fr, hi, pt, tr, ur) including right-to-left
  (ar, ur). Layout and components must survive translation length changes and RTL.
- **Multi-tenant isolation** and central-vs-tenant route separation
  (`routes/central.php` vs `routes/tenant*.php`, `resources/views/central/` vs the SPA).
- **Existing z-index ladder** (`--px-z-*`) is the intended stacking contract; ~25 unscaled
  legacy literals remain to be migrated onto it.
- **Undecided / not yet established:** which additional countries get fiscal modules and
  when; whether purple remains the default brand color (see Brand Commitments); any formal
  accessibility target.

## Brand Commitments

- **Name: "Prodex"** is fixed. Wordmark/logo are managed per-tenant via
  Ajustes del sistema → Configuración de apariencia; the platform default logo is set by
  Super Admin default branding.
- **Voice:** Spanish-first, plain and operational (see `docs/` manuals and
  `docs/user-guides/`). No slogan or tagline is currently established as binding.
- **Not a commitment:** the current `#663399`-family purple is the inherited Stocky default,
  not a chosen brand color. The redesign may move the platform's default palette; whatever
  it chooses must still flow through the tenant `--primary-color` recolor hook.

## Evidence on Hand

- **Real product documentation:** `docs/FACTURACION_SAR_PRODEX.md` (SAR invoicing manual),
  `docs/INVENTORY_ARCHITECTURE_PHASE1.md` / `PHASE2` / `PHASE_2` / `PHASE_3` (inventory
  model), `docs/user-guides/empleados-usuarios-permisos.md`,
  `docs/user-guides/sucursales-bodegas.md`.
- **Legacy reference:** `stocky_saas_documentation/` — screenshots and setup guide from the
  upstream Stocky product (visually outdated; use as feature reference, not design
  reference).
- **Changelog:** `README.md` covers versions 1.0–1.3 (Super Admin and Tenant feature lists).
- **Incumbent design layer:** `resources/src/assets/styles/sass/prodex/` (token, foundation,
  interaction, list, table, toolbar partials) and `resources/static/prodex-*.js` behavior
  scripts — the current state of an in-progress in-house redesign, to be treated as
  evidence, not as a finished system.
- **Not available (must not be fabricated):** customer names, testimonials, logos,
  case studies, pricing tiers, deployment/scale numbers, and screenshots of the redesigned
  product.

## Product Principles

1. **Anchor on the owner-operator.** The person configuring and running the whole business
   is the primary reader; clarity for them outranks density for power users and polish for
   any single sub-surface.
2. **One platform, one language of operation.** Inventory, sales, purchasing, accounting,
   HR and reporting should feel like one system with consistent structure and terminology —
   not a suite of bolted-together modules.
3. **Regional by default, local where it counts.** Present PRODEX as Central America–wide;
   let country-specific fiscal and compliance behavior appear as scoped modules, never as
   the product's framing.
4. **Operational truth over decoration.** Fiscal snapshots, stock locations, quarantine,
   audit trails, freshness and lock states carry real consequences — surface state
   accurately and legibly before adding expressive flourish.
5. **Evolve the incumbent, don't fight the stack.** Improve within Vue 2 / Bootstrap 4 and
   the tenant theming hooks; preserve tenant customization, i18n, and RTL as first-class,
   not afterthoughts.

## Accessibility & Inclusion

No formal standard has been established as a product requirement. Concrete needs the product
already implies: full Spanish-first operation, 11-language support including RTL (ar, ur),
runtime light/dark theming, and keyboard-driven POS operation for cashiers. Treat visible
focus, keyboard reachability, and adequate contrast under both themes and tenant recoloring
as baseline expectations until a formal target is set.
