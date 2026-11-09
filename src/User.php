<?php

declare(strict_types=1);

namespace RayaCash;

use RayaCash\Internal\GlobalState;
use RayaCash\Internal\Params;
use RayaCash\Service\UserService;

/**
 * Una cuenta de Raya que conectó tu aplicación: la persona (o la empresa), con los datos que
 * verificó Raya, y su negocio.
 *
 * Solo la ves con su propio access_token (`$connected->users->retrieve($token->user_id)`):
 * cualquier otro id da USER_NOT_FOUND. Nunca viene la foto del documento ni el número de trámite.
 * Lo que no tenemos viene en null. type puede sumar valores: tu código tiene que ignorarlos.
 *
 * @property-read string $id El user_id que vino con el access_token (y el account de sus
 *     checkouts).
 * @property-read 'user' $object
 * @property-read 'personal'|'business' $type personal: una persona. business: una empresa, con
 *     business.
 * @property-read string $email
 * @property-read string|null $first_name De la persona titular; en una empresa, first_name y
 *     full_name traen la razón social y last_name es null. null donde no hay dato.
 * @property-read string|null $last_name
 * @property-read string|null $full_name
 * @property-read User\Identification|null $identification El DNI (una persona) o el CUIT (una
 *     empresa). null si todavía no lo verificamos.
 * @property-read string|null $tax_id CUIL (una persona) o CUIT (una empresa): 11 dígitos, sin
 *     guiones.
 * @property-read string|null $phone Con el código de país.
 * @property-read string|null $date_of_birth YYYY-MM-DD.
 * @property-read 'M'|'F'|'X'|null $sex Como figura en el documento.
 * @property-read string|null $nationality
 * @property-read User\Address|null $address El domicilio del documento, según RENAPER.
 * @property-read bool $identity_verified Si Raya verificó la identidad de la cuenta.
 * @property-read string|null $verified_at Cuándo la verificó (ISO 8601 en UTC).
 * @property-read User\Business|null $business Los datos de la sociedad; solo en una empresa.
 * @property-read User\Merchant|null $merchant El negocio configurado en Raya (lo que ve quien
 *     paga); null si todavía no lo configuró: no se le pueden crear checkouts.
 * @property-read string $created_at Cuándo se creó la cuenta (ISO 8601 en UTC).
 */
final class User extends RayaObject
{
    public const OBJECT_NAME = 'user';

    public const TYPE_PERSONAL = 'personal';
    public const TYPE_BUSINESS = 'business';

    public const IDENTIFICATION_DNI = 'DNI';
    public const IDENTIFICATION_CUIT = 'CUIT';

    protected const INNER_CLASSES = [
        'identification' => User\Identification::class,
        'address' => User\Address::class,
        'business' => User\Business::class,
        'merchant' => User\Merchant::class,
    ];

    /**
     * Estilo global: `User::retrieve($userId)`, con el access_token de esa cuenta en
     * RayaCash::setApiKey() o en api_key. Ver `$client->users->retrieve()`.
     *
     * @param array{
     *     api_key?: string|null,
     *     timeout?: int|float|null,
     *     max_network_retries?: int|null,
     * } $options
     */
    public static function retrieve(
        string $userId,
        #[\SensitiveParameter]
        array $options = [],
    ): User {
        Params::arguments('User::retrieve', func_num_args(), ['userId', 'options']);
        return (new UserService(GlobalState::requestor()))->retrieve($userId, $options);
    }
}
