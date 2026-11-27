<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;

/**
 * HTML fuer die Ticket-Beleg-Verknuepfung:
 * - Ticket-Detailseite: Belegkette (direkt + abgeleitet) und Zuordnung aus dem Kundenkonto
 * - Minidetail eines Belegs (Protokoll-Bereich): zugeordnete Reparaturtickets und Zuordnung
 *
 * Auf der Ticketseite liegt das Panel innerhalb des Ticket-<form>, daher dort
 * nur Buttons mit formaction (keine verschachtelten Formulare).
 */
final class RepairBelegLinkRenderer
{
    public const CSRF_KEY_BELEG = 'repair_link';

    public function __construct(
        private readonly RepairBelegGateway $gateway,
        private readonly RepairBelegChainResolver $resolver,
    ) {}

    private static function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private static function amount($value): string
    {
        return ($value === null || $value === '') ? '' : number_format((float)$value, 2, ',', '.') . ' EUR';
    }

    private static function date($value): string
    {
        $value = (string)$value;
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? $value : date('d.m.Y', $ts);
    }

    private static function belegLink(string $typ, int $id, string $text): string
    {
        return '<a href="index.php?module=' . self::h($typ) . '&amp;action=edit&amp;id=' . $id . '">' . self::h($text) . '</a>';
    }

    /**
     * Seeds fuer den Resolver aus den direkten Links eines Tickets.
     *
     * @return array{0: array, 1: array<string, array>} [Kettenknoten, direkte Links je Belegschluessel]
     */
    public function resolveForTicket(int $ticketId): array
    {
        $direct = [];
        foreach ($this->gateway->getByTicketId($ticketId) as $link) {
            $direct[RepairBelegChainResolver::key((string)$link['beleg_typ'], (int)$link['beleg_id'])] = $link;
        }
        $seeds = array_map(
            static fn(array $link): array => ['typ' => (string)$link['beleg_typ'], 'id' => (int)$link['beleg_id']],
            array_values($direct)
        );
        return [$this->resolver->resolve($seeds), $direct];
    }

    /** Tabellenzeilen "Verknuepfte Belege" auf der Ticketseite. */
    public function ticketChainRows(int $ticketId, array $nodes, array $direct, bool $canEdit): string
    {
        $unlinkAction = 'index.php?module=repairintegration&amp;action=unlinkbeleg&amp;ticket=' . $ticketId;
        $unlinkButton = static function (array $link) use ($unlinkAction, $canEdit): string {
            if (!$canEdit) {
                return '';
            }
            return '<button type="submit" class="button repair-link-btn" name="repair_unlink" value="' . (int)$link['id'] . '"'
                . ' formaction="' . $unlinkAction . '" formmethod="post"'
                . ' onclick="return confirm(\'Zuordnung wirklich entfernen?\');">Entfernen</button>';
        };

        $rows = '';
        $shown = [];
        foreach ($nodes as $node) {
            $key = (string)$node['key'];
            $shown[$key] = true;
            $link = $direct[$key] ?? null;
            $origin = $link !== null ? 'direkt' : '';
            if ((string)$node['origin_text'] !== '') {
                $origin .= ($origin !== '' ? ' &middot; ' : '') . self::h($node['origin_text']);
            }
            $depth = (int)$node['depth'];
            $prefix = $depth > 0 ? '<span class="repair-chain-branch">&#9492;</span> ' : '';
            $rows .= '<tr class="repair-chain-row' . ($link !== null ? ' repair-chain-direct' : '') . '">'
                . '<td style="padding-left:' . (4 + 18 * $depth) . 'px">' . $prefix . self::h(RepairBelegChainResolver::label((string)$node['typ'])) . '</td>'
                . '<td>' . self::belegLink((string)$node['typ'], (int)$node['id'], RepairBelegChainResolver::number($node)) . '</td>'
                . '<td>' . self::h($node['status']) . '</td>'
                . '<td>' . self::h(self::date($node['datum'])) . '</td>'
                . '<td class="repair-amount">' . self::h(self::amount($node['betrag'])) . '</td>'
                . '<td>' . $origin . '</td>'
                . '<td>' . ($link !== null ? $unlinkButton($link) : '') . '</td>'
                . '</tr>';
        }

        // Direkte Links ausserhalb der Kette: anderer Belegtyp oder geloeschter Beleg
        foreach ($direct as $key => $link) {
            if (isset($shown[$key])) {
                continue;
            }
            $typ = (string)$link['beleg_typ'];
            $exists = !RepairBelegChainResolver::isChainType($typ);
            $nr = trim((string)($link['beleg_nr'] ?? ''));
            $nr = $nr !== '' ? $nr : '#' . (int)$link['beleg_id'];
            $rows .= '<tr class="repair-chain-row repair-chain-direct">'
                . '<td>' . self::h(RepairBelegChainResolver::label($typ)) . '</td>'
                . '<td>' . ($exists ? self::belegLink($typ, (int)$link['beleg_id'], $nr) : self::h($nr)) . '</td>'
                . '<td>' . ($exists ? '' : '<em>Beleg nicht gefunden</em>') . '</td>'
                . '<td>' . self::h(self::date($link['created_at'] ?? '')) . '</td>'
                . '<td></td><td>direkt</td>'
                . '<td>' . $unlinkButton($link) . '</td>'
                . '</tr>';
        }

        return $rows !== '' ? $rows : '<tr><td colspan="7">Keine verkn&uuml;pften Belege.</td></tr>';
    }

