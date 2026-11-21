<?php

declare(strict_types=1);

namespace RayaCash\Tests;

use RayaCash\Balance;
use RayaCash\ConnectOrder;
use RayaCash\ConnectPayout;
use RayaCash\Event;
use RayaCash\Exception\ApiException;
use RayaCash\IdentityVerification;
use RayaCash\IdentityVerificationFiles;
use RayaCash\Internal\Hydrator;
use RayaCash\Internal\ObjectToken;
use RayaCash\Invoice;
use RayaCash\ListObject;
use RayaCash\OAuthToken;
use RayaCash\Payment;
use RayaCash\PspBalance;
use RayaCash\PspCustomer;
use RayaCash\PspCustomerBalance;
use RayaCash\PspDocumentRequest;
use RayaCash\PspTransfer;
use RayaCash\RayaCash;
use RayaCash\RayaObject;
use RayaCash\Tests\Support\Fixtures;
use RayaCash\Tests\Support\MockApi;
use RayaCash\Tests\Support\TestCase;
use RayaCash\Transfer;
use RayaCash\User;

/**
 * Los objetos de la API: su clase, sus valores crudos, los campos que faltan, que se pueden
 * modificar, cómo se muestran y cómo se copian (test_objects.py).
 */
final class ObjectsTest extends TestCase
{
    public function testTransferIsATypedDictWithAttributeAccess(): void
    {
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        // Python: isinstance(transfer, dict). Acá no es un array: se lee también con [].
        self::assertInstanceOf(\ArrayAccess::class, $transfer);
        self::assertSame(Fixtures::TRANSFER_INBOUND['id'], $transfer->id);
        self::assertSame(Fixtures::TRANSFER_INBOUND['id'], $transfer['id']);
        self::assertSame(130.41, $transfer->fee);
        self::assertSame(Transfer\Counterparty::class, $transfer->counterparty::class);
        self::assertNull($transfer->counterparty->alias);
        self::assertSame('0000123319112992888845', $transfer->counterparty->account);
    }

    public function testValuesStayAsRawJson(): void
    {
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        self::assertSame(Fixtures::TRANSFER_INBOUND, self::jsonRoundTrip($transfer));
        // Los mismos bytes que el JSON de la API (en el mismo orden, como Object.keys en Node).
        self::assertSame(
            json_encode(Fixtures::TRANSFER_INBOUND, JSON_THROW_ON_ERROR),
            json_encode($transfer, JSON_THROW_ON_ERROR),
        );
        self::assertIsArray($transfer->toArray()['counterparty']);
        self::assertSame(Fixtures::TRANSFER_INBOUND, $transfer->toArray());
        self::assertIsInt($transfer->amount);
        self::assertIsFloat($transfer->net_amount);
        self::assertIsString($transfer->created_at);
    }

    public function testPaymentNestedObjectsAndPlainMetadata(): void
    {
        $pending = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        self::assertSame(Payment\BankTransfer::class, $pending->bank_transfer::class);
        self::assertSame('0000123322813466534007', $pending->bank_transfer->cvu);
        self::assertNull($pending->payer);
        self::assertSame(['cliente_id' => '8812'], $pending->metadata); // un array común
        $succeeded = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        self::assertNotNull($succeeded->payer);
        self::assertSame(Payment\Payer::class, $succeeded->payer::class);
        self::assertSame('María Ejemplo', $succeeded->payer->name);
    }

    public function testConnectOrderNestedObjectsAndPlainMetadata(): void
    {
        $pending = self::convertAs(ConnectOrder::class, Fixtures::CONNECT_ORDER_PENDING);
        self::assertSame(ConnectOrder\BankTransfer::class, $pending->bank_transfer::class);
        self::assertSame('0000123390456538582824', $pending->bank_transfer->cvu);
        self::assertSame(ConnectOrder\BackUrls::class, $pending->back_urls::class);
        self::assertSame(
            'https://tienda.example.com/pedidos/1042/error',
            $pending->back_urls->failure,
        );
        // Los ítems: una lista de Item.
        self::assertCount(1, $pending->items);
        self::assertSame(ConnectOrder\Item::class, $pending->items[0]::class);
        self::assertSame(
            ['Pedido 1042', 1, 45000],
            [
                $pending->items[0]->title,
                $pending->items[0]->quantity,
                $pending->items[0]->unit_price,
            ],
        );
        self::assertNull($pending->payer);
        self::assertSame(['pedido_id' => '1042'], $pending->metadata); // un array común
        self::assertSame(Fixtures::CONNECT_ORDER_PENDING, self::jsonRoundTrip($pending));
        self::assertSame(Fixtures::CONNECT_ORDER_PENDING, $pending->toArray());
        $succeeded = self::convertAs(ConnectOrder::class, Fixtures::CONNECT_ORDER_SUCCEEDED);
        self::assertSame(ConnectOrder\Payer::class, get_debug_type($succeeded->payer));
        self::assertSame('María Ejemplo', $succeeded->payer?->name);
        $empty = ConnectOrder::constructFrom(['object' => 'connect.order', 'items' => []]);
        self::assertSame([], $empty->items);
    }

    /**
     * "connect.order" se busca entero, con su punto: hidrata un ConnectOrder también anidado en un
     * objeto sin clase propia, y otro tipo con punto o una de sus partes ("connect", "order")
     * quedan como RayaObject.
     */
    public function testConnectOrderObjectNameWithADot(): void
    {
        self::assertSame('connect.order', ConnectOrder::OBJECT_NAME);
        $order = RayaObject::constructFrom(['order' => Fixtures::CONNECT_ORDER_PENDING])['order'];
        self::assertInstanceOf(ConnectOrder::class, $order);
        self::assertSame('connect.order', $order->object);
        $unknown = RayaObject::constructFrom([
            'a' => [...Fixtures::CONNECT_ORDER_PENDING, 'object' => 'connect.order_v2'],
            'b' => [...Fixtures::CONNECT_ORDER_PENDING, 'object' => 'connect'],
            'c' => [...Fixtures::CONNECT_ORDER_PENDING, 'object' => 'order'],
        ]);
        foreach (['a', 'b', 'c'] as $key) {
            self::assertSame(RayaObject::class, get_debug_type($unknown[$key]), $key);
        }
    }

    public function testConnectPayoutNestedObjects(): void
    {
        $pending = self::convertAs(ConnectPayout::class, Fixtures::CONNECT_PAYOUT_PENDING);
        self::assertSame(ConnectPayout\Origin::class, $pending->origin::class);
        self::assertSame(
            ['0000123368198805021689', 'raya.cash.ejemplo', 'Tienda Ejemplo'],
            [$pending->origin->account, $pending->origin->alias, $pending->origin->name],
        );
        self::assertSame(ConnectPayout\Counterparty::class, $pending->counterparty::class);
        self::assertSame(
            ['Ana Ejemplo', '20345678902', '0000123305838495183140', 'ana.ejemplo'],
            [
                $pending->counterparty->name,
                $pending->counterparty->tax_id,
                $pending->counterparty->account,
                $pending->counterparty->alias,
            ],
        );
        self::assertSame(870.25, $pending->processing_fee);
        self::assertSame(59129.75, $pending->net_amount);
        self::assertNull($pending->reference);
        // El JSON exacto de la API, ida y vuelta.
        self::assertSame(Fixtures::CONNECT_PAYOUT_PENDING, self::jsonRoundTrip($pending));
        self::assertSame(Fixtures::CONNECT_PAYOUT_PENDING, $pending->toArray());
        self::assertSame(
            json_encode(Fixtures::CONNECT_PAYOUT_PENDING, JSON_THROW_ON_ERROR),
            json_encode($pending, JSON_THROW_ON_ERROR),
        );
    }

    /** "connect.payout", como "connect.order": se busca entero, con su punto. */
    public function testConnectPayoutObjectNameWithADot(): void
    {
        self::assertSame('connect.payout', ConnectPayout::OBJECT_NAME);
        self::assertSame(
            ['pending', 'completed', 'failed', 'reversed'],
            [
                ConnectPayout::STATUS_PENDING,
                ConnectPayout::STATUS_COMPLETED,
                ConnectPayout::STATUS_FAILED,
                ConnectPayout::STATUS_REVERSED,
            ],
        );
        $nested = RayaObject::constructFrom(['payout' => Fixtures::CONNECT_PAYOUT_COMPLETED]);
        $payout = $nested['payout'];
        self::assertInstanceOf(ConnectPayout::class, $payout);
        self::assertSame('connect.payout', $payout->object);
    }

    /** Una verificación de Identidad: sus sub-objetos, con su clase, y la metadata como array. */
    public function testIdentityVerificationNestedObjects(): void
    {
        $verified = self::convertAs(IdentityVerification::class, Fixtures::VERIFICATION_VERIFIED);
        self::assertSame(Fixtures::VERIFICATION_ID, $verified->id);
        self::assertSame(['plan' => 'pro'], $verified->metadata); // un array común
        self::assertNull($verified->decline_reason);
        $checks = $verified->checks;
        self::assertSame(IdentityVerification\Checks::class, $checks::class);
        self::assertSame(
            [
                IdentityVerification\DocumentCheck::class,
                IdentityVerification\LivenessCheck::class,
                IdentityVerification\FaceMatchCheck::class,
                IdentityVerification\RegistryCheck::class,
            ],
            [
                $checks->document::class,
                $checks->liveness::class,
                $checks->face_match::class,
                $checks->registry::class,
            ],
        );
        self::assertSame(
            ['passed', 99.1, 97.4, true, 'hit'],
            [
                $checks->document->status,
                $checks->liveness->confidence,
                $checks->face_match->similarity,
                $checks->registry->data_match,
                $checks->registry->face_match,
            ],
        );
        $person = $verified->person;
        self::assertNotNull($person);
        self::assertSame(IdentityVerification\Person::class, $person::class);
        self::assertSame(['María Laura', '27123456781'], [$person->first_name, $person->cuil]);
        self::assertSame(IdentityVerification\Address::class, $person->address::class);
        self::assertSame(['Rosario', null], [$person->address->city, $person->address->floor]);
        self::assertSame(IdentityVerification\IdentityDocument::class, $person->document::class);
        self::assertSame('2035-01-31', $person->document->expiry_date);
        self::assertSame([1000, 'ARS'], [$verified->amount, $verified->currency]);
        // El JSON exacto de la API, ida y vuelta, con sus 19 campos en su orden.
        self::assertSame(Fixtures::VERIFICATION_VERIFIED, self::jsonRoundTrip($verified));
        self::assertSame(Fixtures::VERIFICATION_VERIFIED, $verified->toArray());
        self::assertSame(
            json_encode(Fixtures::VERIFICATION_VERIFIED, JSON_THROW_ON_ERROR),
            json_encode($verified, JSON_THROW_ON_ERROR),
        );
        self::assertCount(19, $verified->toArray());

        $declined = self::convertAs(IdentityVerification::class, Fixtures::VERIFICATION_DECLINED);
        self::assertSame(
            IdentityVerification\DeclineReason::class,
            get_debug_type($declined->decline_reason),
        );
        self::assertSame('registry_data_mismatch', $declined->decline_reason?->code);
        self::assertNull($declined->person);
        self::assertSame(Fixtures::VERIFICATION_DECLINED, $declined->toArray());
    }

