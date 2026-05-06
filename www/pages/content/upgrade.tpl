<style>
.status-banner {border-radius:6px;padding:14px;margin-bottom:12px;color:#fff;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;height:100%;}
.banner-success {background:#1b6e30;}
.banner-error {background:#b52b27;}
.banner-warning {background:#d89216;}
.banner-info {background:#0b3c68;}
.banner-text {flex:1;min-width:0;}
.banner-headline {font-size:18px;font-weight:700;}
.banner-sub {font-size:14px;margin-top:4px;}
.banner-guidance {margin-top:8px;font-weight:700;}
.banner-guidance small {display:block;font-weight:400;}
.banner-hint {margin-top:8px;font-size:13px;opacity:0.9;}
.banner-actions {display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;}
.banner-btn {background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:5px;padding:8px 14px;font-weight:700;cursor:pointer;min-height:40px;}
.banner-btn:hover {background:rgba(255,255,255,0.18);}
.icon-btn {width:42px;height:42px;border-radius:21px;display:flex;align-items:center;justify-content:center;font-size:20px;padding:0;}
.hidden-force {display:block;width:100%;margin-top:6px;}
.hidden-force label {display:flex;align-items:center;gap:6px;margin:0;font-size:12px;color:rgba(255,255,255,0.9);}
.top-row {display:grid;grid-template-columns: minmax(420px, 2fr) minmax(320px, 1fr);gap:16px;align-items:stretch;margin-bottom:32px;position:relative;z-index:1;box-sizing:border-box;}
.status-col {display:flex;}
.steps-col {display:flex;}
.steps-stack {display:flex;flex-direction:column;gap:12px;width:100%;}
.info-row {display:grid;grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));gap:16px;align-items:stretch;margin-bottom:24px;margin-top:16px;}
.compare-row {display:grid;grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));gap:16px;align-items:stretch;margin-bottom:16px;margin-top:8px;}
.log-section {margin-top:50px;clear:both;position:relative;z-index:0;}
.stepper {display:flex;flex-direction:column;gap:12px;margin-bottom:12px;}
.step-card {border:1px solid #dbe3ef;border-radius:6px;background:#f6f8fb;padding:12px;}
.step-head {display:flex;align-items:center;justify-content:space-between;gap:8px;}
.pill {display:inline-block;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:700;}
.pill-success {background:#e6f4ea;color:#1b6e30;}
.pill-error {background:#fdecea;color:#b52b27;}
.pill-warning {background:#fff4e5;color:#8a4b0f;}
.pill-info {background:#e8f1ff;color:#0b3c68;}
.step-actions {display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.step-btn {background:#0b3c68;color:#fff;border:none;border-radius:4px;padding:8px 12px;font-weight:700;cursor:pointer;}
.step-btn:hover {opacity:0.9;}
.force-wrap {margin-top:6px;font-size:12px;color:#0b3c68;}
.force-highlight {border:2px solid #b52b27;background:#fff4e5;border-radius:6px;padding:8px;}
.force-highlight label {font-weight:700;}
.card {border:1px solid #dbe3ef;border-radius:6px;background:#fff;padding:16px;margin-bottom:12px;display:flex;flex-direction:column;}
.card-title {margin:0 0 12px 0;padding:0 0 10px 0;font-size:14px;font-weight:700;color:#0b3c68;border-bottom:1px solid #eef1f6;letter-spacing:0.02em;text-transform:uppercase;}
.card-body {flex:1 1 auto;}
.card-footer {margin-top:12px;padding-top:12px;border-top:1px solid #eef1f6;}
.kv-grid {display:grid;grid-template-columns:max-content 1fr;column-gap:14px;row-gap:8px;font-size:13px;align-items:baseline;}
.kv-grid dt {color:#5b6675;font-weight:600;white-space:nowrap;}
.kv-grid dd {margin:0;color:#1a2433;word-break:break-word;}
.kv-grid dd .hint {color:#7a8696;font-family:Consolas,monospace;font-size:12px;}
.form-stack {display:flex;flex-direction:column;gap:10px;}
.form-row {display:flex;flex-direction:column;gap:4px;}
.form-row label {font-size:12px;font-weight:600;color:#5b6675;letter-spacing:0.02em;text-transform:uppercase;}
.tag-select-wrap {margin:6px 0 12px 0;}
.tag-select-wrap select {width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;background:#fff;}
.log-box {background:#0f1720;color:#e5e7eb;border-radius:6px;padding:12px;max-height:420px;overflow:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.5;}
.log-header {display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.log-header .log-title {font-weight:700;color:#0b3c68;flex-shrink:0;}
.log-header .log-search {flex:1 1 auto;max-width:280px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;}
.log-header .log-download {margin-left:auto;background:#0b3c68;color:#fff;border:none;border-radius:4px;padding:7px 12px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer;}
.log-header .log-download:hover {background:#0a325a;}
.hint {color:#555;font-size:13px;line-height:1.5;}
.input-inline {width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;}
.action-btn {width:100%;margin-top:6px;padding:9px 14px;font-weight:700;border-radius:4px;cursor:pointer;font-size:13px;}
button.action-btn.btn-primary {background:#0b3c68 !important;background-image:none !important;color:#fff !important;border:1px solid #0a325a !important;}
button.action-btn.btn-primary:hover {background:#0a325a !important;}
button.action-btn.btn-rollback {background:#d89216 !important;background-image:none !important;color:#fff !important;border:1px solid #8a4b0f !important;}
button.action-btn.btn-rollback:hover {background:#b87810 !important;}
.rollback-card {border:2px solid #d89216 !important;background:#fffaf2;}
.rollback-card legend {color:#8a4b0f;}
.upgrade-lock {background:#fff4e5;border:1px solid #d89216;border-radius:4px;padding:10px 12px;margin-bottom:10px;color:#8a4b0f;font-size:13px;}
</style>

<div id="tabs">
    <ul>
        <li><a href="#tabs-1"></a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form action="" method="post">
            [FORMHANDLEREVENT]
            <input type="hidden" name="details_anzeigen" value="1">
            <input type="hidden" name="db_details_anzeigen" value="1">

            <div class="top-row">
                <div class="status-col">
                    <div class="status-banner banner-[STATUS_LEVEL]" style="width:100%;">
                        <div class="banner-text">
                            <div class="banner-headline">[STATUS_HEADLINE]</div>
                            <div class="banner-sub">[STATUS_MESSAGE]</div>
                            <div class="banner-guidance">[GUIDANCE_TITLE]<small>[GUIDANCE_MESSAGE]</small></div>
                            <div class="banner-hint">Das Upgrade läuft in zwei Schritten: Dateien aktualisieren und Datenbank auffrischen. Für lange Läufe kannst du das Protokoll über das Aktualisieren-Symbol neu laden. Bei hartnäckigen Fehlern hilft der Konsolen-Run: <code>./upgrade.sh -do</code> im Unterordner <code>upgrade</code>.</div>
                        </div>
                        <div class="banner-actions">
                            <button name="submit" value="refresh" class="banner-btn icon-btn" title="Anzeige neu laden">&#x21bb;</button>
                        </div>
                    </div>
                </div>
                <div class="steps-col">
                    <div class="steps-stack">
                        <div class="step-card">
                            <div class="step-head">
                                <div>
                                    <div class="pill pill-[STATUS_LEVEL]">Dateien</div>
                                    <div><strong>Code & Repo</strong></div>
                                </div>
                                <div class="step-actions">
                                    <button name="submit" value="[UPGRADE_BUTTON_ACTION]" class="step-btn" style="width:100%;">[UPGRADE_BUTTON_LABEL]</button>
                                </div>
                            </div>
                            <div class="force-wrap [FORCE_HIGHLIGHT_CLASS]" [UPGRADE_FORCE_VISIBLE]><label><input type="checkbox" name="erzwingen" value="1" [ERZWINGEN]> Erzwingen (-f)</label></div>
                        </div>
                        <div class="step-card">
                            <div class="step-head">
                                <div>
                                    <div class="pill pill-[STATUS_LEVEL]">Datenbank</div>
                                    <div><strong>DB-Check & Upgrade</strong></div>
                                </div>
                                <div class="step-actions">
                                    <button name="submit" value="[UPGRADE_DB_BUTTON_ACTION]" class="step-btn" style="width:100%;">[UPGRADE_DB_BUTTON_LABEL]</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="compare-row">
                <div class="status-col" style="flex:1;min-width:320px;">
                    <div class="card" style="height:100%;">
                        <h3 class="card-title">{|Versionsabgleich|}</h3>
                        <div class="card-body">
                            <dl class="kv-grid">
                                <dt>OpenXE-Version</dt><dd>[APP_VERSION]</dd>
                                <dt>Code-Stand (Git)</dt><dd><span [LOCAL_BRANCH_VISIBLE]>[LOCAL_BRANCH]&nbsp;</span><span class="hint">[LOCAL_HASH_SHORT]</span> <span class="hint">[LOCAL_COMMIT]</span></dd>
                                <dt>Upgrade-Quelle</dt><dd>[REMOTE_HOST] (<strong>[REMOTE_BRANCH]</strong>) <span class="hint">[REMOTE_HASH_SHORT]</span></dd>
                                <dt>Status</dt><dd><span class="pill [UPDATE_STATUS_CLASS]">[UPDATE_STATUS]</span></dd>
                            </dl>
                        </div>
                        <div class="card-footer">
                            <button name="submit" value="reset_remote_origin" class="action-btn btn-primary">Quelle auf Original zurücksetzen</button>
                        </div>
                    </div>
                </div>
                <div class="status-col" style="flex:1;min-width:320px;">
                    <div class="card" style="height:100%;">
                        <h3 class="card-title">{|Upgrade-Quelle (Git)|}</h3>
                        <div class="card-body">
                            <div class="hint" style="margin-bottom:12px;">Passe Remote-URL und Branch an, wenn du auf einen anderen Stand updaten willst.</div>
                            <div class="form-stack">
                                <div class="form-row">
                                    <label for="remote_host">Remote-URL</label>
                                    <input id="remote_host" class="input-inline" type="text" name="remote_host" value="[REMOTE_HOST]" autocomplete="off">
                                </div>
                                <div class="form-row">
                                    <label for="remote_branch">Branch</label>
                                    <input id="remote_branch" class="input-inline" type="text" name="remote_branch" value="[REMOTE_BRANCH]" autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button name="submit" value="save_remote" class="action-btn btn-primary">Quelle speichern</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="compare-row" [ROLLBACK_VISIBLE]>
                <div class="status-col" style="flex:1;min-width:320px;">
                    <div class="card rollback-card" style="height:100%;">
                        <h3 class="card-title">{|Rollback & Wiederherstellung|}</h3>
                        <div class="card-body">
                            <div class="hint" style="margin-bottom:12px;color:#8a4b0f;">
                                ⚠️ <strong>Vorsicht:</strong> Rollback setzt nur den Code zurück. Datenbank-Änderungen werden NICHT rückgängig gemacht!
                            </div>
                            <div class="form-row">
                                <label>Verfügbarer Wiederherstellungspunkt</label>
                                <div class="tag-select-wrap">[ROLLBACK_TAGS_SELECT]</div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button name="submit" value="rollback_to_tag" class="action-btn btn-rollback" onclick="return confirm('Wirklich auf diesen Stand zurücksetzen? Code wird überschrieben!');">🔙 Rollback durchführen</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="log-section">
                <div class="card">
                    <div class="log-header">
                        <span class="log-title">{|Protokoll|}</span>
                        <input type="text" id="log-search" class="log-search" placeholder="Log durchsuchen…" />
                        <a href="?module=upgrade&action=list&ajax=download_log" class="log-download" download>📥 Log herunterladen</a>
                    </div>
                    <div id="upgrade-lock-status" class="upgrade-lock" style="display:none;">
                        <strong>⚠️ Upgrade läuft gerade:</strong> Gestartet von <span id="lock-user">-</span> um <span id="lock-time">-</span>
                    </div>
                    <div class="log-box" id="log-box">[OUTPUT_FROM_CLI]</div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var logBox = document.getElementById('log-box');
    var logSearch = document.getElementById('log-search');
    var lockStatus = document.getElementById('upgrade-lock-status');
    var lockUser = document.getElementById('lock-user');
    var lockTime = document.getElementById('lock-time');
    var pollingInterval = null;
    var lastModified = 0;
    var allLogLines = [];

    // Log-Search Funktionalität
    if (logSearch) {
        logSearch.addEventListener('input', function() {
            var searchTerm = this.value.toLowerCase();
            if (searchTerm === '') {
                logBox.innerHTML = allLogLines.join('<br>');
            } else {
                var filtered = allLogLines.filter(function(line) {
                    return line.toLowerCase().indexOf(searchTerm) !== -1;
                });
                logBox.innerHTML = filtered.length > 0 ? filtered.join('<br>') : '<span style="color:#999;">Keine Treffer</span>';
            }
        });
    }

    // AJAX-Polling-Funktion
    function pollLogStatus() {
        fetch('?module=upgrade&action=list&ajax=get_log_status')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    // Update Log wenn geändert
                    if (data.last_modified > lastModified) {
                        lastModified = data.last_modified;
                        allLogLines = data.log_lines;

                        // Nur updaten wenn kein Search aktiv
                        if (!logSearch || logSearch.value === '') {
                            logBox.innerHTML = allLogLines.join('<br>');
                            // Auto-scroll zum Ende
                            logBox.scrollTop = logBox.scrollHeight;
                        }
                    }

                    // Lock-Status anzeigen
                    if (data.is_locked && data.lock_info) {
                        lockUser.textContent = data.lock_info.user;
                        lockTime.textContent = data.lock_info.timestamp;
                        lockStatus.style.display = 'block';
                    } else {
                        lockStatus.style.display = 'none';
                        // Upgrade fertig - Polling stoppen
                        if (pollingInterval) {
                            clearInterval(pollingInterval);
                            pollingInterval = null;
                        }
                    }
                }
            })
            .catch(function(err) {
                console.error('Log polling failed:', err);
            });
    }

    // Starte Polling wenn Seite geladen wird
    // Prüfe initial, dann alle 2 Sekunden
    pollLogStatus();
    pollingInterval = setInterval(pollLogStatus, 2000);

    // Cleanup beim Verlassen
    window.addEventListener('beforeunload', function() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
    });
})();
</script>
