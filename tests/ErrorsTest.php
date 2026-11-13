<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use RayaCash\Exception\ApiConnectionException;
use RayaCash\Exception\ApiException;
use RayaCash\Exception\AuthenticationException;
use RayaCash\Exception\ConflictException;
use RayaCash\Exception\IdempotencyException;
use RayaCash\Exception\InvalidRequestException;
use RayaCash\Exception\NotFoundException;
use RayaCash\Exception\PermissionDeniedException;
use RayaCash\Exception\RateLimitException;
use RayaCash\Exception\RayaException;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Exception\UnprocessableEntityException;
use RayaCash\HttpClient\Headers;
use RayaCash\HttpClient\TransportException;
use RayaCash\Internal\ErrorFactory;
use RayaCash\Internal\Retry;
use RayaCash\Tests\Support\TestCase;

/**
 * Los errores: la clase sale del status HTTP y el código, del body (test_errors.py). Las clases
 * se llaman *Exception (*Error en Python), el código está en getErrorCode() y getCode() es el
 * status HTTP.
 */
final class ErrorsTest extends TestCase
{
    /**
     * Los 51 códigos del contrato (api.md › Códigos de error), con los de Identidad
     * (IDENTITY_NOT_ACTIVATED al final de los 403) y los del sandbox.
     */
    private const CATALOG = [
        ['INVALID_JSON', 400, InvalidRequestException::class],
        ['PARAMETER_MISSING', 400, InvalidRequestException::class],
        ['PARAMETER_INVALID', 400, InvalidRequestException::class],
        ['PARAMETER_UNKNOWN', 400, InvalidRequestException::class],
        ['OAUTH_GRANT_INVALID', 400, InvalidRequestException::class],
        ['OAUTH_GRANT_TYPE_UNSUPPORTED', 400, InvalidRequestException::class],
        ['API_KEY_MISSING', 401, AuthenticationException::class],
        ['API_KEY_INVALID', 401, AuthenticationException::class],
        ['API_KEY_PUBLISHABLE_NOT_ALLOWED', 401, AuthenticationException::class],
        ['API_KEY_EXPIRED', 401, AuthenticationException::class],
        ['ACCESS_TOKEN_MISSING', 401, AuthenticationException::class],
        ['ACCESS_TOKEN_INVALID', 401, AuthenticationException::class],
        ['ACCESS_TOKEN_REVOKED', 401, AuthenticationException::class],
        ['OAUTH_CLIENT_INVALID', 401, AuthenticationException::class],
        ['API_KEY_ACCOUNT_LOCKED', 403, PermissionDeniedException::class],
        ['API_NOT_ENABLED', 403, PermissionDeniedException::class],
        ['MERCHANT_NOT_CONFIGURED', 403, PermissionDeniedException::class],
        ['TRANSFERS_BLOCKED', 403, PermissionDeniedException::class],
        ['CONNECTED_ACCOUNT_LOCKED', 403, PermissionDeniedException::class],
        ['CONNECTED_ACCOUNT_NOT_CONFIGURED', 403, PermissionDeniedException::class],
        ['APP_DISABLED', 403, PermissionDeniedException::class],
        ['PAYOUT_ORIGIN_UNAVAILABLE', 403, PermissionDeniedException::class],
        ['SANDBOX_NOT_SUPPORTED', 403, PermissionDeniedException::class],
        ['SANDBOX_ONLY', 403, PermissionDeniedException::class],
        ['IDENTITY_NOT_ACTIVATED', 403, PermissionDeniedException::class],
        ['TRANSFER_NOT_FOUND', 404, NotFoundException::class],
        ['PAYMENT_NOT_FOUND', 404, NotFoundException::class],
        ['USER_NOT_FOUND', 404, NotFoundException::class],
        ['ORDER_NOT_FOUND', 404, NotFoundException::class],
        ['NOT_FOUND', 404, NotFoundException::class],
        ['PAYOUT_NOT_FOUND', 404, NotFoundException::class],
        ['VERIFICATION_NOT_FOUND', 404, NotFoundException::class],
        ['METHOD_NOT_ALLOWED', 405, InvalidRequestException::class],
        ['IDEMPOTENCY_KEY_REUSED', 409, IdempotencyException::class],
        ['IDEMPOTENCY_KEY_IN_USE', 409, IdempotencyException::class],
        ['PAYMENT_NOT_REFUNDABLE', 409, ConflictException::class],
        ['PAYMENT_NOT_PENDING', 409, ConflictException::class],
        ['VERIFICATION_NOT_CANCELABLE', 409, ConflictException::class],
        ['REQUEST_TOO_LARGE', 413, InvalidRequestException::class],
        ['INSUFFICIENT_BALANCE', 422, UnprocessableEntityException::class],
        ['LIMIT_EXCEEDED', 422, UnprocessableEntityException::class],
        ['DESTINATION_NOT_FOUND', 422, UnprocessableEntityException::class],
        ['DESTINATION_INACTIVE', 422, UnprocessableEntityException::class],
        ['DESTINATION_NOT_SUPPORTED', 422, UnprocessableEntityException::class],
        ['REFUND_FAILED', 422, UnprocessableEntityException::class],
        ['RATE_LIMITED', 429, RateLimitException::class],
        ['INTERNAL_ERROR', 500, ApiException::class],
        ['BANK_UNAVAILABLE', 503, ApiException::class],
        ['CVU_UNAVAILABLE', 503, ApiException::class],
        ['API_UNAVAILABLE', 503, ApiException::class],
        ['TEMPORARILY_UNAVAILABLE', 503, ApiException::class],
    ];

