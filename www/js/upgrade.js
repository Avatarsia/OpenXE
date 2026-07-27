/*
 * Upgrader UI scripts (Avatarsia fork)
 *
 * Extracted from www/pages/content/upgrade.tpl. The script is loaded
 * via <script src="js/upgrade.js"> from the page template; there are
 * no Smarty variables baked in, the DOM-Lookup is enough.
 * Die Hilfe-Karte laeuft ueber <details>/<summary> ganz ohne JS.
 */
(function() {
    // Refresh-Button ist type="button" (kein Submit), damit Enter in
    // den Textfeldern nicht versehentlich ihn als ersten Submit-Button
    // des Formulars auslöst. Beim Klick wird submit=refresh als
    // hidden input gesetzt und das Formular regulär abgeschickt.
    var refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn && refreshBtn.form) {
        refreshBtn.addEventListener('click', function() {
            var form = refreshBtn.form;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'submit';
            input.value = 'refresh';
            form.appendChild(input);
            form.submit();
        });
    }

    // Enter in den Textfeldern soll KEIN implizites Form-Submit
    // auslösen — der Browser würde sonst den ersten Submit-Button
    // des Formulars wählen und die Eingaben verwerfen.
    var textFieldIds = ['remote_host', 'remote_branch', 'log-search'];
    for (var i = 0; i < textFieldIds.length; i++) {
        var field = document.getElementById(textFieldIds[i]);
        if (field) {
            field.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' || event.keyCode === 13) {
                    event.preventDefault();
                }
            });
        }
    }

    var logBox = document.getElementById('log-box');
    var logSearch = document.getElementById('log-search');
    if (!logBox || !logSearch) {
        return;
    }
    // Initial-Snapshot des Logs übernehmen; die Anzeige wird nicht
    // gepollt — ein Reload (oder der Refresh-Button im Banner) liefert
    // den aktuellen Stand.
    var allLogLines = (logBox.textContent || '').split('\n');

    logSearch.addEventListener('input', function() {
        var searchTerm = this.value.toLowerCase();
        if (searchTerm === '') {
            logBox.textContent = allLogLines.join('\n');
            return;
        }
        var filtered = allLogLines.filter(function(line) {
            return line.toLowerCase().indexOf(searchTerm) !== -1;
        });
        if (filtered.length > 0) {
            logBox.textContent = filtered.join('\n');
        } else {
            logBox.textContent = 'Keine Treffer';
        }
    });
})();
