<div id="repair-details-panel" style="display:[REPAIR_TAB_DISPLAY]; margin-top:15px; padding:10px; border:1px solid #ddd; background:#f9f9f9;">
    [REPAIR_MESSAGE]
    <h3>Reparatur-Details</h3>
    <table class="mkTableFormular" style="width:100%">
        <tr>
            <td width="150"><strong>Service-Typ:</strong></td>
            <td>[REPAIR_SERVICE_TYPE]</td>
            <td width="150"><strong>Hersteller:</strong></td>
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
            <td colspan="4"><strong>Fehlerbeschreibung:</strong><br>[REPAIR_ISSUE_DESC]</td>
        </tr>
    </table>

    <h3 style="margin-top:15px;">Diagnose &amp; Kosten (intern)</h3>
    <table class="mkTableFormular" style="width:100%">
        <tr>
            <td width="150">Diagnose-Ergebnis:</td>
            <td><textarea name="repair_diagnosis_result" rows="3" style="width:100%">[REPAIR_DIAGNOSIS]</textarea></td>
        </tr>
        <tr>
            <td>KV-Betrag (EUR):</td>
            <td><input type="text" name="repair_quote_amount" value="[REPAIR_QUOTE]" size="10"></td>
        </tr>
        <tr>
            <td>Tats. Kosten (EUR):</td>
            <td><input type="text" name="repair_actual_cost" value="[REPAIR_ACTUAL_COST]" size="10"></td>
        </tr>
        <tr>
            <td>KVA-Preis Kunde (EUR):</td>
            <td>[REPAIR_CUSTOMER_QUOTE]</td>
        </tr>
        <tr>
            <td>Reparatur-Notizen:</td>
            <td><textarea name="repair_notes" rows="3" style="width:100%">[REPAIR_NOTES]</textarea></td>
        </tr>
    </table>

    <h3 style="margin-top:15px;">Kundenkonto</h3>
    <table class="mkTableFormular" style="width:100%">
        <tr>
            <td width="150">Adresse:</td>
            <td>[REPAIR_ADRESSE_BLOCK]</td>
        </tr>
    </table>

    <h3 style="margin-top:15px;">Belege erstellen</h3>
    <div style="margin-bottom:5px;">[REPAIR_BELEG_BUTTONS]</div>

    <h3 style="margin-top:15px;">Verknuepfte Belege</h3>
    <table class="mkTable" style="width:100%">
        <thead><tr><th>Typ</th><th>Nr.</th><th>Datum</th></tr></thead>
        <tbody>[REPAIR_BELEGE_ROWS]</tbody>
    </table>
</div>
