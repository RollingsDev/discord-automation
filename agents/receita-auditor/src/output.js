import fs from "node:fs/promises";
import path from "node:path";
import archiver from "archiver";
import { createWriteStream } from "node:fs";
import { filenameCnpj } from "./utils.js";

export async function ensureOutputDirectories(root) {
  const dirs = {
    root,
    pdf: path.join(root, "pdf"),
    debug: path.join(root, "debug")
  };

  await Promise.all(
    Object.values(dirs).map(directory =>
      fs.mkdir(directory, { recursive: true })
    )
  );

  return dirs;
}

export async function writeManifest(root, rows, skipped = []) {
  const csvPath = path.join(root, "_controle.csv");
  const lines = [
    [
      "CNPJ",
      "Razao Social",
      "Sheets",
      "ISC",
      "QSA",
      "Status",
      "Inicio",
      "Fim",
      "Erro"
    ]
  ];

  for (const row of rows) {
    lines.push([
      filenameCnpj(row.cnpj),
      row.name,
      (row.sheets ?? []).join(" | "),
      row.isc ?? "",
      row.qsa ?? "",
      row.status ?? "",
      row.startedAt ?? "",
      row.finishedAt ?? "",
      row.error ?? ""
    ]);
  }

  for (const item of skipped) {
    lines.push([
      item.rawCnpj ?? "",
      item.name ?? "",
      item.sheet ?? "",
      "",
      "",
      "IGNORADO",
      "",
      "",
      item.reason ?? ""
    ]);
  }

  const csv = lines.map(values => values.map(csvCell).join(";")).join("\r\n");
  await fs.writeFile(csvPath, "\uFEFF" + csv, "utf8");

  return csvPath;
}

export async function createFullZip(root, outputZip) {
  const entries = await fs.readdir(root);
  const filtered = entries.filter(name => !name.endsWith(".zip"));

  await createZip(
    outputZip,
    filtered.map(name => ({
      source: path.join(root, name),
      name
    }))
  );

  return outputZip;
}

export async function createDiscordChunks(
  root,
  pdfFiles,
  manifestPath,
  maxBytes
) {
  const groups = [];
  let current = [];

  for (const file of pdfFiles) {
    current.push(file);

    const rawSize = await totalSize(current);

    // PDFs normalmente já são comprimidos. Mantemos margem antes do limite.
    if (rawSize > maxBytes * 0.82 && current.length > 1) {
      const last = current.pop();
      groups.push(current);
      current = [last];
    }
  }

  if (current.length) groups.push(current);

  const outputs = [];

  for (let index = 0; index < groups.length; index++) {
    const zipPath = path.join(
      root,
      `Receita_Discord_parte-${String(index + 1).padStart(2, "0")}.zip`
    );

    const entries = groups[index].map(file => ({
      source: file,
      name: path.basename(file)
    }));

    entries.push({
      source: manifestPath,
      name: path.basename(manifestPath)
    });

    await createZip(zipPath, entries);
    outputs.push(zipPath);
  }

  return outputs;
}

async function createZip(zipPath, entries) {
  await new Promise((resolve, reject) => {
    const output = createWriteStream(zipPath);
    const archive = archiver("zip", { zlib: { level: 9 } });

    output.on("close", resolve);
    output.on("error", reject);
    archive.on("error", reject);

    archive.pipe(output);

    for (const entry of entries) {
      archive.file(entry.source, { name: entry.name });
    }

    archive.finalize();
  });
}

async function totalSize(files) {
  let total = 0;

  for (const file of files) {
    total += (await fs.stat(file)).size;
  }

  return total;
}

function csvCell(value) {
  const text = String(value ?? "").replaceAll('"', '""');
  return `"${text}"`;
}
