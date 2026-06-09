(function ($) {
    'use strict';

    const ROOT = window.CFG_GLPI?.root_doc ?? '';
    const ENDPOINT = ROOT + '/plugins/etlglpitfsworkintens/front/reservation_tickets.php';

    function statusToLabel(status) {
        switch (Number(status)) {
            case 1:
                return 'New';
            case 2:
                return 'Processing (assigned)';
            case 3:
                return 'Processing (planned)';
            case 4:
                return 'Pending';
            default:
                return 'Status ' + String(status);
        }
    }

    function buildSelectMarkup(selectId) {
        return (
            '<div class="mb-3 etlglpitfsworkintens-ticket-select">'
            + '<label class="form-label" for="' + selectId + '">Associated Ticket</label>'
            + '<select class="form-select" id="' + selectId + '" name="etlglpitfsworkintens_tickets_id">'
            + '<option value="">None</option>'
            + '</select>'
            + '</div>'
        );
    }

    function loadOptions($form, $select) {
        const reservationId = Number($form.find('input[name="id"]').val() || 0);
        $.ajax({
            url: ENDPOINT,
            method: 'GET',
            dataType: 'json',
            data: {
                reservation_id: reservationId,
            },
        }).done(function (response) {
            if (!response || response.success !== true || !Array.isArray(response.tickets)) {
                return;
            }

            const selectedId = Number(response.selected_ticket_id || 0);
            response.tickets.forEach(function (ticket) {
                const id = Number(ticket.id || 0);
                if (id <= 0) {
                    return;
                }

                const safeName = $('<span>').text(ticket.name || '').html();
                const label = '#' + id + ' - ' + safeName + ' [' + statusToLabel(ticket.status) + ']';
                const $opt = $('<option>').val(String(id)).html(label);
                if (selectedId > 0 && id === selectedId) {
                    $opt.prop('selected', true);
                }
                $select.append($opt);
            });
        });
    }

    function injectIntoForm(form) {
        const $form = $(form);
        if ($form.data('etlglpitfsworkintensInjected')) {
            return;
        }

        const $comment = $form.find('textarea[name="comment"]').first();
        if ($comment.length === 0) {
            return;
        }

        $form.data('etlglpitfsworkintensInjected', true);

        const selectId = 'etlglpitfsworkintens_tickets_id_' + Math.floor(Math.random() * 1000000);
        const $container = $(buildSelectMarkup(selectId));
        const $select = $container.find('select');

        $comment.closest('.mb-3, .form-field, .col-12, .row').before($container);
        loadOptions($form, $select);
    }

    function scanForms() {
        $('form').each(function () {
            const hasReservationFields = $(this).find('input[name="resa[begin]"], input[name="items[]"], input[name^="items["]').length > 0;
            if (hasReservationFields) {
                injectIntoForm(this);
            }
        });
    }

    $(function () {
        scanForms();

        const observer = new MutationObserver(function () {
            scanForms();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    });
})(jQuery);