    /**
     * Block "Beleg zuordnen" auf der Ticketseite: Belege des (ggf. abweichenden)
     * Kundenkontos, die noch nicht in der Kette stehen.
     */
    public function ticketCandidates(int $ticketId, int $ticketAdresseId, string $kontoInput, array $nodes, array $direct, bool $canEdit): string
    {
        [$adresse, $kontoValue, $error] = $this->resolveKonto($ticketAdresseId, $kontoInput);

        $html = '<div class="repair-link-konto">'
            . '<label for="repair_konto">Kundennummer:</label> '
            . '<input type="text" id="repair_konto" class="repair-konto-input" value="' . self::h($kontoValue) . '" size="14"'
            . ' data-repair-konto-param="repair_konto"> '
            . '<button type="button" class="button repair-konto-apply" data-target="repair_konto">Belege anzeigen</button>';
        if ($adresse !== null) {
            $html .= ' <span class="repair-konto-name">' . self::h($adresse['name'])
                . ((int)$adresse['id'] !== $ticketAdresseId ? ' <strong>(Fremdkonto)</strong>' : '') . '</span>';
        }
        $html .= '</div>';

        if ($error !== '') {
            return $html . '<p class="repairHint">' . self::h($error) . '</p>';
        }
        if ($adresse === null) {
            return $html . '<p class="repairHint">Kein Kundenkonto am Ticket. Kundennummer eingeben, um Belege zu suchen.</p>';
        }

        $exclude = [];
        foreach ($nodes as $node) {
            $exclude[(string)$node['key']] = true;
        }
        foreach ($direct as $key => $unused) {
            $exclude[$key] = true;
        }

        $rows = '';
        foreach ($this->gateway->findBelegeForAdresse((int)$adresse['id']) as $beleg) {
            $key = RepairBelegChainResolver::key((string)$beleg['typ'], (int)$beleg['id']);
            if (isset($exclude[$key])) {
                continue;
            }
            $button = $canEdit
                ? '<button type="submit" class="button repair-link-btn" name="repair_link" value="' . self::h($key) . '"'
                    . ' formaction="index.php?module=repairintegration&amp;action=linkbeleg&amp;ticket=' . $ticketId . '"'
                    . ' formmethod="post">Zuordnen</button>'
                : '';
            $rows .= '<tr data-repair-typ="' . self::h($beleg['typ']) . '">'
                . '<td>' . self::h(RepairBelegChainResolver::label((string)$beleg['typ'])) . '</td>'
                . '<td>' . self::belegLink((string)$beleg['typ'], (int)$beleg['id'], RepairBelegChainResolver::number($beleg)) . '</td>'
                . '<td>' . self::h($beleg['status']) . '</td>'
                . '<td>' . self::h(self::date($beleg['datum'])) . '</td>'
                . '<td class="repair-amount">' . self::h(self::amount($beleg['betrag'])) . '</td>'
                . '<td>' . $button . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return $html . '<p class="repairHint">Keine weiteren Belege f&uuml;r dieses Kundenkonto.</p>';
        }

        return $html
            . '<div class="repair-link-filter">Typ: <select class="repair-link-typfilter">'
            . '<option value="">Alle</option>'
            . '<option value="angebot">Angebote</option><option value="auftrag">Auftr&auml;ge</option>'
            . '<option value="lieferschein">Lieferscheine</option><option value="rechnung">Rechnungen</option>'
            . '<option value="gutschrift">Gutschriften</option></select></div>'
            . '<div class="repair-link-scroll"><table class="mkTable repair-link-candidates" width="100%">'
            . '<thead><tr><th>Typ</th><th>Nr.</th><th>Status</th><th>Datum</th><th>Betrag</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    /**
     * Inline-Handler fuer POST-Buttons. Das Panel steht in der Beleg-Minidetail,
     * die auch in Listen mit eigenem <form> geladen wird - verschachtelte
     * Formulare verwirft der Browser. Daher baut der Button das Formular
     * erst beim Klick am <body> auf.
     */
    private const POST_JS = "var d=JSON.parse(this.getAttribute('data-repair-post'));"
        . "if(d.confirm&&!confirm(d.confirm)){return false;}"
        . "var f=document.createElement('form');f.method='post';f.action=d.action;"
        . "Object.keys(d.fields).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;i.value=d.fields[k];f.appendChild(i);});"
        . "document.body.appendChild(f);f.submit();return false;";

    private const KONTO_JS = "var c=this.closest('.repair-beleg-tickets');"
        . "var v=c.querySelector('.repair-konto-input').value;var x=new XMLHttpRequest();"
        . "x.open('GET',this.getAttribute('data-url')+'&repair_konto='+encodeURIComponent(v));"
        . "x.onload=function(){if(x.status===200){c.outerHTML=x.responseText;}};x.send();return false;";

    private const KONTO_ENTER_JS = "if(event.key==='Enter'){event.preventDefault();"
        . "this.parentNode.querySelector('.repair-konto-apply').click();}";

    private static function postButton(string $label, string $action, array $fields, string $confirm = ''): string
    {
        $data = json_encode(
            ['action' => $action, 'fields' => $fields, 'confirm' => $confirm],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return '<button type="button" class="button" data-repair-post="' . self::h($data) . '"'
            . ' onclick="' . self::h(self::POST_JS) . '">' . $label . '</button>';
    }

    /**
     * Block "Reparaturtickets" fuer die Minidetail (Protokoll-Bereich) eines Belegs.
     * Wird auch per XHR neu geladen (Kundennummer-Filter), daher ein
     * selbsttragender Container.
     */
    public function belegPanel(string $typ, int $belegId, string $kontoInput, string $csrfToken, bool $canEdit): string
    {
        $beleg = $this->resolver->loadBeleg($typ, $belegId);
        if ($beleg === null) {
            return '';
        }

        $nodes = $this->resolver->resolve([['typ' => $typ, 'id' => $belegId]]);
        $nodesByKey = [];
        foreach ($nodes as $node) {
            $nodesByKey[(string)$node['key']] = $node;
        }
        $links = $this->gateway->getByBelege(array_map(
            static fn(array $n): array => ['typ' => (string)$n['typ'], 'id' => (int)$n['id']],
            $nodes
        ));

        // Pro Ticket eine Zeile: direkte Zuordnung dieses Belegs hat Vorrang
        // vor einer Zuordnung ueber einen anderen Beleg der Kette.
        $ownKey = RepairBelegChainResolver::key($typ, $belegId);
        $byTicket = [];
        foreach ($links as $link) {
            $ticketId = (int)$link['ticket_id'];
            $isOwn = RepairBelegChainResolver::key((string)$link['beleg_typ'], (int)$link['beleg_id']) === $ownKey;
            if (!isset($byTicket[$ticketId]) || ($isOwn && !$byTicket[$ticketId]['own'])) {
                $byTicket[$ticketId] = ['link' => $link, 'own' => $isOwn];
            }
        }

        $directTickets = [];
        $assignedRows = '';
        foreach ($byTicket as $ticketId => $entry) {
            $link = $entry['link'];
            $isOwn = $entry['own'];
            if ($isOwn) {
                $directTickets[$ticketId] = true;
            }
            $linkKey = RepairBelegChainResolver::key((string)$link['beleg_typ'], (int)$link['beleg_id']);
            $via = $isOwn ? 'direkt' : '&uuml;ber ' . self::h(RepairBelegChainResolver::label((string)$link['beleg_typ'])
                . ' ' . RepairBelegChainResolver::number($nodesByKey[$linkKey] ?? ['belegnr' => $link['beleg_nr'], 'id' => $link['beleg_id']]));
            $action = '';
            if ($isOwn && $canEdit) {
                $action = self::postButton(
                    'Entfernen',
                    'index.php?module=repairintegration&action=unlinkbeleg&ticket=' . $ticketId,
                    ['repair_link_csrf' => $csrfToken, 'repair_unlink' => (string)(int)$link['id'], 'repair_return' => 'beleg'],
                    'Zuordnung wirklich entfernen?'
                );
            }
            $assignedRows .= '<tr>'
                . '<td><a href="index.php?module=ticket&amp;action=edit&amp;id=' . $ticketId . '">' . self::h($link['ticket_schluessel']) . '</a></td>'
                . '<td>' . self::h($link['betreff'] ?? '') . '</td>'
                . '<td>' . self::h($link['ticket_status'] ?? '') . '</td>'
                . '<td>' . $via . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        $html = '<div class="repair-beleg-tickets" style="background-color:var(--fieldset, white)">'
            . '<h2 class="greyh2">Reparaturtickets</h2><div style="padding:10px;">'
            . '<table class="mkTable" width="100%"><thead><tr><th>Ticket</th><th>Betreff</th><th>Status</th><th>Zuordnung</th><th></th></tr></thead><tbody>'
            . ($assignedRows !== '' ? $assignedRows : '<tr><td colspan="5">Diesem Beleg ist kein Reparaturticket zugeordnet.</td></tr>')
            . '</tbody></table>';

        if ($canEdit) {
            // Tickets, die nur ueber die Kette haengen, bleiben zuordenbar
            $html .= $this->belegAssign($typ, $belegId, (int)$beleg['adresse'], $kontoInput, $csrfToken, $directTickets);
        }

        return $html . '</div></div>';
    }

    private function belegAssign(string $typ, int $belegId, int $belegAdresseId, string $kontoInput, string $csrfToken, array $directTickets): string
    {
        [$adresse, $kontoValue, $error] = $this->resolveKonto($belegAdresseId, $kontoInput);

        $reloadUrl = 'index.php?module=repairintegration&action=belegtickets&typ=' . rawurlencode($typ) . '&id=' . $belegId;
        $html = '<p style="margin-top:10px"><strong>Ticket zuordnen</strong> &middot; '
            . 'Kundennummer: <input type="text" class="repair-konto-input" value="' . self::h($kontoValue) . '" size="14"'
            . ' onkeydown="' . self::h(self::KONTO_ENTER_JS) . '"> '
            . '<button type="button" class="button repair-konto-apply" data-url="' . self::h($reloadUrl) . '"'
            . ' onclick="' . self::h(self::KONTO_JS) . '">Tickets anzeigen</button>';
        if ($adresse !== null) {
            $html .= ' ' . self::h($adresse['name'])
                . ((int)$adresse['id'] !== $belegAdresseId ? ' <strong>(Fremdkonto)</strong>' : '');
        }
        $html .= '</p>';

        if ($error !== '') {
            return $html . '<p class="repairHint">' . self::h($error) . '</p>';
        }
        if ($adresse === null) {
            return $html . '<p class="repairHint">Beleg ohne Kundenkonto. Kundennummer eingeben, um Tickets zu suchen.</p>';
        }

        $rows = '';
        foreach ($this->gateway->findRepairTicketsForAdresse((int)$adresse['id']) as $ticket) {
            if (isset($directTickets[(int)$ticket['id']])) {
                continue;
            }
            $geraet = trim((string)$ticket['manufacturer'] . ' ' . (string)$ticket['model']);
            $rows .= '<tr>'
                . '<td><a href="index.php?module=ticket&amp;action=edit&amp;id=' . (int)$ticket['id'] . '">' . self::h($ticket['schluessel']) . '</a></td>'
                . '<td>' . self::h($ticket['betreff']) . '</td>'
                . '<td>' . self::h($geraet) . '</td>'
                . '<td>' . self::h($ticket['status']) . '</td>'
                . '<td>' . self::h(self::date($ticket['zeit'])) . '</td>'
                . '<td>' . self::postButton(
                    'Zuordnen',
                    'index.php?module=repairintegration&action=linkbeleg&ticket=' . (int)$ticket['id'],
                    ['repair_link_csrf' => $csrfToken, 'repair_link' => RepairBelegChainResolver::key($typ, $belegId), 'repair_return' => 'beleg']
                ) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            return $html . '<p class="repairHint">Keine weiteren Reparaturtickets f&uuml;r dieses Kundenkonto.</p>';
        }

        return $html
            . '<table class="mkTable" width="100%"><thead><tr><th>Ticket</th><th>Betreff</th><th>Ger&auml;t</th><th>Status</th><th>Datum</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @return array{0: ?array, 1: string, 2: string} [Adresse, Feldwert, Fehlertext]
     */
    private function resolveKonto(int $defaultAdresseId, string $kontoInput): array
    {
        $kontoInput = trim($kontoInput);
        if ($kontoInput !== '') {
            $adresse = $this->gateway->findAdresseByKundennummer($kontoInput);
            if ($adresse === null) {
                return [null, $kontoInput, 'Keine Adresse mit Kundennummer "' . $kontoInput . '" gefunden.'];
            }
            return [$adresse, $kontoInput, ''];
        }
        $adresse = $this->gateway->getAdresse($defaultAdresseId);
        return [$adresse, (string)($adresse['kundennummer'] ?? ''), ''];
    }
}
