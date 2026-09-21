# 🔒 Auditor Receita - modo automático SERPRO

Agente privado para processar lotes de CNPJ sem navegador e sem CAPTCHA.

O fluxo principal usa a API contratada do SERPRO/Receita:

1. lê a planilha XLSX;
2. encontra sheets com `Entidade` e `CNPJ`;
3. valida e deduplica os CNPJs;
4. obtém e reutiliza Bearer Token;
5. faz **uma consulta QSA por CNPJ**;
6. usa o mesmo retorno para gerar:
   - `... - ISC.pdf`;
   - `... - QSA.pdf`;
7. salva o JSON bruto da consulta;
8. atualiza `_controle.csv`;
9. gera o ZIP completo;
10. envia o ZIP pelo canal privado do Discord.

## Importante sobre os PDFs

Os PDFs são gerados automaticamente pelo agente a partir dos dados retornados pela API oficial contratada.

Eles usam o mesmo conteúdo cadastral necessário para auditoria, mas **não são o comprovante impresso diretamente pelo botão Imprimir do portal da Receita**. O rodapé deixa essa distinção explícita.

## Requisitos

- Node.js 22+
- contratação ou demonstração da API Consulta CNPJ do SERPRO;
- Consumer Key;
- Consumer Secret.

Não precisa de:

- Chrome;
- Chromium;
- Playwright para o fluxo principal;
- interface gráfica;
- CAPTCHA.

## Ubuntu / WSL

```bash
git pull
cd discord-automation/agents/receita-auditor
chmod +x install-ubuntu.sh
./install-ubuntu.sh
```

O instalador apenas executa `npm install`, cria as pastas locais e preserva um `.env` existente.

## Configuração

Abra:

```bash
nano .env
```

Campos mínimos para a API:

```dotenv
SERPRO_CONSUMER_KEY=
SERPRO_CONSUMER_SECRET=
SERPRO_CNPJ_MODE=production
SERPRO_TOKEN_URL=https://gateway.apiserpro.serpro.gov.br/token
SERPRO_CNPJ_QSA_URL=https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2/qsa
```

Para não precisar anexar a mesma planilha em todo lote:

```dotenv
RECEITA_WORKBOOK_PATH=/home/usuario/Auditoria/estrutura prototipo.xlsx
```

A URL do QSA pode ser sobrescrita no `.env` se a documentação do contrato apresentar um endpoint diferente.

## Testar a API

Depois de preencher as credenciais:

```bash
npm run serpro:check -- --cnpj "00.000.000/0001-00"
```

O teste:

- obtém o Bearer Token;
- consulta QSA;
- mostra HTTP, CNPJ, razão social, situação e quantidade de sócios.

## Testar o lote

Com `RECEITA_WORKBOOK_PATH` configurado:

```bash
npm start -- --limit 1
```

Depois:

```bash
npm start
```

Saída:

```text
data/output/
├── pdf/
│   ├── XX.XXX.XXX.XXXX-XX - EMPRESA - ISC.pdf
│   └── XX.XXX.XXX.XXXX-XX - EMPRESA - QSA.pdf
├── raw/
│   └── XXXXXXXXXXXXXX.json
├── _controle.csv
└── Receita_CNPJ_YYYYMMDD.zip
```

## Discord privado

Comandos:

- `/receita lote`
- `/receita lote arquivo:<xlsx>`
- `/receita status`

Quando `RECEITA_WORKBOOK_PATH` está configurado, o auditor usa apenas:

```text
/receita lote
```

Nenhum arquivo precisa ser enviado.

O bot valida simultaneamente:

- `DISCORD_GUILD_ID`;
- `DISCORD_AUDITOR_CHANNEL_ID`;
- `DISCORD_AUDITOR_USER_ID`.

## Credenciais do Discord

No `.env`:

```dotenv
DISCORD_BOT_TOKEN=
DISCORD_APP_ID=
DISCORD_GUILD_ID=
DISCORD_AUDITOR_CHANNEL_ID=
DISCORD_AUDITOR_USER_ID=
```

Registrar o comando:

```bash
npm run register
```

Iniciar:

```bash
npm run bot
```

## Privacidade

Não faça commit de:

- Consumer Key;
- Consumer Secret;
- Bot Token;
- planilha do auditor;
- PDFs;
- respostas JSON;
- ZIPs.

O `.env` e `data/` permanecem fora do Git.

## Fluxo legado por navegador

O protótipo Playwright foi mantido no código apenas como referência/fallback. O processamento principal e o bot não dependem mais dele.
