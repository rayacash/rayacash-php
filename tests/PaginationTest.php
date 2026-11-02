<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\ListObject;
use RayaCash\RayaCash;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\RecordedRequest;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;

/**
 * La paginación por cursor: nextPage() y autoPagingIter(), un Generator que pide cada página
 * recién cuando hace falta (test_pagination.py, con los extras de pagination.test.ts).
 *
 * Los 4 tests de Python que solo existen para async (async for y mezclar for con async for) no
 * tienen equivalente: PHP es sincrónico.
 */
final class PaginationTest extends TestCase
{
    private const NO_CONTEXT = 'Esta lista no sabe cómo pedir la página siguiente (no vino de un '
        . "método list): pedila vos con ['cursor' => \$page->next_cursor].";

    public function testAutoPagingIterWalksEveryPage(): void
    {
        $this->queueThreePages();
        $page = $this->api->client()->transfers->list(
            ['direction' => 'inbound', 'limit' => 2],
            ['api_key' => Fixtures::OTHER_KEY],
        );
        self::assertSame(['t1', 't2', 't3', 't4', 't5'], self::ids($page->autoPagingIter()));
        self::assertCount(3, $this->api->requests); // al llegar al final no pide otra página
        self::assertSame(
            [
                ['direction' => 'inbound', 'limit' => '2'],
                ['direction' => 'inbound', 'limit' => '2', 'cursor' => 'c1'],
                ['direction' => 'inbound', 'limit' => '2', 'cursor' => 'c2'],
            ],
            $this->queries(),
        );
        // Las opciones de la primera request también viajan en las siguientes.
        self::assertSame(['Bearer ' . Fixtures::OTHER_KEY], $this->authorizations());
    }

    /** «se puede cortar el recorrido a mitad de camino» (Node). */
    public function testTheWalkCanStopHalfway(): void
    {
        $this->queueThreePages();
        $page = $this->api->client()->transfers->list();
        $ids = [];
        foreach ($page->autoPagingIter() as $transfer) {
            $ids[] = $transfer->id;
            if (count($ids) === 3) {
                break;
            }
        }
        self::assertSame(['t1', 't2', 't3'], $ids);
        self::assertCount(2, $this->api->requests);
        self::assertSame(1, $this->api->pending());
    }

    /**
     * De PHP: autoPagingIter() es un Generator perezoso. Armarlo no pide nada, y la página
     * siguiente se pide recién al pasar el último elemento de la anterior.
     */
    public function testAutoPagingIterIsALazyGenerator(): void
    {
        $this->queueThreePages();
        $transfers = $this->api->client()->transfers->list()->autoPagingIter();
        self::assertCount(1, $this->api->requests);
        self::assertSame([0, 't1'], [$transfers->key(), $transfers->current()->id]);
        $transfers->next();
        self::assertSame([1, 't2'], [$transfers->key(), $transfers->current()->id]);
        self::assertCount(1, $this->api->requests); // toda la primera página, sin pedir otra
        $transfers->next();
        self::assertSame([2, 't3'], [$transfers->key(), $transfers->current()->id]);
        self::assertCount(2, $this->api->requests);
    }

    /** De PHP: las claves siguen 0, 1, 2… entre páginas, así iterator_to_array() no pisa nada. */
    public function testAutoPagingIterKeysKeepCountingAcrossPages(): void
    {
        $this->queueThreePages();
        $all = iterator_to_array($this->api->client()->transfers->list()->autoPagingIter());
        self::assertSame([0, 1, 2, 3, 4], array_keys($all));
        self::assertSame(['t1', 't2', 't3', 't4', 't5'], self::ids($all));
    }

    /** De PHP (Q7 del diseño): una página vacía en el medio no corta el recorrido. */
    public function testAutoPagingIterFollowsEmptyPagesInTheMiddle(): void
    {
        $this->api->add(200, Fixtures::transferPage(['t1'], 'c1'));
        $this->api->add(200, Fixtures::transferPage([], 'c2'));
        $this->api->add(200, Fixtures::transferPage(['t2'], null));
        $page = $this->api->client()->transfers->list();
        self::assertSame(['t1', 't2'], self::ids($page->autoPagingIter()));
        self::assertSame([[], ['cursor' => 'c1'], ['cursor' => 'c2']], $this->queries());
    }

