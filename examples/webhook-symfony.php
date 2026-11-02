<?php

declare(strict_types=1);

// Webhooks con Symfony: $request->getContent() da el body crudo para verificar la firma.
//
// Copialo a src/Controller/RayaWebhookController.php. El RayaClient sale de config/services.yaml:
//
//   services:
//       RayaCash\RayaClient:
//           arguments:
//               $apiKey: '%env(RAYA_SECRET_KEY)%'
//
// y el secreto, de RAYA_WEBHOOK_SECRET (en .env.local o en el entorno del servidor).

namespace App\Controller;

use Psr\Log\LoggerInterface;
use RayaCash\Event;
use RayaCash\Exception\SignatureVerificationException;
use RayaCash\Payment;
use RayaCash\RayaClient;
use RayaCash\Transfer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class RayaWebhookController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'RAYA_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {}

    #[Route('/webhooks/raya', name: 'raya_webhook', methods: ['POST'])]
    public function __invoke(Request $request, RayaClient $client): Response
    {
        try {
            // Ligado a $client: $event->data->object->refund() usaría su clave.
            $event = $client->constructEvent(
                $request->getContent(), // el body crudo: $request->toArray() lo re-serializaría
                $request->headers->get('Raya-Signature'),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException) {
            return new Response('Firma inválida', Response::HTTP_BAD_REQUEST);
        }

        // Guardá $event->id en tu base (con un índice único) para procesar cada evento una sola
        // vez y, si el proceso es lento, mandalo a Messenger y respondé enseguida: hay 15 s.
        $object = $event->data->object ?? null; // ?? porque no todo evento trae data
        if ($event->type === Event::TYPE_PAYMENT_SUCCEEDED && $object instanceof Payment) {
            // Los eventos pueden llegar desordenados: si necesitás el estado actual, consultalo.
            $payment = $client->payments->retrieve($object->id);
            if ($payment->status === Payment::STATUS_SUCCEEDED) {
                $this->logger->info("Entregá el pedido {$payment->external_reference}");
            }
        } elseif ($event->type === Event::TYPE_TRANSFER_RECEIVED && $object instanceof Transfer) {
            $this->logger->info("Depósito de \${$object->net_amount}");
        }
        // webhook.test y los tipos que no conocés: respondé 2xx e ignoralos.
        return new Response('', Response::HTTP_OK);
    }
}
