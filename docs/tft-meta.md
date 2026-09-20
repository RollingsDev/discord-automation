# ♟️ TFT Meta

Canal isolado para consulta rápida de Teamfight Tactics.

## Objetivo

O canal mostra um snapshot estático de:

- composições do meta;
- win rate / taxa de 1º lugar das comps;
- Top 4 rate;
- colocação média;
- play rate;
- campeões de cada composição;
- itens recomendados por campeão;
- estatísticas globais dos itens;
- principais usuários de cada item;
- jogadores TFT monitorados no servidor.

## Fontes

### Meta

`tactics.tools`

A automação lê as páginas públicas de composições e itens em baixa frequência e mantém o último snapshot válido em cache.

O snapshot registra também o patch e o filtro de rank apresentados pela fonte.

### Jogadores

Riot TFT API + o histórico já coletado pelo Riot Analytics.

Para o League Entry mostramos o W/L retornado pela Riot como uma taxa separada. Para partidas que temos no Match-V1, mostramos métricas inequívocas de colocação:

- Top 4 rate;
- taxa de 1º lugar;
- posição média;
- tamanho da amostra.

## Itens

A tabela global inclui:

- play rate;
- colocação média;
- Top 4;
- win rate;
- campeões que mais usam o item.

Nas composições, os itens são ligados diretamente aos campeões exibidos pela fonte. Isso permite leitura rápida do tipo:

`Aphelios → Gume do Infinito / Fúria do Cráquem / Lâmina Mortal`

## Política Riot

O canal é informativo e estático. Ele não usa scouting de adversários, não reage ao board durante uma partida e não fornece recomendações dinâmicas em tempo real.

Não publicamos estatísticas proibidas de Legends/Legend-based Augments.

## Webhook

Secret reservado:

`WEBHOOK_TFT_META`

Enquanto ele não existir, o script pode ser validado e atualizar o snapshot, mas não publica no Discord.

## Workflow

`.github/workflows/tft-meta.yml`

Inicialmente fica apenas manual. Depois de validar o webhook do canal TFT, podemos agendar uma ou duas atualizações por dia.

## State

Último snapshot válido:

`.state/tft-meta.json`

Se a fonte externa falhar, a publicação pode usar o último snapshot salvo em vez de enviar dados incompletos.
