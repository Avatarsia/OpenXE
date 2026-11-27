/*
 * Upgrader UI scripts (Avatarsia fork)
 *
 * Extracted from www/pages/content/upgrade.tpl. The script is loaded
 * via <script src="./js/upgrade.js"> from the page template; there are
 * no Smarty variables baked in, the DOM-Lookup is enough.
 * Die Hilfe-Karte laeuft ueber <details>/<summary> ganz ohne JS.
 */
(function() {
    'use strict';

    var refreshBtn = document.getElementById('refresh-btn');
    var form = document.querySelector('#tabs-1 > form') || (refreshBtn && refreshBtn.form);
    var isSubmitting = false;
    var lastClickedSubmitBtn = null;

    var loadingLabels = {
        check_upgrade: 'System-Pr\u00fcfung l\u00e4uft\u2026',
        do_upgrade: 'Komplett-Upgrade l\u00e4uft \u2013 bitte warten\u2026',
        check_db: 'Datenbank-Pr\u00fcfung l\u00e4uft\u2026',
        do_db_upgrade: 'Datenbank-Upgrade l\u00e4uft \u2013 bitte warten\u2026',
        rollback_to_tag: 'Rollback l\u00e4uft\u2026'
    };

    function disableActionControls() {
        if (!form) {
            return;
        }
        var submitBtns = form.querySelectorAll('button[name="submit"]');
        for (var i = 0; i < submitBtns.length; i++) {
            submitBtns[i].disabled = true;
        }
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
    }

    function appendHiddenSubmit(value) {
        if (!form || !value) {
            return;
        }
        var existing = form.querySelector('input[type="hidden"][name="submit"]');
        if (existing) {
            existing.value = value;
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'submit';
        input.value = value;
        form.appendChild(input);
    }

    // Refresh-Button ist type="button" (kein Submit), damit Enter in
    // den Textfeldern nicht versehentlich ihn als ersten Submit-Button
    // des Formulars auslöst. Beim Klick wird submit=refresh als
    // hidden input gesetzt und das Formular regulär abgeschickt.
    if (refreshBtn && form) {
        refreshBtn.addEventListener('click', function() {
            if (isSubmitting) {
                return;
            }
            isSubmitting = true;
            appendHiddenSubmit('refresh');
            disableActionControls();
            form.submit();
        });
    }

    function resetActionControls() {
        isSubmitting = false;
        lastClickedSubmitBtn = null;
        if (!form) {
            return;
        }
        var submitBtns = form.querySelectorAll('button[name="submit"]');
        for (var i = 0; i < submitBtns.length; i++) {
            submitBtns[i].disabled = false;
            submitBtns[i].classList.remove('is-loading');
            var origLabel = submitBtns[i].getAttribute('data-original-label');
            if (origLabel !== null) {
                submitBtns[i].textContent = origLabel;
            }
        }
        if (refreshBtn) {
            refreshBtn.disabled = false;
        }
        var hiddenSubmit = form.querySelector('input[type="hidden"][name="submit"]');
        if (hiddenSubmit && hiddenSubmit.parentNode) {
            hiddenSubmit.parentNode.removeChild(hiddenSubmit);
        }
    }

    // Browser-Back (bfcache): Buttons & Loading-Zustand sauber zurücksetzen
    window.addEventListener('pageshow', function(event) {
        if (event.persisted || isSubmitting) {
            resetActionControls();
        }
    });

    // Submit-Guard, Bestätigungsdialoge (-f / Rollback) und Loading-State
    if (form) {
        var actionButtons = form.querySelectorAll('button[name="submit"]');
        for (var b = 0; b < actionButtons.length; b++) {
            actionButtons[b].setAttribute('data-original-label', actionButtons[b].textContent);
            actionButtons[b].addEventListener('click', function() {
                lastClickedSubmitBtn = this;
            });
        }

        form.addEventListener('submit', function(event) {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }

            var clickedBtn = (event.submitter && event.submitter.name === 'submit')
                ? event.submitter
                : lastClickedSubmitBtn;
            var action = clickedBtn ? clickedBtn.value : '';

            if (action === 'rollback_to_tag') {
                var okRollback = window.confirm(
                    'Wirklich auf diesen Stand zur\u00fccksetzen? Code wird \u00fcberschrieben! DB-\u00c4nderungen werden NICHT r\u00fcckg\u00e4ngig gemacht.'
                );
                if (!okRollback) {
                    event.preventDefault();
                    lastClickedSubmitBtn = null;
                    return;
                }
            }

            if (action === 'do_upgrade') {
                var forceCheckbox = form.querySelector('input[name="erzwingen"]');
                if (forceCheckbox && forceCheckbox.checked) {
                    var okForce = window.confirm(
                        '\u201eErzwingen (-f)\u201c ist aktiv: Alle lokalen Datei\u00e4nderungen im Repository werden unwiderruflich \u00fcberschrieben. Fortfahren?'
                    );
                    if (!okForce) {
                        event.preventDefault();
                        lastClickedSubmitBtn = null;
                        return;
                    }
                }
            }

            isSubmitting = true;

            // Hidden input VOR dem Deaktivieren der Buttons anlegen, da
            // disabled Submit-Buttons nicht im POST-Payload mitgesendet werden.
            if (action) {
                appendHiddenSubmit(action);
            }

            disableActionControls();

            if (clickedBtn) {
                clickedBtn.classList.add('is-loading');
                if (Object.prototype.hasOwnProperty.call(loadingLabels, action)) {
                    clickedBtn.textContent = loadingLabels[action];
                }
            }
        });
    }

    // Enter in den Textfeldern soll KEIN implizites Form-Submit
    // auslösen — der Browser würde sonst den ersten Submit-Button
    // des Formulars wählen und die Eingaben verwerfen.
    var textFieldIds = ['log-search'];
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

    // ---------- Erweiterter Log-Viewer (strictly XSS-safe: kein innerHTML) ----------
    var logBox = document.getElementById('log-box');
    var logSearch = document.getElementById('log-search');
    if (!logBox) {
        return;
    }

    var rawLogText = (logBox.textContent || '').replace(/\r\n/g, '\n');
    var allLogLines = rawLogText.split('\n');
    if (allLogLines.length > 1 && allLogLines[allLogLines.length - 1] === '') {
        allLogLines.pop();
    }

    var reSection = /^-{5,}/;
    var reError = /\bnot ok:|Aborted!|Failed SQL:|Failed to |Cleaning Filescache failed|Error while|Fatal error|exit code: [1-9]/i;
    var reDbErrorsCount = /^Database upgrade errors: [1-9]\d*$/;
    var reDiff = /=> \[|differences remaining after upgrade|differences \(in JSON not in DB\)|There are modified files:/i;
    var reSuccess = /^-{5,}\s*Done!\s*-{5,}$|^Already up to date\.$|^No upgrades pending\.$|^0 differences remaining after upgrade\.$|completed\s*-+$/i;

    var reRemainingDiffsPositive = /[1-9]\d*\s+differences remaining/i;
    var reModifiedFiles = /There are modified files:/i;
    var reDiffFilter = /=> \[|differences|Remaining differences|Comparing database|Table count/i;

    function classifyLine(line) {
        var isErr = reError.test(line) || reDbErrorsCount.test(line);
        var isSuccess = !isErr && reSuccess.test(line);
        var isDiff = !isErr && !isSuccess && reDiff.test(line);
        var isSection = !isErr && !isSuccess && !isDiff && reSection.test(line);

        var cls = '';
        if (isErr) {
            cls = 'log-line-error';
        } else if (isSuccess) {
            cls = 'log-line-success';
        } else if (isDiff) {
            cls = 'log-line-warn';
        } else if (isSection) {
            cls = 'log-line-section';
        }

        return {
            text: line,
            lower: line.toLowerCase(),
            cls: cls,
            matchErrors: isErr || reRemainingDiffsPositive.test(line) || reModifiedFiles.test(line),
            matchDiffs: reDiffFilter.test(line)
        };
    }

    var parsedLines = new Array(allLogLines.length);
    var errorCount = 0;
    var diffCount = 0;
    for (var idx = 0; idx < allLogLines.length; idx++) {
        var entry = classifyLine(allLogLines[idx]);
        parsedLines[idx] = entry;
        if (entry.matchErrors) {
            errorCount++;
        }
        if (entry.matchDiffs) {
            diffCount++;
        }
    }

    var activeFilter = 'all';
    var filterButtons = document.querySelectorAll('.log-filter-btn[data-log-filter]');

    for (var f = 0; f < filterButtons.length; f++) {
        var btn = filterButtons[f];
        var filterKey = btn.getAttribute('data-log-filter');
        var baseLabel = btn.textContent.trim();
        btn.setAttribute('data-base-label', baseLabel);

        if (filterKey === 'errors' && errorCount > 0) {
            btn.textContent = baseLabel + ' (' + errorCount + ')';
        } else if (filterKey === 'diffs' && diffCount > 0) {
            btn.textContent = baseLabel + ' (' + diffCount + ')';
        }

        btn.addEventListener('click', function() {
            var nextFilter = this.getAttribute('data-log-filter') || 'all';
            activeFilter = nextFilter;
            for (var k = 0; k < filterButtons.length; k++) {
                filterButtons[k].classList.toggle('is-active', filterButtons[k] === this);
            }
            renderLog();
        });
    }

    function renderLog() {
        var searchTerm = logSearch ? logSearch.value.toLowerCase() : '';
        var fragment = document.createDocumentFragment();
        var matched = 0;

        for (var j = 0; j < parsedLines.length; j++) {
            var item = parsedLines[j];
            if (activeFilter === 'errors' && !item.matchErrors) {
                continue;
            }
            if (activeFilter === 'diffs' && !item.matchDiffs) {
                continue;
            }
            if (searchTerm !== '' && item.lower.indexOf(searchTerm) === -1) {
                continue;
            }

            var span = document.createElement('span');
            span.className = item.cls ? ('log-line ' + item.cls) : 'log-line';
            span.textContent = item.text + '\n';
            fragment.appendChild(span);
            matched++;
        }

        while (logBox.firstChild) {
            logBox.removeChild(logBox.firstChild);
        }

        if (matched === 0) {
            logBox.textContent = 'Keine Treffer';
        } else {
            logBox.appendChild(fragment);
        }
    }

    if (logSearch) {
        logSearch.addEventListener('input', renderLog);
    }

    // Initiales Syntax-Highlighting rendern
    renderLog();

    // Log kopieren
    var copyBtn = document.getElementById('log-copy-btn');
    if (copyBtn) {
        var copyResetTimer = null;
        var copyOriginalLabel = copyBtn.textContent.trim();

        function showCopiedFeedback() {
            copyBtn.textContent = 'Kopiert!';
            if (copyResetTimer) {
                window.clearTimeout(copyResetTimer);
            }
            copyResetTimer = window.setTimeout(function() {
                copyBtn.textContent = copyOriginalLabel;
                copyResetTimer = null;
            }, 1800);
        }

        function fallbackCopyText(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                showCopiedFeedback();
            } catch (e) {
                // Ignore clipboard errors in restricted contexts
            }
            document.body.removeChild(ta);
        }

        copyBtn.addEventListener('click', function() {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(rawLogText).then(showCopiedFeedback, function() {
                    fallbackCopyText(rawLogText);
                });
            } else {
                fallbackCopyText(rawLogText);
            }
        });
    }

    // Log-Box erweitern / verkleinern
    var expandBtn = document.getElementById('log-expand-btn');
    if (expandBtn) {
        var expandOriginalLabel = expandBtn.textContent.trim() || 'Erweitern';
        expandBtn.addEventListener('click', function() {
            var expanded = logBox.classList.toggle('log-box-expanded');
            expandBtn.textContent = expanded ? 'Verkleinern' : expandOriginalLabel;
        });
    }
})();
