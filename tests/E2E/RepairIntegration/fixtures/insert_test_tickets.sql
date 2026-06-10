-- Test-Ticket 1: Reparatur (Bambu Lab X1C) - Status: neu
INSERT INTO ticket (schluessel, zeit, projekt, bearbeiter, quelle, status, adresse, kunde, warteschlange, mailadresse, prio, betreff, zugewiesen, inbearbeitung, inbearbeitung_user, firma, notiz, kommentar, tags, nachrichten_anz)
VALUES ('202604060001', NOW(), '', 'admin', 'formular', 'neu', 0, 'Max Mustermann', 'Werkstatt', 'max@example.com', 3, '[REP] Reparaturanfrage Ticket #202604060001 - Bambu Lab X1C', 0, 0, '', 1, '', '', 'reparatur,bambu-lab', 1);
SET @tid1 = LAST_INSERT_ID();
INSERT INTO ticket_nachricht (ticket, verfasser, mail, zeit, text, betreff, medium, status, bemerkung, versendet, bearbeiter, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060001', 'Max Mustermann', 'max@example.com', NOW(), '<p>Druckqualitaet verschlechtert nach 500h, Layer-Shifting bei hohen Geschwindigkeiten.</p>', '[REP] Reparaturanfrage Ticket #202604060001 - Bambu Lab X1C', 'email', 'neu', '', '', '', '', '', '');
INSERT INTO ticket_repair_details (ticket_id, ticket_schluessel, wp_request_number, service_type, service_delivery_type, manufacturer, model, serial_number, issue_category, issue_description, warranty_status, cost_limit, is_express)
VALUES (@tid1, '202604060001', '202604060001', 'reparatur', 'einsendung', 'Bambu Lab', 'X1 Carbon', 'BL-2024-123456', 'mechanical', 'Layer-Shifting bei hohen Geschwindigkeiten nach 500h Betrieb', 'no', '200', 0);

-- Test-Ticket 2: Wartung (Prusa MK4S) - Status: neu, Business-Kunde
INSERT INTO ticket (schluessel, zeit, projekt, bearbeiter, quelle, status, adresse, kunde, warteschlange, mailadresse, prio, betreff, zugewiesen, inbearbeitung, inbearbeitung_user, firma, notiz, kommentar, tags, nachrichten_anz)
VALUES ('202604060002', NOW(), '', 'admin', 'formular', 'neu', 0, 'TechPrint GmbH', 'Werkstatt', 't.schmidt@techprint.de', 3, '[WRT] Wartungsanfrage Ticket #202604060002 - Prusa MK4S', 0, 0, '', 1, '', '', 'wartung,prusa', 1);
SET @tid2 = LAST_INSERT_ID();
INSERT INTO ticket_nachricht (ticket, verfasser, mail, zeit, text, betreff, medium, status, bemerkung, versendet, bearbeiter, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060002', 'Thomas Schmidt', 't.schmidt@techprint.de', NOW(), '<p>Jaehrliche Standard-Wartung fuer Prusa MK4S. Bitte auch Firmware aktualisieren.</p>', '[WRT] Wartungsanfrage Ticket #202604060002 - Prusa MK4S', 'email', 'neu', '', '', '', '', '', '');
INSERT INTO ticket_repair_details (ticket_id, ticket_schluessel, wp_request_number, service_type, service_delivery_type, manufacturer, model, serial_number, mods_present, mods_text, wartung_paket, wartung_notes, warranty_status, cost_limit, is_express, customer_type, company_name, vat_id)
VALUES (@tid2, '202604060002', '202604060002', 'wartung', 'einsendung', 'Prusa', 'MK4S', 'CZPX-2024-007890', 1, 'Revo Hotend nachgeruestet', 'standard', 'Jaehrliche Inspektion, Firmware aktualisieren', 'unknown', 'no_limit', 0, 'business', 'TechPrint GmbH', 'DE123456789');

-- Test-Ticket 3: Reverse Engineering - Status: neu
INSERT INTO ticket (schluessel, zeit, projekt, bearbeiter, quelle, status, adresse, kunde, warteschlange, mailadresse, prio, betreff, zugewiesen, inbearbeitung, inbearbeitung_user, firma, notiz, kommentar, tags, nachrichten_anz)
VALUES ('202604060003', NOW(), '', 'admin', 'formular', 'neu', 0, 'Anna Weber', 'Werkstatt', 'a.weber@example.com', 3, '[REV] RE-Anfrage Ticket #202604060003 - Ersatzteil Zahnrad', 0, 0, '', 1, '', '', 'reverse-engineering', 1);
SET @tid3 = LAST_INSERT_ID();
INSERT INTO ticket_nachricht (ticket, verfasser, mail, zeit, text, betreff, medium, status, bemerkung, versendet, bearbeiter, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060003', 'Anna Weber', 'a.weber@example.com', NOW(), '<p>Benotige Ersatzteil (Zahnrad) fuer aeltere Maschine. Original nicht mehr erhaeltlich.</p>', '[REV] RE-Anfrage Ticket #202604060003 - Ersatzteil Zahnrad', 'email', 'neu', '', '', '', '', '', '');
INSERT INTO ticket_repair_details (ticket_id, ticket_schluessel, wp_request_number, service_type, service_delivery_type, manufacturer, model, has_original_part, has_templates, re_tolerance, re_output_format, issue_description, warranty_status, cost_limit, is_express)
VALUES (@tid3, '202604060003', '202604060003', 'reverse_engineering', 'einsendung', 'Unbekannt', 'Zahnrad Z42', 'yes', 'no', '0.05mm', 'STEP', 'Originalteil vorhanden, soll vermessen und nachgebaut werden', 'no', 'consult_always', 0);

-- Test-Ticket 4: Express-Reparatur (Ultimaker S5) - Status: in_diagnose, 3 Tage alt
INSERT INTO ticket (schluessel, zeit, projekt, bearbeiter, quelle, status, adresse, kunde, warteschlange, mailadresse, prio, betreff, zugewiesen, inbearbeitung, inbearbeitung_user, firma, notiz, kommentar, tags, nachrichten_anz)
VALUES ('202604060004', DATE_SUB(NOW(), INTERVAL 3 DAY), '', 'admin', 'formular', 'in_diagnose', 0, 'Prototypen AG', 'Werkstatt', 'service@prototypen.ag', 1, '[REP] EXPRESS Reparatur Ticket #202604060004 - Ultimaker S5', 0, 0, '', 1, '', '', 'reparatur,express,ultimaker', 2);
SET @tid4 = LAST_INSERT_ID();
INSERT INTO ticket_nachricht (ticket, verfasser, mail, zeit, text, betreff, medium, status, bemerkung, versendet, bearbeiter, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060004', 'Prototypen AG', 'service@prototypen.ag', DATE_SUB(NOW(), INTERVAL 3 DAY), '<p>Dringend! Ultimaker S5 druckt nicht mehr. Extruder blockiert komplett.</p>', '[REP] EXPRESS Reparatur Ticket #202604060004 - Ultimaker S5', 'email', 'abgeschlossen', '', '', '', '', '', '');
INSERT INTO ticket_nachricht (ticket, verfasser, bearbeiter, mail, zeit, text, betreff, medium, status, bemerkung, versendet, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060004', 'Werkstatt', 'admin', 'werkstatt@partner3d.de', NOW(), '<p>Geraet eingegangen. Diagnose: Heizblock defekt, PTFE-Tube verschmolzen.</p>', 'RE: EXPRESS Reparatur Ticket #202604060004', 'email', 'neu', '', '', '', '', '');
INSERT INTO ticket_repair_details (ticket_id, ticket_schluessel, wp_request_number, service_type, service_delivery_type, manufacturer, model, serial_number, issue_category, issue_description, warranty_status, cost_limit, is_express, express_price, diagnosis_result, customer_type, company_name)
VALUES (@tid4, '202604060004', '202604060004', 'reparatur', 'einsendung', 'Ultimaker', 'S5', 'UM-S5-2023-4421', 'extrusion', 'Extruder blockiert komplett', 'no', '500', 1, 50.00, 'Heizblock defekt, Thermistor OK, PTFE-Tube verschmolzen', 'business', 'Prototypen AG');

-- Test-Ticket 5: Individualisierung - Status: kv_gesendet, 7 Tage alt
INSERT INTO ticket (schluessel, zeit, projekt, bearbeiter, quelle, status, adresse, kunde, warteschlange, mailadresse, prio, betreff, zugewiesen, inbearbeitung, inbearbeitung_user, firma, notiz, kommentar, tags, nachrichten_anz)
VALUES ('202604060005', DATE_SUB(NOW(), INTERVAL 7 DAY), '', 'admin', 'formular', 'kv_gesendet', 0, 'Lisa Braun', 'Werkstatt', 'lisa.braun@example.com', 3, '[IND] Individualisierung Ticket #202604060005 - Drohnengehaeuse', 0, 0, '', 1, '', '', 'individualisierung,drohne', 1);
SET @tid5 = LAST_INSERT_ID();
INSERT INTO ticket_nachricht (ticket, verfasser, mail, zeit, text, betreff, medium, status, bemerkung, versendet, bearbeiter, mail_cc, verfasser_replyto, mail_replyto)
VALUES ('202604060005', 'Lisa Braun', 'lisa.braun@example.com', DATE_SUB(NOW(), INTERVAL 7 DAY), '<p>Individuelles Drohnengehaeuse, STL vorhanden, Carbon-PETG, Matt-Schwarz, IP67.</p>', '[IND] Individualisierung Ticket #202604060005 - Drohnengehaeuse', 'email', 'neu', '', '', '', '', '', '');
INSERT INTO ticket_repair_details (ticket_id, ticket_schluessel, wp_request_number, service_type, service_delivery_type, manufacturer, model, has_3d_file, material_preference, color_preference, functional_requirements, issue_description, warranty_status, cost_limit, is_express, quote_amount)
VALUES (@tid5, '202604060005', '202604060005', 'individualisierung', 'einsendung', 'Custom', 'Drohnengehaeuse V2', 'yes_stl', 'Carbon-PETG', 'Matt-Schwarz', 'Wasserdicht IP67, max 120g', 'Individuelles Drohnengehaeuse nach eigener 3D-Datei', 'no', '500', 0, 189.00);

SELECT '5 Test-Tickets erstellt' AS ergebnis;
SELECT id, schluessel, status, kunde, LEFT(betreff, 55) AS betreff FROM ticket WHERE schluessel LIKE '20260406%' ORDER BY schluessel;
SELECT ticket_id, service_type, manufacturer, model FROM ticket_repair_details WHERE ticket_schluessel LIKE '20260406%' ORDER BY ticket_id;
