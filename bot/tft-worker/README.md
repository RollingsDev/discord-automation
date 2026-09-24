# 🤖 Oráculo Riot — LoL + TFT no Discord

Bot HTTP executado em Cloudflare Workers.

## Arquitetura

Discord Slash Command → Cloudflare Worker → Riot API / snapshots → resposta no Discord.

O Worker usa duas chaves separadas:

- `RIOT_API_KEY` → Account-V1, League-V4, Match-V5 e Champion Mastery;
- `RIOT_TFT_API_KEY` → TFT League-V1 e TFT Match-V1.

A resolução de Riot ID para PUUID usa `RIOT_API_KEY`. A chave TFT fica restrita aos endpoints de TFT.

## Comandos LoL

- `/lol player riot_id:<Nome#TAG>`
  - Solo/Duo;
  - Flex;
  - últimas 5 ranqueadas;
  - W/L e win rate;
  - KDA médio;
  - campeões recentes;
  - Top 5 maestrias.

- `/lol partida riot_id:<Nome#TAG>`
  - vitória/derrota;
  - campeão e role;
  - K/D/A;
  - CS e CS/min;
  - dano em campeões;
  - visão;
  - duração;
  - Match ID.

- `/lol maestria riot_id:<Nome#TAG>`
  - Top 10 campeões por Champion Mastery.

## Comandos TFT

Consultas ao vivo pela Riot API:

- `/tft player riot_id:<Nome#TAG>`
  - rank;
  - últimas 8 colocações;
  - Top 4 rate;
  - 1º lugar rate;
  - posição média.

- `/tft partida riot_id:<Nome#TAG>`
  - colocação;
  - nível;
  - eliminações;
  - dano a jogadores;
  - traits;
  - unidades;
  - Match ID.

Snapshot de meta existente:

- `/tft meta`
- `/tft comp nome:<comp>`
- `/tft champ nome:<campeão>`
- `/tft item nome:<item>`
- `/tft players`

## Segurança

O endpoint valida as assinaturas Ed25519 do Discord.

Secrets do Cloudflare Worker:

```text
DISCORD_PUBLIC_KEY
RIOT_API_KEY
RIOT_TFT_API_KEY
```

Variáveis opcionais:

```text
DISCORD_GUILD_ID
DISCORD_LOL_CHANNEL_ID
DISCORD_TFT_CHANNEL_ID
RIOT_REGION=americas
RIOT_PLATFORM=br1
```

Nunca commite Bot Token, Client Secret ou Riot API Keys.

## Configurar secrets no Cloudflare

Dentro de `bot/tft-worker`:

```bash
npx wrangler secret put DISCORD_PUBLIC_KEY
npx wrangler secret put RIOT_API_KEY
npx wrangler secret put RIOT_TFT_API_KEY
```

A `RIOT_TFT_API_KEY` pode ser adicionada depois. Enquanto ela estiver ausente, `/lol` continua funcionando e os comandos ao vivo de TFT informam que a chave ainda não foi configurada.

## Registro dos comandos

`scripts/register-commands.mjs` registra `/lol` e `/tft` como guild commands e usa:

- `DISCORD_APP_ID`
- `DISCORD_GUILD_ID`
- `DISCORD_BOT_TOKEN`

## Deploy

```bash
npm install
npx wrangler login
npx wrangler deploy
```

Depois configure a URL do Worker como **Interactions Endpoint URL** no Discord Developer Portal.

## Health check

`GET /health` retorna, sem revelar secrets:

- `ok`;
- comandos disponíveis;
- se a chave LoL está configurada;
- se a chave TFT está configurada.
