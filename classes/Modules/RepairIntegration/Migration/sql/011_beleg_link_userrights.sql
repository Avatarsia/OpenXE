-- Upgrade 1.8.0 -> 1.9.0
-- Die Actions linkbeleg, unlinkbeleg und belegtickets sind neu. Die
-- Session-ACL (phpwf/plugins/class.acl.php Check) laesst Nicht-Admins nur
-- auf Actions mit eigenem userrights-Eintrag zu. Nutzer, die im Modul
-- bereits list oder createbeleg duerfen, erhalten die drei Actions, damit
-- sie nicht mit NO_PERMISSIONS blockiert werden. Die fachliche Pruefung
-- (ticket edit, Beleg edit/list) bleibt in den Action-Methoden.
--
-- Idempotenz: nur Zeilen anlegen, die fuer den User noch fehlen. Eine
-- bewusst gesetzte Sperre (permission = 0) bleibt unangetastet.
-- Wird auch bei Neuinstallationen ausgefuehrt (dort meist No-Op).
--
-- Hinweis: keine Semikolons in Kommentaren, executeSqlFile() splittet naiv
-- am Semikolon und wuerde sonst ein leeres Statement absetzen.

INSERT INTO `userrights` (`user`, `module`, `action`, `permission`)
SELECT DISTINCT ur.`user`, 'repairintegration', 'linkbeleg', 1
FROM `userrights` ur
WHERE ur.`module` = 'repairintegration'
  AND ur.`action` IN ('list', 'createbeleg')
  AND ur.`permission` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `userrights` x
    WHERE x.`user` = ur.`user`
      AND x.`module` = 'repairintegration'
      AND x.`action` = 'linkbeleg'
  );

INSERT INTO `userrights` (`user`, `module`, `action`, `permission`)
SELECT DISTINCT ur.`user`, 'repairintegration', 'unlinkbeleg', 1
FROM `userrights` ur
WHERE ur.`module` = 'repairintegration'
  AND ur.`action` IN ('list', 'createbeleg')
  AND ur.`permission` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `userrights` x
    WHERE x.`user` = ur.`user`
      AND x.`module` = 'repairintegration'
      AND x.`action` = 'unlinkbeleg'
  );

INSERT INTO `userrights` (`user`, `module`, `action`, `permission`)
SELECT DISTINCT ur.`user`, 'repairintegration', 'belegtickets', 1
FROM `userrights` ur
WHERE ur.`module` = 'repairintegration'
  AND ur.`action` IN ('list', 'createbeleg')
  AND ur.`permission` = 1
  AND NOT EXISTS (
    SELECT 1 FROM `userrights` x
    WHERE x.`user` = ur.`user`
      AND x.`module` = 'repairintegration'
      AND x.`action` = 'belegtickets'
  );
