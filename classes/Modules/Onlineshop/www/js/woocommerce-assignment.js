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
                var select = $control.is('select');
                if (busy) {
                    if (checkbox) {
                        $control.prop('checked', !$control.prop('checked'));
                    } else if (select) {
                        $control.val('');
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
                    } else if (select) {
                        $control.val('');
                    }
                    if (cmd === 'stock_set' && $stockDialog[0].open) {
                        // The page error sits behind the modal dialog, so report inside it.
                        $stockDialog.find('[data-wc-stock-error]')
                            .text(message || 'Die \u00c4nderung konnte nicht gespeichert werden.').prop('hidden', false);
                        return;
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
                    } else if (cmd === 'stock_set') {
                        var $cell = $(response.cell);
                        $page.find('tr[data-article="' + response.article + '"] td.wc-col-stock').replaceWith($cell);
                        closeStockDialog();
                        $status.text('Gespeichert.').prop('hidden', false);
                        // The focused control was replaced; keep keyboard users in the same cell.
                        $cell.find('input[data-place], [data-stock-dialog]').first().trigger('focus');
                    } else if (select && category === '0') {
                        var $row = $control.closest('tr');
                        var articleNumber = $.trim($row.find('th').first().text());
                        var targetLabel = $.trim($control.find('option:selected').text());
                        $row.remove();
                        if ($page.find('.wc-assignment-table tbody tr').length === 0) {
                            navigating = true;
                            window.location.href = url + '&category=0'
                                + (onlyUnassigned ? '&unassigned=1' : '');
                        } else {
                            $status.text('Artikel ' + articleNumber + ' der Kategorie \u201e' + targetLabel + '\u201c zugeordnet.').prop('hidden', false);
                        }
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
                        if ($error.prop('hidden') && $active.is(':input') && $.contains(document, $active[0])) {
                            $active.trigger('focus');
                        }
                    }
                });
            }

            var $stockDialog = $('<dialog class="wc-stock-dialog" aria-label="Lagerbestand korrigieren"></dialog>').appendTo($page);

            function closeStockDialog() {
                if ($stockDialog[0].open) {
                    $stockDialog[0].close();
                }
            }

            $stockDialog.on('close', function () {
                $stockDialog.empty();
            });

            $page.on('click', '[data-stock-dialog]', function () {
                var $row = $(this).closest('[data-article]');
                var template = $(this).siblings('template.wc-stock-template')[0];
                if (!template || busy) {
                    return;
                }
                $stockDialog.empty().append(document.importNode(template.content, true));
                $stockDialog.find('form').attr('data-article', $row.attr('data-article'))
                    .prepend('<p class="error" role="alert" data-wc-stock-error hidden></p>');
                $stockDialog[0].showModal();
                $stockDialog.find('input[data-place]').first().trigger('focus');
            });

            $page.on('click', '[data-stock-close]', function () {
                closeStockDialog();
            });

            $page.on('submit', 'form[data-command="stock_set"]', function (event) {
                event.preventDefault();
                var $form = $(this);
                var counts = $form.find('input[data-place]').map(function () {
                    var $input = $(this);
                    return $input.attr('data-place') + ':' + $input.attr('data-expected') + ':' + $.trim($input.val());
                }).get().join(';');
                save($form, {
                    article: $form.closest('[data-article]').attr('data-article'),
                    counts: counts
                });
            });

            $page.on('submit', 'form[data-command]:not([data-command="stock_set"])', function (event) {
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

            $page.on('change', 'select[data-command]', function () {
                var $select = $(this);
                var targetCategory = $select.val();
                if (!targetCategory) {
                    return;
                }
                save($select, {
                    article: $select.closest('[data-article]').attr('data-article'),
                    category: targetCategory
                });
            });

            $page.on('click', 'button[data-command]', function () {
                var $button = $(this);
                save($button, {article: $button.closest('[data-article]').attr('data-article') || ''});
            });
        });
    });
}(jQuery));
