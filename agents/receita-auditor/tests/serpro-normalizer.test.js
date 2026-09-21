import test from "node:test";
import assert from "node:assert/strict";
import { normalizeSerproCnpj } from "../src/serpro-normalizer.js";

test("normaliza retorno QSA do SERPRO", () => {
  const data = normalizeSerproCnpj({
    ni: "28153011000120",
    tipoEstabelecimento: "1",
    nomeEmpresarial: "APOLO ADMINISTRACAO DE RECURSOS LTDA",
    nomeFantasia: "",
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
      tipoLogradouro: "R",
      logradouro: "GOMES DE CARVALHO",
      numero: "1765",
      complemento: "CONJ 61",
      cep: "04547901",
      bairro: "VILA OLIMPIA",
      municipio: {
        codigo: "7107",
        descricao: "SAO PAULO"
      },
      uf: "SP"
    },
    situacaoCadastral: {
      codigo: "2",
      data: "20170711",
      motivo: ""
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
  });

  assert.equal(data.cnpjFormatado, "28.153.011/0001-20");
  assert.equal(data.tipoEstabelecimento, "MATRIZ");
  assert.equal(data.dataAbertura, "11/07/2017");
  assert.equal(data.porte, "DEMAIS");
  assert.equal(data.cnaePrincipal.codigo, "66.30-4-00");
  assert.equal(data.naturezaJuridica.codigo, "206-2");
  assert.equal(data.endereco.cep, "04547-901");
  assert.equal(data.situacaoCadastral.descricao, "ATIVA");
  assert.equal(data.socios.length, 1);
  assert.equal(data.socios[0].qualificacaoCodigo, "49");
});