    public function testNextPageOnTheLastPageMakesNoRequest(): void
    {
        $this->api->add(200, Fixtures::transferPage(['t1'], null));
        $page = $this->api->client()->transfers->list(['direction' => 'outbound']);
        $empty = $page->nextPage();
        self::assertSame([], iterator_to_array($empty));
        self::assertFalse($empty->has_more);
        self::assertSame(ListObject::class, $empty::class); // el instanceof de Node
        // Python lo repite con next_page_async(); acá no hay async: otra vez nextPage().
        self::assertSame([], $page->nextPage()->data);
        self::assertCount(1, $this->api->requests);
        self::assertSame([], iterator_to_array($empty->autoPagingIter()));
        // Como en Node: la siguiente de la página vacía también está vacía, sin request.
        self::assertSame([], self::ids($empty->nextPage()->autoPagingIter()));
        self::assertCount(1, $this->api->requests);
    }

    /** @return iterable<string, array{mixed}> */
    public static function missingCursors(): iterable
    {
        yield 'next_cursor null' => [null];
        // De PHP, con la misma regla que Python: el cursor tiene que ser un string no vacío.
        yield 'next_cursor vacío' => [''];
        yield 'next_cursor numérico' => [123];
    }

    #[DataProvider('missingCursors')]
    public function testHasMoreWithoutACursorStops(mixed $cursor): void
    {
        $this->api->add(
            200,
            ['object' => 'list', 'data' => [], 'has_more' => true, 'next_cursor' => $cursor],
        );
        $page = $this->api->client()->transfers->list();
        self::assertSame([], iterator_to_array($page->autoPagingIter()));
        self::assertSame([], $page->nextPage()->data);
        self::assertCount(1, $this->api->requests);
    }

    public function testAListWithoutContextCannotFetchMore(): void
    {
        $page = ListObject::constructFrom(Fixtures::transferPage(['t1'], 'c1'));
        // RuntimeError «…pedila vos con cursor=next_cursor.» en Python; acá, un LogicException
        // con la sintaxis de PHP.
        $error = self::assertThrows(
            \LogicException::class,
            static fn() => $page->nextPage(),
            "['cursor' => \$page->next_cursor]",
        );
        self::assertSame(self::NO_CONTEXT, $error->getMessage());
        self::assertSame(['t1'], self::ids($page));

        // De PHP: count() es el de esta página, y autoPagingIter() da t1 y falla igual al ir a
        // buscar la siguiente.
        self::assertCount(1, $page);
        $transfers = $page->autoPagingIter();
        self::assertSame(['t1'], self::ids([$transfers->current()]));
        $error = self::assertThrows(
            \LogicException::class,
            static function () use ($transfers): void {
                $transfers->next();
            },
        );
        self::assertSame(self::NO_CONTEXT, $error->getMessage());
        self::assertSame([], $this->api->requests);
    }

    /** De PHP: una lista del estilo global también pagina, con la configuración global. */
    public function testTheGlobalStyleAlsoPages(): void
    {
        RayaCash::setApiKey(Fixtures::TEST_KEY);
        RayaCash::setHttpClient($this->api);
        $this->queueThreePages();
        $page = Transfer::list(['limit' => 2]);
        self::assertSame(['t1', 't2', 't3', 't4', 't5'], self::ids($page->autoPagingIter()));
        self::assertSame(
            [
                ['limit' => '2'],
                ['limit' => '2', 'cursor' => 'c1'],
                ['limit' => '2', 'cursor' => 'c2'],
            ],
            $this->queries(),
        );
        self::assertSame(['Bearer ' . Fixtures::TEST_KEY], $this->authorizations());
    }

