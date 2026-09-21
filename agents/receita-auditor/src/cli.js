import "dotenv/config";
import path from "node:path";
import process from "node:process";
import { processWorkbook } from "./processor.js";
import { formatCnpj } from "./utils.js";

const args = parseArgs(process.argv.slice(2));

if (!args.input) {
  console.error(
    'Uso: npm start -- --input "C:\\caminho\\estrutura.xlsx" [--output "C:\\saida"] [--limit 1]'
  );
  process.exit(1);
}

const inputPath = path.resolve(args.input);
const outputRoot = path.resolve(
  args.output ?? path.join(process.cwd(), "data", "output")
);
const profileDir = path.resolve(
  process.env.RECEITA_BROWSER_PROFILE ||
    path.join(process.cwd(), "data", "browser-profile")
);
const limit = Number(args.limit ?? process.env.RECEITA_LIMIT ?? 0) || 0;

console.log("Receita Auditor");
console.log(`Entrada: ${inputPath}`);
console.log(`Saída:   ${outputRoot}`);
console.log(
  "O robô NÃO resolve CAPTCHA. Quando a Receita mostrar 'Sou humano', marque manualmente no navegador."
);

const result = await processWorkbook({
  inputPath,
  outputRoot,
  profileDir,
  limit,
  onEvent: async message => console.log(message),
  onProgress: async progress => {
    console.log(
      `[${progress.current}/${progress.total}] ${formatCnpj(progress.company.cnpj)} — ${progress.status}`
    );
  },
  onHumanRequired: async company => {
    console.log("");
    console.log("============================================================");
    console.log(
      `🔐 AÇÃO HUMANA NECESSÁRIA: marque 'Sou humano' para ${formatCnpj(company.cnpj)} — ${company.name}`
    );
    console.log("Não feche o navegador. O agente continuará sozinho após a validação.");
    console.log("============================================================");
    console.log("");
  }
});

console.log("");
console.log("Processamento concluído.");
console.log(`CNPJs: ${result.companies}`);
console.log(`Sucesso: ${result.ok}`);
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