    /** Las claves de la metadata son tuyas: nunca se convierten, aunque parezcan un objeto. */
    public function testIdentityVerificationMetadataStaysPlain(): void
    {
        $verification = self::convertAs(
            IdentityVerification::class,
            [
                ...Fixtures::VERIFICATION_PENDING,
                'metadata' => ['object' => 'identity.verification', 'keys' => 'x'],
            ],
        );
        self::assertSame(
            ['object' => 'identity.verification', 'keys' => 'x'],
            $verification->metadata,
        );
    }

    /**
     * "identity.verification" e "identity.verification_files" se buscan enteros, con su punto,
     * como "connect.order", también anidados en un objeto sin clase propia.
     */
    public function testIdentityVerificationObjectNamesWithADot(): void
    {
        self::assertSame('identity.verification', IdentityVerification::OBJECT_NAME);
        self::assertSame('identity.verification_files', IdentityVerificationFiles::OBJECT_NAME);
        self::assertSame(
            ['document', 'biometric'],
            [IdentityVerification::TYPE_DOCUMENT, IdentityVerification::TYPE_BIOMETRIC],
        );
        self::assertSame(
            ['pending', 'in_progress', 'processing', 'verified', 'declined', 'expired', 'canceled'],
            [
                IdentityVerification::STATUS_PENDING,
                IdentityVerification::STATUS_IN_PROGRESS,
                IdentityVerification::STATUS_PROCESSING,
                IdentityVerification::STATUS_VERIFIED,
                IdentityVerification::STATUS_DECLINED,
                IdentityVerification::STATUS_EXPIRED,
                IdentityVerification::STATUS_CANCELED,
            ],
        );
        self::assertSame(
            [
                'face_mismatch',
                'registry_not_found',
                'registry_data_mismatch',
                'registry_unavailable',
                'attempts_exhausted',
            ],
            [
                IdentityVerification::DECLINE_CODE_FACE_MISMATCH,
                IdentityVerification::DECLINE_CODE_REGISTRY_NOT_FOUND,
                IdentityVerification::DECLINE_CODE_REGISTRY_DATA_MISMATCH,
                IdentityVerification::DECLINE_CODE_REGISTRY_UNAVAILABLE,
                IdentityVerification::DECLINE_CODE_ATTEMPTS_EXHAUSTED,
            ],
        );
        $nested = RayaObject::constructFrom([
            'verification' => Fixtures::VERIFICATION_PENDING,
            'files' => Fixtures::VERIFICATION_FILES,
            'other' => [...Fixtures::VERIFICATION_PENDING, 'object' => 'identity'],
        ]);
        self::assertSame(IdentityVerification::class, get_debug_type($nested['verification']));
        self::assertSame(IdentityVerificationFiles::class, get_debug_type($nested['files']));
        self::assertSame(RayaObject::class, get_debug_type($nested['other']));
    }

    /** Las imágenes que la verificación no tiene vienen en null; las que tiene, como File. */
    public function testIdentityVerificationFilesWithNulls(): void
    {
        $files = self::convertAs(
            IdentityVerificationFiles::class,
            [...Fixtures::VERIFICATION_FILES, 'front' => null, 'back' => null],
        );
        self::assertNull($files->front);
        self::assertNull($files->back);
        self::assertSame(IdentityVerificationFiles\File::class, get_debug_type($files->selfie));
        self::assertSame(Fixtures::VERIFICATION_FILES['selfie']['url'], $files->selfie?->url);
        $all = self::convertAs(IdentityVerificationFiles::class, Fixtures::VERIFICATION_FILES);
        self::assertSame(Fixtures::VERIFICATION_FILES, self::jsonRoundTrip($all));
        self::assertSame(Fixtures::VERIFICATION_FILES, $all->toArray());
    }

    /** Un cliente de Raya PSP: su CVU y su decline_reason, con su clase, y la metadata plana. */
    public function testPspCustomerNestedObjects(): void
    {
        $active = self::convertAs(PspCustomer::class, Fixtures::PSP_CUSTOMER_ACTIVE);
        self::assertSame(Fixtures::PSP_CUSTOMER_ID, $active->id);
        self::assertSame(['plan' => 'pro'], $active->metadata); // un array común
        self::assertNull($active->decline_reason);
        self::assertNull($active->blocked_by);
        $cvu = $active->cvu;
        self::assertNotNull($cvu);
        self::assertSame(PspCustomer\Cvu::class, $cvu::class);
        self::assertSame(
            [Fixtures::PSP_ACCOUNT, 'maria_ejemplo', 'MARIA LAURA EJEMPLO', 'active', null],
            [$cvu->account, $cvu->alias, $cvu->holder_name, $cvu->status, $cvu->closed_at],
        );
        self::assertSame([23649.5, 'ARS'], [$active->balance, $active->currency]);
        // El JSON exacto de la API, ida y vuelta, con sus 15 campos en su orden (sin
        // terms_accepted_at).
        self::assertSame(Fixtures::PSP_CUSTOMER_ACTIVE, self::jsonRoundTrip($active));
        self::assertSame(Fixtures::PSP_CUSTOMER_ACTIVE, $active->toArray());
        self::assertSame(
            json_encode(Fixtures::PSP_CUSTOMER_ACTIVE, JSON_THROW_ON_ERROR),
            json_encode($active, JSON_THROW_ON_ERROR),
        );
        self::assertCount(15, $active->toArray());
        self::assertArrayNotHasKey('terms_accepted_at', $active->toArray());

        $rejected = self::convertAs(PspCustomer::class, Fixtures::PSP_CUSTOMER_REJECTED);
        self::assertSame(
            PspCustomer\DeclineReason::class,
            get_debug_type($rejected->decline_reason),
        );
        self::assertSame(PspCustomer::DECLINE_CODE_FACE_MISMATCH, $rejected->decline_reason?->code);
        self::assertNull($rejected->cvu);
        self::assertSame(Fixtures::PSP_CUSTOMER_REJECTED, $rejected->toArray());

        $blocked = self::convertAs(PspCustomer::class, Fixtures::PSP_CUSTOMER_BLOCKED);
        self::assertSame(
            [PspCustomer::STATUS_BLOCKED, PspCustomer::BLOCKED_BY_ACCOUNT],
            [$blocked->status, $blocked->blocked_by],
        );
        $failed = self::convertAs(PspCustomer::class, Fixtures::PSP_CUSTOMER_FAILED);
        self::assertSame(
            [PspCustomer::STATUS_FAILED, PspCustomer::DECLINE_CODE_BANK_REJECTED, null],
            [$failed->status, $failed->decline_reason?->code, $failed->cvu],
        );
        $recreated = self::convertAs(PspCustomer::class, Fixtures::PSP_CUSTOMER_RECREATED);
        self::assertSame(
            [Fixtures::PSP_RECREATED_ACCOUNT, 'maria_ejemplo_nuevo', 23649.5],
            [$recreated->cvu?->account, $recreated->cvu?->alias, $recreated->balance],
        );
        $company = self::convertAs(PspCustomer::class, Fixtures::PSP_COMPANY);
        self::assertSame(
            [PspCustomer::PERSON_TYPE_J, 'DISTRIBUIDORA EJEMPLO S. A.', null],
            [$company->person_type, $company->cvu?->holder_name, $company->identity_verification],
        );
        $reviewing = self::convertAs(PspCustomer::class, Fixtures::PSP_COMPANY_VERIFYING);
        self::assertSame(
            [PspCustomer::STATUS_VERIFYING, null],
            [$reviewing->status, $reviewing->cvu],
        );
        self::assertSame([], $reviewing->metadata);
    }

