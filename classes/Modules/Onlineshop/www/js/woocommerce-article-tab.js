/* SPDX-License-Identifier: AGPL-3.0-only */
(function ($) {
    'use strict';

    function initialiseMirror(field, form) {
        const original = form.elements.namedItem(field.dataset.mirror);
        if (!original || original.disabled || original.readOnly) {
            if (field.type === 'checkbox') {
                field.disabled = true;
            } else {
                field.readOnly = true;
            }
            return field;
        }

        if (original.tagName === 'SELECT') {
            const select = original.cloneNode(true);
            for (const attribute of select.getAttributeNames()) {
                select.removeAttribute(attribute);
            }
            for (const name of ['id', 'name', 'data-woo-key', 'data-mirror']) {
                select.setAttribute(name, field.getAttribute(name));
            }
            field.replaceWith(select);
            field = select;
        }

        if (original.type === 'checkbox') {
            $(field).on('input change', function () {
                if (original.checked !== field.checked) {
                    $(original).prop('checked', field.checked).trigger('change');
                }
            });
            $(original).on('input change', function () {
                field.checked = original.checked;
            });
            field.checked = original.checked;
            return field;
        }

        let editor;
        let updatingEditor = false;
        function fromOriginal() {
            if (!updatingEditor) {
                field.value = editor ? editor.getData() : original.value;
                if (editor) {
                    original.value = field.value;
                }
            }
        }

        function updateEditor() {
            if (editor.getData() === field.value) {
                return;
            }
            const value = field.value;
            updatingEditor = true;
            editor.setData(value, {
                callback: function () {
                    updatingEditor = false;
                    if (value !== field.value) {
                        updateEditor();
                    }
                }
            });
        }

        $(original).on('input change', fromOriginal);
        $(field).on('input change', function () {
            if (original.value !== field.value) {
                $(original).val(field.value);
                if (editor) {
                    if (!updatingEditor) {
                        updateEditor();
                    }
                } else {
                    $(original).trigger('change');
                }
            }
        });

        const ckeditor = window.CKEDITOR;
        if (ckeditor && original.id) {
            const attachEditor = function (instance) {
                editor = instance;
                editor.on('change', fromOriginal);
                editor.on('mode', function () {
                    if (editor.mode === 'source') {
                        const editable = editor.editable();
                        editable.attachListener(editable, 'input', fromOriginal);
                    }
                });
                form.addEventListener('formdata', function (event) {
                    // CKEditor can still be loading the latest data when the form is submitted.
                    if (updatingEditor) {
                        event.formData.set(original.name, field.value);
                    }
                });
                fromOriginal();
            };
            const instance = ckeditor.instances[original.id];
            if (instance && instance.status === 'ready') {
                attachEditor(instance);
            } else {
                const listener = ckeditor.on('instanceReady', function (event) {
                    if (ckeditor.instances[original.id] === event.editor) {
                        listener.removeListener();
                        attachEditor(event.editor);
                    }
                });
            }
        }
        fromOriginal();
        return field;
    }

    function showComparison(results, rows, fields, status) {
        const table = $('<table>');
        const head = $('<tr>').appendTo($('<thead>').appendTo(table));
        for (const title of ['Feld', 'OpenXE gespeichert', 'WooCommerce aktuell', '\u00dcbernehmen']) {
            $('<th>', {scope: 'col'}).text(title).appendTo(head);
        }
        const body = $('<tbody>').appendTo(table);
        const selections = [];
        for (const row of rows) {
            const tr = $('<tr>').appendTo(body);
            $('<th>', {scope: 'row'}).text(row.label).appendTo(tr);
            $('<td>').text(row.openxe).appendTo(tr);
            $('<td>').text(row.woocommerce).appendTo(tr);
            const action = $('<td>').appendTo(tr);
            if (!row.differs) {
                continue;
            }
            const field = fields.get(row.key);
            const representable = field && (field.tagName !== 'SELECT'
                || Array.from(field.options).some(function (option) { return option.value === row.woocommerce; }));
            const checkbox = $('<input>', {
                type: 'checkbox',
                'aria-label': row.label + ' \u00fcbernehmen',
                disabled: !row.pullable || !representable || field.readOnly || field.disabled
            }).appendTo(action)[0];
            if (checkbox.disabled) {
                action.append(document.createTextNode(' ' + (row.reason || 'Feld kann hier nicht bearbeitet werden.')));
            } else {
                selections.push({checkbox: checkbox, field: field, value: row.woocommerce});
            }
        }
        results.append($('<div>', {class: 'wc-admin-scroll'}).append(table));
        $('<button>', {type: 'button'})
            .text('Ausgew\u00e4hlte Werte \u00fcbernehmen')
            .prop('disabled', selections.length === 0)
            .on('click', function () {
                let changed = 0;
                for (const selection of selections) {
                    if (!selection.checkbox.checked) {
                        continue;
                    }
                    if (selection.field.type === 'checkbox') {
                        selection.field.checked = selection.value === '1';
                    } else {
                        selection.field.value = selection.value;
                    }
                    $(selection.field).trigger('change');
                    selection.checkbox.checked = false;
                    changed++;
                }
                status.text(changed ? '\u00dcbernommene Werte werden erst mit Speichern gesichert.'
                    : 'Bitte Werte zur \u00dcbernahme ausw\u00e4hlen.');
            }).appendTo(results);
        results.prop('hidden', false);
        status.text(selections.length ? 'Abweichende Werte k\u00f6nnen zur \u00dcbernahme ausgew\u00e4hlt werden.'
            : 'Keine abweichenden Werte zur \u00dcbernahme vorhanden.');
    }

    $(function () {
        $(document).on('woocommerce:article-overrides-saved', function (event, values) {
            const form = document.forms.namedItem('eprooform');
            if (!form || !values) {
                return;
            }
            for (const name of ['pseudopreis', 'pseudolager', 'restmenge', 'lieferzeitmanuell', 'autolagerlampe']) {
                const original = form.elements.namedItem(name);
                if (!original || !Object.prototype.hasOwnProperty.call(values, name)) {
                    continue;
                }
                if (original.type === 'checkbox') {
                    original.checked = String(values[name]) === '1';
                } else {
                    original.value = values[name];
                }
                $(original).trigger('change');
            }
        });

        $('.wc-article-tab').each(function () {
            const form = this.closest('form');
            if (!form) {
                return;
            }
            const fields = new Map();
            for (let field of this.querySelectorAll('[data-woo-key]')) {
                if (field.dataset.mirror) {
                    field = initialiseMirror(field, form);
                }
                fields.set(field.dataset.wooKey, field);
            }
            const tab = $(this);
            tab.find('[data-woo-shop]').on('change', function () {
                const url = new URL(tab.attr('data-compare-url'), document.baseURI);
                url.searchParams.delete('cmd');
                url.searchParams.delete('shop');
                url.searchParams.set('woo_shop', this.value);
                url.hash = 'tabs-6';
                window.location.assign(url.href);
            });
            const status = tab.find('[data-woo-status]');
            const results = tab.find('[data-woo-results]');
            tab.find('[data-woo-compare]').on('click', function () {
                const button = $(this).prop('disabled', true);
                results.empty().prop('hidden', true);
                status.prop('hidden', false).text('WooCommerce-Daten werden geladen.');
                $.getJSON(tab.attr('data-compare-url')).done(function (comparison) {
                    if (comparison.error) {
                        status.text(comparison.error);
                        return;
                    }
                    showComparison(results, comparison.rows, fields, status);
                }).fail(function () {
                    status.text('WooCommerce-Vergleich fehlgeschlagen.');
                }).always(function () {
                    button.prop('disabled', false);
                });
            });
        });
    });
}(window.jQuery));