    private const PARAMETER_INVALID = 'amount tiene que ser un número con hasta 2 decimales.';

    public function testCatalogHasThe51Codes(): void
    {
        self::assertCount(51, array_unique(array_column(self::CATALOG, 0)));
    }

    /** @return iterable<string, array{string, int, class-string<RayaException>}> */
    public static function catalog(): iterable
    {
        foreach (self::CATALOG as [$code, $status, $class]) {
            yield $code => [$code, $status, $class];
        }
    }

    /** @param class-string<RayaException> $class */
    #[DataProvider('catalog')]
    public function testEveryCodeMapsToItsClass(string $code, int $status, string $class): void
    {
        $error = self::makeError(
            $status,
            ['error' => ['code' => $code, 'message' => 'Texto.', 'data' => null]],
        );
        self::assertSame($class, $error::class);
        self::assertInstanceOf(RayaException::class, $error);
        // Como instanceof Error en Node: un catch (\Exception) también los atrapa.
        self::assertInstanceOf(\Exception::class, $error);
        self::assertSame($code, $error->getErrorCode());
        self::assertSame($status, $error->getHttpStatus());
        self::assertSame($status, $error->getCode()); // en PHP, getCode() es el status HTTP
        self::assertSame('Texto.', $error->getMessage());
        self::assertSame('req_abc', $error->getRequestId());
    }

