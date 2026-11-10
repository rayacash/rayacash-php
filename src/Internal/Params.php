<?php

declare(strict_types=1);

namespace RayaCash\Internal;

/**
 * @internal Los argumentos y los parámetros de cada método, como checkParams de Node. Lo que no
 * existe falla acá, antes de mandar nada: la API lo rechazaría igual (PARAMETER_UNKNOWN).
 */
final class Params
{
    // Las opciones, también en camelCase: puestas entre los parámetros, se perderían.
    private const OPTIONS = [
        'api_key' => 'api_key',
        'apiKey' => 'api_key',
        'timeout' => 'timeout',
        'max_network_retries' => 'max_network_retries',
        'maxNetworkRetries' => 'max_network_retries',
        'idempotency_key' => 'idempotency_key',
        'idempotencyKey' => 'idempotency_key',
    ];

    private const LIST_METADATA = ', no una lista. Si sus claves son "0", "1"…, PHP la convirtió '
        . 'en una: mandala como (object) $metadata.';

    /** @codeCoverageIgnore No se instancia: solo tiene métodos estáticos. */
    private function __construct() {}

    /**
     * PHP descarta en silencio los argumentos de más: retrieve($id, [], $opciones) mandaría la
     * request sin esas opciones (por ejemplo, con la clave de otra cuenta). Acá fallan.
     *
     * @param array{string}|array{string, string} $names Los del método, con options al final:
     *     ningún método tiene más de dos.
     */
    public static function arguments(string $method, int $given, array $names): void
    {
        $count = count($names);
        if ($given <= $count) {
            return;
        }
        [$noun, $position] = $count === 1
            ? ['argumento', 'el primero']
            : ['argumentos', 'el segundo'];
        throw self::withoutArguments(new \ArgumentCountError(sprintf(
            "%s recibe %d %s (%s), no %d: las opciones van en %s, como ['api_key' => …].",
            $method,
            $count,
            $noun,
            Text::listNames($names),
            $given,
            $position,
        )));
    }

    /**
     * Los parámetros de un método: un array con sus nombres, solo los que existen.
     *
     * @param array<array-key, mixed> $params
     * @param non-empty-list<string> $allowed
     * @return array<array-key, mixed>
     */
    public static function check(
        string $method,
        array $params,
        array $allowed,
        string $example,
    ): array {
        if ($params !== [] && array_is_list($params)) {
            throw self::withoutArguments(new \TypeError(
                "{$method} recibe un array con los nombres de los parámetros, como {$example}, no "
                . 'una lista.',
            ));
        }
        foreach (array_keys($params) as $key) {
            $key = (string) $key;
            if (\in_array($key, $allowed, true)) {
                continue;
            }
            $option = self::OPTIONS[$key] ?? null;
            if ($option !== null) {
                throw self::withoutArguments(new \TypeError(
                    "{$method} no acepta {$key} entre los parámetros: va en las opciones, el "
                    . "último argumento (['{$option}' => …]).",
                ));
            }
            throw self::withoutArguments(new \TypeError(sprintf(
                '%s no acepta "%s"%s: los parámetros son %s.',
                $method,
                $key,
                Text::snakeHint($key, $allowed),
                Text::listNames($allowed),
            )));
        }
        return $params;
    }

    /**
     * La metadata de un pago o de un pago de Connect, siempre como objeto JSON: un [] vacío de PHP
     * sería una lista, y la API la rechaza. Una lista suele ser un error (['8812'] por
     * ['cliente_id' => '8812']), pero PHP también convierte en lista las claves "0", "1"…: el
     * mensaje dice cómo mandarlas. null si no vino.
     */
    public static function metadata(mixed $metadata): ?\stdClass
    {
        if ($metadata === null || $metadata instanceof \stdClass) {
            return $metadata;
        }
        if (is_array($metadata) && ($metadata === [] || !array_is_list($metadata))) {
            return (object) $metadata;
        }
        throw self::withoutArguments(new \TypeError(
            "metadata tiene que ser un array de string a string, como ['cliente_id' => '8812']"
            . (is_array($metadata) ? self::LIST_METADATA : '.'),
        ));
    }

    /**
     * Saca de la traza los argumentos de las llamadas al SDK: un argumento de más o una opción
     * puesta entre los parámetros pueden traer la clave, y #[\SensitiveParameter] no los cubre.
     *
     * @template T of \Error
     * @param T $error
     * @return T
     */
    private static function withoutArguments(\Error $error): \Error
    {
        // El namespace del SDK sale del de esta clase («RayaCash\»), no de un string: PHP-Scoper
        // y Strauss (un plugin de WordPress que lo trae prefijado) cambian los namespaces, pero
        // no un 'RayaCash\' escrito entre comillas.
        $sdk = \substr(__NAMESPACE__, 0, -\strlen('Internal'));
        $trace = [];
        foreach ($error->getTrace() as $frame) {
            if (\str_starts_with($frame['class'] ?? '', $sdk)) {
                unset($frame['args']);
            }
            $trace[] = $frame;
        }
        (new \ReflectionProperty(\Error::class, 'trace'))->setValue($error, $trace);
        return $error;
    }
}
