import { onlyDigits, sleep } from "./utils.js";

const DEFAULT_TOKEN_URL =
  "https://gateway.apiserpro.serpro.gov.br/token";

const MODE_BASE_URLS = {
  production:
    "https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2/qsa",
  trial:
    "https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df-trial/v2/qsa"
};

export class SerproCnpjClient {
  constructor({
    consumerKey = process.env.SERPRO_CONSUMER_KEY,
    consumerSecret = process.env.SERPRO_CONSUMER_SECRET,
    tokenUrl = process.env.SERPRO_TOKEN_URL || DEFAULT_TOKEN_URL,
    qsaBaseUrl = process.env.SERPRO_CNPJ_QSA_URL,
    mode = process.env.SERPRO_CNPJ_MODE || "production",
    requestTag = process.env.SERPRO_REQUEST_TAG || "auditoria-receita",
    timeoutMs = Number(process.env.SERPRO_TIMEOUT_MS || 30000)
  } = {}) {
    this.consumerKey = String(consumerKey || "").trim();
    this.consumerSecret = String(consumerSecret || "").trim();
    this.tokenUrl = String(tokenUrl || DEFAULT_TOKEN_URL).trim();
    this.mode = String(mode || "production").trim().toLowerCase();
    this.qsaBaseUrl = String(
      qsaBaseUrl || MODE_BASE_URLS[this.mode] || MODE_BASE_URLS.production
    ).replace(/\/+$/, "");
    this.requestTag = String(requestTag || "auditoria-receita")
      .slice(0, 32);
    this.timeoutMs = timeoutMs;
    this.token = null;
    this.tokenExpiresAt = 0;
  }

  validateConfig() {
    const missing = [];

    if (!this.consumerKey) missing.push("SERPRO_CONSUMER_KEY");
    if (!this.consumerSecret) missing.push("SERPRO_CONSUMER_SECRET");

    if (missing.length) {
      throw new Error(
        "Credenciais SERPRO ausentes: " + missing.join(", ") + "."
      );
    }

    if (!/^https:\/\//i.test(this.qsaBaseUrl)) {
      throw new Error("SERPRO_CNPJ_QSA_URL inválida.");
    }
  }

  async queryQsa(cnpj, { requestTag } = {}) {
    this.validateConfig();

    const ni = onlyDigits(cnpj);

    if (ni.length !== 14) {
      throw new Error("CNPJ precisa conter 14 dígitos.");
    }

    const token = await this.getToken();
    const url = `${this.qsaBaseUrl}/${ni}`;
    const tag = String(requestTag || this.requestTag).slice(0, 32);

    return this.requestJson(url, {
      method: "GET",
      headers: {
        accept: "application/json",
        authorization: `Bearer ${token}`,
        ...(tag ? { "x-request-tag": tag } : {})
      }
    }, { retryAuth: true });
  }

  async getToken({ force = false } = {}) {
    this.validateConfig();

    if (
      !force &&
      this.token &&
      Date.now() < this.tokenExpiresAt - 60_000
    ) {
      return this.token;
    }

    const basic = Buffer.from(
      `${this.consumerKey}:${this.consumerSecret}`,
      "utf8"
    ).toString("base64");

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);

    try {
      const response = await fetch(this.tokenUrl, {
        method: "POST",
        signal: controller.signal,
        headers: {
          authorization: `Basic ${basic}`,
          "content-type": "application/x-www-form-urlencoded",
          accept: "application/json"
        },
        body: "grant_type=client_credentials"
      });

      const text = await response.text();
      let body = {};

      try {
        body = text ? JSON.parse(text) : {};
      } catch {
        body = { raw: text };
      }

      if (!response.ok || !body.access_token) {
        throw new SerproError(
          `Falha ao obter token SERPRO (HTTP ${response.status}).`,
          response.status,
          body
        );
      }

      const expiresIn = Number(body.expires_in || 3600);

      this.token = body.access_token;
      this.tokenExpiresAt = Date.now() + Math.max(60, expiresIn) * 1000;

      return this.token;
    } finally {
      clearTimeout(timer);
    }
  }

  async requestJson(url, options, { retryAuth = false } = {}) {
    let lastError = null;

    for (let attempt = 1; attempt <= 4; attempt++) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);

      try {
        const response = await fetch(url, {
          ...options,
          signal: controller.signal
        });

        const text = await response.text();
        let body = {};

        try {
          body = text ? JSON.parse(text) : {};
        } catch {
          body = { raw: text };
        }

        if (response.ok || response.status === 206) {
          return {
            data: body,
            httpStatus: response.status,
            partial: response.status === 206,
            requestId:
              response.headers.get("x-request-id") ||
              response.headers.get("x-correlation-id") ||
              ""
          };
        }

        if (response.status === 401 && retryAuth && attempt === 1) {
          const token = await this.getToken({ force: true });
          options = {
            ...options,
            headers: {
              ...options.headers,
              authorization: `Bearer ${token}`
            }
          };
          continue;
        }

        if ([429, 500, 502, 503, 504].includes(response.status) && attempt < 4) {
          await sleep(attempt * 1200);
          continue;
        }

        throw new SerproError(
          `Consulta SERPRO falhou (HTTP ${response.status}).`,
          response.status,
          body
        );
      } catch (error) {
        lastError = error;

        if (
          error?.name === "AbortError" &&
          attempt < 4
        ) {
          await sleep(attempt * 1000);
          continue;
        }

        if (
          error instanceof SerproError &&
          ![429, 500, 502, 503, 504].includes(error.status)
        ) {
          throw error;
        }

        if (attempt < 4) {
          await sleep(attempt * 1200);
          continue;
        }
      } finally {
        clearTimeout(timer);
      }
    }

    throw lastError || new Error("Falha desconhecida ao consultar SERPRO.");
  }
}

export class SerproError extends Error {
  constructor(message, status, responseBody) {
    super(message);
    this.name = "SerproError";
    this.status = status;
    this.responseBody = responseBody;
  }
}

export function serproModeBaseUrl(mode = "production") {
  return MODE_BASE_URLS[String(mode).toLowerCase()] || MODE_BASE_URLS.production;
}
