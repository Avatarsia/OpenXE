<?php

/*
 * Copyright (c) 2022 OpenXE project
 */

use Xentral\Components\Database\Exception\QueryFailureException;

class upgrade {

    /**
     * Validation-Pattern fuer den per Radio gewaehlten Zweig-Namen aus
     * upgrade/data/remote.json (Feld "name"). Der Wert wird nur als
     * String an upgrade_main() durchgereicht, nie an eine Shell.
     */
    const BRANCH_NAME_PATTERN = '/^[A-Za-z0-9._\\/-]+$/';
    // Optionaler numerischer Suffix (-2, -3, ...) entsteht bei
    // Tag-Namenskollisionen innerhalb derselben Sekunde.
    const ROLLBACK_TAG_PATTERN = '/^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}(-\d+)?$/';

    /**
     * Match-Regexe auf Zeilen der Engine-Ausgabe (upgrade/data/upgrade.php).
     * Alle Matches sind zeilenverankert (/m), damit Commit-Betreffs in der
     * "Pending upgrades"-Liste oder Verbose-Ausgaben die Klassifikation
     * nicht fälschlich auslösen (False-Positives). Bei Engine-Updates
     * müssen diese mit den dort produzierten Outputs abgeglichen werden.
     */
    const RESULT_ABORTED = '/^-+ Aborted! -+$/m';
    const RESULT_UP_TO_DATE = '/^Already up to date\.$/m';
    const RESULT_NO_UPGRADES = '/^No upgrades pending\.$/m';
    const RESULT_MODIFIED_FILES = '/^There are modified files:$/m';
    const RESULT_NEEDS_FORCE = '/^Clear modified files or use -f$/m';
    const RESULT_DIFF_IN_DB_NOT_JSON = '/^(\d+) differences \(in DB not in JSON\)\.$/m';
    const RESULT_DIFF_IN_JSON_NOT_DB = '/^(\d+) differences \(in JSON not in DB\)\.$/m';
    const RESULT_DIFF_REMAINING = '/^(\d+) differences remaining after upgrade\.$/m';
    const RESULT_DB_ERRORS = '/^Database upgrade errors: (\d+)$/m';

    function __construct($app, $intern = false) {
        $this->app = $app;
        if ($intern)
            return;

        $this->app->ActionHandlerInit($this);
        $this->app->ActionHandler("list", "upgrade_overview");
        $this->app->DefaultActionHandler("list");
        $this->app->ActionHandlerListen($app);
    }

    public function Install() {
        /* Fill out manually later */
    }

    /**
     * Escape-Helper für HTML-Kontext. Setzt explizit Flags und Charset,
     * damit auch invalid encoded Bytes über ENT_SUBSTITUTE behandelt
     * werden und Single-Quotes (Attribut-Kontext) escaped sind.
     */
    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Zeilenverankerter Match auf das Engine-Log. Alle RESULT_*-Konstanten
     * sind /m-Regexe — KEIN str_contains aufs ganze Log verwenden.
     */
    private function logMatches(string $pattern, string $log): bool
    {
        return preg_match($pattern, $log) === 1;
    }

