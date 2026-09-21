import ExcelJS from "exceljs";
import { isValidCnpj, onlyDigits } from "./utils.js";

export async function readCompaniesFromWorkbook(filePath) {
  const workbook = new ExcelJS.Workbook();
  await workbook.xlsx.readFile(filePath);

  const byCnpj = new Map();
  const skipped = [];

  for (const worksheet of workbook.worksheets) {
    const header = findHeader(worksheet);

    if (!header) continue;

    for (
      let rowNumber = header.row + 1;
      rowNumber <= worksheet.rowCount;
      rowNumber++
    ) {
      const row = worksheet.getRow(rowNumber);
      const name = cleanCell(row.getCell(header.entityColumn).value);
      const rawCnpj = cleanCell(row.getCell(header.cnpjColumn).value);

      if (!name && !rawCnpj) continue;

      const cnpj = onlyDigits(rawCnpj);

      if (!isValidCnpj(cnpj)) {
        skipped.push({
          sheet: worksheet.name,
          row: rowNumber,
          name,
          rawCnpj,
          reason: rawCnpj
            ? "CNPJ ausente ou inválido"
            : "Entidade sem CNPJ"
        });
        continue;
      }

      const existing = byCnpj.get(cnpj);

      if (existing) {
        if (!existing.sheets.includes(worksheet.name)) {
          existing.sheets.push(worksheet.name);
        }

        if (name && !existing.aliases.includes(name)) {
          existing.aliases.push(name);
        }

        continue;
      }

      byCnpj.set(cnpj, {
        cnpj,
        name,
        sheets: [worksheet.name],
        aliases: name ? [name] : []
      });
    }
  }

  return {
    companies: [...byCnpj.values()],
    skipped
  };
}

function findHeader(worksheet) {
  const maxScan = Math.min(20, worksheet.rowCount);

  for (let rowNumber = 1; rowNumber <= maxScan; rowNumber++) {
    const row = worksheet.getRow(rowNumber);
    let entityColumn = null;
    let cnpjColumn = null;

    row.eachCell({ includeEmpty: false }, (cell, columnNumber) => {
      const value = normalizeHeader(cell.value);

      if (value === "entidade") entityColumn = columnNumber;
      if (value === "cnpj") cnpjColumn = columnNumber;
    });

    if (entityColumn && cnpjColumn) {
      return {
        row: rowNumber,
        entityColumn,
        cnpjColumn
      };
    }
  }

  return null;
}

function cleanCell(value) {
  if (value === null || value === undefined) return "";

  if (typeof value === "object") {
    if ("text" in value) return String(value.text ?? "").trim();
    if ("result" in value) return String(value.result ?? "").trim();
  }

  return String(value).trim();
}

function normalizeHeader(value) {
  return cleanCell(value)
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/\s+/g, " ")
    .trim();
}
