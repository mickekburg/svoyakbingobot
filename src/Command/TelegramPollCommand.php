<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\BotHandler;
use App\Bot\QuizStore;
use App\Telegram\TelegramApi;
use App\Telegram\TelegramApiException;
use App\Telegram\Update;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Long polling: handy for development, where there is no public HTTPS address for a webhook. */
#[AsCommand(name: 'app:telegram:poll', description: 'Run the bot by long polling (removes the webhook first)')]
final class TelegramPollCommand extends Command
{
    private bool $stop = false;

    public function __construct(
        private readonly TelegramApi $api,
        private readonly BotHandler $handler,
        private readonly QuizStore $store,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds Telegram holds an empty request open', '25')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch of updates and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = max(1, min(50, (int) $input->getOption('timeout')));

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->stop = true;
                });
            }
        }

        try {
            // getUpdates and a webhook exclude each other
            $this->api->call('deleteWebhook');
            $me = $this->api->call('getMe');
        } catch (TelegramApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('Polling as @%s. Press Ctrl+C to stop.', is_array($me) ? (string) ($me['username'] ?? '?') : '?'));

        $offset = 0;
        while (!$this->stop) {
            try {
                $updates = $this->api->call('getUpdates', [
                    'offset' => $offset,
                    'timeout' => $timeout,
                    'allowed_updates' => ['message', 'callback_query'],
                ], $timeout + 10.0);
            } catch (TelegramApiException $e) {
                $this->logger->error('getUpdates failed', ['error' => $e->getMessage()]);
                $io->warning($e->getMessage());
                sleep(3);
                continue;
            }

            foreach (is_array($updates) ? $updates : [] as $raw) {
                if (!is_array($raw) || !is_int($raw['update_id'] ?? null)) {
                    continue;
                }
                $offset = max($offset, $raw['update_id'] + 1);

                $update = Update::fromArray($raw);
                if ($update === null) {
                    continue;
                }

                try {
                    $this->handler->handle($update);
                } catch (\Throwable $e) {
                    $this->logger->error('Update handling failed', ['update_id' => $update->updateId, 'exception' => $e]);
                    $io->warning(sprintf('update %d: %s', $update->updateId, $e->getMessage()));
                } finally {
                    $this->store->reset();
                }
            }

            if ($input->getOption('once')) {
                break;
            }
        }

        return Command::SUCCESS;
    }
}
