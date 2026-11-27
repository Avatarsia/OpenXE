<?php

if (file_exists(dirname(__DIR__) . '/www/lib/class.erpapi_custom.php') && !class_exists('erpAPICustom')) {
    include_once dirname(__DIR__) . '/www/lib/class.erpapi_custom.php';
}

if (empty($app) || !class_exists('ApplicationCore') || !($app instanceof ApplicationCore)) {
    $app = new app_t();
}

if (empty($app->Conf)) {
    $conf = new Config();
    $app->Conf = $conf;
}
if (empty($app->DB)) {
    $app->DB = new DB($app->Conf->WFdbhost, $app->Conf->WFdbname, $app->Conf->WFdbuser, $app->Conf->WFdbpass, null, $app->Conf->WFdbport);
}
if (empty($app->erp)) {
    if (class_exists('erpAPICustom')) {
        $app->erp = new erpAPICustom($app);
    } else {
        $app->erp = new erpAPI($app);
    }
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
/** @var \Psr\Log\LoggerInterface $logger */
$logger = $app->Container->get('Logger');

$logger->info("Lagerzahlen-Synchronisation Start");

//$app->DB->Update("UPDATE artikel SET cache_lagerplatzinhaltmenge='999'");

$firmendatenid = $app->DB->Select("SELECT MAX(id) FROM firmendaten LIMIT 1");

$app->DB->Update(
    "UPDATE `prozessstarter` 
    SET `mutexcounter` = `mutexcounter` + 1 
    WHERE `mutex` = 1 AND `parameter` = 'lagerzahlen' AND `aktiv` = 1"
);
$app->DB->Update(
    "UPDATE `prozessstarter`
    SET `mutex` = 1, `mutexcounter` = 0, `letzteausfuerhung` = NOW()
    WHERE `parameter` = 'lagerzahlen' AND `aktiv` = 1 AND `mutex` = 0"
);
if ($app->DB->affected_rows() < 1) {
    $logger->warning("Lagerzahlen-Synchronisation Ende: Prozessstarter-Mutex nicht bereit");
    return;
}

$message = '';
try {
    $shops = $app->DB->SelectArr('SELECT * FROM `shopexport` WHERE `aktiv` = 1');
    if (empty($shops)) {
        $logger->info("Lagerzahlen-Synchronisation Ende: Keine aktiven Shops");
        return;
    }
    $shopByIds = [];
    foreach ($shops as $shop) {
        $shopByIds[$shop['id']] = $shop;
    }
    $shopIds = array_keys($shopByIds);
    $shopIdsStr = implode(',', $shopIds);
    $hours = 12;
    $hoursShop = 48;
    $lagerartikel = $app->DB->SelectFirstCols(
            "SELECT a.id
          FROM `artikel` AS `a`
          LEFT JOIN (
            SELECT ao2.artikel, 1 AS `autolagerlampe`,
                   MAX(ao2.storage_cache = -999) AS `cache_reseted`,
                   MAX(HOUR(TIMEDIFF(NOW(), `last_storage_transfer`))) AS `last_storage_transfer_hours`
            FROM `artikel_onlineshops` AS `ao2`
            INNER JOIN `artikel` AS `art`
                ON ao2.artikel = art.id AND (art.autolagerlampe = 1 OR (ao2.autolagerlampe = 1 AND ao2.ausartikel = 0))
            WHERE ao2.aktiv = 1 AND ao2.shop IN ({$shopIdsStr})
            GROUP BY ao2.artikel
          ) AS `ao` ON a.id = ao.artikel
          WHERE (a.geloescht = 0 OR a.geloescht IS NULL)
          AND (
              a.lagerartikel = 1
              OR (a.stueckliste = 1 AND a.juststueckliste = 1)
          )
          AND (a.autolagerlampe = 1 OR ao.artikel IS NOT NULL)
          AND (a.shop > 0 OR a.shop2 > 0 OR a.shop3 > 0 OR ao.artikel IS NOT NULL)
          AND a.nummer <> 'DEL'
          AND (
              a.cache_lagerplatzinhaltmenge = -999
              OR ao.cache_reseted = 1
              OR a.laststorage_sync < a.laststorage_changed
              OR a.laststorage_sync < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
              OR (ao.last_storage_transfer_hours IS NULL OR ao.last_storage_transfer_hours > {$hoursShop})
          )
          ORDER BY a.cache_lagerplatzinhaltmenge = -999 DESC,
                   ao.cache_reseted DESC,
                   a.laststorage_sync"
    );

    if (empty($lagerartikel)) {
        $logger->info("Lagerzahlen-Synchronisation Ende: Keine fälligen Artikel");
        return;
    }

    $method = new ReflectionMethod($app->erp, 'LagerSync');
    $supportsShopList = $method->getNumberOfParameters() > 2;
    $logger->info('Lagerzahlen-Synchronisation, Artikel gesamt: ' . count($lagerartikel));
    foreach ($lagerartikel as $articleCounter => $articleId) {
        try {
            $message .= $supportsShopList
                ? $app->erp->LagerSync($articleId, false, $shopByIds)
                : $app->erp->LagerSync($articleId, false);
        } catch (Throwable $exception) {
            $message .= '<br>' . $exception->getMessage();
            $logger->error('Lagerzahlen-Synchronisation fehlgeschlagen.', [
                'article' => $articleId,
                'exception' => $exception,
            ]);
        }
        if ($message !== '') {
            $message .= '<br>';
        }
        if ($articleCounter % 10 === 0 && method_exists($app->erp, 'canRunCronjob') && !$app->erp->canRunCronjob([])) {
            $logger->warning("Lagerzahlen-Synchronisation Ende: lagerzahlen-Job kann nicht geladen werden");
            return;
        }
    }
} catch (Throwable $exception) {
    $logger->error('Lagerzahlen-Synchronisation abgebrochen.', ['exception' => $exception]);
    return;
} finally {
    $app->DB->Update(
        "UPDATE `prozessstarter`
        SET `mutex` = 0, `mutexcounter` = 0, `letzteausfuerhung` = NOW()
        WHERE `parameter` = 'lagerzahlen'"
    );
}

try {
    if ($message != '' && $app->erp->Firmendaten('systemmailsabschalten') == 0 && $app->erp->GetFirmaMail() != '') {
        if ($app->erp->Firmendaten('systemmailsempfaenger') != '') {
            $app->erp->MailSend(
                    $app->erp->GetFirmaMail(),
                    $app->erp->GetFirmaName(),
                    $app->erp->Firmendaten('systemmailsempfaenger'),
                    'Lagerverwaltung',
                    'Systemmeldung: Auto Update Lagerlampen',
                    $message
            );
        } else {
            if ($app->erp->GetFirmaBCC1() != '') {
                $app->erp->MailSend(
                        $app->erp->GetFirmaMail(),
                        $app->erp->GetFirmaName(),
                        $app->erp->GetFirmaBCC1(),
                        'Lagerverwaltung',
                        'Systemmeldung: Auto Update Lagerlampen',
                        $message
                );
            }
        }
    }
} catch (Throwable $exception) {
    $logger->error('Lagerzahlen-Synchronisation Systemmeldung fehlgeschlagen.', ['exception' => $exception]);
}

$logger->info("Lagerzahlen-Synchronisation Ende");
