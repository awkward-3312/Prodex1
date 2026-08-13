<?php

namespace Database\Seeders\Central;

/**
 * Seeds default JSON translations for landing CMS records (hero, features, pricing, etc.).
 *
 * Safe to run multiple times: only inserts locale/field keys that are not already set.
 * Does not overwrite values edited in /super/cms/translations.
 *
 * Run (central connection must be default or configured):
 *   php artisan db:seed --class="Database\\Seeders\\Central\\CmsTranslationSeeder"
 *
 * Adjust locales in config/cms_translation_seeding.php (`locales` key).
 */
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
use App\Models\Central\LandingTestimonial;
use Illuminate\Database\Seeder;

class CmsTranslationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedHero();
        $this->seedFeaturesSection();
        $this->seedFeatures();
        $this->seedPricing();
        $this->seedTestimonials();
        $this->seedFaqs();
        $this->seedStats();
        $this->seedCta();
        $this->seedFooter();
        $this->seedSeo();
        $this->seedPrivacyPolicy();
    }

    protected function seedHero(): void
    {
        $record = LandingHero::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'title'               => 'Gérez votre inventaire plus intelligemment',
                'subtitle'            => 'Plateforme de gestion de stock tout-en-un',
                'description'         => 'Rationalisez vos opérations d\'entrepôt, suivez les stocks en temps réel et développez votre entreprise grâce à un POS puissant, la facturation et la gestion multi-sites.',
                'primary_button_text' => 'Commencer gratuitement',
                'secondary_button_text' => 'En savoir plus',
            ],
            'ar' => [
                'title'               => 'أدر مخزونك بذكاء أكبر',
                'subtitle'            => 'منصة متكاملة لإدارة المخزون',
                'description'         => 'قم بتبسيط عمليات المستودع، وتتبع المخزون في الوقت الفعلي، ونمِّ أعمالك باستخدام نقاط بيع قوية والفوترة ودعم المواقع المتعددة.',
                'primary_button_text' => 'ابدأ مجانًا',
                'secondary_button_text' => 'اعرف المزيد',
            ],
            'es' => [
                'title'               => 'Gestiona tu inventario de forma más inteligente',
                'subtitle'            => 'Plataforma integral de gestión de stock',
                'description'         => 'Optimiza las operaciones de tu almacén, rastrea el stock en tiempo real y haz crecer tu negocio con un potente POS, facturación y soporte multi-ubicación.',
                'primary_button_text' => 'Comenzar gratis',
                'secondary_button_text' => 'Más información',
            ],
            'hi' => [
                'title'               => 'अपनी इन्वेंट्री को स्मार्ट तरीके से प्रबंधित करें',
                'subtitle'            => 'ऑल-इन-वन स्टॉक प्रबंधन प्लेटफ़ॉर्म',
                'description'         => 'अपने गोदाम संचालन को सुव्यवस्थित करें, रीयल टाइम में स्टॉक ट्रैक करें, और शक्तिशाली POS, इनवॉइसिंग और मल्टी-लोकेशन सपोर्ट के साथ अपना व्यवसाय बढ़ाएं।',
                'primary_button_text' => 'मुफ्त में शुरू करें',
                'secondary_button_text' => 'और जानें',
            ],
            'bn' => [
                'title'               => 'আপনার ইনভেন্টরি আরও স্মার্টভাবে পরিচালনা করুন',
                'subtitle'            => 'অল-ইন-ওয়ান স্টক ম্যানেজমেন্ট প্ল্যাটফর্ম',
                'description'         => 'আপনার গুদাম পরিচালনা সহজ করুন, রিয়েল টাইমে স্টক ট্র্যাক করুন এবং শক্তিশালী POS, ইনভয়েসিং এবং মাল্টি-লোকেশন সাপোর্ট দিয়ে আপনার ব্যবসা বাড়ান।',
                'primary_button_text' => 'বিনামূল্যে শুরু করুন',
                'secondary_button_text' => 'আরও জানুন',
            ],
            'tr' => [
                'title'               => 'Envanterinizi daha akıllı yönetin',
                'subtitle'            => 'Hepsi bir arada stok yönetim platformu',
                'description'         => 'Depo operasyonlarınızı kolaylaştırın, stoku gerçek zamanlı takip edin ve güçlü POS, faturalama ve çoklu lokasyon desteğiyle işinizi büyütün.',
                'primary_button_text' => 'Ücretsiz başlayın',
                'secondary_button_text' => 'Daha fazla bilgi',
            ],
            'de' => [
                'title'               => 'Verwalten Sie Ihr Inventar intelligenter',
                'subtitle'            => 'All-in-One Bestandsverwaltungsplattform',
                'description'         => 'Optimieren Sie Ihre Lagerabläufe, verfolgen Sie Bestände in Echtzeit und wachsen Sie mit leistungsstarkem POS, Rechnungsstellung und Multi-Standort-Unterstützung.',
                'primary_button_text' => 'Kostenlos starten',
                'secondary_button_text' => 'Mehr erfahren',
            ],
            'pt' => [
                'title'               => 'Gerencie seu inventário de forma mais inteligente',
                'subtitle'            => 'Plataforma completa de gestão de estoque',
                'description'         => 'Simplifique as operações do seu armazém, rastreie o estoque em tempo real e expanda seus negócios com POS poderoso, faturamento e suporte multi-localização.',
                'primary_button_text' => 'Comece gratuitamente',
                'secondary_button_text' => 'Saiba mais',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedFeaturesSection(): void
    {
        $record = LandingFeaturesSection::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'section_title'    => 'Tout ce dont vous avez besoin',
                'section_subtitle' => 'Des fonctionnalités puissantes pour gérer l\'ensemble de vos opérations d\'inventaire depuis un seul endroit.',
            ],
            'ar' => [
                'section_title'    => 'كل ما تحتاجه',
                'section_subtitle' => 'ميزات قوية لإدارة جميع عمليات المخزون من مكان واحد.',
            ],
            'es' => [
                'section_title'    => 'Todo lo que necesitas',
                'section_subtitle' => 'Funciones potentes para gestionar todas tus operaciones de inventario desde un solo lugar.',
            ],
            'hi' => [
                'section_title'    => 'आपको जो कुछ भी चाहिए',
                'section_subtitle' => 'एक ही स्थान से अपने पूरे इन्वेंट्री संचालन को चलाने के लिए शक्तिशाली सुविधाएं।',
            ],
            'bn' => [
                'section_title'    => 'আপনার যা কিছু প্রয়োজন',
                'section_subtitle' => 'একটি জায়গা থেকে আপনার সম্পূর্ণ ইনভেন্টরি অপারেশন চালানোর জন্য শক্তিশালী বৈশিষ্ট্য।',
            ],
            'tr' => [
                'section_title'    => 'İhtiyacınız olan her şey',
                'section_subtitle' => 'Tüm envanter operasyonlarınızı tek bir yerden yönetmek için güçlü özellikler.',
            ],
            'de' => [
                'section_title'    => 'Alles, was Sie brauchen',
                'section_subtitle' => 'Leistungsstarke Funktionen zur Verwaltung Ihres gesamten Inventarbetriebs von einem Ort aus.',
            ],
            'pt' => [
                'section_title'    => 'Tudo o que você precisa',
                'section_subtitle' => 'Recursos poderosos para gerenciar toda a sua operação de inventário em um só lugar.',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedFeatures(): void
    {
        $features = LandingFeature::orderBy('sort_order')->get();
        if ($features->isEmpty()) return;

        // Translations keyed by English title
        $map = [
            'Multi-Warehouse' => [
                'fr' => ['title' => 'Multi-entrepôt',       'description' => 'Gérez le stock dans un nombre illimité d\'entrepôts et transférez entre les sites en quelques clics.'],
                'ar' => ['title' => 'مستودعات متعددة',        'description' => 'أدر المخزون عبر مستودعات غير محدودة وانقل بين المواقع ببضع نقرات.'],
                'es' => ['title' => 'Multi-almacén',         'description' => 'Gestiona el stock en almacenes ilimitados y transfiere entre ubicaciones con pocos clics.'],
                'hi' => ['title' => 'मल्टी-वेयरहाउस',          'description' => 'असीमित गोदामों में स्टॉक प्रबंधित करें और कुछ ही क्लिक में स्थानों के बीच ट्रांसफर करें।'],
                'bn' => ['title' => 'মাল্টি-গুদাম',            'description' => 'সীমাহীন গুদামে স্টক পরিচালনা করুন এবং কয়েকটি ক্লিকে লোকেশনের মধ্যে ট্রান্সফার করুন।'],
                'tr' => ['title' => 'Çoklu Depo',            'description' => 'Sınırsız depoda stok yönetin ve birkaç tıklamayla lokasyonlar arası transfer yapın.'],
                'de' => ['title' => 'Multi-Lager',           'description' => 'Verwalten Sie Bestände in unbegrenzten Lagern und transferieren Sie mit wenigen Klicks zwischen Standorten.'],
                'pt' => ['title' => 'Multi-armazém',         'description' => 'Gerencie estoque em armazéns ilimitados e transfira entre locais com poucos cliques.'],
            ],
            'Point of Sale' => [
                'fr' => ['title' => 'Point de vente',        'description' => 'Système de point de vente intégré avec lecture de codes-barres, reçus et multiples méthodes de paiement.'],
                'ar' => ['title' => 'نقطة البيع',             'description' => 'نظام نقاط بيع مدمج مع مسح الباركود والإيصالات وطرق دفع متعددة.'],
                'es' => ['title' => 'Punto de venta',        'description' => 'Sistema POS integrado con escaneo de códigos de barras, recibos y múltiples métodos de pago.'],
                'hi' => ['title' => 'पॉइंट ऑफ सेल',          'description' => 'बारकोड स्कैनिंग, रसीदें और कई भुगतान विधियों के साथ बिल्ट-इन POS सिस्टम।'],
                'bn' => ['title' => 'পয়েন্ট অফ সেল',         'description' => 'বারকোড স্ক্যানিং, রসিদ এবং একাধিক পেমেন্ট পদ্ধতি সহ বিল্ট-ইন POS সিস্টেম।'],
                'tr' => ['title' => 'Satış Noktası',         'description' => 'Barkod okuma, fiş ve çoklu ödeme yöntemi ile entegre POS sistemi.'],
                'de' => ['title' => 'Kassensystem',          'description' => 'Integriertes POS-System mit Barcode-Scanning, Quittungen und mehreren Zahlungsmethoden.'],
                'pt' => ['title' => 'Ponto de venda',        'description' => 'Sistema PDV integrado com leitura de código de barras, recibos e múltiplos métodos de pagamento.'],
            ],
            'Purchase & Sales' => [
                'fr' => ['title' => 'Achats et ventes',      'description' => 'Gérez les achats, ventes, retours et devis avec un suivi complet du cycle de vie des documents.'],
                'ar' => ['title' => 'المشتريات والمبيعات',     'description' => 'تعامل مع المشتريات والمبيعات والمرتجعات وعروض الأسعار مع تتبع كامل لدورة حياة المستندات.'],
                'es' => ['title' => 'Compras y ventas',      'description' => 'Gestiona compras, ventas, devoluciones y cotizaciones con seguimiento completo del ciclo de vida de documentos.'],
                'hi' => ['title' => 'खरीद और बिक्री',          'description' => 'पूर्ण दस्तावेज़ जीवनचक्र ट्रैकिंग के साथ खरीद, बिक्री, रिटर्न और कोटेशन संभालें।'],
                'bn' => ['title' => 'ক্রয় ও বিক্রয়',         'description' => 'সম্পূর্ণ ডকুমেন্ট লাইফসাইকেল ট্র্যাকিং সহ ক্রয়, বিক্রয়, রিটার্ন এবং কোটেশন পরিচালনা করুন।'],
                'tr' => ['title' => 'Alım ve Satış',         'description' => 'Tam belge yaşam döngüsü takibi ile satın alma, satış, iade ve teklifleri yönetin.'],
                'de' => ['title' => 'Einkauf & Verkauf',     'description' => 'Verwalten Sie Einkäufe, Verkäufe, Retouren und Angebote mit vollständiger Dokumenten-Lebenszyklus-Verfolgung.'],
                'pt' => ['title' => 'Compras e vendas',      'description' => 'Gerencie compras, vendas, devoluções e cotações com rastreamento completo do ciclo de vida dos documentos.'],
            ],
            'Real-Time Reports' => [
                'fr' => ['title' => 'Rapports en temps réel', 'description' => 'Tableaux de bord et rapports détaillés pour les niveaux de stock, les bénéfices, les meilleurs produits et plus encore.'],
                'ar' => ['title' => 'تقارير فورية',           'description' => 'لوحات تحكم وتقارير مفصلة لمستويات المخزون والأرباح والمنتجات الأكثر مبيعاً والمزيد.'],
                'es' => ['title' => 'Informes en tiempo real', 'description' => 'Paneles e informes detallados sobre niveles de stock, beneficios, productos principales y más.'],
                'hi' => ['title' => 'रीयल-टाइम रिपोर्ट',       'description' => 'स्टॉक स्तर, लाभ, शीर्ष उत्पादों और अधिक के लिए डैशबोर्ड और विस्तृत रिपोर्ट।'],
                'bn' => ['title' => 'রিয়েল-টাইম রিপোর্ট',     'description' => 'স্টক লেভেল, লাভ, শীর্ষ পণ্য এবং আরও অনেক কিছুর জন্য ড্যাশবোর্ড এবং বিস্তারিত রিপোর্ট।'],
                'tr' => ['title' => 'Gerçek zamanlı raporlar', 'description' => 'Stok seviyeleri, kâr, en çok satan ürünler ve daha fazlası için panolar ve ayrıntılı raporlar.'],
                'de' => ['title' => 'Echtzeit-Berichte',     'description' => 'Dashboards und detaillierte Berichte für Lagerbestände, Gewinn, Top-Produkte und mehr.'],
                'pt' => ['title' => 'Relatórios em tempo real', 'description' => 'Painéis e relatórios detalhados para níveis de estoque, lucro, produtos mais vendidos e muito mais.'],
            ],
            'Team & Permissions' => [
                'fr' => ['title' => 'Équipe et permissions',  'description' => 'Invitez votre équipe et contrôlez l\'accès avec des permissions granulaires basées sur les rôles.'],
                'ar' => ['title' => 'الفريق والصلاحيات',       'description' => 'ادعُ فريقك وتحكم في الوصول بصلاحيات دقيقة مبنية على الأدوار.'],
                'es' => ['title' => 'Equipo y permisos',     'description' => 'Invita a tu equipo y controla el acceso con permisos granulares basados en roles.'],
                'hi' => ['title' => 'टीम और अनुमतियाँ',       'description' => 'अपनी टीम को आमंत्रित करें और विस्तृत भूमिका-आधारित अनुमतियों से एक्सेस नियंत्रित करें।'],
                'bn' => ['title' => 'টিম ও অনুমতি',           'description' => 'আপনার টিমকে আমন্ত্রণ জানান এবং বিস্তারিত ভূমিকা-ভিত্তিক অনুমতি দিয়ে অ্যাক্সেস নিয়ন্ত্রণ করুন।'],
                'tr' => ['title' => 'Ekip ve İzinler',       'description' => 'Ekibinizi davet edin ve ayrıntılı rol tabanlı izinlerle erişimi kontrol edin.'],
                'de' => ['title' => 'Team & Berechtigungen', 'description' => 'Laden Sie Ihr Team ein und steuern Sie den Zugriff mit granularen rollenbasierten Berechtigungen.'],
                'pt' => ['title' => 'Equipe e permissões',   'description' => 'Convide sua equipe e controle o acesso com permissões granulares baseadas em funções.'],
            ],
            'WooCommerce' => [
                'fr' => ['title' => 'WooCommerce',           'description' => 'Synchronisez vos produits, commandes et niveaux de stock avec WooCommerce pour une vente en ligne fluide.'],
                'ar' => ['title' => 'ووكومرس',               'description' => 'زامن منتجاتك وطلباتك ومستويات المخزون مع ووكومرس للبيع عبر الإنترنت بسلاسة.'],
                'es' => ['title' => 'WooCommerce',           'description' => 'Sincroniza tus productos, pedidos y niveles de stock con WooCommerce para ventas en línea sin problemas.'],
                'hi' => ['title' => 'वूकॉमर्स',              'description' => 'निर्बाध ऑनलाइन बिक्री के लिए अपने उत्पाद, ऑर्डर और स्टॉक स्तर WooCommerce के साथ सिंक करें।'],
                'bn' => ['title' => 'উকমার্স',               'description' => 'নির্বিঘ্ন অনলাইন বিক্রির জন্য আপনার পণ্য, অর্ডার এবং স্টক লেভেল WooCommerce-এর সাথে সিঙ্ক করুন।'],
                'tr' => ['title' => 'WooCommerce',           'description' => 'Sorunsuz çevrimiçi satış için ürünlerinizi, siparişlerinizi ve stok seviyelerinizi WooCommerce ile senkronize edin.'],
                'de' => ['title' => 'WooCommerce',           'description' => 'Synchronisieren Sie Ihre Produkte, Bestellungen und Lagerbestände mit WooCommerce für nahtlosen Online-Verkauf.'],
                'pt' => ['title' => 'WooCommerce',           'description' => 'Sincronize seus produtos, pedidos e níveis de estoque com o WooCommerce para vendas online perfeitas.'],
            ],
            'Online Store' => [
                'fr' => ['title' => 'Boutique en ligne',     'description' => 'Lancez votre propre boutique en ligne personnalisée et laissez vos clients parcourir et commander directement.'],
                'ar' => ['title' => 'متجر إلكتروني',          'description' => 'أطلق متجرك الإلكتروني المخصص ودع العملاء يتصفحون ويطلبون مباشرة.'],
                'es' => ['title' => 'Tienda en línea',       'description' => 'Lanza tu propia tienda en línea personalizada y permite que los clientes naveguen y hagan pedidos directamente.'],
                'hi' => ['title' => 'ऑनलाइन स्टोर',          'description' => 'अपना खुद का ब्रांडेड ऑनलाइन स्टोर लॉन्च करें और ग्राहकों को सीधे ब्राउज़ और ऑर्डर करने दें।'],
                'bn' => ['title' => 'অনলাইন স্টোর',          'description' => 'আপনার নিজস্ব ব্র্যান্ডেড অনলাইন স্টোর চালু করুন এবং গ্রাহকদের সরাসরি ব্রাউজ ও অর্ডার করতে দিন।'],
                'tr' => ['title' => 'Çevrimiçi Mağaza',      'description' => 'Kendi markalı çevrimiçi mağazanızı açın ve müşterilerin doğrudan göz atıp sipariş vermesini sağlayın.'],
                'de' => ['title' => 'Online-Shop',           'description' => 'Starten Sie Ihren eigenen gebrandeten Online-Shop und lassen Sie Kunden direkt stöbern und bestellen.'],
                'pt' => ['title' => 'Loja online',           'description' => 'Lance sua própria loja online personalizada e permita que os clientes naveguem e façam pedidos diretamente.'],
            ],
            'Client Portal' => [
                'fr' => ['title' => 'Portail client',        'description' => 'Offrez à vos clients un portail en libre-service pour consulter les factures, suivre les commandes et gérer leur compte.'],
                'ar' => ['title' => 'بوابة العملاء',          'description' => 'امنح عملاءك بوابة خدمة ذاتية لعرض الفواتير وتتبع الطلبات وإدارة حساباتهم.'],
                'es' => ['title' => 'Portal de clientes',    'description' => 'Ofrece a tus clientes un portal de autoservicio para ver facturas, rastrear pedidos y gestionar su cuenta.'],
                'hi' => ['title' => 'क्लाइंट पोर्टल',         'description' => 'अपने ग्राहकों को चालान देखने, ऑर्डर ट्रैक करने और अपना खाता प्रबंधित करने के लिए सेल्फ-सर्विस पोर्टल दें।'],
                'bn' => ['title' => 'ক্লায়েন্ট পোর্টাল',      'description' => 'আপনার ক্লায়েন্টদের ইনভয়েস দেখতে, অর্ডার ট্র্যাক করতে এবং তাদের অ্যাকাউন্ট পরিচালনা করতে সেলফ-সার্ভিস পোর্টাল দিন।'],
                'tr' => ['title' => 'Müşteri Portalı',       'description' => 'Müşterilerinize faturaları görüntülemek, siparişleri takip etmek ve hesaplarını yönetmek için self-servis portal sunun.'],
                'de' => ['title' => 'Kundenportal',          'description' => 'Bieten Sie Ihren Kunden ein Self-Service-Portal zum Anzeigen von Rechnungen, Verfolgen von Bestellungen und Verwalten ihres Kontos.'],
                'pt' => ['title' => 'Portal do cliente',     'description' => 'Ofereça aos seus clientes um portal de autoatendimento para visualizar faturas, rastrear pedidos e gerenciar suas contas.'],
            ],
            '24+ Languages' => [
                'fr' => ['title' => '24+ langues',           'description' => 'Atteignez un public mondial avec un support intégré pour plus de 24 langues prêt à l\'emploi.'],
                'ar' => ['title' => '+24 لغة',               'description' => 'اوصل إلى جمهور عالمي مع دعم مدمج لأكثر من 24 لغة جاهزة للاستخدام.'],
                'es' => ['title' => '24+ idiomas',           'description' => 'Alcanza una audiencia global con soporte integrado para más de 24 idiomas listos para usar.'],
                'hi' => ['title' => '24+ भाषाएं',            'description' => '24 से अधिक भाषाओं के बिल्ट-इन सपोर्ट के साथ वैश्विक दर्शकों तक पहुंचें।'],
                'bn' => ['title' => '24+ ভাষা',              'description' => '24টিরও বেশি ভাষার বিল্ট-ইন সাপোর্ট দিয়ে বিশ্বব্যাপী দর্শকদের কাছে পৌঁছান।'],
                'tr' => ['title' => '24+ Dil',               'description' => '24\'ten fazla dil için yerleşik destek ile küresel bir kitleye ulaşın.'],
                'de' => ['title' => '24+ Sprachen',          'description' => 'Erreichen Sie ein globales Publikum mit integrierter Unterstützung für über 24 Sprachen.'],
                'pt' => ['title' => '24+ idiomas',           'description' => 'Alcance um público global com suporte integrado para mais de 24 idiomas prontos para uso.'],
            ],
        ];

        foreach ($features as $feature) {
            $englishTitle = $feature->getOriginalAttribute('title');
            if (isset($map[$englishTitle])) {
                $this->applyTranslations($feature, $map[$englishTitle]);
            }
        }
    }

    protected function seedPricing(): void
    {
        $record = LandingPricing::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'section_title'    => 'Tarification simple et transparente',
                'section_subtitle' => 'Choisissez le plan adapté à votre entreprise. Mettez à niveau ou rétrogradez à tout moment.',
            ],
            'ar' => [
                'section_title'    => 'أسعار بسيطة وشفافة',
                'section_subtitle' => 'اختر الخطة التي تناسب عملك. قم بالترقية أو التخفيض في أي وقت.',
            ],
            'es' => [
                'section_title'    => 'Precios simples y transparentes',
                'section_subtitle' => 'Elige el plan que se adapte a tu negocio. Sube o baja de nivel en cualquier momento.',
            ],
            'hi' => [
                'section_title'    => 'सरल, पारदर्शी मूल्य निर्धारण',
                'section_subtitle' => 'अपने व्यवसाय के लिए उपयुक्त प्लान चुनें। कभी भी अपग्रेड या डाउनग्रेड करें।',
            ],
            'bn' => [
                'section_title'    => 'সহজ, স্বচ্ছ মূল্য নির্ধারণ',
                'section_subtitle' => 'আপনার ব্যবসার জন্য উপযুক্ত প্ল্যান বেছে নিন। যেকোনো সময় আপগ্রেড বা ডাউনগ্রেড করুন।',
            ],
            'tr' => [
                'section_title'    => 'Basit, şeffaf fiyatlandırma',
                'section_subtitle' => 'İşletmenize uygun planı seçin. İstediğiniz zaman yükseltin veya düşürün.',
            ],
            'de' => [
                'section_title'    => 'Einfache, transparente Preisgestaltung',
                'section_subtitle' => 'Wählen Sie den Plan, der zu Ihrem Unternehmen passt. Jederzeit upgraden oder downgraden.',
            ],
            'pt' => [
                'section_title'    => 'Preços simples e transparentes',
                'section_subtitle' => 'Escolha o plano que se adapta ao seu negócio. Faça upgrade ou downgrade a qualquer momento.',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedTestimonials(): void
    {
        $testimonials = LandingTestimonial::orderBy('sort_order')->get();
        if ($testimonials->isEmpty()) return;

        $map = [
            'Sarah Johnson' => [
                'fr' => ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'Stocky a transformé notre façon de gérer l\'inventaire dans nos trois magasins. Les mises à jour de stock en temps réel nous ont sauvés des ruptures constantes.'],
                'ar' => ['client_name' => 'سارة جونسون',    'company_name' => 'أوربان ريتيل',     'review' => 'غيّر Stocky طريقة إدارتنا للمخزون عبر متاجرنا الثلاثة. تحديثات المخزون الفورية أنقذتنا من نفاد المخزون المستمر.'],
                'es' => ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'Stocky transformó la forma en que gestionamos el inventario en nuestras tres tiendas. Las actualizaciones de stock en tiempo real nos salvaron de los desabastecimientos constantes.'],
                'hi' => ['client_name' => 'सारा जॉनसन',     'company_name' => 'अर्बन रिटेल कंपनी', 'review' => 'Stocky ने हमारे तीन स्टोर में इन्वेंट्री प्रबंधन को बदल दिया। रीयल-टाइम स्टॉक अपडेट ने हमें लगातार स्टॉक-आउट से बचाया।'],
                'bn' => ['client_name' => 'সারা জনসন',      'company_name' => 'আর্বান রিটেইল কো.', 'review' => 'Stocky আমাদের তিনটি দোকানে ইনভেন্টরি পরিচালনার ধরন পরিবর্তন করেছে। রিয়েল-টাইম স্টক আপডেট আমাদের ক্রমাগত স্টক-আউট থেকে বাঁচিয়েছে।'],
                'tr' => ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'Stocky, üç mağazamızdaki envanter yönetim şeklimizi dönüştürdü. Gerçek zamanlı stok güncellemeleri bizi sürekli stok tükenmesinden kurtardı.'],
                'de' => ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'Stocky hat die Art und Weise verändert, wie wir das Inventar in unseren drei Geschäften verwalten. Echtzeit-Bestandsaktualisierungen haben uns vor ständigen Engpässen bewahrt.'],
                'pt' => ['client_name' => 'Sarah Johnson', 'company_name' => 'Urban Retail Co.', 'review' => 'O Stocky transformou a forma como gerenciamos o inventário em nossas três lojas. Atualizações de estoque em tempo real nos salvaram de rupturas constantes.'],
            ],
            'Ahmed Benali' => [
                'fr' => ['client_name' => 'Ahmed Benali',  'company_name' => 'MedSupply Direct', 'review' => 'La fonctionnalité multi-entrepôt est révolutionnaire. Nous avons réduit les erreurs de transfert de 80% dès le premier mois.'],
                'ar' => ['client_name' => 'أحمد بن علي',    'company_name' => 'ميدسبلاي دايركت',  'review' => 'ميزة المستودعات المتعددة غيّرت قواعد اللعبة. قللنا أخطاء النقل بنسبة 80% في الشهر الأول.'],
                'es' => ['client_name' => 'Ahmed Benali',  'company_name' => 'MedSupply Direct', 'review' => 'La función multi-almacén es un cambio radical. Redujimos los errores de transferencia en un 80% en el primer mes.'],
                'hi' => ['client_name' => 'अहमद बेनाली',    'company_name' => 'मेडसप्लाई डायरेक्ट', 'review' => 'मल्टी-वेयरहाउस फीचर ने सब कुछ बदल दिया। हमने पहले महीने में ट्रांसफर त्रुटियों को 80% कम कर दिया।'],
                'bn' => ['client_name' => 'আহমেদ বেনালি',   'company_name' => 'মেডসাপ্লাই ডিরেক্ট', 'review' => 'মাল্টি-গুদাম ফিচারটি গেম-চেঞ্জার। আমরা প্রথম মাসেই ট্রান্সফার ত্রুটি 80% কমিয়ে এনেছি।'],
                'tr' => ['client_name' => 'Ahmed Benali',  'company_name' => 'MedSupply Direct', 'review' => 'Çoklu depo özelliği oyunun kurallarını değiştirdi. İlk ayda transfer hatalarını %80 azalttık.'],
                'de' => ['client_name' => 'Ahmed Benali',  'company_name' => 'MedSupply Direct', 'review' => 'Die Multi-Lager-Funktion ist bahnbrechend. Wir haben Transferfehler im ersten Monat um 80% reduziert.'],
                'pt' => ['client_name' => 'Ahmed Benali',  'company_name' => 'MedSupply Direct', 'review' => 'O recurso multi-armazém é revolucionário. Reduzimos erros de transferência em 80% no primeiro mês.'],
            ],
            'Maria Chen' => [
                'fr' => ['client_name' => 'Maria Chen',    'company_name' => 'FreshMart Grocery', 'review' => 'Facile à configurer et le POS fonctionne parfaitement. Nos caissiers étaient à l\'aise dès le premier jour.'],
                'ar' => ['client_name' => 'ماريا تشين',     'company_name' => 'فريش مارت',         'review' => 'سهل الإعداد ونقاط البيع تعمل بسلاسة. كان موظفو الكاشير مرتاحين معه من اليوم الأول.'],
                'es' => ['client_name' => 'Maria Chen',    'company_name' => 'FreshMart Grocery', 'review' => 'Fácil de configurar y el POS funciona a la perfección. Nuestros cajeros se sintieron cómodos desde el primer día.'],
                'hi' => ['client_name' => 'मारिया चेन',     'company_name' => 'फ्रेशमार्ट ग्रॉसरी', 'review' => 'सेटअप करना आसान और POS बिल्कुल सही काम करता है। हमारे कैशियर पहले दिन से ही सहज थे।'],
                'bn' => ['client_name' => 'মারিয়া চেন',    'company_name' => 'ফ্রেশমার্ট গ্রোসারি', 'review' => 'সেটআপ করা সহজ এবং POS নিখুঁতভাবে কাজ করে। আমাদের ক্যাশিয়াররা প্রথম দিন থেকেই স্বাচ্ছন্দ্য বোধ করেছে।'],
                'tr' => ['client_name' => 'Maria Chen',    'company_name' => 'FreshMart Grocery', 'review' => 'Kurulumu kolay ve POS kusursuz çalışıyor. Kasiyerlerimiz ilk günden rahat etti.'],
                'de' => ['client_name' => 'Maria Chen',    'company_name' => 'FreshMart Grocery', 'review' => 'Einfach einzurichten und das POS funktioniert einwandfrei. Unsere Kassierer waren vom ersten Tag an damit vertraut.'],
                'pt' => ['client_name' => 'Maria Chen',    'company_name' => 'FreshMart Grocery', 'review' => 'Fácil de configurar e o PDV funciona perfeitamente. Nossos caixas se sentiram confortáveis desde o primeiro dia.'],
            ],
        ];

        foreach ($testimonials as $testimonial) {
            $englishName = $testimonial->getOriginalAttribute('client_name');
            if (isset($map[$englishName])) {
                $this->applyTranslations($testimonial, $map[$englishName]);
            }
        }
    }

    protected function seedFaqs(): void
    {
        $faqs = LandingFaq::orderBy('sort_order')->get();
        if ($faqs->isEmpty()) return;

        $map = [
            'How long is the free trial?' => [
                'fr' => ['question' => 'Quelle est la durée de l\'essai gratuit ?',          'answer' => 'Chaque nouvel espace de travail bénéficie d\'un essai gratuit de 14 jours sur le plan Professionnel. Aucune carte de crédit requise.'],
                'ar' => ['question' => 'ما مدة الفترة التجريبية المجانية؟',                   'answer' => 'يحصل كل مساحة عمل جديدة على فترة تجريبية مجانية لمدة 14 يومًا على الخطة الاحترافية. لا حاجة لبطاقة ائتمان.'],
                'es' => ['question' => '¿Cuánto dura la prueba gratuita?',                   'answer' => 'Cada nuevo espacio de trabajo incluye una prueba gratuita de 14 días en el plan Profesional. Sin tarjeta de crédito.'],
                'hi' => ['question' => 'मुफ्त परीक्षण कितने दिनों का है?',                      'answer' => 'हर नए कार्यक्षेत्र में प्रोफेशनल प्लान पर 14 दिन का मुफ्त परीक्षण मिलता है। क्रेडिट कार्ड की आवश्यकता नहीं।'],
                'bn' => ['question' => 'বিনামূল্যে ট্রায়াল কতদিনের?',                        'answer' => 'প্রতিটি নতুন ওয়ার্কস্পেস প্রফেশনাল প্ল্যানে 14 দিনের বিনামূল্যে ট্রায়াল পায়। ক্রেডিট কার্ডের প্রয়োজন নেই।'],
                'tr' => ['question' => 'Ücretsiz deneme süresi ne kadar?',                   'answer' => 'Her yeni çalışma alanı, Professional planda 14 günlük ücretsiz deneme süresine sahiptir. Kredi kartı gerekmez.'],
                'de' => ['question' => 'Wie lange dauert die kostenlose Testversion?',       'answer' => 'Jeder neue Arbeitsbereich erhält eine 14-tägige kostenlose Testversion im Professional-Plan. Keine Kreditkarte erforderlich.'],
                'pt' => ['question' => 'Quanto tempo dura o teste gratuito?',                'answer' => 'Cada novo espaço de trabalho recebe um teste gratuito de 14 dias no plano Profissional. Sem necessidade de cartão de crédito.'],
            ],
            'Can I manage multiple warehouses?' => [
                'fr' => ['question' => 'Puis-je gérer plusieurs entrepôts ?',                'answer' => 'Oui. Tous les plans payants prennent en charge un nombre illimité d\'entrepôts avec transferts de stock, ajustements et rapports par emplacement.'],
                'ar' => ['question' => 'هل يمكنني إدارة مستودعات متعددة؟',                    'answer' => 'نعم. تدعم جميع الخطط المدفوعة مستودعات غير محدودة مع نقل المخزون والتعديلات والتقارير حسب الموقع.'],
                'es' => ['question' => '¿Puedo gestionar múltiples almacenes?',              'answer' => 'Sí. Todos los planes de pago admiten almacenes ilimitados con transferencias de stock, ajustes e informes por ubicación.'],
                'hi' => ['question' => 'क्या मैं कई गोदामों को प्रबंधित कर सकता हूं?',         'answer' => 'हां। सभी पेड प्लान स्टॉक ट्रांसफर, एडजस्टमेंट और प्रति-लोकेशन रिपोर्टिंग के साथ असीमित गोदामों का समर्थन करते हैं।'],
                'bn' => ['question' => 'আমি কি একাধিক গুদাম পরিচালনা করতে পারি?',            'answer' => 'হ্যাঁ। সমস্ত পেইড প্ল্যান স্টক ট্রান্সফার, অ্যাডজাস্টমেন্ট এবং প্রতি-লোকেশন রিপোর্টিং সহ সীমাহীন গুদাম সমর্থন করে।'],
                'tr' => ['question' => 'Birden fazla depo yönetebilir miyim?',               'answer' => 'Evet. Tüm ücretli planlar stok transferleri, ayarlamalar ve lokasyon bazlı raporlama ile sınırsız depo destekler.'],
                'de' => ['question' => 'Kann ich mehrere Lager verwalten?',                  'answer' => 'Ja. Alle kostenpflichtigen Pläne unterstützen unbegrenzte Lager mit Bestandstransfers, Anpassungen und standortbezogenen Berichten.'],
                'pt' => ['question' => 'Posso gerenciar vários armazéns?',                   'answer' => 'Sim. Todos os planos pagos suportam armazéns ilimitados com transferências de estoque, ajustes e relatórios por localização.'],
            ],
            'Is my data secure?' => [
                'fr' => ['question' => 'Mes données sont-elles sécurisées ?',                'answer' => 'Absolument. Chaque espace de travail dispose de sa propre base de données isolée, de connexions chiffrées et de sauvegardes quotidiennes automatiques.'],
                'ar' => ['question' => 'هل بياناتي آمنة؟',                                    'answer' => 'بالتأكيد. كل مساحة عمل لديها قاعدة بيانات معزولة خاصة بها، واتصالات مشفرة، ونسخ احتياطية يومية تلقائية.'],
                'es' => ['question' => '¿Están seguros mis datos?',                          'answer' => 'Absolutamente. Cada espacio de trabajo tiene su propia base de datos aislada, conexiones cifradas y copias de seguridad diarias automáticas.'],
                'hi' => ['question' => 'क्या मेरा डेटा सुरक्षित है?',                           'answer' => 'बिल्कुल। हर कार्यक्षेत्र की अपनी अलग डेटाबेस, एन्क्रिप्टेड कनेक्शन और स्वचालित दैनिक बैकअप होते हैं।'],
                'bn' => ['question' => 'আমার ডেটা কি নিরাপদ?',                               'answer' => 'অবশ্যই। প্রতিটি ওয়ার্কস্পেসের নিজস্ব বিচ্ছিন্ন ডেটাবেস, এনক্রিপ্টেড কানেকশন এবং স্বয়ংক্রিয় দৈনিক ব্যাকআপ আছে।'],
                'tr' => ['question' => 'Verilerim güvende mi?',                              'answer' => 'Kesinlikle. Her çalışma alanının kendi izole veritabanı, şifreli bağlantıları ve otomatik günlük yedeklemeleri vardır.'],
                'de' => ['question' => 'Sind meine Daten sicher?',                           'answer' => 'Absolut. Jeder Arbeitsbereich hat seine eigene isolierte Datenbank, verschlüsselte Verbindungen und automatische tägliche Backups.'],
                'pt' => ['question' => 'Meus dados estão seguros?',                          'answer' => 'Com certeza. Cada espaço de trabalho tem seu próprio banco de dados isolado, conexões criptografadas e backups diários automáticos.'],
            ],
            'Can I import my existing products?' => [
                'fr' => ['question' => 'Puis-je importer mes produits existants ?',          'answer' => 'Oui. Vous pouvez importer en masse des produits, clients et fournisseurs via CSV. Nous offrons aussi la synchronisation WooCommerce pour les boutiques en ligne.'],
                'ar' => ['question' => 'هل يمكنني استيراد منتجاتي الحالية؟',                  'answer' => 'نعم. يمكنك استيراد المنتجات والعملاء والموردين بشكل مجمع عبر CSV. كما نوفر مزامنة ووكومرس للمتاجر الإلكترونية.'],
                'es' => ['question' => '¿Puedo importar mis productos existentes?',          'answer' => 'Sí. Puedes importar productos, clientes y proveedores masivamente por CSV. También ofrecemos sincronización con WooCommerce para tiendas en línea.'],
                'hi' => ['question' => 'क्या मैं अपने मौजूदा उत्पाद आयात कर सकता हूं?',      'answer' => 'हां। आप CSV के माध्यम से उत्पाद, ग्राहक और आपूर्तिकर्ता बल्क-इम्पोर्ट कर सकते हैं। हम ऑनलाइन स्टोर के लिए WooCommerce सिंक भी प्रदान करते हैं।'],
                'bn' => ['question' => 'আমি কি আমার বিদ্যমান পণ্য আমদানি করতে পারি?',       'answer' => 'হ্যাঁ। আপনি CSV-এর মাধ্যমে পণ্য, ক্লায়েন্ট এবং সরবরাহকারী বাল্ক-ইমপোর্ট করতে পারেন। আমরা অনলাইন স্টোরের জন্য WooCommerce সিঙ্কও অফার করি।'],
                'tr' => ['question' => 'Mevcut ürünlerimi içe aktarabilir miyim?',            'answer' => 'Evet. CSV ile ürünleri, müşterileri ve tedarikçileri toplu olarak içe aktarabilirsiniz. Çevrimiçi mağazalar için WooCommerce senkronizasyonu da sunuyoruz.'],
                'de' => ['question' => 'Kann ich meine bestehenden Produkte importieren?',   'answer' => 'Ja. Sie können Produkte, Kunden und Lieferanten per CSV-Massenimport hochladen. Wir bieten auch WooCommerce-Synchronisation für Online-Shops.'],
                'pt' => ['question' => 'Posso importar meus produtos existentes?',           'answer' => 'Sim. Você pode importar produtos, clientes e fornecedores em massa via CSV. Também oferecemos sincronização com WooCommerce para lojas online.'],
            ],
            'What payment methods do you accept?' => [
                'fr' => ['question' => 'Quels moyens de paiement acceptez-vous ?',           'answer' => 'Nous acceptons toutes les cartes de crédit principales via Stripe, ainsi que PayPal, Paystack et Flutterwave selon votre région.'],
                'ar' => ['question' => 'ما طرق الدفع المقبولة لديكم؟',                        'answer' => 'نقبل جميع بطاقات الائتمان الرئيسية عبر Stripe، بالإضافة إلى PayPal وPaystack وFlutterwave حسب منطقتك.'],
                'es' => ['question' => '¿Qué métodos de pago aceptan?',                      'answer' => 'Aceptamos todas las tarjetas de crédito principales a través de Stripe, así como PayPal, Paystack y Flutterwave según tu región.'],
                'hi' => ['question' => 'आप कौन से भुगतान तरीके स्वीकार करते हैं?',             'answer' => 'हम Stripe के माध्यम से सभी प्रमुख क्रेडिट कार्ड स्वीकार करते हैं, साथ ही आपके क्षेत्र के अनुसार PayPal, Paystack और Flutterwave भी।'],
                'bn' => ['question' => 'আপনারা কোন পেমেন্ট পদ্ধতি গ্রহণ করেন?',             'answer' => 'আমরা Stripe-এর মাধ্যমে সমস্ত প্রধান ক্রেডিট কার্ড গ্রহণ করি, সেইসাথে আপনার অঞ্চল অনুযায়ী PayPal, Paystack এবং Flutterwave।'],
                'tr' => ['question' => 'Hangi ödeme yöntemlerini kabul ediyorsunuz?',        'answer' => 'Stripe üzerinden tüm büyük kredi kartlarını, ayrıca bölgenize göre PayPal, Paystack ve Flutterwave\'i kabul ediyoruz.'],
                'de' => ['question' => 'Welche Zahlungsmethoden akzeptieren Sie?',           'answer' => 'Wir akzeptieren alle gängigen Kreditkarten über Stripe sowie PayPal, Paystack und Flutterwave je nach Region.'],
                'pt' => ['question' => 'Quais métodos de pagamento vocês aceitam?',          'answer' => 'Aceitamos todos os principais cartões de crédito via Stripe, além de PayPal, Paystack e Flutterwave dependendo da sua região.'],
            ],
        ];

        foreach ($faqs as $faq) {
            $englishQuestion = $faq->getOriginalAttribute('question');
            if (isset($map[$englishQuestion])) {
                $this->applyTranslations($faq, $map[$englishQuestion]);
            }
        }
    }

    protected function seedStats(): void
    {
        $stats = LandingStat::orderBy('sort_order')->get();
        if ($stats->isEmpty()) return;

        $map = [
            'Businesses' => [
                'fr' => ['label' => 'Entreprises'],
                'ar' => ['label' => 'شركة'],
                'es' => ['label' => 'Empresas'],
                'hi' => ['label' => 'व्यवसाय'],
                'bn' => ['label' => 'ব্যবসা'],
                'tr' => ['label' => 'İşletme'],
                'de' => ['label' => 'Unternehmen'],
                'pt' => ['label' => 'Empresas'],
            ],
            'Items Managed' => [
                'fr' => ['label' => 'Articles gérés'],
                'ar' => ['label' => 'منتج مُدار'],
                'es' => ['label' => 'Artículos gestionados'],
                'hi' => ['label' => 'प्रबंधित आइटम'],
                'bn' => ['label' => 'পরিচালিত আইটেম'],
                'tr' => ['label' => 'Yönetilen ürün'],
                'de' => ['label' => 'Verwaltete Artikel'],
                'pt' => ['label' => 'Itens gerenciados'],
            ],
            'Uptime' => [
                'fr' => ['label' => 'Disponibilité'],
                'ar' => ['label' => 'وقت التشغيل'],
                'es' => ['label' => 'Tiempo activo'],
                'hi' => ['label' => 'अपटाइम'],
                'bn' => ['label' => 'আপটাইম'],
                'tr' => ['label' => 'Çalışma süresi'],
                'de' => ['label' => 'Verfügbarkeit'],
                'pt' => ['label' => 'Disponibilidade'],
            ],
            'User Rating' => [
                'fr' => ['label' => 'Note utilisateurs'],
                'ar' => ['label' => 'تقييم المستخدمين'],
                'es' => ['label' => 'Valoración de usuarios'],
                'hi' => ['label' => 'उपयोगकर्ता रेटिंग'],
                'bn' => ['label' => 'ব্যবহারকারী রেটিং'],
                'tr' => ['label' => 'Kullanıcı puanı'],
                'de' => ['label' => 'Nutzerbewertung'],
                'pt' => ['label' => 'Avaliação dos usuários'],
            ],
        ];

        foreach ($stats as $stat) {
            $englishLabel = $stat->getOriginalAttribute('label');
            if (isset($map[$englishLabel])) {
                $this->applyTranslations($stat, $map[$englishLabel]);
            }
        }
    }

    protected function seedCta(): void
    {
        $record = LandingCta::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'title'       => 'Prêt à prendre le contrôle de votre inventaire ?',
                'subtitle'    => 'Rejoignez des milliers d\'entreprises qui utilisent déjà Stocky. Commencez votre essai gratuit aujourd\'hui.',
                'button_text' => 'Commencer gratuitement',
            ],
            'ar' => [
                'title'       => 'هل أنت مستعد للتحكم في مخزونك؟',
                'subtitle'    => 'انضم إلى آلاف الشركات التي تستخدم Stocky بالفعل. ابدأ تجربتك المجانية اليوم.',
                'button_text' => 'ابدأ مجانًا',
            ],
            'es' => [
                'title'       => '¿Listo para tomar el control de tu inventario?',
                'subtitle'    => 'Únete a miles de empresas que ya usan Stocky. Comienza tu prueba gratuita hoy.',
                'button_text' => 'Comenzar gratis',
            ],
            'hi' => [
                'title'       => 'अपनी इन्वेंट्री पर नियंत्रण के लिए तैयार हैं?',
                'subtitle'    => 'हजारों व्यवसायों में शामिल हों जो पहले से Stocky का उपयोग कर रहे हैं। आज ही अपना मुफ्त परीक्षण शुरू करें।',
                'button_text' => 'मुफ्त में शुरू करें',
            ],
            'bn' => [
                'title'       => 'আপনার ইনভেন্টরির নিয়ন্ত্রণ নিতে প্রস্তুত?',
                'subtitle'    => 'হাজার হাজার ব্যবসায় যোগ দিন যারা ইতিমধ্যে Stocky ব্যবহার করছে। আজই আপনার বিনামূল্যে ট্রায়াল শুরু করুন।',
                'button_text' => 'বিনামূল্যে শুরু করুন',
            ],
            'tr' => [
                'title'       => 'Envanterinizin kontrolünü almaya hazır mısınız?',
                'subtitle'    => 'Stocky\'yi zaten kullanan binlerce işletmeye katılın. Ücretsiz denemenizi bugün başlatın.',
                'button_text' => 'Ücretsiz başlayın',
            ],
            'de' => [
                'title'       => 'Bereit, die Kontrolle über Ihr Inventar zu übernehmen?',
                'subtitle'    => 'Schließen Sie sich Tausenden von Unternehmen an, die Stocky bereits nutzen. Starten Sie noch heute Ihre kostenlose Testversion.',
                'button_text' => 'Kostenlos starten',
            ],
            'pt' => [
                'title'       => 'Pronto para assumir o controle do seu inventário?',
                'subtitle'    => 'Junte-se a milhares de empresas que já usam o Stocky. Comece seu teste gratuito hoje.',
                'button_text' => 'Comece gratuitamente',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedFooter(): void
    {
        $record = LandingFooter::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'footer_about'   => 'Stocky est une plateforme moderne de gestion d\'inventaire et de stock conçue pour les entreprises de toutes tailles.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. Tous droits réservés.',
            ],
            'ar' => [
                'footer_about'   => 'Stocky هي منصة حديثة لإدارة المخزون مصممة للشركات بجميع أحجامها.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. جميع الحقوق محفوظة.',
            ],
            'es' => [
                'footer_about'   => 'Stocky es una plataforma moderna de gestión de inventario y stock diseñada para empresas de todos los tamaños.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. Todos los derechos reservados.',
            ],
            'hi' => [
                'footer_about'   => 'Stocky हर आकार के व्यवसायों के लिए बनाया गया एक आधुनिक इन्वेंट्री और स्टॉक प्रबंधन प्लेटफ़ॉर्म है।',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '। सर्वाधिकार सुरक्षित।',
            ],
            'bn' => [
                'footer_about'   => 'Stocky সকল আকারের ব্যবসার জন্য নির্মিত একটি আধুনিক ইনভেন্টরি ও স্টক ম্যানেজমেন্ট প্ল্যাটফর্ম।',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '। সর্বস্বত্ব সংরক্ষিত।',
            ],
            'tr' => [
                'footer_about'   => 'Stocky, her büyüklükteki işletme için tasarlanmış modern bir envanter ve stok yönetim platformudur.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. Tüm hakları saklıdır.',
            ],
            'de' => [
                'footer_about'   => 'Stocky ist eine moderne Inventar- und Bestandsverwaltungsplattform für Unternehmen jeder Größe.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. Alle Rechte vorbehalten.',
            ],
            'pt' => [
                'footer_about'   => 'Stocky é uma plataforma moderna de gestão de inventário e estoque projetada para empresas de todos os tamanhos.',
                'copyright_text' => '© ' . date('Y') . ' ' . config('app.name', 'Stocky') . '. Todos os direitos reservados.',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedSeo(): void
    {
        $record = LandingSeo::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — Plateforme de gestion d\'inventaire et de stock',
                'meta_description' => 'Gérez l\'inventaire, les entrepôts, le POS, les achats et les ventes depuis une seule plateforme puissante. Commencez votre essai gratuit aujourd\'hui.',
                'meta_keywords'    => 'gestion d\'inventaire, gestion de stock, POS, entrepôt, multi-sites, SaaS',
            ],
            'ar' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — منصة إدارة المخزون والمستودعات',
                'meta_description' => 'أدر المخزون والمستودعات ونقاط البيع والمشتريات والمبيعات من منصة واحدة قوية. ابدأ تجربتك المجانية اليوم.',
                'meta_keywords'    => 'إدارة المخزون, إدارة المستودعات, نقاط البيع, مستودع, متعدد المواقع, SaaS',
            ],
            'es' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — Plataforma de gestión de inventario y stock',
                'meta_description' => 'Gestiona inventario, almacenes, POS, compras y ventas desde una sola plataforma potente. Comienza tu prueba gratuita hoy.',
                'meta_keywords'    => 'gestión de inventario, gestión de stock, POS, almacén, multi-ubicación, SaaS',
            ],
            'hi' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — इन्वेंट्री और स्टॉक प्रबंधन प्लेटफ़ॉर्म',
                'meta_description' => 'एक शक्तिशाली प्लेटफ़ॉर्म से इन्वेंट्री, गोदाम, POS, खरीद और बिक्री प्रबंधित करें। आज ही अपना मुफ्त परीक्षण शुरू करें।',
                'meta_keywords'    => 'इन्वेंट्री प्रबंधन, स्टॉक प्रबंधन, POS, गोदाम, मल्टी-लोकेशन, SaaS',
            ],
            'bn' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — ইনভেন্টরি ও স্টক ম্যানেজমেন্ট প্ল্যাটফর্ম',
                'meta_description' => 'একটি শক্তিশালী প্ল্যাটফর্ম থেকে ইনভেন্টরি, গুদাম, POS, ক্রয় এবং বিক্রয় পরিচালনা করুন। আজই আপনার বিনামূল্যে ট্রায়াল শুরু করুন।',
                'meta_keywords'    => 'ইনভেন্টরি ম্যানেজমেন্ট, স্টক ম্যানেজমেন্ট, POS, গুদাম, মাল্টি-লোকেশন, SaaS',
            ],
            'tr' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — Envanter ve Stok Yönetim Platformu',
                'meta_description' => 'Tek bir güçlü platformdan envanter, depo, POS, satın alma ve satışları yönetin. Ücretsiz denemenizi bugün başlatın.',
                'meta_keywords'    => 'envanter yönetimi, stok yönetimi, POS, depo, çoklu lokasyon, SaaS',
            ],
            'de' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — Inventar- und Bestandsverwaltungsplattform',
                'meta_description' => 'Verwalten Sie Inventar, Lager, POS, Einkäufe und Verkäufe über eine einzige leistungsstarke Plattform. Starten Sie noch heute Ihre kostenlose Testversion.',
                'meta_keywords'    => 'Inventarverwaltung, Bestandsverwaltung, POS, Lager, Multi-Standort, SaaS',
            ],
            'pt' => [
                'meta_title'       => config('app.name', 'Stocky') . ' — Plataforma de gestão de inventário e estoque',
                'meta_description' => 'Gerencie inventário, armazéns, PDV, compras e vendas em uma única plataforma poderosa. Comece seu teste gratuito hoje.',
                'meta_keywords'    => 'gestão de inventário, gestão de estoque, PDV, armazém, multi-localização, SaaS',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    protected function seedPrivacyPolicy(): void
    {
        $record = LandingPrivacyPolicy::first();
        if (!$record) return;

        $translations = [
            'fr' => [
                'introduction'    => 'Nous nous engageons à protéger votre vie privée. Cette politique de confidentialité explique comment nous collectons, utilisons, divulguons et protégeons vos informations lorsque vous utilisez notre plateforme.',
                'data_collection' => 'Nous collectons les informations que vous fournissez directement, telles que votre nom, adresse e-mail, nom de l\'entreprise et détails de paiement lors de votre inscription ou abonnement. Nous collectons également automatiquement des données d\'utilisation, des informations sur l\'appareil et des cookies.',
                'data_usage'      => 'Nous utilisons les informations collectées pour fournir et maintenir nos services, traiter les transactions, envoyer des notifications importantes, améliorer notre plateforme et respecter nos obligations légales.',
                'cookies_usage'   => 'Nous utilisons des cookies et des technologies de suivi similaires pour améliorer votre expérience de navigation, analyser le trafic du site et personnaliser le contenu. Vous pouvez contrôler les préférences de cookies via les paramètres de votre navigateur ou notre outil de consentement.',
                'third_party'     => 'Nous pouvons partager vos données avec des prestataires de services tiers de confiance qui nous aident à exploiter notre plateforme, traiter les paiements et analyser l\'utilisation. Ces prestataires sont contractuellement tenus de protéger vos données.',
                'data_protection' => 'Nous mettons en œuvre des mesures de sécurité conformes aux normes de l\'industrie, notamment le chiffrement, des serveurs sécurisés et des contrôles d\'accès pour protéger vos données personnelles. Chaque espace de travail dispose de sa propre base de données isolée pour une séparation maximale des données.',
                'user_rights'     => 'Vous avez le droit d\'accéder, de corriger ou de supprimer vos données personnelles. Vous pouvez également vous opposer au traitement, demander la portabilité des données ou retirer votre consentement à tout moment en nous contactant.',
                'contact_info'    => 'Si vous avez des questions concernant cette politique de confidentialité, veuillez nous contacter à :',
            ],
            'ar' => [
                'introduction'    => 'نحن ملتزمون بحماية خصوصيتك. توضح سياسة الخصوصية هذه كيفية جمع معلوماتك واستخدامها والإفصاح عنها وحمايتها عند استخدام منصتنا.',
                'data_collection' => 'نجمع المعلومات التي تقدمها مباشرة، مثل اسمك وعنوان بريدك الإلكتروني واسم الشركة وتفاصيل الدفع عند التسجيل أو الاشتراك. كما نجمع تلقائيًا بيانات الاستخدام ومعلومات الجهاز وملفات تعريف الارتباط.',
                'data_usage'      => 'نستخدم المعلومات التي نجمعها لتقديم خدماتنا وصيانتها، ومعالجة المعاملات، وإرسال إشعارات مهمة، وتحسين منصتنا، والامتثال للالتزامات القانونية.',
                'cookies_usage'   => 'نستخدم ملفات تعريف الارتباط وتقنيات التتبع المماثلة لتحسين تجربة التصفح، وتحليل حركة المرور على الموقع، وتخصيص المحتوى. يمكنك التحكم في تفضيلات ملفات تعريف الارتباط من خلال إعدادات المتصفح أو أداة الموافقة الخاصة بنا.',
                'third_party'     => 'قد نشارك بياناتك مع مزودي خدمات طرف ثالث موثوقين يساعدوننا في تشغيل منصتنا ومعالجة المدفوعات وتحليل الاستخدام. هؤلاء المزودون ملزمون تعاقديًا بحماية بياناتك.',
                'data_protection' => 'نطبق إجراءات أمان متوافقة مع معايير الصناعة بما في ذلك التشفير والخوادم الآمنة وضوابط الوصول لحماية بياناتك الشخصية. يحتوي كل مساحة عمل على قاعدة بيانات معزولة خاصة بها لتحقيق أقصى فصل للبيانات.',
                'user_rights'     => 'لديك الحق في الوصول إلى بياناتك الشخصية أو تصحيحها أو حذفها. يمكنك أيضًا الاعتراض على المعالجة أو طلب نقل البيانات أو سحب الموافقة في أي وقت عن طريق الاتصال بنا.',
                'contact_info'    => 'إذا كانت لديك أي أسئلة حول سياسة الخصوصية هذه، يرجى الاتصال بنا على:',
            ],
            'es' => [
                'introduction'    => 'Nos comprometemos a proteger su privacidad. Esta Política de Privacidad explica cómo recopilamos, usamos, divulgamos y protegemos su información cuando utiliza nuestra plataforma.',
                'data_collection' => 'Recopilamos la información que proporciona directamente, como su nombre, dirección de correo electrónico, nombre de la empresa y detalles de pago al registrarse o suscribirse. También recopilamos automáticamente datos de uso, información del dispositivo y cookies.',
                'data_usage'      => 'Utilizamos la información recopilada para proporcionar y mantener nuestros servicios, procesar transacciones, enviar notificaciones importantes, mejorar nuestra plataforma y cumplir con obligaciones legales.',
                'cookies_usage'   => 'Utilizamos cookies y tecnologías de seguimiento similares para mejorar su experiencia de navegación, analizar el tráfico del sitio y personalizar el contenido. Puede controlar las preferencias de cookies a través de la configuración de su navegador o nuestra herramienta de consentimiento.',
                'third_party'     => 'Podemos compartir sus datos con proveedores de servicios de terceros de confianza que nos ayudan a operar nuestra plataforma, procesar pagos y analizar el uso. Estos proveedores están obligados contractualmente a proteger sus datos.',
                'data_protection' => 'Implementamos medidas de seguridad estándar de la industria, incluyendo cifrado, servidores seguros y controles de acceso para proteger sus datos personales. Cada espacio de trabajo tiene su propia base de datos aislada para máxima separación de datos.',
                'user_rights'     => 'Tiene derecho a acceder, corregir o eliminar sus datos personales. También puede oponerse al procesamiento, solicitar la portabilidad de datos o retirar su consentimiento en cualquier momento contactándonos.',
                'contact_info'    => 'Si tiene alguna pregunta sobre esta Política de Privacidad, contáctenos en:',
            ],
            'hi' => [
                'introduction'    => 'हम आपकी गोपनीयता की रक्षा के लिए प्रतिबद्ध हैं। यह गोपनीयता नीति बताती है कि जब आप हमारे प्लेटफ़ॉर्म का उपयोग करते हैं तो हम आपकी जानकारी कैसे एकत्र, उपयोग, प्रकट और सुरक्षित करते हैं।',
                'data_collection' => 'हम वह जानकारी एकत्र करते हैं जो आप सीधे प्रदान करते हैं, जैसे आपका नाम, ईमेल पता, कंपनी का नाम और पंजीकरण या सदस्यता के समय भुगतान विवरण। हम स्वचालित रूप से उपयोग डेटा, डिवाइस जानकारी और कुकीज़ भी एकत्र करते हैं।',
                'data_usage'      => 'हम एकत्रित जानकारी का उपयोग अपनी सेवाएँ प्रदान करने और बनाए रखने, लेनदेन संसाधित करने, महत्वपूर्ण सूचनाएँ भेजने, अपने प्लेटफ़ॉर्म को बेहतर बनाने और कानूनी दायित्वों का पालन करने के लिए करते हैं।',
                'cookies_usage'   => 'हम आपके ब्राउज़िंग अनुभव को बेहतर बनाने, साइट ट्रैफ़िक का विश्लेषण करने और सामग्री को वैयक्तिकृत करने के लिए कुकीज़ और समान ट्रैकिंग तकनीकों का उपयोग करते हैं। आप अपनी ब्राउज़र सेटिंग्स या हमारे कुकी सहमति टूल के माध्यम से कुकी प्राथमिकताओं को नियंत्रित कर सकते हैं।',
                'third_party'     => 'हम आपके डेटा को विश्वसनीय तृतीय-पक्ष सेवा प्रदाताओं के साथ साझा कर सकते हैं जो हमारे प्लेटफ़ॉर्म को संचालित करने, भुगतान संसाधित करने और उपयोग का विश्लेषण करने में हमारी सहायता करते हैं। ये प्रदाता आपके डेटा की सुरक्षा के लिए अनुबंधित रूप से बाध्य हैं।',
                'data_protection' => 'हम आपके व्यक्तिगत डेटा की सुरक्षा के लिए एन्क्रिप्शन, सुरक्षित सर्वर और एक्सेस नियंत्रण सहित उद्योग-मानक सुरक्षा उपाय लागू करते हैं। प्रत्येक टेनेंट कार्यक्षेत्र में अधिकतम डेटा पृथक्करण के लिए अपना स्वयं का पृथक डेटाबेस होता है।',
                'user_rights'     => 'आपको अपने व्यक्तिगत डेटा तक पहुँचने, उसे सही करने या हटाने का अधिकार है। आप प्रसंस्करण पर आपत्ति भी कर सकते हैं, डेटा पोर्टेबिलिटी का अनुरोध कर सकते हैं, या हमसे संपर्क करके किसी भी समय सहमति वापस ले सकते हैं।',
                'contact_info'    => 'यदि इस गोपनीयता नीति के बारे में आपके कोई प्रश्न हैं, तो कृपया हमसे संपर्क करें:',
            ],
            'bn' => [
                'introduction'    => 'আমরা আপনার গোপনীয়তা রক্ষায় প্রতিশ্রুতিবদ্ধ। এই গোপনীয়তা নীতি ব্যাখ্যা করে যে আপনি আমাদের প্ল্যাটফর্ম ব্যবহার করার সময় আমরা কীভাবে আপনার তথ্য সংগ্রহ, ব্যবহার, প্রকাশ এবং সুরক্ষিত করি।',
                'data_collection' => 'আমরা আপনার সরাসরি প্রদান করা তথ্য সংগ্রহ করি, যেমন আপনার নাম, ইমেল ঠিকানা, কোম্পানির নাম এবং নিবন্ধন বা সাবস্ক্রিপশনের সময় পেমেন্ট বিবরণ। আমরা স্বয়ংক্রিয়ভাবে ব্যবহারের ডেটা, ডিভাইস তথ্য এবং কুকিজও সংগ্রহ করি।',
                'data_usage'      => 'আমরা সংগৃহীত তথ্য আমাদের সেবা প্রদান ও রক্ষণাবেক্ষণ, লেনদেন প্রক্রিয়াকরণ, গুরুত্বপূর্ণ বিজ্ঞপ্তি পাঠানো, আমাদের প্ল্যাটফর্ম উন্নত করা এবং আইনি বাধ্যবাধকতা মেনে চলার জন্য ব্যবহার করি।',
                'cookies_usage'   => 'আমরা আপনার ব্রাউজিং অভিজ্ঞতা উন্নত করতে, সাইট ট্রাফিক বিশ্লেষণ করতে এবং বিষয়বস্তু ব্যক্তিগতকৃত করতে কুকিজ এবং অনুরূপ ট্র্যাকিং প্রযুক্তি ব্যবহার করি। আপনি আপনার ব্রাউজার সেটিংস বা আমাদের কুকি সম্মতি টুলের মাধ্যমে কুকি পছন্দগুলি নিয়ন্ত্রণ করতে পারেন।',
                'third_party'     => 'আমরা বিশ্বস্ত তৃতীয় পক্ষের সেবা প্রদানকারীদের সাথে আপনার ডেটা ভাগ করতে পারি যারা আমাদের প্ল্যাটফর্ম পরিচালনা, পেমেন্ট প্রক্রিয়াকরণ এবং ব্যবহার বিশ্লেষণে সহায়তা করে। এই প্রদানকারীরা আপনার ডেটা সুরক্ষিত রাখতে চুক্তিবদ্ধ।',
                'data_protection' => 'আমরা আপনার ব্যক্তিগত ডেটা সুরক্ষিত করতে এনক্রিপশন, সুরক্ষিত সার্ভার এবং অ্যাক্সেস নিয়ন্ত্রণ সহ শিল্প-মানের নিরাপত্তা ব্যবস্থা প্রয়োগ করি। প্রতিটি টেন্যান্ট ওয়ার্কস্পেসে সর্বাধিক ডেটা পৃথকীকরণের জন্য নিজস্ব বিচ্ছিন্ন ডেটাবেস রয়েছে।',
                'user_rights'     => 'আপনার ব্যক্তিগত ডেটা অ্যাক্সেস, সংশোধন বা মুছে ফেলার অধিকার আছে। আপনি প্রক্রিয়াকরণে আপত্তি জানাতে, ডেটা পোর্টেবিলিটির অনুরোধ করতে বা আমাদের সাথে যোগাযোগ করে যেকোনো সময় সম্মতি প্রত্যাহার করতে পারেন।',
                'contact_info'    => 'এই গোপনীয়তা নীতি সম্পর্কে আপনার কোনো প্রশ্ন থাকলে, অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন:',
            ],
            'tr' => [
                'introduction'    => 'Gizliliğinizi korumaya kararlıyız. Bu Gizlilik Politikası, platformumuzu kullandığınızda bilgilerinizi nasıl topladığımızı, kullandığımızı, ifşa ettiğimizi ve koruduğumuzu açıklar.',
                'data_collection' => 'Kayıt olurken veya abone olurken adınız, e-posta adresiniz, şirket adınız ve ödeme bilgileriniz gibi doğrudan sağladığınız bilgileri toplarız. Ayrıca kullanım verilerini, cihaz bilgilerini ve çerezleri otomatik olarak toplarız.',
                'data_usage'      => 'Toplanan bilgileri hizmetlerimizi sağlamak ve sürdürmek, işlemleri gerçekleştirmek, önemli bildirimler göndermek, platformumuzu iyileştirmek ve yasal yükümlülüklerimizi yerine getirmek için kullanırız.',
                'cookies_usage'   => 'Tarama deneyiminizi geliştirmek, site trafiğini analiz etmek ve içeriği kişiselleştirmek için çerezler ve benzer izleme teknolojileri kullanırız. Çerez tercihlerinizi tarayıcı ayarlarınız veya çerez onay aracımız aracılığıyla kontrol edebilirsiniz.',
                'third_party'     => 'Platformumuzu işletmemize, ödemeleri işlememize ve kullanımı analiz etmemize yardımcı olan güvenilir üçüncü taraf hizmet sağlayıcılarıyla verilerinizi paylaşabiliriz. Bu sağlayıcılar verilerinizi korumakla sözleşmesel olarak yükümlüdür.',
                'data_protection' => 'Kişisel verilerinizi korumak için şifreleme, güvenli sunucular ve erişim kontrolleri dahil endüstri standardı güvenlik önlemleri uyguluyoruz. Her kiracı çalışma alanı, maksimum veri ayrımı için kendi izole veritabanına sahiptir.',
                'user_rights'     => 'Kişisel verilerinize erişme, düzeltme veya silme hakkına sahipsiniz. Ayrıca işlemeye itiraz edebilir, veri taşınabilirliği talep edebilir veya bizimle iletişime geçerek istediğiniz zaman onayınızı geri çekebilirsiniz.',
                'contact_info'    => 'Bu Gizlilik Politikası hakkında herhangi bir sorunuz varsa, lütfen bizimle iletişime geçin:',
            ],
            'de' => [
                'introduction'    => 'Wir verpflichten uns, Ihre Privatsphäre zu schützen. Diese Datenschutzrichtlinie erläutert, wie wir Ihre Informationen erfassen, verwenden, offenlegen und schützen, wenn Sie unsere Plattform nutzen.',
                'data_collection' => 'Wir erfassen Informationen, die Sie direkt angeben, wie Ihren Namen, Ihre E-Mail-Adresse, Ihren Firmennamen und Zahlungsdetails bei der Registrierung oder dem Abonnement. Wir erfassen außerdem automatisch Nutzungsdaten, Geräteinformationen und Cookies.',
                'data_usage'      => 'Wir verwenden die gesammelten Informationen, um unsere Dienste bereitzustellen und zu pflegen, Transaktionen abzuwickeln, wichtige Benachrichtigungen zu senden, unsere Plattform zu verbessern und gesetzliche Verpflichtungen zu erfüllen.',
                'cookies_usage'   => 'Wir verwenden Cookies und ähnliche Tracking-Technologien, um Ihr Browsing-Erlebnis zu verbessern, den Website-Verkehr zu analysieren und Inhalte zu personalisieren. Sie können die Cookie-Einstellungen über Ihre Browsereinstellungen oder unser Cookie-Consent-Tool steuern.',
                'third_party'     => 'Wir können Ihre Daten mit vertrauenswürdigen Drittanbietern teilen, die uns beim Betrieb unserer Plattform, der Zahlungsabwicklung und der Nutzungsanalyse unterstützen. Diese Anbieter sind vertraglich verpflichtet, Ihre Daten zu schützen.',
                'data_protection' => 'Wir implementieren branchenübliche Sicherheitsmaßnahmen einschließlich Verschlüsselung, sichere Server und Zugriffskontrollen zum Schutz Ihrer persönlichen Daten. Jeder Mandanten-Arbeitsbereich verfügt über eine eigene isolierte Datenbank für maximale Datentrennung.',
                'user_rights'     => 'Sie haben das Recht, auf Ihre persönlichen Daten zuzugreifen, diese zu korrigieren oder zu löschen. Sie können auch der Verarbeitung widersprechen, Datenportabilität anfordern oder Ihre Einwilligung jederzeit widerrufen, indem Sie uns kontaktieren.',
                'contact_info'    => 'Wenn Sie Fragen zu dieser Datenschutzrichtlinie haben, kontaktieren Sie uns bitte unter:',
            ],
            'pt' => [
                'introduction'    => 'Estamos comprometidos em proteger sua privacidade. Esta Política de Privacidade explica como coletamos, usamos, divulgamos e protegemos suas informações quando você utiliza nossa plataforma.',
                'data_collection' => 'Coletamos as informações que você fornece diretamente, como seu nome, endereço de e-mail, nome da empresa e detalhes de pagamento ao se registrar ou assinar. Também coletamos automaticamente dados de uso, informações do dispositivo e cookies.',
                'data_usage'      => 'Utilizamos as informações coletadas para fornecer e manter nossos serviços, processar transações, enviar notificações importantes, melhorar nossa plataforma e cumprir obrigações legais.',
                'cookies_usage'   => 'Utilizamos cookies e tecnologias de rastreamento semelhantes para melhorar sua experiência de navegação, analisar o tráfego do site e personalizar o conteúdo. Você pode controlar as preferências de cookies através das configurações do seu navegador ou nossa ferramenta de consentimento.',
                'third_party'     => 'Podemos compartilhar seus dados com provedores de serviços terceirizados confiáveis que nos ajudam a operar nossa plataforma, processar pagamentos e analisar o uso. Esses provedores são contratualmente obrigados a proteger seus dados.',
                'data_protection' => 'Implementamos medidas de segurança padrão da indústria, incluindo criptografia, servidores seguros e controles de acesso para proteger seus dados pessoais. Cada espaço de trabalho possui seu próprio banco de dados isolado para máxima separação de dados.',
                'user_rights'     => 'Você tem o direito de acessar, corrigir ou excluir seus dados pessoais. Também pode se opor ao processamento, solicitar a portabilidade dos dados ou retirar seu consentimento a qualquer momento entrando em contato conosco.',
                'contact_info'    => 'Se você tiver alguma dúvida sobre esta Política de Privacidade, entre em contato conosco:',
            ],
        ];

        $this->applyTranslations($record, $translations);
    }

    /**
     * Merge default strings only where that locale+field is not already present.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $record
     */
    protected function applyTranslations($record, array $localeTranslations): void
    {
        $allowedLocales = config('cms_translation_seeding.locales');
        if (! is_array($allowedLocales)) {
            $allowedLocales = null;
        } elseif ($allowedLocales === []) {
            return;
        }

        $existing = $record->translations ?? [];
        $changed = false;

        foreach ($localeTranslations as $locale => $fields) {
            if ($allowedLocales !== null && ! in_array($locale, $allowedLocales, true)) {
                continue;
            }

            if (! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $localeBucket = $existing[$locale] ?? [];
                if (array_key_exists($field, $localeBucket)) {
                    continue;
                }

                $existing[$locale][$field] = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $record->translations = $existing;
            $record->save();
        }
    }
}
