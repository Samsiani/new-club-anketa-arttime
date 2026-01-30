/**
 * SMS OTP Verification Script
 * Club Anketa Registration for WooCommerce
 * Version: 2.2.0
 * 
 * Implements inline phone verification with:
 * - Verify button next to phone field (inline, same row)
 * - Modal popup for OTP entry
 * - Real-time verification status tracking
 * - Reset on phone number change (edit detection)
 * - Form submission blocking until verified
 * 
 * Works on four locations:
 * 1. Registration Shortcode Form ([club_anketa_form]) - #anketa_phone_local
 * 2. WooCommerce Checkout Page - #billing_phone
 * 3. WooCommerce Registration Form - #reg_billing_phone
 * 4. My Account - Edit Address/Details Page - #account_phone
 */
(function($) {
    'use strict';

    // Exit if clubAnketaSms is not defined
    if (typeof clubAnketaSms === 'undefined') {
        return;
    }

    var i18n = clubAnketaSms.i18n;
    var ajaxUrl = clubAnketaSms.ajaxUrl;
    var nonce = clubAnketaSms.nonce;

    // State variables
    var verifiedPhone = clubAnketaSms.verifiedPhone || '';
    var sessionVerifiedPhone = ''; // Phone verified in current session (not yet saved)
    var verificationToken = '';
    var countdownInterval = null;
    var resendCountdown = 60;
    var pendingFormSubmit = false;
    var currentPhoneField = null; // Track the phone field that triggered verification

    /**
     * Initialize the SMS verification system
     */
    function init() {
        // Inject OTP Modal HTML - ensure it's at the end of body for proper positioning
        injectModalHtml();

        // Inject verify button for WooCommerce fields that don't have it
        injectVerifyButtonForWooCommerce();

        // Bind events
        bindEvents();

        // Initialize phone field states
        initializePhoneFields();

        // Update submit button states
        updateSubmitButtonStates();

        // Re-initialize on WooCommerce AJAX events (for checkout updates)
        $(document.body).on('updated_checkout', function() {
            injectVerifyButtonForWooCommerce();
            initializePhoneFields();
            updateSubmitButtonStates();
        });
    }

    /**
     * Inject verify button for WooCommerce and other phone fields
     * This handles checkout, registration, and account pages
     * 
     * Creates a clean sibling relationship with Flexbox layout:
     * <div class="phone-verify-group">
     *    <input class="phone-field">
     *    <div class="phone-verify-container">
     *        <button>Verify</button>
     *    </div>
     * </div>
     */
    function injectVerifyButtonForWooCommerce() {
        // Array of phone field selectors to target
        var phoneSelectors = [
            '#billing_phone',        // WooCommerce Checkout
            '#reg_billing_phone',    // WooCommerce Registration Form
            '#account_phone',        // My Account > Account Details
            '#anketa_phone_local'    // Registration Shortcode Form (already handled in PHP)
        ];

        phoneSelectors.forEach(function(selector) {
            var $phoneInput = $(selector);
            
            // Skip if not found or already has verify group
            if ($phoneInput.length === 0 || $phoneInput.closest('.phone-verify-group').length > 0) {
                return;
            }

            // Check if PHP has appended a phone-verify-container somewhere near the input
            // Search in the parent's siblings and the parent's parent (to handle various WooCommerce structures)
            var $existingContainer = null;
            
            // First check: Look for container as a sibling of the input
            $existingContainer = $phoneInput.siblings('.phone-verify-container');
            
            // Second check: Look in the input wrapper's siblings
            if ($existingContainer.length === 0) {
                var $inputWrapper = $phoneInput.parent();
                $existingContainer = $inputWrapper.siblings('.phone-verify-container');
            }
            
            // Third check: Look in the form-row wrapper's siblings (WooCommerce structure)
            if ($existingContainer.length === 0) {
                var $formRow = $phoneInput.closest('.form-row, p');
                $existingContainer = $formRow.siblings('.phone-verify-container');
            }
            
            // Fourth check: Look for any container that comes after this input in the DOM
            if ($existingContainer.length === 0) {
                var $formRow = $phoneInput.closest('.form-row, p');
                $existingContainer = $formRow.nextAll('.phone-verify-container').first();
            }
            
            // Fifth check: Look anywhere within the same form for a phone-verify-container
            // that hasn't been associated with another input yet
            if ($existingContainer.length === 0) {
                var $form = $phoneInput.closest('form');
                if ($form.length > 0) {
                    $existingContainer = $form.find('.phone-verify-container').not('.phone-verify-group .phone-verify-container').first();
                }
            }
            
            if ($existingContainer.length > 0) {
                // PHP has appended the container; wrap input and move container into a phone-verify-group
                // Create wrapper div
                var $wrapper = $('<div class="phone-verify-group wc-phone-verify-group"></div>');
                
                // Insert wrapper before the input (within the input's current container)
                $phoneInput.before($wrapper);
                
                // Move input into the wrapper
                $wrapper.append($phoneInput);
                
                // Use detach() to properly move the element from its current position
                // detach() removes the element from the DOM and returns it for re-insertion
                var $detachedContainer = $existingContainer.detach();
                $wrapper.append($detachedContainer);
            } else {
                // No existing container from PHP, create everything from scratch
                // Wrap the phone input in phone-verify-group container (Flexbox parent)
                $phoneInput.wrap('<div class="phone-verify-group wc-phone-verify-group"></div>');
                
                // Add verify container as a sibling AFTER the input (not inside)
                // This creates the clean sibling relationship required for side-by-side Flexbox layout
                var verifyHtml = '<div class="phone-verify-container">' +
                    '<button type="button" class="phone-verify-btn" aria-label="' + i18n.verify + '">' + 
                    (i18n.verifyBtn || 'Verify') + '</button>' +
                    '<span class="phone-verified-icon" style="display:none;" aria-label="' + i18n.verified + '">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    '</span></div>';
                $phoneInput.after(verifyHtml);
            }
        });
    }

    /**
     * Inject the OTP Modal HTML into the page
     * CRITICAL: Appends as last child of body to ensure it breaks out of any theme containers
     */
    function injectModalHtml() {
        // Remove existing modal first to ensure it's the last child of body
        var $existingModal = $('#club-anketa-otp-modal');
        if ($existingModal.length > 0) {
            $existingModal.remove();
        }

        var modalHtml = '<div id="club-anketa-otp-modal" class="club-anketa-modal" style="display:none;">' +
            '<div class="club-anketa-modal-overlay"></div>' +
            '<div class="club-anketa-modal-content">' +
            '<button type="button" class="club-anketa-modal-close">&times;</button>' +
            '<div class="club-anketa-modal-header">' +
            '<h3>' + i18n.modalTitle + '</h3>' +
            '<p class="modal-subtitle">' + i18n.modalSubtitle + ' <span class="otp-phone-display"></span></p>' +
            '</div>' +
            '<div class="club-anketa-modal-body">' +
            '<div class="otp-input-container">' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="0" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]" />' +
            '<input type="text" class="otp-digit" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]" />' +
            '</div>' +
            '<div class="otp-message"></div>' +
            '<div class="otp-resend-container">' +
            '<span class="otp-countdown" style="display:none;">' + i18n.resendIn + ' <span class="countdown-timer">60</span> წამი</span>' +
            '<button type="button" class="otp-resend-btn" style="display:none;">' + i18n.resend + '</button>' +
            '</div>' +
            '</div>' +
            '<div class="club-anketa-modal-footer">' +
            '<button type="button" class="otp-verify-btn">' + i18n.verify + '</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        // CRITICAL: Append to body as the last child to ensure it's outside any theme containers
        // This prevents the modal from being trapped inside restricted parent elements
        $(document.body).append(modalHtml);
    }

    /**
     * Initialize phone field states on page load
     */
    function initializePhoneFields() {
        // Check each phone field and set initial state
        // Include all possible phone field selectors
        $('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').each(function() {
            var $input = $(this);
            var currentPhone = normalizePhone($input.val());
            updatePhoneFieldState($input, currentPhone);
        });
    }

    /**
     * Normalize phone number to 9-digit format
     */
    function normalizePhone(phone) {
        if (!phone) return '';
        
        var digits = phone.replace(/\D/g, '');
        
        // If it includes country code, extract local part
        if (digits.length > 9 && digits.indexOf('995') === 0) {
            digits = digits.substring(3);
        }
        
        // Take only last 9 digits
        if (digits.length > 9) {
            digits = digits.substring(digits.length - 9);
        }
        
        return digits;
    }

    /**
     * Check if a phone number is verified
     */
    function isPhoneVerified(phone) {
        var normalizedPhone = normalizePhone(phone);
        if (!normalizedPhone || normalizedPhone.length !== 9) {
            return false;
        }
        
        // Check against stored verified phone or session verified phone
        return normalizedPhone === verifiedPhone || normalizedPhone === sessionVerifiedPhone;
    }

    /**
     * Update the visual state of a phone field (button vs checkmark)
     */
    function updatePhoneFieldState($input, currentPhone) {
        var $container = $input.closest('.phone-verify-group, .phone-group');
        var $verifyBtn = $container.find('.phone-verify-btn');
        var $verifiedIcon = $container.find('.phone-verified-icon');
        
        if ($verifyBtn.length === 0 && $verifiedIcon.length === 0) {
            return; // No verify UI for this field
        }

        var normalizedPhone = normalizePhone(currentPhone);
        var phoneValid = normalizedPhone.length === 9;
        var phoneVerified = isPhoneVerified(normalizedPhone);

        if (phoneVerified && phoneValid) {
            // State 2: Verified - show green checkmark
            $verifyBtn.hide();
            $verifiedIcon.show();
            $container.addClass('phone-verified').removeClass('phone-unverified');
        } else if (phoneValid) {
            // State 1: Unverified but valid - show verify button
            $verifyBtn.show();
            $verifiedIcon.hide();
            $container.addClass('phone-unverified').removeClass('phone-verified');
        } else {
            // Invalid/empty - hide both
            $verifyBtn.hide();
            $verifiedIcon.hide();
            $container.removeClass('phone-verified phone-unverified');
        }

        // Update submit button states
        updateSubmitButtonStates();
    }

    /**
     * Update submit button enabled/disabled states based on verification
     * Applies to: Checkout, My Account Edit Address/Details, WooCommerce Registration
     * NOTE: Anketa form (.club-anketa-form) does NOT require verification - submission is allowed without it
     */
    function updateSubmitButtonStates() {
        // Find all forms with phone verification (including edit-address form and registration)
        $('.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register').each(function() {
            var $form = $(this);
            var $phoneInput = $form.find('#anketa_phone_local, .phone-local, #billing_phone, #reg_billing_phone, #account_phone').first();
            var $submitBtn = $form.find('.submit-btn, button[type="submit"], input[type="submit"]').not('.phone-verify-btn, .otp-verify-btn, .otp-resend-btn');
            
            if ($phoneInput.length === 0 || $submitBtn.length === 0) {
                return;
            }

            var currentPhone = normalizePhone($phoneInput.val());
            var phoneValid = currentPhone.length === 9;
            var phoneVerified = isPhoneVerified(currentPhone);

            // Check if phone field verification is required
            // Anketa form (.club-anketa-form) does NOT require verification
            var isAnketaForm = $form.hasClass('club-anketa-form');
            var isCheckout = $form.hasClass('checkout');
            var isAccountForm = $form.hasClass('woocommerce-EditAccountForm') || $form.hasClass('edit-address');
            var isWcRegistration = $form.hasClass('woocommerce-form-register') || $form.hasClass('register');
            
            // Only Checkout, Account, and WooCommerce Registration forms require verification
            // Anketa form is explicitly excluded from verification requirement
            var requiresVerification = !isAnketaForm && (isCheckout || isAccountForm || isWcRegistration || $form.find('.phone-verify-group').length > 0);

            if (requiresVerification && phoneValid && !phoneVerified) {
                // Phone is filled but not verified - disable submit
                $submitBtn.prop('disabled', true).addClass('verification-blocked');
                
                // Add or update warning message
                if (!$form.find('.phone-verify-warning').length) {
                    $submitBtn.before('<p class="phone-verify-warning">' + (i18n.verificationRequired || 'Phone verification required') + '</p>');
                }
            } else {
                // Enable submit (either verified or not required)
                $submitBtn.prop('disabled', false).removeClass('verification-blocked');
                $form.find('.phone-verify-warning').remove();
            }
        });
    }

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Phone input change - real-time monitoring (edit detection)
        // Include all phone field selectors
        $(document).on('input change', '.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local', function() {
            var $input = $(this);
            var currentPhone = $input.val();
            // Store reference to current field for later use
            currentPhoneField = $input;
            updatePhoneFieldState($input, currentPhone);
        });

        // Verify button click - Opens modal immediately
        $(document).on('click', '.phone-verify-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $btn = $(this);
            var $container = $btn.closest('.phone-verify-group, .phone-group');
            var $input = $container.find('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local, input[type="tel"]').first();
            
            // Fallback for Checkout page if grouping failed or input not found
            if ($input.length === 0) {
                // Try to find the input based on the button's position in DOM
                var $verifyContainer = $btn.closest('.phone-verify-container');
                if ($verifyContainer.length > 0) {
                    // Look for sibling input or nearby phone input
                    $input = $verifyContainer.siblings('input[type="tel"], #billing_phone, #reg_billing_phone, #account_phone').first();
                    if ($input.length === 0) {
                        $input = $verifyContainer.prev('input[type="tel"], #billing_phone, #reg_billing_phone, #account_phone');
                    }
                }
                
                // Final fallback: find the closest phone field by traversing up to parent form
                // This is more reliable than blindly selecting any phone field on the page
                if ($input.length === 0) {
                    var $form = $btn.closest('form');
                    if ($form.length > 0) {
                        // Try to find phone input within the same form
                        $input = $form.find('#billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local, .phone-local, input[type="tel"]').first();
                    }
                    
                    // Ultimate fallback: search the entire page for known phone field selectors
                    // This handles edge cases where the button may not be inside a form
                    if ($input.length === 0) {
                        $input = $('#billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').first();
                    }
                }
            }
            
            var phone = normalizePhone($input.val());
            
            if (!phone || phone.length !== 9) {
                showModalError(i18n.phoneRequired || 'Phone number must be 9 digits');
                $input.focus();
                return;
            }

            // Store reference to the phone field that triggered this
            currentPhoneField = $input;

            // Disable button and show loading
            $btn.prop('disabled', true).addClass('loading');

            // Open modal first, then send OTP (better UX)
            openModal(phone);
            showMessage(i18n.sendingOtp || 'Sending code...', 'info');

            // Send OTP
            sendOtp(phone, function() {
                $btn.prop('disabled', false).removeClass('loading');
                showMessage(i18n.enterCode || 'Enter the 6-digit code', 'success');
                startResendCountdown(60);
            }, function(errorMessage) {
                $btn.prop('disabled', false).removeClass('loading');
                // Show error in modal, don't close it
                showMessage(errorMessage || i18n.error, 'error');
            });
        });

        // Form submission interception - block if verification required
        $(document).on('submit', '.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register', function(e) {
            return handleFormSubmit(e, $(this));
        });

        // WooCommerce AJAX checkout interception
        $(document.body).on('checkout_error', function() {
            // Re-check verification status after WooCommerce validation errors
            updateSubmitButtonStates();
        });

        // Close modal
        $(document).on('click', '.club-anketa-modal-close, .club-anketa-modal-overlay', closeModal);

        // OTP digit input handling
        $(document).on('input', '.otp-digit', handleOtpInput);
        $(document).on('keydown', '.otp-digit', handleOtpKeydown);
        $(document).on('paste', '.otp-digit', handleOtpPaste);

        // Verify OTP button
        $(document).on('click', '.otp-verify-btn', verifyOtp);

        // Resend OTP button
        $(document).on('click', '.otp-resend-btn', function() {
            var phone = $('.otp-phone-display').data('phone');
            if (phone) {
                sendOtp(phone);
            }
        });

        // Close modal on Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Handle form submission
     * Blocks submission if phone is filled but not verified
     * NOTE: Anketa form (.club-anketa-form) is allowed to submit without verification
     */
    function handleFormSubmit(e, $form) {
        var $phoneInput = $form.find('#anketa_phone_local, .phone-local, #billing_phone, #reg_billing_phone, #account_phone').first();
        
        if ($phoneInput.length === 0) {
            return true; // No phone field, allow submission
        }

        var currentPhone = normalizePhone($phoneInput.val());
        
        // Check if phone is filled but not verified
        // Anketa form (.club-anketa-form) does NOT require verification - allow submission
        var isAnketaForm = $form.hasClass('club-anketa-form');
        var isCheckout = $form.hasClass('checkout');
        var isAccountForm = $form.hasClass('woocommerce-EditAccountForm') || $form.hasClass('edit-address');
        var isWcRegistration = $form.hasClass('woocommerce-form-register') || $form.hasClass('register');
        
        // Only Checkout, Account, and WooCommerce Registration forms require verification
        // Anketa form is explicitly excluded from verification requirement
        var requiresVerification = !isAnketaForm && (isCheckout || isAccountForm || isWcRegistration || $form.find('.phone-verify-group').length > 0);

        if (requiresVerification && currentPhone.length === 9 && !isPhoneVerified(currentPhone)) {
            e.preventDefault();
            e.stopPropagation();

            // Highlight the verify button
            var $verifyBtn = $form.find('.phone-verify-btn');
            if ($verifyBtn.length > 0 && $verifyBtn.is(':visible')) {
                $verifyBtn.addClass('highlight-pulse');
                setTimeout(function() {
                    $verifyBtn.removeClass('highlight-pulse');
                }, 2000);
            }

            // Show a more descriptive message instead of alert
            showModalError(i18n.verificationRequired || 'Please verify your phone number before submitting.');
            return false;
        }

        // Update verification token in form
        updateVerificationToken(verificationToken);

        return true;
    }

    /**
     * Show modal error - display error in modal or create alert modal
     */
    function showModalError(message) {
        var $modal = $('#club-anketa-otp-modal');
        if ($modal.is(':visible')) {
            showMessage(message, 'error');
        } else {
            // Create a temporary alert for errors outside modal
            alert(message);
        }
    }

    /**
     * Send OTP via AJAX
     * Modal should already be open when this is called
     * Enhanced error handling with console logging for debugging
     */
    function sendOtp(phone, successCallback, errorCallback) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'club_anketa_send_otp',
                nonce: nonce,
                phone: phone
            },
            success: function(response) {
                // Log the full response for debugging
                console.log('[Club Anketa SMS] Send OTP response:', response);
                
                if (response.success) {
                    if (typeof successCallback === 'function') {
                        successCallback();
                    }
                } else {
                    // Log detailed error info for debugging API/credential issues
                    console.error('[Club Anketa SMS] OTP send failed:', {
                        message: response.data && response.data.message ? response.data.message : 'Unknown error',
                        data: response.data,
                        phone: phone
                    });
                    
                    // Pass the specific error message to the callback
                    var errorMsg = response.data && response.data.message ? response.data.message : (i18n.error || 'SMS sending failed');
                    showMessage(errorMsg, 'error');
                    if (typeof errorCallback === 'function') {
                        errorCallback(errorMsg);
                    }
                }
            },
            error: function(xhr, status, error) {
                // Log detailed network/server error for debugging
                console.error('[Club Anketa SMS] AJAX error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    statusCode: xhr.status
                });
                
                var errorMsg = i18n.error || 'Network error. Please try again.';
                showMessage(errorMsg, 'error');
                if (typeof errorCallback === 'function') {
                    errorCallback(errorMsg);
                }
            }
        });
    }

    /**
     * Verify OTP via AJAX
     * Enhanced error handling with console logging for debugging
     */
    function verifyOtp() {
        var code = getOtpCode();
        var phone = $('.otp-phone-display').data('phone');

        if (code.length !== 6) {
            showMessage(i18n.enterCode || 'Please enter the 6-digit code', 'error');
            return;
        }

        var $btn = $('.otp-verify-btn');
        $btn.prop('disabled', true).text(i18n.verifying || 'Verifying...');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'club_anketa_verify_otp',
                nonce: nonce,
                phone: phone,
                code: code
            },
            success: function(response) {
                // Log the full response for debugging
                console.log('[Club Anketa SMS] Verify OTP response:', response);
                
                $btn.prop('disabled', false).text(i18n.verify || 'Verify');

                if (response.success) {
                    // Update session verified phone
                    sessionVerifiedPhone = response.data.verifiedPhone || phone;
                    verificationToken = response.data.token;
                    
                    // Update stored verified phone if returned
                    if (response.data.verifiedPhone) {
                        verifiedPhone = response.data.verifiedPhone;
                    }

                    updateVerificationToken(verificationToken);
                    showMessage(i18n.verified || 'Verified!', 'success');
                    
                    // Update all phone field states (include all selectors)
                    $('.phone-local, #billing_phone, #reg_billing_phone, #account_phone, #anketa_phone_local').each(function() {
                        updatePhoneFieldState($(this), $(this).val());
                    });

                    // Close modal after a short delay
                    setTimeout(function() {
                        closeModal();
                        // Update submit button states after verification
                        updateSubmitButtonStates();
                    }, 800);
                } else {
                    // Log detailed error info for debugging
                    console.error('[Club Anketa SMS] OTP verification failed:', {
                        message: response.data && response.data.message ? response.data.message : 'Unknown error',
                        data: response.data,
                        phone: phone,
                        code: code
                    });
                    
                    // Show the SPECIFIC error message returned by the backend
                    var errorMsg = (response.data && response.data.message) 
                        ? response.data.message 
                        : (i18n.invalidCode || 'Invalid code');
                    showMessage(errorMsg, 'error');
                    clearOtpInputs();
                }
            },
            error: function(xhr, status, error) {
                // Log detailed network/server error for debugging
                console.error('[Club Anketa SMS] Verify AJAX error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    statusCode: xhr.status
                });
                
                $btn.prop('disabled', false).text(i18n.verify || 'Verify');
                showMessage(i18n.error || 'Network error. Please try again.', 'error');
            }
        });
    }

    /**
     * Update verification token in hidden field
     */
    function updateVerificationToken(token) {
        $('.otp-verification-token').val(token);
        
        // Also add to any form that needs it (including edit-address form and registration)
        $('form.club-anketa-form, form.checkout, form.woocommerce-EditAccountForm, form.edit-address, form.woocommerce-form-register, form.register').each(function() {
            var $form = $(this);
            if (!$form.find('.otp-verification-token').length) {
                $form.append('<input type="hidden" name="otp_verification_token" value="' + token + '" class="otp-verification-token" />');
            } else {
                $form.find('.otp-verification-token').val(token);
            }
        });
    }

    /**
     * Get OTP code from inputs
     */
    function getOtpCode() {
        var code = '';
        $('.otp-digit').each(function() {
            code += $(this).val();
        });
        return code;
    }

    /**
     * Clear OTP inputs
     */
    function clearOtpInputs() {
        $('.otp-digit').val('').first().focus();
    }

    /**
     * Handle OTP input
     */
    function handleOtpInput() {
        var $this = $(this);
        var val = $this.val();

        // Only allow digits
        val = val.replace(/\D/g, '');
        if (val.length > 1) {
            val = val.charAt(0);
        }
        $this.val(val);

        // Auto-advance to next input
        if (val.length === 1) {
            var index = parseInt($this.data('index'));
            var $next = $('.otp-digit[data-index="' + (index + 1) + '"]');
            if ($next.length > 0) {
                $next.focus();
            }
        }

        // Check if all digits are filled
        if (getOtpCode().length === 6) {
            verifyOtp();
        }
    }

    /**
     * Handle OTP keydown
     */
    function handleOtpKeydown(e) {
        var $this = $(this);
        var index = parseInt($this.data('index'));

        // Handle backspace
        if (e.key === 'Backspace' && $this.val() === '' && index > 0) {
            var $prev = $('.otp-digit[data-index="' + (index - 1) + '"]');
            $prev.val('').focus();
        }

        // Handle left/right arrow keys
        if (e.key === 'ArrowLeft' && index > 0) {
            $('.otp-digit[data-index="' + (index - 1) + '"]').focus();
        }
        if (e.key === 'ArrowRight' && index < 5) {
            $('.otp-digit[data-index="' + (index + 1) + '"]').focus();
        }
    }

    /**
     * Handle OTP paste
     */
    function handleOtpPaste(e) {
        e.preventDefault();
        var pastedData = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
        var digits = pastedData.replace(/\D/g, '').substring(0, 6);

        for (var i = 0; i < digits.length; i++) {
            $('.otp-digit[data-index="' + i + '"]').val(digits.charAt(i));
        }

        // Focus the next empty input or last input
        var nextEmpty = $('.otp-digit').filter(function() { return !$(this).val(); }).first();
        if (nextEmpty.length > 0) {
            nextEmpty.focus();
        } else {
            $('.otp-digit').last().focus();
        }

        // Auto-verify if 6 digits pasted
        if (digits.length === 6) {
            verifyOtp();
        }
    }

    /**
     * Open OTP modal
     */
    function openModal(phone) {
        var formattedPhone = '+995 ' + phone;
        $('.otp-phone-display').text(formattedPhone).data('phone', phone);
        clearOtpInputs();
        $('.otp-message').empty().removeClass('success error info');
        $('#club-anketa-otp-modal').fadeIn(200);
        $('.otp-digit').first().focus();
        $('body').addClass('club-anketa-modal-open');
    }

    /**
     * Close OTP modal
     */
    function closeModal() {
        $('#club-anketa-otp-modal').fadeOut(200);
        $('body').removeClass('club-anketa-modal-open');
        clearCountdown();
    }

    /**
     * Show message in modal
     */
    function showMessage(message, type) {
        var $msgEl = $('.otp-message');
        $msgEl.removeClass('success error info').addClass(type).text(message);
    }

    /**
     * Start resend countdown
     */
    function startResendCountdown(seconds) {
        resendCountdown = seconds || 60;
        $('.otp-resend-btn').hide();
        $('.otp-countdown').show();

        clearCountdown();

        updateCountdownDisplay();
        countdownInterval = setInterval(function() {
            resendCountdown--;
            updateCountdownDisplay();

            if (resendCountdown <= 0) {
                clearCountdown();
                $('.otp-countdown').hide();
                $('.otp-resend-btn').show();
            }
        }, 1000);
    }

    /**
     * Update countdown display
     */
    function updateCountdownDisplay() {
        $('.countdown-timer').text(resendCountdown);
    }

    /**
     * Clear countdown interval
     */
    function clearCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
