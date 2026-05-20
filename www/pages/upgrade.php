<?php

/*
 * Copyright (c) 2022 OpenXE project
 */

use Xentral\Components\Database\Exception\QueryFailureException;

class upgrade {

    /**
     * Validation-Patterns für Remote-Eingabe.
     * Host: erlaubt Wort-Zeichen, @, Punkte, Doppelpunkt, Slash, Dash
     * (deckt SSH-URLs und HTTPS-URLs ab). `..` wird absichtlich nicht
     * separat blockiert — alle git-Aufrufe nutzen escapeshellarg(),
     * eine Path-Traversal über den Host-Wert ist daher nicht möglich.
     */
    const REMOTE_HOST_PATTERN = '/^[\\w@.:\\/-]+$/';
    const BRANCH_NAME_PATTERN = '/^[A-Za-z0-9._\\/-]+$/';
    const ROLLBACK_TAG_PATTERN = '/^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/';

    /**
     * Match-Strings aus der upstream Engine (upgrade/data/upgrade.php).
     * Bei Engine-Updates müssen diese mit den dort produzierten Outputs
     * abgeglichen werden.
     */
    const RESULT_ABORTED = 'Aborted';
    const RESULT_UP_TO_DATE = 'Already up to date';
    const RESULT_MODIFIED_FILES = 'There are modified files';
    const RESULT_NEEDS_FORCE = 'Clear modified files or use -f';

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
     * Erzeugt einen pre-upgrade-Tag im lokalen Git-Repo und entfernt
     * Tags jenseits der 10 jüngsten Einträge. Schlägt die Tag-Erstellung
     * fehl, wird das in der Session nicht vermerkt — der Aufrufer kann
     * sich dann nicht auf einen Rollback verlassen.
     */
    private function createRollbackTag(string $git_root): void
    {
        $tag_name = 'pre-upgrade-'.date('Y-m-d-H-i-s');
        $tag_cmd = 'git -C '.escapeshellarg($git_root).' tag '.escapeshellarg($tag_name).' 2>&1';

        $tag_output = [];
        $tag_exit_code = 0;
        exec($tag_cmd, $tag_output, $tag_exit_code);

        if ($tag_exit_code !== 0) {
            return;
        }

        $_SESSION['last_rollback_tag'] = $tag_name;

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
            $logfile = "../upgrade/data/upgrade.log";
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
        $details_post = $this->app->Secure->GetPOST('details_anzeigen');
        $db_details_post = $this->app->Secure->GetPOST('db_details_anzeigen');
        $verbose = $details_post === null ? true : $details_post === '1';
        $db_verbose = $db_details_post === null ? true : $db_details_post === '1';
        $force = $this->app->Secure->GetPOST('erzwingen') === '1';
        $remote_host_input = trim((string)$this->app->Secure->GetPOST('remote_host'));
        $remote_branch_input = trim((string)$this->app->Secure->GetPOST('remote_branch'));

      	$this->app->Tpl->Set('DETAILS_ANZEIGEN', $verbose?"checked":"");
      	$this->app->Tpl->Set('DB_DETAILS_ANZEIGEN', $db_verbose?"checked":"");
        $this->app->Tpl->Set('ERZWINGEN', $force?"checked":"");

        include("../upgrade/data/upgrade.php");

        $logfile = "../upgrade/data/upgrade.log";
        $remote_config_file = "../upgrade/data/remote.json";
        upgrade_set_out_file_name($logfile);

        $this->app->Tpl->Set('UPGRADE_VISIBLE', "hidden");
        $this->app->Tpl->Set('UPGRADE_DB_VISIBLE', "hidden");
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
        $remote_errors = array();
        $original_remote_host = "";
        $original_remote_branch = "";

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

        $git_branch = "";
        $git_commit = "";
        $local_hash = "";
        $local_hash_short = "";
        if ($git_root !== "") {
            $git_branch = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse --abbrev-ref HEAD'));
            $git_commit = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' log -1 --date=short --pretty="%cd"'));
            $local_hash = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse HEAD'));
            $local_hash_short = trim((string)@shell_exec('git -C '.escapeshellarg($git_root).' rev-parse --short=8 HEAD'));
        }

        $update_status_text = "Remote-Stand nicht geprüft.";
        $update_status_class = "pill-info";
        $remote_hash = "";
        $remote_hash_short = "";

        if (is_readable($remote_config_file)) {
            $remote_data_raw = file_get_contents($remote_config_file);
            $remote_data = json_decode($remote_data_raw, true) ?: array();
            $remote_host = $remote_data['host'] ?? "";
            $remote_branch = $remote_data['branch'] ?? "";
            $original_remote_host = $remote_data['original_host'] ?? "";
            $original_remote_branch = $remote_data['original_branch'] ?? "";
        } else {
            $status_headline = "Hinweis";
            $status_level = "warning";
            $status_message = "Konfiguration der Upgrade-Quelle konnte nicht geladen werden.";
        }

        if ($submit === 'save_remote') {
            if ($remote_host_input === '') {
                $remote_errors[] = "Git-Remote darf nicht leer sein.";
            }
            if ($remote_branch_input === '') {
                $remote_errors[] = "Branch darf nicht leer sein.";
            }
            if ($remote_host_input !== '' && !preg_match(self::REMOTE_HOST_PATTERN, $remote_host_input)) {
                $remote_errors[] = "Git-Remote enthält ungültige Zeichen.";
            }
            if ($remote_branch_input !== '' && !preg_match(self::BRANCH_NAME_PATTERN, $remote_branch_input)) {
                $remote_errors[] = "Branch enthält ungültige Zeichen.";
            }

            if (empty($remote_errors)) {
                $payload = json_encode(
                    array(
                        'host' => $remote_host_input,
                        'branch' => $remote_branch_input,
                        'original_host' => $remote_host_input,
                        'original_branch' => $remote_branch_input
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                );
                if (file_put_contents($remote_config_file, $payload) === false) {
                    $remote_errors[] = "Upgrade-Quelle konnte nicht gespeichert werden.";
                } else {
                    $remote_host = $remote_host_input;
                    $remote_branch = $remote_branch_input;
                    $original_remote_host = $remote_host_input;
                    $original_remote_branch = $remote_branch_input;
                    $status_headline = "Upgrade-Quelle gespeichert";
                    $status_level = "success";
                    $status_message = "Remote und Branch wurden übernommen.";
                }
            } else {
                $status_headline = "Eingabefehler";
                $status_level = "error";
                $status_message = implode(" ", $remote_errors);
            }
        } elseif ($submit === 'reset_remote_origin') {
            if ($original_remote_host === "" || $original_remote_branch === "") {
                $remote_errors[] = "Kein Original-Remote hinterlegt.";
            }
            if (empty($remote_errors)) {
                $payload = json_encode(
                    array(
                        'host' => $original_remote_host,
                        'branch' => $original_remote_branch,
                        'original_host' => $original_remote_host,
                        'original_branch' => $original_remote_branch
                    ),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                );
                if (file_put_contents($remote_config_file, $payload) === false) {
                    $remote_errors[] = "Upgrade-Quelle konnte nicht zurückgesetzt werden.";
                } else {
                    $remote_host = $original_remote_host;
                    $remote_branch = $original_remote_branch;
                    $status_headline = "Upgrade-Quelle zurückgesetzt";
                    $status_level = "info";
                    $status_message = "Remote/Branch auf Originalwerte gestellt.";
                }
            } else {
                $status_headline = "Eingabefehler";
                $status_level = "error";
                $status_message = implode(" ", $remote_errors);
            }
        }

        // Calculate version alignment (local vs. upgrade source)
        if ($git_root !== "" && $remote_host !== "" && $remote_branch !== "") {
            $remote_ref = "refs/heads/".$remote_branch;
            $remote_cmd = 'git -C '.escapeshellarg($git_root).' ls-remote '.escapeshellarg($remote_host).' '.escapeshellarg($remote_ref).' 2>&1';
            $remote_line = trim((string)shell_exec($remote_cmd));

            if ($remote_line !== "") {
                $remote_hash = trim(strtok($remote_line, "\t "));
                $remote_hash_short = substr($remote_hash, 0, 8);

                if ($local_hash === "") {
                    $update_status_text = "Lokaler Stand unbekannt";
                    $update_status_class = "pill-warning";
                } elseif ($local_hash === $remote_hash) {
                    $update_status_text = "Alles aktuell";
                    $update_status_class = "pill-success";
                } else {
                    $update_status_text = "Update verfügbar";
                    $update_status_class = "pill-warning";
                }
            } else {
                $update_status_text = "Remote nicht erreichbar";
                $update_status_class = "pill-warning";
            }
        }

        $this->app->Tpl->Set('REMOTE_HOST', htmlspecialchars($remote_host));
        $this->app->Tpl->Set('REMOTE_BRANCH', htmlspecialchars($remote_branch));
        $this->app->Tpl->Set('REMOTE_ORIGINAL_HOST', htmlspecialchars($original_remote_host));
        $this->app->Tpl->Set('REMOTE_ORIGINAL_BRANCH', htmlspecialchars($original_remote_branch));
        $this->app->Tpl->Set('UPDATE_STATUS', $update_status_text);
        $this->app->Tpl->Set('UPDATE_STATUS_CLASS', $update_status_class);
        $this->app->Tpl->Set('LOCAL_HASH', $local_hash);
        $this->app->Tpl->Set('LOCAL_HASH_SHORT', $local_hash_short);
        $this->app->Tpl->Set('REMOTE_HASH_SHORT', $remote_hash_short);
        $this->app->Tpl->Set('LOCAL_COMMIT', $git_commit);
        $this->app->Tpl->Set('LOCAL_BRANCH', $git_branch);
        $this->app->Tpl->Set('SHOW_SYNC_REMOTE', "hidden");
        $show_local_branch = ($git_branch !== "" && $remote_branch !== "" && $git_branch === $remote_branch);
        $this->app->Tpl->Set('LOCAL_BRANCH_VISIBLE', $show_local_branch ? "" : "hidden");

        $directory = dirname(getcwd())."/upgrade";
        $result_code = null;

        // Lookup-Tabelle für die Engine-Aktionen. Jede Action mapped auf
        // ein Set Flags, das nahezu 1:1 an upgrade_main() durchgereicht
        // wird. Andere Submits (refresh, save_remote, rollback_to_tag)
        // sind UI-only und werden separat behandelt.
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
                'label' => "Upgrade (Dateien & Datenbank)",
                'verbose' => $verbose,
                'check_git' => true,
                'do_git' => true,
                'check_db' => true,
                'do_db' => true,
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

        if (isset($actions[$submit])) {
            $cfg = $actions[$submit];
            $last_action = $cfg['label'];
            if ($cfg['sets_upgrade_visible']) {
                $this->app->Tpl->Set('UPGRADE_VISIBLE', "");
                $upgrade_available = true;
            }
            if ($cfg['sets_upgrade_db_visible']) {
                $this->app->Tpl->Set('UPGRADE_DB_VISIBLE', "");
                $upgrade_db_available = true;
            }
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
            if (($cfg['do_git'] || $cfg['do_db']) && $git_root !== "") {
                $this->createRollbackTag($git_root);
            }

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
                drop_keys: false
            );
        } elseif ($submit === 'refresh') {
            $last_action = "Anzeige aktualisiert";
        } elseif ($submit === 'save_remote') {
            $last_action = "Upgrade-Quelle speichern";
        } elseif ($submit === 'rollback_to_tag') {
            $last_action = "Rollback durchgeführt";
            $rollback_tag = $this->app->Secure->GetPOST('rollback_tag');

            if ($git_root !== "" && !empty($rollback_tag)) {
                // Validiere Tag-Name (nur pre-upgrade-* Tags erlauben)
                if (preg_match(self::ROLLBACK_TAG_PATTERN, $rollback_tag)) {
                    if (file_exists($logfile)) {
                        unlink($logfile);
                    }

                    // Checkout zum Tag
                    $checkout_cmd = 'git -C '.escapeshellarg($git_root).' checkout '.escapeshellarg($rollback_tag).' -f 2>&1';
                    $checkout_output = shell_exec($checkout_cmd);

                    file_put_contents($logfile, "=== Rollback to tag: $rollback_tag ===\n");
                    file_put_contents($logfile, $checkout_output, FILE_APPEND);

                    $status_headline = "Rollback durchgeführt";
                    $status_level = "info";
                    $status_message = "System auf Stand von Tag $rollback_tag zurückgesetzt.";
                    $guidance_title = "Wichtig";
                    $guidance_message = "Code wurde zurückgesetzt. DB-Änderungen wurden NICHT rückgängig gemacht!";
                } else {
                    $status_headline = "Ungültiger Tag";
                    $status_level = "error";
                    $status_message = "Nur pre-upgrade-* Tags können für Rollback verwendet werden.";
                }
            }
        }

        // Read results
        $result = file_exists($logfile) ? file_get_contents($logfile) : "";
        $highlight_force = (!$force && str_contains($result, self::RESULT_NEEDS_FORCE));

        if ($result_code === 0 && $result !== "") {
            if (str_contains($result, self::RESULT_ABORTED)) {
                $result_code = -1;
            }
        }

        if ($submit && $submit !== 'refresh' && $submit !== 'save_remote') {

            $diff_count = null;
            if (preg_match('/(\\d+) differences\\./', $result, $matches)) {
                $diff_count = (int)$matches[1];
            }

            $has_modified_files = str_contains($result, self::RESULT_MODIFIED_FILES);

            if ($result_code === 0) {
                $status_headline = "Aktion erfolgreich";
                $status_level = "success";
                if (str_contains($result, self::RESULT_UP_TO_DATE)) {
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
                        $guidance_title = "Upgrade abgeschlossen";
                        $guidance_message = "System und Datenbank wurden aktualisiert. Nächster Schritt: Funktionstest durchführen.";
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
                    default:
                        $guidance_title = "Ergebnis vorliegend";
                        $guidance_message = "Siehe Protokoll für Details.";
                }

            } elseif ($result_code === -1) {
                $status_headline = "Fehlgeschlagen";
                $status_level = "error";
                $status_message = "Upgrade hat Fehler gemeldet. Protokoll prüfen.";
                $guidance_title = "Fehlerbehebung";
                $guidance_message = "Siehe Protokoll, bereinige Fehler (z.B. lokale Änderungen, Verbindungsprobleme) und starte erneut.";
            } else {
                $status_headline = "Abgeschlossen";
                $status_level = "info";
                $status_message = "Ergebnis siehe Protokoll.";
                $guidance_title = "Protokoll prüfen";
                $guidance_message = "Bitte Protokoll ansehen und ggf. nächsten Schritt manuell wählen.";
            }
        }

        if ($highlight_force && $result_code === -1) {
            $status_level = "warning";
            $status_headline = "Lokale Dateien verändert";
            $status_message = "Es gibt lokale Änderungen im Repo. Bitte 'Erzwingen (-f)' aktivieren oder Änderungen bereinigen.";
            $guidance_title = "Hinweis";
            $guidance_message = "Aktiviere unten 'Erzwingen (-f)' und starte das Upgrade erneut (oder setze die Änderungen zurück).";
        }

        if ($result !== "") {
            $last_run = date('d.m.Y H:i', filemtime($logfile));
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
        $this->app->Tpl->Set('UPGRADE_FORCE_VISIBLE', ($upgrade_available || $highlight_force) ? "" : "hidden");
        $this->app->Tpl->Set('FORCE_HIGHLIGHT_CLASS', $highlight_force ? "force-highlight" : "");
        $this->app->Tpl->Set('UPGRADE_DB_BUTTON_ACTION', $upgrade_db_available ? "do_db_upgrade" : "check_db");
        $this->app->Tpl->Set('UPGRADE_DB_BUTTON_LABEL', $upgrade_db_available ? "DB-Upgrade" : "DB prüfen");
        $this->app->Tpl->Set('UPGRADE_DB_FORCE_VISIBLE', "hidden");

        // Rollback-Tags laden. Der OpenXE-Template-Parser kennt nur
        // [VARIABLE]-Platzhalter (kein Smarty-foreach), daher wird das
        // <select>-Markup hier gebaut und als String an die Vorlage
        // übergeben. Inline-Styles werden vermieden — siehe CSS-Klasse
        // .rollback-tag-select.
        $rollback_tags = [];
        $rollback_tags_html = "";
        if ($git_root !== "") {
            $tags_output = shell_exec('git -C '.escapeshellarg($git_root).' tag -l "pre-upgrade-*" --sort=-creatordate 2>&1');
            if ($tags_output !== null) {
                $tags = array_filter(explode("\n", trim($tags_output)));
                $rollback_tags = array_slice($tags, 0, 10); // Nur letzte 10 Tags

                if (!empty($rollback_tags)) {
                    $rollback_tags_html .= '<select name="rollback_tag" class="input-inline rollback-tag-select">';
                    foreach ($rollback_tags as $tag) {
                        $escaped = htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $rollback_tags_html .= '<option value="'.$escaped.'">'.$escaped.'</option>';
                    }
                    $rollback_tags_html .= '</select>';
                }
            }
        }

        $has_rollback_tags = !empty($rollback_tags);
        $this->app->Tpl->Set('ROLLBACK_TAGS_SELECT', $rollback_tags_html);
        $this->app->Tpl->Set('ROLLBACK_VISIBLE', $has_rollback_tags ? "" : "hidden");

        $this->app->Tpl->Set('CURRENT', $this->app->erp->Revision());
        $revision_raw = (string)$this->app->erp->Revision();
        $app_version = trim((string)preg_replace('/\\s*\\([^)]*\\)\\s*$/', '', $revision_raw));
        if ($app_version === '') {
            $app_version = $revision_raw;
        }
        $this->app->Tpl->Set('APP_VERSION', htmlspecialchars($app_version));
        $this->app->Tpl->Set('OUTPUT_FROM_CLI',nl2br($result));
        $this->app->Tpl->Parse('PAGE', "upgrade.tpl");
    }


}