    /** Una transferencia de un cliente: la otra cuenta, con su clase, y los montos tal cual. */
    public function testPspTransferNestedObjects(): void
    {
        $inbound = self::convertAs(PspTransfer::class, Fixtures::PSP_TRANSFER_INBOUND);
        self::assertSame(PspTransfer\Counterparty::class, $inbound->counterparty::class);
        self::assertSame(
            ['Juan Ejemplo', '20123456785', '0000123319112992888845', null],
            [
                $inbound->counterparty->name,
                $inbound->counterparty->tax_id,
                $inbound->counterparty->account,
                $inbound->counterparty->alias,
            ],
        );
        self::assertSame(
            [42000, 133.9, 41866.1],
            [$inbound->amount, $inbound->fee, $inbound->net_amount],
        );
        self::assertSame(Fixtures::PSP_CUSTOMER_ID, $inbound->customer);
        self::assertSame(Fixtures::PSP_TRANSFER_INBOUND, self::jsonRoundTrip($inbound));
        self::assertSame(Fixtures::PSP_TRANSFER_INBOUND, $inbound->toArray());
        $outbound = self::convertAs(PspTransfer::class, Fixtures::PSP_TRANSFER_OUTBOUND_PENDING);
        self::assertSame(['Transferencia', null], [$outbound->description, $outbound->reference]);
        self::assertSame(
            json_encode(Fixtures::PSP_TRANSFER_OUTBOUND_PENDING, JSON_THROW_ON_ERROR),
            json_encode($outbound, JSON_THROW_ON_ERROR),
        );

        $balance = self::convertAs(PspBalance::class, Fixtures::PSP_BALANCE);
        self::assertSame([640200.5, 512875.25], [$balance->available, $balance->customers_balance]);
        $customerBalance = self::convertAs(
            PspCustomerBalance::class,
            Fixtures::PSP_CUSTOMER_BALANCE,
        );
        self::assertSame(Fixtures::PSP_CUSTOMER_BALANCE, $customerBalance->toArray());
        $request = self::convertAs(PspDocumentRequest::class, Fixtures::PSP_DOCUMENT_REQUEST);
        self::assertSame(
            [PspDocumentRequest::STATUS_OPEN, null, null],
            [$request->status, $request->submitted_at, $request->closed_at],
        );
        self::assertSame(Fixtures::PSP_DOCUMENT_REQUEST, $request->toArray());
        $closed = self::convertAs(
            PspDocumentRequest::class,
            Fixtures::PSP_DOCUMENT_REQUEST_CLOSED,
        );
        self::assertSame(
            [PspDocumentRequest::STATUS_CLOSED, '2026-10-26T09:00:00Z'],
            [$closed->status, $closed->closed_at],
        );
        $failed = self::convertAs(PspTransfer::class, Fixtures::PSP_TRANSFER_FAILED);
        self::assertSame([PspTransfer::STATUS_FAILED, 61.37], [$failed->status, $failed->fee]);
    }

    /**
     * Los cinco objetos de Raya PSP llevan un punto en "object" ("psp.customer"…): se buscan
     * enteros, también anidados en un objeto sin clase propia.
     */
    public function testPspObjectNamesWithADot(): void
    {
        self::assertSame(
            [
                'psp.customer',
                'psp.customer_balance',
                'psp.transfer',
                'psp.balance',
                'psp.document_request',
            ],
            [
                PspCustomer::OBJECT_NAME,
                PspCustomerBalance::OBJECT_NAME,
                PspTransfer::OBJECT_NAME,
                PspBalance::OBJECT_NAME,
                PspDocumentRequest::OBJECT_NAME,
            ],
        );
        self::assertSame(
            ['verifying', 'pending', 'active', 'blocked', 'closed', 'failed', 'rejected'],
            [
                PspCustomer::STATUS_VERIFYING,
                PspCustomer::STATUS_PENDING,
                PspCustomer::STATUS_ACTIVE,
                PspCustomer::STATUS_BLOCKED,
                PspCustomer::STATUS_CLOSED,
                PspCustomer::STATUS_FAILED,
                PspCustomer::STATUS_REJECTED,
            ],
        );
        self::assertSame(
            [
                'image_unavailable',
                'image_invalid',
                'document_unreadable',
                'document_sides_mismatch',
                'document_mismatch',
                'identity_not_verified',
                'name_mismatch',
                'selfie_invalid',
                'face_mismatch',
                'verification_unavailable',
                'not_approved',
                'bank_rejected',
                'psp_disabled',
            ],
            [
                PspCustomer::DECLINE_CODE_IMAGE_UNAVAILABLE,
                PspCustomer::DECLINE_CODE_IMAGE_INVALID,
                PspCustomer::DECLINE_CODE_DOCUMENT_UNREADABLE,
                PspCustomer::DECLINE_CODE_DOCUMENT_SIDES_MISMATCH,
                PspCustomer::DECLINE_CODE_DOCUMENT_MISMATCH,
                PspCustomer::DECLINE_CODE_IDENTITY_NOT_VERIFIED,
                PspCustomer::DECLINE_CODE_NAME_MISMATCH,
                PspCustomer::DECLINE_CODE_SELFIE_INVALID,
                PspCustomer::DECLINE_CODE_FACE_MISMATCH,
                PspCustomer::DECLINE_CODE_VERIFICATION_UNAVAILABLE,
                PspCustomer::DECLINE_CODE_NOT_APPROVED,
                PspCustomer::DECLINE_CODE_BANK_REJECTED,
                PspCustomer::DECLINE_CODE_PSP_DISABLED,
            ],
        );
        // Ni uno más: sin gender_mismatch ni GENDER_*, porque el sexo sale del frente del DNI.
        $names = array_keys((new \ReflectionClass(PspCustomer::class))->getConstants());
        self::assertCount(13, preg_grep('/\ADECLINE_CODE_/', $names) ?: []);
        self::assertSame([], preg_grep('/\AGENDER_/', $names));
        self::assertSame(
            ['F', 'J', 'account', 'raya'],
            [
                PspCustomer::PERSON_TYPE_F,
                PspCustomer::PERSON_TYPE_J,
                PspCustomer::BLOCKED_BY_ACCOUNT,
                PspCustomer::BLOCKED_BY_RAYA,
            ],
        );
        self::assertSame(
            ['active', 'closing', 'closed'],
            [
                PspCustomer\Cvu::STATUS_ACTIVE,
                PspCustomer\Cvu::STATUS_CLOSING,
                PspCustomer\Cvu::STATUS_CLOSED,
            ],
        );
        self::assertSame(
            ['inbound', 'outbound', 'pending', 'completed', 'failed', 'reversed'],
            [
                PspTransfer::DIRECTION_INBOUND,
                PspTransfer::DIRECTION_OUTBOUND,
                PspTransfer::STATUS_PENDING,
                PspTransfer::STATUS_COMPLETED,
                PspTransfer::STATUS_FAILED,
                PspTransfer::STATUS_REVERSED,
            ],
        );
        self::assertSame(
            ['open', 'submitted', 'closed'],
            [
                PspDocumentRequest::STATUS_OPEN,
                PspDocumentRequest::STATUS_SUBMITTED,
                PspDocumentRequest::STATUS_CLOSED,
            ],
        );
        $nested = RayaObject::constructFrom([
            'customer' => Fixtures::PSP_CUSTOMER_ACTIVE,
            'customer_balance' => Fixtures::PSP_CUSTOMER_BALANCE,
            'transfer' => Fixtures::PSP_TRANSFER_INBOUND,
            'balance' => Fixtures::PSP_BALANCE,
            'request' => Fixtures::PSP_DOCUMENT_REQUEST,
            'other' => [...Fixtures::PSP_CUSTOMER_ACTIVE, 'object' => 'psp'],
        ]);
        self::assertSame(
            [
                PspCustomer::class,
                PspCustomerBalance::class,
                PspTransfer::class,
                PspBalance::class,
                PspDocumentRequest::class,
                RayaObject::class,
            ],
            [
                get_debug_type($nested['customer']),
                get_debug_type($nested['customer_balance']),
                get_debug_type($nested['transfer']),
                get_debug_type($nested['balance']),
                get_debug_type($nested['request']),
                get_debug_type($nested['other']),
            ],
        );
    }

    /**
     * Una factura del Facturador: sus 20 campos en el orden de la API, el motivo y los mensajes
     * de ARCA con su clase (una lista), la metadata plana y vat_amount en null en una Factura C.
     */
    public function testInvoiceNestedObjects(): void
    {
        $issued = self::convertAs(Invoice::class, Fixtures::INVOICE_ISSUED);
        self::assertSame(Fixtures::INVOICE_ID, $issued->id);
        self::assertSame(
            [
                'id',
                'object',
                'type',
                'status',
                'amount',
                'net_amount',
                'vat_amount',
                'currency',
                'dni',
                'point_of_sale',
                'number',
                'concept',
                'cae',
                'cae_expires_on',
                'issued_on',
                'failure_reason',
                'arca_messages',
                'metadata',
                'issued_at',
                'created_at',
            ],
            array_keys($issued->toArray()),
        );
        self::assertSame(
            [15000, 12396.69, 2603.31, 'ARS'],
            [$issued->amount, $issued->net_amount, $issued->vat_amount, $issued->currency],
        );
        // net_amount + vat_amount es amount, al centavo.
        $net = (int) round($issued->net_amount * 100);
        $vat = (int) round(($issued->vat_amount ?? 0) * 100);
        self::assertSame(1500000, $net + $vat);
        self::assertSame(
            ['12345678', 5, 128],
            [$issued->dni, $issued->point_of_sale, $issued->number],
        );
        self::assertSame(['pedido' => '1042'], $issued->metadata); // un array común
        self::assertNull($issued->failure_reason);
        self::assertSame([], $issued->arca_messages);
        // El JSON exacto de la API, ida y vuelta.
        self::assertSame(Fixtures::INVOICE_ISSUED, self::jsonRoundTrip($issued));
        self::assertSame(Fixtures::INVOICE_ISSUED, $issued->toArray());
        self::assertSame(
            json_encode(Fixtures::INVOICE_ISSUED, JSON_THROW_ON_ERROR),
            json_encode($issued, JSON_THROW_ON_ERROR),
        );

        $rejected = self::convertAs(Invoice::class, Fixtures::INVOICE_REJECTED);
        self::assertSame(Invoice\FailureReason::class, get_debug_type($rejected->failure_reason));
        self::assertSame(
            [
                Invoice::FAILURE_CODE_ARCA_REJECTED,
                'ARCA rechazó la factura: arca_messages dice por qué.',
            ],
            [$rejected->failure_reason?->code, $rejected->failure_reason?->message],
        );
        self::assertSame(
            [Invoice\ArcaMessage::class],
            array_map(
                static fn(mixed $message): string => get_debug_type($message),
                $rejected->arca_messages,
            ),
        );
        self::assertSame(
            [Invoice\ArcaMessage::TYPE_OBSERVATION, 10015],
            [$rejected->arca_messages[0]->type, $rejected->arca_messages[0]->code],
        );
        self::assertSame(Fixtures::INVOICE_REJECTED, $rejected->toArray());
        self::assertSame(Fixtures::INVOICE_REJECTED, self::jsonRoundTrip($rejected));

        $factC = self::convertAs(Invoice::class, Fixtures::INVOICE_C_ISSUED);
        self::assertSame(
            [Invoice::TYPE_C, 15000, null, Invoice::CONCEPT_SERVICES],
            [$factC->type, $factC->net_amount, $factC->vat_amount, $factC->concept],
        );
        self::assertSame(Fixtures::INVOICE_C_ISSUED, $factC->toArray());

        $failed = self::convertAs(Invoice::class, Fixtures::INVOICE_FAILED_NUMBERING);
        self::assertSame(
            [Invoice::STATUS_FAILED, Invoice::FAILURE_CODE_NUMBERING_CONFLICT, null],
            [$failed->status, $failed->failure_reason?->code, $failed->number],
        );
        self::assertSame(10016, $failed->arca_messages[0]->code);

        $pending = self::convertAs(Invoice::class, Fixtures::INVOICE_PENDING);
        self::assertSame(
            [Invoice::STATUS_PENDING, null, null, null, null, null],
            [
                $pending->status,
                $pending->number,
                $pending->cae,
                $pending->cae_expires_on,
                $pending->issued_on,
                $pending->issued_at,
            ],
        );
        // Los DNI de los ejemplos son los que acepta la API: sin puntos ni ceros adelante.
        $examples = [
            Fixtures::INVOICE_PENDING,
            Fixtures::INVOICE_REJECTED,
            Fixtures::INVOICE_C_ISSUED,
        ];
        foreach ($examples as $values) {
            self::assertMatchesRegularExpression('/\A[1-9][0-9]{5,7}\z/', $values['dni']);
        }
    }

