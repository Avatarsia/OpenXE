// RepairIntegration module JavaScript
$(document).ready(function() {
    // Status dropdown override for repair tickets
    var repairDropdown = $('#repair-status-override');
    if (repairDropdown.length) {
        $('#status').replaceWith(repairDropdown.children());
    }

    // --- WordPress-Verbindungs-Tab -------------------------------------

    function repairSetTempLabel(btn, text) {
        var original = btn.textContent;
        btn.textContent = text;
        window.setTimeout(function() { btn.textContent = original; }, 1500);
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
        if (wasPassword) { input.type = 'password'; }
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
});
