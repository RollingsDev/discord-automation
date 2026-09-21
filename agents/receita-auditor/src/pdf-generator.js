import fs from "node:fs";
import path from "node:path";
import PDFDocument from "pdfkit";
import { companyBaseFilename, formatCnpj } from "./utils.js";
import { normalizeSerproCnpj } from "./serpro-normalizer.js";

const PAGE = {
  size: "A4",
  margins: { top: 42, right: 42, bottom: 42, left: 42 }
};

export async function generateCnpjPdfs({
  entity,
  serproData,
  outputDir,
  queriedAt = new Date(),
  sourceLabel = "SERPRO / Receita Federal"
}) {
  const normalized = normalizeSerproCnpj(serproData);
  const companyName =
    normalized.nomeEmpresarial || entity.name || "EMPRESA";
  const base = companyBaseFilename({
    cnpj: entity.cnpj,
    name: companyName
  });

  const iscPath = path.join(outputDir, `${base} - ISC.pdf`);
  const qsaPath = path.join(outputDir, `${base} - QSA.pdf`);

  await writeIscPdf({
    filePath: iscPath,
    data: normalized,
    queriedAt,
    sourceLabel
  });

  await writeQsaPdf({
    filePath: qsaPath,
    data: normalized,
    queriedAt,
    sourceLabel
  });

  return { iscPath, qsaPath, normalized };
}

async function writeIscPdf({
  filePath,
  data,
  queriedAt,
  sourceLabel
}) {
  await createPdf(filePath, doc => {
    header(doc, "CADASTRO NACIONAL DA PESSOA JURIDICA");
    title(doc, "RELATORIO DE INSCRICAO E SITUACAO CADASTRAL");

    row2(doc,
      field("NUMERO DE INSCRICAO", data.cnpjFormatado || formatCnpj(data.cnpj)),
      field("TIPO", data.tipoEstabelecimento)
    );

    fullField(doc, "NOME EMPRESARIAL", data.nomeEmpresarial);
    row2(doc,
      field("NOME FANTASIA", data.nomeFantasia || "NAO INFORMADO"),
      field("PORTE", data.porte || "NAO INFORMADO")
    );

    fullField(
      doc,
      "ATIVIDADE ECONOMICA PRINCIPAL",
      joinCodeDescription(data.cnaePrincipal)
    );

    const secondary = data.cnaesSecundarios.length
      ? data.cnaesSecundarios.map(joinCodeDescription).join("\n")
      : "NAO INFORMADA";

    fullField(doc, "ATIVIDADES ECONOMICAS SECUNDARIAS", secondary);

    fullField(
      doc,
      "NATUREZA JURIDICA",
      joinCodeDescription(data.naturezaJuridica)
    );

    row2(doc,
      field(
        "LOGRADOURO",
        [data.endereco.tipoLogradouro, data.endereco.logradouro]
          .filter(Boolean)
          .join(" ")
      ),
      field("NUMERO", data.endereco.numero)
    );

    fullField(doc, "COMPLEMENTO", data.endereco.complemento || "NAO INFORMADO");

    row3(doc,
      field("CEP", data.endereco.cep),
      field("BAIRRO/DISTRITO", data.endereco.bairro),
      field(
        "MUNICIPIO / UF",
        [data.endereco.municipio, data.endereco.uf]
          .filter(Boolean)
          .join(" / ")
      )
    );

    row2(doc,
      field("ENDERECO ELETRONICO", data.email || "NAO INFORMADO"),
      field(
        "TELEFONE",
        data.telefones.length
          ? data.telefones.join(" / ")
          : "NAO INFORMADO"
      )
    );

    fullField(
      doc,
      "ENTE FEDERATIVO RESPONSAVEL",
      data.enteFederativo || "NAO INFORMADO"
    );

    row2(doc,
      field(
        "SITUACAO CADASTRAL",
        data.situacaoCadastral.descricao ||
          data.situacaoCadastral.codigo ||
          "NAO INFORMADA"
      ),
      field(
        "DATA DA SITUACAO CADASTRAL",
        data.situacaoCadastral.data || "NAO INFORMADA"
      )
    );

    fullField(
      doc,
      "MOTIVO DA SITUACAO CADASTRAL",
      data.situacaoCadastral.motivo || "NAO INFORMADO"
    );

    row2(doc,
      field(
        "SITUACAO ESPECIAL",
        data.situacaoEspecial || "NAO INFORMADA"
      ),
      field(
        "DATA DA SITUACAO ESPECIAL",
        data.dataSituacaoEspecial || "NAO INFORMADA"
      )
    );

    auditFooter(doc, queriedAt, sourceLabel);
  });
}

