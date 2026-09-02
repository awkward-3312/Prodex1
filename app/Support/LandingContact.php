<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deriva el destino de "Hablar con ventas" a partir del CMS de la landing
 * (landing_cta.sales_button_url > footer WhatsApp > footer email). Fuente única
 * para el server-render de landing-prime y para el endpoint de la calculadora,
 * de modo que ambos coincidan siempre.
 */
final class LandingContact
{
    public static function salesUrl(?object $footer = null, ?object $cta = null): ?string
    {
        $explicit = $cta->sales_button_url ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $whatsappRaw = $footer->sales_whatsapp_number ?? $footer->contact_phone ?? null;
        $whatsappDigits = $whatsappRaw ? preg_replace('/\D+/', '', (string) $whatsappRaw) : null;
        if ($whatsappDigits) {
            $message = ($footer->sales_whatsapp_message ?? null)
                ?: 'Hola, me interesa conocer más sobre Prodex y sus planes.';

            return 'https://wa.me/' . $whatsappDigits . '?text=' . rawurlencode($message);
        }

        $email = $footer->sales_email ?? $footer->contact_email ?? null;
        if (is_string($email) && $email !== '') {
            return 'mailto:' . $email . '?subject=' . rawurlencode('Consulta comercial Prodex');
        }

        return null;
    }

    /** ¿El href abre una app externa (WhatsApp)? Para decidir target="_blank". */
    public static function isExternal(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://wa.me/');
    }
}
