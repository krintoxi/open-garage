<?php
declare(strict_types=1);
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/seed_codes.php';

/** First run: an empty garage plus the OBD-II code library and a few useful links. Vehicles are added in the app. */
function seed_all(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        seed_codes($pdo);
        $links = [
            ['NHTSA recalls lookup (by VIN)', 'https://www.nhtsa.gov/recalls', 'Safety', 'Open recalls are fixed free by the dealer.'],
            ['NHTSA VIN decoder', 'https://vpic.nhtsa.dot.gov/decoder/', 'Reference', 'The same database the Add-vehicle form uses.'],
            ['OBD-codes.com', 'https://www.obd-codes.com/', 'Reference', 'Generic OBD-II code explanations.'],
        ];
        $st = $pdo->prepare('INSERT INTO links (title, url, category, notes) VALUES (?,?,?,?)');
        foreach ($links as $l) $st->execute($l);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