async function writeQsaPdf({
  filePath,
  data,
  queriedAt,
  sourceLabel
}) {
  await createPdf(filePath, doc => {
    header(doc, "CONSULTA CNPJ");
    title(doc, "QUADRO DE SOCIOS E ADMINISTRADORES - QSA");

    fullField(
      doc,
      "CNPJ",
      data.cnpjFormatado || formatCnpj(data.cnpj)
    );
    fullField(doc, "NOME EMPRESARIAL", data.nomeEmpresarial);
    fullField(
      doc,
      "CAPITAL SOCIAL",
      formatCurrency(data.capitalSocial)
    );

    doc.moveDown(0.6);

    if (!data.socios.length) {
      panel(doc, [
        ["QSA", "Nenhum socio/administrador foi retornado pela API para este CNPJ."]
      ]);
    } else {
      data.socios.forEach((socio, index) => {
        ensureRoom(doc, 120);

        const qual = [
          socio.qualificacaoCodigo,
          socio.qualificacao
        ].filter(Boolean).join(" - ");

        const rows = [
          ["NOME / NOME EMPRESARIAL", socio.nome || "NAO INFORMADO"],
          ["QUALIFICACAO", qual || "NAO INFORMADA"]
        ];

        if (socio.tipoSocio) {
          rows.push(["TIPO DE SOCIO", socio.tipoSocio]);
        }

        if (socio.documento) {
          rows.push(["DOCUMENTO", socio.documento]);
        }

        if (socio.dataInclusao) {
          rows.push(["DATA DE INCLUSAO", socio.dataInclusao]);
        }

        if (socio.pais) {
          rows.push(["PAIS", socio.pais]);
        }

        if (socio.representanteLegal.nome) {
          rows.push([
            "REPRESENTANTE LEGAL",
            socio.representanteLegal.nome
          ]);

          if (socio.representanteLegal.qualificacao) {
            rows.push([
              "QUALIFICACAO DO REPRESENTANTE",
              socio.representanteLegal.qualificacao
            ]);
          }
        }

        panel(doc, rows, `REGISTRO ${index + 1}`);
        doc.moveDown(0.5);
      });
    }

    auditFooter(doc, queriedAt, sourceLabel);
  });
}

function createPdf(filePath, draw) {
  return new Promise((resolve, reject) => {
    const doc = new PDFDocument({
      ...PAGE,
      info: {
        Title: path.basename(filePath),
        Author: "Auditor Receita",
        Subject: "Dados cadastrais CNPJ consultados via SERPRO/RFB"
      }
    });

    const stream = fs.createWriteStream(filePath);

    stream.on("finish", resolve);
    stream.on("error", reject);
    doc.on("error", reject);

    doc.pipe(stream);
    draw(doc);
    doc.end();
  });
}

function header(doc, text) {
  doc
    .font("Helvetica-Bold")
    .fontSize(13)
    .text("REPUBLICA FEDERATIVA DO BRASIL", { align: "center" });

  doc.moveDown(0.3);

  doc
    .font("Helvetica-Bold")
    .fontSize(12)
    .text(text, { align: "center" });

  doc.moveDown(0.8);
}

function title(doc, text) {
  doc
    .font("Helvetica-Bold")
    .fontSize(11)
    .text(text, { align: "center" });

  doc.moveDown(0.8);
}

function field(label, value) {
  return {
    label,
    value: String(value || "NAO INFORMADO")
  };
}

function fullField(doc, label, value) {
  drawCells(doc, [field(label, value)], [1]);
}

function row2(doc, left, right) {
  drawCells(doc, [left, right], [0.62, 0.38]);
}

function row3(doc, a, b, c) {
  drawCells(doc, [a, b, c], [0.22, 0.36, 0.42]);
}

