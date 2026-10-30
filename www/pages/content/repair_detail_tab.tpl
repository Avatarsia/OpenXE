<div id="repair-details-panel" style="display:[REPAIR_TAB_DISPLAY]; margin-top:15px;">
    [REPAIR_MESSAGE]
    <input type="hidden" name="repair_csrf" value="[REPAIR_CSRF]">
    <div class="row"><div class="row-height">
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Reparatur-Details &middot; Formularangaben</legend>
            <table width="100%" class="mkTableFormular">[REPAIR_FORM_ROWS]</table>
            <div class="repair-form-price">[REPAIR_FORM_PRICE]</div>
            <div class="repair-customer-description"><strong>[REPAIR_DESCRIPTION_LABEL]</strong><div class="issue-box">[REPAIR_DESCRIPTION]</div></div>
        </fieldset></div></div>
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Bearbeitung (intern)</legend>
            <table width="100%" class="mkTableFormular">
                <tr><td><label for="repair_diagnosis_result">Diagnose-Notiz:</label></td><td><textarea id="repair_diagnosis_result" name="repair_diagnosis_result" rows="3">[REPAIR_DIAGNOSIS]</textarea></td></tr>
                <tr><td><label for="repair_notes">Interne Notizen:</label></td><td><textarea id="repair_notes" name="repair_notes" rows="3">[REPAIR_NOTES]</textarea></td></tr>
            </table>
            <p class="repairHint">Interne Notizen werden nicht in den Produkttext &uuml;bernommen.</p>
        </fieldset></div></div>
    </div></div>
    <div class="row"><div class="row-height">
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Leistungsdaten f&uuml;r den Produkttext</legend>
            <table width="100%" class="mkTableFormular">
                <tr><td><label for="repair_public_diagnosis">Eingangsdiagnose:</label></td><td><textarea id="repair_public_diagnosis" name="repair_public_diagnosis" rows="6">[REPAIR_PUBLIC_DIAGNOSIS]</textarea></td></tr>
                <tr><td><label for="repair_service_work">Reparatur- /<br>Wartungsarbeiten:</label></td><td><textarea id="repair_service_work" name="repair_service_work" rows="5">[REPAIR_SERVICE_WORK]</textarea></td></tr>
                <tr><td><label for="repair_final_check">Endkontrolle:</label></td><td><textarea id="repair_final_check" name="repair_final_check" rows="5">[REPAIR_FINAL_CHECK]</textarea></td></tr>
                <tr><td><label for="repair_position_net_amount">Positionspreis netto (EUR):</label></td><td><input type="text" id="repair_position_net_amount" name="repair_position_net_amount" value="[REPAIR_POSITION_NET_AMOUNT]" size="12" inputmode="decimal"><div class="repairHint">Leer: hinterlegten Artikelpreis verwenden. Dieser Preis betrifft nur die Serviceposition.</div></td></tr>
            </table>
        </fieldset></div></div>
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Belegposition &middot; Produkttext</legend>
            <p class="repairHint">Vorschau f&uuml;r das Angebot. Vorlagen unter Reparaturen &gt; Einstellungen &gt; Artikel und Produkttexte.</p>
            <div class="repair-product-preview"><span>[REPAIR_SERVICE_ARTICLE_NUMBER]</span><div><strong>[REPAIR_SERVICE_ARTICLE_NAME]</strong><p id="repair_position_preview"></p></div></div>
            <p id="repair_position_warning" class="repair-position-warning" role="status"></p>
            <textarea id="repair_position_template" hidden>[REPAIR_POSITION_TEMPLATE]</textarea>
            <script type="application/json" id="repair_position_values">[REPAIR_POSITION_VALUES]</script>
        </fieldset></div></div>
    </div></div>
    <div class="row"><div class="row-height">
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Kundenkonto &amp; Belege</legend>
            <table width="100%" class="mkTableFormular"><tr><td>Adresse:</td><td>[REPAIR_ADRESSE_BLOCK]</td></tr><tr><td>Beleg anlegen:</td><td>[REPAIR_BELEG_BUTTONS]</td></tr></table>
            <p class="repairHint">Leistungsdaten werden bei der Belegerstellung gespeichert. Der normale OpenXE-Positionseditor &ouml;ffnet sich mit dem Serviceartikel. Dort k&ouml;nnen Sie Versand und weitere Artikel erg&auml;nzen.</p>
        </fieldset></div></div>
        <div class="col-xs-14 col-md-6 col-md-height"><div class="inside inside-full-height"><fieldset>
            <legend>Verkn&uuml;pfte Belege</legend>
            <table class="mkTable" width="100%"><thead><tr><th>Typ</th><th>Nr.</th><th>Datum</th></tr></thead><tbody>[REPAIR_BELEGE_ROWS]</tbody></table>
        </fieldset></div></div>
    </div></div>
</div>