    /**
     * Extrahiert eine Zahl aus einer zeilenverankerten Log-Zeile (Regex mit
     * genau einer Capture-Group). Liefert null, wenn die Zeile fehlt —
     * "0" und "Zeile fehlt" müssen unterscheidbar bleiben.
     */
    private function logNumber(string $pattern, string $log): ?int
    {
        if (preg_match($pattern, $log, $m) === 1) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Berechtigungs-Guard für Upgrader-Endpoints (UI und AJAX).
     * Der Upgrader führt git-Operationen und DB-Migrationen aus —
     * daher ist Admin-Login Voraussetzung.
     */
    private function isUpgraderAuthorized(): bool
    {
        if (empty($this->app->User) || (int)$this->app->User->GetID() <= 0) {
            return false;
        }
        return $this->app->User->GetType() === 'admin';
    }

    /**
     * Liest Branch/Commit-Datum/Hashes aus dem lokalen Repo. Bei detached
     * HEAD liefert rev-parse --abbrev-ref den String "HEAD" — für die
     * Anzeige wird daraus "detached @ <kurzhash>" (passiert z.B. nach
     * einem Rollback-Checkout auf einen Tag).
     */
    private function readGitInfo(string $git_root): array
    {
        $info = array('branch' => '', 'commit' => '', 'hash' => '', 'hash_short' => '');
        if ($git_root === "") {
            return $info;
        }
        $info['branch'] = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse --abbrev-ref HEAD'));
        $info['commit'] = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' log -1 --date=short --pretty="%cd"'));
        $info['hash'] = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse HEAD'));
        $info['hash_short'] = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse --short=8 HEAD'));
        if ($info['branch'] === 'HEAD') {
            $info['branch'] = 'detached @ '.$info['hash_short'];
        }
        return $info;
    }

    /**
     * Erzeugt einen pre-upgrade-Tag im lokalen Git-Repo und entfernt
     * Tags jenseits der 10 jüngsten Einträge. Kollidiert der Name mit
     * einem Tag aus derselben Sekunde, wird mit Suffix -2, -3 erneut
     * versucht (Pattern ROLLBACK_TAG_PATTERN lässt den Suffix zu).
     *
     * @return string|null Name des erzeugten Tags oder null bei Fehlschlag —
     *                     der Aufrufer kann sich dann nicht auf einen
     *                     Rollback verlassen.
     */
    private function createRollbackTag(string $git_root): ?string
    {
        $base_name = 'pre-upgrade-'.date('Y-m-d-H-i-s');
        $tag_name = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $candidate = $attempt === 1 ? $base_name : $base_name.'-'.$attempt;
            $tag_cmd = 'git -C '.escapeshellarg($git_root).' tag '.escapeshellarg($candidate).' 2>&1';

            $tag_output = [];
            $tag_exit_code = 0;
            exec($tag_cmd, $tag_output, $tag_exit_code);

            if ($tag_exit_code === 0) {
                $tag_name = $candidate;
                break;
            }
            // Nur bei Namenskollision erneut versuchen — andere Fehler
            // (kein Repo, keine Rechte) verschwinden nicht durch Retry.
            if (preg_match('/already exists/i', implode("\n", $tag_output)) !== 1) {
                break;
            }
        }

        if ($tag_name === null) {
            return null;
        }

        // Cleanup: Behalte nur die neuesten 10 pre-upgrade Tags
        $list_tags_cmd = 'git -C '.escapeshellarg($git_root).' tag -l "pre-upgrade-*" --sort=-creatordate 2>&1';
        $all_tags_output = [];
        $list_exit_code = 0;
        exec($list_tags_cmd, $all_tags_output, $list_exit_code);

        if ($list_exit_code === 0 && count($all_tags_output) > 10) {
            $tags_to_delete = array_slice($all_tags_output, 10);
            foreach ($tags_to_delete as $old_tag) {
                $delete_cmd = 'git -C '.escapeshellarg($git_root).' tag -d '.escapeshellarg(trim($old_tag)).' 2>&1';
                exec($delete_cmd);
            }
        }

        return $tag_name;
    }

    function upgrade_overview() {

        // AJAX-Endpoints für Log-Download. Vor jeder Ausgabe wird die
        // Berechtigung geprüft: nur eingeloggte Admin-User dürfen den
        // Upgrader und seinen Log einsehen.
        $ajax_action = $this->app->Secure->GetGET('ajax');

        if ($ajax_action === 'download_log') {
            if (!$this->isUpgraderAuthorized()) {
                header('HTTP/1.1 403 Forbidden');
                exit;
            }
            $logfile = dirname(__DIR__, 2) . '/upgrade/data/upgrade.log';
            if (file_exists($logfile)) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="upgrade_log_' . date('Y-m-d_H-i') . '.txt"');
                readfile($logfile);
            } else {
                header('HTTP/1.1 404 Not Found');
                echo "Log file not found.";
            }
            exit;
        }

        $submit = $this->app->Secure->GetPOST('submit');
        // Secure::Syntax() reicht Arrays unveraendert durch, d.h. submit[]=x
        // liefert hier ein Array und laesst isset($actions[$submit]) weiter
        // unten mit einem TypeError abbrechen. Nicht-Strings deshalb wie
        // "kein Submit" behandeln ('' nimmt ueberall denselben Pfad wie null).
        if (!is_string($submit)) {
            $submit = '';
        }
        $details_post = $this->app->Secure->GetPOST('details_anzeigen');
        $db_details_post = $this->app->Secure->GetPOST('db_details_anzeigen');
        // GetPOST liefert '' (nie null), wenn ein Feld nicht gesendet wurde.
        // Beim allerersten Aufruf (kein Submit) ist verbose Default an,
        // danach zählt allein der Checkbox-Zustand.
        $no_submit = ($submit === null || $submit === '');
        $verbose = $no_submit ? true : ($details_post === '1');
        $db_verbose = $no_submit ? true : ($db_details_post === '1');
        $force = $this->app->Secure->GetPOST('erzwingen') === '1';
        // Zweigauswahl (Upstream branchupgrade): Radio-Wert aus der Liste
        // der in remote.json hinterlegten Zweige. Ungueltige Werte werden
        // verworfen, dann verhaelt sich do_upgrade wie ohne Zweigwechsel.
        // branch[]=x kaeme als Array an; (string) daraus wuerde das Literal
        // "Array" erzeugen, das BRANCH_NAME_PATTERN passiert.
        $branch_raw = $this->app->Secure->GetPOST('branch');
        $branch_input = is_string($branch_raw) ? trim($branch_raw) : '';
        if ($branch_input !== '' && !preg_match(self::BRANCH_NAME_PATTERN, $branch_input)) {
            $branch_input = '';
        }

        $this->app->Tpl->Set('ERZWINGEN', $force ? "checked" : "");
        $this->app->Tpl->Set('DETAILS_ANZEIGEN', $verbose ? "checked" : "");
        $this->app->Tpl->Set('DB_DETAILS_ANZEIGEN', $db_verbose ? "checked" : "");

        // Pfadstabil relativ zu __DIR__: getcwd() kann sich durch den
        // OpenXE-Frontcontroller ändern, daher ALLE Pfade aus __DIR__
        // ableiten. require_once verhindert Reentry bei künftigen
        // Refactorings.
        require_once(__DIR__ . '/../../upgrade/data/upgrade.php');

        $upgrade_dir = dirname(__DIR__, 2) . '/upgrade';
        $logfile = $upgrade_dir . '/data/upgrade.log';
        $remote_config_file = $upgrade_dir . '/data/remote.json';
        upgrade_set_out_file_name($logfile);

        $upgrade_available = false;
        $upgrade_db_available = false;

        $status_headline = "Bereit";
        $status_level = "info";
        $status_message = "Wähle eine Aktion, um den Upgrader zu starten.";
        $guidance_title = "Nächste Schritte";
        $guidance_message = "Aktion auswählen und starten.";
        $last_action = "Noch keine Aktion ausgeführt";
        $last_run = "";

        $remote_host = "";
        $remote_branch = "";
        $current_branch = "";
        $branches_html = "";

        // Sucht ab __DIR__ aufwärts nach dem Repo-Root (.git-Verzeichnis).
        // Bricht ab, wenn dirname() denselben Pfad zurückgibt (Drive-Root).
        $git_root = __DIR__;
        while (!is_dir($git_root."/.git")) {
            $parent = dirname($git_root);
            if ($parent === $git_root) {
                break;
            }
            $git_root = $parent;
        }
        if (!is_dir($git_root."/.git")) {
            $git_root = "";
        }

        $git_info = $this->readGitInfo($git_root);
        $git_branch = $git_info['branch'];
        $git_commit = $git_info['commit'];
        $local_hash = $git_info['hash'];
        $local_hash_short = $git_info['hash_short'];

        $update_status_text = "Remote-Stand nicht geprüft.";
        $update_status_class = "pill-info";
        $remote_hash = "";
        $remote_hash_short = "";

        // remote.json ist seit Upstream branchupgrade eine Liste von
        // Zweigen (name/enabled/active/description/host/branch/check/
        // migration). Der erste Eintrag mit active=true ist die aktuelle
        // Upgrade-Quelle — dieselbe Regel wie in upgrade_main().
        if (is_readable($remote_config_file)) {
            $remote_data_raw = file_get_contents($remote_config_file);
            $remotes = json_decode($remote_data_raw, true);
            if (!is_array($remotes)) {
                $remotes = array();
            }
            $branch_rows = "";
            foreach ($remotes as $remote) {
                if (!is_array($remote) || empty($remote['name'])) {
                    continue;
                }
                $is_active = !empty($remote['active']);
                $is_enabled = !empty($remote['enabled']);
                if ($is_active && $current_branch === "") {
                    $current_branch = (string)$remote['name'];
                    $remote_host = (string)($remote['host'] ?? "");
                    $remote_branch = (string)($remote['branch'] ?? "");
                }
                $branch_status = $is_active ? "aktiv" : "";
                if (!$is_enabled) {
                    $branch_status = "gesperrt";
                }
                $name_escaped = $this->esc((string)$remote['name']);
                $branch_rows .= '<tr>'
                    .'<td><input type="radio" name="branch" value="'.$name_escaped.'"'
                    .($is_active ? ' checked' : '').(!$is_enabled ? ' disabled' : '').'></td>'
                    .'<td>'.$name_escaped.'</td>'
                    .'<td>'.$this->esc($branch_status).'</td>'
                    .'<td>'.$this->esc((string)($remote['description']['de'] ?? "")).'</td>'
                    .'</tr>';
            }
            if ($branch_rows !== "") {
                $branches_html = '<table class="branch-table">'
                    .'<thead><tr><th></th><th>Name</th><th>Status</th><th>Beschreibung</th></tr></thead>'
                    .'<tbody>'.$branch_rows.'</tbody></table>';
            }
            if ($current_branch === "") {
                $status_headline = "Hinweis";
                $status_level = "warning";
                $status_message = "Kein aktiver Zweig in der Upgrade-Quelle konfiguriert.";
            }
        } else {
            $status_headline = "Hinweis";
            $status_level = "warning";
            $status_message = "Konfiguration der Upgrade-Quelle konnte nicht geladen werden.";
        }

        // Concurrency-Lock: verhindert parallele Engine-/schreibende Submits
        // (Doppel-Klick, zweiter Browser-Tab). Auch die Check-Submits löschen
        // und schreiben das Logfile und laufen daher unter demselben Lock.
        // flock() ist non-blocking — ein konkurrierender Request bekommt
        // sofort eine UI-Meldung statt zu hängen. Auth/AJAX-Pfade laufen
        // bereits oben via exit raus, danach gibt es kein exit/die mehr in
        // dieser Funktion, daher reicht ein einfaches Release am Ende
        // (kein try/finally).
        $locked_submits = ['check_upgrade', 'do_upgrade', 'check_db', 'do_db_upgrade', 'rollback_to_tag'];
        $needs_lock = in_array($submit, $locked_submits, true);
        $lock_handle = null;
        if ($needs_lock && $git_root !== "") {
            $lock_file = $upgrade_dir.'/data/.upgrader.lock';
            $lock_handle = @fopen($lock_file, 'c');
            if ($lock_handle === false) {
                // fopen-Fehler (Rechte/Verzeichnis) ist KEIN belegtes Lock —
                // klar unterscheiden, sonst sucht der User nach einem
                // Phantom-Prozess statt nach Berechtigungen.
                $status_headline = "Lock-Datei nicht schreibbar";
                $status_level = "error";
                $status_message = "Die Lock-Datei konnte nicht angelegt werden. Bitte Schreibrechte auf dem upgrade/data-Verzeichnis prüfen.";
                $last_action = "Abgebrochen (Lock-Datei nicht schreibbar)";
                $submit = null; // verhindert Submit-Dispatch unten
            } elseif (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
                fclose($lock_handle);
                $lock_handle = null;
                $status_headline = "Anderer Vorgang aktiv";
                $status_level = "warning";
                $status_message = "Anderer Upgrade-Vorgang läuft gerade. Bitte warten und Seite neu laden.";
                $last_action = "Abgebrochen (Lock belegt)";
                $submit = null; // verhindert Submit-Dispatch unten
            }
        }

        // Calculate version alignment (local vs. upgrade source)
        $step_files_pill_class = "pill-info";
        $step_files_pill_text = "nicht geprüft";
        if ($git_root !== "" && $remote_host !== "" && $remote_branch !== "") {
            $remote_ref = "refs/heads/".$remote_branch;
            $remote_cmd = 'git -C '.escapeshellarg($git_root).' ls-remote '.escapeshellarg($remote_host).' '.escapeshellarg($remote_ref).' 2>&1';
            $remote_output = [];
            $remote_exit_code = 0;
            exec($remote_cmd, $remote_output, $remote_exit_code);
            $remote_line = trim((string)($remote_output[0] ?? ''));

            if ($remote_exit_code !== 0) {
                // Remote-Probe fehlgeschlagen: exec liefert genauen Exit-Code,
                // shell_exec hätte hier nur `null` zurückgegeben.
                $update_status_text = "Remote-Probe fehlgeschlagen (Exit-Code ".$remote_exit_code.")";
                $update_status_class = "pill-warning";
                $step_files_pill_class = "pill-warning";
                $step_files_pill_text = "Remote nicht prüfbar";
            } elseif ($remote_line !== "") {
                $remote_hash = trim(strtok($remote_line, "\t "));
                $remote_hash_short = substr($remote_hash, 0, 8);

                if ($local_hash === "") {
                    $update_status_text = "Lokaler Stand unbekannt";
                    $update_status_class = "pill-warning";
                    $step_files_pill_class = "pill-warning";
                    $step_files_pill_text = "lokaler Stand unbekannt";
                } elseif ($local_hash === $remote_hash) {
                    $update_status_text = "Alles aktuell";
                    $update_status_class = "pill-success";
                    $step_files_pill_class = "pill-success";
                    $step_files_pill_text = "aktuell";
                } else {
                    // Hash-Mismatch heißt nicht zwingend "remote ist neuer" —
                    // lokal kann auch voraus oder divergiert sein. Ohne
                    // zusätzliche git-Aufrufe ist das nicht unterscheidbar.
                    $update_status_text = "Stand weicht ab – Prüfung empfohlen";
                    $update_status_class = "pill-warning";
                    $step_files_pill_class = "pill-warning";
                    $step_files_pill_text = "Stand weicht ab";
                }
            } else {
                $update_status_text = "Remote nicht erreichbar";
                $update_status_class = "pill-warning";
                $step_files_pill_class = "pill-warning";
                $step_files_pill_text = "Remote nicht erreichbar";
            }
        }

        // Engine erwartet als directory das upgrade/-Verzeichnis.
        $directory = $upgrade_dir;
        $result_code = null;
        $engine_exception = null;

        // Lookup-Tabelle für die Engine-Aktionen. Jede Action mapped auf
        // ein Set Flags, das nahezu 1:1 an upgrade_main() durchgereicht
        // wird. Andere Submits (refresh, rollback_to_tag) sind UI-only und
        // werden separat behandelt; unbekannte Submits laufen wie
        // 'refresh' (kein Status-Overwrite, kein Log-Reset).
        //
        // Zweigwechsel (Upstream branchupgrade): weicht der gewaehlte Zweig
        // vom aktiven ab, laeuft do_upgrade als Migration (-m) — die Engine
        // prueft dann check.php, fuehrt migrate.php aus und wechselt den
        // Zweig, bevor das eigentliche Upgrade startet.
        $do_migrate = ($branch_input !== '' && $current_branch !== '' && $branch_input !== $current_branch);
        $actions = [
            'check_upgrade' => [
                'label' => "System-Check (Dateien & Datenbank)",
                'verbose' => $verbose,
                'check_git' => true,
                'do_git' => false,
                'check_db' => true,
                'do_db' => false,
                'sets_upgrade_visible' => true,
                'sets_upgrade_db_visible' => false,
            ],
            'do_upgrade' => [
                'label' => $do_migrate ? "Zweigwechsel auf ".$branch_input." (Dateien & Datenbank)" : "Upgrade (Dateien & Datenbank)",
                'verbose' => $verbose,
                'check_git' => true,
                'do_git' => true,
                'check_db' => true,
                'do_db' => true,
                'do_migrate' => $do_migrate,
                'sets_upgrade_visible' => false,
                'sets_upgrade_db_visible' => false,
            ],
            'check_db' => [
                'label' => "Datenbank-Check",
                'verbose' => $db_verbose,
                'check_git' => false,
                'do_git' => false,
                'check_db' => true,
                'do_db' => false,
                'sets_upgrade_visible' => false,
                'sets_upgrade_db_visible' => true,
            ],
            'do_db_upgrade' => [
                'label' => "Datenbank-Upgrade",
                'verbose' => $db_verbose,
                'check_git' => false,
                'do_git' => false,
                'check_db' => true,
                'do_db' => true,
                'sets_upgrade_visible' => false,
                'sets_upgrade_db_visible' => true,
            ],
        ];

        $engine_ran = isset($actions[$submit]);

        if ($engine_ran) {
            $cfg = $actions[$submit];
            $last_action = $cfg['label'];
            if (file_exists($logfile)) {
                unlink($logfile);
            }

            // Rollback-Tag setzen, bevor irgendetwas am Working-Tree
            // verändert wird. Auch für do_db_upgrade gesetzt: ein DB-
            // Upgrade modifiziert den Tree zwar nicht direkt, aber bei
            // gemischten Fehler-Szenarien hilft der Tag, einen späteren
            // manuellen Git-Reset auf einen bekannten Stand zu fahren.
            // ACHTUNG: Der Tag rollt nur Code zurück — DB-Schema-
            // Migrationen müssen separat behandelt werden.
            // Bei do_upgrade ohne -f bricht die Engine bei lokalen
            // Änderungen sofort ab ("Clear modified files or use -f") —
            // ein Tag auf denselben Stand wäre nutzlos, daher vorher
            // denselben Check wie die Engine (git ls-files -m) machen.
            if (($cfg['do_git'] || $cfg['do_db']) && $git_root !== "") {
                $create_tag = true;
                if ($cfg['do_git'] && !$force) {
                    $modified_output = [];
                    $modified_exit_code = 0;
                    exec('git -C '.escapeshellarg($git_root).' ls-files -m 2>&1', $modified_output, $modified_exit_code);
                    if (!empty($modified_output)) {
                        $create_tag = false;
                    }
                }
                if ($create_tag) {
                    $this->createRollbackTag($git_root);
                }
            }

            // Engine-Output deterministisch in englischer Locale halten —
            // die RESULT_*-Konstanten matchen englische git-Strings.
            // Vorherige Werte sichern und nach dem Call restoren, damit
            // nachgelagerter Code (z.B. weitere Module im selben Request)
            // die konfigurierte Locale nicht verliert.
            $prev_lc_all = getenv('LC_ALL');
            $prev_lang = getenv('LANG');
            putenv('LC_ALL=C');
            putenv('LANG=C');

            try {
                $result_code = upgrade_main(
                    directory: $directory,
                    verbose: $cfg['verbose'],
                    check_git: $cfg['check_git'],
                    do_git: $cfg['do_git'],
                    export_db: false,
                    check_db: $cfg['check_db'],
                    strict_db: false,
                    do_db: $cfg['do_db'],
                    force: $force,
                    connection: false,
                    origin: false,
                    drop_keys: false,
                    do_migrate: !empty($cfg['do_migrate']),
                    upgrade_branch: !empty($cfg['do_migrate']) ? $branch_input : ''
                );
            } catch (Throwable $e) {
                // PHP >= 8.1 wirft z.B. mysqli_sql_exception — das darf den
                // Request nicht killen, der User braucht eine UI-Meldung.
                $result_code = -1;
                $engine_exception = $e->getMessage();
            } finally {
                putenv($prev_lc_all === false ? 'LC_ALL' : 'LC_ALL='.$prev_lc_all);
                putenv($prev_lang === false ? 'LANG' : 'LANG='.$prev_lang);
            }

            // Check-Buttons erst nach bekanntem Ergebnis schalten: ein
            // fehlgeschlagener Check (-1) darf den Button nicht auf
            // "Upgrade starten" umstellen.
            if ($cfg['sets_upgrade_visible']) {
                $upgrade_available = ($result_code === 0 || $result_code === 1);
            }
            if ($cfg['sets_upgrade_db_visible']) {
                $upgrade_db_available = ($result_code === 0 || $result_code === 1);
            }
        } elseif ($submit === 'refresh') {
            $last_action = "Anzeige aktualisiert";
        } elseif ($submit === 'rollback_to_tag') {
            $last_action = "Rollback durchgeführt";
            $rollback_tag = $this->app->Secure->GetPOST('rollback_tag');
            // rollback_tag[]=x waere ein Array und liesse preg_match() unten
            // mit einem TypeError abbrechen.
            if (!is_string($rollback_tag)) {
                $rollback_tag = '';
            }

            if ($git_root !== "" && !empty($rollback_tag)) {
                // Validiere Tag-Name (nur pre-upgrade-* Tags erlauben)
                if (preg_match(self::ROLLBACK_TAG_PATTERN, $rollback_tag)) {
                    if (file_exists($logfile)) {
                        unlink($logfile);
                    }

                    // Checkout zum Tag. Exit-Code MUSS geprüft werden:
                    // bei Failure darf der User nicht denken, der Rollback
                    // sei durchgelaufen.
                    $checkout_cmd = 'git -C '.escapeshellarg($git_root).' checkout '.escapeshellarg($rollback_tag).' -f 2>&1';
                    $checkout_output = [];
                    $checkout_exit_code = 0;
                    exec($checkout_cmd, $checkout_output, $checkout_exit_code);

                    file_put_contents($logfile, "=== Rollback to tag: $rollback_tag ===\n");
                    file_put_contents($logfile, implode("\n", $checkout_output)."\n", FILE_APPEND);
                    file_put_contents($logfile, "exit code: $checkout_exit_code\n", FILE_APPEND);

                    if ($checkout_exit_code === 0) {
                        $status_headline = "Rollback durchgeführt";
                        $status_level = "success";
                        $status_message = "System auf Stand von Tag $rollback_tag zurückgesetzt.";
                        $guidance_title = "Wichtig";
                        $guidance_message = "Code wurde zurückgesetzt. DB-Änderungen wurden NICHT rückgängig gemacht!";

                        // Git-Infos wurden VOR dem Checkout gelesen und wären
                        // jetzt stale (Hash/Branch/Datum). Neu einlesen; der
                        // Remote-Vergleich oben lief mit dem alten Stand,
                        // daher die Update-Pille neutral setzen statt
                        // erneut zu proben.
                        $git_info = $this->readGitInfo($git_root);
                        $git_branch = $git_info['branch'];
                        $git_commit = $git_info['commit'];
                        $local_hash = $git_info['hash'];
                        $local_hash_short = $git_info['hash_short'];
                        $remote_hash = "";
                        $remote_hash_short = "";
                        $update_status_text = "Nach Rollback nicht erneut geprüft";
                        $update_status_class = "pill-info";
                        $step_files_pill_class = "pill-info";
                        $step_files_pill_text = "nicht geprüft";
                    } else {
                        $status_headline = "Rollback fehlgeschlagen";
                        $status_level = "error";
                        $status_message = "Rollback fehlgeschlagen (Exit-Code $checkout_exit_code). Working-Tree wurde NICHT zurückgesetzt. Siehe Protokoll.";
                        $guidance_title = "Fehlerbehebung";
                        $guidance_message = "Protokoll prüfen, lokale Änderungen oder Konflikte beheben und Rollback erneut versuchen.";
                        $last_action = "Rollback fehlgeschlagen";
                    }
                } else {
                    $status_headline = "Ungültiger Tag";
                    $status_level = "error";
                    $status_message = "Nur pre-upgrade-* Tags können für Rollback verwendet werden.";
                }
            }
        }

        // Read results
        $result = file_exists($logfile) ? file_get_contents($logfile) : "";

        // Force-Hinweis nur auswerten, wenn in DIESEM Request ein Engine-
        // Lauf stattfand — sonst blendet das alte Log beim bloßen
        // Seitenaufruf/Refresh die Force-Checkbox ohne Anlass ein.
        $highlight_force = $engine_ran && !$force && $this->logMatches(self::RESULT_NEEDS_FORCE, $result);

        if ($result_code === 0 && $result !== "" && $this->logMatches(self::RESULT_ABORTED, $result)) {
            // Absicherung: ein "Aborted!"-Marker im Log dominiert den
            // Returncode (die Engine meldet Abbrüche normalerweise mit -1).
            $result_code = -1;
        }

        // Differences-Auswertung je Aktion:
        // - Checks: relevant ist "in JSON not in DB" (Schema-Teile, die der
        //   DB fehlen). "in DB not in JSON" (überflüssige Tabellen) ist nur
        //   informativ und triggert nie ein "Upgrade empfohlen".
        // - do_*: zählen die Restdifferenzen nach dem Upgrade.
        $diff_count = null;      // fehlende Teile (in JSON not in DB)
        $obsolete_count = null;  // überflüssige Teile (in DB not in JSON)
        $remaining_count = null; // Restdifferenzen nach Upgrade
        $db_error_count = null;  // fehlgeschlagene DB-Statements

        // Status-Klassifikation NUR für Engine-Submits — rollback_to_tag
        // und unbekannte Submits setzen ihren Status selbst bzw. behalten
        // den Default.
        if ($engine_ran) {
            $diff_count = $this->logNumber(self::RESULT_DIFF_IN_JSON_NOT_DB, $result);
            $obsolete_count = $this->logNumber(self::RESULT_DIFF_IN_DB_NOT_JSON, $result);
            $remaining_count = $this->logNumber(self::RESULT_DIFF_REMAINING, $result);
            $db_error_count = $this->logNumber(self::RESULT_DB_ERRORS, $result);

            $has_modified_files = $this->logMatches(self::RESULT_MODIFIED_FILES, $result);
            $up_to_date = $this->logMatches(self::RESULT_UP_TO_DATE, $result)
                || $this->logMatches(self::RESULT_NO_UPGRADES, $result);

            if ($result_code === 0 && $result === "") {
                // Return 0 ohne eine einzige Log-Zeile: kein echter
                // Erfolg nachweisbar — nicht grün melden.
                $status_headline = "Kein Protokoll erzeugt";
                $status_level = "warning";
                $status_message = "Die Aktion meldet Erfolg, hat aber kein Protokoll geschrieben. Bitte manuell prüfen.";
                $guidance_title = "Hinweis";
                $guidance_message = "Log-Datei und Schreibrechte prüfen, Aktion ggf. erneut starten.";
            } elseif ($result_code === 0) {
                $status_headline = "Aktion erfolgreich";
                $status_level = "success";
                if ($up_to_date) {
                    $status_message = "Keine neuen Updates verfügbar. System ist aktuell.";
                } else {
                    $status_message = "Der Durchlauf wurde ohne Fehler abgeschlossen.";
                }

                switch ($submit) {
                    case 'check_upgrade':
                        if ($diff_count === 0) {
                            $guidance_title = "Alles aktuell";
                            $guidance_message = "Keine DB-Differenzen erkannt. Code wurde nicht aktualisiert. Kein Upgrade nötig.";
                        } elseif ($diff_count !== null && $diff_count > 0) {
                            $guidance_title = "Upgrade empfohlen";
                            $guidance_message = "Es wurden Unterschiede festgestellt. Starte jetzt \"Upgrade jetzt starten\", um Dateien und DB zu aktualisieren.";
                        } else {
                            $guidance_title = "Prüfung abgeschlossen";
                            $guidance_message = "Protokoll prüfen. Wenn Änderungen gewünscht sind, starte das Upgrade.";
                        }
                        if ($has_modified_files) {
                            $guidance_message .= " Achtung: Lokale Änderungen vorhanden – entweder bereinigen oder mit '-f/Erzwingen' arbeiten.";
                        }
                        break;
                    case 'do_upgrade':
                        if ($up_to_date && ($remaining_count === 0 || ($remaining_count === null && $diff_count === 0))) {
                            // No-op-Lauf: weder Dateien noch DB angefasst —
                            // dann nicht "wurden aktualisiert" behaupten.
                            $guidance_title = "Alles aktuell";
                            $guidance_message = "Dateien und Datenbank sind auf dem Stand der Upgrade-Quelle.";
                        } else {
                            $guidance_title = "Upgrade abgeschlossen";
                            $guidance_message = "System und Datenbank wurden aktualisiert. Nächster Schritt: Funktionstest durchführen.";
                        }
                        break;
                    case 'check_db':
                        if ($diff_count === 0) {
                            $guidance_title = "Datenbank ist aktuell";
                            $guidance_message = "Keine Differenzen gefunden. Kein DB-Upgrade erforderlich.";
                        } elseif ($diff_count !== null && $diff_count > 0) {
                            $guidance_title = "DB-Upgrade möglich";
                            $guidance_message = "Es wurden Datenbankunterschiede gefunden. Starte \"Datenbank-Upgrade\".";
                        } else {
                            $guidance_title = "Prüfung abgeschlossen";
                            $guidance_message = "Protokoll ansehen und bei Bedarf das DB-Upgrade auslösen.";
                        }
                        break;
                    case 'do_db_upgrade':
                        $guidance_title = "Datenbank aktualisiert";
                        $guidance_message = "DB-Upgrade ausgeführt. Prüfe das Protokoll und teste Funktionen.";
                        break;
                }

                if (($submit === 'check_upgrade' || $submit === 'check_db') && $obsolete_count !== null && $obsolete_count > 0) {
                    $guidance_message .= " Hinweis: ".$obsolete_count." Einträge existieren nur in der Datenbank (nicht in JSON). Das blockiert kein Upgrade.";
                }

            } elseif ($result_code === 1) {
                // Returncode 1: durchgelaufen, aber mit Warnungen (DB-
                // Statement-Fehler, Restdifferenzen nach dem Upgrade oder
                // fehlgeschlagener Verifikations-Reload). Kein Erfolgsbanner.
                $status_headline = "Mit Warnungen abgeschlossen";
                $status_level = "warning";
                $warnings_detail = [];
                if ($db_error_count !== null && $db_error_count > 0) {
                    $warnings_detail[] = $db_error_count." fehlgeschlagene DB-Statements";
                }
                if ($remaining_count !== null && $remaining_count > 0) {
                    $warnings_detail[] = $remaining_count." Restdifferenzen nach dem Upgrade";
                }
                $status_message = "Der Durchlauf wurde mit Warnungen abgeschlossen"
                    .(!empty($warnings_detail) ? ": ".implode(", ", $warnings_detail)."." : ". Details siehe Protokoll.");
                $guidance_title = "Protokoll auswerten";
                $guidance_message = "\"Database upgrade errors: N\" zeigt fehlgeschlagene DB-Statements, \"N differences remaining after upgrade.\" zeigt verbleibende Schema-Differenzen. Ursachen beheben und das Upgrade ggf. erneut starten.";
                if ($remaining_count !== null && $remaining_count > 0) {
                    $guidance_message .= " Upgrade unvollständig: ".$remaining_count." Restdifferenzen prüfen.";
                }
            } elseif ($result_code === -1) {
                $status_headline = "Fehlgeschlagen";
                $status_level = "error";
                if ($engine_exception !== null) {
                    $status_message = "Unerwarteter Fehler im Upgrade-Lauf: ".$this->esc($engine_exception);
                } else {
                    $status_message = "Upgrade hat Fehler gemeldet. Protokoll prüfen.";
                }
                $guidance_title = "Fehlerbehebung";
                $guidance_message = "Siehe Protokoll, bereinige Fehler (z.B. lokale Änderungen, Verbindungsprobleme) und starte erneut.";
            }
        }

        if ($highlight_force && $result_code === -1) {
            $status_level = "warning";
            $status_headline = "Lokale Dateien verändert";
            $status_message = "Es gibt lokale Änderungen im Repo. Bitte 'Erzwingen (-f)' aktivieren oder Änderungen bereinigen.";
            $guidance_title = "Hinweis";
            $guidance_message = "Aktiviere unten 'Erzwingen (-f)' und starte das Upgrade erneut (oder setze die Änderungen zurück).";
        }

        // DB-Pill nur nach einem DB-Submit in DIESEM Request bewerten —
        // sonst bliebe ein veralteter Zustand als "geprüft" stehen.
        $step_db_pill_class = "pill-info";
        $step_db_pill_text = "nicht geprüft";
        if ($engine_ran && ($submit === 'check_db' || $submit === 'do_db_upgrade')) {
            $db_diff = ($submit === 'check_db') ? $diff_count : $remaining_count;
            if (($result_code === 0 || $result_code === 1) && $db_diff !== null) {
                if ($db_diff === 0) {
                    $step_db_pill_class = "pill-success";
                    $step_db_pill_text = "aktuell";
                } else {
                    $step_db_pill_class = "pill-warning";
                    $step_db_pill_text = $db_diff." Differenzen";
                }
            } elseif ($result_code === -1) {
                $step_db_pill_class = "pill-warning";
                $step_db_pill_text = "Prüfung fehlgeschlagen";
            }
        }

        if ($result !== "") {
            // filemtime mit file_exists absichern: ein paralleler Request
            // kann das Logfile zwischen Lesen und mtime gelöscht haben.
            $last_run = file_exists($logfile) ? date('d.m.Y H:i', filemtime($logfile)) : "";
        } else {
            $result = "Noch kein Protokoll vorhanden.";
            $last_run = "Noch kein Durchlauf";
        }

        $this->app->Tpl->Set('STATUS_HEADLINE', $status_headline);
        $this->app->Tpl->Set('STATUS_LEVEL', $status_level);
        $this->app->Tpl->Set('STATUS_MESSAGE', $status_message);
        $this->app->Tpl->Set('GUIDANCE_TITLE', $guidance_title);
        $this->app->Tpl->Set('GUIDANCE_MESSAGE', $guidance_message);
        $this->app->Tpl->Set('LAST_ACTION', $last_action);
        $this->app->Tpl->Set('LAST_RUN', $last_run);
        $this->app->Tpl->Set('UPGRADE_BUTTON_ACTION', $upgrade_available ? "do_upgrade" : "check_upgrade");
        $this->app->Tpl->Set('UPGRADE_BUTTON_LABEL', $upgrade_available ? "Upgrade starten" : "Upgrades prüfen");
        // Die Force-Checkbox wird komplett als Platzhalter gerendert: ist
        // sie versteckt, existiert das input-Element gar nicht und der
        // Browser kann kein verstecktes, aber gechecktes "erzwingen"
        // mitsenden. UPGRADE_FORCE_VISIBLE steuert weiterhin den Wrapper
        // (Highlight-Styling), FORCE_CHECKBOX das eigentliche Element.
        $force_visible = ($upgrade_available || $highlight_force);
        $this->app->Tpl->Set('UPGRADE_FORCE_VISIBLE', $force_visible ? "" : "hidden");
        $this->app->Tpl->Set('FORCE_HIGHLIGHT_CLASS', $highlight_force ? "force-highlight" : "");
        $this->app->Tpl->Set('FORCE_CHECKBOX', $force_visible
            ? '<input type="checkbox" name="erzwingen" value="1"'.($force ? ' checked' : '').'>'
            : '');
        $this->app->Tpl->Set('UPGRADE_DB_BUTTON_ACTION', $upgrade_db_available ? "do_db_upgrade" : "check_db");
        $this->app->Tpl->Set('UPGRADE_DB_BUTTON_LABEL', $upgrade_db_available ? "DB-Upgrade" : "DB prüfen");

        $this->app->Tpl->Set('REMOTE_HOST', $this->esc($remote_host));
        $this->app->Tpl->Set('REMOTE_BRANCH', $this->esc($remote_branch));
        $this->app->Tpl->Set('CURRENTBRANCH', $this->esc($current_branch));
        $this->app->Tpl->Set('BRANCHES', $branches_html !== "" ? $branches_html : '<p class="hint">Keine Zweige in remote.json hinterlegt.</p>');
        $this->app->Tpl->Set('UPDATE_STATUS', $update_status_text);
        $this->app->Tpl->Set('UPDATE_STATUS_CLASS', $update_status_class);
        $this->app->Tpl->Set('LOCAL_HASH_SHORT', $this->esc($local_hash_short));
        $this->app->Tpl->Set('REMOTE_HASH_SHORT', $this->esc($remote_hash_short));
        $this->app->Tpl->Set('LOCAL_COMMIT', $this->esc($git_commit));
        $this->app->Tpl->Set('LOCAL_BRANCH', $this->esc($git_branch));
        // Lokalen Branch nur anzeigen, wenn er vom Remote-Branch ABWEICHT —
        // genau das ist der warnenswerte Fall (inkl. detached HEAD, der
        // oben als "detached @ <hash>" formatiert wird).
        $show_local_branch = ($git_branch !== "" && $remote_branch !== "" && $git_branch !== $remote_branch);
        $this->app->Tpl->Set('LOCAL_BRANCH_VISIBLE', $show_local_branch ? "" : "hidden");
        $this->app->Tpl->Set('STEP_FILES_PILL_CLASS', $step_files_pill_class);
        $this->app->Tpl->Set('STEP_FILES_PILL_TEXT', $step_files_pill_text);
        $this->app->Tpl->Set('STEP_DB_PILL_CLASS', $step_db_pill_class);
        $this->app->Tpl->Set('STEP_DB_PILL_TEXT', $step_db_pill_text);

        // Rollback-Tags laden. Der OpenXE-Template-Parser kennt nur
        // [VARIABLE]-Platzhalter (kein Smarty-foreach), daher wird das
        // <select>-Markup hier gebaut und als String an die Vorlage
        // übergeben. Inline-Styles werden vermieden — siehe CSS-Klasse
        // .rollback-tag-select.
        $rollback_tags = [];
        $rollback_tags_html = "";
        if ($git_root !== "") {
            // Konsistent mit createRollbackTag(): exec() + Exit-Code statt
            // shell_exec()->null-vs-false-Ambiguität.
            $tag_list_cmd = 'git -C '.escapeshellarg($git_root).' tag -l "pre-upgrade-*" --sort=-creatordate 2>&1';
            $tags_output = [];
            $tag_list_exit_code = 0;
            exec($tag_list_cmd, $tags_output, $tag_list_exit_code);
            if ($tag_list_exit_code === 0 && !empty($tags_output)) {
                $tags = array_filter(array_map('trim', $tags_output));
                $rollback_tags = array_slice($tags, 0, 10); // Nur letzte 10 Tags

                if (!empty($rollback_tags)) {
                    $rollback_tags_html .= '<select name="rollback_tag" class="input-inline rollback-tag-select">';
                    foreach ($rollback_tags as $tag) {
                        $escaped = $this->esc($tag);
                        $rollback_tags_html .= '<option value="'.$escaped.'">'.$escaped.'</option>';
                    }
                    $rollback_tags_html .= '</select>';
                }
            }
        }

        $has_rollback_tags = !empty($rollback_tags);
        $this->app->Tpl->Set('ROLLBACK_TAGS_SELECT', $rollback_tags_html);
        $this->app->Tpl->Set('ROLLBACK_VISIBLE', $has_rollback_tags ? "" : "hidden");

        $revision_raw = (string)$this->app->erp->Revision();
        $app_version = trim((string)preg_replace('/\\s*\\([^)]*\\)\\s*$/', '', $revision_raw));
        if ($app_version === '') {
            $app_version = $revision_raw;
        }
        $this->app->Tpl->Set('APP_VERSION', $this->esc($app_version));
        // Log-Output wird escaped als reiner Text ausgegeben. Die
        // log-box-CSS-Regel `white-space:pre-wrap` sorgt für die
        // Zeilenumbrüche — kein nl2br nötig, damit auch das JS-Filter
        // (textContent-basiert) konsistent bleibt.
        $this->app->Tpl->Set('OUTPUT_FROM_CLI', $this->esc($result));

        if ($lock_handle !== null) {
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
        }

        $this->app->Tpl->Parse('PAGE', "upgrade.tpl");
    }


}
