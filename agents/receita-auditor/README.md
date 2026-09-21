# 🔒 Auditor Receita — Agente local (Ubuntu/Windows)

Agente local para gerar os dois documentos usados pelo auditor:

- ISC — Comprovante de Inscrição e de Situação Cadastral;
- QSA — Consulta Quadro de Sócios e Administradores.

O agente usa a página oficial da Receita com Chromium visível. Ele **não resolve nem contorna CAPTCHA**. Quando aparecer "Sou humano", o auditor faz somente essa validação manual e o processo continua.

## Fluxo

1. Lê a planilha XLSX.
2. Procura sheets com as colunas `Entidade` e `CNPJ`.
3. Valida o CNPJ.
4. Deduplica empresas repetidas entre sheets.
5. Consulta a Receita.
6. Salva ISC em PDF.
7. Abre Consultar QSA.
8. Salva QSA em PDF.
9. Atualiza `_controle.csv`.
10. Gera ZIP completo.

Padrão dos arquivos:

`12.345.678.0001-95 - EMPRESA TESTE LTDA - ISC.pdf`

`12.345.678.0001-95 - EMPRESA TESTE LTDA - QSA.pdf`

## Instalação no Windows

No PowerShell:

```powershell
git clone https://github.com/RollingsDev/discord-automation.git
cd discord-automation\agents\receita-auditor
npm install
npm run install-browser
copy .env.example .env
```

Teste primeiro com uma empresa:

```powershell
npm start -- --input "C:\Auditoria\estrutura.xlsx" --output "C:\Auditoria\saida" --limit 1
```

Se os dois PDFs ficarem equivalentes aos exemplos aprovados, remova `--limit 1` para o lote completo.

## Discord privado

Comandos preparados:

- `/receita lote arquivo:<xlsx>`
- `/receita status`

O bot só aceita execução quando servidor, canal e usuário coincidirem com:

- `DISCORD_GUILD_ID`
- `DISCORD_AUDITOR_CHANNEL_ID`
- `DISCORD_AUDITOR_USER_ID`

Preencha esses valores apenas no `.env` local.

Registrar comandos:

```bash
npm run register
```

Executar bot:

```bash
npm run bot
```

## Dados privados

Não faça commit da planilha nem dos PDFs. As pastas locais `data/` e o `.env` são ignorados pelo Git.

## CAPTCHA

O agente apenas detecta o desafio, avisa o auditor e aguarda a validação humana. Ele não clica nem terceiriza o hCaptcha.

## Fonte oficial

`https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/`
