<?php

namespace Xentral\Modules\Api\Resource;

use Xentral\Components\Database\SqlQuery\DeleteQuery;
use Xentral\Components\Database\SqlQuery\InsertQuery;
use Xentral\Components\Database\SqlQuery\SelectQuery;
use Xentral\Components\Database\SqlQuery\UpdateQuery;

/**
 * REST v1 Resource for OpenXE's article-to-onlineshop assignments
 * (`artikel_onlineshops`, Reiter "Onlineshop").
 */
class ArticleOnlineShopResource extends AbstractResource
{
    const TABLE_NAME = 'artikel_onlineshops';

    private const WRITABLE_COLUMNS = [
        'artikel', 'shop', 'aktiv', 'ausartikel', 'lagerkorrekturwert', 'pseudolager',
        'autolagerlampe', 'restmenge', 'lieferzeitmanuell', 'pseudopreis', 'autoabgeleicherlaubt',
    ];

    protected function configure()
    {
        $this->setTableName(self::TABLE_NAME);

        $this->registerFilterParams([
            'artikel' => 'ao.artikel =',
            'shop'    => 'ao.shop =',
            'aktiv'   => 'ao.aktiv =',
        ]);

        $this->registerSortingParams([
            'id'      => 'ao.id',
            'artikel' => 'ao.artikel',
            'shop'    => 'ao.shop',
            'aktiv'   => 'ao.aktiv',
        ]);

        $this->registerValidationRules([
            'id'                   => 'not_present',
            'artikel'              => 'required|numeric|db_value:artikel,id',
            'shop'                 => 'required|numeric|db_value:shopexport,id',
            'aktiv'                => 'in:0,1',
            'ausartikel'           => 'in:0,1',
            'lagerkorrekturwert'   => 'numeric',
            'autolagerlampe'       => 'in:0,1',
            'restmenge'            => 'in:0,1',
            'autoabgeleicherlaubt' => 'in:0,1',
        ]);
    }

    /**
     * @return SelectQuery
     */
    protected function selectAllQuery()
    {
        return $this->db->select()
            ->cols([
                'ao.id',
                'ao.artikel',
                'ao.shop',
                'ao.aktiv',
                'ao.ausartikel',
                'ao.lagerkorrekturwert',
                'ao.pseudolager',
                'ao.autolagerlampe',
                'ao.restmenge',
                'ao.lieferzeitmanuell',
                'ao.pseudopreis',
                'ao.autoabgeleicherlaubt',
            ])
            ->from(self::TABLE_NAME . ' AS ao');
    }

    /**
     * @return SelectQuery
     */
    protected function selectOneQuery()
    {
        return $this->selectAllQuery()->where('ao.id = :id');
    }

    /**
     * @return SelectQuery
     */
    protected function selectIdsQuery()
    {
        return $this->selectAllQuery()->where('ao.id IN (:ids)');
    }

    /**
     * @return InsertQuery
     */
    protected function insertQuery()
    {
        return $this->db->insert()
            ->into(self::TABLE_NAME)
            ->set('pseudolager', "''")
            ->set('lieferzeitmanuell', "''")
            ->set('last_article_hash', "''");
    }

    protected function mapInputData($inputVars, $inputMapping = null)
    {
        // Keep the ID added by AbstractResource::edit() for the WHERE binding.
        $routeId = array_intersect_key($inputVars, ['id' => true]);
        $mappedInput = parent::mapInputData($inputVars, $inputMapping);

        return $routeId + array_intersect_key($mappedInput, array_flip(self::WRITABLE_COLUMNS));
    }

    /**
     * @return UpdateQuery
     */
    protected function updateQuery()
    {
        return $this->db->update()
            ->table(self::TABLE_NAME)
            ->where('id = :id');
    }

    /**
     * @return DeleteQuery
     */
    protected function deleteQuery()
    {
        return $this->db->delete()
            ->from(self::TABLE_NAME)
            ->where('id = :id');
    }
}
