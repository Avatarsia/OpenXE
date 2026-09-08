<div id="repair-details-panel" style="display:[REPAIR_TAB_DISPLAY]; margin-top:15px;">
    [REPAIR_MESSAGE]
    <div class="row">
        <div class="row-height">
            <div class="col-xs-14 col-md-6 col-md-height">
                <div class="inside inside-full-height">
                    <fieldset>
                        <legend>Reparatur-Details</legend>
                        <table width="100%" border="0" class="mkTableFormular">
                            <tr>
                                <td width="130"><strong>Service-Typ:</strong></td>
                                <td>[REPAIR_SERVICE_TYPE]</td>
                                <td width="130"><strong>Hersteller:</strong></td>
                                <td>[REPAIR_MANUFACTURER]</td>
                            </tr>
                            <tr>
                                <td><strong>Modell:</strong></td>
                                <td>[REPAIR_MODEL]</td>
                                <td><strong>Seriennummer:</strong></td>
                                <td>[REPAIR_SERIAL]</td>
                            </tr>
                            <tr>
                                <td><strong>Fehlerkategorie:</strong></td>
                                <td>[REPAIR_ISSUE_CAT]</td>
                                <td><strong>Garantie:</strong></td>
                                <td>[REPAIR_WARRANTY]</td>
                            </tr>
                            <tr>
                                <td><strong>Kostenrahmen:</strong></td>
                                <td>[REPAIR_COST_LIMIT]</td>
                                <td><strong>Express:</strong></td>
                                <td>[REPAIR_EXPRESS]</td>
                            </tr>
                            <tr>
                                <td><strong>Fehler&shy;beschreibung:</strong></td>
                                <td colspan="3"><div class="issue-box">[REPAIR_ISSUE_DESC]</div></td>
                            </tr>
                        </table>
                    </fieldset>
                </div>
            </div>
            <div class="col-xs-14 col-md-6 col-md-height">
                <div class="inside inside-full-height">
                    <fieldset>
                        <legend>Diagnose &amp; Kosten (intern)</legend>
                        <table width="100%" border="0" class="mkTableFormular">
                            <tr>
                                <td><strong>KV-Betrag:</strong></td>
                                <td nowrap><input type="text" name="repair_quote_amount" value="[REPAIR_QUOTE]" size="8"> &euro;</td>
                                <td><strong>Tats. Kosten:</strong></td>
                                <td nowrap><input type="text" name="repair_actual_cost" value="[REPAIR_ACTUAL_COST]" size="8"> &euro;</td>
                                <td><strong>KVA-Preis Kunde:</strong></td>
                                <td><span class="kva-kunde">[REPAIR_CUSTOMER_QUOTE]</span></td>
                            </tr>
                            <tr>
                                <td colspan="6" class="repairHint">KVA-Preis Kunde wird vom Kunden im WP-Portal freigegeben (read-only)</td>
                            </tr>
                            <tr>
                                <td><strong>Diagnose-Ergebnis:</strong></td>
                                <td colspan="5"><textarea name="repair_diagnosis_result" rows="3" style="width:100%">[REPAIR_DIAGNOSIS]</textarea></td>
                            </tr>
                            <tr>
                                <td><strong>Reparatur-Notizen:</strong></td>
                                <td colspan="5"><textarea name="repair_notes" rows="2" style="width:100%">[REPAIR_NOTES]</textarea></td>
                            </tr>
                        </table>
                    </fieldset>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="row-height">
            <div class="col-xs-14 col-md-6 col-md-height">
                <div class="inside inside-full-height">
                    <fieldset>
                        <legend>Kundenkonto &amp; Belege</legend>
                        <table width="100%" border="0" class="mkTableFormular">
                            <tr>
                                <td width="130"><strong>Adresse:</strong></td>
                                <td>[REPAIR_ADRESSE_BLOCK]</td>
                            </tr>
                            <tr>
                                <td><strong>Beleg anlegen:</strong></td>
                                <td>[REPAIR_BELEG_BUTTONS]</td>
                            </tr>
                        </table>
                    </fieldset>
                </div>
            </div>
            <div class="col-xs-14 col-md-6 col-md-height">
                <div class="inside inside-full-height">
                    <fieldset>
                        <legend>Verkn&uuml;pfte Belege</legend>
                        <table class="mkTable" width="100%">
                            <thead><tr><th>Typ</th><th>Nr.</th><th>Datum</th></tr></thead>
                            <tbody>[REPAIR_BELEGE_ROWS]</tbody>
                        </table>
                    </fieldset>
                </div>
            </div>
        </div>
    </div>
</div>
