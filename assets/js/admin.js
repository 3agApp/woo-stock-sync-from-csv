/**
 * Woo Stock Sync Admin JavaScript
 */

(function ($) {
    'use strict';

    // Main object
    const WSSC = {

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
            if (text === null || text === undefined) {
                return '';
            }
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initChart();
            this.initLocalTimes();
        },

        /**
         * Convert timestamps to local timezone and relative time
         */
        initLocalTimes: function () {
            $('.wssc-local-time').each(function () {
                const $el = $(this);
                const timestamp = parseInt($el.data('timestamp'), 10);

                if (!timestamp || isNaN(timestamp)) {
                    return;
                }

                // Convert Unix timestamp to milliseconds
                const date = new Date(timestamp * 1000);
                const now = new Date();

                // Calculate relative time
                const diffMs = now - date;

                // Handle negative differences (future dates or clock skew)
                if (diffMs < 0) {
                    $el.text('just now');
                    $el.attr('title', date.toLocaleString());
                    return;
                }

                const diffSec = Math.floor(diffMs / 1000);
                const diffMin = Math.floor(diffSec / 60);
                const diffHour = Math.floor(diffMin / 60);
                const diffDay = Math.floor(diffHour / 24);

                let relativeTime;
                if (diffSec < 60) {
                    relativeTime = diffSec <= 5 ? 'just now' : diffSec + ' sec ago';
                } else if (diffMin < 60) {
                    relativeTime = diffMin + ' min ago';
                } else if (diffHour < 24) {
                    relativeTime = diffHour + ' hour' + (diffHour !== 1 ? 's' : '') + ' ago';
                } else if (diffDay < 30) {
                    relativeTime = diffDay + ' day' + (diffDay !== 1 ? 's' : '') + ' ago';
                } else {
                    const months = Math.floor(diffDay / 30);
                    relativeTime = months + ' month' + (months !== 1 ? 's' : '') + ' ago';
                }

                // Update text
                $el.text(relativeTime);

                // Set title to local datetime
                const localDateTime = date.toLocaleString();
                $el.attr('title', localDateTime);
            });
        },

        /**
         * Bind events
         */
        bindEvents: function () {
            // Dashboard
            $('#wssc-run-sync').on('click', this.runSync.bind(this));
            $('#wssc-toggle-sync').on('change', this.toggleSync.bind(this));

            // Logs
            $('.wssc-view-log').on('click', this.viewLog.bind(this));
            $('#wssc-clear-logs').on('click', this.clearLogs.bind(this));

            // Settings
            $('#wssc-settings-form').on('submit', this.saveSettings.bind(this));
            $('#wssc-test-connection').on('click', this.testConnection.bind(this));
            $('#wssc-preview-csv').on('click', this.previewCSV.bind(this));

            // License
            $('#wssc-license-form').on('submit', this.activateLicense.bind(this));
            $('#wssc-deactivate-license').on('click', this.deactivateLicense.bind(this));
            $('#wssc-check-license').on('click', this.checkLicense.bind(this));
            $('#wssc-activate-domain').on('click', this.activateDomain.bind(this));

            // Updates
            $('#wssc-check-update').on('click', this.checkUpdate.bind(this));
            $('#wssc-install-update').on('click', this.installUpdate.bind(this));

            // Modal
            $('.wssc-modal-close').on('click', this.closeModal.bind(this));
            $('.wssc-modal').on('click', function (e) {
                if ($(e.target).hasClass('wssc-modal')) {
                    WSSC.closeModal();
                }
            });

            // ESC key to close modal
            $(document).on('keyup', function (e) {
                if (e.key === 'Escape') {
                    WSSC.closeModal();
                }
            });
        },

        /**
         * Run manual sync
         */
        runSync: function (e) {
            e.preventDefault();

            if (!confirm(wssc_admin.strings.confirm_sync)) {
                return;
            }

            const $btn = $('#wssc-run-sync');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.sync_running);

            this.ajax('wssc_run_sync', {})
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                        // Reload page to update stats
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        WSSC.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WSSC.toast(wssc_admin.strings.sync_error, 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Toggle sync enabled/disabled
         */
        toggleSync: function (e) {
            const enabled = $(e.target).is(':checked');

            this.ajax('wssc_toggle_sync', { enabled: enabled })
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                    } else {
                        WSSC.toast(response.data.message, 'error');
                        // Revert toggle
                        $(e.target).prop('checked', !enabled);
                    }
                })
                .fail(function () {
                    WSSC.toast('An error occurred', 'error');
                    $(e.target).prop('checked', !enabled);
                });
        },

        /**
         * View log details
         */
        viewLog: function (e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const logId = $btn.data('log-id');

            // Store original icon and show spinner
            const $icon = $btn.find('.dashicons');
            const originalClass = $icon.attr('class');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-update wssc-spin');
            $btn.css('pointer-events', 'none');

            this.ajax('wssc_get_log_details', { log_id: logId })
                .done(function (response) {
                    if (response.success) {
                        WSSC.showLogModal(response.data.log);
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to load log details', 'error');
                })
                .always(function () {
                    // Restore original icon
                    $icon.attr('class', originalClass);
                    $btn.css('pointer-events', '');
                });
        },

        /**
         * Show log modal
         */
        showLogModal: function (log) {
            const esc = this.escapeHtml.bind(this);
            let html = '<div class="wssc-log-detail">';

            // Status
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Status</span>';
            html += '<span class="wssc-log-detail-value">';
            html += '<span class="wssc-status-badge wssc-status-' + esc(log.status) + '">';
            if (log.status === 'success') {
                html += '<span class="dashicons dashicons-yes-alt"></span>';
            } else if (log.status === 'error') {
                html += '<span class="dashicons dashicons-dismiss"></span>';
            } else {
                html += '<span class="dashicons dashicons-warning"></span>';
            }
            html += '</span> ' + esc(log.status.charAt(0).toUpperCase() + log.status.slice(1));
            html += '</span></div>';

            // Type
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Type</span>';
            html += '<span class="wssc-log-detail-value">' + esc(log.type.charAt(0).toUpperCase() + log.type.slice(1)) + '</span>';
            html += '</div>';

            // Trigger
            if (log.trigger_type) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Trigger</span>';
                html += '<span class="wssc-log-detail-value">' + esc(log.trigger_type.charAt(0).toUpperCase() + log.trigger_type.slice(1)) + '</span>';
                html += '</div>';
            }

            // Message
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Message</span>';
            html += '<span class="wssc-log-detail-value">' + esc(log.message) + '</span>';
            html += '</div>';

            // Duration
            if (log.duration && parseFloat(log.duration) > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Duration</span>';
                html += '<span class="wssc-log-detail-value">' + parseFloat(log.duration).toFixed(2) + ' seconds</span>';
                html += '</div>';
            }

            // Date - convert to local timezone
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Date</span>';
            const logDate = new Date(log.created_at.replace(' ', 'T') + 'Z'); // Parse as UTC
            const localDateStr = logDate.toLocaleString();
            html += '<span class="wssc-log-detail-value">' + esc(localDateStr) + '</span>';
            html += '</div>';

            // Stats
            if (log.stats && typeof log.stats === 'object' && Object.keys(log.stats).length > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Statistics</span>';
                html += '<div class="wssc-log-detail-value">';
                html += '<div class="wssc-log-stats-grid">';

                if (log.stats.total_rows !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.total_rows + '</strong><span>Total Rows</span></div>';
                }
                if (log.stats.updated !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.updated + '</strong><span>Updated</span></div>';
                }
                if (log.stats.skipped !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.skipped + '</strong><span>Skipped</span></div>';
                }
                if (log.stats.not_found !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.not_found + '</strong><span>Not Found</span></div>';
                }
                if (log.stats.missing_set_private !== undefined && log.stats.missing_set_private > 0) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.missing_set_private + '</strong><span>Set Private</span></div>';
                }
                if (log.stats.missing_restored !== undefined && log.stats.missing_restored > 0) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.missing_restored + '</strong><span>Restored</span></div>';
                }
                if (log.stats.errors !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.errors + '</strong><span>Errors</span></div>';
                }

                html += '</div></div></div>';
            }

            // Errors list
            if (log.errors && Array.isArray(log.errors) && log.errors.length > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Error Details</span>';
                html += '<div class="wssc-log-detail-value">';
                html += '<div class="wssc-log-errors">';
                html += '<strong>Errors:</strong><ul>';
                log.errors.forEach(function (err) {
                    html += '<li>' + esc(err) + '</li>';
                });
                html += '</ul></div></div></div>';
            }

            html += '</div>';

            $('#wssc-log-modal-body').html(html);
            $('#wssc-log-modal').addClass('wssc-modal-open');
        },

        /**
         * Clear logs
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(wssc_admin.strings.confirm_clear_logs)) {
                return;
            }

            const $btn = $('#wssc-clear-logs');
            $btn.prop('disabled', true);

            this.ajax('wssc_clear_logs', {})
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Save settings
         */
        saveSettings: function (e) {
            e.preventDefault();

            const $form = $('#wssc-settings-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.saving);

            const data = {
                csv_url: $('#wssc-csv-url').val(),
                sku_column: $('#wssc-sku-column').val(),
                quantity_column: $('#wssc-qty-column').val(),
                schedule_interval: $('#wssc-schedule-interval').val(),
                enabled: $('#wssc-enabled').is(':checked'),
                disable_ssl: $('#wssc-disable-ssl').is(':checked'),
                missing_sku_action: $('#wssc-missing-sku-action').val()
            };

            this.ajax('wssc_save_settings', data)
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to save settings', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Test CSV connection
         */
        testConnection: function (e) {
            e.preventDefault();

            const url = $('#wssc-csv-url').val();
            if (!url) {
                WSSC.toast('Please enter a CSV URL', 'error');
                return;
            }

            const $btn = $('#wssc-test-connection');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.testing);

            this.ajax('wssc_test_connection', {
                url: url,
                disable_ssl: $('#wssc-disable-ssl').is(':checked')
            })
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Connection test failed', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Preview CSV
         */
        previewCSV: function (e) {
            e.preventDefault();

            const url = $('#wssc-csv-url').val();
            if (!url) {
                WSSC.toast('Please enter a CSV URL', 'error');
                return;
            }

            const $btn = $('#wssc-preview-csv');
            $btn.prop('disabled', true);

            this.ajax('wssc_preview_csv', {
                url: url,
                disable_ssl: $('#wssc-disable-ssl').is(':checked')
            })
                .done(function (response) {
                    if (response.success) {
                        WSSC.showPreviewModal(response.data);
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to preview CSV', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Show CSV preview modal
         */
        showPreviewModal: function (data) {
            const esc = this.escapeHtml.bind(this);
            let html = '<div class="wssc-csv-preview-content">';
            html += '<p><strong>Columns found:</strong> ' + esc(data.columns.join(', ')) + '</p>';
            html += '<table class="wssc-preview-table">';
            html += '<thead><tr>';
            data.columns.forEach(function (col) {
                html += '<th>' + esc(col) + '</th>';
            });
            html += '</tr></thead>';
            html += '<tbody>';
            data.sample.forEach(function (row) {
                html += '<tr>';
                row.forEach(function (cell) {
                    html += '<td>' + esc(cell) + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody></table>';
            html += '</div>';

            $('#wssc-preview-modal-body').html(html);
            $('#wssc-preview-modal').addClass('wssc-modal-open');
        },

        /**
         * Activate license
         */
        activateLicense: function (e) {
            e.preventDefault();

            const licenseKey = $('#wssc-license-key').val().trim();
            if (!licenseKey) {
                WSSC.toast('Please enter a license key', 'error');
                return;
            }

            const $form = $('#wssc-license-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.activating);

            this.ajax('wssc_activate_license', { license_key: licenseKey })
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        WSSC.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to activate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Activate domain for existing license
         * Used when license is valid but not activated on this domain
         */
        activateDomain: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-activate-domain');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.activating);

            this.ajax('wssc_activate_domain', {})
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        WSSC.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to activate domain', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Deactivate license
         */
        deactivateLicense: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to deactivate this license?')) {
                return;
            }

            const $btn = $('#wssc-deactivate-license');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wssc_admin.strings.deactivating);

            this.ajax('wssc_deactivate_license', {})
                .done(function (response) {
                    WSSC.toast(response.data.message, 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                })
                .fail(function () {
                    WSSC.toast('Failed to deactivate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Check license
         */
        checkLicense: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-check-license');
            $btn.prop('disabled', true);

            this.ajax('wssc_check_license', {})
                .done(function (response) {
                    if (response.success) {
                        if (response.data.valid && response.data.activated) {
                            WSSC.toast('License is valid and active', 'success');
                        } else if (response.data.valid && !response.data.activated) {
                            WSSC.toast('License is valid but not activated on this domain', 'warning');
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            const status = response.data.data && response.data.data.status ? response.data.data.status : 'invalid';
                            WSSC.toast('License is ' + status, 'error');
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to check license', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Check for plugin updates
         */
        checkUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-check-update');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> Checking...');

            this.ajax('wssc_check_update', {})
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, response.data.has_update ? 'info' : 'success');

                        if (response.data.has_update) {
                            // Reload to show update button
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        WSSC.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WSSC.toast('Failed to check for updates', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Install plugin update
         */
        installUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-install-update');
            const version = $btn.data('version');

            if (!confirm('Are you sure you want to update to version ' + version + '?')) {
                return;
            }

            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> Updating...');

            // Disable other buttons during update
            $('#wssc-check-update').prop('disabled', true);

            this.ajax('wssc_install_update', {})
                .done(function (response) {
                    if (response.success) {
                        WSSC.toast(response.data.message, 'success');

                        if (response.data.reload) {
                            setTimeout(function () {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        WSSC.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                        $('#wssc-check-update').prop('disabled', false);
                    }
                })
                .fail(function () {
                    WSSC.toast('Update failed. Please try again.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                    $('#wssc-check-update').prop('disabled', false);
                });
        },

        /**
         * Close modal
         */
        closeModal: function () {
            $('.wssc-modal').removeClass('wssc-modal-open');
        },

        /**
         * AJAX helper
         */
        ajax: function (action, data) {
            data = data || {};
            data.action = action;
            data.nonce = wssc_admin.nonce;

            return $.ajax({
                url: wssc_admin.ajax_url,
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        /**
         * Toast notification
         */
        toast: function (message, type) {
            type = type || 'success';

            // Remove existing toasts
            $('.wssc-toast').remove();

            const $toast = $('<div class="wssc-toast wssc-toast-' + type + '">' + message + '</div>');
            $('body').append($toast);

            // Auto remove after 4 seconds
            setTimeout(function () {
                $toast.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 4000);
        },

        /**
         * Initialize chart
         */
        initChart: function () {
            const canvas = document.getElementById('wssc-activity-chart');
            if (!canvas || typeof Chart === 'undefined') {
                // Load Chart.js if not already loaded
                if (canvas && typeof wsscChartData !== 'undefined') {
                    this.loadChartJS(function () {
                        WSSC.renderChart();
                    });
                }
                return;
            }

            this.renderChart();
        },

        /**
         * Load Chart.js
         */
        loadChartJS: function (callback) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            script.onload = callback;
            document.head.appendChild(script);
        },

        /**
         * Render chart
         */
        renderChart: function () {
            const canvas = document.getElementById('wssc-activity-chart');
            if (!canvas || typeof Chart === 'undefined' || typeof wsscChartData === 'undefined') {
                return;
            }

            const labels = wsscChartData.map(function (item) {
                const date = new Date(item.date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });

            const successData = wsscChartData.map(function (item) {
                return item.success;
            });

            const errorData = wsscChartData.map(function (item) {
                return item.error;
            });

            const updatedData = wsscChartData.map(function (item) {
                return item.updated;
            });

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Successful Syncs',
                            data: successData,
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                            borderColor: 'rgb(16, 185, 129)',
                            borderWidth: 1,
                            borderRadius: 4,
                        },
                        {
                            label: 'Failed Syncs',
                            data: errorData,
                            backgroundColor: 'rgba(239, 68, 68, 0.8)',
                            borderColor: 'rgb(239, 68, 68)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        WSSC.init();
    });

})(jQuery);
