<?php

declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Search;

use Xentral\Components\Database\Database;
use Xentral\Modules\SuperSearch\SearchIndex\Collection\ItemFormatterCollection;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexData;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexIdentifier;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexItem;
use Xentral\Modules\SuperSearch\SearchIndex\Provider\FullIndexProviderInterface;
use Xentral\Modules\SuperSearch\SearchIndex\Provider\ItemIndexProviderInterface;

final class RepairTicketSearchProvider implements FullIndexProviderInterface, ItemIndexProviderInterface
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getModuleName(): ?string
    {
        return 'repairintegration';
    }

    public function getIndexName()
    {
        return 'repairs';
    }

    public function getIndexTitle()
    {
        return 'Reparaturen';
    }

    public function getItem(IndexIdentifier $identifier)
    {
        $row = $this->db->fetchRow(
            $this->buildBaseQuery() . ' AND t.id = :id LIMIT 1',
            ['id' => (int)$identifier->getId()]
        );

        if (empty($row)) {
            return null;
        }

        $formatter = $this->getRowFormatter();

        return $formatter($row);
    }

    public function getAllItems()
    {
        $rows = $this->db->fetchAll($this->buildBaseQuery());

        return new ItemFormatterCollection($rows, $this->getRowFormatter());
    }

    private function buildBaseQuery(): string
    {
        return "SELECT
                t.id,
                t.schluessel,
                t.betreff,
                t.kunde,
                t.mailadresse,
                rd.manufacturer,
                rd.model,
                rd.serial_number,
                rd.service_type,
                COALESCE(sc.label_de, t.status) AS status_label
             FROM `ticket` t
             INNER JOIN `ticket_repair_details` rd ON rd.ticket_id = t.id
             LEFT JOIN `ticket_status_config` sc ON sc.slug = t.status
             WHERE rd.anonymized_at IS NULL";
    }

    /**
     * @return callable
     */
    private function getRowFormatter()
    {
        return static function (array $row) {
            $manufacturer = trim((string)($row['manufacturer'] ?? ''));
            $model        = trim((string)($row['model'] ?? ''));
            $schluessel   = trim((string)($row['schluessel'] ?? ''));
            $kunde        = trim((string)($row['kunde'] ?? ''));
            $serviceType  = trim((string)($row['service_type'] ?? ''));
            $statusLabel  = trim((string)($row['status_label'] ?? ''));

            $deviceParts = array_filter([$manufacturer, $model]);
            $devicePart  = $deviceParts === [] ? '' : implode(' ', $deviceParts);

            $titleParts = [];
            if ($schluessel !== '') {
                $titleParts[] = '#' . $schluessel;
            }
            if ($devicePart !== '') {
                $titleParts[] = $devicePart;
            }
            if ($titleParts === []) {
                $titleParts[] = 'Reparatur ' . (int)$row['id'];
            }
            $title = implode(' - ', $titleParts);

            $subtitleParts = array_filter([
                $kunde,
                $serviceType !== '' ? ucfirst($serviceType) : '',
                $statusLabel,
            ]);

            $link = sprintf('index.php?module=ticket&action=edit&id=%d', (int)$row['id']);

            $data = new IndexData($title, $link, 0);
            if ($subtitleParts !== []) {
                $data->setSubTitle(implode(' | ', $subtitleParts));
            }

            $data->addSearchWord('reparatur');
            $data->addSearchWord('repair');
            $data->addSearchWord($schluessel);
            $data->addSearchWord($kunde);
            $data->addSearchWord((string)($row['mailadresse'] ?? ''));
            $data->addSearchWord($manufacturer);
            $data->addSearchWord($model);
            $data->addSearchWord((string)($row['serial_number'] ?? ''));
            $data->addSearchWord((string)($row['betreff'] ?? ''));
            $data->addSearchWord($serviceType);

            $identifier = new IndexIdentifier('repairs', (int)$row['id']);

            return new IndexItem($identifier, $data);
        };
    }
}
