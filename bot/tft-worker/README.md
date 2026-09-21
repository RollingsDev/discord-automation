# 🤖 Oráculo TFT — Discord Slash Commands

Bot HTTP para o canal TFT, executado em Cloudflare Workers.

## Arquitetura

Discord Slash Command → Cloudflare Worker → snapshots públicos do GitHub → resposta no Discord.

O Worker não consulta tactics.tools nem a Riot a cada comando. Ele lê os snapshots já mantidos pelo projeto:

- `.state/tft-meta.json`
- `.state/riot-analytics.json`

## Comandos

- `/tft meta`
- `/tft comp nome:<comp>`
- `/tft champ nome:<campeão>`
- `/tft item nome:<item>`
- `/tft player nome:<Riot ID>`
- `/tft players`

## Segurança

O endpoint valida assinaturas Ed25519 enviadas pelo Discord. O Worker precisa do secret `DISCORD_PUBLIC_KEY`.

Opcionalmente, configure `DISCORD_GUILD_ID` para aceitar comandos somente do servidor esperado.

Nunca commite Bot Token, Client Secret ou outras credenciais.

## Registro dos comandos

`scripts/register-commands.mjs` registra `/tft` como guild command e usa:

- `DISCORD_APP_ID`
- `DISCORD_GUILD_ID`
- `DISCORD_BOT_TOKEN`

## Health check

Depois do deploy, `GET /health` deve retornar JSON com `ok: true`.

## Deploy local

```bash
npm install
npx wrangler login
npx wrangler secret put DISCORD_PUBLIC_KEY
npx wrangler deploy
```

A URL `https://<worker>.<subdomain>.workers.dev` será usada como **Interactions Endpoint URL** no Discord Developer Portal.