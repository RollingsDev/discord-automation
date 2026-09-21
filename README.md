# Discord Automation

Central de automações gratuitas do Discord, executadas com **PHP + GitHub Actions**.

## Automações ativas

| Automação | Secret | Rotina |
| --- | --- | --- |
| 🎁 Jogos grátis | `WEBHOOK_FREE_GAMES` | 09:13 e 18:13 |
| 💻 Dev Watch | `WEBHOOK_DEV` | 09:27 e 18:27 |
| ⚔️ LoL / TFT Patch Watch | `WEBHOOK_RIOT` | 10:07 e 19:07 |
| 🏆 Riot Analytics — LoL + ARAM + TFT | `WEBHOOK_RIOT_RANKING` + `RIOT_API_KEY` | sync horário + daily + weekly + mastery |
| ♟️ TFT Meta — Comps e Itens | `WEBHOOK_TFT_META` | 08:37 e 18:37 |
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
- Souza Nara#br1

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

### Camada 2

No LoL, o Match-V5 Timeline adiciona diferenças de gold/CS/XP aos 10/15/20 minutos, early K/D/A, role, lado do mapa, objetivos, builds e timing de itens. O TFT também cruza carry + item no histórico.

### TFT Meta isolado

A automação `tft-meta` prepara um canal separado com comps atuais, Win/Top4/posição média, play rate, itens por campeão, estatísticas globais de itens e a seção TFT dos jogadores monitorados. O webhook separado é `WEBHOOK_TFT_META`.

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

- **Sync horário (:41)**: coleta LoL, ARAM, TFT, ranks, maestrias e timelines.
- **Group Board 08:07**: painel coletivo com ranks oficiais e recorte recente.
- **Daily 08:17**: LoL + ARAM + overview + early game 10/15/20 + builds + objetivos + Top maestrias + TFT.
- **Weekly domingo 20:17**: evolução semanal de LoL, ARAM e TFT.
- **Mastery domingo 20:27**: ranking de maestrias do grupo.

Documentação completa: `docs/riot-analytics.md`.

## Auditor Receita

Agente privado para consulta em lote de CNPJ via API SERPRO/Receita + Discord.

- lê planilhas com `Entidade` e `CNPJ`;
- deduplica CNPJs repetidos entre sheets;
- faz uma consulta QSA por CNPJ e reaproveita o retorno cadastral;
- gera ISC e QSA em PDF;
- guarda o JSON bruto, controle CSV e ZIP;
- pode usar uma planilha fixa privada, permitindo apenas `/receita lote`;
- restringe o comando por servidor, canal e usuário.

O fluxo principal não depende de navegador nem CAPTCHA.

Código: `agents/receita-auditor/`.

Credenciais SERPRO, planilha, PDFs, JSONs e ZIPs não são versionados.

## Oráculo TFT

Bot de slash commands hospedado em Cloudflare Workers, usando os snapshots já mantidos pelo projeto.

Comandos:

- `/tft meta`
- `/tft comp nome:<comp>`
- `/tft champ nome:<campeão>`
- `/tft item nome:<item>`
- `/tft player nome:<Riot ID>`
- `/tft players`

Código: `bot/tft-worker/`.

O Worker valida assinaturas do Discord e não precisa manter uma conexão Gateway/WebSocket aberta.


## Estrutura

```text
discord-automation/
├── .github/workflows/
│   ├── free-games.yml
│   ├── dev-watch.yml
│   ├── riot-patches.yml
│   ├── riot-analytics-sync.yml
│   ├── riot-group.yml
│   ├── riot-daily.yml
│   ├── riot-weekly.yml
│   ├── riot-mastery.yml
│   ├── tft-meta.yml
│   ├── fortnite.yml
│   ├── security-watch.yml
│   └── delivery-advisor.yml
├── .state/
├── bot/tft-worker/
├── agents/receita-auditor/
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

- `WEBHOOK_RIOT` fica reservado para updates/patches; `WEBHOOK_RIOT_RANKING` recebe ranking e analytics. Ambos, junto com `RIOT_API_KEY`, ficam apenas em Repository Secrets.
- Nenhuma chave deve ser commitada.
- O state público não persiste PUUID.
- Menções automáticas do Discord são bloqueadas.

## Documentação

- `docs/free-games.md`
- `docs/dev-watch.md`
- `docs/riot-patches.md`
- `docs/riot-analytics.md`
- `docs/tft-meta.md`
- `docs/fortnite.md`
- `docs/security-watch.md`
- `docs/delivery-advisor.md`
