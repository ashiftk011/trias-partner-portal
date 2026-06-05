<?php
/**
 * Supported currencies for Quotations & Invoices.
 * Format: 'ISO_CODE' => ['name' => '...', 'symbol' => '...']
 * Add more entries here to extend support.
 */
define('CURRENCIES', [
    'INR' => ['name' => 'Indian Rupee',     'symbol' => '₹'],
    'USD' => ['name' => 'US Dollar',         'symbol' => '$'],
    'AED' => ['name' => 'UAE Dirham',        'symbol' => 'AED'],
    'SAR' => ['name' => 'Saudi Riyal',       'symbol' => 'SAR'],
    'EUR' => ['name' => 'Euro',              'symbol' => '€'],
    'GBP' => ['name' => 'British Pound',     'symbol' => '£'],
    'OMR' => ['name' => 'Omani Rial',        'symbol' => 'OMR'],
    'QAR' => ['name' => 'Qatari Riyal',      'symbol' => 'QAR'],
    'KWD' => ['name' => 'Kuwaiti Dinar',     'symbol' => 'KWD'],
    'BHD' => ['name' => 'Bahraini Dinar',    'symbol' => 'BHD'],
]);

/**
 * Return the currency symbol for a given ISO code.
 * Falls back to the code itself if not found.
 */
function currencySymbol(string $code): string {
    return CURRENCIES[$code]['symbol'] ?? $code;
}

/**
 * Return the currency name for a given ISO code.
 */
function currencyName(string $code): string {
    return CURRENCIES[$code]['name'] ?? $code;
}