    /**
     * Las constantes de la factura y de sus mensajes de ARCA, y "invoice" registrado: también
     * anidado en un objeto sin clase propia.
     */
    public function testInvoiceConstantsAndObjectName(): void
    {
        self::assertSame('invoice', Invoice::OBJECT_NAME);
        self::assertSame(['B', 'C'], [Invoice::TYPE_B, Invoice::TYPE_C]);
        self::assertSame(
            ['pending', 'issued', 'rejected', 'failed'],
            [
                Invoice::STATUS_PENDING,
                Invoice::STATUS_ISSUED,
                Invoice::STATUS_REJECTED,
                Invoice::STATUS_FAILED,
            ],
        );
        self::assertSame(
            ['products', 'services', 'products_and_services'],
            [
                Invoice::CONCEPT_PRODUCTS,
                Invoice::CONCEPT_SERVICES,
                Invoice::CONCEPT_PRODUCTS_AND_SERVICES,
            ],
        );
        self::assertSame(
            [
                'arca_rejected',
                'facturador_not_ready',
                'not_issued',
                'numbering_conflict',
                'account_unavailable',
            ],
            [
                Invoice::FAILURE_CODE_ARCA_REJECTED,
                Invoice::FAILURE_CODE_FACTURADOR_NOT_READY,
                Invoice::FAILURE_CODE_NOT_ISSUED,
                Invoice::FAILURE_CODE_NUMBERING_CONFLICT,
                Invoice::FAILURE_CODE_ACCOUNT_UNAVAILABLE,
            ],
        );
        // Ni uno más: los 5 códigos públicos de failure_reason.
        $names = array_keys((new \ReflectionClass(Invoice::class))->getConstants());
        self::assertCount(5, preg_grep('/\AFAILURE_CODE_/', $names) ?: []);
        self::assertSame(
            ['error', 'observation'],
            [Invoice\ArcaMessage::TYPE_ERROR, Invoice\ArcaMessage::TYPE_OBSERVATION],
        );
        $nested = RayaObject::constructFrom([
            'invoice' => Fixtures::INVOICE_ISSUED,
            'other' => [...Fixtures::INVOICE_ISSUED, 'object' => 'invoice.v2'],
        ]);
        self::assertSame(
            [Invoice::class, RayaObject::class],
            [get_debug_type($nested['invoice']), get_debug_type($nested['other'])],
        );
    }

    public function testUserNestedObjects(): void
    {
        $user = self::convertAs(User::class, Fixtures::USER);
        self::assertSame(User\Identification::class, get_debug_type($user->identification));
        self::assertSame(
            ['DNI', '34567890'],
            [$user->identification?->type, $user->identification?->number],
        );
        self::assertSame(User\Address::class, get_debug_type($user->address));
        self::assertSame('Rosario', $user->address?->city);
        self::assertNull($user->business);
        self::assertSame(User\Merchant::class, get_debug_type($user->merchant));
        self::assertSame(
            ['Almacén Ejemplo', null],
            [$user->merchant?->name, $user->merchant?->image],
        );
        self::assertTrue($user->identity_verified);
        self::assertSame(Fixtures::USER, $user->toArray());

        $business = self::convertAs(User::class, Fixtures::USER_BUSINESS);
        self::assertSame(User::TYPE_BUSINESS, $business->type);
        self::assertSame(User::IDENTIFICATION_CUIT, $business->identification?->type);
        self::assertSame(User\Business::class, get_debug_type($business->business));
        self::assertSame(
            User\Representative::class,
            get_debug_type($business->business?->representative),
        );
        self::assertSame('27345678901', $business->business?->representative?->tax_id);
        self::assertSame(Fixtures::USER_BUSINESS, $business->toArray());
    }

    /**
     * El access_token es un secreto, como la clave: (string), print_r y var_dump lo muestran
     * enmascarado. Para guardarlo están ->access_token, toArray() y json_encode(), enteros.
     */
    public function testOAuthTokenShowsTheAccessTokenMasked(): void
    {
        $token = self::convertAs(OAuthToken::class, Fixtures::OAUTH_TOKEN);
        self::assertSame(Fixtures::ACCESS_TOKEN, $token->access_token);
        self::assertSame(Fixtures::OAUTH_TOKEN, $token->toArray());
        self::assertSame(
            json_encode(Fixtures::OAUTH_TOKEN, JSON_THROW_ON_ERROR),
            json_encode($token, JSON_THROW_ON_ERROR),
        );
        $masked = 'raya_at_live_…9hJk';
        self::assertSame(
            self::sortedKeys([...Fixtures::OAUTH_TOKEN, 'access_token' => $masked]),
            json_decode((string) $token, true, 512, JSON_THROW_ON_ERROR),
        );
        $printed = print_r($token, true);
        self::assertStringStartsWith("RayaCash\\OAuthToken Object\n(\n", $printed);
        self::assertStringContainsString("[access_token] => {$masked}", $printed);
        ob_start();
        var_dump($token);
        $dumped = (string) ob_get_clean();
        self::assertStringContainsString($masked, $dumped);
        $secret = substr(Fixtures::ACCESS_TOKEN, strlen('raya_at_live_'));
        foreach ([(string) $token, $printed, $dumped] as $shown) {
            self::assertStringNotContainsString($secret, $shown);
        }
        // Enmascarar no cambia el objeto.
        self::assertSame(Fixtures::ACCESS_TOKEN, $token->access_token);
        self::assertSame(Fixtures::OAUTH_TOKEN, $token->toArray());
        // Sin access_token (uno armado a mano, o vacío), nada que enmascarar.
        self::assertSame('{}', (string) OAuthToken::constructFrom([]));
        $empty = OAuthToken::constructFrom(['access_token' => '']);
        self::assertSame(['access_token' => ''], $empty->__debugInfo());
        self::assertSame("{\n  \"access_token\": \"\"\n}", (string) $empty);
    }

    /**
     * El de un usuario de prueba de un sandbox (raya_at_test_…): la misma máscara, con su
     * prefijo.
     */
    public function testOAuthTokenShowsASandboxAccessTokenMaskedWithItsPrefix(): void
    {
        $values = [...Fixtures::OAUTH_TOKEN, 'access_token' => Fixtures::SANDBOX_ACCESS_TOKEN];
        $token = self::convertAs(OAuthToken::class, $values);
        self::assertSame(Fixtures::SANDBOX_ACCESS_TOKEN, $token->access_token);
        $masked = 'raya_at_test_…L0sA';
        self::assertSame(
            self::sortedKeys([...$values, 'access_token' => $masked]),
            json_decode((string) $token, true, 512, JSON_THROW_ON_ERROR),
        );
        $printed = print_r($token, true);
        self::assertStringContainsString("[access_token] => {$masked}", $printed);
        $secret = substr(Fixtures::SANDBOX_ACCESS_TOKEN, strlen('raya_at_test_'));
        foreach ([(string) $token, $printed] as $shown) {
            self::assertStringNotContainsString($secret, $shown);
        }
        // Para guardarlo, entero.
        self::assertSame($values, $token->toArray());
    }