    /**
     * Divergencia declarada: un clon conserva cómo pedir la página siguiente, con las mismas
     * opciones (en Python, copy.copy() de una lista no). serialize() lo deja afuera, como pickle.
     */
    public function testACloneKeepsThePagingContext(): void
    {
        $this->api->add(200, Fixtures::transferPage(['t1'], 'c1'));
        $page = $this->api->client()->transfers->list(
            ['limit' => 1],
            ['api_key' => Fixtures::OTHER_KEY],
        );
        $this->api->add(200, Fixtures::transferPage(['t2'], null));
        $copy = clone $page;
        self::assertSame(['t2'], self::ids($copy->nextPage()));
        self::assertSame(['limit' => '1', 'cursor' => 'c1'], $this->api->last()->query);
        self::assertSame(['Bearer ' . Fixtures::OTHER_KEY], $this->authorizations());

        $restored = unserialize(serialize($page));
        self::assertInstanceOf(ListObject::class, $restored);
        self::assertSame(['t1'], self::ids($restored));
        $error = self::assertThrows(\LogicException::class, static fn() => $restored->nextPage());
        self::assertSame(self::NO_CONTEXT, $error->getMessage());
        self::assertCount(2, $this->api->requests);
    }

    /**
     * La clave de la llamada queda guardada para pedir las páginas siguientes, pero nunca a la
     * vista: ni en los dumps, ni en el JSON, ni serializada (invariante 11 del contrato común de
     * los SDKs de Raya).
     */
    public function testThePerCallKeyOfAPageNeverShowsInDumps(): void
    {
        $this->api->add(200, Fixtures::TRANSFER_LIST);
        $page = $this->api->client()->transfers->list([], ['api_key' => Fixtures::OTHER_KEY]);
        ob_start();
        var_dump($page);
        $dumps = [
            'var_dump' => (string) ob_get_clean(),
            'print_r' => print_r($page, true),
            'var_export' => var_export($page, true),
            'json_encode' => json_encode($page, JSON_THROW_ON_ERROR),
            'serialize' => serialize($page),
            '(array)' => self::everything($page),
        ];
        // Un pedazo del medio: aparece si se filtró la clave entera (no si va enmascarada).
        $secret = substr(Fixtures::OTHER_KEY, 20, 16);
        foreach ($dumps as $how => $dump) {
            self::assertStringNotContainsString($secret, $dump, $how);
        }

        $this->api->add(200, Fixtures::transferPage([], null));
        $page->nextPage();
        self::assertSame(['Bearer ' . Fixtures::OTHER_KEY], $this->authorizations());
    }

    /** Como queue_three_pages() en Python: t1 y t2, t3 y t4, t5 (y no hay más). */
    private function queueThreePages(): void
    {
        $this->api->add(200, Fixtures::transferPage(['t1', 't2'], 'c1'));
        $this->api->add(200, Fixtures::transferPage(['t3', 't4'], 'c2'));
        $this->api->add(200, Fixtures::transferPage(['t5'], null));
    }

    /** @return list<array<string, string>> La query de cada request, decodificada. */
    private function queries(): array
    {
        return array_map(
            static fn(RecordedRequest $request): array => $request->query,
            $this->api->requests,
        );
    }

    /** @return list<string|null> Los Authorization distintos que llegaron (como un set). */
    private function authorizations(): array
    {
        return array_values(array_unique(array_map(
            static fn(RecordedRequest $request): ?string => $request->header('authorization'),
            $this->api->requests,
        )));
    }

    /**
     * Los ids, en orden. Cada elemento tiene que ser un Transfer.
     *
     * @param iterable<mixed> $transfers
     * @return list<string>
     */
    private static function ids(iterable $transfers): array
    {
        $ids = [];
        foreach ($transfers as $transfer) {
            self::assertInstanceOf(Transfer::class, $transfer);
            $ids[] = $transfer->id;
        }
        return $ids;
    }

    /** Todo lo que guarda un valor, recorrido con (array): también lo privado de cada objeto. */
    private static function everything(mixed $value): string
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $text = '';
        foreach ($value as $key => $item) {
            $text .= $key . '=' . self::everything($item) . "\n";
        }
        return $text;
    }
}