    public function testErrorAttributes(): void
    {
        $body = [
            'error' => [
                'code' => 'PARAMETER_INVALID',
                'message' => self::PARAMETER_INVALID,
                'data' => ['field' => 'amount'],
            ],
        ];
        $error = self::makeError(400, $body, ['Retry-After' => '3'], 'key-1');
        self::assertInstanceOf(InvalidRequestException::class, $error);
        self::assertSame('amount', $error->getParam());
        self::assertSame(['field' => 'amount'], $error->getData());
        self::assertSame($body, $error->getJsonBody());
        self::assertSame(json_encode($body, JSON_THROW_ON_ERROR), $error->getHttpBody());
        self::assertSame('req_abc', $error->getHttpHeaders()['raya-request-id']);
        self::assertSame('3', $error->getHttpHeaders()->get('RETRY-AFTER'));
        self::assertSame('key-1', $error->getIdempotencyKey());
        self::assertSame(3.0, $error->getRetryAfter());
        self::assertSame($error->getMessage(), $error->getUserMessage());
        self::assertSame(self::PARAMETER_INVALID, $error->getMessage()); // el mensaje, crudo
        // str(error) en Python: acá es el mensaje de (string) $error, con el formato de PHP.
        self::assertSame(
            self::shown(
                $error,
                'Request req_abc: amount tiene que ser un número con hasta 2 decimales. '
                    . '(PARAMETER_INVALID)',
            ),
            (string) $error,
        );
        // repr(error) en Python (y lo que muestra console.log en Node): var_dump y print_r
        // muestran lo útil, sin los headers ni el body.
        self::assertSame(
            [
                'message' => self::PARAMETER_INVALID,
                'error_code' => 'PARAMETER_INVALID',
                'http_status' => 400,
                'request_id' => 'req_abc',
                'idempotency_key' => 'key-1',
                'retry_after' => 3.0,
                'data' => ['field' => 'amount'],
            ],
            $error->__debugInfo(),
        );
        self::assertSame(
            InvalidRequestException::class . " Object\n(\n"
                . '    [message] => ' . self::PARAMETER_INVALID . "\n"
                . "    [error_code] => PARAMETER_INVALID\n"
                . "    [http_status] => 400\n"
                . "    [request_id] => req_abc\n"
                . "    [idempotency_key] => key-1\n"
                . "    [retry_after] => 3\n"
                . "    [data] => Array\n        (\n            [field] => amount\n        )\n\n"
                . ")\n",
            print_r($error, true),
        );
        // Lo que Laravel suma al log de la excepción (solo de PHP).
        self::assertSame(
            ['error_code' => 'PARAMETER_INVALID', 'request_id' => 'req_abc', 'http_status' => 400],
            $error->context(),
        );
    }

    public function testParamIsNoneWithoutField(): void
    {
        $error = self::makeError(
            400,
            ['error' => ['code' => 'INVALID_JSON', 'message' => 'x', 'data' => null]],
        );
        self::assertInstanceOf(InvalidRequestException::class, $error);
        self::assertNull($error->getParam());
        // Como param en Python: un field que no es un string tampoco cuenta.
        $error = self::makeError(400, [
            'error' => ['code' => 'PARAMETER_INVALID', 'message' => 'x', 'data' => ['field' => 1]],
        ]);
        self::assertInstanceOf(InvalidRequestException::class, $error);
        self::assertSame(['field' => 1], $error->getData());
        self::assertNull($error->getParam());
    }

    public function testPaymentNotRefundableWithAndWithoutReason(): void
    {
        $withReason = self::makeError(409, [
            'error' => [
                'code' => 'PAYMENT_NOT_REFUNDABLE',
                'message' => 'x',
                'data' => ['status' => 'succeeded', 'reason' => 'refund_window_expired'],
            ],
        ]);
        self::assertSame(
            ['status' => 'succeeded', 'reason' => 'refund_window_expired'],
            $withReason->getData(),
        );
        $without = self::makeError(409, [
            'error' => [
                'code' => 'PAYMENT_NOT_REFUNDABLE',
                'message' => 'x',
                'data' => ['status' => 'refunded'],
            ],
        ]);
        self::assertSame(ConflictException::class, $without::class);
        self::assertSame(['status' => 'refunded'], $without->getData());
    }

    /** Identidad: el estado de la verificación que ya no se puede cancelar, en getData(). */
    public function testVerificationNotCancelableCarriesTheStatus(): void
    {
        $error = self::makeError(409, [
            'error' => [
                'code' => 'VERIFICATION_NOT_CANCELABLE',
                'message' => 'Solo podés cancelar verificaciones pending o in_progress.',
                'data' => ['status' => 'processing'],
            ],
        ]);
        self::assertSame(ConflictException::class, $error::class);
        self::assertSame('VERIFICATION_NOT_CANCELABLE', $error->getErrorCode());
        self::assertSame(['status' => 'processing'], $error->getData());
    }

    /** El tope de verificaciones de Identidad: RATE_LIMITED con data.limit y Retry-After. */
    public function testTheVerificationLimitCarriesItsLimitAndRetryAfter(): void
    {
        $body = ['code' => 'RATE_LIMITED', 'message' => 'x', 'data' => ['limit' => 'open']];
        $error = self::makeError(429, ['error' => $body], ['Retry-After' => '3600']);
        self::assertSame(RateLimitException::class, $error::class);
        self::assertSame(3600.0, $error->getRetryAfter());
        self::assertSame(['limit' => 'open'], $error->getData());
    }

