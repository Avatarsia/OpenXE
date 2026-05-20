<link rel="stylesheet" type="text/css" href="./css/upgrade.css">

<div id="tabs">
    <ul>
        <li><a href="#tabs-1"></a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form action="" method="post">
            [FORMHANDLEREVENT]
            <input type="hidden" name="db_details_anzeigen" value="1">

            <div class="top-row">
                <div class="status-col">
                    <div class="status-banner banner-[STATUS_LEVEL]">
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
                        <div class="hint hint-spaced">Passe Remote-URL und Branch an, wenn du auf einen anderen Stand updaten willst.</div>
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
                        <div class="hint hint-rollback">
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
                        <a href="?module=upgrade&action=list&ajax=download_log" class="log-download" download>&#128229; Log herunterladen</a>
                    </div>
                    <div class="log-box" id="log-box">[OUTPUT_FROM_CLI]</div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="./js/upgrade.js"></script>
