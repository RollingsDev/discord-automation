# Discord Automation

Central de automações do Discord do grupo, executada principalmente via **GitHub Actions** e scripts PHP, sem servidor dedicado.

## Objetivos

Centralizar automações como:

1. 🎁 Jogos grátis
2. 💻 Dev Watch
3. ⚔️ LoL / TFT Patch Watch
4. 🎮 Fortnite Updates
5. 🔐 Security Watch
6. 🏆 Riot Rank Tracker
7. 🏍️ Delivery Advisor
8. 📊 Integrações de auditoria

Os projetos existentes de liturgia e clima continuam separados por enquanto.

## Estrutura

```text
discord-automation/
├── .github/
│   └── workflows/
├── .state/
├── config/
├── scripts/
├── src/
│   └── Support/
├── bootstrap.php
└── README.md
```

### `src/Support`

Código compartilhado entre todas as automações:

- `HttpClient.php`: requisições HTTP/JSON.
- `DiscordWebhook.php`: envio seguro para webhooks do Discord.
- `StateStore.php`: controle de itens já publicados para evitar duplicação.

### `scripts`

Cada automação terá um script executável próprio. Exemplos futuros:

```text
scripts/
├── free-games.php
├── dev-watch.php
├── riot-patches.php
├── fortnite.php
├── security-watch.php
├── riot-rank.php
└── delivery-advisor.php
```

### `.state`

Estado persistido pelas automações, como IDs de notícias/releases já publicados.

Nunca deve conter tokens, chaves ou webhooks.

## Secrets

Os webhooks serão armazenados somente em **GitHub Actions Secrets**.

Padrão planejado:

```text
WEBHOOK_FREE_GAMES
WEBHOOK_DEV
WEBHOOK_RIOT
WEBHOOK_FORTNITE
WEBHOOK_SECURITY
WEBHOOK_DELIVERY
RIOT_API_KEY
```

Não coloque URLs de webhook ou chaves de API diretamente no repositório.

## Ordem de implementação

A primeira fase será:

1. 🎁 Jogos grátis
2. 💻 Dev Watch
3. ⚔️ LoL / TFT Patch Watch

Depois:

4. 🎮 Fortnite
5. 🔐 Security Watch
6. 🏆 Riot Rank Tracker
7. 🏍️ Delivery Advisor
8. 📊 Auditoria

## Requisitos

- PHP 8.4+
- extensões `curl` e `mbstring`
- GitHub Actions

## Filosofia

Cada automação seguirá, sempre que possível:

```text
API/RSS
   ↓
script PHP
   ↓
compara com .state
   ↓
tem novidade?
   ├─ não → encerra
   └─ sim → envia ao Discord
             ↓
          atualiza .state
```
