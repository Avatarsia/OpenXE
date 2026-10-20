// RepairIntegration module JavaScript
$(document).ready(function() {
    // --- WordPress-Verbindungs-Tab -------------------------------------

    // Merkt sich das Original-Label einmalig pro Button, damit schnelle
    // Wiederholungs-Klicks nicht das Feedback-Label festschreiben.
    function repairSetTempLabel(btn, text) {
        if (btn.getAttribute('data-repair-label') === null) {
            btn.setAttribute('data-repair-label', btn.textContent);
        }
        if (btn.repairLabelTimer) {
            window.clearTimeout(btn.repairLabelTimer);
        }
        btn.textContent = text;
        btn.repairLabelTimer = window.setTimeout(function() {
            btn.textContent = btn.getAttribute('data-repair-label');
            btn.repairLabelTimer = null;
        }, 1500);
    }

    // Copy: Clipboard-API nur im Secure Context (https/localhost) verfuegbar.
    // Test-VM laeuft ueber http://192.168.0.150 -> execCommand-Fallback.
    $(document).on('click', '.repairCopyBtn', function() {
        var btn = this;
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) { return; }
        var value = input.value;
        if (window.isSecureContext && navigator.clipboard) {
            navigator.clipboard.writeText(value).then(function() {
                repairSetTempLabel(btn, 'Kopiert!');
            }, function() {
                repairSetTempLabel(btn, 'Fehler');
            });
            return;
        }
        var wasPassword = input.type === 'password';
        if (wasPassword) { input.type = 'text'; }
        input.select();
        input.setSelectionRange(0, value.length);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        // Nur bei Erfolg wieder maskieren: ein type-Wechsel verwirft die
        // Selektion, sonst waere der Hinweis "Manuell kopieren" nutzlos.
        if (wasPassword && ok) { input.type = 'password'; }
        repairSetTempLabel(btn, ok ? 'Kopiert!' : 'Manuell kopieren');
    });

    $(document).on('click', '.repairToggleBtn', function() {
        var input = document.getElementById(this.getAttribute('data-target'));
        if (!input) { return; }
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        this.textContent = hidden ? 'Verbergen' : 'Anzeigen';
    });

    $(document).on('submit', '.repairGenForm', function(e) {
        var btn = this.querySelector('button[data-confirm="1"]');
        if (btn && !window.confirm(
            'Vorhandenen Schluessel wirklich ersetzen? Das WordPress-Plugin muss danach aktualisiert werden.'
        )) {
            e.preventDefault();
        }
    });
    // Native Reparatur-Leistungsdaten: nur Text anzeigen, kein Kunden-HTML.
    function repairUpdatePositionPreview() {
        var template = document.getElementById('repair_position_template');
        var data = document.getElementById('repair_position_values');
        if (!template || !data) return;
        var values;
        try { values = JSON.parse(data.textContent); } catch (e) { return; }
        ['public_diagnosis', 'service_work', 'final_check'].forEach(function(field) {
            var input = document.getElementById('repair_' + field);
            if (!input) return;
            var key = {public_diagnosis: 'eingangsdiagnose', service_work: 'reparaturarbeiten', final_check: 'endkontrolle'}[field];
            values[key] = input.value;
            if (field === 'service_work') values.leistungsbeschreibung = input.value;
        });
        var missing = [];
        var result = template.value.replace(/\{([^{}\r\n]*)\}/g, function(match, key) {
            if (!Object.prototype.hasOwnProperty.call(values, key) || String(values[key]).trim() === '') {
                if (missing.indexOf(key) === -1) missing.push(key);
                return '[fehlt: ' + key + ']';
            }
            return String(values[key]);
        });
        $('#repair_position_preview').text(result);
        $('#repair_position_warning').text(missing.length ? 'Vor Belegerstellung ergaenzen oder Vorlage anpassen: ' + missing.join(', ') : '');
    }
    $(document).on('input', '#repair_public_diagnosis, #repair_service_work, #repair_final_check', repairUpdatePositionPreview);
    repairUpdatePositionPreview();

    $(document).on('change', '#repair_template_type', function() {
        var key = this.value;
        $('.repair-template-editor').each(function() { this.hidden = this.getAttribute('data-repair-template') !== key; });
    });
    $(document).on('click', '.repair-variable', function() {
        var selector = document.getElementById('repair_template_type');
        if (!selector) return;
        var input = document.getElementById('repair_template_' + selector.value);
        if (!input) return;
        var start = input.selectionStart, end = input.selectionEnd;
        input.setRangeText('{' + this.getAttribute('data-variable') + '}', start, end, 'end');
        input.focus();
    });

});