    /** @return iterable<string, array{int, string, class-string<RayaException>}> */
    public static function unknownCodes(): iterable
    {
        yield '422 NEW_BUSINESS_RULE' => [
            422,
            'NEW_BUSINESS_RULE',
            UnprocessableEntityException::class,
        ];
        yield '409 NEW_CONFLICT' => [409, 'NEW_CONFLICT', ConflictException::class];
        yield '409 IDEMPOTENCY_KEY_NEW' => [
            409,
            'IDEMPOTENCY_KEY_NEW',
            IdempotencyException::class,
        ];
        yield '418 TEAPOT' => [418, 'TEAPOT', InvalidRequestException::class];
        yield '410 GONE' => [410, 'GONE', InvalidRequestException::class];
        yield '599 WEIRD' => [599, 'WEIRD', ApiException::class];
        yield '302 MOVED' => [302, 'MOVED', ApiException::class];
    }

    /** @param class-string<RayaException> $class */
    #[DataProvider('unknownCodes')]
    public function testUnknownCodesFollowTheHttpStatus(
        int $status,
        string $code,
        string $class,
    ): void {
        $error = self::makeError(
            $status,
            ['error' => ['code' => $code, 'message' => 'x', 'data' => null]],
        );
        self::assertSame($class, $error::class);
        self::assertSame($code, $error->getErrorCode());
    }

    /** @return iterable<string, array{int, mixed, class-string<RayaException>, string, string}> */
    public static function responsesWithoutTheErrorObject(): iterable
    {
        $internal = static fn(int $status): string => 'Falló algo del lado de Raya (HTTP '
            . "{$status}). Reintentá en unos minutos; en los POST, con la misma Idempotency-Key.";
        $unexpected = static fn(int $status): string => 'La API respondió algo inesperado (HTTP '
            . "{$status}).";
        yield '502 HTML' => [
            502,
            '<html>Bad gateway</html>',
            ApiException::class,
            'INTERNAL_ERROR',
            $internal(502),
        ];
        yield '504 vacío' => [504, '', ApiException::class, 'INTERNAL_ERROR', $internal(504)];
        yield '408 texto' => [
            408,
            'timeout',
            ApiException::class,
            'INTERNAL_ERROR',
            $internal(408),
        ];
        yield '429 HTML' => [
            429,
            '<html>Cloudflare</html>',
            RateLimitException::class,
            'RATE_LIMITED',
            'Superaste el límite de requests. Esperá los segundos que indica Retry-After y '
                . 'reintentá.',
        ];
        yield '404 HTML' => [
            404,
            '<h1>Not Found</h1>',
            NotFoundException::class,
            'INVALID_RESPONSE',
            $unexpected(404),
        ];
        yield '403 otro JSON' => [
            403,
            ['detail' => 'CSRF'],
            PermissionDeniedException::class,
            'INVALID_RESPONSE',
            $unexpected(403),
        ];
        yield '400 error que no es un objeto' => [
            400,
            ['error' => 'texto'],
            InvalidRequestException::class,
            'INVALID_RESPONSE',
            $unexpected(400),
        ];
        yield '500 lista' => [
            500,
            ['no', 'es', 'un', 'objeto'],
            ApiException::class,
            'INTERNAL_ERROR',
            $internal(500),
        ];
    }

    /** @param class-string<RayaException> $class */
    #[DataProvider('responsesWithoutTheErrorObject')]
    public function testResponsesWithoutTheErrorObject(
        int $status,
        mixed $body,
        string $class,
        string $code,
        string $message,
    ): void {
        $error = self::makeError($status, $body);
        self::assertSame($class, $error::class);
        self::assertSame($code, $error->getErrorCode());
        // Python solo pide que haya un mensaje en castellano; acá, el texto exacto de _fallback().
        self::assertSame($message, $error->getMessage());
        self::assertNull($error->getData());
    }

