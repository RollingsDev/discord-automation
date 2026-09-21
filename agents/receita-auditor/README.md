# Auditor Receita - gerador local automático

Ferramenta local para processar uma planilha de empresas e gerar os arquivos de auditoria por CNPJ.

## O que ela faz

Ao executar o comando, o programa:

1. lê todas as sheets que tenham `Entidade` e `CNPJ`;
2. valida os CNPJs;
3. remove duplicidades entre sheets;
4. consulta automaticamente o QSA pela API SERPRO/Receita;
5. reaproveita a mesma resposta cadastral para gerar:
   - `... - ISC.pdf`;
   - `... - QSA.pdf`;
6. salva o JSON bruto retornado pela API;
7. cria `_controle.csv`;
8. cria um ZIP com tudo.

Não usa Discord, navegador, CAPTCHA ou interface gráfica.

## PDFs

Os PDFs são gerados pelo programa a partir dos dados retornados pela API oficial contratada.

Eles não são o PDF produzido pelo botão **Imprimir** do portal da Receita. O próprio arquivo deixa essa origem explícita para não confundir um relatório gerado pelo sistema com um comprovante impresso diretamente no portal.

## Instalação no Ubuntu / WSL

```bash
git pull
cd ~/discord-automation/agents/receita-auditor
chmod +x install-ubuntu.sh
./install-ubuntu.sh
```

## Configuração

Edite:

```bash
nano .env
```

Preencha:

```dotenv
SERPRO_CONSUMER_KEY=
SERPRO_CONSUMER_SECRET=
SERPRO_CNPJ_MODE=production

RECEITA_WORKBOOK_PATH=/home/andre/Auditoria/estrutura prototipo.xlsx
```

As credenciais não devem ser enviadas em chat nem commitadas.

## Conferir a planilha

Sem consultar a Receita:

```bash
npm run inspect -- --input "/home/andre/Auditoria/estrutura prototipo.xlsx"
```

## Testar a API

```bash
npm run serpro:check -- --cnpj "00.000.000/0001-00"
```

## Gerar somente uma empresa

Com `RECEITA_WORKBOOK_PATH` configurado:

```bash
npm run gerar -- --limit 1
```

## Gerar o lote inteiro

```bash
npm run gerar
```

Também é possível informar a planilha diretamente:

```bash
npm run gerar -- \
  --input "/home/andre/Auditoria/estrutura prototipo.xlsx" \
  --output "/home/andre/Auditoria/resultado"
```

## Saída

Por padrão:

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

O JSON bruto é mantido por padrão para rastreabilidade da auditoria. Para não incluí-lo:

```dotenv
SERPRO_KEEP_RAW=0
```

## Segurança

O repositório não deve conter:

- Consumer Key;
- Consumer Secret;
- planilha;
- PDFs;
- JSONs;
- ZIPs.

O `.env` e a pasta `data/` ficam fora do Git.
