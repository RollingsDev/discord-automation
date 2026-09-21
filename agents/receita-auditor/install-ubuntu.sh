#!/usr/bin/env bash
set -euo pipefail

echo "=== Auditor Receita - instalação Ubuntu ==="

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

npm install

echo
echo "Instalando Chromium e dependências do Playwright..."
npx playwright install --with-deps chromium

if [ ! -f ".env" ]; then
  cp ".env.example" ".env"
  echo
  echo ".env criado a partir de .env.example."
  echo "Preencha os IDs e o Bot Token antes de iniciar o bot."
else
  echo
  echo ".env já existe; não foi alterado."
fi

mkdir -p data/browser-profile data/jobs data/output

echo
echo "Instalação concluída."
echo
echo "Para validar a planilha:"
echo 'npm run inspect -- --input "/home/$USER/Auditoria/estrutura.xlsx"'
echo
echo "Para testar somente uma empresa:"
echo 'npm start -- --input "/home/$USER/Auditoria/estrutura.xlsx" --output "/home/$USER/Auditoria/saida" --limit 1'
echo
echo "IMPORTANTE: este agente precisa de sessão gráfica (Ubuntu Desktop/X11/Wayland)"
echo "para que o auditor consiga resolver manualmente o 'Sou humano'."
