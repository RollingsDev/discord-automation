import test from "node:test";
import assert from "node:assert/strict";
import {
  companyBaseFilename,
  filenameCnpj,
  formatCnpj,
  isValidCnpj,
  onlyDigits,
  sanitizeFilename
} from "../src/utils.js";

test("valida e formata CNPJ", () => {
  const cnpj = "12.345.678/0001-95";

  assert.equal(onlyDigits(cnpj), "12345678000195");
  assert.equal(isValidCnpj(cnpj), true);
  assert.equal(formatCnpj(cnpj), "12.345.678/0001-95");
  assert.equal(filenameCnpj(cnpj), "12.345.678.0001-95");
});

test("rejeita CNPJ inválido", () => {
  assert.equal(isValidCnpj("12.345.678/0001-00"), false);
  assert.equal(isValidCnpj,("11111111111111"), false);
});
