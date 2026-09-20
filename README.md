# Discord Automation

Central de automações gratuitas do Discord, executadas com **PHP + GitHub Actions**.

## Automações ativas

| Automação | Secret | Rotina |
| --- | --- | --- |
| 🎁 Jogos grátis | `WEBHOOK_FREE_GAMES` | 09:13 e 18:13 |
| 💻 Dev Watch | `WEBHOOK_DEV` | 09:27 e 18:27 |
| ⚔️ LoL / TFT Patch Watch | `WEBHOOK_RIOT` | 10:07 e 19:07 |
| 🏆 Riot Analytics — LoL + ARAM + TFT | `WEBHOOK_RIOT` + `RIOT_API_KEY` | sync horário + daily + weekly + mastery |
| 🎮 Fortnite | `WEBHOOK_FORTNITE` | 10:23 e 18:23 |
| 🛡️ Security Watch | `WEBHOOK_SECURITY` | 08:53 |
| 🏍️ Entregas SP — Zona Norte | `WEBHOOK_DELIVERY` | 10:35, 16:35 e 19:35 |

Os horários são de São Paulo. GitHub Actions pode iniciar alguns minutos depois do horário programado.

## Riot Analytics — LoL + ARAM + TFT

Jogadores monitorados:

- crow#GT1
- Não grita#grll
- flafu#ILY
- Lappush#br1

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
- desempenho por campeão;
- role mais frequente e pool recente.

### ARAM

- partidas da fila ARAM;
- W/L e win rate;
- KDA;
- dano/min;
- kill participation;
- campeões mais usados.

O histórico ARAM é separado do ranqueado.

### Champion Mastery

- nível e pontos de maestria;
- Top 5 por jogador;
- soma de pontos de maestria;
- ranking semanal do grupo por pontos oficiais de maestria.

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

- **Sync horário (:41)**: coleta LoL, ARAM, TFT, ranks e maestrias.
- **Daily 08:17**: LoL + ARAM + overview + Top maestrias + TFT.
- **Weekly domingo 20:17**: evolução semanal de LoL, ARAM e TFT.
- **Mastery domingo 20:27**: ranking de maestrias do grupo.

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
│   ├── riot-mastery.yml
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

## Segurança

- Webhooks e `RIOT_API_KEY` ficam apenas em Repository Secrets.
- Nenhuma chave deve ser commitada.
- O state público não persiste PUUID.
- Menções automáticas do Discord são bloqueadas.

## Documentação

- `docs/free-games.md`
- `docs/dev-watch.md`
- `docs/riot-patches.md`
- `docs/riot-analytics.md`
- `docs/fortnite.md`
- `docs/security-watch.md`
- `docs/delivery-advisor.md`