    public function testEventHydratesAConnectOrder(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_CONNECT_ORDER_SUCCEEDED);
        self::assertSame(Event::TYPE_CONNECT_ORDER_SUCCEEDED, $event->type);
        self::assertSame('connect.order.succeeded', $event->type);
        $object = $event->data->object;
        self::assertSame(ConnectOrder::class, get_debug_type($object));
        self::assertInstanceOf(ConnectOrder::class, $object);
        self::assertSame(['succeeded', 500], [$object->status, $object->fee]);
        self::assertSame(ConnectOrder\Item::class, get_debug_type($object->items[0]));
    }

    /** Un evento de Identidad trae la verificación, siempre con person en null. */
    public function testEventHydratesAnIdentityVerification(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_VERIFICATION_VERIFIED);
        self::assertSame(Event::TYPE_IDENTITY_VERIFICATION_VERIFIED, $event->type);
        self::assertSame('identity.verification.verified', $event->type);
        $object = $event->data->object;
        self::assertSame(IdentityVerification::class, get_debug_type($object));
        self::assertInstanceOf(IdentityVerification::class, $object);
        self::assertSame(IdentityVerification::STATUS_VERIFIED, $object->status);
        self::assertNull($object->person);
        self::assertSame(IdentityVerification\Checks::class, $object->checks::class);
        // Un evento de la cuenta real.
        self::assertTrue($event->livemode);
        self::assertTrue($event['livemode']);
        $canceled = Event::constructFrom(Fixtures::EVENT_VERIFICATION_CANCELED);
        self::assertSame(Event::TYPE_IDENTITY_VERIFICATION_CANCELED, $canceled->type);
        self::assertSame(
            ['identity.verification.declined', 'identity.verification.expired'],
            [Event::TYPE_IDENTITY_VERIFICATION_DECLINED, Event::TYPE_IDENTITY_VERIFICATION_EXPIRED],
        );
    }

    /**
     * Los eventos de Raya PSP traen el PspCustomer, la PspTransfer o el PspDocumentRequest, cada
     * uno con su clase.
     */
    public function testEventHydratesPspObjects(): void
    {
        $activated = Event::constructFrom(Fixtures::EVENT_PSP_CUSTOMER_ACTIVATED);
        self::assertSame(Event::TYPE_PSP_CUSTOMER_ACTIVATED, $activated->type);
        $pspCustomer = $activated->data->object;
        self::assertSame(PspCustomer::class, get_debug_type($pspCustomer));
        self::assertInstanceOf(PspCustomer::class, $pspCustomer);
        self::assertSame(PspCustomer\Cvu::class, get_debug_type($pspCustomer->cvu));
        self::assertTrue($activated->livemode);

        $received = Event::constructFrom(Fixtures::EVENT_PSP_TRANSFER_RECEIVED);
        self::assertSame('psp.transfer.received', $received->type);
        $pspTransfer = $received->data->object;
        self::assertSame(PspTransfer::class, get_debug_type($pspTransfer));
        self::assertInstanceOf(PspTransfer::class, $pspTransfer);
        self::assertSame(
            [PspTransfer::DIRECTION_INBOUND, 42000],
            [$pspTransfer->direction, $pspTransfer->amount],
        );
        self::assertSame(PspTransfer\Counterparty::class, $pspTransfer->counterparty::class);

        $requested = Event::constructFrom(Fixtures::EVENT_PSP_DOCUMENT_REQUEST_CREATED);
        self::assertSame(Event::TYPE_PSP_DOCUMENT_REQUEST_CREATED, $requested->type);
        $request = $requested->data->object;
        self::assertSame(PspDocumentRequest::class, get_debug_type($request));
        self::assertInstanceOf(PspDocumentRequest::class, $request);
        self::assertSame(Fixtures::PSP_CUSTOMER_ID, $request->customer);
    }

    /** Los invoice.* traen la Invoice, con su motivo y sus mensajes de ARCA con su clase. */
    public function testEventHydratesAnInvoice(): void
    {
        $issued = Event::constructFrom(Fixtures::EVENT_INVOICE_ISSUED);
        self::assertSame(Event::TYPE_INVOICE_ISSUED, $issued->type);
        $invoice = $issued->data->object;
        self::assertSame(Invoice::class, get_debug_type($invoice));
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame([Fixtures::INVOICE_CAE, 128], [$invoice->cae, $invoice->number]);
        self::assertTrue($issued->livemode);

        $rejected = Event::constructFrom(Fixtures::EVENT_INVOICE_REJECTED);
        self::assertSame('invoice.rejected', $rejected->type);
        $invoice = $rejected->data->object;
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice\FailureReason::class, get_debug_type($invoice->failure_reason));
        self::assertSame(Invoice\ArcaMessage::class, get_debug_type($invoice->arca_messages[0]));

        $failed = Event::constructFrom(Fixtures::EVENT_INVOICE_FAILED);
        self::assertSame(Event::TYPE_INVOICE_FAILED, $failed->type);
        $invoice = $failed->data->object;
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(
            [Invoice::STATUS_FAILED, Invoice::FAILURE_CODE_FACTURADOR_NOT_READY],
            [$invoice->status, $invoice->failure_reason?->code],
        );
    }

    public function testMetadataKeysCanShadowDictMethods(): void
    {
        $payment = self::convertAs(
            Payment::class,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => ['items' => '3', 'keys' => 'x']],
        );
        self::assertSame('3', $payment->metadata['items']);
        // Node: las claves de la metadata son tuyas, aunque parezcan un objeto de la API.
        $payment = self::convertAs(
            Payment::class,
            [...Fixtures::PAYMENT_PENDING, 'metadata' => ['object' => 'payment', 'keys' => 'x']],
        );
        self::assertSame(['object' => 'payment', 'keys' => 'x'], $payment->metadata);
    }

    public function testUnknownFieldsAndObjectsAreKept(): void
    {
        $transfer = self::convertAs(Transfer::class, [
            ...Fixtures::TRANSFER_INBOUND,
            'nuevo_campo' => ['a' => 1],
            'otro' => [['object' => 'algo_nuevo', 'x' => 2]],
        ]);
        // Con -> también (PHPStan no conoce los campos nuevos: en tu código, ['nuevo_campo']).
        // @phpstan-ignore property.notFound (a propósito: un campo que el SDK no declara)
        $nuevo = $transfer->nuevo_campo;
        self::assertInstanceOf(RayaObject::class, $nuevo);
        self::assertSame(RayaObject::class, $nuevo::class);
        self::assertSame(['a' => 1], $nuevo->toArray());
        $otro = $transfer['otro'];
        self::assertIsArray($otro);
        self::assertInstanceOf(RayaObject::class, $otro[0]);
        self::assertSame(RayaObject::class, $otro[0]::class);
        self::assertSame(2, $otro[0]['x']);
        self::assertSame(RayaObject::class, get_debug_type(self::convert(['object' => 'nuevo'])));
        self::assertSame([1, 'x', null], self::convert([1, 'x', null]));
    }

    public function testMissingFieldsRaiseAttributeError(): void
    {
        // El AttributeError de Python es un \OutOfBoundsException.
        $balance = self::convertAs(
            Balance::class,
            ['object' => 'balance', 'available' => 1, 'currency' => 'ARS'],
        );
        $message = "Balance no tiene el campo 'inexistente'. Campos: available, currency, object.";
        $error = self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $balance->inexistente,
        );
        self::assertSame($message, $error->getMessage());
        // Python: balance._privado también falla. En PHP no hay regla para el «_»: es un campo.
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $balance->_privado,
            "Balance no tiene el campo '_privado'.",
        );
        // Python: balance["inexistente"] es KeyError. Acá, el mismo error que con ->.
        $error = self::assertThrows(
            \OutOfBoundsException::class,
            static fn() => $balance['inexistente'],
        );
        self::assertSame($message, $error->getMessage());
        // getattr(balance, "inexistente", "default") en Python: ?? e isset nunca fallan.
        self::assertSame('default', $balance->inexistente ?? 'default');
        self::assertSame('default', $balance['inexistente'] ?? 'default');
        self::assertFalse(isset($balance->inexistente));
        self::assertFalse(isset($balance['inexistente']));

        // Un campo en null existe: se lee sin error, pero isset y ?? lo ven como ausente.
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        self::assertNull($payment->paid_at);
        self::assertFalse(isset($payment->paid_at));
        self::assertSame('todavía no', $payment->paid_at ?? 'todavía no');
        // El nombre corto de la clase, también en los sub-objetos; (ninguno) si no hay campos.
        $transfer = self::convertAs(Transfer::class, Fixtures::TRANSFER_INBOUND);
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $transfer->counterparty->cbu,
            "Counterparty no tiene el campo 'cbu'. Campos: account, alias, name, tax_id.",
        );
        $empty = RayaObject::constructFrom([]);
        self::assertThrows(
            \OutOfBoundsException::class,
            // @phpstan-ignore property.notFound (a propósito: el campo no existe)
            static fn() => $empty->x,
            "RayaObject no tiene el campo 'x'. Campos: (ninguno).",
        );
        // Un número no es un campo que falta: con [0] es el mismo error, con otra cosa, TypeError.
        self::assertThrows(
            \OutOfBoundsException::class,
            static fn() => $balance[0],
            "Balance no tiene el campo '0'.",
        );
        self::assertThrows(
            \TypeError::class,
            // @phpstan-ignore offsetAccess.notFound (lo que haría alguien sin PHPStan)
            static fn() => $balance[1.5],
            "Los campos de Balance se leen con su nombre, como \$obj['status'], no con float.",
        );
    }

    public function testSettingAndDeletingFields(): void
    {
        $object = RayaObject::constructFrom([]);
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        $object->nombre = 'x';
        self::assertSame(['nombre' => 'x'], self::fieldsOf($object));
        unset($object->nombre);
        self::assertSame([], self::fieldsOf($object));
        // Python: borrarlo otra vez es AttributeError. En PHP, como unset en un array: nada.
        unset($object->nombre);
        self::assertSame([], self::fieldsOf($object));
        // Python: un atributo con «_» no es un dato. En PHP no hay esa regla: es un campo más.
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        $object->_interno = 1;
        self::assertSame(['_interno' => 1], self::fieldsOf($object));
        self::assertSame(1, $object['_interno']);
        unset($object->_interno);
        self::assertSame([], self::fieldsOf($object));

        // Con [] es lo mismo; sin nombre de campo, no.
        $object['nombre'] = 'y';
        // @phpstan-ignore property.notFound (a propósito: RayaObject no declara campos)
        self::assertSame('y', $object->nombre);
        self::assertTrue(isset($object['nombre']));
        unset($object['nombre']);
        self::assertSame([], self::fieldsOf($object));
        self::assertThrows(
            \TypeError::class,
            static function () use ($object): void {
                $object[] = 'x';
            },
            "RayaObject no acepta \$obj[] = …: poné el nombre del campo, como \$obj['campo'] = "
                . '$valor.',
        );
        self::assertSame([], self::fieldsOf($object));
    }

    public function testStrAndRepr(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        // str() en Python: JSON con las claves ordenadas y 2 espacios de sangría.
        self::assertSame(
            self::sortedKeys(Fixtures::PAYMENT_PENDING),
            json_decode((string) $payment, true, 512, JSON_THROW_ON_ERROR),
        );
        $object = RayaObject::constructFrom([
            'b' => ['y' => 1, 'x' => 'María/Ana'],
            'a' => 25000.0,
            'c' => null,
            'd' => new \stdClass(),
            'e' => [],
            'f' => [2, 1],
        ]);
        self::assertSame(
            "{\n"
                . "  \"a\": 25000.0,\n"
                . "  \"b\": {\n"
                . "    \"x\": \"María/Ana\",\n"
                . "    \"y\": 1\n"
                . "  },\n"
                . "  \"c\": null,\n"
                . "  \"d\": {},\n"
                . "  \"e\": [],\n"
                . "  \"f\": [\n"
                . "    2,\n"
                . "    1\n"
                . "  ]\n"
                . '}',
            (string) $object,
        );
        self::assertSame('{}', (string) RayaObject::constructFrom([]));
        // repr() en Python («<Payment payment id=… at 0x…> JSON: {…}»): en PHP, print_r y
        // var_dump, que muestran la clase y los campos.
        self::assertStringStartsWith(
            "RayaCash\\Payment Object\n(\n    [id] => " . Fixtures::PAYMENT_ID . "\n",
            print_r($payment, true),
        );
        self::assertSame(
            "RayaCash\\RayaObject Object\n(\n)\n",
            print_r(RayaObject::constructFrom([]), true),
        );
    }

    /**
     * sort_keys en Python ordena las claves en todos los niveles, también las de un dict que le
     * asignaste vos: acá, las de un array asociativo (las listas quedan en su orden).
     */
    public function testStrSortsTheKeysOfWhatYouSetToo(): void
    {
        $object = RayaObject::constructFrom(['b' => 1]);
        $object['a'] = ['z' => 1, 'y' => ['x' => [3, 2], 'w' => null]];
        self::assertSame(
            "{\n"
                . "  \"a\": {\n"
                . "    \"y\": {\n"
                . "      \"w\": null,\n"
                . "      \"x\": [\n"
                . "        3,\n"
                . "        2\n"
                . "      ]\n"
                . "    },\n"
                . "    \"z\": 1\n"
                . "  },\n"
                . "  \"b\": 1\n"
                . '}',
            (string) $object,
        );
    }

    public function testPickleCopyAndDeepcopy(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        // pickle es serialize.
        $restored = unserialize(serialize($payment));
        self::assertInstanceOf(Payment::class, $restored);
        self::assertSame(Payment::class, $restored::class);
        self::assertNotNull($restored->payer);
        self::assertSame(Payment\Payer::class, $restored->payer::class);
        self::assertEquals($payment, $restored);
        self::assertSame($payment->toArray(), $restored->toArray());
        // restored._requestor en Python: acá vive en el token (ObjectToken, interno).
        self::assertNull(ObjectToken::of($restored)->requestor());

        // copy.copy y copy.deepcopy son clone: copia también los sub-objetos.
        $shallow = clone $payment;
        self::assertSame(Payment::class, $shallow::class);
        self::assertEquals($payment, $shallow);

        $deep = clone $payment;
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $deep->metadata['cliente_id'] = 'otro';
        self::assertSame('8812', $payment->metadata['cliente_id']);
        $payer = $deep->payer;
        self::assertNotNull($payer);
        self::assertNotSame($payment->payer, $payer);
        $payer['name'] = 'Otra Persona';
        self::assertSame('Otra Persona', $deep->payer?->name);
        self::assertSame('María Ejemplo', $payment->payer?->name);
        // También los objetos dentro de una lista.
        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        $copy = clone $page;
        $transfer = $copy->data[0];
        self::assertInstanceOf(Transfer::class, $transfer);
        $transfer['description'] = 'Otra';
        $original = $page->data[0];
        self::assertInstanceOf(Transfer::class, $original);
        self::assertNotSame($original, $transfer);
        self::assertSame('Alquiler octubre', $original->description);
    }

    public function testEventHydratesItsObject(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_PAYMENT_SUCCEEDED);
        self::assertSame(Event::class, $event::class);
        self::assertSame(Event\Data::class, $event->data::class);
        $object = $event->data->object;
        self::assertSame(Payment::class, $object::class);
        self::assertInstanceOf(Payment::class, $object);
        self::assertSame('succeeded', $object->status);
        $testEvent = Event::constructFrom([
            'id' => 'evt_x',
            'object' => 'event',
            'type' => 'webhook.test',
            'data' => ['object' => ['object' => 'test']],
        ]);
        self::assertSame(RayaObject::class, $testEvent->data->object::class);
    }

    /**
     * Un evento de un sandbox trae livemode en false, entre created_at y data (el orden del
     * sobre); uno de la cuenta real, en true.
     */
    public function testEventLivemodeIsFalseInASandbox(): void
    {
        $event = Event::constructFrom(Fixtures::EVENT_SANDBOX_PAYMENT_SUCCEEDED);
        self::assertFalse($event->livemode);
        self::assertFalse($event['livemode']);
        self::assertTrue(isset($event->livemode));
        self::assertSame(
            ['id', 'object', 'type', 'api_version', 'created_at', 'livemode', 'data'],
            array_keys($event->toArray()),
        );
        self::assertSame(Event::TYPE_PAYMENT_SUCCEEDED, $event->type);
        // El pago de prueba, con el CVU de prueba y el pagador de prueba.
        $payment = $event->data->object;
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(
            ['succeeded', '0000000271940365218453', 'sandbox.no.transferir'],
            [$payment->status, $payment->bank_transfer->cvu, $payment->bank_transfer->alias],
        );
        self::assertSame(Fixtures::SANDBOX_PAYER['name'], $payment->payer?->name);
        self::assertStringStartsWith('https://raya.cash/sandbox/pay/', $payment->checkout_url);
        // Uno de la cuenta real.
        self::assertTrue(Event::constructFrom(Fixtures::EVENT_VERIFICATION_CANCELED)->livemode);
    }

    /**
     * Un evento creado antes de que la API sumara livemode no lo trae si se reenvía (el body es
     * el que se guardó): se arma igual, sin el campo, y es de la cuenta real.
     */
    public function testEventWithoutLivemodeStaysAsItCame(): void
    {
        self::assertArrayNotHasKey('livemode', Fixtures::EVENT_PAYMENT_SUCCEEDED);
        $event = Event::constructFrom(Fixtures::EVENT_PAYMENT_SUCCEEDED);
        self::assertFalse(isset($event->livemode));
        self::assertTrue($event->livemode ?? true);
        self::assertNull($event['livemode'] ?? null);
        self::assertArrayNotHasKey('livemode', $event->toArray());
        self::assertSame(
            json_encode(Fixtures::EVENT_PAYMENT_SUCCEEDED, JSON_THROW_ON_ERROR),
            json_encode($event, JSON_THROW_ON_ERROR),
        );
        // Leerlo sin ?? es leer un campo que no vino.
        self::assertThrows(
            \OutOfBoundsException::class,
            static fn() => $event->livemode,
            "Event no tiene el campo 'livemode'.",
        );
        self::assertInstanceOf(Payment::class, $event->data->object);
    }

    public function testListObjectIteratesItsData(): void
    {
        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        $first = Fixtures::TRANSFER_LIST['data'][0]['id'];
        $second = Fixtures::TRANSFER_LIST['data'][1]['id'];
        $ids = [];
        foreach ($page as $transfer) {
            self::assertInstanceOf(Transfer::class, $transfer);
            $ids[] = $transfer->id;
        }
        self::assertSame([$first, $second], $ids);
        self::assertCount(2, $page);
        // next(reversed(page)) en Python: el último de la página.
        $reversed = array_reverse(iterator_to_array($page));
        self::assertInstanceOf(Transfer::class, $reversed[0]);
        self::assertSame($second, $reversed[0]->id);
        self::assertSame(
            [Transfer::class, Transfer::class],
            array_map(get_debug_type(...), $page->data),
        );
        self::assertTrue($page['has_more']);
        self::assertSame(Fixtures::NEXT_CURSOR, $page->next_cursor);
        self::assertSame(Fixtures::TRANSFER_LIST, self::jsonRoundTrip($page));
        self::assertSame(Fixtures::TRANSFER_LIST, $page->toArray());
        // Python: page[0] es KeyError y dice dónde están los elementos.
        $error = self::assertThrows(\OutOfBoundsException::class, static fn() => $page[0]);
        self::assertSame(
            'Una ListObject no se indexa con 0: los elementos están en ->data (por ejemplo, '
                . '$page->data[0]).',
            $error->getMessage(),
        );
        self::assertFalse(isset($page[0]));
        // PHP: lo que no es un nombre ni un int da el TypeError de cualquier objeto (como en
        // testMissingFieldsRaiseAttributeError).
        foreach (['float' => 1.5, 'bool' => true] as $type => $offset) {
            $error = self::assertThrows(
                \TypeError::class,
                // @phpstan-ignore offsetAccess.notFound (lo que haría alguien sin PHPStan)
                static fn() => $page[$offset],
            );
            self::assertSame(
                "Los campos de ListObject se leen con su nombre, como \$obj['status'], no con "
                    . "{$type}.",
                $error->getMessage(),
            );
        }
    }

    public function testListObjectWithoutData(): void
    {
        $page = ListObject::constructFrom(['object' => 'list']);
        self::assertSame([], iterator_to_array($page));
        self::assertCount(0, $page);
    }

    // --- De Node ----------------------------------------------------------------------------

    /**
     * Como el «__proto__» de Node: un campo del JSON que se llama como las propiedades internas
     * de RayaObject (values, token) es un dato más y no pisa nada.
     */
    public function testJsonFieldsNamedLikeTheInternalsStayAsData(): void
    {
        $client = $this->api->client();
        $this->api->add(
            200,
            ['object' => 'transfer', 'id' => 't', 'values' => [1], 'token' => 'x'],
        );
        $transfer = $client->transfers->retrieve('t');
        self::assertSame(Transfer::class, $transfer::class);
        self::assertSame([1], $transfer['values']);
        self::assertSame('x', $transfer['token']);
        foreach (['values' => [1], 'token' => 'x'] as $name => $value) {
            self::assertTrue(isset($transfer->{$name}));
            self::assertSame($value, $transfer->{$name});
        }
        self::assertSame(
            ['object' => 'transfer', 'id' => 't', 'values' => [1], 'token' => 'x'],
            $transfer->toArray(),
        );
        self::assertSame('req_test00000000000000000001', $transfer->getLastResponse()?->requestId);
    }

    /** Como console.log en Node: los dumps muestran la clase y los campos, no el cliente. */
    public function testDumpsShowTheFieldsButNotTheClientOrTheResponse(): void
    {
        $this->api->add(200, Fixtures::PAYMENT_PENDING);
        $payment = $this->api->client()->payments->retrieve(Fixtures::PAYMENT_ID);
        self::assertNotNull($payment->getLastResponse());
        $printed = print_r($payment, true);
        self::assertStringStartsWith("RayaCash\\Payment Object\n(\n", $printed);
        self::assertStringContainsString('[status] => pending', $printed);
        ob_start();
        var_dump($payment);
        $dumped = (string) ob_get_clean();
        // Sin mirar el formato exacto: con Xdebug en modo develop, var_dump es otro.
        self::assertStringContainsString('RayaCash\Payment', $dumped);
        self::assertStringContainsString('string(7) "pending"', $dumped);
        $exported = var_export($payment, true);
        self::assertStringContainsString("'status' => 'pending'", $exported);
        foreach ([$printed, $dumped, $exported, serialize($payment)] as $shown) {
            self::assertStringNotContainsString('req_test', $shown);
            self::assertStringNotContainsString('Requestor', $shown);
            self::assertStringNotContainsString('RayaResponse', $shown);
            self::assertStringNotContainsString('raya_sk_live_', $shown);
        }
    }

    public function testConstructFromNeedsAnObjectAndHasNoResponse(): void
    {
        $payment = Payment::constructFrom(['object' => 'payment', 'id' => 'p']);
        self::assertSame(Payment::class, $payment::class);
        self::assertNull($payment->getLastResponse());
        $error = self::assertThrows(
            \TypeError::class,
            static fn() => Payment::constructFrom(['a', 'b']),
        );
        self::assertSame(
            'constructFrom necesita un objeto JSON (un array asociativo o un stdClass), no una '
                . 'lista.',
            $error->getMessage(),
        );
        // [] es el objeto vacío ({}) y un stdClass también sirve.
        self::assertSame([], Payment::constructFrom([])->toArray());
        self::assertSame('p', Payment::constructFrom((object) ['id' => 'p'])->id);
        // La clase es la que llamás, como construct_from en Python.
        self::assertSame(Payment::class, Payment::constructFrom(['object' => 'transfer'])::class);
    }

    // --- Solo de PHP ------------------------------------------------------------------------

    /** json_decode(…, false) distingue {} de []: json_encode($obj) da el JSON tal cual vino. */
    public function testEmptyObjectsStayObjects(): void
    {
        $json = '{"object":"payment","id":"p","metadata":{},"bank_transfer":{},"nuevo":{},'
            . '"lista":[],"anidado":{"vacio":{},"lista":[]}}';
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $payment = Hydrator::convert($decoded, null, null);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame($json, json_encode($payment, JSON_THROW_ON_ERROR));
        // toArray() da arrays comunes: ahí {} y [] son lo mismo (declarado en el CHANGELOG).
        self::assertSame(
            [
                'object' => 'payment',
                'id' => 'p',
                'metadata' => [],
                'bank_transfer' => [],
                'nuevo' => [],
                'lista' => [],
                'anidado' => ['vacio' => [], 'lista' => []],
            ],
            $payment->toArray(),
        );
        // La metadata vacía de un pago armado a mano también sale como objeto.
        self::assertSame(
            '{"object":"payment","metadata":{}}',
            json_encode(Payment::constructFrom(['object' => 'payment', 'metadata' => []])),
        );

        // Lo mismo con las claves "0", "1"…: PHP las convierte en una lista, que toArray() deja
        // así (declarado en el CHANGELOG). json_encode y (string) conservan el objeto.
        $json = '{"object":"payment","metadata":{"0":"a","1":"b"},"nuevo":{"0":"x"}}';
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $payment = Hydrator::convert($decoded, null, null);
        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame($json, json_encode($payment, JSON_THROW_ON_ERROR));
        self::assertSame(
            ['object' => 'payment', 'metadata' => ['a', 'b'], 'nuevo' => ['x']],
            $payment->toArray(),
        );
        self::assertStringContainsString(
            "  \"metadata\": {\n    \"0\": \"a\",\n    \"1\": \"b\"\n  },\n"
                . "  \"nuevo\": {\n    \"0\": \"x\"\n  },\n",
            (string) $payment,
        );
    }

    /**
     * (string) $obj escribe los floats como json.dumps en Python, con cualquier
     * serialize_precision (17 era el de fábrica antes de PHP 7.1): 1500.1 y no
     * 1500.0999999999999, 1e-07 y no 1.0e-7, Infinity y NaN. U+2028 y U+2029 quedan tal cual,
     * como con ensure_ascii=False.
     */
    public function testStrDoesNotDependOnPhpIni(): void
    {
        $object = RayaObject::constructFrom([
            'amount' => 1500.1,
            'fee' => 0.1,
            'total' => 25000.0,
            'tiny' => 1e-7,
            'big' => 1e16,
            'zero' => -0.0,
            'limits' => [INF, -INF, NAN],
            'description' => "Línea 1\u{2028}Línea 2\u{2029}",
        ]);
        // El str() de Python con los mismos valores.
        $python = "{\n"
            . "  \"amount\": 1500.1,\n"
            . "  \"big\": 1e+16,\n"
            . "  \"description\": \"Línea 1\u{2028}Línea 2\u{2029}\",\n"
            . "  \"fee\": 0.1,\n"
            . "  \"limits\": [\n"
            . "    Infinity,\n"
            . "    -Infinity,\n"
            . "    NaN\n"
            . "  ],\n"
            . "  \"tiny\": 1e-07,\n"
            . "  \"total\": 25000.0,\n"
            . "  \"zero\": -0.0\n"
            . '}';
        $ini = ini_get('serialize_precision');
        try {
            foreach (['-1', '17'] as $precision) {
                ini_set('serialize_precision', $precision);
                self::assertSame($python, (string) $object, "serialize_precision = {$precision}");
            }
        } finally {
            ini_set('serialize_precision', (string) $ini);
        }

        // Lo que no es del SDK (una fecha que asignaste vos) lo escribe json_encode, con la misma
        // sangría. json.dumps de Python no puede.
        $object = RayaObject::constructFrom(['b' => 1]);
        $object['a'] = new \DateTimeImmutable('2026-10-24 14:00:00', new \DateTimeZone('UTC'));
        self::assertSame(
            "{\n"
                . "  \"a\": {\n"
                . "    \"date\": \"2026-10-24 14:00:00.000000\",\n"
                . "    \"timezone_type\": 3,\n"
                . "    \"timezone\": \"UTC\"\n"
                . "  },\n"
                . "  \"b\": 1\n"
                . '}',
            (string) $object,
        );
    }

    /**
     * Los campos vuelven por referencia: un array o un sub-objeto se cambia en el lugar, como en
     * Python y Node, sin el «Indirect modification» de PHP.
     */
    public function testNestedValuesChangeInPlace(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_PENDING);
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $payment->metadata['pedido'] = '1042';
        // @phpstan-ignore offsetAccess.nonOffsetAccessible ($payment['metadata'] es mixed)
        $payment['metadata']['canal'] = 'web';
        self::assertSame(
            ['cliente_id' => '8812', 'pedido' => '1042', 'canal' => 'web'],
            $payment->metadata,
        );
        self::assertStringContainsString(
            '"metadata":{"cliente_id":"8812","pedido":"1042","canal":"web"}',
            json_encode($payment, JSON_THROW_ON_ERROR),
        );
        $bankTransfer = $payment->bank_transfer; // un objeto: es el mismo, no una copia
        $bankTransfer['alias'] = null;
        self::assertSame(
            ['cvu' => '0000123322813466534007', 'alias' => null],
            $payment->toArray()['bank_transfer'],
        );
        // Lo que guardás en una variable es una copia: el pago no cambia.
        $metadata = $payment->metadata;
        $metadata['otro'] = 'x';
        self::assertArrayNotHasKey('otro', $payment->metadata);

        $page = self::convertAs(ListObject::class, Fixtures::TRANSFER_LIST);
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $page->data[] = Transfer::constructFrom(Fixtures::TRANSFER_OUTBOUND_PENDING);
        self::assertCount(3, $page);

        // Un clon de un objeto ya cambiado por referencia sigue siendo independiente.
        $copy = clone $payment;
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['pedido'] = '2000';
        $copyBankTransfer = $copy->bank_transfer;
        $copyBankTransfer['alias'] = 'otro.alias';
        self::assertSame('1042', $payment->metadata['pedido']);
        self::assertNull($payment->bank_transfer->alias);
    }

    /**
     * Un clon no comparte nada con el original, como copy y deepcopy en Python, aunque tengas
     * una referencia a uno de sus campos ($ref = &$payment->bank_transfer, o lo que deja un
     * foreach por referencia): sin eso, el clon escribiría a través de ella.
     */
    public function testCloneIsIndependentEvenWithReferences(): void
    {
        $payment = self::convertAs(Payment::class, Fixtures::PAYMENT_SUCCEEDED);
        $original = $payment->bank_transfer;
        $bankTransfer = &$payment->bank_transfer;
        $metadata = &$payment->metadata;
        $clientId = &$payment->metadata['cliente_id'];
        $status = &$payment->status;

        $copy = clone $payment;
        self::assertSame($original, $payment->bank_transfer);
        self::assertNotSame($original, $copy->bank_transfer);
        $copyBankTransfer = $copy->bank_transfer;
        $copyBankTransfer['alias'] = 'otro.alias';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['canal'] = 'web';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->metadata['cliente_id'] = 'otro';
        // @phpstan-ignore assign.propertyReadOnly (documentado de solo lectura, pero se puede)
        $copy->status = Payment::STATUS_REFUNDED;
        self::assertSame(Fixtures::PAYMENT_SUCCEEDED, $payment->toArray());
        self::assertSame('otro.alias', $copy->bank_transfer->alias);
        self::assertSame(['cliente_id' => 'otro', 'canal' => 'web'], $copy->metadata);
        self::assertSame(Payment::STATUS_REFUNDED, $copy->status);

        // Las referencias siguen siendo del original.
        $bankTransfer = null;
        $metadata['pedido'] = '1042';
        $clientId = '9000';
        $status = Payment::STATUS_FAILED;
        self::assertSame(
            [null, ['cliente_id' => '9000', 'pedido' => '1042'], Payment::STATUS_FAILED],
            [$payment['bank_transfer'], $payment->metadata, $payment->status],
        );
        self::assertSame($copyBankTransfer, $copy->bank_transfer);
        self::assertSame(['cliente_id' => 'otro', 'canal' => 'web'], $copy->metadata);
        self::assertSame(Payment::STATUS_REFUNDED, $copy->status);
    }

    public function testSerializeDropsTheClientAndCloneKeepsIt(): void
    {
        $client = $this->api->client();
        $this->api->add(200, Fixtures::PAYMENT_SUCCEEDED);
        $payment = $client->payments->retrieve(Fixtures::PAYMENT_ID);
        $response = $payment->getLastResponse();
        self::assertNotNull($response);
        $refund = '/v1/payments/' . Fixtures::PAYMENT_ID . '/refund';

        // clone conserva el cliente y la respuesta, como copy en Python.
        $copy = clone $payment;
        self::assertSame($response, $copy->getLastResponse());
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        self::assertSame('refunded', $copy->refund()->status);
        self::assertSame(['POST', $refund], [$this->api->last()->method, $this->api->last()->path]);
        self::assertSame(
            'Bearer ' . Fixtures::TEST_KEY,
            $this->api->last()->header('authorization'),
        );

        // Los sub-objetos de un clon también: un pago dentro de otro objeto.
        $this->api->add(200, ['object' => 'algo_nuevo', 'pago' => Fixtures::PAYMENT_SUCCEEDED]);
        $wrapper = $client->rawRequest('GET', '/v1/algo_nuevo');
        $nested = (clone $wrapper)['pago'];
        self::assertInstanceOf(Payment::class, $nested);
        $this->api->add(200, Fixtures::PAYMENT_REFUNDED);
        $nested->refund();
        self::assertSame($refund, $this->api->last()->path);
        self::assertCount(4, $this->api->requests);

        // serialize no lleva el cliente ni la respuesta, como pickle: uno deserializado usa el
        // estilo global.
        $restored = unserialize(serialize($payment));
        self::assertInstanceOf(Payment::class, $restored);
        self::assertNull($restored->getLastResponse());
        $global = new MockApi();
        RayaCash::setApiKey(Fixtures::OTHER_KEY);
        RayaCash::setHttpClient($global);
        $global->add(200, Fixtures::PAYMENT_REFUNDED);
        $restored->refund();
        self::assertCount(1, $global->requests);
        self::assertSame($refund, $global->last()->path);
        self::assertSame('Bearer ' . Fixtures::OTHER_KEY, $global->last()->header('authorization'));
        self::assertCount(4, $this->api->requests);
    }

    /** Un objeto serializado sin sus datos (por otra versión, o tocado a mano) vuelve vacío. */
    public function testUnserializeWithoutTheDataGivesAnEmptyObject(): void
    {
        $payloads = [
            'O:16:"RayaCash\Payment":0:{}',
            'O:16:"RayaCash\Payment":1:{s:6:"values";s:4:"otro";}',
        ];
        foreach ($payloads as $payload) {
            $payment = unserialize($payload);
            self::assertInstanceOf(Payment::class, $payment);
            self::assertSame([], $payment->toArray());
            self::assertSame('{}', json_encode($payment));
            self::assertNull($payment->getLastResponse());
        }
    }

    /**
     * constructFrom acepta objetos del SDK adentro, como en Python (donde un RayaObject es un
     * dict): sirve para armar en tus tests un evento con un pago que ya tenés. Adentro quedan
     * copias, con la clase de su "object" o la de su campo.
     */
    public function testConstructFromAcceptsObjectsOfTheSdkInside(): void
    {
        $payment = Payment::constructFrom(Fixtures::PAYMENT_SUCCEEDED);
        $event = Event::constructFrom(
            ['data' => ['object' => $payment]] + Fixtures::EVENT_PAYMENT_SUCCEEDED,
        );
        $object = $event->data->object;
        self::assertInstanceOf(Payment::class, $object);
        self::assertNotSame($payment, $object);
        self::assertSame($payment->toArray(), $object->toArray());
        self::assertSame(Payment\Payer::class, get_debug_type($object->payer));
        $object['status'] = 'refunded';
        self::assertSame('succeeded', $payment->status);

        $payer = RayaObject::constructFrom(['name' => 'Ana']);
        $other = Payment::constructFrom(['object' => 'payment', 'payer' => $payer]);
        self::assertSame(Payment\Payer::class, get_debug_type($other->payer));
        self::assertSame('Ana', $other->payer?->name);
    }

    /**
     * La clase sale de "object"; si no está o el SDK no lo conoce, de lo que esperaba el método
     * (solo en el primer nivel). Otro tipo conocido no se puede devolver: respuesta inválida.
     */
    public function testUnknownObjectTypesHydrateAsTheExpectedClass(): void
    {
        $client = $this->api->client();
        $this->api->add(200, ['available' => 1, 'currency' => 'ARS']);
        self::assertSame(Balance::class, $client->balance->retrieve()::class);
        $this->api->add(200, ['object' => 'balance_v2', 'available' => 1, 'nuevo' => ['x' => 1]]);
        $balance = $client->balance->retrieve();
        self::assertSame(Balance::class, $balance::class);
        self::assertSame(RayaObject::class, get_debug_type($balance['nuevo']));

        $calls = [
            'un saldo' => [Fixtures::PAYMENT_PENDING, static fn() => $client->balance->retrieve()],
            'una transferencia' => [
                Fixtures::BALANCE,
                static fn() => $client->transfers->retrieve('t'),
            ],
            'un pago' => [
                Fixtures::TRANSFER_INBOUND,
                static fn() => $client->payments->retrieve('p'),
            ],
            'un access_token' => [
                Fixtures::USER,
                static fn() => $client->oauth->token([
                    'client_id' => Fixtures::CLIENT_ID,
                    'client_secret' => Fixtures::CLIENT_SECRET,
                    'code' => Fixtures::CODE,
                    'redirect_uri' => Fixtures::REDIRECT_URI,
                ]),
            ],
            'un usuario' => [
                Fixtures::CONNECT_ORDER_PENDING,
                static fn() => $client->users->retrieve('me'),
            ],
            'un pago de Connect' => [
                Fixtures::OAUTH_TOKEN,
                static fn() => $client->connect->orders->retrieve('c'),
            ],
        ];
        foreach ($calls as $noun => [$body, $call]) {
            $this->api->add(200, $body);
            $error = self::assertThrows(
                ApiException::class,
                $call,
                "La API respondió algo que no es {$noun}.",
                'INVALID_RESPONSE',
            );
            self::assertSame("La API respondió algo que no es {$noun}.", $error->getMessage());
            self::assertSame(200, $error->getHttpStatus());
            // Con la respuesta que lo causó, como cualquier error de la API.
            self::assertSame(
                sprintf('req_test%020d', count($this->api->requests)),
                $error->getRequestId(),
            );
            self::assertSame($body, json_decode((string) $error->getHttpBody(), true));
        }
        self::assertCount(8, $this->api->requests); // no se reintenta
    }

    /**
     * Como convert_to_raya_object en Python: el valor, pasado por JSON y decodificado como lo hace
     * el SDK (los objetos como stdClass), convertido en objetos del SDK.
     */
    private static function convert(mixed $value): mixed
    {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        return Hydrator::convert(json_decode($json, false, 512, JSON_THROW_ON_ERROR), null, null);
    }

    /**
     * convert(), que tiene que dar exactamente esa clase (como `type(x) is Clase` en Python).
     *
     * @template T of RayaObject
     * @param class-string<T> $class
     * @return T
     */
    private static function convertAs(string $class, mixed $value): RayaObject
    {
        $object = self::convert($value);
        self::assertSame($class, get_debug_type($object));
        self::assertInstanceOf($class, $object);
        return $object;
    }

    /**
     * Los datos del objeto en este momento (toArray()). Impuro para PHPStan, que si no recordaría
     * el resultado de la llamada anterior aunque el objeto haya cambiado en el medio.
     *
     * @phpstan-impure
     * @return array<array-key, mixed>
     */
    private static function fieldsOf(RayaObject $object): array
    {
        return $object->toArray();
    }

    /** json.loads(json.dumps(obj)) en Python: el JSON de json_encode(), con arrays comunes. */
    private static function jsonRoundTrip(\JsonSerializable $object): mixed
    {
        $json = json_encode($object, JSON_THROW_ON_ERROR);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Las claves ordenadas, en todos los niveles (las listas quedan como están), como
     * sort_keys=True en Python.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortedKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(
            static fn(mixed $item): mixed => is_array($item) ? self::sortedKeys($item) : $item,
            $value,
        );
    }
}
