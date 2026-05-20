<style>
.status-banner {border-radius:6px;padding:18px 56px 18px 18px;margin-bottom:12px;color:#fff;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;height:100%;box-sizing:border-box;position:relative;}
.banner-success {background:#1b6e30;}
.banner-error {background:#b52b27;}
.banner-warning {background:#d89216;}
.banner-info {background:#0b3c68;}
.banner-text {flex:1;min-width:0;}
.banner-headline {font-size:19px;font-weight:700;}
.banner-sub {font-size:14px;margin-top:4px;}
.banner-guidance {margin-top:8px;font-weight:700;}
.banner-guidance small {display:block;font-weight:400;}
.banner-actions {position:absolute;top:10px;right:10px;display:flex;gap:8px;}
.banner-btn {background:rgba(255,255,255,0.12);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:5px;padding:8px 14px;font-weight:700;cursor:pointer;}
.banner-btn:hover {background:rgba(255,255,255,0.18);}
.icon-btn {width:34px;height:34px;border-radius:17px;display:flex;align-items:center;justify-content:center;font-size:16px;padding:0;}
.hidden-force {display:block;width:100%;margin-top:6px;}
.hidden-force label {display:flex;align-items:center;gap:6px;margin:0;font-size:12px;color:rgba(255,255,255,0.9);}
#tabs-1 > form {max-width:1600px;margin:0 auto;}
.top-row {display:grid;grid-template-columns: minmax(440px, 1.7fr) minmax(320px, 420px);gap:20px;align-items:stretch;margin-bottom:56px;position:relative;z-index:1;box-sizing:border-box;}
.status-col {display:flex;}
.steps-col {display:flex;}
.steps-stack {display:flex;flex-direction:column;gap:12px;width:100%;height:100%;}
.compare-row {display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:stretch;margin:0 0 56px 0;}
.compare-row .card {height:100%;margin-bottom:0;min-width:0;}
.secondary-row {display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px;align-items:stretch;margin:0 0 56px 0;}
.secondary-row .card {height:100%;margin-bottom:0;min-width:0;}
.log-section {margin-top:0;clear:both;position:relative;z-index:0;}
.stepper {display:flex;flex-direction:column;gap:12px;margin-bottom:12px;}
.step-card {border:1px solid #dbe3ef;border-radius:6px;background:#f6f8fb;padding:14px;display:flex;flex-direction:column;gap:8px;}
.step-card:last-child {flex:1 1 auto;}
.step-head {display:flex;flex-direction:column;align-items:flex-start;gap:6px;}
.step-head strong {font-size:14px;color:#0b3c68;}
.pill {display:inline-block;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:700;}
.pill-success {background:#e6f4ea;color:#1b6e30;}
.pill-error {background:#fdecea;color:#b52b27;}
.pill-warning {background:#fff4e5;color:#8a4b0f;}
.pill-info {background:#e8f1ff;color:#0b3c68;}
.step-actions {display:flex;justify-content:flex-end;align-items:flex-end;flex:1;}
.step-btn {width:auto;min-width:170px;background:#0b3c68;color:#fff;border:none;border-radius:4px;padding:9px 16px;font-weight:700;cursor:pointer;font-size:13px;}
.step-btn:hover {background:#0a325a;}
.force-wrap {margin-top:2px;font-size:12px;color:#0b3c68;}
.force-highlight {border:2px solid #b52b27;background:#fff4e5;border-radius:6px;padding:8px;}
.force-highlight label {font-weight:700;}
.card {border:1px solid #dbe3ef;border-radius:6px;background:#fff;padding:20px;margin-bottom:12px;display:flex;flex-direction:column;}
.card-title {margin:0 0 12px 0;padding:0 0 10px 0;font-size:14px;font-weight:700;color:#0b3c68;border-bottom:1px solid #eef1f6;letter-spacing:0.02em;text-transform:uppercase;}
.card-body {flex:1 1 auto;}
.card-footer {display:flex;justify-content:flex-end;margin-top:16px;padding-top:14px;border-top:1px solid #eef1f6;}
.kv-grid {display:grid;grid-template-columns:max-content minmax(0,1fr);column-gap:14px;row-gap:10px;font-size:13px;align-items:baseline;}
.kv-grid dt {color:#5b6675;font-weight:600;white-space:nowrap;}
.kv-grid dd {margin:0;color:#1a2433;overflow-wrap:anywhere;min-width:0;}
.kv-grid dd .hint {color:#7a8696;font-family:Consolas,monospace;font-size:12px;}
.form-stack {display:flex;flex-direction:column;gap:10px;}
.form-row {display:flex;flex-direction:column;gap:4px;}
.form-row label {font-size:12px;font-weight:600;color:#5b6675;letter-spacing:0.02em;text-transform:uppercase;}
.tag-select-wrap {margin:6px 0 12px 0;}
.tag-select-wrap select {width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;background:#fff;}
.help-card {background:#f6f8fb;border:1px dashed #c4cfde;}
.help-card .help-toggle {cursor:pointer;font-weight:700;color:#0b3c68;font-size:13px;display:flex;align-items:center;gap:8px;letter-spacing:0.02em;text-transform:uppercase;}
.help-card .help-toggle::before {content:"\203A";display:inline-block;transition:transform 0.15s ease;font-size:18px;line-height:1;}
.help-card.open .help-toggle::before {transform:rotate(90deg);}
.help-card .help-body {margin-top:12px;font-size:13px;color:#3a4655;line-height:1.55;max-width:760px;}
.help-card.collapsed .help-body {display:none;}
.help-card code {background:#e9eef6;padding:1px 6px;border-radius:3px;font-family:Consolas,monospace;font-size:12px;}
.log-box {background:#0f1720;color:#e5e7eb;border-radius:6px;padding:12px;max-height:420px;overflow:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.5;}
.log-header {display:flex;align-items:center;gap:10px;margin-bottom:10px;padding-right:16px;}
.log-header .log-title {font-weight:700;color:#0b3c68;flex-shrink:0;}
.log-header .log-search {flex:1 1 auto;max-width:280px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;}
.log-header .log-download {margin-left:auto;background:#0b3c68;color:#fff;border:none;border-radius:4px;padding:7px 12px;font-size:12px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer;}
.log-header .log-download:hover {background:#0a325a;}
.hint {color:#555;font-size:13px;line-height:1.5;}
.input-inline {width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;}
.action-btn {width:auto;min-width:240px;margin-top:0;padding:9px 18px;font-weight:700;border-radius:4px;cursor:pointer;font-size:13px;}
button.action-btn.btn-primary {background:#0b3c68 !important;background-image:none !important;color:#fff !important;border:1px solid #0a325a !important;}
button.action-btn.btn-primary:hover {background:#0a325a !important;}
button.action-btn.btn-rollback {background:#d89216 !important;background-image:none !important;color:#fff !important;border:1px solid #8a4b0f !important;}
button.action-btn.btn-rollback:hover {background:#b87810 !important;}
.rollback-card {border:2px solid #d89216 !important;background:#fffaf2;}
.rollback-card legend {color:#8a4b0f;}
@media (max-width: 980px) {
    .top-row {grid-template-columns:1fr;}
    .compare-row {grid-template-columns:1fr;}
    .secondary-row {grid-template-columns:1fr;}
}
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
                                <span class="pill pill-[STATUS_LEVEL]">Dateien</span>
                                <strong>Code &amp; Repo</strong>
                            </div>
                            <div class="step-actions">
                                <button name="submit" value="[UPGRADE_BUTTON_ACTION]" class="step-btn">[UPGRADE_BUTTON_LABEL]</button>
                            </div>
                            <div class="force-wrap [FORCE_HIGHLIGHT_CLASS]" [UPGRADE_FORCE_VISIBLE]><label><input type="checkbox" name="erzwingen" value="1" [ERZWINGEN]> Erzwingen (-f)</label></div>
                        </div>
                        <div class="step-card">
                            <div class="step-head">
                                <span class="pill pill-[STATUS_LEVEL]">Datenbank</span>
                                <strong>DB-Check &amp; Upgrade</strong>
                            </div>
                            <div class="step-actions">
                                <button name="submit" value="[UPGRADE_DB_BUTTON_ACTION]" class="step-btn">[UPGRADE_DB_BUTTON_LABEL]</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="compare-row">
                <div class="card">
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
                <div class="card">
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

            <div class="secondary-row" [ROLLBACK_VISIBLE]>
                <div class="card rollback-card">
                    <h3 class="card-title">{|Rollback &amp; Wiederherstellung|}</h3>
                    <div class="card-body">
                        <div class="hint" style="margin-bottom:12px;color:#8a4b0f;">
                            &#9888;&#65039; <strong>Vorsicht:</strong> Rollback setzt nur den Code zurück. Datenbank-Änderungen werden NICHT rückgängig gemacht!
                        </div>
                        <div class="form-row">
                            <label>Verfügbarer Wiederherstellungspunkt</label>
                            <div class="tag-select-wrap">[ROLLBACK_TAGS_SELECT]</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button name="submit" value="rollback_to_tag" class="action-btn btn-rollback" onclick="return confirm('Wirklich auf diesen Stand zurücksetzen? Code wird überschrieben!');">&#128281; Rollback durchführen</button>
                    </div>
                </div>
                <div class="card help-card open" id="upgrade-help">
                    <div class="help-toggle" onclick="toggleUpgradeHelp()">Hilfe &amp; Hinweise</div>
                    <div class="help-body">
                        Das Upgrade läuft in zwei Schritten: <strong>Dateien aktualisieren</strong> und <strong>Datenbank auffrischen</strong>.
                        Für lange Läufe das Protokoll über das Aktualisieren-Symbol neu laden.
                        Bei hartnäckigen Fehlern hilft der Konsolen-Run: <code>./upgrade.sh -do</code> im Unterordner <code>upgrade</code>.
                        <br><br>
                        <strong>Tipp:</strong> &bdquo;Quelle auf Original zurücksetzen&ldquo; springt zurück auf den Standard-Stand.
                        Rollback links setzt nur den Code zurück &ndash; DB-Änderungen müssen separat behandelt werden.
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
                    <div class="log-box" id="log-box">[OUTPUT_FROM_CLI]</div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
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
</script>
