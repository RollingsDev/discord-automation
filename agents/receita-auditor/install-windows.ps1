$ErrorActionPreference = "Stop"

Write-Host "=== Auditor Receita - instalacao Windows ===" -ForegroundColor Cyan

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    throw "Node.js nao encontrado. Instale o Node.js LTS antes de continuar."
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw "npm nao encontrado."
}

Write-Host "Node: $(node --version)"
Write-Host "npm:  $(npm --version)"

npm install
if ($LASTEXITCODE -ne 0) { throw "npm install falhou." }

npm run install-browser
if ($LASTEXITCODE -ne 0) { throw "Falha ao instalar Chromium do Playwright." }

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host ".env criado a partir de .env.example." -ForegroundColor Yellow
    Write-Host "Preencha os IDs e o Bot Token antes de iniciar o bot." -ForegroundColor Yellow
}
else {
    Write-Host ".env ja existe; nao foi alterado."
}

Write-Host ""
Write-Host "Instalacao concluida." -ForegroundColor Green
Write-Host "Teste a planilha com:"
Write-Host 'npm run inspect -- --input "C:\Auditoria\estrutura.xlsx"'
Write-Host ""
Write-Host "Depois teste apenas uma empresa:"
Write-Host 'npm start -- --input "C:\Auditoria\estrutura.xlsx" --output "C:\Auditoria\saida" --limit 1'
