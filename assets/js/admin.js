/**
 * Centralized Variation Price Manager - Admin JavaScript
 */

(function($) {
    'use strict';

    var CVPM = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initPriceInputs();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Update button click
            $(document).on('click', '.cvpm-update-btn', this.handleUpdateClick.bind(this));

            // Price input change detection
            $(document).on('input', '.cvpm-price-input', this.handlePriceChange.bind(this));

            // Enter key on price inputs
            $(document).on('keypress', '.cvpm-price-input', this.handleKeyPress.bind(this));
        },

        /**
         * Initialize price inputs
         */
        initPriceInputs: function() {
            $('.cvpm-price-input').each(function() {
                $(this).data('original', $(this).val());
            });
        },

        /**
         * Handle price input change
         */
        handlePriceChange: function(e) {
            var $input = $(e.target);
            var original = $input.data('original');
            var current = $input.val();

            // Remove error class
            $input.removeClass('error');

            // Add/remove changed class
            if (current !== original) {
                $input.addClass('changed');
            } else {
                $input.removeClass('changed');
            }
        },

        /**
         * Handle Enter key press
         */
        handleKeyPress: function(e) {
            if (e.which === 13) {
                e.preventDefault();
                var $row = $(e.target).closest('tr');
                $row.find('.cvpm-update-btn').trigger('click');
            }
        },

        /**
         * Handle update button click
         */
        handleUpdateClick: function(e) {
            e.preventDefault();

            var $btn = $(e.target);
            var $row = $btn.closest('tr');

            // Get data
            var variationIds = JSON.parse($row.attr('data-variation-ids') || '[]');
            var productIds = JSON.parse($row.attr('data-product-ids') || '[]');
            var regularPrice = $row.find('.regular-price-input').val();
            var salePrice = $row.find('.sale-price-input').val();

            // Validate prices
            if (!this.validatePrices(regularPrice, salePrice, $row)) {
                return;
            }

            // Get variation count for confirmation
            var count = variationIds.length;
            var confirmMsg = cvpmData.confirmMessage.replace('%d', count);

            if (!confirm(confirmMsg)) {
                return;
            }

            // Perform update
            this.updatePrices($row, variationIds, productIds, regularPrice, salePrice);
        },

        /**
         * Validate price inputs
         */
        validatePrices: function(regularPrice, salePrice, $row) {
            var isValid = true;
            var $regularInput = $row.find('.regular-price-input');
            var $saleInput = $row.find('.sale-price-input');

            // Remove previous error states
            $regularInput.removeClass('error');
            $saleInput.removeClass('error');

            // Validate regular price
            if (regularPrice !== '' && !this.isValidPrice(regularPrice)) {
                $regularInput.addClass('error');
                isValid = false;
            }

            // Validate sale price
            if (salePrice !== '' && !this.isValidPrice(salePrice)) {
                $saleInput.addClass('error');
                isValid = false;
            }

            // Check sale price is less than regular price
            if (regularPrice !== '' && salePrice !== '' && 
                parseFloat(salePrice) >= parseFloat(regularPrice)) {
                $saleInput.addClass('error');
                this.showNotice('error', 'Sale price must be less than regular price.');
                return false;
            }

            if (!isValid) {
                this.showNotice('error', cvpmData.invalidPriceError);
            }

            return isValid;
        },

        /**
         * Check if price is valid
         */
        isValidPrice: function(price) {
            if (price === '') {
                return true;
            }
            var num = parseFloat(price);
            return !isNaN(num) && num >= 0;
        },

        /**
         * Update prices via AJAX
         */
        updatePrices: function($row, variationIds, productIds, regularPrice, salePrice) {
            var self = this;
            var $btn = $row.find('.cvpm-update-btn');
            var originalText = $btn.text();

            // Set loading state
            $btn.prop('disabled', true).addClass('updating').html('<span class="cvpm-spinner"></span>' + cvpmData.updatingMessage);
            $row.addClass('updating');

            // AJAX request
            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_update_variation_prices',
                    nonce: cvpmData.nonce,
                    variation_ids: variationIds,
                    product_ids: productIds,
                    regular_price: regularPrice,
                    sale_price: salePrice
                },
                success: function(response) {
                    $row.removeClass('updating');
                    $btn.prop('disabled', false).removeClass('updating').text(originalText);

                    if (response.success) {
                        self.handleUpdateSuccess($row, response.data);
                    } else {
                        self.handleUpdateError($row, response.data.message);
                    }
                },
                error: function() {
                    $row.removeClass('updating');
                    $btn.prop('disabled', false).removeClass('updating').text(originalText);
                    self.handleUpdateError($row, cvpmData.errorMessage);
                }
            });
        },

        /**
         * Handle successful update
         */
        handleUpdateSuccess: function($row, data) {
            // Update original values
            var $regularInput = $row.find('.regular-price-input');
            var $saleInput = $row.find('.sale-price-input');

            $regularInput.data('original', $regularInput.val()).removeClass('changed');
            $saleInput.data('original', $saleInput.val()).removeClass('changed');

            // Update current price display
            if (data.new_current_price) {
                $row.find('.cvpm-current-price').html(data.new_current_price);
            }

            // Visual feedback
            $row.addClass('updated');
            setTimeout(function() {
                $row.removeClass('updated');
            }, 2000);

            // Show success notice
            this.showNotice('success', data.message);
        },

        /**
         * Handle update error
         */
        handleUpdateError: function($row, message) {
            // Visual feedback
            $row.addClass('error');
            setTimeout(function() {
                $row.removeClass('error');
            }, 2000);

            // Show error notice
            this.showNotice('error', message);
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            var $container = $('#cvpm-notices');
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';

            // Remove existing notices of same type
            $container.find('.notice-' + type).remove();

            // Create notice
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

            // Add dismiss button
            var $dismissBtn = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            $dismissBtn.on('click', function() {
                $notice.fadeOut(200, function() {
                    $(this).remove();
                });
            });
            $notice.append($dismissBtn);

            // Show notice
            $container.prepend($notice);

            // Auto-dismiss success notices
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(200, function() {
                        $(this).remove();
                    });
                }, 5000);
            }

            // Scroll to notice
            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        CVPM.init();
    });

})(jQuery);
