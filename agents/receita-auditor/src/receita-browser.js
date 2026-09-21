import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "playwright";
import {
  companyBaseFilename,
  formatCnpj,
  onlyDigits,
  sleep
} from "./utils.js";

const BASE_URL =
  "https://solucoes.receita.fazenda.gov.br/Servicos/cnpjreva/";

export class ReceitaBrowser {
  constructor({
    profileDir,
    onHumanRequired = async () => {},
    onEvent = async () => {}
  } = {}) {
    this.profileDir = profileDir;
    this.onHumanRequired = onHumanRequired;
    this.onEvent = onEvent;
    this.context = null;
    this.page = null;
  }

  async start() {
    await fs.mkdir(this.profileDir, { recursive: true });

    this.context = await chromium.launchPersistentContext(this.profileDir, {
      headless: false,
      viewport: null,
      args: ["--start-maximized"],
      acceptDownloads: true
    });

    this.page = this.context.pages()[0] ?? (await this.context.newPage());
    this.page.setDefaultTimeout(30_000);

    await this.onEvent("Navegador da Receita iniciado.");
  }

  async close() {
    if (this.context) {
      await this.context.close();
      this.context = null;
      this.page = null;
    }
  }

  async captureCompany(entity, dirs) {
    if (!this.page) {
      throw new Error("Navegador não iniciado.");
    }

    const base = companyBaseFilename(entity);
    const iscPath = path.join(dirs.pdf, `${base} - ISC.pdf`);
    const qsaPath = path.join(dirs.pdf, `${base} - QSA.pdf`);

    if (!(await fileExists(iscPath))) {
      await this.captureIsc(entity, iscPath);
    } else {
      await this.onEvent(`ISC já existe: ${path.basename(iscPath)}`);
    }

    if (!(await fileExists(qsaPath))) {
      await this.captureQsa(entity, qsaPath);
    } else {
      await this.onEvent(`QSA já existe: ${path.basename(qsaPath)}`);
    }

    return {
      iscPath,
      qsaPath
    };
  }

  async captureIsc(entity, outputPath) {
    const page = this.page;

    await this.openQueryPage(page);
    await this.fillCnpj(page, entity.cnpj);
    await this.waitForHumanCaptcha(page, entity);
    await this.clickConsultar(page);
    await this.waitForIscResult(page, entity.cnpj);
    await this.printOfficialPage(page, outputPath);

    await this.onEvent(`ISC salvo: ${path.basename(outputPath)}`);
  }

  async captureQsa(entity, outputPath) {
    const sourcePage = this.page;

    // O QSA parte da tela do comprovante recém-consultado.
    await this.waitForIscResult(sourcePage, entity.cnpj);

    const qsaLocator = await firstVisible(sourcePage, [
      sourcePage.getByRole("button", { name: /consultar\s+qsa/i }),
      sourcePage.getByRole("link", { name: /consultar\s+qsa/i }),
      sourcePage.locator('input[value*="QSA" i]'),
      sourcePage.locator('a:has-text("QSA")'),
      sourcePage.locator('button:has-text("QSA")')
    ]);

    if (!qsaLocator) {
      throw new Error(
        "Não encontrei a opção 'Consultar QSA' no comprovante da Receita."
      );
    }

    const popupPromise = sourcePage
      .waitForEvent("popup", { timeout: 4_000 })
      .catch(() => null);

    const previousUrl = sourcePage.url();

    await qsaLocator.click();

    const popup = await popupPromise;
    const qsaPage = popup ?? sourcePage;

    if (!popup) {
      await sourcePage
        .waitForURL(url => String(url) !== previousUrl, { timeout: 10_000 })
        .catch(() => {});
    }

    await qsaPage.waitForLoadState("domcontentloaded");
    await waitForBodyText(qsaPage, [
      /Consulta\s+Quadro\s+de\s+Sócios\s+e\s+Administradores/i,
      /Quadro\s+de\s+Sócios\s+e\s+Administradores\s*\(QSA\)/i
    ]);

    await this.printOfficialPage(qsaPage, outputPath);

    if (popup) {
      await popup.close();
    }

    await this.onEvent(`QSA salvo: ${path.basename(outputPath)}`);
  }

  async openQueryPage(page) {
    await page.goto(BASE_URL, {
      waitUntil: "domcontentloaded",
      timeout: 45_000
    });

    await waitForBodyText(page, [
      /Emissão\s+de\s+Comprovante\s+de\s+Inscrição/i,
      /Digite\s+o\s+número\s+de\s+CNPJ/i
    ]);
  }

