<?php

declare(strict_types=1);

namespace DiscordAutomation\Support;

use JsonException;
use RuntimeException;

final class DiscordWebhook
{
    public function __construct(
        private readonly string $url,
    ) {
        if (trim($this->url) === '') {
            throw new RuntimeException('URL do webhook do Discord não configurada.');
        }
    }

    public static function fromEnv(string $variable): self
    {
        return new self(trim((string) getenv($variable)));
    }

    public function send(array $payload): void
    {
        $payload['allowed_mentions'] ??= ['parse' => []];

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Não foi possível serializar a mensagem do Discord.', 0, $e);
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $separator = str_contains($this->url, '?') ? '&' : '?';
            $ch = curl_init($this->url . $separator . 'wait=true');

            if ($ch === false) {
                throw new RuntimeException('Não foi possível iniciar o cURL para o Discord.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
            ]);

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            if ($response !== false && $status >= 200 && $status < 300) {
                return;
            }

            if ($status === 429) {
                $rate = json_decode((string) $response, true);
                $retryAfter = is_array($rate)
                    ? (float) ($rate['retry_after'] ?? 1.5)
                    : 1.5;

                usleep((int) (($retryAfter + 0.25) * 1_000_000));
                continue;
            }

            if ($attempt < 5 && ($status === 0 || $status >= 500)) {
                sleep($attempt);
                continue;
            }

            throw new RuntimeException(
                sprintf('Erro ao enviar mensagem ao Discord. HTTP %d. %s', $status, $error)
            );
        }

        throw new RuntimeException('Número máximo de tentativas ao Discord excedido.');
    }

    public function sendEmbed(
        string $title,
        string $description,
        array $fields = [],
        int $color = 0x5865F2,
        ?string $footer = null,
    ): void {
        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'fields' => $fields,
            'timestamp' => date(DATE_ATOM),
        ];

        if ($footer !== null && $footer !== '') {
            $embed['footer'] = ['text' => $footer];
        }

        $this->send(['embeds' => [$embed]]);
    }
}
