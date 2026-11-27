<link rel="stylesheet" type="text/css" href="./css/upgrade.css">

<div id="tabs">
    <ul>
        <li><a href="#tabs-1">{|Upgrader|}</a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form action="" method="post">
            <input type="hidden" name="csrf_token" value="[CSRF_TOKEN]">

            <!-- 1. Kompaktes Cockpit: Status + Version + Aktionen -->
            <section class="card cockpit-card cockpit-[STATUS_LEVEL]" role="status">
                <div class="cockpit-top">
                    <div class="cockpit-status">
                        <div class="cockpit-headline">
                            <span>[STATUS_HEADLINE]</span>
                            <span class="pill pill-warning" [LOCAL_BRANCH_VISIBLE]>[LOCAL_BRANCH]</span>
                        </div>
                        <div class="cockpit-sub">[STATUS_MESSAGE]</div>
                        <div class="cockpit-guidance"><strong>[GUIDANCE_TITLE]:</strong> [GUIDANCE_MESSAGE]</div>
                    </div>
                    <div class="cockpit-meta">
                        <div class="ver-strip" title="[REMOTE_HOST]">
                            <span class="ver-muted">[APP_VERSION]</span>
                            <span class="mono">[LOCAL_HASH_SHORT]</span>
                            <span class="ver-arrow">&rarr;</span>
                            <strong>[REMOTE_BRANCH]</strong>
                            <span class="pill [STEP_FILES_PILL_CLASS]">[STEP_FILES_PILL_TEXT]</span>
                        </div>
                        <button type="button" id="refresh-btn" class="cockpit-icon-btn" title="{|Anzeige neu laden|}" aria-label="{|Anzeige neu laden|}">&#x21bb;</button>
                    </div>
                </div>

                <div class="cockpit-actions">
                    <button name="submit" value="[UPGRADE_BUTTON_ACTION]" class="step-btn btn-upgrade-primary">[UPGRADE_BUTTON_LABEL]</button>
                    <button name="submit" value="[UPGRADE_DB_BUTTON_ACTION]" class="step-btn btn-upgrade-secondary">[UPGRADE_DB_BUTTON_LABEL]</button>
                    <span class="db-status-inline">DB: <span class="pill [STEP_DB_PILL_CLASS]">[STEP_DB_PILL_TEXT]</span></span>
                    <div id="force-wrap" class="force-wrap [FORCE_HIGHLIGHT_CLASS]" [UPGRADE_FORCE_VISIBLE]><label>[FORCE_CHECKBOX] {|Erzwingen (-f)|}</label></div>
                    <label class="details-toggle" title="{|Ausführliche Ausgabe im Protokoll aktivieren|}">
                        <input type="checkbox" name="details_anzeigen" value="1" [DETAILS_ANZEIGEN]> {|Details|}
                    </label>
                </div>
            </section>

            <!-- 2. Ein-Seiten-Arbeitsfläche: Links Diagnose + Protokoll, Rechts Quelle + Rollback + Hilfe -->
            <div class="cockpit-grid">
                <div class="cockpit-main">
                    <!-- Diagnose: Fehler, Restdifferenzen, geänderte Dateien (nur sichtbar wenn relevant) -->
                    <section class="card diagnostics-card" [DIAGNOSTICS_VISIBLE] aria-labelledby="diagnostics-title">
                        <h3 class="card-title" id="diagnostics-title">{|Diagnose &amp; Details|}</h3>
                        <div class="card-body">[DIAGNOSTICS_HTML]</div>
                    </section>

                    <!-- Protokoll -->
                    <section class="card log-card" aria-labelledby="log-title">
                        <div class="log-head-row">
                            <div class="log-head-left">
                                <span class="card-title-inline" id="log-title">{|Protokoll|}</span>
                                <span class="log-meta">[LAST_ACTION] &middot; [LAST_RUN]</span>
                            </div>
                            <div class="log-head-actions">
                                <button type="button" id="log-copy-btn" class="log-tool-btn" title="{|Vollständiges Protokoll kopieren|}">{|Kopieren|}</button>
                                <button type="button" id="log-expand-btn" class="log-tool-btn">{|Erweitern|}</button>
                                <a href="?module=upgrade&action=list&ajax=download_log" class="log-download" download>&#128229; {|Download|}</a>
                            </div>
                        </div>
                        <div class="log-toolbar">
                            <div class="log-filters" role="group" aria-label="{|Log-Filter|}">
                                <button type="button" class="log-filter-btn is-active" data-log-filter="all">{|Alle|}</button>
                                <button type="button" class="log-filter-btn" data-log-filter="errors">{|Fehler &amp; Warnungen|}</button>
                                <button type="button" class="log-filter-btn" data-log-filter="diffs">{|DB-Differenzen|}</button>
                            </div>
                            <input type="text" id="log-search" class="log-search" placeholder="{|Log durchsuchen|}…" aria-label="{|Log durchsuchen|}">
                        </div>
                        <div class="log-box" id="log-box">[OUTPUT_FROM_CLI]</div>
                    </section>
                </div>

                <aside class="cockpit-side">
                    <!-- Quelle & Zweig -->
                    <section class="card side-card" aria-labelledby="source-title">
                        <div class="side-card-head">
                            <h3 class="card-title-inline" id="source-title">{|Quelle &amp; Zweig|}</h3>
                            <span class="pill pill-info">[CURRENTBRANCH]</span>
                        </div>
                        <dl class="kv-grid kv-compact">
                            <dt>{|Repo|}</dt><dd title="[REMOTE_HOST]"><span class="mono">[REMOTE_HOST_SHORT]</span></dd>
                            <dt>{|Lokal|}</dt><dd><span class="mono">[LOCAL_HASH_SHORT]</span> <span class="kv-muted">&middot; [LOCAL_COMMIT]</span></dd>
                            <dt>Remote</dt><dd><span class="mono">[REMOTE_HASH_SHORT]</span> <span class="kv-muted">([REMOTE_BRANCH])</span></dd>
                        </dl>
                        <div class="branch-switch-wrap" [BRANCH_SWITCH_VISIBLE]>
                            <p class="branch-switch-warn">{|Vor Zweigwechsel Backup erstellen!|}</p>
                            <div class="branch-list">[BRANCHES]</div>
                        </div>
                    </section>

                    <!-- Rollback -->
                    <section class="card side-card rollback-card" [ROLLBACK_VISIBLE] aria-labelledby="rollback-title">
                        <div class="side-card-head">
                            <h3 class="card-title-inline" id="rollback-title">{|Rollback|}</h3>
                            <span class="pill pill-warning">{|Nur Code|}</span>
                        </div>
                        <div class="tag-select-wrap">[ROLLBACK_TAGS_SELECT]</div>
                        <button name="submit" value="rollback_to_tag" class="action-btn btn-rollback btn-full">&#128281; {|Rollback durchführen|}</button>
                        <p class="side-note">{|DB-Änderungen werden nicht rückgängig gemacht.|}</p>
                    </section>

                    <!-- Hilfe & CLI (standardmäßig eingeklappt) -->
                    <section class="card help-card" aria-labelledby="help-title">
                        <details>
                            <summary id="help-title">{|Hilfe &amp; CLI-Befehle|}</summary>
                            <div class="help-body">
                                <strong>{|Komplett-Upgrade|}</strong> {|aktualisiert Dateien (Git) und Datenbank gemeinsam.|}
                                <strong>{|Nur Datenbank|}</strong> {|prüft oder repariert ausschließlich das Schema.|}
                                <br><br>
                                {|Konsole im Ordner|} <code>upgrade</code>:<br>
                                <code>./upgrade.sh -do</code> &middot; <code>./upgrade.sh -db -do</code>
                            </div>
                        </details>
                    </section>
                </aside>
            </div>
        </form>
    </div>
</div>

<script src="./js/upgrade.js"></script>
