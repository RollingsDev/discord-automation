# Discord Automation

Central de automações gratuitas do Discord, executadas com **PHP + GitHub Actions**.

Os projetos de liturgia e clima geral continuam separados. Este repositório concentra as demais integrações do servidor.

## Automações ativas

| Automação | Secret | Rotina |
| --- | --- | --- |
| 🎁 Jogos grátis | `WEBHOOK_FREE_GAMES` | 09:13 e 18:13 |
| 💻 Dev Watch | `WEBHOOK_DEV` | 09:27 e 18:27 |
| ⚔️ LoL / TFT Patch Watch | `WEBHOOK_RIOT` | 10:07 e 19:07 |
| 🎮 Fortnite | `WEBHOOK_FORTNITE` | 10:23 e 18:23 |
| 🛡️ Security Watch | `WEBHOOK_SECURITY` | 08:53 |
| 🏍️ Entregas SP — Zona Norte | `WEBHOOK_DELIVERY` | 10:35, 16:35 e 19:35 |

Os horários acima são de São Paulo. GitHub Actions pode iniciar alguns minutos depois do horário programado.

## Estrutura

```text
discord-automation/
├── .github/workflows/
│   ├── free-games.yml
│   ├── dev-watch.yml
│   ├── riot-patches.yml
│   ├── fortnite.yml
│   ├── security-watch.yml
│   └── delivery-advisor.yml
├── .state/
├── config/
├── docs/
├── scripts/
├── src/Support/
├── bootstrap.php
└── README.md
```

## Como funciona

Cada integração segue o mesmo padrão:

```text
fonte pública
     ↓
script PHP
     ↓
StateStore
     ↓
é novidade?
 ├─ não → encerra
 └─ sim → webhook do Discord
           ↓
       atualiza .state
```

A primeira execução das automações de notícias/releases inicializa o estado sem publicar um grande histórico antigo.

## 🏍️ Entregas SP — Zona Norte

O boletim de entregas é focado em quatro pontos representativos:

- Santana
- Tucuruvi
- Vila Maria
- Casa Verde

Ele usa condições atuais e as próximas 4 horas para resumir chuva, rajadas, calor, umidade e tempestades.

O índice operacional de 0–100 é **somente meteorológico**. Ele não prevê demanda, ganhos, tarifa dinâmica ou quantidade de pedidos.

## Security Watch

Monitora advisories HIGH e CRITICAL para pacotes relevantes do stack Laravel/PHP, JavaScript e GitHub Actions.

## Dev Watch

Monitora novas tags de Laravel, Livewire, FrankenPHP, Octane, Horizon, PHP, Docker Compose, Redis, MySQL e Pest.

## LoL / TFT

O Patch Watch usa páginas oficiais da Riot e funciona apenas com `WEBHOOK_RIOT`.

O futuro **Rank Tracker** continua desativado porque exige uma chave separada:

```text
RIOT_API_KEY
```

## Testes manuais

Todos os workflows possuem `workflow_dispatch` e podem ser executados pela aba **Actions**. Os workflows de notícias/releases possuem uma opção para forçar uma publicação de teste.

## Documentação

- `docs/free-games.md`
- `docs/dev-watch.md`
- `docs/riot-patches.md`
- `docs/fortnite.md`
- `docs/security-watch.md`
- `docs/delivery-advisor.md`

## Segurança

- Webhooks ficam apenas em Repository Secrets.
- Nenhuma URL de webhook deve ser commitada.
- `allowed_mentions` é desativado no cliente compartilhado para evitar menções acidentais.
- Estados persistidos em `.state` contêm somente IDs e metadados operacionais.

## Requisitos

- PHP 8.4+
- GitHub Actions
- extensões PHP definidas em cada workflow