    public function testMessageFallsBackWhenCodeIsMissing(): void
    {
        $error = self::makeError(
            500,
            ['error' => ['message' => 'Algo falló.', 'data' => ['x' => 1]]],
        );
        self::assertSame('INTERNAL_ERROR', $error->getErrorCode());
        self::assertSame('Algo falló.', $error->getMessage());
        self::assertSame(['x' => 1], $error->getData());
    }

    /**
     * Como error_from_response en Python: con el código y sin un message de texto, el mensaje
     * queda vacío (str() muestra «<sin mensaje>»); sin el código, un message vacío no cuenta
     * («message or fallback_message») y va el de _fallback().
     */
    public function testMessagesThatAreMissingOrEmpty(): void
    {
        foreach ([['code' => 'NEW_RULE'], ['code' => 'NEW_RULE', 'message' => 42]] as $envelope) {
            $error = self::makeError(422, ['error' => $envelope]);
            self::assertSame(UnprocessableEntityException::class, $error::class);
            self::assertSame('NEW_RULE', $error->getErrorCode());
            self::assertSame('', $error->getMessage());
            self::assertSame(
                self::shown($error, 'Request req_abc: <sin mensaje> (NEW_RULE)'),
                (string) $error,
            );
        }
        $error = self::makeError(502, ['error' => ['message' => '']]);
        self::assertSame('INTERNAL_ERROR', $error->getErrorCode());
        self::assertSame(
            'Falló algo del lado de Raya (HTTP 502). Reintentá en unos minutos; en los POST, con '
                . 'la misma Idempotency-Key.',
            $error->getMessage(),
        );
    }

    public function testStrWithoutRequestIdOrCode(): void
    {
        // str() en Python da solo el resumen; (string) en PHP lo pone en su formato de siempre
        // (como el «RayaError: » de String() en Node). Es el mismo que el de \Exception:
        $native = new \Exception('Hola');
        self::assertSame(self::shown($native, 'Hola'), (string) $native);

        $error = new RayaException('Hola');
        self::assertSame(self::shown($error, 'Hola'), (string) $error);
        $error = new RayaException();
        self::assertSame(self::shown($error, '<sin mensaje>'), (string) $error);
        $error = new RayaException('Hola', errorCode: 'X');
        self::assertSame(self::shown($error, 'Hola (X)'), (string) $error);
    }

    public function testConnectionAndSignatureErrors(): void
    {
        $cause = TransportException::network('curl 7: Failed to connect');
        $error = new ApiConnectionException(
            'Sin red.',
            errorCode: 'NETWORK_ERROR',
            previous: $cause,
            shouldRetry: true,
        );
        self::assertTrue($error->shouldRetry());
        self::assertNull($error->getHttpStatus());
        self::assertSame(0, $error->getCode()); // sin respuesta no hay status
        // Node: la causa queda en cause (acá, getPrevious()) y los headers vienen vacíos.
        self::assertSame($cause, $error->getPrevious());
        self::assertNull($error->getHttpHeaders()->get('raya-request-id'));
        self::assertCount(0, $error->getHttpHeaders());
        // Como toda excepción de PHP con una anterior: primero la causa, después «Next».
        self::assertSame(
            $cause . "\n\nNext " . self::shown($error, 'Sin red. (NETWORK_ERROR)'),
            (string) $error,
        );

        $signature = new SignatureVerificationException('Mala firma.', 't=1,v1=x', '{}');
        self::assertSame('t=1,v1=x', $signature->getSigHeader());
        self::assertSame('{}', $signature->getHttpBody());
        self::assertNull($signature->getErrorCode());
        self::assertInstanceOf(RayaException::class, $signature);
        self::assertSame('Mala firma.', $signature->getMessage());
    }

    public function testErrorsSurvivePickle(): void
    {
        $error = unserialize(serialize(new ApiException('Falló.')));
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame('Falló.', $error->getMessage());
    }

    // --- Solo de PHP ------------------------------------------------------------------------

