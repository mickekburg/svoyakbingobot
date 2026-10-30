<?php

declare(strict_types=1);

namespace App\Command;

use App\Telegram\TelegramApi;
use App\Telegram\TelegramApiException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:telegram:webhook', description: 'Register, inspect or remove the Telegram webhook')]
final class TelegramSetWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramApi $api,
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'Public HTTPS URL of POST /telegram/webhook, e.g. https://example.com/telegram/webhook')
            ->addOption('info', null, InputOption::VALUE_NONE, 'Show the current webhook state')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Remove the webhook (needed before using app:telegram:poll)')
            ->addOption('drop-pending', null, InputOption::VALUE_NONE, 'Discard updates Telegram has queued for the bot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($input->getOption('info')) {
                return $this->info($io);
            }

            if ($input->getOption('delete')) {
                $this->api->call('deleteWebhook', ['drop_pending_updates' => (bool) $input->getOption('drop-pending')]);
                $io->success('Webhook removed.');

                return Command::SUCCESS;
            }

            $url = (string) $input->getArgument('url');
            if (!str_starts_with($url, 'https://')) {
                $io->error('Give the public HTTPS URL of the webhook, e.g. https://example.com/telegram/webhook (Telegram accepts only HTTPS).');

                return Command::INVALID;
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,256}$/', $this->webhookSecret) !== 1 || str_starts_with($this->webhookSecret, 'replace-with')) {
                $io->error('TELEGRAM_WEBHOOK_SECRET must be set (in .env.local) to a random string of 1-256 characters A-Z a-z 0-9 _ -');

                return Command::INVALID;
            }

            $this->api->call('setWebhook', [
                'url' => $url,
                'secret_token' => $this->webhookSecret,
                'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => (bool) $input->getOption('drop-pending'),
            ]);
            $io->success('Webhook set to ' . $url);

            return $this->info($io);
        } catch (TelegramApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function info(SymfonyStyle $io): int
    {
        $info = $this->api->call('getWebhookInfo');
        if (!is_array($info)) {
            $io->error('Unexpected answer from Telegram.');

            return Command::FAILURE;
        }

        $rows = [];
        foreach (['url', 'pending_update_count', 'last_error_date', 'last_error_message'] as $key) {
            if (array_key_exists($key, $info)) {
                $value = $info[$key];
                if ($key === 'last_error_date' && is_int($value)) {
                    $value = gmdate('Y-m-d H:i:s', $value) . ' UTC';
                }
                $rows[] = [$key, is_scalar($value) ? (string) $value : json_encode($value)];
            }
        }
        $io->table(['field', 'value'], $rows === [] ? [['url', '(no webhook set)']] : $rows);

        return Command::SUCCESS;
    }
}
