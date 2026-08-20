<?php

namespace App\Services;

class SarInvoiceSettings
{
    public static function defaults(): array
    {
        return [
            'document_title' => 'FACTURA',
            'sale_type_label' => 'CONTADO',
            'website' => '',
            'footer_message' => 'Gracias por su compra.',
            'original_label' => 'Original: Cliente',
            'copy_label' => 'Copia: Obligado Tributario Emisor',
            'show_logo' => true,
            'show_internal_reference' => true,
            'show_cashier' => true,
            'show_warehouse' => true,
            'show_payment_summary' => true,
            'show_customer_address' => true,
            'show_item_code' => true,
            'show_total_in_words' => true,
            'show_qr' => true,
        ];
    }

    public static function merge($settings): array
    {
        return array_merge(self::defaults(), is_array($settings) ? $settings : []);
    }
}