    /** Lo de pickle, completo: serialize conserva todo menos la traza y la excepción anterior. */
    public function testSerializeKeepsEverythingButTheTraceAndThePreviousException(): void
    {
        $body = [
            'error' => [
                'code' => 'PARAMETER_INVALID',
                'message' => self::PARAMETER_INVALID,
                'data' => ['field' => 'amount'],
            ],
        ];
        $original = self::makeError(400, $body, ['Retry-After' => '3'], 'key-1');
        $error = unserialize(serialize($original));
        self::assertInstanceOf(InvalidRequestException::class, $error);
        self::assertSame(InvalidRequestException::class, $error::class);
        self::assertSame($original->__debugInfo(), $error->__debugInfo());
        self::assertSame(400, $error->getCode());
        self::assertSame('amount', $error->getParam());
        self::assertSame($body, $error->getJsonBody());
        self::assertSame($original->getHttpBody(), $error->getHttpBody());
        self::assertSame(
            ['raya-request-id' => 'req_abc', 'retry-after' => '3'],
            $error->getHttpHeaders()->toArray(),
        );
        self::assertSame([$original->getFile(), $original->getLine()], [
            $error->getFile(),
            $error->getLine(),
        ]);

        $cause = TransportException::timeout('curl 28: Operation timed out');
        $connection = new ApiConnectionException(
            'La API de Raya no respondió a tiempo.',
            errorCode: 'TIMEOUT',
            idempotencyKey: 'k-1',
            previous: $cause,
            shouldRetry: true,
        );
        $serialized = serialize($connection);
        self::assertStringNotContainsString('curl 28', $serialized);
        self::assertStringNotContainsString(__FUNCTION__, $serialized); // ni la traza
        $restored = unserialize($serialized);
        self::assertInstanceOf(ApiConnectionException::class, $restored);
        self::assertTrue($restored->shouldRetry());
        self::assertSame(['TIMEOUT', 'k-1', null], [
            $restored->getErrorCode(),
            $restored->getIdempotencyKey(),
            $restored->getPrevious(),
        ]);

        $signature = unserialize(serialize(new SignatureVerificationException('x', 't=1', '{}')));
        self::assertInstanceOf(SignatureVerificationException::class, $signature);
        self::assertSame(['t=1', '{}'], [$signature->getSigHeader(), $signature->getHttpBody()]);
    }

    /**
     * Un error serializado por otra versión del SDK, o tocado a mano, vuelve sin fallar. Lo que
     * falta queda vacío. Con datos de otro tipo, cada getter devuelve el tipo que declara (PHP lo
     * hace cumplir: si no pudiera, fields() fallaría) y queda vacío lo que no tiene cómo leerse
     * así; que el resto se convierta o no ('12' en 12) no importa.
     */
    public function testUnserializeFillsInWhatIsMissingOrWrong(): void
    {
        $wrong = [
            'message' => 42,
            'line' => '12',
            'error_code' => 7,
            'data' => 'x',
            'http_status' => '400',
            'request_id' => false,
            'http_headers' => 'raya-request-id: req_abc',
            'http_body' => ['x'],
            'idempotency_key' => 1.5,
            'retry_after' => '3',
            'should_retry' => 'sí',
            'sig_header' => 1,
        ];
        $classes = [
            RayaException::class,
            ApiConnectionException::class,
            SignatureVerificationException::class,
        ];
        $own = [
            RayaException::class => [],
            ApiConnectionException::class => [false], // shouldRetry()
            SignatureVerificationException::class => [null], // getSigHeader()
        ];
        foreach ($classes as $class) {
            self::assertSame(
                ['', '', 0, 0, null, null, null, null, null, null, null, [], ...$own[$class]],
                self::fields(self::unserializeAs($class, [])),
                "{$class}, nada",
            );
            // data no es un array, el body no es texto y los headers no son un array.
            $fields = self::fields(self::unserializeAs($class, $wrong));
            self::assertSame([null, null, []], [$fields[5], $fields[8], $fields[11]], $class);
        }
        // De los headers queda el que es texto.
        $error = self::unserializeAs(
            RayaException::class,
            ['http_headers' => ['raya-request-id' => 'req_abc', 'retry-after' => 3]],
        );
        self::assertSame('req_abc', $error->getHttpHeaders()->get('raya-request-id'));
    }

