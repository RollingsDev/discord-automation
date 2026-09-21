import "dotenv/config";
import process from "node:process";
import { SerproCnpjClient } from "./serpro-client.js";
import { formatCnpj } from "./utils.js";
import { normalizeSerproCnpj } from "./serpro-normalizer.js";

const index = process.argv.indexOf("--cnpj");
const cnpj = index >= 0 ? process.argv[index + 1] : "";

if (!cnpj) {
  console.error('Uso: npm run serpro:check -- --cnpj "00.000.000/0001-00"');
  process.exit(1);
}

const client = new SerproCnpjClient();

console.log("Obtendo token SERPRO...");
await client.getToken();

console.log(`Consultando ${formatCnpj(cnpj)}...`);
const response = await client.queryQsa(cnpj, {
  requestTag: "receita-check"
});

const data = normalizeSerproCnpj(response.data);

console.log(
  JSON.stringify(
    {
      httpStatus: response.httpStatus,
      partial: response.partial,
      cnpj: data.cnpjFormatado,
      nomeEmpresarial: data.nomeEmpresarial,
      situacao: data.situacaoCadastral.descricao,
      socios: data.socios.length
    },
    null,
    2
  )
);
