#!/usr/bin/env bash
set -euo pipefail

echo "=== Auditor Receita - instalação Ubuntu/WSL ==="

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js não encontrado."
  echo "Instale o Node.js LTS antes de continuar."
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "npm não encontrado."
  exit 1
fi

echo "Node: $(node --version)"
echo "npm:  $(npm --version)"

echo
echo "Instalando dependências Node..."
npm install

if [ ! -f ".env" ]; then
  cp ".env.example" ".env"
  echo
  echo ".env criado a partir de .env.example."
else
  echo
  echo ".env já existe; não foi alterado."
fi

mkdir -p data/jobs data/output

echo
echo "Instalação concluída."
echo
echo "Não é necessário Chromium, Playwright nem interface gráfica no modo SERPRO."
echo
echo "Próximos passos:"
echo "1. Preencha SERPRO_CONSUMER_KEY e SERPRO_CONSUMER_SECRET no .env"
echo "2. Configure RECEITA_WORKBOOK_PATH para usar o lote fixo"
echo "3. Teste o token/API com:"
echo '   npm run serpro:check -- --cnpj "SEU_CNPJ_DE_TESTE"'
echo "4. Rode o lote:"
echo "   npm start"
