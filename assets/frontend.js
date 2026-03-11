(function($) {
    'use strict';

    $(document).ready(function() {
        initDependentSelects();
        initAjaxFormSubmit();
        initProjectModals();
        initTableEnhancements();
    });

    function initDependentSelects() {
        var $client = $('select[name="client_id"]');
        var $project = $('select[name="project_id"]');
        var $service = $('select[name="service_id"]');

        if (!$client.length || !$project.length || !$service.length) return;

        $client.on('change', function() {
            var clientId = $(this).val();
            if (!clientId) {
                $project.empty().append('<option value="">Wybierz projekt</option>').prop('disabled', true);
                $service.empty().append('<option value="">Wybierz usługę</option>').prop('disabled', true);
                return;
            }

            $.get(crm_omd_ajax.ajaxurl, {
                action: 'crm_omd_get_projects',
                client_id: clientId
            }, function(response) {
                if (response.success) {
                    $project.empty().append('<option value="">Wybierz projekt</option>');
                    $.each(response.data, function(i, project) {
                        $project.append($('<option>', {
                            value: project.id,
                            text: project.name
                        }));
                    });
                    $project.prop('disabled', false);
                } else {
                    console.error(response.data);
                }
            }).fail(function() {
                console.error('Błąd pobierania projektów');
            });

            $.get(crm_omd_ajax.ajaxurl, {
                action: 'crm_omd_get_services',
                client_id: clientId
            }, function(response) {
                if (response.success) {
                    $service.empty().append('<option value="">Wybierz usługę</option>');
                    $.each(response.data, function(i, service) {
                        var option = $('<option>', {
                            value: service.id,
                            text: service.name + ' (' + (service.billing_type === 'fixed' ? 'ryczałt' : 'godzinowa') + ')'
                        });
                        option.attr('data-billing-type', service.billing_type);
                        $service.append(option);
                    });
                    $service.prop('disabled', false);
                } else {
                    console.error(response.data);
                }
            }).fail(function() {
                console.error('Błąd pobierania usług');
            });
        });

        if ($client.val()) {
            $client.trigger('change');
        }

        $service.on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var billingType = selectedOption.data('billing-type');
            if (billingType === 'fixed') {
                $('.crm-omd-field-hours').hide().find('input').prop('required', false);
                $('.crm-omd-field-amount').show().find('input').prop('required', true);
            } else {
                $('.crm-omd-field-hours').show().find('input').prop('required', true);
                $('.crm-omd-field-amount').hide().find('input').prop('required', false).val(0);
            }
        });
    }

    function initAjaxFormSubmit() {
        var $forms = $('form.crm-omd-tracker-form').filter(function() {
            return $(this).find('input[name="action"]').val() === 'crm_omd_submit_entry';
        });

        if (!$forms.length) return;

        $forms.on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);

            var formData = $form.serialize();
            formData += '&action=crm_omd_submit_entry_ajax';
            formData += '&nonce=' + crm_omd_ajax.nonce;

            $.post(crm_omd_ajax.ajaxurl, formData, function(response) {
                if (response.success) {
                    $form[0].reset();
                    $('select[name="project_id"]').empty().append('<option value="">Wybierz projekt</option>').prop('disabled', true);
                    $('select[name="service_id"]').empty().append('<option value="">Wybierz usługę</option>').prop('disabled', true);
                    $('.crm-omd-field-amount').hide().find('input').prop('required', false).val(0);
                    $('.crm-omd-field-hours').show().find('input').prop('required', true);
                    refreshMonthlyTable();
                    alert('Wpis dodany pomyślnie!');
                } else {
                    alert('Błąd: ' + response.data);
                }
            }).fail(function() {
                alert('Wystąpił błąd połączenia.');
            });
        });
    }


    function initProjectModals() {
        var $modals = $('.crm-omd-modal');
        if (!$modals.length) return;

        $(document).on('click', '.crm-omd-open-cost-modal, .crm-omd-open-status-modal', function() {
            openModalByTrigger($(this));
        });

        $(document).on('click', '[data-close-modal="1"]', function() {
            $(this).closest('.crm-omd-modal').removeClass('is-open').attr('aria-hidden', 'true');
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.crm-omd-modal.is-open').removeClass('is-open').attr('aria-hidden', 'true');
            }
        });
    }

    function openModalByTrigger($trigger) {
        var modalId = $trigger.data('modal-target');
        if (!modalId) return;

        var $modal = $('#' + modalId);
        if (!$modal.length) return;

        $modal.addClass('is-open').attr('aria-hidden', 'false');
    }



    function initTableEnhancements() {
        $('.crm-omd-sort-filter-table').each(function() {
            var $table = $(this);
            if ($table.data('enhanced') === 1) {
                return;
            }

            var tableId = $table.attr('id');
            if (!tableId) {
                return;
            }

            var $tools = $('.crm-omd-table-tools[data-table-target="' + tableId + '"]');
            var $headers = $table.find('thead th');
            var $tbody = $table.find('tbody');
            var $rows = $tbody.find('tr');
            var activeSort = { index: -1, dir: 'asc' };
            var filters = {};

            if (!$rows.length || !$headers.length) {
                return;
            }

            $rows.each(function() {
                var $row = $(this);
                var values = [];
                $row.children('td').each(function() {
                    values.push($(this).text().trim());
                });
                $row.data('rowValues', values);
            });

            $tools.empty();

            $headers.each(function(index) {
                var $header = $(this);
                var sortType = ($header.data('sort-type') || 'text').toString();
                var filterEnabled = $header.data('filter') === true || $header.attr('data-filter') === 'true';

                if (sortType !== 'none') {
                    var originalLabel = $.trim($header.text());
                    $header
                        .addClass('crm-omd-sortable-header')
                        .attr('role', 'button')
                        .attr('tabindex', '0')
                        .attr('data-original-label', originalLabel)
                        .attr('aria-sort', 'none');

                    $header.on('click keydown', function(e) {
                        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                            return;
                        }
                        if (e.type === 'keydown') {
                            e.preventDefault();
                        }

                        if (activeSort.index === index) {
                            activeSort.dir = activeSort.dir === 'asc' ? 'desc' : 'asc';
                        } else {
                            activeSort.index = index;
                            activeSort.dir = 'asc';
                        }

                        sortRows();
                        updateHeaderState();
                    });
                }

                if (filterEnabled) {
                    var options = [];
                    $rows.each(function() {
                        var rowValues = $(this).data('rowValues') || [];
                        var value = rowValues[index] || '';
                        if (value && $.inArray(value, options) === -1) {
                            options.push(value);
                        }
                    });

                    options.sort(function(a, b) {
                        return a.localeCompare(b, 'pl', { sensitivity: 'base' });
                    });

                    if (options.length) {
                        var headerLabel = $.trim($header.attr('data-original-label') || $header.text());
                        var $filterWrap = $('<label>', {
                            class: 'crm-omd-filter-label',
                            text: headerLabel + ':'
                        });
                        var $select = $('<select>', {
                            class: 'crm-omd-column-filter',
                            'data-column-index': index
                        });

                        $select.append($('<option>', { value: '', text: 'Wszystkie' }));
                        $.each(options, function(_, optionValue) {
                            $select.append($('<option>', { value: optionValue, text: optionValue }));
                        });

                        $select.on('change', function() {
                            filters[index] = $(this).val() || '';
                            applyFilters();
                        });

                        $filterWrap.append($select);
                        $tools.append($filterWrap);
                    }
                }
            });

            function applyFilters() {
                $rows.each(function() {
                    var $row = $(this);
                    var rowValues = $row.data('rowValues') || [];
                    var visible = true;

                    $.each(filters, function(columnIndex, expectedValue) {
                        if (expectedValue && rowValues[columnIndex] !== expectedValue) {
                            visible = false;
                            return false;
                        }
                    });

                    $row.toggle(visible);
                });
            }

            function sortRows() {
                if (activeSort.index < 0) {
                    return;
                }

                var sortType = ($headers.eq(activeSort.index).data('sort-type') || 'text').toString();
                var rowsArray = $rows.get();

                rowsArray.sort(function(a, b) {
                    var aValues = $(a).data('rowValues') || [];
                    var bValues = $(b).data('rowValues') || [];
                    var aVal = (aValues[activeSort.index] || '').toString();
                    var bVal = (bValues[activeSort.index] || '').toString();
                    var comparison = 0;

                    if (sortType === 'number') {
                        var normalize = function(value) {
                            return parseFloat(value.replace(/\s/g, '').replace(',', '.')) || 0;
                        };
                        comparison = normalize(aVal) - normalize(bVal);
                    } else if (sortType === 'date') {
                        comparison = aVal.localeCompare(bVal);
                    } else {
                        comparison = aVal.localeCompare(bVal, 'pl', { sensitivity: 'base' });
                    }

                    return activeSort.dir === 'asc' ? comparison : -comparison;
                });

                $tbody.append(rowsArray);
            }

            function updateHeaderState() {
                $headers.each(function(index) {
                    var $header = $(this);
                    var originalLabel = $header.attr('data-original-label');
                    if (!originalLabel) {
                        return;
                    }

                    if (index === activeSort.index) {
                        var arrow = activeSort.dir === 'asc' ? ' ↑' : ' ↓';
                        $header.text(originalLabel + arrow);
                        $header.attr('aria-sort', activeSort.dir === 'asc' ? 'ascending' : 'descending');
                    } else {
                        $header.text(originalLabel);
                        $header.attr('aria-sort', 'none');
                    }
                });
            }

            $table.data('enhanced', 1);
        });
    }

    function refreshMonthlyTable() {
        var $container = $('#crm-omd-monthly-table-container');
        if (!$container.length) return;

        var month = $('select[name="crm_omd_month"]').val() || '';

        $.get(crm_omd_ajax.ajaxurl, {
            action: 'crm_omd_get_monthly_table',
            month: month
        }, function(response) {
            if (response.success) {
                $container.html(response.data);
                initTableEnhancements();
            } else {
                console.error(response.data);
            }
        }).fail(function() {
            console.error('Błąd odświeżania tabeli');
        });
    }
})(jQuery);
