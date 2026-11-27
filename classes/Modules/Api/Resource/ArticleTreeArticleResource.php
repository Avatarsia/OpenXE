<?php

namespace Xentral\Modules\Api\Resource;

use Xentral\Components\Database\SqlQuery\DeleteQuery;
use Xentral\Components\Database\SqlQuery\InsertQuery;
use Xentral\Components\Database\SqlQuery\SelectQuery;
use Xentral\Components\Database\SqlQuery\UpdateQuery;

/**
 * REST v1 Resource for OpenXE's article tree (Artikelbaum / Shopkategorien)
 * many-to-many assignments (`artikelbaum_artikel`).
 */
class ArticleTreeArticleResource extends AbstractResource
{
    const TABLE_NAME = 'artikelbaum_artikel';

    protected function configure()
    {
        $this->setTableName(self::TABLE_NAME);

        $this->registerFilterParams([
            'artikel'   => 'aba.artikel =',
            'kategorie' => 'aba.kategorie =',
            'haupt'     => 'aba.haupt =',
        ]);

        $this->registerSortingParams([
            'id'        => 'aba.id',
            'artikel'   => 'aba.artikel',
            'kategorie' => 'aba.kategorie',
            'haupt'     => 'aba.haupt',
        ]);

        $this->registerValidationRules([
            'id'        => 'not_present',
            'artikel'   => 'required|numeric|db_value:artikel,id',
            'kategorie' => 'required|numeric|db_value:artikelkategorien,id',
            'haupt'     => 'in:0,1',
        ]);

        $this->registerIncludes([
            'kategorie' => [
                'key'      => 'kategorie',
                'resource' => ArticleCategoryResource::class,
                'columns'  => [
                    'k.id',
                    'k.bezeichnung',
                    'k.parent',
                    'k.projekt',
                ],
            ],
        ]);
    }

    /**
     * @return SelectQuery
     */
    protected function selectAllQuery()
    {
        return $this->db->select()
            ->cols([
                'aba.id',
                'aba.artikel',
                'aba.kategorie',
                'aba.haupt',
            ])
            ->from(self::TABLE_NAME . ' AS aba');
    }

    /**
     * @return SelectQuery
     */
    protected function selectOneQuery()
    {
        return $this->selectAllQuery()->where('aba.id = :id');
    }

    /**
     * @return SelectQuery
     */
    protected function selectIdsQuery()
    {
        return $this->selectAllQuery()->where('aba.id IN (:ids)');
    }

    /**
     * @return InsertQuery
     */
    protected function insertQuery()
    {
        return $this->db->insert()->into(self::TABLE_NAME);
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
