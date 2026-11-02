<?php

declare(strict_types=1);

// Conciliar depósitos: suma las transferencias recibidas en un día, página por página.
//
// Solo lee (GET /v1/transfers): no crea ni mueve nada.
//
//   RAYA_SECRET_KEY=raya_sk_live_… php examples/conciliar-depositos.php 2026-10-23

use RayaCash\RayaClient;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * El día (YYYY-MM-DD) en la hora de Argentina, como rango semiabierto [inicio, fin): los días
 * seguidos no se pisan. null si no es una fecha.
 *
 * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
 */
function day_range(string $day): ?array
{
    $argentina = new DateTimeZone('America/Argentina/Buenos_Aires');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $argentina);
    if ($start === false || $start->format('Y-m-d') !== $day) {
        return null;
    }
    return [$start, $start->add(new DateInterval('P1D'))];
}

/**
 * Lo que se sumó a tu saldo entre $start y $end, en centavos.
 */
function deposits_of(RayaClient $client, DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $page = $client->transfers->list([
        'direction' => 'inbound',
        'created_gte' => $start,
        'created_lt' => $end,
        'limit' => 100,
    ]);
    $cents = 0;
    foreach ($page->autoPagingIter() as $transfer) {
        // net_amount es lo que se sumó a tu saldo. En centavos enteros, la suma es exacta.
        $cents += (int) round($transfer->net_amount * 100);
        echo $transfer->id, ' ', $transfer->counterparty->name, ' ', $transfer->net_amount, "\n";
    }
    return $cents;
}

$today = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
$day = $argv[1] ?? $today->format('Y-m-d');
$range = day_range($day);
if ($range === null) {
    fwrite(STDERR, "Uso: php examples/conciliar-depositos.php [AAAA-MM-DD]\n");
    exit(1);
}
$cents = deposits_of(new RayaClient(), ...$range);
echo "Total del {$day}: \$", number_format($cents / 100, 2, '.', ''), "\n";
