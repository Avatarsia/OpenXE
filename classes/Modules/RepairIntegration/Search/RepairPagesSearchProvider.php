<?php

declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Search;

use Xentral\Modules\SuperSearch\SearchIndex\Collection\ItemFormatterCollection;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexData;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexIdentifier;
use Xentral\Modules\SuperSearch\SearchIndex\Data\IndexItem;
use Xentral\Modules\SuperSearch\SearchIndex\Provider\FullIndexProviderInterface;
use Xentral\Modules\SuperSearch\SearchIndex\Provider\ItemIndexProviderInterface;

final class RepairPagesSearchProvider implements FullIndexProviderInterface, ItemIndexProviderInterface
{
    public function getModuleName(): ?string
    {
        return 'repairintegration';
    }

    public function getIndexName()
    {
        return 'repairpages';
    }

    public function getIndexTitle()
    {
        return 'Reparatur-Bedienseiten';
    }

    public function getItem(IndexIdentifier $identifier)
    {
        $pages = $this->getPages();
        $key = (string)$identifier->getId();
        if (!isset($pages[$key])) {
            return null;
        }

        $formatter = $this->getRowFormatter();

        return $formatter($pages[$key], $key);
    }

    public function getAllItems()
    {
        $callback = $this->getRowFormatter();

        return new ItemFormatterCollection($this->getPages(), $callback);
    }

    /**
     * @return array<string, array{title: string, link: string, words: string[]}>
     */
    private function getPages(): array
    {
        return [
            'list' => [
                'title' => 'Reparaturen',
                'link'  => 'index.php?module=repairintegration&action=list',
                'words' => ['Reparatur', 'Repair', 'Reparaturen', 'Liste', 'Tickets', 'Uebersicht'],
            ],
            'einstellungen' => [
                'title' => 'Reparatur-Einstellungen',
                'link'  => 'index.php?module=repairintegration&action=einstellungen',
                'words' => ['Reparatur', 'Repair', 'Reparaturen', 'Einstellungen', 'Konfiguration', 'Settings', 'Config'],
            ],
            'merge' => [
                'title' => 'Reparatur-Tickets zusammenfuehren',
                'link'  => 'index.php?module=repairintegration&action=merge',
                'words' => ['Reparatur', 'Repair', 'Reparaturen', 'Merge', 'Zusammenfuehren', 'Duplikate', 'Tickets'],
            ],
            'syncstatus' => [
                'title' => 'Reparatur Sync-Status',
                'link'  => 'index.php?module=repairintegration&action=syncstatus',
                'words' => ['Reparatur', 'Repair', 'Reparaturen', 'Sync', 'Status', 'Synchronisation', 'WordPress'],
            ],
        ];
    }

    /**
     * @return callable
     */
    private function getRowFormatter()
    {
        $indexName = $this->getIndexName();

        return static function (array $page, $key) use ($indexName) {
            $data = new IndexData($page['title'], $page['link'], 0);
            $data->addSearchWord($page['title']);
            foreach ($page['words'] as $word) {
                $data->addSearchWord($word);
            }
            $identifier = new IndexIdentifier($indexName, (string)$key);

            return new IndexItem($identifier, $data);
        };
    }
}
