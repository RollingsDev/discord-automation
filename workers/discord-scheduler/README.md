# Discord Scheduler — Cloudflare Workers

Substituto dos agendamentos do GitHub Actions para:

- clima horário de São Paulo;
- previsão diária;
- liturgia diária.

## Por que existe

GitHub Actions continua útil para builds e automações de repositório, mas schedules podem iniciar com atraso. Este Worker usa Cloudflare Cron Triggers para as tarefas que precisam rodar de forma recorrente.

## Estratégia

Existe apenas um Cron Trigger:

`17 * * * *`

O cron é UTC, como definido pela Cloudflare.

A cada execução:

1. publica o clima horário;
2. entre 06:00 e 12:59 em `America/Sao_Paulo`, verifica se a previsão diária já foi publicada;
3. no mesmo período, verifica se a liturgia diária já foi publicada;
4. se uma tarefa diária falhou antes, a próxima execução horária tenta novamente.

A deduplicação usa Workers KV.

## Bindings obrigatórios

KV binding:

`SCHEDULER_STATE`

Secrets:

- `DISCORD_CLIMA_WEBHOOK`
- `DISCORD_LITURGIA_WEBHOOK`

Nunca coloque URLs de webhook no repositório.

## Deploy

Root directory no Cloudflare:

`workers/discord-scheduler`

Deploy command:

`npx wrangler deploy`

Depois do primeiro deploy, crie um namespace KV e conecte-o ao Worker usando o nome de binding `SCHEDULER_STATE`.

## Health check

`GET /health`

deve retornar `ok: true`.

## Migração segura

Não desative os schedules antigos do GitHub antes de:

1. deploy do Worker;
2. secrets configurados;
3. KV conectado;
4. um ciclo real validado.

Depois disso, os cron schedules dos repositórios antigos podem ser desativados para evitar mensagens duplicadas.
