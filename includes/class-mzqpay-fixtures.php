<?php
/**
 * Mazala QPay Fixtures - Centralized data provider for qPay API reference data
 * 
 * Provides access to embedded reference data:
 * - Bank codes (21 Mongolian banks with EN/MN names)
 * - District/Branch codes (506 sub-branches across 30 branches)
 * - GS1 Classification codes (~3,730 product categories for eBarimt)
 * - Currency codes (6 supported: MNT, USD, CNY, JPY, RUB, EUR)
 * - VAT codes (~44 codes for 0% and exempt rates)
 * - Error messages (61 codes with bilingual support)
 * 
 * @package MazalaQPay
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load embedded data classes
require_once dirname( __FILE__ ) . '/class-mzqpay-fixtures-data.php';
require_once dirname( __FILE__ ) . '/class-mzqpay-gs1-data.php';

class MZQPay_Fixtures {
    
    /** @var array|null Cached bank data */
    private static $banks = null;
    
    /** @var array|null Cached districts data */
    private static $districts = null;
    
    /** @var array|null Cached GS1 codes indexed by code */
    private static $gs1_codes = null;
    
    /** @var array|null GS1 codes indexed by normalized name for fuzzy matching */
    private static $gs1_by_name = null;
    
    /** @var array|null GS1 token index for partial matching */
    private static $gs1_tokens = null;
    
    /** @var array|null Cached currency data */
    private static $currencies = null;
    
    /** @var array|null Cached VAT exempt codes */
    private static $vat_exempt = null;
    
    /** @var array|null Cached VAT zero codes */
    private static $vat_zero = null;
    
    /** @var array|null Cached error messages */
    private static $errors = null;
    
    /** @var array|null Cached HTTP status codes */
    private static $http_codes = null;

    /**
     * Normalize a string for matching (lowercase, remove punctuation)
     * @param string $str Input string
     * @return string Normalized string
     */
    private static function normalize( $str ) {
        $str = mb_strtolower( trim( $str ) );
        $str = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $str );
        $str = preg_replace( '/\s+/', ' ', $str );
        return trim( $str );
    }

    // =========================================================================
    // BANK CODES (21 banks)
    // =========================================================================

    /**
     * Get all bank codes
     * @return array Array of bank data: ['code' => ['code', 'name_en', 'name_mn']]
     */
    public static function get_banks() {
        if ( self::$banks === null ) {
            self::$banks = MZQPay_Fixtures_Data::get_bank_data();
        }
        return self::$banks;
    }

    /**
     * Get bank by code
     * @param string $code Bank code
     * @return array|null Bank data or null if not found
     */
    public static function get_bank( $code ) {
        $banks = self::get_banks();
        return isset( $banks[ $code ] ) ? $banks[ $code ] : null;
    }

    /**
     * Get banks as options for select dropdowns
     * @param string $lang Language ('en' or 'mn')
     * @return array ['code' => 'name']
     */
    public static function get_banks_options( $lang = 'en' ) {
        $banks = self::get_banks();
        $options = array( '' => __( 'Select Bank', 'qpay-gateway' ) );
        
        foreach ( $banks as $code => $bank ) {
            $name = ( $lang === 'mn' && ! empty( $bank['name_mn'] ) ) ? $bank['name_mn'] : $bank['name_en'];
            $options[ $code ] = $name . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_banks_options
     * @param string $lang Language ('en' or 'mn')
     * @return array
     */
    public static function get_bank_dropdown( $lang = 'en' ) {
        return self::get_banks_options( $lang );
    }

    // =========================================================================
    // DISTRICT/BRANCH CODES (506 sub-branches across 30 branches)
    // =========================================================================

    /**
     * Get all districts with their sub-branches
     * @return array ['branch_code' => ['name', 'code', 'sub_branches' => [...]]]
     */
    public static function get_districts() {
        if ( self::$districts === null ) {
            self::$districts = MZQPay_Fixtures_Data::get_district_data();
        }
        return self::$districts;
    }

    /**
     * Get districts as options for select dropdowns
     * @return array ['code' => 'name']
     */
    public static function get_districts_options() {
        $districts = self::get_districts();
        $options = array( '' => __( 'Select District', 'qpay-gateway' ) );
        
        foreach ( $districts as $code => $district ) {
            $options[ $code ] = $district['name'] . ' (' . $code . ')';
        }

        return $options;
    }

    /**
     * Alias for get_districts_options
     * @param string $lang Language (not used, for API consistency)
     * @return array
     */
    public static function get_district_dropdown( $lang = 'en' ) {
        return self::get_districts_options();
    }

    /**
     * Get sub-branches for a district
     * @param string $district_code District/branch code
     * @return array Sub-branch options
     */
    public static function get_sub_branches( $district_code ) {
        $districts = self::get_districts();
        
        if ( ! isset( $districts[ $district_code ] ) ) {
            return array();
        }

        $options = array( '' => __( 'Select Sub-branch', 'qpay-gateway' ) );
        
        foreach ( $districts[ $district_code ]['sub_branches'] as $sub ) {
            $options[ $sub['full_code'] ] = $sub['name'] . ' (' . $sub['code'] . ')';
        }

        return $options;
    }

    /**
     * Get full district code from branch + sub-branch
     * @param string $branch_code Branch code
     * @param string $sub_code Sub-branch code
     * @return string Full 4-digit district code
     */
    public static function get_full_district_code( $branch_code, $sub_code ) {
        return $branch_code . sprintf( '%02d', intval( $sub_code ) );
    }

    // =========================================================================
    // GS1 CLASSIFICATION CODES (~3,730 codes with fuzzy matching)
    // =========================================================================

    /**
     * Parse and index GS1 codes from embedded data
     */
    private static function parse_gs1_codes() {
        if ( self::$gs1_codes !== null ) {
            return;
        }

        self::$gs1_codes = MZQPay_GS1_Data::get_gs1_data();
        self::$gs1_by_name = array();
        self::$gs1_tokens = array();
        
        // Build indexes for fuzzy matching
        foreach ( self::$gs1_codes as $code => $entry ) {
            $normalized = self::normalize( $entry['name'] );
            if ( ! empty( $normalized ) ) {
                self::$gs1_by_name[ $normalized ] = $entry;
                
                // Token index for partial matching
                $tokens = preg_split( '/\s+/', $normalized );
                foreach ( $tokens as $token ) {
                    if ( mb_strlen( $token ) < 3 ) continue;
                    if ( ! isset( self::$gs1_tokens[ $token ] ) ) {
                        self::$gs1_tokens[ $token ] = array();
                    }
                    self::$gs1_tokens[ $token ][] = $entry;
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
    public static function get_gs1_code( $code ) {
        self::parse_gs1_codes();
        return isset( self::$gs1_codes[ $code ] ) ? self::$gs1_codes[ $code ] : null;
    }

    /**
     * Search GS1 codes by product name/SKU with fuzzy matching
     * @param string $sku Product SKU
     * @param string $name Product name
     * @return array|null Best matching GS1 entry or null
     */
    public static function find_gs1_code( $sku, $name ) {
        self::parse_gs1_codes();
        
        // 1. Try exact SKU match
        if ( ! empty( $sku ) ) {
            $sku = trim( strval( $sku ) );
            if ( isset( self::$gs1_codes[ $sku ] ) ) {
                return self::$gs1_codes[ $sku ];
            }
        }
        
        // 2. Try exact name match
        if ( ! empty( $name ) ) {
            $normalized = self::normalize( $name );
            if ( isset( self::$gs1_by_name[ $normalized ] ) ) {
                return self::$gs1_by_name[ $normalized ];
            }
            
            // 3. Token-based matching with scoring
            $tokens = preg_split( '/\s+/', $normalized );
            $scores = array();
            
            foreach ( $tokens as $token ) {
                if ( mb_strlen( $token ) < 3 ) continue;
                if ( isset( self::$gs1_tokens[ $token ] ) ) {
                    foreach ( self::$gs1_tokens[ $token ] as $entry ) {
                        $code = $entry['code'];
                        if ( ! isset( $scores[ $code ] ) ) {
                            $scores[ $code ] = array( 'entry' => $entry, 'score' => 0 );
                        }
                        $scores[ $code ]['score']++;
                    }
                }
            }
            
            if ( ! empty( $scores ) ) {
                // Sort by score descending
                uasort( $scores, function( $a, $b ) {
                    return $b['score'] - $a['score'];
                } );
                $best = reset( $scores );
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
        
        $options = array( '' => __( 'Select Classification', 'qpay-gateway' ) );
        
        foreach ( self::$gs1_codes as $code => $entry ) {
            $label = $entry['name'];
            if ( ! empty( $entry['category'] ) ) {
                $label = $entry['category'] . ' > ' . $label;
            }
            $options[ $code ] = $label . ' (' . $code . ')';
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
    // CURRENCY CODES (6 currencies)
    // =========================================================================

    /**
     * Get all supported currencies
     * @return array ['code' => ['code', 'name']]
     */
    public static function get_currencies() {
        if ( self::$currencies === null ) {
            self::$currencies = MZQPay_Fixtures_Data::get_currency_data();
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
        
        foreach ( $currencies as $code => $currency ) {
            $options[ $code ] = $currency['name'] . ' (' . $code . ')';
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
    public static function is_currency_supported( $code ) {
        if ( empty( $code ) ) return false;
        $currencies = self::get_currencies();
        $code_upper = strtoupper( trim( $code ) );
        return isset( $currencies[ $code_upper ] );
    }

    /**
     * Alias for get_currencies
     * @return array
     */
    public static function get_currency_codes() {
        return self::get_currencies();
    }

    // =========================================================================
    // VAT CODES (~44 codes for 0% and exempt rates)
    // =========================================================================

    /**
     * Get VAT zero-rated product codes (0%)
     * @return array ['code' => ['code', 'name', 'type']]
     */
    public static function get_vat_zero_codes() {
        if ( self::$vat_zero === null ) {
            self::$vat_zero = MZQPay_Fixtures_Data::get_vat_zero_data();
        }
        return self::$vat_zero;
    }

    /**
     * Get VAT exempt product codes
     * @return array ['code' => ['code', 'name', 'type']]
     */
    public static function get_vat_exempt_codes() {
        if ( self::$vat_exempt === null ) {
            self::$vat_exempt = MZQPay_Fixtures_Data::get_vat_exempt_data();
        }
        return self::$vat_exempt;
    }

    /**
     * Get all VAT special codes (zero + exempt)
     * @return array Combined array
     */
    public static function get_all_vat_codes() {
        return array_merge( self::get_vat_zero_codes(), self::get_vat_exempt_codes() );
    }

    /**
     * Get VAT type for a product code
     * @param string $code VAT code
     * @return int Tax type: 1=VAT taxable, 2=VAT free (0%), 3=VAT exempt
     */
    public static function get_vat_type( $code ) {
        $vat_zero = self::get_vat_zero_codes();
        $vat_exempt = self::get_vat_exempt_codes();
        
        if ( isset( $vat_zero[ $code ] ) ) {
            return 2; // VAT free / 0%
        }
        if ( isset( $vat_exempt[ $code ] ) ) {
            return 3; // VAT exempt
        }
        
        return 1; // VAT taxable - default 10%
    }

    /**
     * Get VAT codes as options for select
     * @return array
     */
    public static function get_vat_options() {
        $options = array(
            '' => __( 'Standard VAT (10%)', 'qpay-gateway' ),
        );
        
        // VAT 0% codes
        foreach ( self::get_vat_zero_codes() as $code => $vat ) {
            $options[ $code ] = '[0%] ' . $vat['name'] . ' (' . $code . ')';
        }
        
        // VAT Exempt codes
        foreach ( self::get_vat_exempt_codes() as $code => $vat ) {
            $options[ $code ] = '[Exempt] ' . $vat['name'] . ' (' . $code . ')';
        }

        return $options;
    }

    // =========================================================================
    // ERROR MESSAGES (61 codes with bilingual support)
    // =========================================================================

    /**
     * Get all error messages
     * @return array ['key' => ['key', 'message_mn', 'message_en']]
     */
    public static function get_errors() {
        if ( self::$errors === null ) {
            self::$errors = MZQPay_Fixtures_Data::get_error_data();
        }
        return self::$errors;
    }

    /**
     * Get error message by key
     * @param string $key Error key
     * @param string $lang Language ('en' or 'mn')
     * @return string|null Error message or null if not found
     */
    public static function get_error_message( $key, $lang = 'en' ) {
        $errors = self::get_errors();
        
        if ( empty( $key ) || ! isset( $errors[ $key ] ) ) {
            return null;
        }
        
        $error = $errors[ $key ];
        return ( $lang === 'mn' && ! empty( $error['message_mn'] ) ) 
            ? $error['message_mn'] 
            : $error['message_en'];
    }

    /**
     * Get HTTP status codes
     * @return array
     */
    public static function get_http_codes() {
        if ( self::$http_codes === null ) {
            self::$http_codes = MZQPay_Fixtures_Data::get_http_codes();
        }
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
     * Alias for get_errors
     * @return array
     */
    public static function get_error_codes() {
        return self::get_errors();
    }

    /**
     * Translate a qPay API error response
     * @param mixed $response API response (array or string)
     * @param string $lang Language
     * @return string Human-readable error message
     */
    public static function translate_error( $response, $lang = 'en' ) {
        // Handle direct string error codes
        if ( is_string( $response ) ) {
            // Try to decode as JSON first
            $decoded = json_decode( $response, true );
            if ( is_array( $decoded ) ) {
                $response = $decoded;
            } else {
                // Try direct lookup
                $msg = self::get_error_message( $response, $lang );
                return $msg !== null ? $msg : $response;
            }
        }
        
        if ( ! is_array( $response ) ) {
            return strval( $response );
        }
        
        // Check for error key in response
        $error_key = null;
        if ( isset( $response['error'] ) ) {
            $error_key = $response['error'];
        } elseif ( isset( $response['message'] ) ) {
            $error_key = $response['message'];
        } elseif ( isset( $response['code'] ) ) {
            $error_key = $response['code'];
        }
        
        if ( $error_key ) {
            $msg = self::get_error_message( $error_key, $lang );
            return $msg !== null ? $msg : $error_key;
        }
        
        return json_encode( $response );
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
            'banks'       => count( self::get_banks() ),
            'districts'   => count( self::get_districts() ),
            'sub_branches'=> array_sum( array_map( function( $d ) { 
                return count( $d['sub_branches'] ); 
            }, self::get_districts() ) ),
            'gs1_codes'   => count( self::get_gs1_codes() ),
            'currencies'  => count( self::get_currencies() ),
            'vat_zero'    => count( self::get_vat_zero_codes() ),
            'vat_exempt'  => count( self::get_vat_exempt_codes() ),
            'errors'      => count( self::get_errors() ),
            'http_codes'  => count( self::get_http_codes() ),
            'data_source' => 'embedded',
        );
    }

    /**
     * Clear cached data (useful for testing)
     */
    public static function clear_cache() {
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
     * @return bool Always true since data is embedded
     */
    public static function is_available() {
        return true;
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
     * Get tax product codes (GS1) for eBarimt
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
            'CAPITRON' => array(
                'name' => 'Capitron Bank',
                'android' => 'capitronbank://qpay',
                'ios' => 'capitronbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/capitron-bank/id1504751099',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.capitronbank.app'
            ),
            'ARIG' => array(
                'name' => 'Arig Bank',
                'android' => 'arigbank://qpay',
                'ios' => 'arigbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/arig-bank/id1234567895',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.arigbank.mobile'
            ),
            'BOGD' => array(
                'name' => 'Bogd Bank',
                'android' => 'bogdbank://qpay',
                'ios' => 'bogdbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/bogd-bank/id1234567896',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.bogdbank.mobile'
            ),
            'CHINGGIS' => array(
                'name' => 'Chinggis Khaan Bank',
                'android' => 'ckbank://qpay',
                'ios' => 'ckbank://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/chinggis-bank/id1234567897',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.ckbank.mobile'
            ),
            'TOKI' => array(
                'name' => 'Toki',
                'android' => 'toki://qpay',
                'ios' => 'toki://qpay',
                'app_store' => 'https://apps.apple.com/mn/app/toki/id1508610083',
                'play_store' => 'https://play.google.com/store/apps/details?id=mn.grapecity.toki'
            ),
        );
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
}
