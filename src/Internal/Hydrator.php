<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\Event;
use RayaCash\RayaObject;
use RayaCash\RayaResponse;

/**
 * @internal Convierte el JSON de la API en objetos del SDK, como convert_to_raya_object de
 * Python: la clase sale de "object" (o del campo, o de lo que esperaba el método) y los campos
 * que son tuyos (metadata) quedan como arrays comunes.
 *
 * RayaObject no deja construir ni cargar sus datos desde afuera (su API pública es
 * constructFrom), así que este armado va por Reflection.
 */
final class Hydrator
{
    private static ?\ReflectionProperty $values = null;

    /**
     * Las constantes INNER_CLASSES y PLAIN_FIELDS de cada clase, ya leídas.
     *
     * @var array<class-string<RayaObject>, array{
     *     inner: array<array-key, class-string<RayaObject>>,
     *     plain: list<string>,
     * }>
     */
    private static array $fields = [];

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * El objeto de una respuesta: la clase sale de "object"; si no, de lo que esperaba el método.
     *
     * @param class-string<RayaObject>|null $expected
     */
    public static function hydrate(
        \stdClass $data,
        Requestor $requestor,
        ?string $expected,
        RayaResponse $response,
    ): RayaObject {
        $class = ObjectTypes::get(get_object_vars($data)['object'] ?? null)
            ?? $expected
            ?? RayaObject::class;
        return self::construct($class, $data, $requestor, $response);
    }

    /** El evento de un webhook ya verificado. */
    public static function event(string $raw, ?Requestor $requestor): Event
    {
        try {
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \ValueError('El body del webhook no es un objeto JSON.', 0, $e);
        }
        if (!$data instanceof \stdClass) {
            throw new \ValueError('El body del webhook no es un objeto JSON.');
        }
        return self::construct(Event::class, $data, $requestor, null);
    }

    /**
     * Arma un objeto de la clase dada y convierte sus campos.
     *
     * @template T of RayaObject
     * @param class-string<T> $class
     * @param array<array-key, mixed>|\stdClass $values
     * @return T
     */
    public static function construct(
        string $class,
        array|\stdClass $values,
        ?Requestor $requestor,
        ?RayaResponse $response,
    ): RayaObject {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        ObjectToken::attach($object, $requestor, $response);

        ['inner' => $inner, 'plain' => $plain] = self::fieldsOf($class);
        $converted = [];
        foreach (self::fields($values) as $key => $value) {
            $converted[$key] = \in_array((string) $key, $plain, true)
                ? self::plain($value)
                : self::convert($value, $requestor, $inner[$key] ?? null);
        }
        self::values()->setValue($object, $converted);
        return $object;
    }

    /**
     * Un valor anidado: los objetos JSON pasan a su clase (o a la del campo, o a RayaObject) y
     * las listas se convierten elemento por elemento.
     *
     * @param class-string<RayaObject>|null $default
     */
    public static function convert(mixed $value, ?Requestor $requestor, ?string $default): mixed
    {
        if ($value instanceof RayaObject) {
            $value = $value->jsonSerialize();
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(
                static fn(mixed $item): mixed => self::convert($item, $requestor, $default),
                $value,
            );
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $fields = self::fields($value);
            $class = ObjectTypes::get($fields['object'] ?? null) ?? $default ?? RayaObject::class;
            return self::construct($class, $fields, $requestor, null);
        }
        return $value;
    }

    /** Arrays comunes, recursivos: lo que devuelve toArray(). */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof RayaObject) {
            return $value->toArray();
        }
        if ($value instanceof \stdClass) {
            return self::plainObject($value);
        }
        return is_array($value)
            ? array_map(static fn(mixed $item): mixed => self::plain($item), $value)
            : $value;
    }

    /** @return array<array-key, mixed> */
    public static function plainObject(\stdClass $value): array
    {
        return array_map(
            static fn(mixed $field): mixed => self::plain($field),
            get_object_vars($value),
        );
    }

    /**
     * @param array<array-key, mixed>|\stdClass $values
     * @return array<array-key, mixed>
     */
    private static function fields(array|\stdClass $values): array
    {
        return $values instanceof \stdClass ? get_object_vars($values) : $values;
    }

    /**
     * Los sub-objetos (INNER_CLASSES) y los campos planos (PLAIN_FIELDS) de una clase.
     *
     * @param class-string<RayaObject> $class
     * @return array{inner: array<array-key, class-string<RayaObject>>, plain: list<string>}
     */
    private static function fieldsOf(string $class): array
    {
        if (!isset(self::$fields[$class])) {
            $reflection = new \ReflectionClass($class);
            $inner = $reflection->getConstant('INNER_CLASSES');
            $plain = $reflection->getConstant('PLAIN_FIELDS');
            self::$fields[$class] = [
                'inner' => array_filter(
                    is_array($inner) ? $inner : [],
                    static fn(mixed $inner): bool => is_string($inner)
                        && is_a($inner, RayaObject::class, true),
                ),
                'plain' => array_values(array_filter(is_array($plain) ? $plain : [], 'is_string')),
            ];
        }
        return self::$fields[$class];
    }

    private static function values(): \ReflectionProperty
    {
        return self::$values ??= new \ReflectionProperty(RayaObject::class, 'values');
    }
}
