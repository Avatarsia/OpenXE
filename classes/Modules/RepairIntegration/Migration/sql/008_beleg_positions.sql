-- Native Belegweiterfuehrung behaelt die Reparaturverknuepfung
INSERT INTO hook (name, alias, aktiv, parametercount, description)
SELECT 'WeiterfuehrenAngebotZuAuftrag', '', 1, 2, 'Repair: Angebot zu Auftrag'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hook WHERE name = 'WeiterfuehrenAngebotZuAuftrag');
INSERT INTO hook (name, alias, aktiv, parametercount, description)
SELECT 'WeiterfuehrenAuftragZuRechnungPositionen', '', 1, 2, 'Repair: Auftrag zu Rechnung'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hook WHERE name = 'WeiterfuehrenAuftragZuRechnungPositionen');
UPDATE hook SET aktiv = 1 WHERE name IN ('WeiterfuehrenAngebotZuAuftrag', 'WeiterfuehrenAuftragZuRechnungPositionen');
INSERT INTO hook_register (hook_action, `function`, aktiv, position, hook, module, module_parameter)
SELECT 0, 'RepairOnAngebotZuAuftrag', 1, 0, h.id, 'repairintegration', 0
FROM hook h WHERE h.name = 'WeiterfuehrenAngebotZuAuftrag'
AND NOT EXISTS (SELECT 1 FROM hook_register hr WHERE hr.hook = h.id AND hr.module = 'repairintegration' AND hr.`function` = 'RepairOnAngebotZuAuftrag');
INSERT INTO hook_register (hook_action, `function`, aktiv, position, hook, module, module_parameter)
SELECT 0, 'RepairOnAuftragZuRechnung', 1, 0, h.id, 'repairintegration', 0
FROM hook h WHERE h.name = 'WeiterfuehrenAuftragZuRechnungPositionen'
AND NOT EXISTS (SELECT 1 FROM hook_register hr WHERE hr.hook = h.id AND hr.module = 'repairintegration' AND hr.`function` = 'RepairOnAuftragZuRechnung');
