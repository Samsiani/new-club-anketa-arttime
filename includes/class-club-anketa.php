<?php
if (!defined('ABSPATH')) {
    exit;
}

class Club_Anketa_Registration {

    private static $instance = null;
    private static $errors = [];
    private static $old = [];

    /**
     * Rate limiting constants
     */
    const OTP_MAX_ATTEMPTS = 3;
    const OTP_RATE_LIMIT_MINUTES = 10;
    const OTP_EXPIRY_SECONDS = 300;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('club_anketa_form', [$this, 'shortcode_form']);
        // Process early so we can redirect ASAP
        add_action('template_redirect', [$this, 'maybe_process_submission'], 1);

        // Routing for print pages
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'maybe_use_print_template']);

        // Admin: Users list column for SMS consent
        add_filter('manage_users_columns', [$this, 'add_user_columns']);
        add_filter('manage_users_custom_column', [$this, 'render_user_columns'], 10, 3);

        // Optional async email hooks (not used by default)
        add_action('club_anketa_send_user_notification', [$this, 'send_user_notification'], 10, 1);
        add_action('club_anketa_send_user_notification_cron', [$this, 'send_user_notification'], 10, 1);

        // SMS OTP AJAX handlers
        add_action('wp_ajax_club_anketa_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_club_anketa_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_club_anketa_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_club_anketa_verify_otp', [$this, 'ajax_verify_otp']);

        // WooCommerce hooks
        add_action('woocommerce_review_order_before_submit', [$this, 'checkout_sms_consent']);
        add_action('woocommerce_edit_account_form', [$this, 'account_sms_consent']);
        add_action('woocommerce_save_account_details', [$this, 'save_account_sms_consent'], 10, 1);

        // WooCommerce phone field verification hooks
        add_filter('woocommerce_form_field_tel', [$this, 'modify_phone_field_html'], 20, 4);
        add_action('woocommerce_after_edit_address_form_billing', [$this, 'add_account_phone_verification']);
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_phone_verification']);
        add_action('woocommerce_save_account_details_errors', [$this, 'validate_account_phone_verification'], 10, 1);

        // Enqueue scripts on appropriate pages
        add_action('wp_enqueue_scripts', [$this, 'enqueue_sms_verification_scripts']);
    }

    // ===== SMS OTP Methods =====

    /**
     * Enqueue SMS verification scripts and styles
     */
    public function enqueue_sms_verification_scripts() {
        // Check if we're on a page that needs SMS verification
        global $post;
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_account = function_exists('is_account_page') && is_account_page();
        $has_shortcode = $post && has_shortcode($post->post_content, 'club_anketa_form');
        
        // Check if this is a WooCommerce page (checkout or account)
        // Note: is_account_page() returns true for My Account pages which include:
        // - Login/Registration form
        // - Edit Account/Address forms
        $is_wc_page = $is_checkout || $is_account;

        if (!$is_wc_page && !$has_shortcode) {
            return;
        }

        // Register the style here to ensure it loads on WooCommerce pages
        // where the shortcode may not be present
        wp_register_style(
            'club-anketa-form',
            CLUB_ANKETA_URL . 'assets/anketa-form.css',
            [],
            CLUB_ANKETA_VERSION
        );
        wp_enqueue_style('club-anketa-form');
        
        wp_enqueue_script(
            'club-anketa-sms-verification',
            CLUB_ANKETA_URL . 'assets/sms-verification.js',
            ['jquery'],
            CLUB_ANKETA_VERSION,
            true
        );

        // Get verified phone number for logged-in users
        $verified_phone = '';
        if (is_user_logged_in()) {
            $verified_phone = $this->get_user_verified_phone(get_current_user_id());
        }

        wp_localize_script('club-anketa-sms-verification', 'clubAnketaSms', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('club_anketa_sms_nonce'),
            'verifiedPhone' => $verified_phone,
            'i18n'    => [
                'sendingOtp'      => __('იგზავნება...', 'club-anketa'),
                'enterCode'       => __('შეიყვანეთ 6-ნიშნა კოდი', 'club-anketa'),
                'verifying'       => __('მოწმდება...', 'club-anketa'),
                'verified'        => __('დადასტურებულია!', 'club-anketa'),
                'error'           => __('შეცდომა', 'club-anketa'),
                'invalidCode'     => __('არასწორი კოდი', 'club-anketa'),
                'codeExpired'     => __('კოდის ვადა ამოიწურა', 'club-anketa'),
                'resendIn'        => __('ხელახლა გაგზავნა:', 'club-anketa'),
                'resend'          => __('ხელახლა გაგზავნა', 'club-anketa'),
                'close'           => __('დახურვა', 'club-anketa'),
                'verify'          => __('დადასტურება', 'club-anketa'),
                'phoneRequired'   => __('ტელეფონის ნომერი სავალდებულოა', 'club-anketa'),
                'rateLimitError'  => __('ზედმეტად ბევრი მცდელობა. გთხოვთ სცადეთ მოგვიანებით.', 'club-anketa'),
                'modalTitle'      => __('ტელეფონის ვერიფიკაცია', 'club-anketa'),
                'modalSubtitle'   => __('SMS კოდი გამოგზავნილია ნომერზე:', 'club-anketa'),
                'verifyBtn'       => __('Verify', 'club-anketa'),
                'verificationRequired' => __('ტელეფონის ვერიფიკაცია სავალდებულოა', 'club-anketa'),
            ]
        ]);
    }

    /**
     * Get user's verified phone number (9-digit local format)
     */
    private function get_user_verified_phone($user_id) {
        $verified_phone = get_user_meta($user_id, '_verified_phone_number', true);
        if ($verified_phone) {
            // Ensure it's in 9-digit local format
            $verified_phone = preg_replace('/\D+/', '', $verified_phone);
            if (strlen($verified_phone) > 9 && strpos($verified_phone, '995') === 0) {
                $verified_phone = substr($verified_phone, 3);
            }
            if (strlen($verified_phone) > 9) {
                $verified_phone = substr($verified_phone, -9);
            }
        }
        return $verified_phone ?: '';
    }

    /**
     * Generate a 6-digit OTP code
     */
    private function generate_otp() {
        return str_pad(wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get rate limit key for phone/IP
     */
    private function get_rate_limit_key($phone) {
        $ip = $this->get_client_ip();
        return 'otp_rate_' . md5($phone . $ip);
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return $ip;
    }

    /**
     * Check rate limit
     */
    private function check_rate_limit($phone) {
        $key = $this->get_rate_limit_key($phone);
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            return true; // No previous attempts
        }
        
        return (int) $attempts < self::OTP_MAX_ATTEMPTS;
    }

    /**
     * Increment rate limit counter
     */
    private function increment_rate_limit($phone) {
        $key = $this->get_rate_limit_key($phone);
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            set_transient($key, 1, self::OTP_RATE_LIMIT_MINUTES * MINUTE_IN_SECONDS);
        } else {
            set_transient($key, (int) $attempts + 1, self::OTP_RATE_LIMIT_MINUTES * MINUTE_IN_SECONDS);
        }
    }

    /**
     * Send SMS via MS Group API
     */
    private function send_sms($phone, $message) {
        $username   = get_option('club_anketa_sms_username', '');
        $password   = get_option('club_anketa_sms_password', '');
        $client_id  = get_option('club_anketa_sms_client_id', '');
        $service_id = get_option('club_anketa_sms_service_id', '');

        if (empty($username) || empty($password) || empty($client_id) || empty($service_id)) {
            return [
                'success' => false,
                'error'   => __('SMS API not configured.', 'club-anketa'),
            ];
        }

        // Format phone number to international format (995XXXXXXXXX)
        $phone_digits = preg_replace('/\D+/', '', $phone);
        if (strlen($phone_digits) === 9) {
            $phone_digits = '995' . $phone_digits;
        }

        // Note: MS Group API uses HTTP (http://bi.msg.ge/sendsms.php) as documented
        $api_url = add_query_arg([
            'username'   => $username,
            'password'   => $password,
            'client_id'  => $client_id,
            'service_id' => $service_id,
            'to'         => $phone_digits,
            'text'       => rawurlencode($message),
            'result'     => 'json',
        ], 'http://bi.msg.ge/sendsms.php');

        $response = wp_remote_get($api_url, [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check response code
        if (isset($data['code'])) {
            $code = $data['code'];
            if (strpos($code, '0000') === 0) {
                return [
                    'success'    => true,
                    'message_id' => isset($data['message_id']) ? $data['message_id'] : '',
                ];
            }
            
            $error_messages = [
                '0001' => __('Invalid API credentials or forbidden IP.', 'club-anketa'),
                '0007' => __('Invalid phone number.', 'club-anketa'),
                '0008' => __('Insufficient SMS balance.', 'club-anketa'),
            ];
            
            return [
                'success' => false,
                'error'   => isset($error_messages[$code]) ? $error_messages[$code] : __('SMS sending failed.', 'club-anketa'),
            ];
        }

        // Fallback: check if response starts with 0000
        if (strpos($body, '0000') === 0) {
            return [
                'success' => true,
                'message_id' => trim(str_replace('0000-', '', $body)),
            ];
        }

        return [
            'success' => false,
            'error'   => __('Unexpected API response.', 'club-anketa'),
        ];
    }

    /**
     * AJAX handler: Send OTP
     */
    public function ajax_send_otp() {
        check_ajax_referer('club_anketa_sms_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone_digits = preg_replace('/\D+/', '', $phone);

        if (strlen($phone_digits) !== 9) {
            wp_send_json_error(['message' => __('Invalid phone number. Must be 9 digits.', 'club-anketa')]);
        }

        // Check rate limit
        if (!$this->check_rate_limit($phone_digits)) {
            wp_send_json_error(['message' => __('Too many attempts. Please try again later.', 'club-anketa')]);
        }

        // Generate OTP
        $otp = $this->generate_otp();
        
        // Store OTP in transient
        $otp_key = 'otp_' . $phone_digits;
        set_transient($otp_key, $otp, self::OTP_EXPIRY_SECONDS);

        // Send SMS
        $message = sprintf(__('თქვენი ვერიფიკაციის კოდია: %s', 'club-anketa'), $otp);
        $result = $this->send_sms($phone_digits, $message);

        if ($result['success']) {
            $this->increment_rate_limit($phone_digits);
            wp_send_json_success([
                'message' => __('OTP sent successfully.', 'club-anketa'),
                'expires' => self::OTP_EXPIRY_SECONDS,
            ]);
        } else {
            wp_send_json_error(['message' => $result['error']]);
        }
    }

    /**
     * AJAX handler: Verify OTP
     */
    public function ajax_verify_otp() {
        check_ajax_referer('club_anketa_sms_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        $phone_digits = preg_replace('/\D+/', '', $phone);

        if (strlen($phone_digits) !== 9 || strlen($code) !== 6) {
            wp_send_json_error(['message' => __('Invalid phone or code format.', 'club-anketa')]);
        }

        $otp_key = 'otp_' . $phone_digits;
        $stored_otp = get_transient($otp_key);

        if ($stored_otp === false) {
            wp_send_json_error(['message' => __('OTP expired. Please request a new code.', 'club-anketa')]);
        }

        if ($stored_otp !== $code) {
            wp_send_json_error(['message' => __('Invalid OTP code.', 'club-anketa')]);
        }

        // OTP verified - delete transient and create verification token
        delete_transient($otp_key);
        
        // Create a temporary verification token for form submission
        $verify_token = wp_generate_password(32, false);
        $verify_key = 'otp_verified_' . $phone_digits;
        set_transient($verify_key, $verify_token, self::OTP_EXPIRY_SECONDS);

        // If user is logged in, update their verified phone number
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, '_verified_phone_number', $phone_digits);
        }

        wp_send_json_success([
            'message'       => __('Phone verified successfully.', 'club-anketa'),
            'token'         => $verify_token,
            'verifiedPhone' => $phone_digits,
        ]);
    }

    /**
     * Check if phone is verified via OTP
     */
    private function is_phone_verified($phone) {
        $phone_digits = preg_replace('/\D+/', '', $phone);
        $verify_key = 'otp_verified_' . $phone_digits;
        $token = isset($_POST['otp_verification_token']) ? sanitize_text_field(wp_unslash($_POST['otp_verification_token'])) : '';
        
        if (empty($token)) {
            return false;
        }
        
        $stored_token = get_transient($verify_key);
        return $stored_token !== false && $stored_token === $token;
    }

    /**
     * Check if user already has SMS consent
     */
    private function user_has_sms_consent($user_id = null) {
        if (!$user_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        $consent = get_user_meta($user_id, '_sms_consent', true);
        return $consent === 'yes';
    }

    /**
     * WooCommerce Checkout SMS Consent
     */
    public function checkout_sms_consent() {
        // Skip if user already has SMS consent
        if ($this->user_has_sms_consent()) {
            return;
        }

        $this->render_sms_consent_fields('checkout');
    }

    /**
     * WooCommerce My Account SMS Consent
     */
    public function account_sms_consent() {
        $user_id = get_current_user_id();
        $current_consent = get_user_meta($user_id, '_sms_consent', true);
        $current_call_consent = get_user_meta($user_id, '_call_consent', true);
        
        $this->render_sms_consent_fields('account', $current_consent, $current_call_consent);
    }

    /**
     * Save Account SMS Consent
     */
    public function save_account_sms_consent($user_id) {
        if (!isset($_POST['anketa_sms_consent'])) {
            return;
        }

        $new_consent = sanitize_text_field(wp_unslash($_POST['anketa_sms_consent']));
        $old_consent = get_user_meta($user_id, '_sms_consent', true);

        // Get current phone number
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $phone_digits = preg_replace('/\D+/', '', $phone);
        
        // Extract 9-digit local number
        if (preg_match('/995(\d{9})$/', $phone_digits, $m)) {
            $phone_digits = $m[1];
        } elseif (strlen($phone_digits) > 9) {
            $phone_digits = substr($phone_digits, -9);
        }

        // If changing from "no" to "yes", require OTP verification
        if ($new_consent === 'yes' && $old_consent !== 'yes') {
            if (!$this->is_phone_verified($phone_digits)) {
                // Don't update, verification required
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Phone verification required to enable SMS notifications.', 'club-anketa'), 'error');
                }
                return;
            }
            // Update the verified phone number
            update_user_meta($user_id, '_verified_phone_number', $phone_digits);
        }

        // If consent is "no", clear the verified phone number
        if ($new_consent === 'no') {
            delete_user_meta($user_id, '_verified_phone_number');
        }

        update_user_meta($user_id, '_sms_consent', $new_consent);

        // Handle Call Consent
        if (isset($_POST['anketa_call_consent'])) {
            $call_consent = sanitize_text_field(wp_unslash($_POST['anketa_call_consent']));
            if ($call_consent !== 'yes' && $call_consent !== 'no') {
                $call_consent = 'yes';
            }
            update_user_meta($user_id, '_call_consent', $call_consent);
        }
    }

    /**
     * Render SMS and Call consent fields
     */
    private function render_sms_consent_fields($context = 'registration', $current_value = 'yes', $current_call_value = 'yes') {
        $field_id = 'anketa_sms_consent_' . $context;
        ?>
        <div class="club-anketa-sms-consent" data-context="<?php echo esc_attr($context); ?>">
            <div class="row">
                <span class="label"><?php esc_html_e('SMS შეტყობინებების მიღების თანხმობა', 'club-anketa'); ?></span>
                <div class="field sms-consent-options">
                    <label style="margin-right:12px;">
                        <input type="radio" name="anketa_sms_consent" value="yes" <?php checked($current_value, 'yes'); ?> class="sms-consent-radio" />
                        <?php esc_html_e('დიახ', 'club-anketa'); ?>
                    </label>
                    <label>
                        <input type="radio" name="anketa_sms_consent" value="no" <?php checked($current_value, 'no'); ?> class="sms-consent-radio" />
                        <?php esc_html_e('არა', 'club-anketa'); ?>
                    </label>
                </div>
            </div>
            <div class="row">
                <span class="label"><?php esc_html_e('თანხმობა სატელეფონო ზარზე', 'club-anketa'); ?></span>
                <div class="field sms-consent-options">
                    <label style="margin-right:12px;">
                        <input type="radio" name="anketa_call_consent" value="yes" <?php checked($current_call_value, 'yes'); ?> class="call-consent-radio" />
                        <?php esc_html_e('დიახ', 'club-anketa'); ?>
                    </label>
                    <label>
                        <input type="radio" name="anketa_call_consent" value="no" <?php checked($current_call_value, 'no'); ?> class="call-consent-radio" />
                        <?php esc_html_e('არა', 'club-anketa'); ?>
                    </label>
                </div>
            </div>
            <input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
        </div>
        <?php
    }

    // ===== WooCommerce Phone Verification =====

    /**
     * Modify WooCommerce phone field HTML to add verify button
     * Applies to billing_phone field on checkout and account pages
     */
    public function modify_phone_field_html($field, $key, $args, $value) {
        // Only modify billing_phone field
        if ($key !== 'billing_phone') {
            return $field;
        }

        // Check if we're on checkout or account page
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_account = function_exists('is_account_page') && is_account_page();
        
        if (!$is_checkout && !$is_account) {
            return $field;
        }

        // Get verified phone for comparison
        $verified_phone = '';
        if (is_user_logged_in()) {
            $verified_phone = $this->get_user_verified_phone(get_current_user_id());
        }

        // Check if current phone matches verified phone
        $current_phone = $this->normalize_phone($value);
        $is_verified = !empty($verified_phone) && $current_phone === $verified_phone;

        // Build the verification UI HTML to append after the field
        // NOTE: Do NOT wrap the input using regex as it can fail and strip the input.
        // Instead, append the button HTML and let JavaScript handle the visual grouping.
        $verify_button_html = '<div class="phone-verify-container">';
        
        if ($is_verified) {
            $verify_button_html .= '<button type="button" class="phone-verify-btn" style="display:none;" aria-label="' . esc_attr__('Verify phone number', 'club-anketa') . '">' . esc_html__('Verify', 'club-anketa') . '</button>';
            $verify_button_html .= '<span class="phone-verified-icon" aria-label="' . esc_attr__('Phone verified', 'club-anketa') . '">';
        } else {
            $verify_button_html .= '<button type="button" class="phone-verify-btn" aria-label="' . esc_attr__('Verify phone number', 'club-anketa') . '">' . esc_html__('Verify', 'club-anketa') . '</button>';
            $verify_button_html .= '<span class="phone-verified-icon" style="display:none;" aria-label="' . esc_attr__('Phone verified', 'club-anketa') . '">';
        }
        
        $verify_button_html .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        $verify_button_html .= '</span></div>';

        // NOTE: The hidden otp_verification_token field is handled by JavaScript (updateVerificationToken function)
        // which dynamically adds it inside the form to ensure proper form submission

        // Simply append the verification UI to the end of the field HTML
        // JavaScript will handle wrapping both input and container in phone-verify-group div
        $field .= $verify_button_html;

        return $field;
    }

    /**
     * Normalize phone number to 9-digit format
     */
    private function normalize_phone($phone) {
        if (empty($phone)) {
            return '';
        }
        
        $digits = preg_replace('/\D+/', '', $phone);
        
        // If it includes country code, extract local part
        if (strlen($digits) > 9 && strpos($digits, '995') === 0) {
            $digits = substr($digits, 3);
        }
        
        // Take only last 9 digits
        if (strlen($digits) > 9) {
            $digits = substr($digits, -9);
        }
        
        return $digits;
    }

    /**
     * Add phone verification UI to account edit address page
     */
    public function add_account_phone_verification() {
        // This hook fires after the billing address form
        // The phone field should already have been modified by modify_phone_field_html
        // Just ensure hidden field exists for the form
        ?>
        <script>
        // Ensure verification token field exists in the form
        (function() {
            var form = document.querySelector('form.woocommerce-EditAccountForm, form.edit-address');
            if (form && !form.querySelector('.otp-verification-token')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'otp_verification_token';
                input.value = '';
                input.className = 'otp-verification-token';
                form.appendChild(input);
            }
        })();
        </script>
        <?php
    }

    /**
     * Validate phone verification on checkout
     */
    public function validate_checkout_phone_verification() {
        if (!isset($_POST['billing_phone'])) {
            return;
        }

        $phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));
        $phone_digits = $this->normalize_phone($phone);

        if (strlen($phone_digits) !== 9) {
            return; // Invalid phone, WooCommerce will handle validation
        }

        // Check if user is logged in and phone matches verified phone
        if (is_user_logged_in()) {
            $verified_phone = $this->get_user_verified_phone(get_current_user_id());
            if ($phone_digits === $verified_phone) {
                return; // Phone already verified for this user
            }
        }

        // Check for OTP verification token
        if (!$this->is_phone_verified($phone_digits)) {
            wc_add_notice(__('Phone verification required. Please verify your phone number before placing the order.', 'club-anketa'), 'error');
        }
    }

    /**
     * Validate phone verification on account details save
     */
    public function validate_account_phone_verification($errors) {
        if (!isset($_POST['billing_phone'])) {
            return;
        }

        $phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));
        $phone_digits = $this->normalize_phone($phone);

        if (strlen($phone_digits) !== 9) {
            return; // Invalid phone format will be handled by WooCommerce
        }

        $user_id = get_current_user_id();
        $verified_phone = $this->get_user_verified_phone($user_id);

        // If phone number changed, require verification
        if ($phone_digits !== $verified_phone) {
            if (!$this->is_phone_verified($phone_digits)) {
                $errors->add('phone_verification', __('Phone verification required. Please verify your new phone number.', 'club-anketa'));
            } else {
                // Update verified phone number after successful verification
                update_user_meta($user_id, '_verified_phone_number', $phone_digits);
            }
        }
    }

    // ===== Routing for print pages =====

    public function register_rewrite_rules() {
        add_rewrite_rule('^print-anketa/?$', 'index.php?is_anketa_print_page=1', 'top');
        add_rewrite_rule('^signature-terms/?$', 'index.php?is_signature_terms_page=1', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'is_anketa_print_page';
        $vars[] = 'is_signature_terms_page';
        return $vars;
    }

    public function maybe_use_print_template($template) {
        if (get_query_var('is_anketa_print_page')) {
            return CLUB_ANKETA_PATH . 'templates/print-anketa.php';
        }
        if (get_query_var('is_signature_terms_page')) {
            return CLUB_ANKETA_PATH . 'templates/signature-terms.php';
        }
        return $template;
    }

    // ===== Form processing =====

    public function maybe_process_submission() {
        if ('POST' !== $_SERVER['REQUEST_METHOD'] || empty($_POST['club_anketa_form_submitted'])) {
            return;
        }

        // Nonce
        if (
            empty($_POST['club_anketa_form_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['club_anketa_form_nonce'])), 'club_anketa_register')
        ) {
            return;
        }

        // Honeypot
        $honeypot = isset($_POST['anketa_security_field']) ? trim((string) $_POST['anketa_security_field']) : '';
        if ($honeypot !== '') {
            return;
        }

        // Collect and sanitize inputs
        $fields = [
            'anketa_personal_id'        => 'text',
            'anketa_first_name'         => 'text',
            'anketa_last_name'          => 'text',
            'anketa_dob'                => 'date',
            'anketa_phone_local'        => 'tel',   // 9-digit local part
            'anketa_address'            => 'text',
            'anketa_email'              => 'email',
            'anketa_card_no'            => 'text',
            // Signature not collected
            'anketa_responsible_person' => 'text',
            'anketa_form_date'          => 'date',
            'anketa_shop'               => 'text',
            // SMS consent radio (default yes)
            'anketa_sms_consent'        => 'text',
            // Call consent radio (default yes)
            'anketa_call_consent'       => 'text',
        ];

        $data = [];
        foreach ($fields as $key => $type) {
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $data[$key] = $this->sanitize_by_type($raw, $type);
        }
        self::$old = $data;

        // Required
        $required = [
            'anketa_personal_id' => __('Personal ID is required.', 'club-anketa'),
            'anketa_first_name'  => __('First name is required.', 'club-anketa'),
            'anketa_last_name'   => __('Last name is required.', 'club-anketa'),
            'anketa_dob'         => __('Date of birth is required.', 'club-anketa'),
            'anketa_phone_local' => __('Phone number is required.', 'club-anketa'),
            'anketa_email'       => __('Email is required.', 'club-anketa'),
        ];
        foreach ($required as $key => $message) {
            if ($data[$key] === '') {
                self::$errors[] = $message;
            }
        }

        // Email validity
        if ($data['anketa_email'] && !is_email($data['anketa_email'])) {
            self::$errors[] = __('Please enter a valid email address.', 'club-anketa');
        }

        // Phone local digits (username)
        $local_digits = preg_replace('/\D+/', '', (string)$data['anketa_phone_local']);
        if (!preg_match('/^\d{9}$/', $local_digits)) {
            self::$errors[] = __('Local phone number must be exactly 9 digits.', 'club-anketa');
        }

        // Uniqueness
        if ($local_digits && username_exists($local_digits)) {
            self::$errors[] = __('This phone number is already registered.', 'club-anketa');
        }
        if ($data['anketa_email'] && email_exists($data['anketa_email'])) {
            self::$errors[] = __('This email is already registered.', 'club-anketa');
        }

        if (!empty(self::$errors)) {
            return;
        }

        // Default SMS consent to 'yes' if not provided
        $sms_consent = strtolower($data['anketa_sms_consent']);
        if ($sms_consent !== 'yes' && $sms_consent !== 'no') {
            $sms_consent = 'yes';
        }

        // Default Call consent to 'yes' if not provided
        $call_consent = strtolower($data['anketa_call_consent']);
        if ($call_consent !== 'yes' && $call_consent !== 'no') {
            $call_consent = 'yes';
        }

        // Phone verification is optional for Anketa form - allow submission without OTP
        // The verify button is still visible, but skipping verification does not block submission
        // Note: WooCommerce Checkout and Registration still require verification (handled separately)
        // Check if user verified their phone before we potentially clean up the transient
        $phone_was_verified = $this->is_phone_verified($local_digits);
        
        // If user verified their phone, clean up the verification token after storing the status
        // (cleanup happens after meta is saved)

        // Create user quickly
        $password = wp_generate_password(18, true, true);
        $user_id = wp_insert_user([
            'user_login'   => $local_digits,
            'user_email'   => $data['anketa_email'],
            'user_pass'    => $password,
            'first_name'   => $data['anketa_first_name'],
            'last_name'    => $data['anketa_last_name'],
            'display_name' => trim($data['anketa_first_name'] . ' ' . $data['anketa_last_name']),
        ]);

        if (is_wp_error($user_id)) {
            self::$errors[] = $user_id->get_error_message();
            return;
        }

        // Save meta
        $full_phone = '+995 ' . $local_digits;
        $meta_map = [
            'billing_phone'               => $full_phone,
            'billing_address_1'           => $data['anketa_address'],
            '_anketa_personal_id'         => $data['anketa_personal_id'],
            '_anketa_dob'                 => $data['anketa_dob'],
            '_anketa_card_no'             => $data['anketa_card_no'],
            '_anketa_responsible_person'  => $data['anketa_responsible_person'],
            '_anketa_form_date'           => $data['anketa_form_date'],
            '_anketa_shop'                => $data['anketa_shop'],
            // SMS consent
            '_sms_consent'                => $sms_consent,
            // Call consent
            '_call_consent'               => $call_consent,
            // Store the verified phone number only if actually verified via OTP
            '_verified_phone_number'      => $phone_was_verified ? $local_digits : '',
        ];
        foreach ($meta_map as $meta_key => $meta_value) {
            if ($meta_value !== '') {
                update_user_meta($user_id, $meta_key, $meta_value);
            }
        }

        // Clean up verification token after successful use (if phone was verified)
        if ($phone_was_verified) {
            delete_transient('otp_verified_' . $local_digits);
        }

        // Redirect to print page
        $url = home_url('/signature-terms/?user_id=' . absint($user_id)); // Optional: go to terms first
        $url = home_url('/print-anketa/?user_id=' . absint($user_id));   // Default: go to anketa
        wp_safe_redirect($url);
        exit;
    }

    private function sanitize_by_type($value, $type) {
        $value = is_string($value) ? trim($value) : $value;
        switch ($type) {
            case 'email':
                return sanitize_email($value);
            case 'date':
                return preg_replace('/[^0-9\-\.\s\/]/', '', $value);
            case 'tel':
                return preg_replace('/[^0-9\+\-\s\(\)]/', '', $value);
            case 'text':
            default:
                return sanitize_text_field($value);
        }
    }

    // ===== Admin Users list column =====

    public function add_user_columns($columns) {
        $columns['club_anketa_sms'] = __('SMS accept', 'club-anketa');
        $columns['club_anketa_call'] = __('Call accept', 'club-anketa');
        return $columns;
    }

    public function render_user_columns($output, $column_name, $user_id) {
        if ($column_name === 'club_anketa_sms') {
            $val = get_user_meta((int)$user_id, '_sms_consent', true);
            $val = is_string($val) ? strtolower($val) : '';
            if ($val === 'yes') {
                return '<span style="color:#2e7d32;font-weight:600;">' . esc_html__('Yes', 'club-anketa') . '</span>';
            }
            if ($val === 'no') {
                return '<span style="color:#c62828;font-weight:600;">' . esc_html__('No', 'club-anketa') . '</span>';
            }
            return '<span style="color:#616161;">' . esc_html__('(blank)', 'club-anketa') . '</span>';
        }
        if ($column_name === 'club_anketa_call') {
            $val = get_user_meta((int)$user_id, '_call_consent', true);
            $val = is_string($val) ? strtolower($val) : '';
            if ($val === 'yes') {
                return '<span style="color:#2e7d32;font-weight:600;">' . esc_html__('Yes', 'club-anketa') . '</span>';
            }
            if ($val === 'no') {
                return '<span style="color:#c62828;font-weight:600;">' . esc_html__('No', 'club-anketa') . '</span>';
            }
            return '<span style="color:#616161;">' . esc_html__('(blank)', 'club-anketa') . '</span>';
        }
        return $output;
    }

    // ===== Optional async user email (unused by default) =====
    public function send_user_notification($user_id) {
        if ($user_id > 0 && function_exists('wp_new_user_notification')) {
            wp_new_user_notification($user_id, null, 'user');
        }
    }

    // ===== Shortcode =====

    public function shortcode_form() {
        // Enqueue form CSS (style is registered in enqueue_sms_verification_scripts)
        wp_enqueue_style('club-anketa-form');

        $errors = self::$errors;
        $old    = self::$old;

        $v = function ($key) use ($old) {
            return isset($old[$key]) ? esc_attr($old[$key]) : '';
        };

        $sms_old = isset($old['anketa_sms_consent']) ? strtolower($old['anketa_sms_consent']) : 'yes';
        if ($sms_old !== 'yes' && $sms_old !== 'no') {
            $sms_old = 'yes';
        }

        $call_old = isset($old['anketa_call_consent']) ? strtolower($old['anketa_call_consent']) : 'yes';
        if ($call_old !== 'yes' && $call_old !== 'no') {
            $call_old = 'yes';
        }

        ob_start();
        ?>
        <div class="club-anketa-form-wrap">
            <?php if (!empty($errors)): ?>
                <div class="club-anketa-errors" role="alert" aria-live="polite">
                    <?php foreach ($errors as $e): ?>
                        <div><?php echo esc_html($e); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="club-anketa-form" method="post" action="">
                <?php wp_nonce_field('club_anketa_register', 'club_anketa_form_nonce'); ?>
                <input type="hidden" name="club_anketa_form_submitted" value="1" />
                <div class="club-anketa-hp">
                    <label for="anketa_security_field">Leave this empty</label>
                    <input type="text" id="anketa_security_field" name="anketa_security_field" value="" autocomplete="off" />
                </div>

                <div class="row">
                    <label class="label" for="anketa_personal_id">პირადი ნომერი *</label>
                    <div class="field">
                        <input type="text" id="anketa_personal_id" name="anketa_personal_id" required value="<?php echo $v('anketa_personal_id'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_first_name">სახელი *</label>
                    <div class="field">
                        <input type="text" id="anketa_first_name" name="anketa_first_name" required value="<?php echo $v('anketa_first_name'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_last_name">გვარი *</label>
                    <div class="field">
                        <input type="text" id="anketa_last_name" name="anketa_last_name" required value="<?php echo $v('anketa_last_name'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_dob">დაბადების თარიღი *</label>
                    <div class="field">
                        <input type="date" id="anketa_dob" name="anketa_dob" required value="<?php echo $v('anketa_dob'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label">ტელეფონის ნომერი *</label>
                    <div class="field">
                        <div class="phone-group phone-verify-group">
                            <input class="phone-prefix" type="text" value="+995" readonly aria-label="Country code +995" />
                            <input
                                class="phone-local"
                                type="tel"
                                id="anketa_phone_local"
                                name="anketa_phone_local"
                                inputmode="numeric"
                                pattern="[0-9]{9}"
                                maxlength="9"
                                placeholder="599620303"
                                required
                                value="<?php echo $v('anketa_phone_local'); ?>"
                                aria-describedby="phoneHelp"
                            />
                            <div class="phone-verify-container">
                                <button type="button" class="phone-verify-btn" aria-label="<?php esc_attr_e('Verify phone number', 'club-anketa'); ?>">
                                    <?php esc_html_e('Verify', 'club-anketa'); ?>
                                </button>
                                <span class="phone-verified-icon" style="display:none;" aria-label="<?php esc_attr_e('Phone verified', 'club-anketa'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </span>
                            </div>
                        </div>
                        <small id="phoneHelp" class="help-text">9-ციფრიანი ნომერი, მაგალითად 599620303</small>
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_address">მისამართი</label>
                    <div class="field">
                        <input type="text" id="anketa_address" name="anketa_address" value="<?php echo $v('anketa_address'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_email">E-mail *</label>
                    <div class="field">
                        <input type="email" id="anketa_email" name="anketa_email" required value="<?php echo $v('anketa_email'); ?>" />
                    </div>
                </div>

                <div class="rules-wrap">
                    <div class="rules-title">წესები და პირობები</div>
                    <div class="rules-text">
                        <?php
                        $rules = <<<HTML
<p><strong>Arttime-ის კლუბის წევრები სარგებლობენ შემდეგი უპირატესობით:</strong></p>
<ul>
<li>ბარათზე 500-5000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 5%</li>
<li>ბარათზე 5001-10000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 10%;</li>
<li>ბარათზე 10 000 ლარზე მეტის დაგროვების შემთხვევაში ფასდაკლება 15%.</li>
</ul>
<p>&nbsp;</p>
<p><strong>გთხოვთ გაითვალისწინოთ:</strong></p>
<ol>
<li>ართთაიმის კლუბის ბარათით გათვალისწინებული ფასდაკლება არ მოქმედებს ფასდაკლებელ პროდუქციაზე;</li>
<li>ფასდაკლებული პროდუქციის შეძენის შემთხვევაში ბარათზე მხოლოდ ქულები დაგერიცხებათ;</li>
<li>ფასდაკლება მოქმედებს, მაგრამ ქულები არ გერიცხებათ პროდუქციის სასაჩუქრე ვაუჩერით შემენისას</li>
<li>სასაჩუქრე ვაუჩერის შეძენისას ფასდაკლება არ მოქმედებს, მაგრამ ქულები გროვდება:</li>
<li>დაგროვილი ქულები ბარათზე აისახება 2 სამუშაო დღის ვადაში;</li>
<li>გაითვალისწინეთ, წინამდებარე წესებით დადგენილი პირობები შეიძლება შეიცვალოს შპს „ართთაიმის“ მიერ, რომელიც სავალდებულო იქნება ბარათების პროექტში ჩართული მომხმარებლებისთვის.</li>
<li>ხელმოწერით ვადასტურებ ჩემი პირადი მონაცემების სიზუსტეს და ბარათის მიღებას</li>
</ol>
HTML;
                        echo wp_kses_post(apply_filters('club_anketa_rules_text', $rules));
                        ?>
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_card_no">მივიღე ბარათი №</label>
                    <div class="field">
                        <input type="text" id="anketa_card_no" name="anketa_card_no" value="<?php echo $v('anketa_card_no'); ?>" />
                    </div>
                </div>

                <!-- SMS consent -->
                <div class="row club-anketa-sms-consent" data-context="registration">
                    <span class="label">SMS შეტყობინებების მიღების თანხმობა</span>
                    <div class="field sms-consent-options">
                        <label style="margin-right:12px;">
                            <input type="radio" name="anketa_sms_consent" value="yes" <?php checked($sms_old, 'yes'); ?> class="sms-consent-radio" />
                            დიახ
                        </label>
                        <label>
                            <input type="radio" name="anketa_sms_consent" value="no" <?php checked($sms_old, 'no'); ?> class="sms-consent-radio" />
                            არა
                        </label>
                    </div>
                    <input type="hidden" name="otp_verification_token" value="" class="otp-verification-token" />
                </div>

                <!-- Call consent -->
                <div class="row club-anketa-sms-consent" data-context="registration">
                    <span class="label"><?php esc_html_e('თანხმობა სატელეფონო ზარზე', 'club-anketa'); ?></span>
                    <div class="field sms-consent-options">
                        <label style="margin-right:12px;">
                            <input type="radio" name="anketa_call_consent" value="yes" <?php checked($call_old, 'yes'); ?> class="call-consent-radio" />
                            <?php esc_html_e('დიახ', 'club-anketa'); ?>
                        </label>
                        <label>
                            <input type="radio" name="anketa_call_consent" value="no" <?php checked($call_old, 'no'); ?> class="call-consent-radio" />
                            <?php esc_html_e('არა', 'club-anketa'); ?>
                        </label>
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_responsible_person">პასუხისმგებელი პირი</label>
                    <div class="field">
                        <input type="text" id="anketa_responsible_person" name="anketa_responsible_person" value="<?php echo $v('anketa_responsible_person'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_form_date">თარიღი</label>
                    <div class="field">
                        <input type="date" id="anketa_form_date" name="anketa_form_date" value="<?php echo $v('anketa_form_date'); ?>" />
                    </div>
                </div>

                <div class="row">
                    <label class="label" for="anketa_shop">მაღაზია</label>
                    <div class="field">
                        <input type="text" id="anketa_shop" name="anketa_shop" value="<?php echo $v('anketa_shop'); ?>" />
                    </div>
                </div>

                <div class="submit-row">
                    <button type="submit" class="submit-btn">რეგისტრაცია</button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}