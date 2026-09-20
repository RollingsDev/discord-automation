# 🏆 Riot Analytics — LoL + TFT

A automação acompanha os Riot IDs cadastrados em:

`config/riot-players.php`

## Jogadores

- crow#GT1
- Não grita#grll
- flafu#ILY
- Lappush#br1
- KingXds#br1

## Secrets

- `RIOT_API_KEY`
- `WEBHOOK_RIOT`

A chave Riot nunca deve ser commitada.

## LoL

Acompanha:

- Solo/Duo e Flex;
- rank e LP oficiais;
- partidas ranqueadas recentes;
- W/L e win rate;
- KDA;
- CS/min;
- dano/min;
- ouro/min;
- visão/min;
- kill participation;
- desempenho por campeão.

## TFT

Acompanha:

- rank e LP oficiais;
- partidas Ranked TFT;
- Top 4 rate;
- 1º lugar rate;
- posição média;
- comps/traits mais usados;
- Top 4 e 1º lugar observados por comp;
- itens mais recorrentes;
- Top 4, 1º lugar e posição média observados quando cada item apareceu.

Essas métricas são pós-jogo e históricas. Não são recomendações dinâmicas durante uma partida.

## Projeções / tendência

O projeto **não calcula MMR ou ELO oculto**. A política da Riot proíbe sistemas alternativos de skill ranking.

A automação mostra apenas tendência baseada no rank e LP oficiais observados ao longo do tempo:

- ↑ progressão;
- → estabilidade;
- ↓ queda.

Com mais histórico acumulado, podemos adicionar cenários de ritmo de LP baseados em snapshots oficiais, sem estimar MMR.

## Frequência

### Sync

`.github/workflows/riot-analytics-sync.yml`

A cada hora, no minuto 41.

O sync busca apenas partidas recentes ainda não armazenadas e mantém até 100 partidas compactadas de LoL e 100 de TFT por jogador.

### Daily

`.github/workflows/riot-daily.yml`

08:17 em São Paulo.

Envia um relatório por jogador.

### Weekly

`.github/workflows/riot-weekly.yml`

Domingo às 20:17 em São Paulo.

## State

O histórico fica em:

`.state/riot-analytics.json`

Ele contém PUUIDs, ranks oficiais, snapshots de rank e estatísticas compactadas das partidas, mas não contém a API key.

## Observação sobre TFT

A Riot permite ferramentas de análise pós-jogo e histórico agregado. O projeto não mostra scouting de adversários em tempo real nem recomendações dinâmicas durante a partida.
