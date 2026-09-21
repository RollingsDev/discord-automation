import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { generateCnpjPdfs } from "../src/pdf-generator.js";

test("gera ISC e QSA em PDF", async () => {
  const dir = await fs.mkdtemp(
    path.join(os.tmpdir(), "receita-pdf-")
  );

  const result = await generateCnpjPdfs({
    entity: {
      cnpj: "28153011000120",
      name: "APOLO ADMINISTRACAO DE RECURSOS LTDA"
    },
    outputDir: dir,
    queriedAt: new Date("2026-09-21T13:24:48Z"),
    serproData: {
      ni: "28153011000120",
      tipoEstabelecimento: "1",
      nomeEmpresarial: "APOLO ADMINISTRACAO DE RECURSOS LTDA",
      dataAbertura: "20170711",
      porte: "05",
      cnaePrincipal: {
        codigo: "6630400",
        descricao: "Atividades de administracao de fundos"
      },
      naturezaJuridica: {
        codigo: "2062",
        descricao: "Sociedade Empresaria Limitada"
      },
      endereco: {
        logradouro: "GOMES DE CARVALHO",
        numero: "1765",
        cep: "04547901",
        bairro: "VILA OLIMPIA",
        municipio: { descricao: "SAO PAULO" },
        uf: "SP"
      },
      situacaoCadastral: {
        codigo: "2",
        data: "20170711"
      },
      capitalSocial: 1056735.15,
      socios: [
        {
          nome: "SOCIO TESTE",
          qualificacao: {
            codigo: "49",
            descricao: "Socio-Administrador"
          }
        }
      ]
    }
  });

  for (const file of [result.iscPath, result.qsaPath]) {
    const buffer = await fs.readFile(file);

    assert.equal(buffer.subarray(0, 4).toString(), "%PDF");
    assert.ok(buffer.length > 1000);
  }
});
