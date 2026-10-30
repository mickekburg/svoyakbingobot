<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bot\BotHandler;
use App\Telegram\Update;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class WebhookController
{
    public function __construct(
        private readonly BotHandler $handler,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Telegram echoes the secret given to setWebhook in this header; anything else is not Telegram
        $given = (string) $request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');
        if ($this->webhookSecret === '' || !hash_equals($this->webhookSecret, $given)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $update = is_array($data) ? Update::fromArray($data) : null;

        if ($update !== null) {
            try {
                $this->handler->handle($update);
            } catch (\Throwable $e) {
                // Always answer 200: a non-200 makes Telegram redeliver the same update again and again.
                $this->logger->error('Update handling failed', [
                    'update_id' => $update->updateId,
                    'exception' => $e,
                ]);
            }
        }

        return new JsonResponse(['ok' => true]);
    }
}
