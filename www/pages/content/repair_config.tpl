<div id="tabs">
    <ul>
        <li><a href="#tabs-1">Einstellungen</a></li>
        <li><a href="#tabs-2">WordPress-Verbindung</a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form method="post">
            <table class="mkTableFormular">
                <tr><td colspan="2"><h2>WordPress-Integration</h2></td></tr>
                <tr>
                    <td width="200">Aktiviert:</td>
                    <td><input type="checkbox" name="enabled" value="1"[ENABLED]></td>
                </tr>
                <tr>
                    <td>WP API-URL:</td>
                    <td><input type="text" name="wp_api_url" value="[WP_API_URL]" size="60" placeholder="https://example.com"></td>
                </tr>
                <tr>
                    <td>WP API-Key (Bearer):</td>
                    <td><input type="password" name="wp_api_key" value="[WP_API_KEY]" size="60"></td>
                </tr>
                <tr>
                    <td>Inbound Shared Secret:</td>
                    <td><input type="password" name="inbound_shared_secret" value="[INBOUND_SHARED_SECRET]" size="60"></td>
                </tr>
                <tr><td colspan="2"><h2>Sync</h2></td></tr>
                <tr>
                    <td>Max Retries:</td>
                    <td><input type="text" name="max_retries" value="[MAX_RETRIES]" size="5"></td>
                </tr>
                <tr>
                    <td>Fehler-Benachrichtigung an:</td>
                    <td><input type="text" name="notify_on_permanent_fail" value="[NOTIFY_EMAIL]" size="40" placeholder="admin@example.com"></td>
                </tr>
                <tr><td colspan="2"><h2>DSGVO</h2></td></tr>
                <tr>
                    <td>Anonymisierung nach (Jahre):</td>
                    <td><input type="text" name="retention_anonymize_years" value="[RETENTION_YEARS]" size="5"></td>
                </tr>
                <tr>
                    <td colspan="2">
                        <input type="submit" name="submit" value="save" class="btnGreen">
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <div id="tabs-2">
        [MESSAGE]
        <table class="mkTableFormular">
            <tr><td colspan="2"><h2>Verbindungsdaten fuer das WordPress-Plugin</h2></td></tr>
            <tr>
                <td width="220">OpenXE Endpoint-URL:</td>
                <td>
                    <input type="text" id="repairEndpointUrl" value="[ENDPOINT_URL]" size="60" readonly>
                    <button type="button" class="repairCopyBtn" data-target="repairEndpointUrl">Kopieren</button>
                    <div class="repairHint">Im WP-Plugin als OpenXE-Endpoint eintragen.</div>
                </td>
            </tr>
            <tr>
                <td>Inbound Shared Secret:</td>
                <td>
                    <input type="password" id="repairInboundSecret" value="[INBOUND_SHARED_SECRET]" size="60" readonly>
                    <button type="button" class="repairToggleBtn" data-target="repairInboundSecret">Anzeigen</button>
                    <button type="button" class="repairCopyBtn" data-target="repairInboundSecret">Kopieren</button>
                    <form method="post" class="repairGenForm">
                        <input type="hidden" name="submit" value="generate_inbound_secret">
                        <button type="submit" class="repairGenBtn"[GEN_INBOUND_CONFIRM]>[GEN_INBOUND_LABEL]</button>
                    </form>
                    <div class="repairHint">Im WP-Plugin als Bearer-Token eintragen.</div>
                </td>
            </tr>
            <tr>
                <td>WP API-Key (Outbound):</td>
                <td>
                    <input type="password" id="repairWpApiKey" value="[WP_API_KEY]" size="60" readonly>
                    <button type="button" class="repairToggleBtn" data-target="repairWpApiKey">Anzeigen</button>
                    <button type="button" class="repairCopyBtn" data-target="repairWpApiKey">Kopieren</button>
                    <form method="post" class="repairGenForm">
                        <input type="hidden" name="submit" value="generate_wp_api_key">
                        <button type="submit" class="repairGenBtn"[GEN_WPKEY_CONFIRM]>[GEN_WPKEY_LABEL]</button>
                    </form>
                    <div class="repairHint">Im WP-Plugin als API-Key fuer eingehende Status-Updates eintragen.</div>
                </td>
            </tr>
            <tr>
                <td>Verbindungstest:</td>
                <td>
                    <form method="post" action="index.php?module=repairintegration&amp;action=einstellungen#tabs-2" class="repairGenForm">
                        <input type="hidden" name="submit" value="test_wp_connection">
                        <button type="submit" class="repairGenBtn">Verbindung zu WordPress testen</button>
                    </form>
                    <div class="repairHint">POST auf /wp-json/p3d/v1/ping mit dem WP API-Key, erfordert Plugin v3.30.0+. Aeltere Versionen antworten mit HTTP 404.</div>
                </td>
            </tr>
        </table>
    </div>
</div>
