# Scripts

Cada automação deve ter um único ponto de entrada executável nesta pasta.

Padrão:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DiscordAutomation\Support\DiscordWebhook;
use DiscordAutomation\Support\HttpClient;
use DiscordAutomation\Support\StateStore;

$discord = DiscordWebhook::fromEnv('WEBHOOK_EXEMPLO');
$state = new StateStore(__DIR__ . '/../.state');

// 1. buscar dados
// 2. comparar estado
// 3. publicar somente novidades
// 4. atualizar estado
```

Os workflows do GitHub Actions serão adicionados quando cada integração estiver pronta.
