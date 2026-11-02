<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\Encoding;
use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Hydrator;
use RayaCash\Internal\ObjectToken;
use RayaCash\Internal\Requestor;
use RayaCash\Internal\Text;

/**
 * Un objeto de la API, con los valores tal como vienen en su JSON (montos int o float, fechas en
 * string ISO). `$obj->campo` y `$obj['campo']` dan lo mismo. Los campos que el SDK todavía no
 * conoce también están: la API puede sumar campos y tu código tiene que ignorarlos.
 *
 * Leer un campo que no existe es un error (\OutOfBoundsException), como el AttributeError de
 * Python. Para uno que puede faltar, `$obj->campo ?? null` o `isset($obj->campo)`, que nunca
 * fallan. `json_encode($obj)` da el JSON exacto de la API (con el serialize_precision de
 * fábrica, -1); `toArray()`, arrays comunes.
 *
 * @implements \ArrayAccess<array-key, mixed>
 */
class RayaObject implements \ArrayAccess, \JsonSerializable
{
    /** El valor de "object" que identifica a la clase ('payment', 'transfer'…). */
    public const OBJECT_NAME = null;

    /** @var array<string, class-string<RayaObject>> Sub-objetos sin "object" propio. */
    protected const INNER_CLASSES = [];

    /** @var list<string> Campos que quedan como array común: sus claves son tuyas (metadata). */
    protected const PLAIN_FIELDS = [];

    /**
     * Lo que __toString le deja a json_encode (los strings y lo que no es del SDK), como
     * json.dumps con ensure_ascii=False: también U+2028 y U+2029 tal cual.
     */
    private const TEXT_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** @var array<array-key, mixed> */
    private array $values = [];

    // El cliente del que vino, la respuesta y el contexto de paginación, fuera de los datos.
    private ObjectToken $token;

    /**
     * @codeCoverageIgnore No corre: Hydrator arma los objetos sin el constructor. Es protegido
     * para que nadie haga new Payment(): los objetos salen de la API o de constructFrom().
     */
    final protected function __construct()
    {
        $this->token = new ObjectToken();
    }

    /**
     * Arma el objeto (y sus sub-objetos) desde el JSON de la API, sin hacer ninguna request.
     * Sirve para tus tests: `Payment::constructFrom(['object' => 'payment', 'id' => '…'])`.
     *
     * @param array<array-key, mixed>|\stdClass $values
     */
    public static function constructFrom(array|\stdClass $values): static
    {
        if (is_array($values) && $values !== [] && array_is_list($values)) {
            throw new \TypeError(
                'constructFrom necesita un objeto JSON (un array asociativo o un stdClass), no '
                . 'una lista.',
            );
        }
        return Hydrator::construct(static::class, $values, null, null);
    }

    /** La respuesta HTTP que trajo este objeto (requestId, headers…); null si lo armaste vos. */
    public function getLastResponse(): ?RayaResponse
    {
        return $this->token->response();
    }

    /**
     * Arrays comunes y recursivos, sin las clases del SDK. Un objeto vacío ({}) queda como [], y
     * uno con claves "0", "1"…, como lista: PHP no los distingue.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return array_map(static fn(mixed $value): mixed => Hydrator::plain($value), $this->values);
    }

    /**
     * El JSON exacto de la API: con json_encode($obj), {} sigue siendo {} (salvo en una metadata
     * fuera del contrato, que no sea un objeto de strings). Los floats los escribe json_encode
     * según serialize_precision: el de fábrica, -1, da los mismos que la API.
     */
    public function jsonSerialize(): \stdClass
    {
        $fields = [];
        foreach ($this->values as $key => $value) {
            $fields[$key] = is_array($value) && \in_array((string) $key, static::PLAIN_FIELDS, true)
                ? (object) $value
                : self::json($value);
        }
        return (object) $fields;
    }

