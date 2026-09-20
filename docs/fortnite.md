# 🎮 Fortnite Updates

Monitora:

- notícias in-game de Battle Royale, Salve o Mundo e Criativo via Fortnite-API;
- ocorrências do Fortnite no RSS oficial de status da Epic Games.

## Secret

`WEBHOOK_FORTNITE`

## Horários

- 10:23
- 18:23

Horário de São Paulo.

A primeira execução cria `.state/fortnite.json` sem republicar conteúdo antigo. O workflow manual permite testar a publicação mais recente com `force_latest`.

A Fortnite-API é usada porque o site Fortnite.com pode bloquear requisições automatizadas vindas dos runners do GitHub. Os incidentes e indisponibilidades continuam vindo diretamente do Epic Games Status.
