<link rel="stylesheet" type="text/css" href="./css/upgrade.css">

<div id="tabs">
    <ul>
        <li><a href="#tabs-1">{|Upgrader|}</a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form action="" method="post">

            <!-- Status-Banner (volle Breite) -->
            <div class="status-banner banner-[STATUS_LEVEL]" role="status">
                <div class="banner-text">
                    <div class="banner-headline">[STATUS_HEADLINE]</div>
                    <div class="banner-sub">[STATUS_MESSAGE]</div>
                    <div class="banner-guidance">[GUIDANCE_TITLE]<small>[GUIDANCE_MESSAGE]</small></div>
                </div>
                <div class="banner-actions">
                    <button type="button" id="refresh-btn" class="banner-btn icon-btn" title="{|Anzeige neu laden|}" aria-label="{|Anzeige neu laden|}">&#x21bb;</button>
                </div>
            </div>

            <!-- Steps: Dateien + Datenbank -->
            <div class="grid grid-2">
                <section class="card step-card">
                    <div class="step-head">
                        <span class="pill [STEP_FILES_PILL_CLASS]">[STEP_FILES_PILL_TEXT]</span>
                        <strong>{|Dateien · Code &amp; Repo|}</strong>
                    </div>
                    <label class="step-check"><input type="checkbox" name="details_anzeigen" value="1" [DETAILS_ANZEIGEN]> {|Upgrade-Details anzeigen|}</label>
                    <div class="step-actions">
                        <button name="submit" value="[UPGRADE_BUTTON_ACTION]" class="step-btn">[UPGRADE_BUTTON_LABEL]</button>
                    </div>
                    <div class="force-wrap [FORCE_HIGHLIGHT_CLASS]" [UPGRADE_FORCE_VISIBLE]><label>[FORCE_CHECKBOX] {|Erzwingen (-f)|}</label></div>
                </section>
                <section class="card step-card">
                    <div class="step-head">
                        <span class="pill [STEP_DB_PILL_CLASS]">[STEP_DB_PILL_TEXT]</span>
                        <strong>{|Datenbank · DB-Check &amp; Upgrade|}</strong>
                    </div>
                    <label class="step-check"><input type="checkbox" name="db_details_anzeigen" value="1" [DB_DETAILS_ANZEIGEN]> {|DB-Details anzeigen|}</label>
                    <div class="step-actions">
                        <button name="submit" value="[UPGRADE_DB_BUTTON_ACTION]" class="step-btn">[UPGRADE_DB_BUTTON_LABEL]</button>
                    </div>
                </section>
            </div>

            <!-- Versionsabgleich + Upgrade-Quelle -->
            <div class="grid grid-2">
                <section class="card" aria-labelledby="compare-title">
                    <h3 class="card-title" id="compare-title">{|Versionsabgleich|}</h3>
                    <div class="card-body">
                        <div class="compare-panels">
                            <div class="compare-panel">
                                <h4>{|Lokal|}</h4>
                                <dl class="kv-grid">
                                    <dt>{|Version|}</dt><dd>[APP_VERSION]</dd>
                                    <dt>Branch</dt><dd><span class="pill pill-warning" [LOCAL_BRANCH_VISIBLE]>[LOCAL_BRANCH]</span></dd>
                                    <dt>Commit</dt><dd><span class="mono">[LOCAL_HASH_SHORT]</span></dd>
                                    <dt>{|Datum|}</dt><dd><span class="mono">[LOCAL_COMMIT]</span></dd>
                                </dl>
                            </div>
                            <div class="compare-panel">
                                <h4>Remote</h4>
                                <dl class="kv-grid">
                                    <dt>{|Quelle|}</dt><dd>[REMOTE_HOST]</dd>
                                    <dt>Branch</dt><dd><strong>[REMOTE_BRANCH]</strong></dd>
                                    <dt>Commit</dt><dd><span class="mono">[REMOTE_HASH_SHORT]</span></dd>
                                </dl>
                            </div>
                        </div>
                        <div class="compare-status">
                            {|Status|}: <span class="pill [UPDATE_STATUS_CLASS]">[UPDATE_STATUS]</span>
                        </div>
                    </div>
                </section>
                <section class="card" aria-labelledby="branch-title">
                    <h3 class="card-title" id="branch-title">{|Upgrade-Zweig|}</h3>
                    <div class="card-body">
                        <p class="hint hint-spaced">{|Aktueller Zweig|}: <strong>OpenXE [CURRENTBRANCH]</strong></p>
                        <p class="hint hint-spaced">{|Ab der Version 1.13 werden mehrere Zweige unterstützt. Beim Wechsel des Zweigs, i.d.R. auf eine höhere Version, wird eine Kompatibilitätsprüfung durchgeführt, bei Erfolg eine Migration, dann das eigentliche Upgrade. Zweige sind untereinander nicht kompatibel, ein Wechsel auf einen alten Zweig ist in der Regel nicht möglich.|}</p>
                        <p class="hint hint-spaced"><strong class="branch-warning">{|Vor dem Zweigwechsel IMMER ein Backup erstellen!|}</strong></p>
                        <div class="branch-list">[BRANCHES]</div>
                    </div>
                </section>
            </div>

            <!-- Rollback + Hilfe -->
            <div class="grid grid-2">
                <section class="card rollback-card" [ROLLBACK_VISIBLE] aria-labelledby="rollback-title">
                    <h3 class="card-title" id="rollback-title">{|Rollback &amp; Wiederherstellung|}</h3>
                    <div class="card-body">
                        <p class="hint hint-rollback">
                            &#9888;&#65039; <strong>{|Vorsicht:|}</strong> {|Rollback setzt nur den Code zurück. Datenbank-Änderungen werden|} <strong>{|nicht|}</strong> {|rückgängig gemacht!|}
                        </p>
                        <div class="form-row">
                            <label>{|Verfügbarer Wiederherstellungspunkt|}</label>
                            <div class="tag-select-wrap">[ROLLBACK_TAGS_SELECT]</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button name="submit" value="rollback_to_tag" class="action-btn btn-rollback" onclick="return confirm('Wirklich auf diesen Stand zurücksetzen? Code wird überschrieben!');">&#128281; {|Rollback durchführen|}</button>
                    </div>
                </section>
                <section class="card help-card" aria-labelledby="help-title">
                    <details open>
                        <summary id="help-title">{|Hilfe &amp; Hinweise|}</summary>
                        <div class="help-body">
                            {|Das Upgrade läuft in zwei Schritten:|} <strong>{|Dateien aktualisieren|}</strong> {|und|} <strong>{|Datenbank auffrischen|}</strong>.
                            {|Für lange Läufe das Protokoll über das Aktualisieren-Symbol neu laden.|}
                            {|Bei hartnäckigen Fehlern hilft der Konsolen-Run:|} <code>./upgrade.sh -do</code> {|im Unterordner|} <code>upgrade</code>.
                            <br><br>
                            <strong>{|Tipp:|}</strong> {|Zweigwechsel: anderen Zweig auswählen und &bdquo;Upgrade starten&ldquo; ausführen. Über die Konsole alternativ im Unterordner|} <code>upgrade</code>: <code>./upgrade.sh -do -m BRANCHNAME</code>
                            {|Rollback setzt nur den Code zurück &ndash; DB-Änderungen müssen separat behandelt werden.|}
                        </div>
                    </details>
                </section>
            </div>

            <!-- Protokoll -->
            <section class="card" aria-labelledby="log-title">
                <div class="log-header">
                    <span class="log-title" id="log-title">{|Protokoll|}</span>
                    <span class="hint log-meta">{|Letzte Aktion|}: [LAST_ACTION] &middot; {|Letzter Durchlauf|}: [LAST_RUN]</span>
                    <input type="text" id="log-search" class="log-search" placeholder="{|Log durchsuchen|}…" aria-label="{|Log durchsuchen|}">
                    <a href="?module=upgrade&action=list&ajax=download_log" class="log-download" download>&#128229; {|Log herunterladen|}</a>
                </div>
                <div class="log-box" id="log-box">[OUTPUT_FROM_CLI]</div>
            </section>
        </form>
    </div>
</div>

<script src="./js/upgrade.js"></script>
