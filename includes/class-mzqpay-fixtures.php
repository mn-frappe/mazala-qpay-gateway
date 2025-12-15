<?php
/**
 * Mazala QPay Fixtures - Centralized data provider for qPay API reference data
 * 
 * Loads and provides access to:
 * - Bank codes (Mongolian banks)
 * - District/Branch codes (Mongolia regions and sub-regions)
 * - GS1 Classification codes (product categories for eBarimt)
 * - Currency codes
 * - VAT codes (VAT free and zero-rated products)
 * - Error messages (qPay API errors in EN/MN)
 * 
 * @package MazalaQPay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MZQPay_Fixtures {
    
    /** @var array|null Cached parsed JSON data */
    private static $data = null;
    
    /** @var array Bank codes indexed by code */
    private static $banks = null;
    
    /** @var array Districts indexed by code */
    private static $districts = null;
    
    /** @var array GS1 codes indexed by code */
    private static $gs1_codes = null;
    
    /** @var array GS1 codes indexed by normalized name for fuzzy matching */
    private static $gs1_by_name = null;
    
    /** @var array GS1 token index for partial matching */
    private static $gs1_tokens = null;
    
    /** @var array Currency codes */
    private static $currencies = null;
    
    /** @var array VAT exempt codes */
    private static $vat_exempt = null;
    
    /** @var array VAT zero codes */
    private static $vat_zero = null;
    
    /** @var array Error messages indexed by key */
    private static $errors = null;
    
    /** @var array HTTP status codes with descriptions */
    private static $http_codes = null;

    /**
     * Get the path to the parsed JSON file
     */
    private static function get_json_path() {
        $paths = array();
        
        // Try plugin dir relative path first
        if (defined('MZQPAY_PLUGIN_DIR')) {
            $paths[] = MZQPAY_PLUGIN_DIR . '../docs/QPayAPIv2_parsed.json';
            $paths[] = dirname(MZQPAY_PLUGIN_DIR) . '/docs/QPayAPIv2_parsed.json';
        }
        
        // WP_PLUGIN_DIR if available
        if (defined('WP_PLUGIN_DIR')) {
            $paths[] = WP_PLUGIN_DIR . '/docs/QPayAPIv2_parsed.json';
        }
        
        // Try relative to this file
        $paths[] = dirname(__DIR__) . '/../docs/QPayAPIv2_parsed.json';
        $paths[] = dirname(dirname(__DIR__)) . '/docs/QPayAPIv2_parsed.json';
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }
        
        return null;
    }

    /**
     * Load and parse the JSON data file
     */
    private static function load_data() {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = self::get_json_path();
        if (!$path) {
            self::$data = array();
            return self::$data;
        }

        $raw = file_get_contents($path);
        $json = json_decode($raw, true);
        
        if (!is_array($json)) {
            self::$data = array();
            return self::$data;
        }

        self::$data = $json;
        return self::$data;
    }

    /**
     * Normalize a string for matching (lowercase, remove punctuation)
     */
    private static function normalize($str) {
        $str = mb_strtolower(trim($str));
        $str = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    // =========================================================================
    // BANK CODES
    // =========================================================================

    /**
     * Get all bank codes
     * @return array Array of bank data: ['code' => ['code', 'name_en', 'name_mn']]
     */
    public static function get_banks() {
        if (self::$banks !== null) {
            return self::$banks;
        }

        self::load_data();
        self::$banks = array();

        $rows = isset(self::$data['bank_code']) ? self::$data['bank_code'] : array();
        
        foreach ($rows as $i => $row) {
            if ($i === 0 || !is_array($row) || count($row) < 4) continue; // Skip header
            
            $code = isset($row[1]) ? trim(strval($row[1])) : '';
            $name_en = isset($row[2]) ? trim(strval($row[2])) : '';
            $name_mn = isset($row[3]) ? trim(strval($row[3])) : '';
            
            if (empty($code)) continue;
            
            self::$banks[$code] = array(
                'code' => $code,
                'name_en' => $name_en,
                'name_mn' => $name_mn,
            );
        }

        return self::$banks;
    }

    /**
     * Get bank by code
     * @param string $code Bank code
     * @return array|null Bank data or null if not found
     */
    public static function get_bank($code) {
        $banks = self::get_banks();
        return isset($banks[$code]) ? $banks[$code] : null;
    }

    /**
     * Get banks as options for select dropdowns
     * @param string $lang Language ('en' or 'mn')
     * @return array ['code' => 'name']
     */
    public static function get_banks_options($lang = 'en') {
        $banks = self::get_banks();
        $options = array('' => __('Select Bank', 'mazala-qpay-gateway'));
        
        foreach ($banks as $code => $bank) {
            $name = ($lang === 'mn' && !empty($bank['name_mn'])) ? $bank['name_mn'] : $bank['name_en'];
            $options[$code] = $name . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_banks_options
     * @param string $lang Language ('en' or 'mn')
     * @return array
     */
    public static function get_bank_dropdown($lang = 'en') {
        return self::get_banks_options($lang);
    }

    // =========================================================================
    // DISTRICT/BRANCH CODES
    // =========================================================================

    /**
     * Get all districts with their sub-branches
     * @return array ['branch_code' => ['name', 'code', 'sub_branches' => [...]]]
     */
    public static function get_districts() {
        if (self::$districts !== null) {
            return self::$districts;
        }

        self::load_data();
        self::$districts = array();

        $rows = isset(self::$data['district_code']) ? self::$data['district_code'] : array();
        
        foreach ($rows as $i => $row) {
            if ($i === 0 || !is_array($row) || count($row) < 5) continue; // Skip header
            
            $branch_name = isset($row[1]) ? trim(strval($row[1])) : '';
            $branch_code = isset($row[2]) ? trim(strval($row[2])) : '';
            $sub_name = isset($row[3]) ? trim(strval($row[3])) : '';
            $sub_code = isset($row[4]) ? trim(strval($row[4])) : '';
            
            if (empty($branch_code)) continue;
            
            if (!isset(self::$districts[$branch_code])) {
                self::$districts[$branch_code] = array(
                    'name' => $branch_name,
                    'code' => $branch_code,
                    'sub_branches' => array(),
                );
            }
            
            if (!empty($sub_code)) {
                $full_code = $branch_code . sprintf('%02d', intval($sub_code));
                self::$districts[$branch_code]['sub_branches'][$sub_code] = array(
                    'name' => $sub_name,
                    'code' => $sub_code,
                    'full_code' => $full_code,
                );
            }
        }

        // Sort by branch code
        ksort(self::$districts);

        return self::$districts;
    }

    /**
     * Get districts as options for select dropdowns
     * @return array ['code' => 'name']
     */
    public static function get_districts_options() {
        $districts = self::get_districts();
        $options = array('' => __('Select District', 'mazala-qpay-gateway'));
        
        foreach ($districts as $code => $district) {
            $options[$code] = $district['name'] . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_districts_options
     * @param string $lang Language (not used, for API consistency)
     * @return array
     */
    public static function get_district_dropdown($lang = 'en') {
        return self::get_districts_options();
    }

    /**
     * Get sub-branches for a district
     * @param string $district_code District/branch code
     * @return array Sub-branch options
     */
    public static function get_sub_branches($district_code) {
        $districts = self::get_districts();
        
        if (!isset($districts[$district_code])) {
            return array();
        }

        $options = array('' => __('Select Sub-branch', 'mazala-qpay-gateway'));
        
        foreach ($districts[$district_code]['sub_branches'] as $sub) {
            $options[$sub['full_code']] = $sub['name'] . ' (' . $sub['code'] . ')';
        }

        return $options;
    }

    /**
     * Get full district code from branch + sub-branch
     * @param string $branch_code Branch code
     * @param string $sub_code Sub-branch code
     * @return string Full 4-digit district code
     */
    public static function get_full_district_code($branch_code, $sub_code) {
        return $branch_code . sprintf('%02d', intval($sub_code));
    }

    // =========================================================================
    // GS1 CLASSIFICATION CODES
    // =========================================================================

    /**
     * Parse and index GS1 codes from the raw data
     */
    private static function parse_gs1_codes() {
        if (self::$gs1_codes !== null) {
            return;
        }

        self::load_data();
        self::$gs1_codes = array();
        self::$gs1_by_name = array();
        self::$gs1_tokens = array();

        $rows = isset(self::$data['GS1']) ? self::$data['GS1'] : array();
        
        $current_category = '';
        $current_subcategory = '';
        
        foreach ($rows as $i => $row) {
            if ($i < 2 || !is_array($row)) continue; // Skip header rows
            
            // GS1 data structure: [null, L1, L2, L3, L4, code, description] 
            // The structure varies - codes can be at different positions
            
            // Find the code and description
            $code = '';
            $description = '';
            
            // Check column 5 (index 5) for 7-digit codes
            if (isset($row[5]) && !empty($row[5]) && preg_match('/^\d{5,7}$/', trim(strval($row[5])))) {
                $code = trim(strval($row[5]));
            }
            // Also check column 4 for shorter codes
            if (empty($code) && isset($row[4]) && !empty($row[4]) && preg_match('/^\d{5}$/', trim(strval($row[4])))) {
                $code = trim(strval($row[4]));
            }
            
            // Get description from column 6 or find the last non-empty text column
            if (isset($row[6]) && !empty($row[6]) && !preg_match('/^\d+$/', trim(strval($row[6])))) {
                $description = trim(strval($row[6]));
            } else {
                // Find description in other columns
                for ($j = count($row) - 1; $j >= 1; $j--) {
                    if (isset($row[$j]) && !empty($row[$j]) && !preg_match('/^\d+$/', trim(strval($row[$j])))) {
                        $description = trim(strval($row[$j]));
                        break;
                    }
                }
            }
            
            // Track category hierarchy for context
            if (isset($row[1]) && !empty($row[1]) && preg_match('/^\d{2}$/', trim(strval($row[1])))) {
                $current_category = isset($row[6]) ? trim(strval($row[6])) : $description;
            }
            if (isset($row[2]) && !empty($row[2]) && preg_match('/^\d{3}$/', trim(strval($row[2])))) {
                $current_subcategory = isset($row[6]) ? trim(strval($row[6])) : $description;
            }
            
            if (empty($code) || empty($description)) continue;
            
            $entry = array(
                'code' => $code,
                'name' => $description,
                'category' => $current_category,
                'subcategory' => $current_subcategory,
            );
            
            self::$gs1_codes[$code] = $entry;
            
            // Index by normalized name
            $normalized = self::normalize($description);
            if (!empty($normalized)) {
                self::$gs1_by_name[$normalized] = $entry;
                
                // Token index for partial matching
                $tokens = preg_split('/\s+/', $normalized);
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 3) continue;
                    if (!isset(self::$gs1_tokens[$token])) {
                        self::$gs1_tokens[$token] = array();
                    }
                    self::$gs1_tokens[$token][] = $entry;
                }
            }
        }
    }

    /**
     * Get all GS1 codes
     * @return array ['code' => ['code', 'name', 'category', 'subcategory']]
     */
    public static function get_gs1_codes() {
        self::parse_gs1_codes();
        return self::$gs1_codes;
    }

    /**
     * Get GS1 code by exact code
     * @param string $code GS1 code
     * @return array|null GS1 entry or null
     */
    public static function get_gs1_code($code) {
        self::parse_gs1_codes();
        return isset(self::$gs1_codes[$code]) ? self::$gs1_codes[$code] : null;
    }

    /**
     * Search GS1 codes by product name/SKU
     * @param string $sku Product SKU
     * @param string $name Product name
     * @return array|null Best matching GS1 entry or null
     */
    public static function find_gs1_code($sku, $name) {
        self::parse_gs1_codes();
        
        // 1. Try exact SKU match
        if (!empty($sku)) {
            $sku = trim(strval($sku));
            if (isset(self::$gs1_codes[$sku])) {
                return self::$gs1_codes[$sku];
            }
        }
        
        // 2. Try exact name match
        if (!empty($name)) {
            $normalized = self::normalize($name);
            if (isset(self::$gs1_by_name[$normalized])) {
                return self::$gs1_by_name[$normalized];
            }
            
            // 3. Token-based matching with scoring
            $tokens = preg_split('/\s+/', $normalized);
            $scores = array();
            
            foreach ($tokens as $token) {
                if (mb_strlen($token) < 3) continue;
                if (isset(self::$gs1_tokens[$token])) {
                    foreach (self::$gs1_tokens[$token] as $entry) {
                        $code = $entry['code'];
                        if (!isset($scores[$code])) {
                            $scores[$code] = array('entry' => $entry, 'score' => 0);
                        }
                        $scores[$code]['score']++;
                    }
                }
            }
            
            if (!empty($scores)) {
                // Sort by score descending
                uasort($scores, function($a, $b) {
                    return $b['score'] - $a['score'];
                });
                $best = reset($scores);
                return $best['entry'];
            }
        }
        
        return null;
    }

    /**
     * Get GS1 codes as hierarchical options for select
     * @return array Hierarchical options
     */
    public static function get_gs1_options() {
        self::parse_gs1_codes();
        
        $options = array('' => __('Select Classification', 'mazala-qpay-gateway'));
        
        foreach (self::$gs1_codes as $code => $entry) {
            $label = $entry['name'];
            if (!empty($entry['category'])) {
                $label = $entry['category'] . ' > ' . $label;
            }
            $options[$code] = $label . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_gs1_options
     * @return array
     */
    public static function get_gs1_dropdown() {
        return self::get_gs1_options();
    }

    // =========================================================================
    // CURRENCY CODES
    // =========================================================================

    /**
     * Get all supported currencies
     * @return array ['code' => ['code', 'name']]
     */
    public static function get_currencies() {
        if (self::$currencies !== null) {
            return self::$currencies;
        }

        self::load_data();
        self::$currencies = array();

        $rows = isset(self::$data['currency_code']) ? self::$data['currency_code'] : array();
        
        foreach ($rows as $i => $row) {
            if ($i === 0 || !is_array($row) || count($row) < 3) continue;
            
            $code = isset($row[1]) ? trim(strval($row[1])) : '';
            $name = isset($row[2]) ? trim(strval($row[2])) : '';
            
            if (empty($code)) continue;
            
            self::$currencies[$code] = array(
                'code' => $code,
                'name' => $name,
            );
        }

        return self::$currencies;
    }

    /**
     * Get currencies as options for select
     * @return array ['code' => 'name']
     */
    public static function get_currencies_options() {
        $currencies = self::get_currencies();
        $options = array();
        
        foreach ($currencies as $code => $currency) {
            $options[$code] = $currency['name'] . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_currencies_options
     * @return array
     */
    public static function get_currency_dropdown() {
        return self::get_currencies_options();
    }

    /**
     * Check if a currency is supported by qPay
     * @param string $code Currency code
     * @return bool
     */
    public static function is_currency_supported($code) {
        if (empty($code)) return false;
        $currencies = self::get_currencies();
        $code_upper = strtoupper(trim($code));
        return isset($currencies[$code_upper]);
    }

    // =========================================================================
    // VAT CODES (VAT FREE & ZERO RATED)
    // =========================================================================

    /**
     * Parse VAT codes from the data
     */
    private static function parse_vat_codes() {
        if (self::$vat_zero !== null) {
            return;
        }

        self::load_data();
        self::$vat_zero = array();
        self::$vat_exempt = array();

        $rows = isset(self::$data['Vat Free, Zero']) ? self::$data['Vat Free, Zero'] : array();
        
        foreach ($rows as $i => $row) {
            if ($i < 2 || !is_array($row)) continue; // Skip headers
            
            // VAT Zero (0%) - columns 1-3
            if (isset($row[1]) && is_numeric($row[1]) && isset($row[2]) && isset($row[3])) {
                $code = trim(strval($row[3]));
                $name = trim(strval($row[2]));
                if (!empty($code) && preg_match('/^\d{3}$/', $code)) {
                    self::$vat_zero[$code] = array(
                        'code' => $code,
                        'name' => $name,
                        'type' => 'zero',
                    );
                }
            }
            
            // VAT Exempt (Free) - columns 7-9
            if (isset($row[7]) && is_numeric($row[7]) && isset($row[8]) && isset($row[9])) {
                $code = trim(strval($row[9]));
                $name = trim(strval($row[8]));
                if (!empty($code) && preg_match('/^\d{3}$/', $code)) {
                    self::$vat_exempt[$code] = array(
                        'code' => $code,
                        'name' => $name,
                        'type' => 'exempt',
                    );
                }
            }
        }
    }

    /**
     * Get VAT zero-rated product codes
     * @return array ['code' => ['code', 'name', 'type']]
     */
    public static function get_vat_zero_codes() {
        self::parse_vat_codes();
        return self::$vat_zero;
    }

    /**
     * Get VAT exempt product codes
     * @return array ['code' => ['code', 'name', 'type']]
     */
    public static function get_vat_exempt_codes() {
        self::parse_vat_codes();
        return self::$vat_exempt;
    }

    /**
     * Get all VAT special codes (zero + exempt)
     * @return array Combined array
     */
    public static function get_all_vat_codes() {
        self::parse_vat_codes();
        return array_merge(self::$vat_zero, self::$vat_exempt);
    }

    /**
     * Get VAT type for a product code
     * @param string $code VAT code
     * @return int Tax type: 1=VAT taxable, 2=VAT free, 3=VAT exempt
     */
    public static function get_vat_type($code) {
        self::parse_vat_codes();
        
        if (isset(self::$vat_zero[$code])) {
            return 2; // НӨАТ-гүй (VAT free / 0%)
        }
        if (isset(self::$vat_exempt[$code])) {
            return 3; // НӨАТ-аас чөлөөлөгдөх (VAT exempt)
        }
        
        return 1; // НӨАТ тооцогдох (VAT taxable - default)
    }

    /**
     * Get VAT codes as options
     * @return array
     */
    public static function get_vat_options() {
        self::parse_vat_codes();
        
        $options = array(
            '' => __('Standard VAT (10%)', 'mazala-qpay-gateway'),
        );
        
        $options['-- ' . __('VAT 0%', 'mazala-qpay-gateway') . ' --'] = array();
        foreach (self::$vat_zero as $code => $vat) {
            $options[$code] = $vat['name'] . ' (' . $code . ')';
        }
        
        $options['-- ' . __('VAT Exempt', 'mazala-qpay-gateway') . ' --'] = array();
        foreach (self::$vat_exempt as $code => $vat) {
            $options[$code] = $vat['name'] . ' (' . $code . ')';
        }

        return $options;
    }

    // =========================================================================
    // ERROR MESSAGES
    // =========================================================================

    /**
     * Parse error messages from data
     */
    private static function parse_errors() {
        if (self::$errors !== null) {
            return;
        }

        self::load_data();
        self::$errors = array();
        self::$http_codes = array();

        $rows = isset(self::$data['error_message']) ? self::$data['error_message'] : array();
        
        $in_http_section = true;
        
        foreach ($rows as $i => $row) {
            if ($i === 0 || !is_array($row) || count($row) < 3) continue;
            
            // Check if this is the header row for error keys
            if (isset($row[1]) && $row[1] === 'KEY') {
                $in_http_section = false;
                continue;
            }
            
            if ($in_http_section) {
                // HTTP status codes section
                $code = isset($row[1]) ? trim(strval($row[1])) : '';
                $key = isset($row[2]) ? trim(strval($row[2])) : '';
                $desc_mn = isset($row[3]) ? trim(strval($row[3])) : '';
                
                if (!empty($code) && is_numeric($code)) {
                    self::$http_codes[$code] = array(
                        'code' => $code,
                        'key' => $key,
                        'message_mn' => $desc_mn,
                        'message_en' => $key,
                    );
                }
            } else {
                // Error keys section
                $key = isset($row[1]) ? trim(strval($row[1])) : '';
                $msg_mn = isset($row[2]) ? trim(strval($row[2])) : '';
                $msg_en = isset($row[3]) ? trim(strval($row[3])) : '';
                
                if (!empty($key)) {
                    self::$errors[$key] = array(
                        'key' => $key,
                        'message_mn' => $msg_mn,
                        'message_en' => $msg_en,
                    );
                }
            }
        }
    }

    /**
     * Get all error messages
     * @return array ['key' => ['key', 'message_mn', 'message_en']]
     */
    public static function get_errors() {
        self::parse_errors();
        return self::$errors;
    }

    /**
     * Get error message by key
     * @param string $key Error key
     * @param string $lang Language ('en' or 'mn')
     * @return string|null Error message or null if not found
     */
    public static function get_error_message($key, $lang = 'en') {
        self::parse_errors();
        
        if (empty($key) || !isset(self::$errors[$key])) {
            return null;
        }
        
        $error = self::$errors[$key];
        return ($lang === 'mn' && !empty($error['message_mn'])) 
            ? $error['message_mn'] 
            : $error['message_en'];
    }

    /**
     * Get HTTP status codes
     * @return array
     */
    public static function get_http_codes() {
        self::parse_errors();
        return self::$http_codes;
    }

    /**
     * Alias for get_errors
     * @return array
     */
    public static function get_error_messages() {
        return self::get_errors();
    }

    /**
     * Alias for get_http_codes
     * @return array
     */
    public static function get_http_status_codes() {
        return self::get_http_codes();
    }

    /**
     * Translate a qPay API error response
     * @param mixed $response API response (array or string)
     * @param string $lang Language
     * @return string Human-readable error message
     */
    public static function translate_error($response, $lang = 'en') {
        // Handle direct string error codes
        if (is_string($response)) {
            // Try to decode as JSON first
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $response = $decoded;
            } else {
                // Try direct lookup
                $msg = self::get_error_message($response, $lang);
                return $msg !== null ? $msg : $response;
            }
        }
        
        if (!is_array($response)) {
            return strval($response);
        }
        
        // Check for error key in response
        $error_key = null;
        if (isset($response['error'])) {
            $error_key = $response['error'];
        } elseif (isset($response['message'])) {
            $error_key = $response['message'];
        } elseif (isset($response['code'])) {
            $error_key = $response['code'];
        }
        
        if ($error_key) {
            $msg = self::get_error_message($error_key, $lang);
            return $msg !== null ? $msg : $error_key;
        }
        
        return json_encode($response);
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Get statistics about loaded fixtures
     * @return array
     */
    public static function get_stats() {
        return array(
            'banks' => count(self::get_banks()),
            'districts' => count(self::get_districts()),
            'gs1_codes' => count(self::get_gs1_codes()),
            'currencies' => count(self::get_currencies()),
            'vat_zero' => count(self::get_vat_zero_codes()),
            'vat_exempt' => count(self::get_vat_exempt_codes()),
            'errors' => count(self::get_errors()),
            'data_loaded' => self::$data !== null,
            'json_path' => self::get_json_path(),
        );
    }

    /**
     * Clear cached data (useful for testing)
     */
    public static function clear_cache() {
        self::$data = null;
        self::$banks = null;
        self::$districts = null;
        self::$gs1_codes = null;
        self::$gs1_by_name = null;
        self::$gs1_tokens = null;
        self::$currencies = null;
        self::$vat_exempt = null;
        self::$vat_zero = null;
        self::$errors = null;
        self::$http_codes = null;
    }

    /**
     * Check if fixtures are available
     * @return bool
     */
    public static function is_available() {
        return self::get_json_path() !== null;
    }

    // =========================================================================
    // QPAY API STATUS CODES & ADDITIONAL FIXTURES
    // =========================================================================

    /**
     * Get invoice statuses
     * @return array Invoice status codes with descriptions
     */
    public static function get_invoice_statuses() {
        return array(
            'OPEN' => array(
                'name_en' => 'Open',
                'name_mn' => 'Нээлттэй',
                'description' => 'Invoice is open and awaiting payment'
            ),
            'CLOSED' => array(
                'name_en' => 'Closed',
                'name_mn' => 'Хаагдсан',
                'description' => 'Invoice has been paid'
            ),
            'CANCELLED' => array(
                'name_en' => 'Cancelled',
                'name_mn' => 'Цуцлагдсан',
                'description' => 'Invoice has been cancelled'
            ),
            'EXPIRED' => array(
                'name_en' => 'Expired',
                'name_mn' => 'Хугацаа дууссан',
                'description' => 'Invoice has expired'
            ),
            'PARTIAL' => array(
                'name_en' => 'Partial',
                'name_mn' => 'Хэсэгчилсэн',
                'description' => 'Invoice is partially paid'
            ),
        );
    }

    /**
     * Get payment statuses
     * @return array Payment status codes with descriptions
     */
    public static function get_payment_statuses() {
        return array(
            'NEW' => array(
                'name_en' => 'New',
                'name_mn' => 'Шинэ',
                'description' => 'Payment is initiated'
            ),
            'PAID' => array(
                'name_en' => 'Paid',
                'name_mn' => 'Төлөгдсөн',
                'description' => 'Payment completed successfully'
            ),
            'FAILED' => array(
                'name_en' => 'Failed',
                'name_mn' => 'Амжилтгүй',
                'description' => 'Payment failed'
            ),
            'REFUNDED' => array(
                'name_en' => 'Refunded',
                'name_mn' => 'Буцаагдсан',
                'description' => 'Payment has been refunded'
            ),
            'CANCELLED' => array(
                'name_en' => 'Cancelled',
                'name_mn' => 'Цуцлагдсан',
                'description' => 'Payment was cancelled'
            ),
            'PENDING' => array(
                'name_en' => 'Pending',
                'name_mn' => 'Хүлээгдэж байна',
                'description' => 'Payment is pending confirmation'
            ),
        );
    }

    /**
     * Get tax product codes (G1s) for eBarimt
     * Alias for get_gs1_codes
     * @return array
     */
    public static function get_tax_product_codes() {
        return self::get_gs1_codes();
    }

    /**
     * Get wallet deep links for bank apps
     * @return array Bank app deep links for QR payment
     */
    public static function get_wallet_deep_links() {
        return array(
            'KHAN' => array(
                'name' => 'Khan Bank',
                'android' => 'khanbank://qpay',
                'ios' => 'khanbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/khan-bank/id1085135055',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.slide.bank.khan'
            ),
            'GOLOMT' => array(
                'name' => 'Golomt Bank',
                'android' => 'golomtbank://qpay',
                'ios' => 'golomtbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/golomt-mobile-bank/id955827679',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.golomtbank.android'
            ),
            'TDB' => array(
                'name' => 'Trade and Development Bank',
                'android' => 'tdbmbank://qpay',
                'ios' => 'tdbmbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/tdbm-bank/id1124665234',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.tdb.digitalbank'
            ),
            'KHAS' => array(
                'name' => 'Khas Bank',
                'android' => 'khasbank://qpay',
                'ios' => 'khasbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/khas-bank/id1234567890',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.khasbank.mobile'
            ),
            'STATE' => array(
                'name' => 'State Bank',
                'android' => 'statebank://qpay',
                'ios' => 'statebank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/state-bank/id1234567891',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.statebank.mobile'
            ),
            'XACBANK' => array(
                'name' => 'XacBank',
                'android' => 'xacbank://qpay',
                'ios' => 'xacbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/xacbank/id1087543206',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.xacbank.mobile'
            ),
            'MOST' => array(
                'name' => 'Most Money',
                'android' => 'mostmoney://qpay',
                'ios' => 'mostmoney://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/most-money/id1234567894',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.mostmoney.app'
            ),
            'MONPAY' => array(
                'name' => 'Monpay',
                'android' => 'monpay://qpay',
                'ios' => 'monpay://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/monpay/id1182558498',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.fintech.monpay'
            ),
            'SOCIALPAY' => array(
                'name' => 'SocialPay',
                'android' => 'socialpay://qpay',
                'ios' => 'socialpay://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/socialpay/id1463627665',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.golomtbank.socialpay'
            ),
        );
    }

    /**
     * Get currency codes
     * Alias for get_currencies
     * @return array
     */
    public static function get_currency_codes() {
        return self::get_currencies();
    }

    /**
     * Get eBarimt receiver types
     * @return array Receiver types for eBarimt
     */
    public static function get_receiver_types() {
        return array(
            'CITIZEN' => array(
                'name_en' => 'Individual/Citizen',
                'name_mn' => 'Иргэн',
                'description' => 'Individual person tax receipt'
            ),
            'COMPANY' => array(
                'name_en' => 'Company/Business',
                'name_mn' => 'Байгууллага',
                'description' => 'Business entity tax receipt'
            ),
            'FOREIGN' => array(
                'name_en' => 'Foreign Entity',
                'name_mn' => 'Гадаад байгууллага',
                'description' => 'Foreign company or individual'
            ),
        );
    }

    /**
     * Alias for get_errors - for compatibility
     * @return array
     */
    public static function get_error_codes() {
        return self::get_errors();
    }
}