    /**
     * Los headers de un error (y los de getLastResponse()) se leen sin importar las mayúsculas,
     * como los de httpx en Python y los de fetch en Node, y son de solo lectura.
     */
    public function testHttpHeadersAreCaseInsensitiveAndReadOnly(): void
    {
        $error = self::makeError(
            429,
            ['error' => ['code' => 'RATE_LIMITED', 'message' => 'x']],
            ['Retry-After' => '3'],
        );
        $headers = $error->getHttpHeaders();
        self::assertSame('3', $headers->get('RETRY-AFTER'));
        self::assertSame('3', $headers['retry-after']);
        self::assertTrue($headers->has('Raya-Request-ID'));
        self::assertTrue(isset($headers['RAYA-REQUEST-ID']));
        self::assertFalse($headers->has('X-No-Vino'));
        self::assertFalse(isset($headers['X-No-Vino']));
        self::assertNull($headers['X-No-Vino']);
        // Al recorrerlos, los nombres van en minúscula, como los manda HTTP/2.
        self::assertSame(
            ['raya-request-id' => 'req_abc', 'retry-after' => '3'],
            iterator_to_array($headers),
        );
        self::assertCount(2, $headers);
        $readOnly = 'Los headers de una respuesta son de solo lectura.';
        self::assertThrows(
            \LogicException::class,
            static function () use ($headers): void {
                $headers['X-Nuevo'] = '1';
            },
            $readOnly,
        );
        self::assertThrows(
            \LogicException::class,
            static function () use ($headers): void {
                unset($headers['Retry-After']);
            },
            $readOnly,
        );
        self::assertSame('3', $headers->get('Retry-After'));

        // Los de la Response de tu HttpClientInterface: texto o int, y uno repetido junta sus
        // valores con ", ".
        $built = new Headers(['X-Repetido' => ['uno', 'dos'], 'Content-Length' => 2]);
        self::assertSame(
            ['x-repetido' => 'uno, dos', 'content-length' => '2'],
            $built->toArray(),
        );
        $error = self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore argument.type (un float, a propósito)
            static fn() => new Headers(['Retry-After' => 1.5]),
        );
        self::assertSame(
            'El header retry-after tiene que ser un string o un int, no float.',
            $error->getMessage(),
        );
    }

    /**
     * Un byte que no es UTF-8 en el body de un error (de un proxy, o de tu HttpClientInterface)
     * no se lleva el código: como response.text en Python, el JSON se lee con U+FFFD en su lugar.
     * Así un IDEMPOTENCY_KEY_IN_USE sigue siendo IdempotencyException, y se reintenta. El body
     * queda tal como llegó.
     */
    public function testInvalidUtf8InTheBodyKeepsTheCode(): void
    {
        $body = '{"error":{"code":"IDEMPOTENCY_KEY_IN_USE","message":"Otra request usa la '
            . "clave \xff\",\"data\":null}}";
        $error = self::makeError(409, $body, idempotencyKey: 'k');
        self::assertSame(IdempotencyException::class, $error::class);
        self::assertSame('IDEMPOTENCY_KEY_IN_USE', $error->getErrorCode());
        self::assertSame("Otra request usa la clave \u{fffd}", $error->getMessage());
        self::assertSame($body, $error->getHttpBody());
        self::assertSame('k', $error->getIdempotencyKey());
        self::assertTrue(Retry::shouldRetryResponse(409, $error->getErrorCode(), null));

        $error = self::makeError(
            422,
            "{\"error\":{\"code\":\"INSUFFICIENT_BALANCE\",\"message\":\"Saldo \xc3\"}}",
        );
        self::assertSame(UnprocessableEntityException::class, $error::class);
        self::assertSame('INSUFFICIENT_BALANCE', $error->getErrorCode());
        self::assertSame("Saldo \u{fffd}", $error->getMessage());
    }

    /**
     * Con json_decode(…, true) una lista y un objeto se ven iguales: el sobre de error y su data
     * tienen que ser objetos JSON de verdad.
     */
    public function testJsonListsAreNotObjects(): void
    {
        $error = self::makeError(400, ['error' => ['PARAMETER_INVALID', 'x']]);
        self::assertSame(InvalidRequestException::class, $error::class);
        self::assertSame('INVALID_RESPONSE', $error->getErrorCode());
        self::assertSame('La API respondió algo inesperado (HTTP 400).', $error->getMessage());

        $error = self::makeError(
            400,
            ['error' => ['code' => 'PARAMETER_INVALID', 'message' => 'x', 'data' => ['amount']]],
        );
        self::assertInstanceOf(InvalidRequestException::class, $error);
        self::assertSame('PARAMETER_INVALID', $error->getErrorCode());
        self::assertNull($error->getData());
        self::assertNull($error->getParam());

        // Un objeto vacío sí es data (vacía), como el {} de Python.
        $error = self::makeError(
            409,
            ['error' => ['code' => 'NEW_CONFLICT', 'message' => 'x', 'data' => new \stdClass()]],
        );
        self::assertSame([], $error->getData());
    }

    /** La jerarquía de Python: la que importa para el orden de los catch. */
    public function testTheHierarchyIsPythons(): void
    {
        $expected = [
            RayaException::class => \Exception::class,
            ApiConnectionException::class => RayaException::class,
            InvalidRequestException::class => RayaException::class,
            NotFoundException::class => InvalidRequestException::class,
            AuthenticationException::class => RayaException::class,
            PermissionDeniedException::class => RayaException::class,
            ConflictException::class => RayaException::class,
            IdempotencyException::class => ConflictException::class,
            UnprocessableEntityException::class => RayaException::class,
            // RateLimitException no es un InvalidRequestException (en stripe-php, sí).
            RateLimitException::class => RayaException::class,
            ApiException::class => RayaException::class,
            SignatureVerificationException::class => RayaException::class,
        ];
        $parents = [];
        foreach (array_keys($expected) as $class) {
            $parents[$class] = get_parent_class($class);
        }
        self::assertSame($expected, $parents);
    }

    /**
     * Lo que devuelve cada getter de un error, en orden, más los propios de su clase al final
     * (shouldRetry() o getSigHeader()).
     *
     * @return list<mixed>
     */
    private static function fields(RayaException $error): array
    {
        $fields = [
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getCode(),
            $error->getErrorCode(),
            $error->getData(),
            $error->getHttpStatus(),
            $error->getRequestId(),
            $error->getHttpBody(),
            $error->getIdempotencyKey(),
            $error->getRetryAfter(),
            $error->getHttpHeaders()->toArray(),
        ];
        if ($error instanceof ApiConnectionException) {
            $fields[] = $error->shouldRetry();
        }
        if ($error instanceof SignatureVerificationException) {
            $fields[] = $error->getSigHeader();
        }
        return $fields;
    }

    /**
     * unserialize() de un objeto de esa clase con esos datos, como los deja serialize(): el
     * formato de un array, con «O:largo:"Clase"» adelante.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @return T
     */
    private static function unserializeAs(string $class, array $data): object
    {
        $object = unserialize(
            sprintf('O:%d:"%s"', strlen($class), $class) . substr(serialize($data), 1),
        );
        self::assertInstanceOf($class, $object);
        return $object;
    }

    /**
     * Como make_error() en Python: el error que arma el SDK para una respuesta con ese status y
     * ese body (un string va tal cual; lo demás, como JSON), con Raya-Request-Id: req_abc.
     *
     * @param array<string, string> $headers
     */
    private static function makeError(
        int $status,
        mixed $body,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): RayaException {
        $text = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
        return ErrorFactory::fromResponse(
            $status,
            new Headers(['Raya-Request-Id' => 'req_abc', ...$headers]),
            $text,
            $idempotencyKey,
        );
    }

    /**
     * Lo que da (string) $error con ese mensaje: el formato de \Exception::__toString() («Clase:
     * mensaje in archivo:línea» y la traza). Para un RayaException, el mensaje es el resumen de
     * str() en Python: «Request req_…: mensaje (CÓDIGO)».
     */
    private static function shown(\Throwable $error, string $message): string
    {
        return sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            $error::class,
            $message,
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString(),
        );
    }
}
