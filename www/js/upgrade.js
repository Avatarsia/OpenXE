/*
 * Upgrader UI scripts (Avatarsia fork)
 *
 * Extracted from www/pages/content/upgrade.tpl. The script is loaded
 * via <script src="js/upgrade.js"> from the page template; there are
 * no Smarty variables baked in, the DOM-Lookup is enough.
 */
function toggleUpgradeHelp() {
    var card = document.getElementById('upgrade-help');
    if (card) {
        card.classList.toggle('open');
        card.classList.toggle('collapsed');
    }
}

(function() {
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
