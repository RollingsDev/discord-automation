import path from "node:path";
import process from "node:process";
import { readCompaniesFromWorkbook } from "./spreadsheet.js";
import { formatCnpj } from "./utils.js";

const inputIndex = process.argv.indexOf("--input");
const input =
  inputIndex >= 0 && process.argv[inputIndex + 1]
    ? process.argv[inputIndex + 1]
    : "";

if (!input) {
  console.error('Uso: npm run inspect -- --input "C:\\Auditoria\\estrutura.xlsx"');
  process.exit(1);
}

const filePath = path.resolve(input);
const result = await readCompaniesFromWorkbook(filePath);

console.log(`Arquivo: ${filePath}`);
console.log(`CNPJs únicos válidos: ${result.companies.length}`);
console.log(`Linhas ignoradas: ${result.skipped.length}`);
console.log("");

for (const company of result.companies) {
  console.log(
    `${formatCnpj(company.cnpj)} | ${company.name} | ${company.sheets.join(" | ")}`
  );
}

if (result.skipped.length) {
  console.log("");
  console.log("Ignorados:");

  for (const item of result.skipped) {
    console.log(
      `${item.sheet} linha ${item.row}: ${item.name || "(sem nome)"} — ${item.rawCnpj || "(sem CNPJ)"} — ${item.reason}`
    );
  }
}
