const JSON_HEADERS = { "content-type": "application/json; charset=utf-8" };

export async function sendDiscord(webhookUrl, payload) {
  if (!webhookUrl) {
    throw new Error("Webhook do Discord não configurado.");
  }

  const body = JSON.stringify({
    ...payload,
    allowed_mentions: { parse: [] }
  });

  for (let attempt = 1; attempt <= 5; attempt++) {
    const separator = webhookUrl.includes("?") ? "&" : "?";
    const response = await fetch(`${webhookUrl}${separator}wait=true`, {
      method: "POST",
      headers: JSON_HEADERS,
      body
    });

    if (response.ok) {
      return;
    }

    if (response.status === 429) {
      let retryAfter = 1.5;

      try {
        const rate = await response.json();
        retryAfter = Number(rate.retry_after ?? 1.5);
      } catch {
        // Usa fallback.
      }

      await sleep(Math.max(500, (retryAfter + 0.25) * 1000));
      continue;
    }

    if (response.status >= 500 && attempt < 5) {
      await sleep(attempt * 1000);
      continue;
    }

    throw new Error(
      `Discord respondeu HTTP ${response.status}: ${await response.text()}`
    );
  }

  throw new Error("Número máximo de tentativas ao Discord excedido.");
}

export async function sendSection(webhookUrl, title, body) {
  const clean = String(body ?? "").trim();

  if (!clean) return;

  const titleBlock = `**${title}**\n\n`;
  const chunks = splitText(clean, Math.max(1000, 1800 - titleBlock.length));

  for (let index = 0; index < chunks.length; index++) {
    const content =
      index === 0
        ? titleBlock + chunks[index]
        : `**↳ Continuação — ${title}**\n\n${chunks[index]}`;

    await sendDiscord(webhookUrl, { content });
  }
}

function splitText(text, limit) {
  let remaining = String(text).replaceAll("\r", "").trim();
  const chunks = [];

  while (remaining.length > limit) {
    const candidate = remaining.slice(0, limit);
    const newline = candidate.lastIndexOf("\n");
    const space = candidate.lastIndexOf(" ");
    let cut = Math.max(newline, space);

    if (cut < limit * 0.55) cut = limit;

    chunks.push(remaining.slice(0, cut).trim());
    remaining = remaining.slice(cut).trim();
  }

  if (remaining) chunks.push(remaining);

  return chunks;
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}