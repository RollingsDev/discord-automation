import fs from "node:fs/promises";
import path from "node:path";
import { SerproCnpjClient } from "./serpro-client.js";
import { generateCnpjPdfs } from "./pdf-generator.js";
import { readCompaniesFromWorkbook } from "./spreadsheet.js";
import {
  createFullZip,
  ensureOutputDirectories,
  writeManifest
} from "./output.js";
import { formatCnpj, isoNow, sleep } from "./utils.js";

export async function processWorkbook({
  inputPath,
  outputRoot,
  limit = 0,
  keepRaw = truthy(process.env.SERPRO_KEEP_RAW ?? "1"),
  delayMs = Number(process.env.SERPRO_DELAY_MS ?? 150) || 0,
  onEvent = async () => {},
  onProgress = async () => {}
}) {
  const parsed = await readCompaniesFromWorkbook(inputPath);
  const companies =
    limit > 0 ? parsed.companies.slice(0, limit) : parsed.companies;

  if (companies.length === 0) {
    throw new Error("Nenhum CNPJ válido foi encontrado na planilha.");
  }

  const dirs = await ensureOutputDirectories(outputRoot);
  const results = [];
  const client = new SerproCnpjClient();

  // Falha cedo se as credenciais não estiverem configuradas.
  client.validateConfig();
  await client.getToken();

  await onEvent(
    `Planilha carregada: ${companies.length} CNPJ(s) único(s); ${parsed.skipped.length} linha(s) ignorada(s).`
  );

  for (let index = 0; index < companies.length; index++) {
    const company = companies[index];

    const row = {
      ...company,
      status: "PROCESSANDO",
      startedAt: isoNow(),
      finishedAt: "",
      isc: "",
      qsa: "",
      apiStatus: "",
      partial: false,
      error: ""
    };

    results.push(row);

    await onProgress({
      current: index + 1,
      total: companies.length,
      company,
      status: "PROCESSANDO"
    });

    try {
      const queriedAt = new Date();
      const response = await client.queryQsa(company.cnpj, {
        requestTag: requestTagFor(index + 1)
      });

      row.apiStatus = String(response.httpStatus);
      row.partial = response.partial;

      if (keepRaw) {
        const rawPath = path.join(dirs.raw, `${company.cnpj}.json`);

        await fs.writeFile(
          rawPath,
          JSON.stringify(
            {
              queriedAt: queriedAt.toISOString(),
              httpStatus: response.httpStatus,
              partial: response.partial,
              requestId: response.requestId,
              data: response.data
            },
            null,
            2
          ),
          "utf8"
        );
      }

      const generated = await generateCnpjPdfs({
        entity: company,
        serproData: response.data,
        outputDir: dirs.pdf,
        queriedAt,
        sourceLabel: "SERPRO / Receita Federal"
      });

      row.name =
        generated.normalized.nomeEmpresarial || company.name;
      row.isc = path.basename(generated.iscPath);
      row.qsa = path.basename(generated.qsaPath);
      row.status = response.partial ? "OK_PARCIAL" : "OK";

      await onEvent(
        `✅ ${index + 1}/${companies.length} - ${formatCnpj(company.cnpj)} - ${row.name}` +
          (response.partial ? " (retorno parcial HTTP 206)" : "")
      );
    } catch (error) {
      row.status = "ERRO";
      row.error =
        error instanceof Error ? error.message : String(error);

      await onEvent(
        `❌ ${index + 1}/${companies.length} - ${formatCnpj(company.cnpj)} - ${row.error}`
      );
    } finally {
      row.finishedAt = isoNow();

      await writeManifest(
        dirs.root,
        results,
        parsed.skipped
      );

      await onProgress({
        current: index + 1,
        total: companies.length,
        company: {
          ...company,
          name: row.name || company.name
        },
        status: row.status,
        error: row.error
      });
    }

    if (delayMs > 0 && index < companies.length - 1) {
      await sleep(delayMs);
    }
  }

  const manifestPath = await writeManifest(
    dirs.root,
    results,
    parsed.skipped
  );

  const date = new Date()
    .toLocaleDateString("sv-SE", {
      timeZone: "America/Sao_Paulo"
    })
    .replaceAll("-", "");

  const fullZip = path.join(
    dirs.root,
    `Receita_CNPJ_${date}.zip`
  );

  await createFullZip(dirs.root, fullZip);

  return {
    companies: companies.length,
    skipped: parsed.skipped.length,
    ok: results.filter(row =>
      ["OK", "OK_PARCIAL"].includes(row.status)
    ).length,
    partial: results.filter(
      row => row.status === "OK_PARCIAL"
    ).length,
    errors: results.filter(
      row => row.status === "ERRO"
    ).length,
    results,
    manifestPath,
    fullZip
  };
}

function requestTagFor(index) {
  const prefix = String(
    process.env.SERPRO_REQUEST_TAG ||
      "auditoria-receita"
  )
    .replace(/[^a-zA-Z0-9._-]/g, "-")
    .slice(0, 24);

  return `${prefix}-${String(index).padStart(3, "0")}`.slice(
    0,
    32
  );
}

function truthy(value) {
  return ["1", "true", "yes", "sim", "on"].includes(
    String(value).trim().toLowerCase()
  );
}
