<?php

declare(strict_types=1);

namespace RayaCash\Internal;

use RayaCash\RayaObject;
use RayaCash\RayaResponse;

/**
 * @internal Lo que acompaña a un RayaObject sin ser parte de sus datos: el requestor del cliente
 * del que vino, la respuesta HTTP y, en las listas, cómo pedir la página siguiente.
 *
 * Vive en un WeakMap estático y este token vacío es la clave: así var_export, (array), serialize
 * y los dumps de los frameworks ven solo los datos, y == compara solo los datos. Un clon comparte
 * el token (conserva el cliente, como copy en Python); uno deserializado recibe uno nuevo, vacío
 * (como pickle).
 *
 * @phpstan-type PageContext array{
 *     path: string,
 *     query: array<string, string>,
 *     options: RequestOptions,
 * }
 * @phpstan-type Meta array{
 *     requestor: Requestor|null,
 *     response: RayaResponse|null,
 *     context: PageContext|null,
 * }
 */
final class ObjectToken
{
    /** @var \WeakMap<self, Meta>|null */
    private static ?\WeakMap $meta = null;

    private static ?\ReflectionProperty $property = null;

    /** El token de un objeto (su propiedad privada, que la API pública no expone). */
    public static function of(RayaObject $object): self
    {
        $token = self::property()->getValue($object);
        assert($token instanceof self);
        return $token;
    }

    /** Le pone un token nuevo a un objeto armado sin su constructor (lo usa Hydrator). */
    public static function attach(
        RayaObject $object,
        ?Requestor $requestor,
        ?RayaResponse $response,
    ): void {
        $token = new self();
        $token->bind($requestor, $response);
        self::property()->setValue($object, $token);
    }

    public function bind(?Requestor $requestor, ?RayaResponse $response): void
    {
        if ($requestor === null && $response === null && !isset(self::meta()[$this])) {
            return;
        }
        $meta = $this->read();
        $meta['requestor'] = $requestor;
        $meta['response'] = $response;
        self::meta()[$this] = $meta;
    }

    public function requestor(): ?Requestor
    {
        return $this->read()['requestor'];
    }

    public function response(): ?RayaResponse
    {
        return $this->read()['response'];
    }

    /** @param array<string, string> $query */
    public function setContext(string $path, array $query, RequestOptions $options): void
    {
        $meta = $this->read();
        $meta['context'] = ['path' => $path, 'query' => $query, 'options' => $options];
        self::meta()[$this] = $meta;
    }

    /** @return PageContext|null */
    public function context(): ?array
    {
        return $this->read()['context'];
    }

    /** @return Meta */
    private function read(): array
    {
        return self::meta()[$this] ?? ['requestor' => null, 'response' => null, 'context' => null];
    }

    private static function property(): \ReflectionProperty
    {
        return self::$property ??= new \ReflectionProperty(RayaObject::class, 'token');
    }

    /** @return \WeakMap<self, Meta> */
    private static function meta(): \WeakMap
    {
        return self::$meta ??= new \WeakMap();
    }
}
