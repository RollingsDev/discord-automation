import fs from "node:fs/promises";
import path from "node:path";
import { ReceitaBrowser } from "./receita-browser.js";
import { readCompaniesFromWorkbook } from "./spreadsheet.js";
import {
  createDiscordChunks,
  createFullZip,
  ensureOutputDirectories,
  writeManifest
} from "./output.js";
import { formatCnpj, isoNow } from "./utils.js";

export async function processWorkbook({
  inputPath,
  outputRoot,
  profileDir,
  limit = 0,
  uploadMaxBytes = 9 * 1024 * 1024,
  onEvent = async () => {},
  onProgress = async () => {},
  onHumanRequired = async () => {}
}) {
  const parsed = await readCompaniesFromWorkbook(inputPath);
  const companies =
    limit > 0 ? parsed.companies.slice(0, limit) : parsed.companies;

  if (companies.length === 0) {
    throw new Error("Nenhum CNPJ válido foi encontrado na planilha.");
  }

  const dirs = await ensureOutputDirectories(outputRoot);
  const results = [];
  const browser = new ReceitaBrowser({
    profileDir,
    onHumanRequired,
    onEvent
  });

  await onEvent(
    `Planilha carregada: ${companies.length} CNPJ(s) único(s); ${parsed.skipped.length} linha(s) ignorada(s).`
  );

  try {
    await browser.start();

    for (let index = 0; index < companies.length; index++) {
      const company = companies[index];
      const row = {
        ...company,
        status: "PROCESSANDO",
        startedAt: isoNow(),
        finishedAt: "",
        isc: "",
        qsa: "",
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
        const files = await browser.captureCompany(company, dirs);

        row.isc = path.basename(files.iscPath);
        row.qsa = path.basename(files.qsaPath);
        row.status = "OK";

        await onEvent(
          `✅ ${index + 1}/${companies.length} — ${formatCnpj(company.cnpj)} — ${company.name}`
        );
      } catch (error) {
        row.status = "ERRO";
        row.error = error instanceof Error ? error.message : String(error);

        await captureDebug(browser.page, dirs.debug, company.cnpj);

        await onEvent(
          `❌ ${index + 1}/${companies.length} — ${formatCnpj(company.cnpj)} — ${row.error}`
        );
      } finally {
        row.finishedAt = isoNow();

        await writeManifest(dirs.root, results, parsed.skipped);

        await onProgress({
          current: index + 1,
          total: companies.length,
          company,
          status: row.status,
          error: row.error
        });
      }
    }
  } finally {
    await browser.close();
  }

  const manifestPath = await writeManifest(
    dirs.root,
    results,
    parsed.skipped
  );

  const date = new Date()
    .toLocaleDateString("sv-SE", { timeZone: "America/Sao_Paulo" })
    .replaceAll("-", "");

  const fullZip = path.join(dirs.root, `Receita_CNPJ_${date}.zip`);
  await createFullZip(dirs.root, fullZip);

  const successfulPdfs = results
    .filter(row => row.status === "OK")
    .flatMap(row => [
      path.join(dirs.pdf, row.isc),
      path.join(dirs.pdf, row.qsa)
    ]);

  const discordFiles = await chooseDiscordFiles({
    fullZip,
    root: dirs.root,
    pdfFiles: successfulPdfs,
    manifestPath,
    uploadMaxBytes
  });

  return {
    companies: companies.length,
    skipped: parsed.skipped.length,
    ok: results.filter(row => row.status === "OK").length,
    errors: results.filter(row => row.status === "ERRO").length,
    results,
    manifestPath,
    fullZip,
    discordFiles
  };
}

async function chooseDiscordFiles({
  fullZip,
  root,
  pdfFiles,
  manifestPath,
  uploadMaxBytes
}) {
  const stat = await fs.stat(fullZip);

  if (stat.size <= uploadMaxBytes) {
    return [fullZip];
  }

  return createDiscordChunks(
    root,
    pdfFiles,
    manifestPath,
    uploadMaxBytes
  );
}

async function captureDebug(page, debugDir, cnpj) {
  if (!page) return;

  const safe = String(cnpj).replace(/\D/g, "");

  await page
    .screenshot({
      path: path.join(debugDir, `${safe}-erro.png`),
      fullPage: true
    })
    .catch(() => {});

  const html = await page.content().catch(() => "");

  if (html) {
    await fs
      .writeFile(
        path.join(debugDir, `${safe}-erro.html`),
        html,
        "utf8"
      )
      .catch(() => {});
  }
}
