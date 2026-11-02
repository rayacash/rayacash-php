<?php

declare(strict_types=1);

namespace RayaCash\HttpClient;

/**
 * Un cliente HTTP para el SDK: el CurlClient de serie o el tuyo (para tus tests, o para usar
 * otra librería).
 *
 * Hace UN intento, sin seguir redirects ni reintentar: los reintentos los maneja el SDK. Leé el
 * body entero antes de devolver, así un corte a mitad de la respuesta es un error de transporte.
 */
interface HttpClientInterface
{
    /**
     * Manda la request y devuelve la respuesta, sea cual sea su status.
     *
     * @throws TransportException si no llegó una respuesta completa (corte, timeout). Cualquier
     *     otra excepción sale tal cual hasta quien llamó al SDK.
     */
    public function send(Request $request): Response;
}