  async fillCnpj(page, cnpj) {
    const input = await firstVisible(page, [
      page.getByLabel(/CNPJ/i),
      page.locator('input[id*="cnpj" i]'),
      page.locator('input[name*="cnpj" i]'),
      page.locator('input[type="text"]').first()
    ]);

    if (!input) {
      throw new Error("Não encontrei o campo CNPJ na página da Receita.");
    }

    await input.fill(onlyDigits(cnpj));
  }

  async waitForHumanCaptcha(page, entity) {
    const hasCaptcha = await page
      .locator(
        'iframe[src*="hcaptcha" i], iframe[title*="hcaptcha" i], textarea[name="h-captcha-response"]'
      )
      .count();

    if (!hasCaptcha) {
      await this.onEvent("CAPTCHA não apareceu nesta consulta.");
      return;
    }

    const isSolved = async () => {
      const candidates = [
        'textarea[name="h-captcha-response"]',
        'input[name="h-captcha-response"]'
      ];

      for (const selector of candidates) {
        const locator = page.locator(selector).first();

        if ((await locator.count()) > 0) {
          const value = await locator.inputValue().catch(() => "");
          if (String(value).trim().length > 10) return true;
        }
      }

      return false;
    };

    // Dá alguns segundos para aproveitar uma validação ainda válida na sessão.
    for (let attempt = 0; attempt < 6; attempt++) {
      if (await isSolved()) return;
      await sleep(500);
    }

    await this.onHumanRequired(entity);

    const deadline = Date.now() + 15 * 60 * 1000;

    while (Date.now() < deadline) {
      if (await isSolved()) {
        await this.onEvent(
          `Validação humana concluída para ${formatCnpj(entity.cnpj)}.`
        );
        return;
      }

      await sleep(750);
    }

    throw new Error(
      "Tempo limite de 15 minutos aguardando a validação 'Sou humano'."
    );
  }

  async clickConsultar(page) {
    const button = await firstVisible(page, [
      page.getByRole("button", { name: /^consultar$/i }),
      page.locator('input[type="submit"][value*="Consultar" i]'),
      page.locator('input[type="button"][value*="Consultar" i]'),
      page.getByText(/^Consultar$/i)
    ]);

    if (!button) {
      throw new Error("Não encontrei o botão Consultar da Receita.");
    }

    await Promise.all([
      page.waitForLoadState("domcontentloaded").catch(() => {}),
      button.click()
    ]);
  }

  async waitForIscResult(page, cnpj) {
    await waitForBodyText(page, [
      /NÚMERO\s+DE\s+INSCRIÇÃO/i,
      /SITUAÇÃO\s+CADASTRAL/i
    ]);

    const body = await page.locator("body").innerText();
    const formatted = formatCnpj(cnpj);
    const digits = onlyDigits(cnpj);

    if (
      !body.includes(formatted) &&
      !body.replace(/\D/g, "").includes(digits)
    ) {
      throw new Error(
        `A página retornada não parece corresponder ao CNPJ ${formatted}.`
      );
    }
  }

  async printOfficialPage(page, outputPath) {
    await page.emulateMedia({ media: "print" });
    await page.waitForTimeout(350);

    const timestamp = new Intl.DateTimeFormat("en-US", {
      timeZone: "America/Sao_Paulo",
      year: "2-digit",
      month: "numeric",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit"
    }).format(new Date());

    const sharedStyle =
      "font-family:Arial,sans-serif;font-size:8px;color:#333;width:100%;padding:0 8mm;box-sizing:border-box;";

    await page.pdf({
      path: outputPath,
      format: "A4",
      printBackground: true,
      preferCSSPageSize: false,
      displayHeaderFooter: true,
      margin: {
        top: "13mm",
        right: "7mm",
        bottom: "13mm",
        left: "7mm"
      },
      headerTemplate:
        `<div style="${sharedStyle}display:flex;justify-content:space-between;">` +
        `<span>${escapeHtml(timestamp)}</span><span>about:blank</span><span></span></div>`,
      footerTemplate:
        `<div style="${sharedStyle}display:flex;justify-content:space-between;">` +
        '<span>about:blank</span><span><span class="pageNumber"></span>/<span class="totalPages"></span></span></div>'
    });
  }
}

async function firstVisible(page, locators) {
  for (const locator of locators) {
    try {
      if ((await locator.count()) > 0 && (await locator.first().isVisible())) {
        return locator.first();
      }
    } catch {
      // Tenta o próximo seletor.
    }
  }

  return null;
}

async function waitForBodyText(page, patterns, timeout = 30_000) {
  const deadline = Date.now() + timeout;

  while (Date.now() < deadline) {
    const text = await page
      .locator("body")
      .innerText({ timeout: 3_000 })
      .catch(() => "");

    if (patterns.every(pattern => pattern.test(text))) {
      return;
    }

    await sleep(350);
  }

  throw new Error(
    "A página da Receita não chegou ao estado esperado dentro do tempo limite."
  );
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}