function drawCells(doc, cells, widths) {
  const x = doc.page.margins.left;
  const available =
    doc.page.width - doc.page.margins.left - doc.page.margins.right;

  const heights = cells.map((cell, index) => {
    const width = available * widths[index] - 12;
    const labelHeight = doc
      .font("Helvetica")
      .fontSize(6)
      .heightOfString(cell.label, { width });
    const valueHeight = doc
      .font("Helvetica-Bold")
      .fontSize(8.5)
      .heightOfString(cell.value || "NAO INFORMADO", { width });

    return Math.max(39, 9 + labelHeight + valueHeight + 10);
  });

  const height = Math.max(...heights);
  ensureRoom(doc, height + 2);

  let cursorX = x;

  cells.forEach((cell, index) => {
    const width = available * widths[index];

    doc.rect(cursorX, doc.y, width, height).lineWidth(0.7).stroke();

    const y = doc.y + 5;

    doc
      .font("Helvetica")
      .fontSize(6)
      .text(cell.label, cursorX + 6, y, {
        width: width - 12
      });

    doc
      .font("Helvetica-Bold")
      .fontSize(8.5)
      .text(cell.value || "NAO INFORMADO", cursorX + 6, y + 10, {
        width: width - 12
      });

    cursorX += width;
  });

  doc.y += height;
}

function panel(doc, rows, heading = "") {
  const x = doc.page.margins.left;
  const width =
    doc.page.width - doc.page.margins.left - doc.page.margins.right;

  const startY = doc.y;
  let contentHeight = heading ? 24 : 8;

  for (const [label, value] of rows) {
    contentHeight +=
      doc
        .font("Helvetica-Bold")
        .fontSize(7)
        .heightOfString(label, { width: width - 20 }) +
      doc
        .font("Helvetica")
        .fontSize(9)
        .heightOfString(String(value || "NAO INFORMADO"), {
          width: width - 20
        }) +
      8;
  }

  ensureRoom(doc, contentHeight + 10);

  const y = doc.y;

  doc
    .roundedRect(x, y, width, contentHeight, 4)
    .lineWidth(0.7)
    .stroke();

  let cursorY = y + 8;

  if (heading) {
    doc
      .font("Helvetica-Bold")
      .fontSize(8)
      .text(heading, x + 10, cursorY, { width: width - 20 });
    cursorY += 16;
  }

  for (const [label, value] of rows) {
    doc
      .font("Helvetica-Bold")
      .fontSize(7)
      .text(label, x + 10, cursorY, { width: width - 20 });

    cursorY = doc.y + 1;

    doc
      .font("Helvetica")
      .fontSize(9)
      .text(String(value || "NAO INFORMADO"), x + 10, cursorY, {
        width: width - 20
      });

    cursorY = doc.y + 5;
  }

  doc.y = y + contentHeight;
}

function auditFooter(doc, queriedAt, sourceLabel) {
  ensureRoom(doc, 65);
  doc.moveDown(1);

  doc
    .font("Helvetica")
    .fontSize(7.5)
    .text(
      "Documento gerado automaticamente a partir de dados consultados na API oficial contratada. " +
      "Nao substitui o comprovante impresso emitido diretamente pelo portal da Receita Federal.",
      { align: "left" }
    );

  doc.moveDown(0.4);

  doc
    .font("Helvetica")
    .fontSize(7.5)
    .text(
      `Fonte: ${sourceLabel}. Consulta em ${formatDateTime(queriedAt)}.`
    );
}

function ensureRoom(doc, requiredHeight) {
  const bottom =
    doc.page.height - doc.page.margins.bottom;

  if (doc.y + requiredHeight > bottom) {
    doc.addPage(PAGE);
  }
}

function joinCodeDescription(item) {
  return [item?.codigo, item?.descricao]
    .filter(Boolean)
    .join(" - ") || "NAO INFORMADO";
}

function formatCurrency(value) {
  return new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: "BRL"
  }).format(Number(value || 0));
}

function formatDateTime(value) {
  return new Intl.DateTimeFormat("pt-BR", {
    timeZone: "America/Sao_Paulo",
    dateStyle: "short",
    timeStyle: "medium"
  }).format(new Date(value));
}
