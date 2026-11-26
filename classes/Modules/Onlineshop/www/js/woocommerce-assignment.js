/* SPDX-License-Identifier: AGPL-3.0-only */
(function ($) {
    'use strict';

    $(function () {
        $('.wc-assignment').each(function () {
            var $page = $(this);
            var $error = $page.find('[data-wc-assignment-error]');
            var $status = $page.find('[data-wc-assignment-status]');
            var url = $page.attr('data-url');
            var category = $page.attr('data-category');
            var onlyUnassigned = $page.attr('data-unassigned') === '1';
            var busy = false;

            function showError(message) {
                $error.text(message).prop('hidden', false).attr('tabindex', '-1').trigger('focus');
            }

            function save($control, data) {
                var cmd = $control.attr('data-command');
                var checkbox = $control.is(':checkbox');
                if (busy) {
                    if (checkbox) {
                        $control.prop('checked', !$control.prop('checked'));
                    }
                    return;
                }
                var $active = $(document.activeElement);
                var $enabledControls = $page.find('[data-command], [data-command] :input').filter(':input:enabled');
                var navigating = false;
                busy = true;
                $enabledControls.prop('disabled', true);
                $error.prop('hidden', true);
                $status.prop('hidden', true);

                function failed(message) {
                    if (checkbox) {
                        $control.prop('checked', !$control.prop('checked'));
                    }
                    showError(message || 'Die \u00c4nderung konnte nicht gespeichert werden.');
                }

                $.ajax({
                    url: url + '&cmd=' + encodeURIComponent(cmd),
                    method: 'POST',
                    dataType: 'json',
                    data: $.extend({
                        csrf_token: $page.attr('data-token'),
                        shop: $page.attr('data-shop'),
                        category: category
                    }, data)
                }).done(function (response) {
                    if (!response || response.ok !== true) {
                        failed(response && response.error);
                    } else if (!checkbox || (cmd === 'toggle_shop' && onlyUnassigned)) {
                        var next = response.category === undefined ? category : response.category;
                        navigating = true;
                        window.location.href = url + '&category=' + encodeURIComponent(next)
                            + (onlyUnassigned ? '&unassigned=1' : '');
                    } else {
                        $status.text('Gespeichert.').prop('hidden', false);
                    }
                }).fail(function () {
                    failed();
                }).always(function () {
                    if (!navigating) {
                        busy = false;
                        $enabledControls.prop('disabled', false);
                        if ($error.prop('hidden') && $active.is(':input')) {
                            $active.trigger('focus');
                        }
                    }
                });
            }

            $page.on('submit', 'form[data-command]', function (event) {
                event.preventDefault();
                var $form = $(this);
                var data = {};
                $form.serializeArray().forEach(function (field) {
                    data[field.name] = field.value;
                });
                save($form, data);
            });

            $page.on('change', ':checkbox[data-command]', function () {
                var $checkbox = $(this);
                save($checkbox, {
                    article: $checkbox.closest('[data-article]').attr('data-article'),
                    enabled: $checkbox.prop('checked') ? '1' : '0'
                });
            });

            $page.on('click', 'button[data-command]', function () {
                var $button = $(this);
                save($button, {article: $button.closest('[data-article]').attr('data-article') || ''});
            });
        });
    });
}(jQuery));
