# Discord Automation

Central de automações gratuitas do Discord, executadas com **PHP + GitHub Actions**.

## Automações ativas

| Automação | Secret | Rotina |
| --- | --- | --- |
| 🎁 Jogos grátis | `WEBHOOK_FREE_GAMES` | 09:13 e 18:13 |
| 💻 Dev Watch | `WEBHOOK_DEV` | 09:27 e 18:27 |
| ⚔️ LoL / TFT Patch Watch | `WEBHOOK_RIOT` | 10:07 e 19:07 |
| 🏆 Riot Analytics — LoL + TFT | `WEBHOOK_RIOT` + `RIOT_API_KEY` | sync horário + daily + weekly |
| 🎮 Fortnite | `WEBHOOK_FORTNITE` | 10:23 e 18:23 |
| 🛡️ Security Watch | `WEBHOOK_SECURITY` | 08:53 |
| 🏍️ Entregas SP — Zona Norte | `WEBHOOK_DELIVERY` | 10:35, 16:35 e 19:35 |

Os horários são de São Paulo. GitHub Actions pode iniciar alguns minutos depois do horário programado.

## Riot Analytics — LoL + TFT

Jogadores monitorados:

- crow#GT1
- Não grita#grll
- flafu#ILY
- Lappush#br1
- KingXds#br1

O sync roda a cada hora no minuto 41 e mantém histórico incremental em `.state/riot-analytics.json`.

### LoL

- Solo/Duo e Flex oficiais;
- rank e LP;
- partidas ranqueadas;
- W/L e win rate;
- KDA;
- CS/min;
- dano/min;
- ouro/min;
- visão/min;
- kill participation;
- desempenho por campeão.

### TFT

- rank e LP;
- partidas Ranked TFT;
- Top 4 rate;
- 1º lugar rate;
- posição média;
- comps/traits mais utilizadas;
- desempenho histórico por comp;
- desempenho histórico por item.

Os relatórios são **pós-jogo**. O projeto não fornece recomendações dinâmicas durante a partida e não calcula MMR/ELO oculto.

### Relatórios

- **Sync horário**: detecta rank e partidas novas.
- **Daily 08:17**: relatório detalhado por jogador.
- **Weekly domingo 20:17**: evolução da semana usando rank e LP oficiais observados.

Documentação completa: `docs/riot-analytics.md`.

## Estrutura

```text
discord-automation/
├── .github/workflows/
│   ├── free-games.yml
│   ├── dev-watch.yml
│   ├── riot-patches.yml
│   ├── riot-analytics-sync.yml
│   ├── riot-daily.yml
│   ├── riot-weekly.yml
│   ├── fortnite.yml
│   ├── security-watch.yml
│   └── delivery-advisor.yml
├── .state/
├── config/
├── docs/
├── scripts/
├── src/
│   ├── Support/
│   └── Riot/
├── bootstrap.php
└── README.md
```

## Como funciona

```text
fonte/API
   ↓
script PHP
   ↓
StateStore
   ↓
novidade?
 ├─ não → encerra
 └─ sim → Discord
           ↓
       atualiza .state
```

## 🏍️ Entregas SP — Zona Norte

Foco em Santana, Tucuruvi, Vila Maria e Casa Verde.

O índice operacional é somente meteorológico e considera chuva, vento, tempestades, calor e umidade.

## Security Watch

Monitora advisories HIGH e CRITICAL relevantes ao stack Laravel/PHP, JavaScript e GitHub Actions.

## Dev Watch

Monitora novas versões de Laravel, Livewire, FrankenPHP, Octane, Horizon, PHP, Docker Compose, Redis, MySQL e Pest.

## Segurança

- Webhooks e `RIOT_API_KEY` ficam apenas em Repository Secrets.
- Nenhuma chave deve ser commitada.
- Estados em `.state` não contêm secrets.
- Menções automáticas do Discord são bloqueadas.

## Documentação

- `docs/free-games.md`
- `docs/dev-watch.md`
- `docs/riot-patches.md`
- `docs/riot-analytics.md`
- `docs/fortnite.md`
- `docs/security-watch.md`
- `docs/delivery-advisor.md`
