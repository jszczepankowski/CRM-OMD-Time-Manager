(function($) {
    'use strict';

    $(document).ready(function() {
        initDependentSelects();
        initAjaxFormSubmit();
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
            } else {
                console.error(response.data);
            }
        }).fail(function() {
            console.error('Błąd odświeżania tabeli');
        });
    }
})(jQuery);