    /**
     * El valor del campo. Vuelve por referencia, así `$payment->metadata['x'] = '1'` cambia el
     * objeto, como en Python y Node.
     */
    public function &__get(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new \OutOfBoundsException($this->missing($name));
        }
        return $this->values[$name];
    }

    /** Como isset() en un array: un campo en null cuenta como ausente (así ?? nunca falla). */
    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /** Los objetos se pueden modificar, como en Python y Node; el valor se guarda tal cual. */
    public function __set(string $name, mixed $value): void
    {
        $this->values[$name] = $value;
    }

    public function __unset(string $name): void
    {
        unset($this->values[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$this->key($offset)]);
    }

    /** Lo mismo que $obj->campo (también por referencia). */
    public function &offsetGet(mixed $offset): mixed
    {
        $key = $this->key($offset);
        if (!array_key_exists($key, $this->values)) {
            throw new \OutOfBoundsException($this->missing($key));
        }
        return $this->values[$key];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \TypeError(
                Text::shortName(static::class) . ' no acepta $obj[] = …: poné el nombre del '
                . "campo, como \$obj['campo'] = \$valor.",
            );
        }
        $this->values[$this->key($offset)] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$this->key($offset)]);
    }

    /**
     * JSON con las claves ordenadas y 2 espacios de sangría, como str() en Python. Los floats
     * salen como en json.dumps (1500.1, 25000.0, 1e-07), sin depender de serialize_precision.
     */
    public function __toString(): string
    {
        return self::pretty($this, "\n");
    }

    /**
     * var_dump y print_r muestran solo los datos: ni el cliente ni la respuesta.
     *
     * @return array<array-key, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->values;
    }

    /**
     * Se serializa sin el cliente ni la respuesta, como pickle en Python: un objeto
     * deserializado usa el estilo global (por ejemplo, en $payment->refund()).
     *
     * @return array{values: array<array-key, mixed>}
     */
    public function __serialize(): array
    {
        return ['values' => $this->values];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        $this->token = new ObjectToken();
        $values = $data['values'] ?? null;
        $this->values = is_array($values) ? $values : [];
    }

    /** Un clon conserva el cliente y la respuesta (como copy); sus sub-objetos son copias. */
    public function __clone()
    {
        $this->values = self::cloned($this->values);
    }

    /** El valor de un campo, o null si no está (sin el error de __get). */
    final protected function rawValue(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /** El requestor del cliente del que vino este objeto; si no, el del estilo global. */
    final protected function resolveRequestor(): Requestor
    {
        return $this->token->requestor() ?? GlobalState::requestor();
    }

    private function key(mixed $offset): int|string
    {
        if (is_int($offset) || is_string($offset)) {
            return $offset;
        }
        throw new \TypeError(
            'Los campos de ' . Text::shortName(static::class) . ' se leen con su nombre, como '
            . "\$obj['status'], no con " . get_debug_type($offset) . '.',
        );
    }

    /** «Payment no tiene el campo 'statuss'. Campos: amount, …», como AttributeError en Python. */
    private function missing(int|string $name): string
    {
        $fields = array_map(
            static fn(int|string $key): string => (string) $key,
            array_keys($this->values),
        );
        sort($fields, SORT_STRING);
        return sprintf(
            "%s no tiene el campo '%s'. Campos: %s.",
            Text::shortName(static::class),
            $name,
            $fields === [] ? '(ninguno)' : \implode(', ', $fields),
        );
    }

    private static function json(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->jsonSerialize();
        }
        return is_array($value)
            ? array_map(static fn(mixed $item): mixed => self::json($item), $value)
            : $value;
    }

    /**
     * Un valor de __toString, con las claves ordenadas en todos los niveles (sort_keys).
     * $newline es el salto de línea con la sangría de su nivel.
     */
    private static function pretty(mixed $value, string $newline): string
    {
        if ($value instanceof self) {
            $value = $value->jsonSerialize();
        }
        if (is_float($value)) {
            return Encoding::floatRepr($value);
        }
        if (!is_array($value) && !$value instanceof \stdClass) {
            // null, bool, int, string o un objeto que no es del SDK: lo escribe json_encode, que
            // sangra con 4 espacios (un salto de línea en un string sale como \n).
            return (string) preg_replace_callback(
                '/\n((?: {4})*)/',
                static fn(array $match): string
                    => $newline . str_repeat('  ', intdiv(strlen($match[1]), 4)),
                (string) json_encode($value, self::TEXT_FLAGS | JSON_PRETTY_PRINT),
            );
        }
        $object = !is_array($value) || !array_is_list($value);
        $fields = is_array($value) ? $value : get_object_vars($value);
        if ($fields === []) {
            return $object ? '{}' : '[]';
        }
        if ($object) {
            ksort($fields, SORT_STRING);
        }
        $inner = $newline . '  ';
        $items = [];
        foreach ($fields as $key => $item) {
            $items[] = ($object ? json_encode((string) $key, self::TEXT_FLAGS) . ': ' : '')
                . self::pretty($item, $inner);
        }
        return ($object ? '{' : '[') . $inner . \implode(',' . $inner, $items) . $newline
            . ($object ? '}' : ']');
    }

    /**
     * Un array nuevo, valor por valor: copiar el array le pasaría al clon los campos que alguien
     * tiene por referencia ($ref = &$payment->metadata), y el clon escribiría en el original.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function cloned(array $values): array
    {
        $copy = [];
        foreach ($values as $key => $value) {
            if ($value instanceof self) {
                $value = clone $value;
            } elseif (is_array($value)) {
                $value = self::cloned($value);
            }
            $copy[$key] = $value;
        }
        return $copy;
    }
}
