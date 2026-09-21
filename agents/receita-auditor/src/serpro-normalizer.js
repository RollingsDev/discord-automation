import { formatCnpj, onlyDigits } from "./utils.js";

export function normalizeSerproCnpj(payload = {}) {
  const data = payload && typeof payload === "object" ? payload : {};
  const situacao = object(data.situacaoCadastral ?? data.situacao_cadastral);
  const natureza = object(data.naturezaJuridica ?? data.natureza_juridica);
  const cnaePrincipal = object(data.cnaePrincipal ?? data.cnae_principal);
  const endereco = object(data.endereco);
  const municipio = object(endereco.municipio);
  const pais = object(endereco.pais);
  const sociosRaw = array(
    data.socios ??
      data.quadroSocietario ??
      data.qsa ??
      data.sociosAdministradores
  );

  const cnpj = onlyDigits(data.ni ?? data.cnpj ?? "");

  return {
    cnpj,
    cnpjFormatado: formatCnpj(cnpj),
    tipoEstabelecimento: establishmentLabel(
      data.tipoEstabelecimento ?? data.tipo_estabelecimento
    ),
    nomeEmpresarial: text(
      data.nomeEmpresarial ?? data.nome_empresarial ?? data.razaoSocial
    ),
    nomeFantasia: text(data.nomeFantasia ?? data.nome_fantasia),
    dataAbertura: formatDate(
      data.dataAbertura ?? data.data_abertura
    ),
    porte: porteLabel(data.porte),
    cnaePrincipal: {
      codigo: formatCnae(cnaePrincipal.codigo),
      descricao: text(cnaePrincipal.descricao)
    },
    cnaesSecundarios: array(
      data.cnaeSecundarias ?? data.cnaesSecundarios ?? data.cnae_secundarias
    ).map(item => {
      const row = object(item);
      return {
        codigo: formatCnae(row.codigo),
        descricao: text(row.descricao)
      };
    }),
    naturezaJuridica: {
      codigo: formatNatureza(natureza.codigo),
      descricao: text(natureza.descricao)
    },
    endereco: {
      tipoLogradouro: text(
        endereco.tipoLogradouro ?? endereco.tipo_logradouro
      ),
      logradouro: text(endereco.logradouro),
      numero: text(endereco.numero),
      complemento: text(endereco.complemento),
      cep: formatCep(endereco.cep),
      bairro: text(endereco.bairro),
      municipio: text(municipio.descricao ?? endereco.municipio),
      uf: text(endereco.uf),
      pais: text(pais.descricao)
    },
    email: text(
      data.correioEletronico ??
        data.correio_eletronico ??
        data.email
    ),
    telefones: array(data.telefones).map(item => {
      const row = object(item);
      const ddd = onlyDigits(row.ddd);
      const numero = onlyDigits(row.numero);
      return [ddd ? `(${ddd})` : "", numero].filter(Boolean).join(" ");
    }),
    capitalSocial: numberValue(data.capitalSocial ?? data.capital_social),
    situacaoCadastral: {
      codigo: text(situacao.codigo),
      descricao: text(
        situacao.descricao ||
          situacaoLabel(situacao.codigo)
      ),
      data: formatDate(situacao.data),
      motivo: text(situacao.motivo)
    },
    situacaoEspecial: text(
      data.situacaoEspecial ?? data.situacao_especial
    ),
    dataSituacaoEspecial: formatDate(
      data.dataSituacaoEspecial ?? data.data_situacao_especial
    ),
    enteFederativo: text(
      data.enteFederativo ?? data.ente_federativo
    ),
    socios: sociosRaw.map(normalizeSocio)
  };
}

function normalizeSocio(item) {
  const row = object(item);
  const qualificacao = object(row.qualificacao);
  const representante = object(
    row.representanteLegal ?? row.representante_legal
  );
  const pais = object(row.pais);

  return {
    tipoSocio: text(
      row.tipoSocio ?? row.tipo_socio
    ),
    documento: text(
      row.cpfCnpj ??
        row.cpf_cnpj ??
        row.cpf ??
        row.cnpj ??
        row.ni
    ),
    nome: text(
      row.nome ?? row.nomeEmpresarial ?? row.nome_empresarial
    ),
    qualificacao: text(
      qualificacao.descricao ??
        qualificacao.nome ??
        row.qualificacao
    ),
    qualificacaoCodigo: text(
      qualificacao.codigo ??
        row.codigoQualificacao ??
        row.codigo_qualificacao
    ),
    dataInclusao: formatDate(
      row.dataInclusao ?? row.data_inclusao
    ),
    pais: text(pais.descricao ?? row.pais),
    representanteLegal: {
      nome: text(representante.nome),
      documento: text(
        representante.cpf ??
          representante.cnpj ??
          representante.ni
      ),
      qualificacao: text(
        object(representante.qualificacao).descricao ??
          representante.qualificacao
      )
    }
  };
}

function establishmentLabel(value) {
  const raw = text(value).trim();

  if (["1", "MATRIZ"].includes(raw.toUpperCase())) return "MATRIZ";
  if (["2", "FILIAL"].includes(raw.toUpperCase())) return "FILIAL";

  return raw;
}

function situacaoLabel(value) {
  const code = String(value ?? "").replace(/^0+/, "");

  return {
    "1": "NULA",
    "2": "ATIVA",
    "3": "SUSPENSA",
    "4": "INAPTA",
    "8": "BAIXADA"
  }[code] || "";
}

function porteLabel(value) {
  const raw = text(value).trim();
  const code = raw.padStart(2, "0");

  return {
    "00": "NAO INFORMADO",
    "01": "MICRO EMPRESA",
    "03": "EMPRESA DE PEQUENO PORTE",
    "05": "DEMAIS"
  }[code] || raw;
}

function formatDate(value) {
  const raw = text(value).replace(/\D/g, "");

  if (raw.length === 8) {
    if (Number(raw.slice(0, 4)) > 1900) {
      return `${raw.slice(6, 8)}/${raw.slice(4, 6)}/${raw.slice(0, 4)}`;
    }

    return `${raw.slice(0, 2)}/${raw.slice(2, 4)}/${raw.slice(4, 8)}`;
  }

  return text(value);
}

function formatCep(value) {
  const raw = onlyDigits(value);
  if (raw.length !== 8) return text(value);
  return `${raw.slice(0, 5)}-${raw.slice(5)}`;
}

function formatNatureza(value) {
  const raw = onlyDigits(value);
  if (raw.length !== 4) return text(value);
  return `${raw.slice(0, 3)}-${raw.slice(3)}`;
}

function formatCnae(value) {
  const raw = onlyDigits(value);
  if (raw.length !== 7) return text(value);
  return `${raw.slice(0, 2)}.${raw.slice(2, 4)}-${raw.slice(4)}`;
}

function numberValue(value) {
  const number = Number(
    typeof value === "string"
      ? value.replace(/\./g, "").replace(",", ".")
      : value
  );

  return Number.isFinite(number) ? number : 0;
}

function object(value) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value
    : {};
}

function array(value) {
  return Array.isArray(value) ? value : [];
}

function text(value) {
  if (value === null || value === undefined) return "";
  if (typeof value === "object") return "";
  return String(value).trim();
}
