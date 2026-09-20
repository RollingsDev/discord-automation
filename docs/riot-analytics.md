# 🏆 Riot Analytics — LoL + TFT + ARAM

A automação acompanha os Riot IDs cadastrados em:

`config/riot-players.php`

## Jogadores

- crow#GT1
- Não grita#grll
- flafu#ILY
- Lappush#br1

## Secrets

- `RIOT_API_KEY`
- `WEBHOOK_RIOT`

A chave Riot nunca deve ser commitada.

## LoL ranqueado

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
- desempenho por campeão;
- função mais frequente;
- tamanho do pool de campeões no recorte recente.

## ARAM

A automação coleta separadamente a fila ARAM e calcula:

- partidas;
- W/L;
- win rate;
- KDA;
- dano por minuto;
- kill participation;
- campeões mais usados.

O histórico de ARAM fica separado do histórico ranqueado para não distorcer métricas de Summoner's Rift.

## Champion Mastery

O sync consulta Champion Mastery para cada jogador e guarda um resumo dos campeões com mais pontos:

- campeão;
- nível de maestria;
- pontos;
- última vez jogado.

O relatório diário mostra o Top 5 de cada jogador.

Também existe uma listagem coletiva:

`.github/workflows/riot-mastery.yml`

Ela publica aos domingos, 20:27 em São Paulo:

- ranking do grupo pela soma dos pontos oficiais de maestria;
- Top 5 campeões de cada jogador.

Esse ranking mede apenas pontos oficiais de Champion Mastery; não é um ranking de habilidade.

## Overview da gameplay

O Daily inclui um overview factual do recorte recente:

- role mais frequente;
- quantas partidas foram jogadas nessa role;
- quantidade de campeões diferentes usados;
- campeão mais usado;
- KP;
- KDA;
- CS/min;
- dano/min;
- resumo de ARAM.

O overview descreve o histórico observado. Ele não tenta diagnosticar personalidade, talento ou MMR oculto.

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

O projeto **não calcula MMR ou ELO oculto**.

A automação mostra apenas tendência baseada no rank e LP oficiais observados ao longo do tempo:

- ↑ progressão;
- → estabilidade;
- ↓ queda.

## Frequência

### Sync

`.github/workflows/riot-analytics-sync.yml`

A cada hora, no minuto 41.

Coleta incrementalmente LoL ranqueado, ARAM, TFT, ranks e Champion Mastery.

### Daily

`.github/workflows/riot-daily.yml`

08:17 em São Paulo.

Envia um relatório por jogador com LoL, ARAM, overview, maestrias e TFT.

### Weekly

`.github/workflows/riot-weekly.yml`

Domingo às 20:17 em São Paulo.

Inclui LoL, ARAM, TFT e tendência dos ranks oficiais.

### Mastery

`.github/workflows/riot-mastery.yml`

Domingo às 20:27 em São Paulo.

## State

O histórico fica em:

`.state/riot-analytics.json`

O state mantém ranks, snapshots e estatísticas compactadas. A API key nunca é persistida, e o PUUID não é mantido no state público.

## Observação sobre ARAM

A fila oficial usada é a queue ID 450, correspondente ao ARAM 5v5 em Howling Abyss.

## Observação sobre TFT

O projeto permanece estritamente pós-jogo: não mostra scouting em tempo real nem recomendações dinâmicas durante a partida.
