<?php

declare(strict_types=1);

// Arranque de PHPUnit. Ningún test puede llamar a la API real: con una clave real, cada request
// mueve plata. Tres capas, de adentro hacia afuera:
//
// 1. El cliente HTTP propio del SDK sale de una fábrica que falla (Support\TestCase::isolate()):
//    los tests usan MockApi, y TransportTest le pasa su CurlClient a un servidor en 127.0.0.1.
// 2. phpunit.xml.dist apunta los proxies de libcurl a 127.0.0.1:9: un curl que se escape no llega.
// 3. scripts/dev.sh corre los tests con --network none, y el CI con scripts/offline.sh.
//
// Y nada del entorno de quien corre los tests: sin RAYA_SECRET_KEY ni RAYA_LOG (cada test las
// vuelve a borrar en su setUp() y su tearDown()).

require dirname(__DIR__) . '/vendor/autoload.php';

RayaCash\Tests\Support\TestCase::isolate();
