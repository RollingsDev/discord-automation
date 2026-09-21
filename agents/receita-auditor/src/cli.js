import "dotenv/config";
import path from "node:path";
import process from "node:process";
import { processWorkbook } from "./processor.js";
import { formatCnpj } from "./utils.js";

const args = parseArgs(process.argv.slice(2));

const configuredWorkbook =
  process.env.RECEITA_WORKBOOK_PATH?.trim() || "";

const input = args.input || configuredWorkbook;

if (!input) {
  console.error(
    'Uso: npm start -- --input "/caminho/estrutura.xlsx" [--output "/caminho/saida"] [--limit 1]'
  );
  console.error(
    "Ou configure RECEITA_WORKBOOK_PATH no .env para não precisar informar --input."
  );
  process.exit(1);
}

const inputPath = path.resolve(input);
const outputRoot = path.resolve(
  args.output ??
    path.join(process.cwd(), "data", "output")
);
const limit =
  Number(
    args.limit ??
      process.env.RECEITA_LIMIT ??
      0
  ) || 0;

console.log("Receita Auditor - modo API SERPRO");
console.log(`Entrada: ${inputPath}`);
console.log(`Saída:   ${outputRoot}`);
console.log(
  "Fluxo automático: 1 consulta QSA por CNPJ, geração de ISC + QSA e ZIP."
);

const result = await processWorkbook({
  inputPath,
  outputRoot,
  limit,
  onEvent: async message => console.log(message),
  onProgress: async progress => {
    console.log(
      `[${progress.current}/${progress.total}] ${formatCnpj(progress.company.cnpj)} - ${progress.status}`
    );
  }
});

console.log("");
console.log("Processamento concluído.");
console.log(`CNPJs: ${result.companies}`);
console.log(`Sucesso: ${result.ok}`);
console.log(`Retornos parciais: ${result.partial}`);
console.log(`Erros: ${result.errors}`);
console.log(`ZIP completo: ${result.fullZip}`);

function parseArgs(values) {
  const result = {};

  for (let index = 0; index < values.length; index++) {
    const value = values[index];

    if (!value.startsWith("--")) continue;

    const key = value.slice(2);
    const next = values[index + 1];

    if (next && !next.startsWith("--")) {
      result[key] = next;
      index++;
    } else {
      result[key] = true;
    }
  }

  return result;
}
