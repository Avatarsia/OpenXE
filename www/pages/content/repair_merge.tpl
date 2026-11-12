<div id="tabs">
    <ul>
        <li><a href="#tabs-1">Tickets zusammenfuehren</a></li>
    </ul>
    <div id="tabs-1">
        [MESSAGE]
        <form method="post">
            <table class="mkTableFormular">
                <tr>
                    <td>Quell-Ticket (wird geschlossen):</td>
                    <td><input type="text" id="source_ticket" name="source" size="20" placeholder="Ticketnummer"></td>
                </tr>
                <tr>
                    <td>Ziel-Ticket (bleibt bestehen):</td>
                    <td><input type="text" id="target_ticket" name="target" size="20" placeholder="Ticketnummer"></td>
                </tr>
                <tr>
                    <td colspan="2">
                        <input type="hidden" name="confirm" value="1">
                        <input type="submit" value="Zusammenfuehren" class="btnRed" onclick="return confirm('Tickets wirklich zusammenfuehren? Diese Aktion kann nicht rueckgaengig gemacht werden.');">
                    </td>
                </tr>
            </table>
        </form>
    </div>
</div>
