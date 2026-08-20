<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSetting extends Model
{
    protected $fillable = [
        'note_customer', 'show_logo', 'logo_size', 'show_store_name',
        'show_reference', 'show_date', 'show_seller',
        'show_note', 'show_barcode', 'show_discount', 'show_product_discount', 'show_tax', 'show_shipping', 'show_customer',
        'show_email', 'show_phone', 'show_address', 'is_printable', 'show_Warehouse', 'products_per_page',
        'quick_add_customer', 'barcode_scanning_sound', 'show_product_images',
        'show_stock_quantity', 'enable_hold_sales', 'enable_customer_points', 'show_categories', 'show_brands',
        'allow_overselling',
        'receipt_layout', 'receipt_paper_size',
        'receipt_header_alignment', 'receipt_fiscal_alignment', 'receipt_customer_alignment',
        'receipt_items_alignment', 'receipt_totals_alignment', 'receipt_footer_alignment', 'receipt_qr_alignment',
        'receipt_font_size', 'receipt_density', 'receipt_separator',
        'show_paid', 'show_due', 'show_payments', 'show_zatca_qr',
        'cash_drawer_auto_open', 'cash_drawer_printer_name',
        'direct_network_printing', 'network_printer_ip', 'network_printer_port',
    ];

    protected $casts = [
        'show_logo' => 'integer',
        'show_store_name' => 'integer',
        'show_reference' => 'integer',
        'show_date' => 'integer',
        'show_seller' => 'integer',
        'show_note' => 'integer',
        'show_barcode' => 'integer',
        'show_discount' => 'integer',
        'show_product_discount' => 'integer',
        'show_tax' => 'integer',
        'show_shipping' => 'integer',
        'show_customer' => 'integer',
        'show_Warehouse' => 'integer',
        'show_email' => 'integer',
        'show_phone' => 'integer',
        'show_address' => 'integer',
        'is_printable' => 'integer',
        'products_per_page' => 'integer',
        'quick_add_customer' => 'integer',
        'barcode_scanning_sound' => 'integer',
        'show_product_images' => 'integer',
        'show_stock_quantity' => 'integer',
        'enable_hold_sales' => 'integer',
        'enable_customer_points' => 'integer',
        'show_categories' => 'integer',
        'show_brands' => 'integer',
        'allow_overselling' => 'boolean',
        'receipt_layout' => 'integer',
        'receipt_paper_size' => 'integer',
        'receipt_font_size' => 'integer',
        'logo_size' => 'integer',
        'show_paid' => 'integer',
        'show_due' => 'integer',
        'show_payments' => 'integer',
        'show_zatca_qr' => 'integer',
        'cash_drawer_auto_open' => 'boolean',
        'direct_network_printing' => 'boolean',
        'network_printer_port' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (PosSetting $setting) {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            $alignments = [
                'receipt_header_alignment' => 'center',
                'receipt_fiscal_alignment' => 'center',
                'receipt_customer_alignment' => 'left',
                'receipt_items_alignment' => 'left',
                'receipt_totals_alignment' => 'right',
                'receipt_footer_alignment' => 'center',
                'receipt_qr_alignment' => 'center',
            ];

            foreach ($alignments as $field => $default) {
                if ($request->has($field)) {
                    $value = strtolower(trim((string) $request->input($field)));
                    $setting->{$field} = in_array($value, ['left', 'center', 'right'], true) ? $value : $default;
                }
            }

            if ($request->has('receipt_font_size')) {
                $fontSize = (int) $request->input('receipt_font_size');
                $setting->receipt_font_size = max(8, min(14, $fontSize ?: 10));
            }

            if ($request->has('receipt_density')) {
                $density = strtolower(trim((string) $request->input('receipt_density')));
                $setting->receipt_density = in_array($density, ['compact', 'normal', 'wide'], true) ? $density : 'normal';
            }

            if ($request->has('receipt_separator')) {
                $separator = strtolower(trim((string) $request->input('receipt_separator')));
                $setting->receipt_separator = in_array($separator, ['none', 'solid', 'dotted', 'dashed'], true) ? $separator : 'dotted';
            }

            // The legacy controller historically accepted only layouts 1-4.
            // Keep layout 5 working without duplicating the whole settings endpoint.
            if ($request->has('receipt_layout')) {
                $layout = (int) $request->input('receipt_layout');
                if (in_array($layout, [1, 2, 3, 4, 5], true)) {
                    $setting->receipt_layout = $layout;
                }
            }
        });
    }
}
