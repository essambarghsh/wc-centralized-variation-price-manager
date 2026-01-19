/**
 * WC Centralized Variation Price Manager - Admin JavaScript
 */

(function($) {
    'use strict';

    var CVPM = {
        /**
         * Current job ID
         */
        currentJobId: null,

        /**
         * Polling interval reference
         */
        pollInterval: null,

        /**
         * Active jobs polling interval
         */
        activeJobsPollInterval: null,

        /**
         * Current row being updated
         */
        currentRow: null,

        /**
         * Last log count for incremental updates
         */
        lastLogCount: 0,

        /**
         * Currently processing variation IDs
         */
        processingVariationIds: [],

        /**
         * Active jobs data
         */
        activeJobs: [],

        /**
         * Log counts per job for incremental updates
         */
        jobLogCounts: {},

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initPriceInputs();
            this.initActiveJobsMonitoring();
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

            // Progress dialog close button
            $(document).on('click', '.cvpm-progress-close, .cvpm-close-btn', this.closeProgressDialog.bind(this));

            // Cancel button
            $(document).on('click', '.cvpm-cancel-btn', this.handleCancel.bind(this));

            // Close dialog on overlay click
            $(document).on('click', '.cvpm-progress-overlay', function(e) {
                if ($(e.target).hasClass('cvpm-progress-overlay')) {
                    CVPM.closeProgressDialog();
                }
            });

            // Escape key to close dialog
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#cvpm-progress-overlay').is(':visible')) {
                    CVPM.closeProgressDialog();
                }
            });

            // Active jobs card - cancel job button
            $(document).on('click', '.cvpm-job-cancel-btn', this.handleActiveJobCancel.bind(this));

            // Active jobs card - toggle logs
            $(document).on('click', '.cvpm-job-logs-toggle', this.handleToggleJobLogs.bind(this));
        },

        /**
         * Initialize active jobs monitoring
         */
        initActiveJobsMonitoring: function() {
            // Use initial data from page load
            if (cvpmData.initialActiveJobs && cvpmData.initialActiveJobs.length > 0) {
                this.activeJobs = cvpmData.initialActiveJobs;
                this.processingVariationIds = cvpmData.initialProcessingIds || [];
                this.renderActiveJobsCard();
                this.updateProcessingRows();
                this.startActiveJobsPolling();
            }
        },

        /**
         * Start polling for active jobs
         */
        startActiveJobsPolling: function() {
            var self = this;

            // Stop existing polling if any
            this.stopActiveJobsPolling();

            // Poll every 2 seconds
            this.activeJobsPollInterval = setInterval(function() {
                self.fetchActiveJobs();
            }, 2000);
        },

        /**
         * Stop active jobs polling
         */
        stopActiveJobsPolling: function() {
            if (this.activeJobsPollInterval) {
                clearInterval(this.activeJobsPollInterval);
                this.activeJobsPollInterval = null;
            }
        },

        /**
         * Fetch active jobs from server
         */
        fetchActiveJobs: function() {
            var self = this;

            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_get_active_jobs',
                    nonce: cvpmData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.activeJobs = response.data.jobs || [];
                        self.processingVariationIds = response.data.processing_variation_ids || [];
                        self.renderActiveJobsCard();
                        self.updateProcessingRows();

                        // Stop polling if no more active jobs
                        if (self.activeJobs.length === 0) {
                            self.stopActiveJobsPolling();
                        }
                    }
                },
                error: function() {
                    // Silently fail - will retry on next poll
                }
            });
        },

        /**
         * Render active jobs card
         */
        renderActiveJobsCard: function() {
            var $container = $('#cvpm-active-jobs-container');
            
            if (this.activeJobs.length === 0) {
                $container.empty();
                return;
            }

            var html = '<div class="cvpm-active-jobs-card has-jobs">';
            html += '<div class="cvpm-active-jobs-header">';
            html += '<h3 class="cvpm-active-jobs-title"><span class="dashicons dashicons-update"></span>' + cvpmData.activeJobsTitle + '</h3>';
            html += '</div>';
            html += '<div class="cvpm-jobs-list">';

            var self = this;
            this.activeJobs.forEach(function(job) {
                html += self.renderJobItem(job);
            });

            html += '</div></div>';

            $container.html(html);
        },

        /**
         * Render a single job item
         */
        renderJobItem: function(job) {
            var data = job.data;
            var jobId = job.job_id;
            var percentage = data.percentage || 0;
            var processed = data.processed || 0;
            var total = data.total || 0;

            var html = '<div class="cvpm-job-item" data-job-id="' + this.escapeHtml(jobId) + '">';
            
            // Header with info and cancel button
            html += '<div class="cvpm-job-header">';
            html += '<span class="cvpm-job-info">';
            html += '<strong>' + processed + '</strong> / ' + total + ' ' + cvpmData.variationsText;
            html += '</span>';
            html += '<button type="button" class="button cvpm-job-cancel-btn" data-job-id="' + this.escapeHtml(jobId) + '">' + cvpmData.cancelButton + '</button>';
            html += '</div>';

            // Progress bar
            html += '<div class="cvpm-job-progress-container">';
            html += '<div class="cvpm-job-progress-bar" style="width: ' + percentage + '%;">';
            html += '<span class="cvpm-job-progress-percentage">' + percentage + '%</span>';
            html += '</div>';
            html += '</div>';

            // Logs toggle
            html += '<button type="button" class="cvpm-job-logs-toggle" data-job-id="' + this.escapeHtml(jobId) + '">';
            html += 'Show logs';
            html += '</button>';

            // Logs container
            html += '<div class="cvpm-job-logs" id="cvpm-job-logs-' + this.escapeHtml(jobId) + '">';
            
            if (data.logs && data.logs.length > 0) {
                var self = this;
                data.logs.forEach(function(log) {
                    var time = self.formatTime(log.time);
                    html += '<div class="cvpm-job-log-entry">';
                    html += '<span class="cvpm-job-log-time">[' + time + ']</span> ' + self.escapeHtml(log.message);
                    html += '</div>';
                });
            }
            
            html += '</div>';
            html += '</div>';

            return html;
        },

        /**
         * Handle active job cancel button click
         */
        handleActiveJobCancel: function(e) {
            e.preventDefault();

            if (!confirm(cvpmData.cancelConfirm)) {
                return;
            }

            var $btn = $(e.target);
            var jobId = $btn.data('job-id');

            $btn.prop('disabled', true).text(cvpmData.updatingMessage);

            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_cancel_job',
                    nonce: cvpmData.nonce,
                    job_id: jobId
                },
                success: function() {
                    // The next poll will update the UI
                },
                error: function() {
                    $btn.prop('disabled', false).text(cvpmData.cancelButton);
                }
            });
        },

        /**
         * Handle toggle job logs
         */
        handleToggleJobLogs: function(e) {
            e.preventDefault();
            var $btn = $(e.target);
            var jobId = $btn.data('job-id');
            var $logs = $('#cvpm-job-logs-' + jobId);

            $logs.toggleClass('expanded');
            $btn.text($logs.hasClass('expanded') ? 'Hide logs' : 'Show logs');

            // Scroll to bottom if expanded
            if ($logs.hasClass('expanded')) {
                $logs.scrollTop($logs[0].scrollHeight);
            }
        },

        /**
         * Update table rows for processing variations
         */
        updateProcessingRows: function() {
            var self = this;
            var $rows = $('.cvpm-table tbody tr');

            $rows.each(function() {
                var $row = $(this);
                var variationIds = JSON.parse($row.attr('data-variation-ids') || '[]');
                
                // Check if any variation in this row is being processed
                var isProcessing = variationIds.some(function(id) {
                    return self.processingVariationIds.indexOf(id) !== -1;
                });

                if (isProcessing) {
                    if (!$row.hasClass('cvpm-processing')) {
                        $row.addClass('cvpm-processing');
                        // Add processing label to actions column
                        var $actionsCell = $row.find('.column-actions');
                        if ($actionsCell.find('.cvpm-processing-label').length === 0) {
                            $actionsCell.find('.cvpm-update-btn').after(
                                '<span class="cvpm-processing-label"><span class="cvpm-spinner"></span>' + cvpmData.processingLabel + '</span>'
                            );
                        }
                    }
                } else {
                    if ($row.hasClass('cvpm-processing')) {
                        $row.removeClass('cvpm-processing');
                        $row.find('.cvpm-processing-label').remove();
                    }
                }
            });
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

            // Store current row reference
            this.currentRow = $row;

            // Start background job
            this.startBackgroundJob(variationIds, productIds, regularPrice, salePrice);
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
         * Start background job
         */
        startBackgroundJob: function(variationIds, productIds, regularPrice, salePrice) {
            var self = this;

            // Reset state
            this.lastLogCount = 0;

            // Show progress dialog immediately
            this.showProgressDialog();

            // AJAX request to start job
            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_start_price_update',
                    nonce: cvpmData.nonce,
                    variation_ids: variationIds,
                    product_ids: productIds,
                    regular_price: regularPrice,
                    sale_price: salePrice
                },
                success: function(response) {
                    if (response.success) {
                        self.currentJobId = response.data.job_id;
                        self.startPolling();
                        
                        // Start active jobs polling to update the card
                        self.startActiveJobsPolling();
                        
                        // Immediately update processing rows
                        self.processingVariationIds = self.processingVariationIds.concat(variationIds);
                        self.updateProcessingRows();
                    } else {
                        self.handleJobError(response.data.message);
                    }
                },
                error: function() {
                    self.handleJobError(cvpmData.errorMessage);
                }
            });
        },

        /**
         * Show progress dialog
         */
        showProgressDialog: function() {
            var $overlay = $('#cvpm-progress-overlay');
            var $logsContainer = $overlay.find('.cvpm-logs-container');

            // Reset dialog state
            $overlay.find('.cvpm-progress-bar').css('width', '0%');
            $overlay.find('.cvpm-progress-percentage').text('0%');
            $overlay.find('.cvpm-progress-status').text(cvpmData.updatingMessage);
            $logsContainer.empty();
            $overlay.find('.cvpm-cancel-btn').show();
            $overlay.find('.cvpm-close-btn').hide();
            $overlay.removeClass('cvpm-completed cvpm-cancelled cvpm-error');

            // Show dialog
            $overlay.fadeIn(200);
            $('body').addClass('cvpm-dialog-open');
        },

        /**
         * Start polling for job status
         */
        startPolling: function() {
            var self = this;

            // Poll immediately
            this.pollJobStatus();

            // Then poll every 1.5 seconds
            this.pollInterval = setInterval(function() {
                self.pollJobStatus();
            }, 1500);
        },

        /**
         * Stop polling
         */
        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        /**
         * Poll job status
         */
        pollJobStatus: function() {
            var self = this;

            if (!this.currentJobId) {
                return;
            }

            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_get_job_status',
                    nonce: cvpmData.nonce,
                    job_id: this.currentJobId
                },
                success: function(response) {
                    if (response.success) {
                        self.updateProgressUI(response.data);
                    } else {
                        self.handleJobError(response.data.message);
                    }
                },
                error: function() {
                    // Don't show error for polling failures, just retry
                }
            });
        },

        /**
         * Update progress UI
         */
        updateProgressUI: function(data) {
            var $overlay = $('#cvpm-progress-overlay');
            var $progressBar = $overlay.find('.cvpm-progress-bar');
            var $percentage = $overlay.find('.cvpm-progress-percentage');
            var $status = $overlay.find('.cvpm-progress-status');
            var $logsContainer = $overlay.find('.cvpm-logs-container');

            // Update progress bar
            $progressBar.css('width', data.percentage + '%');
            $percentage.text(data.percentage + '%');

            // Update status text
            var statusText = cvpmData.processingText
                .replace('%1$d', data.processed)
                .replace('%2$d', data.total);
            $status.text(statusText);

            // Update logs (only add new ones)
            if (data.logs && data.logs.length > this.lastLogCount) {
                var newLogs = data.logs.slice(this.lastLogCount);
                var self = this;

                newLogs.forEach(function(log) {
                    var time = self.formatTime(log.time);
                    var $logEntry = $('<div class="cvpm-log-entry">')
                        .html('<span class="cvpm-log-time">[' + time + ']</span> ' + self.escapeHtml(log.message));
                    $logsContainer.append($logEntry);
                });

                this.lastLogCount = data.logs.length;

                // Auto-scroll to bottom
                $logsContainer.scrollTop($logsContainer[0].scrollHeight);
            }

            // Handle completion states
            if (data.status === 'completed') {
                this.handleJobComplete();
            } else if (data.status === 'cancelled') {
                this.handleJobCancelled();
            }
        },

        /**
         * Format timestamp to time string
         */
        formatTime: function(timestamp) {
            var date = new Date(timestamp * 1000);
            return date.toLocaleTimeString('en-US', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Handle job completion
         */
        handleJobComplete: function() {
            var $overlay = $('#cvpm-progress-overlay');

            this.stopPolling();
            
            $overlay.addClass('cvpm-completed');
            $overlay.find('.cvpm-progress-status').text(cvpmData.completedText);
            $overlay.find('.cvpm-cancel-btn').hide();
            $overlay.find('.cvpm-close-btn').show();

            // Update the row
            if (this.currentRow) {
                this.updateRowAfterSuccess();
            }

            this.showNotice('success', cvpmData.completedText);

            // Refresh active jobs to update the card and rows
            this.fetchActiveJobs();
        },

        /**
         * Handle job cancelled
         */
        handleJobCancelled: function() {
            var $overlay = $('#cvpm-progress-overlay');

            this.stopPolling();
            
            $overlay.addClass('cvpm-cancelled');
            $overlay.find('.cvpm-progress-status').text(cvpmData.cancelledText);
            $overlay.find('.cvpm-cancel-btn').hide();
            $overlay.find('.cvpm-close-btn').show();

            // Refresh active jobs to update the card and rows
            this.fetchActiveJobs();
        },

        /**
         * Handle job error
         */
        handleJobError: function(message) {
            var $overlay = $('#cvpm-progress-overlay');

            this.stopPolling();
            
            $overlay.addClass('cvpm-error');
            $overlay.find('.cvpm-progress-status').text(message);
            $overlay.find('.cvpm-cancel-btn').hide();
            $overlay.find('.cvpm-close-btn').show();

            this.showNotice('error', message);
        },

        /**
         * Handle cancel button click
         */
        handleCancel: function(e) {
            e.preventDefault();

            if (!confirm(cvpmData.cancelConfirm)) {
                return;
            }

            var self = this;
            var $btn = $(e.target);

            $btn.prop('disabled', true).text(cvpmData.updatingMessage);

            $.ajax({
                url: cvpmData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cvpm_cancel_job',
                    nonce: cvpmData.nonce,
                    job_id: this.currentJobId
                },
                success: function(response) {
                    // The status polling will handle the UI update
                },
                error: function() {
                    $btn.prop('disabled', false).text(cvpmData.cancelButton);
                }
            });
        },

        /**
         * Close progress dialog
         */
        closeProgressDialog: function() {
            var $overlay = $('#cvpm-progress-overlay');

            this.stopPolling();
            
            $overlay.fadeOut(200, function() {
                $('body').removeClass('cvpm-dialog-open');
            });

            this.currentJobId = null;
            this.currentRow = null;
            this.lastLogCount = 0;
        },

        /**
         * Update row after successful completion
         */
        updateRowAfterSuccess: function() {
            var $row = this.currentRow;
            if (!$row) return;

            var $regularInput = $row.find('.regular-price-input');
            var $saleInput = $row.find('.sale-price-input');

            // Update original values
            $regularInput.data('original', $regularInput.val()).removeClass('changed');
            $saleInput.data('original', $saleInput.val()).removeClass('changed');

            // Visual feedback
            $row.addClass('updated');
            setTimeout(function() {
                $row.removeClass('updated');
            }, 2000);
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
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        CVPM.init();
    });

})(jQuery);
