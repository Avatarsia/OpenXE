<div id="tabs">
    <ul>
        <li><a href="#tabs-1">Einstellungen</a></li>
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
</div>
