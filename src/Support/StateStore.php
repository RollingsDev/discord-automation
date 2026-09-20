<?php

declare(strict_types=1);

namespace DiscordAutomation\Support;

use JsonException;
use RuntimeException;

final class StateStore
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function read(string $key, array $default = []): array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return $default;
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Estado inválido em {$path}.", 0, $e);
        }

        return is_array($data) ? $data : $default;
    }

    public function write(string $key, array $value): void
    {
        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Não foi possível criar {$directory}.");
        }

        try {
            $json = json_encode(
                $value,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Não foi possível serializar o estado.', 0, $e);
        }

        if (file_put_contents($path, $json . PHP_EOL) === false) {
            throw new RuntimeException("Não foi possível gravar {$path}.");
        }
    }

    public function contains(string $key, string $id): bool
    {
        $state = $this->read($key, ['items' => []]);
        $items = $state['items'] ?? [];

        return is_array($items) && in_array($id, $items, true);
    }

    public function remember(string $key, string $id, int $limit = 100): void
    {
        $state = $this->read($key, ['items' => []]);
        $items = is_array($state['items'] ?? null) ? $state['items'] : [];

        array_unshift($items, $id);
        $items = array_values(array_unique($items));
        $items = array_slice($items, 0, max(1, $limit));

        $this->write($key, [
            'updated_at' => date(DATE_ATOM),
            'items' => $items,
        ]);
    }

    private function path(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $key);

        if ($safe === null || $safe === '') {
            throw new RuntimeException('Chave de estado inválida.');
        }

        return rtrim($this->basePath, '/') . '/' . $safe . '.json';
    }
}
