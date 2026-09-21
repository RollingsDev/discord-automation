import { sendDiscord, sendSection } from "./discord.js";
import { saoPauloParts } from "./time.js";

const API_URL = "https://liturgia.up.railway.app/v2/";

export async function publishLiturgy(env, now = new Date()) {
  const today = saoPauloParts(now);
  const response = await fetch(`${API_URL}?_=${Date.now()}`, {
    headers: {
      accept: "application/json",
      "user-agent": "discord-scheduler/1.0",
      "cache-control": "no-cache"
    }
  });

  if (!response.ok) {
    throw new Error(`API da liturgia respondeu HTTP ${response.status}`);
  }

  const liturgy = await response.json();
  const date = String(liturgy.data ?? today.brDate).trim();

  if (date && date !== today.brDate) {
    throw new Error(
      `API da liturgia retornou ${date}; esperado ${today.brDate}.`
    );
  }

  const webhook = env.DISCORD_LITURGIA_WEBHOOK;
  const celebration = String(liturgy.liturgia ?? "Liturgia do Dia").trim();
  const colorName = String(liturgy.cor ?? "").trim();

  await sendDiscord(webhook, {
    embeds: [
      {
        title: `✝️ Liturgia do Dia — ${date}`,
        description: `**${celebration}**`,
        color: liturgicalColor(colorName),
        fields: [
          {
            name: "Cor litúrgica",
            value: colorName || "Não informada",
            inline: true
          }
        ],
        footer: {
          text: "O Caminho • dados: liturgia.up.railway.app"
        },
        timestamp: new Date().toISOString()
      }
    ]
  });

  const prayers = object(liturgy.oracoes);
  const readings = object(liturgy.leituras);
  const antiphons = object(liturgy.antifonas);

  if (antiphons.entrada) {
    await sendSection(webhook, "🕯️ Antífona de Entrada", antiphons.entrada);
  }

  if (prayers.coleta) {
    await sendSection(webhook, "🙏 Oração da Coleta", prayers.coleta);
  }

  await sendReadings(webhook, readings, "primeiraLeitura", "📖 Primeira Leitura");
  await sendReadings(webhook, readings, "salmo", "🎵 Salmo Responsorial");
  await sendReadings(webhook, readings, "segundaLeitura", "📖 Segunda Leitura");

  const extraReadings = Array.isArray(readings.extras) ? readings.extras : [];

  for (let index = 0; index < extraReadings.length; index++) {
    const extra = object(extraReadings[index]);
    const type = String(extra.tipo ?? extra.titulo ?? "Leitura Extra").trim();
    await sendSection(
      webhook,
      type ? `📜 ${type}` : `📜 Leitura Extra ${index + 1}`,
      readingBody(extra)
    );
  }

  await sendReadings(webhook, readings, "evangelho", "✠ Evangelho");

  const extraPrayers = Array.isArray(prayers.extras) ? prayers.extras : [];

  for (const item of extraPrayers) {
    const extra = object(item);
    const text = String(extra.texto ?? "").trim();

    if (text) {
      await sendSection(
        webhook,
        `🙏 ${String(extra.titulo ?? "Oração Extra").trim()}`,
        text
      );
    }
  }

  if (prayers.oferendas) {
    await sendSection(webhook, "🍞 Oração sobre as Oferendas", prayers.oferendas);
  }

  if (antiphons.comunhao) {
    await sendSection(webhook, "🕊️ Antífona da Comunhão", antiphons.comunhao);
  }

  if (prayers.comunhao) {
    await sendSection(
      webhook,
      "🙏 Oração depois da Comunhão",
      prayers.comunhao
    );
  }

  return { date };
}

async function sendReadings(webhook, readings, key, label) {
  const raw = readings[key];
  const items = Array.isArray(raw)
    ? raw
    : raw && typeof raw === "object"
      ? [raw]
      : [];

  for (let index = 0; index < items.length; index++) {
    const suffix = items.length > 1 ? ` — Opção ${index + 1}` : "";
    await sendSection(
      webhook,
      label + suffix,
      readingBody(object(items[index]))
    );
  }
}

function readingBody(reading) {
  const parts = [];
  const reference = String(reading.referencia ?? "").trim();
  const title = String(reading.titulo ?? "").trim();
  const refrain = String(reading.refrao ?? "").trim();
  const text = String(reading.texto ?? "").trim();

  if (reference) parts.push(`**${reference}**`);
  if (title) parts.push(`*${title}*`);
  if (refrain) parts.push(`**R.: ${refrain}**`);
  if (text) parts.push(text);

  return parts.join("\n\n");
}

function liturgicalColor(color) {
  const normalized = String(color)
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();

  const colors = {
    verde: 0x2e8b57,
    vermelho: 0xb22222,
    roxo: 0x6f42c1,
    violeta: 0x6f42c1,
    rosa: 0xff69b4,
    roseo: 0xff69b4,
    preto: 0x23272a,
    branco: 0xf2f3f5
  };

  return colors[normalized] ?? 0xc9a227;
}

function object(value) {
  return value && typeof value === "object" && !Array.isArray(value)
    ? value
    : {};
}