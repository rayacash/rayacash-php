<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\Hydrator;
use RayaCash\Internal\ObjectToken;
use RayaCash\Internal\RequestOptions;

/**
 * Una página de resultados, del más nuevo al más viejo.
 *
 * `foreach ($page as $item)` recorre `data` (solo esta página). Para recorrer todas, usá
 * `foreach ($page->autoPagingIter() as $item)`: pide las páginas siguientes con los mismos
 * filtros y opciones, de a una y recién cuando hacen falta.
 *
 * @template-covariant T
 * @implements \IteratorAggregate<int, T>
 * @property-read 'list' $object
 * @property-read list<T> $data
 * @property-read bool $has_more
 * @property-read string|null $next_cursor
 */
final class ListObject extends RayaObject implements \IteratorAggregate, \Countable
{
    public const OBJECT_NAME = 'list';

    /** @return \Iterator<int, T> Los elementos de esta página. */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items());
    }

    /** Cuántos elementos tiene esta página. */
    public function count(): int
    {
        return count($this->items());
    }

    /**
     * Los campos se leen por nombre ($page['has_more']); los elementos están en ->data. Lo que
     * no es un nombre ni un int da el TypeError de cualquier objeto.
     */
    public function &offsetGet(mixed $offset): mixed
    {
        if (is_int($offset)) {
            throw new \OutOfBoundsException(
                "Una ListObject no se indexa con {$offset}: los elementos están en ->data (por "
                . "ejemplo, \$page->data[{$offset}]).",
            );
        }
        return parent::offsetGet($offset);
    }

    /**
     * La página siguiente, con los mismos filtros y opciones. Si no hay más, una lista vacía (sin
     * hacer la request).
     *
     * @return ListObject<T>
     */
    public function nextPage(): ListObject
    {
        $next = $this->next();
        if ($next === null) {
            return $this->emptyPage();
        }
        $requestor = $this->resolveRequestor();
        /** @var ListObject<T> $page */
        $page = $requestor->requestList($next['path'], $next['query'], $next['options']);
        return $page;
    }

    /**
     * Todos los elementos, página por página: `foreach ($page->autoPagingIter() as $item)`.
     * Pide cada página recién cuando termina la anterior (cortar el foreach no pide más) y las
     * claves siguen 0, 1, 2… entre páginas, así iterator_to_array() no pisa nada.
     *
     * @return \Generator<int, T, mixed, void>
     */
    public function autoPagingIter(): \Generator
    {
        $page = $this;
        $index = 0;
        while (true) {
            foreach ($page->items() as $item) {
                yield $index++ => $item;
            }
            if ($page->next() === null) {
                break;
            }
            $page = $page->nextPage();
        }
    }

    /** @return list<T> */
    private function items(): array
    {
        $data = $this->rawValue('data');
        /** @var list<T> $items */
        $items = is_array($data) && array_is_list($data) ? $data : [];
        return $items;
    }

    /**
     * Cómo pedir la página siguiente: la misma ruta y las mismas opciones, con el cursor nuevo.
     * null si no hay más (has_more en false o sin next_cursor).
     *
     * @return array{path: string, query: array<string, string>, options: RequestOptions}|null
     */
    private function next(): ?array
    {
        $cursor = $this->rawValue('next_cursor');
        if (!$this->rawValue('has_more') || !is_string($cursor) || $cursor === '') {
            return null;
        }
        $context = ObjectToken::of($this)->context();
        if ($context === null) {
            throw new \LogicException(
                'Esta lista no sabe cómo pedir la página siguiente (no vino de un método list): '
                . "pedila vos con ['cursor' => \$page->next_cursor].",
            );
        }
        $context['query']['cursor'] = $cursor;
        return $context;
    }

    /** @return ListObject<T> */
    private function emptyPage(): ListObject
    {
        $token = ObjectToken::of($this);
        /** @var ListObject<T> $page */
        $page = Hydrator::construct(
            self::class,
            ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null],
            $token->requestor(),
            null,
        );
        $context = $token->context();
        if ($context !== null) {
            ObjectToken::of($page)->setContext(
                $context['path'],
                $context['query'],
                $context['options'],
            );
        }
        return $page;
    }
}
