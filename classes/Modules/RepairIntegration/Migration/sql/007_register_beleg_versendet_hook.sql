-- Upgrade 1.4.0 -> 1.5.0
-- Registriert den Core-Hook DokumentMaskVersendet(typ, id) auf
-- Repairintegration::RepairOnBelegVersendet. Der Core feuert ihn nach dem
-- Versand eines Belegs aus der Belegmaske (E-Mail, Druck, Fax). Das Modul
-- zieht dann Belegnummer, KVA-Betrag bzw. Ist-Kosten und den Ticket-Status
-- nach (Angebot -> quote_sent, Auftrag -> approved, Rechnung -> repaired).
--
-- Idempotenz: hook-Zeile nur anlegen, wenn sie fehlt (der Core legt sie
-- sonst beim ersten RunHook selbst an), hook_register nur ohne bestehende
-- Registrierung. Wird auch bei Neuinstallationen ausgefuehrt.
--
-- Hinweis: keine Semikolons in Kommentaren, executeSqlFile() splittet naiv
-- am Semikolon und wuerde sonst ein leeres Statement absetzen.

INSERT INTO `hook` (`name`, `alias`, `aktiv`, `parametercount`, `description`)
SELECT 'DokumentMaskVersendet', '', 1, 2, 'Nach Versand eines Belegs aus der Belegmaske (typ, id)'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `hook` WHERE `name` = 'DokumentMaskVersendet');

UPDATE `hook` SET `aktiv` = 1 WHERE `name` = 'DokumentMaskVersendet' AND `aktiv` = 0;

INSERT INTO `hook_register` (`hook_action`, `function`, `aktiv`, `position`, `hook`, `module`, `module_parameter`)
SELECT 0, 'RepairOnBelegVersendet', 1, 0, h.`id`, 'repairintegration', 0
FROM `hook` h
WHERE h.`name` = 'DokumentMaskVersendet'
  AND NOT EXISTS (
    SELECT 1 FROM `hook_register` hr
    WHERE hr.`hook` = h.`id`
      AND hr.`module` = 'repairintegration'
      AND hr.`function` = 'RepairOnBelegVersendet'
  );
