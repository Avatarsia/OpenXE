-- Upgrade 1.1.0 -> 1.2.0
-- Der Menuepunkt "Reparatur" kommt jetzt hardcoded aus erpapi->Navigation().
-- Die von install.php frueher angelegten hook_navigation-Zeilen unter
-- "Verwaltung" wuerden sonst doppelt erscheinen.
--
-- Idempotenz: DELETE ohne Vorbedingung, auf frischen Installationen ein No-Op
-- (dort wurden nie Zeilen fuer dieses Modul angelegt).
--
-- Hinweis: keine Semikolons in Kommentaren, executeSqlFile() splittet naiv
-- am Semikolon und wuerde sonst ein leeres Statement absetzen.

DELETE FROM `hook_navigation` WHERE `module` = 'repairintegration';
