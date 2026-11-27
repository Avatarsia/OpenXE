<?php

use Xentral\Components\Logger\Logger;
use Xentral\Modules\Onlineshop\Data\ArticleExportResult;

/** @var Logger $logger */
$logger = $app->Container->get('Logger');

if (file_exists(dirname(__DIR__) . '/www/lib/class.erpapi_custom.php') && !class_exists('erpAPICustom')) {
    include_once dirname(__DIR__) . '/www/lib/class.erpapi_custom.php';
}
if (empty($app->Conf)) {
    $conf = new Config();
    $app->Conf = $conf;
}
if (empty($app->DB) || empty($app->DB->connection)) {
    $app->DB = new DB($app->Conf->WFdbhost, $app->Conf->WFdbname, $app->Conf->WFdbuser, $app->Conf->WFdbpass, null, $app->Conf->WFdbport);
}
if (!isset($app->erp) || !$app->erp) {
    if (class_exists('erpAPICustom')) {
        $erp = new erpAPICustom($app);
    } else {
        $erp = new erpAPI($app);
    }
    $app->erp = $erp;
}
if (empty($app->remote)) {
    if (is_file(dirname(__DIR__) . '/www/lib/class.remote_custom.php')) {
        if (!class_exists('RemoteCustom')) {
            require_once dirname(__DIR__) . '/www/lib/class.remote_custom.php';
        }
        $app->remote = new RemoteCustom($app);
    } else {
        $app->remote = new Remote($app);
    }
}

$logger->debug(
    'Start'
);

$app->DB->Update(
        "UPDATE `prozessstarter` 
  SET `mutexcounter` = `mutexcounter` + 1 
  WHERE `mutex` = 1 AND (`parameter` = 'artikeluebertragen') AND `aktiv` = 1"
);

$app->DB->Update(
    "UPDATE `prozessstarter`
    SET `letzteausfuerhung` = NOW(), `mutex` = 1, `mutexcounter` = 0
    WHERE `parameter` = 'artikeluebertragen' AND `mutex` = 0"
);
if ($app->DB->affected_rows() < 1) {
    $logger->debug('Läuft bereits');
    return;
}

