# 🎁 Automação de Jogos Grátis

A automação consulta a API pública da **GamerPower** e publica no Discord somente jogos completos (`type=game`) compatíveis com os filtros de plataforma configurados.

## Fonte

Endpoint usado:

```text
https://www.gamerpower.com/api/giveaways?type=game&sort-by=date
```

A GamerPower não exige API key para este endpoint.

## Plataformas

Por padrão, o bot procura ofertas relacionadas a:

- PC
- Steam
- Epic Games Store
- GOG
- DRM-Free
- itch.io
- Battle.net
- Ubisoft Connect
- Origin

A lista fica em:

```text
config/free-games.php
```

## Configurar o webhook

No Discord:

1. Abra o canal que receberá as ofertas.
2. Vá em **Editar canal → Integrações → Webhooks**.
3. Crie um webhook e copie a URL.

No GitHub:

1. Abra o repositório `discord-automation`.
2. Vá em **Settings → Secrets and variables → Actions**.
3. Abra **Secrets**.
4. Clique em **New repository secret**.
5. Crie exatamente:

```text
WEBHOOK_FREE_GAMES
```

6. No valor, cole a URL completa do webhook do Discord.

Nunca salve a URL do webhook em arquivo público, variável pública ou commit.

## Agendamento

O workflow:

```text
.github/workflows/free-games.yml
```

executa duas vezes por dia:

- 09:13 — horário de São Paulo
- 18:13 — horário de São Paulo

O agendamento do GitHub Actions pode sofrer alguns minutos de atraso.

## Primeira execução

Para evitar inundar o canal com todas as promoções já existentes, a primeira execução normal apenas cria:

```text
.state/free-games.json
```

com os IDs das ofertas já conhecidas.

Depois disso, somente ofertas novas são publicadas.

## Testar imediatamente

Abra:

**Actions → Jogos Grátis → Run workflow**

Marque:

```text
Enviar o jogo grátis mais recente como teste = true
```

O bot publicará o item mais recente com o prefixo **TESTE**.

Esse teste também funciona na primeira execução.

## Controle de duplicidade

O `StateStore` guarda os IDs já publicados em:

```text
.state/free-games.json
```

Uma oferta já registrada não é enviada novamente em execuções agendadas.

O bot limita o envio a 6 novas ofertas por execução para evitar spam. Se houver mais, as restantes ficam para a próxima execução.

## Arquivos envolvidos

```text
config/free-games.php
scripts/free-games.php
.github/workflows/free-games.yml
.state/free-games.json       # criado automaticamente
```

## Segurança

- O webhook fica somente em GitHub Actions Secrets.
- O script desativa menções automáticas no Discord.
- O código não imprime o webhook no log.
- Se `WEBHOOK_FREE_GAMES` ainda não estiver configurado, a execução é ignorada sem falhar.
