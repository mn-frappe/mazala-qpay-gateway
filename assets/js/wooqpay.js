/**
 * WooQPay Frontend JavaScript
 *
 * Handles qPay payment interactions on the frontend
 *
 * @package WooQPay
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * WooQPay Frontend Handler
     */
    var WooQPay = {
        
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initQRModal();
            this.initPaymentPolling();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            $(document).on('click', '.wooqpay-qr-expand', this.expandQR);
            $(document).on('click', '.wooqpay-modal-close', this.closeModal);
            $(document).on('click', '.wooqpay-modal-overlay', this.closeModal);
            $(document).on('click', '.wooqpay-copy-deeplink', this.copyDeeplink);
            $(document).on('click', '.wooqpay-bank-link', this.handleBankLink);
            $(document).on('keydown', this.handleKeydown);
        },

        /**
         * Initialize QR Modal
         */
        initQRModal: function() {
            if ($('.wooqpay-qr-modal').length === 0) {
                $('body').append(
                    '<div class="wooqpay-modal-overlay" style="display:none;">' +
                    '<div class="wooqpay-qr-modal">' +
                    '<button class="wooqpay-modal-close">&times;</button>' +
                    '<div class="wooqpay-modal-content"></div>' +
                    '</div></div>'
                );
            }
        },

        /**
         * Expand QR code in modal
         */
        expandQR: function(e) {
            e.preventDefault();
            var $qr = $(this).closest('.wooqpay-qr-container').find('.wooqpay-qr-image');
            if ($qr.length) {
                var src = $qr.attr('src');
                $('.wooqpay-modal-content').html('<img src="' + src + '" alt="QR Code" style="max-width:100%;height:auto;">');
                $('.wooqpay-modal-overlay').fadeIn(200);
            }
        },

        /**
         * Close modal
         */
        closeModal: function(e) {
            if ($(e.target).hasClass('wooqpay-modal-overlay') || $(e.target).hasClass('wooqpay-modal-close')) {
                $('.wooqpay-modal-overlay').fadeOut(200);
            }
        },

        /**
         * Handle keyboard events
         */
        handleKeydown: function(e) {
            if (e.key === 'Escape' && $('.wooqpay-modal-overlay').is(':visible')) {
                $('.wooqpay-modal-overlay').fadeOut(200);
            }
        },

        /**
         * Copy deeplink to clipboard
         */
        copyDeeplink: function(e) {
            e.preventDefault();
            var $btn = $(this);
            var link = $btn.data('link') || $btn.attr('href');
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(function() {
                    WooQPay.showCopySuccess($btn);
                }).catch(function() {
                    WooQPay.fallbackCopy(link, $btn);
                });
            } else {
                WooQPay.fallbackCopy(link, $btn);
            }
        },

        /**
         * Fallback copy method
         */
        fallbackCopy: function(text, $btn) {
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
            WooQPay.showCopySuccess($btn);
        },

        /**
         * Show copy success feedback
         */
        showCopySuccess: function($btn) {
            var originalText = $btn.text();
            $btn.text(wooqpay_params.i18n.copied || 'Copied!');
            setTimeout(function() {
                $btn.text(originalText);
            }, 2000);
        },

        /**
         * Handle bank app link click
         */
        handleBankLink: function(e) {
            var $link = $(this);
            var deeplink = $link.data('deeplink');
            var fallback = $link.data('fallback');
            
            if (deeplink && WooQPay.isMobile()) {
                e.preventDefault();
                window.location.href = deeplink;
                
                // Fallback to app store if app not installed
                if (fallback) {
                    setTimeout(function() {
                        if (!document.hidden) {
                            window.location.href = fallback;
                        }
                    }, 2000);
                }
            }
        },

        /**
         * Check if on mobile device
         */
        isMobile: function() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        },

        /**
         * Initialize payment polling
         */
        initPaymentPolling: function() {
            var $container = $('.wooqpay-payment-pending');
            if ($container.length === 0) return;
            
            var orderId = $container.data('order-id');
            var invoiceId = $container.data('invoice-id');
            var checkUrl = $container.data('check-url');
            var interval = parseInt($container.data('poll-interval')) || 5000;
            var maxAttempts = parseInt($container.data('max-attempts')) || 60;
            var attempts = 0;
            
            if (!orderId || !checkUrl) return;
            
            var pollTimer = setInterval(function() {
                attempts++;
                
                if (attempts > maxAttempts) {
                    clearInterval(pollTimer);
                    WooQPay.showPaymentTimeout();
                    return;
                }
                
                $.ajax({
                    url: checkUrl,
                    type: 'POST',
                    data: {
                        action: 'wooqpay_check_payment',
                        order_id: orderId,
                        invoice_id: invoiceId,
                        nonce: wooqpay_params.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.paid) {
                            clearInterval(pollTimer);
                            WooQPay.handlePaymentSuccess(response.data);
                        } else if (response.data && response.data.cancelled) {
                            clearInterval(pollTimer);
                            WooQPay.handlePaymentCancelled();
                        }
                    },
                    error: function() {
                        // Silent fail, continue polling
                    }
                });
            }, interval);
        },

        /**
         * Handle successful payment
         */
        handlePaymentSuccess: function(data) {
            $('.wooqpay-payment-pending').addClass('wooqpay-payment-success');
            $('.wooqpay-status-message').html(
                '<div class="wooqpay-success">' +
                '<span class="wooqpay-success-icon">✓</span> ' +
                (wooqpay_params.i18n.payment_success || 'Payment successful!') +
                '</div>'
            );
            
            if (data.redirect_url) {
                setTimeout(function() {
                    window.location.href = data.redirect_url;
                }, 2000);
            }
        },

        /**
         * Handle cancelled payment
         */
        handlePaymentCancelled: function() {
            $('.wooqpay-payment-pending').addClass('wooqpay-payment-cancelled');
            $('.wooqpay-status-message').html(
                '<div class="wooqpay-error">' +
                (wooqpay_params.i18n.payment_cancelled || 'Payment was cancelled.') +
                '</div>'
            );
        },

        /**
         * Show payment timeout message
         */
        showPaymentTimeout: function() {
            $('.wooqpay-status-message').html(
                '<div class="wooqpay-warning">' +
                (wooqpay_params.i18n.payment_timeout || 'Payment check timed out. Please refresh the page.') +
                '</div>'
            );
        },

        /**
         * Format amount with currency
         */
        formatAmount: function(amount, currency) {
            currency = currency || 'MNT';
            return new Intl.NumberFormat('mn-MN', {
                style: 'currency',
                currency: currency,
                minimumFractionDigits: 0
            }).format(amount);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WooQPay.init();
    });

    // Expose to global scope if needed
    window.WooQPay = WooQPay;

})(jQuery);