try {
    $articles = $app->DB->SelectArr(
            'SELECT `id`,`shop`,`artikel` FROM `shopexport_artikeluebertragen_check` ORDER BY `id`'
    );
    if (!empty($articles)) {
        /** @var Shopexport $objShopexport */
        $objShopexport = $app->loadModule('shopexport');
        if ($objShopexport !== null && method_exists($objShopexport, 'addChangedArticles')) {
            $objShopexport->addChangedArticles();
        }
    }

    $logger->debug(
        'Prepare',
        [
            'articles' => $articles
        ]
    );

    $anzChecked = [];
    $anzChanged = [];
    $lastids = [];
    while (!empty($articles)) {
        foreach ($articles as $article) {
            if (empty($anzChanged[$article['shop']])) {
                $anzChanged[$article['shop']] = 0;
            }
            if (empty($anzChecked[$article['shop']])) {
                $anzChecked[$article['shop']] = 0;
            }
            if (!isset($lastids[$article['shop']])) {
                $lastids[$article['shop']] = (int) $app->erp->GetKonfiguration(
                                'shopexport_artikeluebertragen_check_lastid_' . $article['shop']
                );
            }
            $changed = $objShopexport->hasArticleHashChanged($article['artikel'], $article['shop']);
            if ($changed['changed']) {
                $app->DB->Insert(
                        sprintf(
                                'INSERT INTO `shopexport_artikeluebertragen` (`artikel`, `shop`, `check_nr`) VALUES (%d, %d, %d)',
                                $article['artikel'], $article['shop'], $lastids[$article['shop']]
                        )
                );
                $anzChanged[$article['shop']]++;
            }
            $anzChecked[$article['shop']]++;
            $app->DB->Delete(
                    sprintf(
                            'DELETE FROM `shopexport_artikeluebertragen_check` WHERE `id` = %d',
                            $article['id']
                    )
            );
            $app->DB->Update(
                    sprintf(
                            "UPDATE `shopexport` SET `autosendarticle_last` = NOW() WHERE `id` = %d",
                            $article['shop']
                    )
            );
        }
        $app->erp->SetKonfigurationValue(
                'shopexport_artikeluebertragen_check_changed_' . $article['shop'],
                $anzChanged[$article['shop']]
        );
        $app->erp->SetKonfigurationValue(
                'shopexport_artikeluebertragen_check_checked_' . $article['shop'],
                $anzChecked[$article['shop']]
        );
        if (method_exists($app->erp, 'canRunCronjob') && !$app->erp->canRunCronjob([])) {
            $logger->debug(
                '!canRunCronjob'
            );
            return;
        }
        $articles = $app->DB->SelectArr(
                'SELECT `id`,`shop`,`artikel` FROM `shopexport_artikeluebertragen_check` ORDER BY `id` LIMIT 10'
        );
    }

/*
while ($check > 0) {
    $shopartikel = $app->DB->Query(
            "SELECT `id`,`shop`,`artikel`,`check_nr` FROM `shopexport_artikeluebertragen` ORDER BY `id` LIMIT 10"
    );
    if (!empty($shopartikel)) {
        $anz = 0;
        while ($row = $app->DB->Fetch_Assoc($shopartikel)) {
            if (!isset($lastids[$row['shop']])) {
                $lastids[$row['shop']] = (int) $app->erp->GetKonfiguration('shopexport_artikeluebertragen_check_lastid_' . $row['shop']);
            }

            $anz++;
            try {
                $app->remote->RemoteSendArticleList($row['shop'], array($row['artikel']));
                $app->erp->LagerSync($row['artikel'], true);
            } catch (Throwable $exception) {
                $app->erp->LogFile($app->DB->real_escape_string($exception->getMessage()));
            }
            $app->DB->Delete(
                    sprintf(
                            'DELETE FROM `shopexport_artikeluebertragen` WHERE `id`= %d LIMIT 1',
                            $row['id']
                    )
            );

            if (!empty($row['check_nr']) && $row['check_nr'] == $lastids[$row['shop']]) {
                $transfered = 1 + (int) $app->erp->GetKonfiguration('shopexport_artikeluebertragen_check_transfered_' . $row['shop']);
                $app->erp->SetKonfigurationValue('shopexport_artikeluebertragen_check_transfered_' . $row['shop'], $transfered);
            }

            $app->DB->Update(
                    "UPDATE `prozessstarter` 
        SET `letzteausfuerhung`=NOW(), `mutex` = 1,`mutexcounter`=0 
        WHERE `parameter` = 'artikeluebertragen'"
            );
        }

        $app->DB->free($shopartikel);
    }

    if (method_exists($app->erp, 'canRunCronjob') && !$app->erp->canRunCronjob(['artikeluebertragen'])) {
        return;
    }

    $check = $app->DB->Select('SELECT COUNT(`id`) FROM `shopexport_artikeluebertragen`');
}*/

    $shops = $app->DB->SelectArr(
        'SELECT DISTINCT `shop`, shopexport.`bezeichnung`, shopexport.`artikelexportbatchsize`
        FROM `shopexport_artikeluebertragen` INNER JOIN shopexport ON shopexport.id = `shop`'
    );
    foreach ($shops ?: [] as $shop) {
        $shopId = (int) $shop['shop'];
        $batchSize = (int) $shop['artikelexportbatchsize'];
        if ($batchSize <= 0) {
            $batchSize = 10;
        }
        $cursorKey = 'shopexport_artikeluebertragen_cursor_' . $shopId;
        $cursor = (int) $app->erp->GetKonfiguration($cursorKey);
        $selectBatch = static function (int $afterId) use ($app, $shopId, $batchSize) {
            return $app->DB->SelectArr(
                "SELECT `id`, `artikel` FROM `shopexport_artikeluebertragen`
                WHERE `shop` = {$shopId} AND `id` > {$afterId} ORDER BY `id` LIMIT {$batchSize}"
            );
        };
        $batch = $selectBatch($cursor);
        if (empty($batch) && $cursor > 0) {
            $batch = $selectBatch(0);
        }
        if (empty($batch)) {
            continue;
        }

        $queueIds = [];
        foreach ($batch as $row) {
            $queueIds[(int) $row['artikel']][] = (int) $row['id'];
        }
        // Advance past retryable rows so that they cannot occupy every slot in the next batch.
        $app->erp->SetKonfigurationValue($cursorKey, (int) end($batch)['id']);
        $context = ['shop' => $shopId, 'bezeichnung' => $shop['bezeichnung'], 'count' => count($queueIds)];
        $logger->debug('{bezeichnung} (Shop {shop}) {count} Artikel', $context);

        $result = [];
        try {
            $result = $app->remote->RemoteSendArticleList($shopId, array_keys($queueIds));
        } catch (Throwable $exception) {
            $logger->error('Fehler {bezeichnung} (Shop {shop}) {count} Artikel', $context + ['exception' => $exception]);
        }

        $logger->debug('Ende {bezeichnung} (Shop {shop}) {count} Artikel', $context + ['result' => $result]);
        if (!is_array($result)) {
            continue;
        }
        foreach ($result as $articleResult) {
            if (!$articleResult instanceof ArticleExportResult || $articleResult->articleId <= 0
                || $articleResult->retryExport || !isset($queueIds[$articleResult->articleId])) {
                continue;
            }
            $ids = implode(',', $queueIds[$articleResult->articleId]);
            $app->DB->Delete("DELETE FROM `shopexport_artikeluebertragen` WHERE `id` IN ({$ids})");
            unset($queueIds[$articleResult->articleId]);
            if ($articleResult->success) {
                try {
                    $app->erp->LagerSync($articleResult->articleId, true);
                } catch (Throwable $exception) {
                    $logger->error('Lagerabgleich nach Artikelexport fehlgeschlagen.', $context + [
                        'article' => $articleResult->articleId,
                        'exception' => $exception,
                    ]);
                }
            }
        }
    }
    $logger->debug('Ende');
} finally {
    $app->DB->Update(
        "UPDATE `prozessstarter`
        SET `letzteausfuerhung` = NOW(), `mutex` = 0, `mutexcounter` = 0
        WHERE `parameter` = 'artikeluebertragen'"
    );
}
