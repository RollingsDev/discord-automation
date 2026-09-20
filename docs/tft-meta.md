# ♟️ TFT Meta

Canal isolado para consulta rápida de Teamfight Tactics.

## Objetivo

- composições do meta;
- win rate;
- Top 4 rate;
- colocação média;
- play rate;
- itens recomendados nos campeões das comps;
- estatísticas globais de itens;
- jogadores TFT monitorados no servidor.

## Fontes

Meta: `tactics.tools`.

A automação lê as páginas públicas de comps e itens com baixa frequência e mantém o último snapshot em cache.

Nomes de campeões e itens: CommunityDragon, usado como dicionário estático.

Jogadores: Riot TFT API + o state já usado pelo Riot Analytics.

## Win rate dos jogadores

No League Entry oficial de TFT, `wins` representa partidas terminadas em 1º lugar e `losses` representa colocações de 2º a 8º.

Por isso o relatório apresenta Top 1 / total de jogos como 1º lugar oficial / Win Rate oficial.

Quando temos partidas Match-V1 armazenadas, também mostramos Top 4 rate, 1º lugar rate, posição média e tamanho da amostra recente.

## Política Riot

O canal é informativo e estático. Não usa scouting de adversários, não reage ao board durante uma partida e não fornece recomendações dinâmicas em tempo real.

Também não publica estatísticas proibidas de Legends/Legend-based Augments.

## Webhook

Secret reservado: `WEBHOOK_TFT_META`.

Enquanto ele não existir, o script pode atualizar o snapshot, mas não publica no Discord.

## Workflow

`.github/workflows/tft-meta.yml`

Inicialmente fica apenas manual. Depois de validar o webhook do canal TFT, ele será agendado.