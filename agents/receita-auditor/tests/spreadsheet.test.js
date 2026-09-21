import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import ExcelJS from "exceljs";
import { readCompaniesFromWorkbook } from "../src/spreadsheet.js";

test("encontra Entidade/CNPJ em sheets e deduplica CNPJ", async () => {
  const dir = await fs.mkdtemp(
    path.join(os.tmpdir(), "receita-auditor-")
  );
  const file = path.join(dir, "teste.xlsx");

  const workbook = new ExcelJS.Workbook();

  const first = workbook.addWorksheet("01 Grupo");
  first.addRow(["Título"]);
  first.addRow([]);
  first.addRow(["Nº", "Entidade", "CNPJ", "Observação"]);
  first.addRow([1, "EMPRESA A LTDA.", "12.345.678/0001-95", ""]);
  first.addRow([2, "SEM CNPJ LTDA.", "Em constituição", ""]);

  const second = workbook.addWorksheet("02 Outro");
  second.addRow(["Nº", "Entidade", "CNPJ"]);
  second.addRow([1, "EMPRESA A LTDA.", "12.345.678/0001-95"]);
  second.addRow([2, "EMPRESA B S.A.", "11.222.333/0001-81"]);

  const summary = workbook.addWorksheet("00 Resumo");
  summary.addRow(["Controladora", "CNPJ"]);
  summary.addRow(["EMPRESA A", "12.345.678/0001-95"]);

  await workbook.xlsx.writeFile(file);

  const result = await readCompaniesFromWorkbook(file);

  assert.equal(result.companies.length, 2);
  assert.equal(result.skipped.length, 1);
  assert.deepEqual(result.companies[0].sheets, [
    "01 Grupo",
    "02 Outro"
  ]);
  assert.equal(result.companies[1].name, "EMPRESA B S.A.");
});
