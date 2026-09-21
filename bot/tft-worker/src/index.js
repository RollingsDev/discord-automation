import { TFT_COMMAND } from "./commands.js";

const JSON_HEADERS = { "content-type": "application/json; charset=utf-8" };
const COLORS = { purple: 0x7c4dff, blue: 0x3498db, green: 0x2ecc71, gold: 0xf1c40f, red: 0xe74c3c };

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (request.method === "GET" && url.pathname === "/health") {
      return json({ ok: true, service: "oraculo-tft", command: "/tft" });
    }

    if (request.method !== "POST") {
      return new Response("Oráculo TFT online.", { status: 200 });
    }

    const signature = request.headers.get("x-signature-ed25519");
    const timestamp = request.headers.get("x-signature-timestamp");
    const rawBody = await request.text();

    if (!signature || !timestamp || !env.DISCORD_PUBLIC_KEY || !(await verifyDiscordRequest(env.DISCORD_PUBLIC_KEY, signature, timestamp, rawBody))) {
      return new Response("invalid request signature", { status: 401 });
    }

    let interaction;
    try {
      interaction = JSON.parse(rawBody);
    } catch {
      return new Response("invalid json", { status: 400 });
    }

    if (interaction.type === 1) return json({ type: 1 });

    if (interaction.type !== 2 || interaction.data?.name !== TFT_COMMAND.name) {
      return interactionMessage("Comando não reconhecido pelo Oráculo TFT.", true);
    }

    if (env.DISCORD_GUILD_ID && interaction.guild_id && interaction.guild_id !== env.DISCORD_GUILD_ID) {
      return interactionMessage("Este Oráculo TFT está configurado para outro servidor.", true);
    }

    ctx.waitUntil(handleTftCommand(interaction, env));
    return json({ type: 5 });
  }
};

async function handleTftCommand(interaction, env) {
  try {
    const subcommand = interaction.data?.options?.[0];
    const name = subcommand?.name ?? "meta";
    const input = optionValue(subcommand, "nome");
    let response;

    if (name === "player" || name === "players") {
      const riot = await fetchJson(env.RIOT_ANALYTICS_URL);
      response = name === "player" ? renderPlayer(riot, input) : renderPlayers(riot);
    } else {
      const meta = await fetchJson(env.TFT_META_URL);
      if (name === "comp") response = renderComp(meta, input);
      else if (name === "champ") response = renderChampion(meta, input);
      else if (name === "item") response = renderItem(meta, input);
      else response = renderMeta(meta);
    }

    await editOriginal(interaction, response);
  } catch (error) {
    console.error(error);
    await editOriginal(interaction, {
      content: "⚠️ Não consegui consultar os dados agora. Tente novamente em alguns segundos.",
      allowed_mentions: { parse: [] }
    });
  }
}

function renderMeta(meta) {
  const comps = Array.isArray(meta.comps) ? meta.comps.slice(0, 5) : [];
  const fields = comps.map((comp, index) => ({
    name: `${index + 1}. ${comp.name ?? "Comp"}`,
    value: [
      `**Win:** ${pct(comp.win_rate)} • **Top 4:** ${pct(comp.top4_rate)}`,
      `**Média:** ${num(comp.avg_place, 2)} • **Play:** ${num(comp.play_rate, 2)}`,
      topItemizedUnits(comp)
    ].join("\n"),
    inline: false
  }));

  return embedResponse({
    title: "♟️ TFT Meta — principais comps",
    url: meta.source_url,
    description: metaHeader(meta),
    color: COLORS.purple,
    fields,
    footer: "Fonte do meta: tactics.tools • snapshot atualizado automaticamente"
  });
}

function renderComp(meta, input) {
  const comps = Array.isArray(meta.comps) ? meta.comps : [];
  const comp = findBest(comps, input, row => row.name);
  if (!comp) return errorResponse(`Não encontrei uma comp parecida com **${safe(input)}** no snapshot atual.`);

  const units = Array.isArray(comp.units) ? comp.units : [];
  const itemLines = [];
  for (const [unit, items] of Object.entries(comp.unit_items ?? {})) {
    if (!Array.isArray(items) || items.length === 0) continue;
    itemLines.push(`**${unit}** → ${items.slice(0, 3).join(" / ")}`);
  }

  return embedResponse({
    title: `🧩 ${comp.name}`,
    url: meta.source_url,
    description: metaHeader(meta),
    color: COLORS.purple,
    fields: [
      {
        name: "📊 Estatísticas",
        value: `Win **${pct(comp.win_rate)}** • Top 4 **${pct(comp.top4_rate)}**\nMédia **${num(comp.avg_place, 2)}** • Play **${num(comp.play_rate, 2)}**`,
        inline: false
      },
      { name: "👥 Unidades", value: units.length ? units.join(" • ") : "Sem unidades no snapshot.", inline: false },
      { name: "🧰 Itens por campeão", value: itemLines.length ? itemLines.slice(0, 8).join("\n") : "Sem itens associados no snapshot.", inline: false }
    ]
  });
}

function renderChampion(meta, input) {
  const comps = Array.isArray(meta.comps) ? meta.comps : [];
  const championNames = unique(comps.flatMap(comp => (Array.isArray(comp.units) ? comp.units : [])));
  const champion = findBest(championNames.map(name => ({ name })), input, row => row.name)?.name;

  if (!champion) return errorResponse(`Não encontrei o campeão **${safe(input)}** nas comps do snapshot atual.`);

  const matchingComps = comps.filter(comp => (comp.units ?? []).some(unit => normalize(unit) === normalize(champion)));
  const fields = matchingComps.slice(0, 5).map(comp => {
    const items = comp.unit_items?.[champion] ?? [];
    return {
      name: `🧩 ${comp.name}`,
      value: [
        `Win **${pct(comp.win_rate)}** • Top 4 **${pct(comp.top4_rate)}** • média **${num(comp.avg_place, 2)}**`,
        items.length ? `Itens: ${items.slice(0, 3).join(" / ")}` : "Sem itens específicos nessa variação."
      ].join("\n"),
      inline: false
    };
  });

  const globalItems = (meta.items ?? []).filter(item => (item.top_users ?? []).some(user => normalize(user) === normalize(champion))).slice(0, 5);
  if (globalItems.length) {
    fields.push({
      name: "🧰 Itens em que aparece entre os principais usuários",
      value: globalItems.map(item => `**${item.name}** — Win ${pct(item.win_rate)} • Top4 ${pct(item.top4_rate)} • uso ${num(item.play_rate, 2)}/8`).join("\n"),
      inline: false
    });
  }

  return embedResponse({ title: `🧙 ${champion} — TFT Meta`, url: meta.source_url, description: metaHeader(meta), color: COLORS.blue, fields });
}

function renderItem(meta, input) {
  const items = Array.isArray(meta.items) ? meta.items : [];
  const item = findBest(items, input, row => row.name);
  if (!item) return errorResponse(`Não encontrei o item **${safe(input)}** no snapshot atual.`);

  return embedResponse({
    title: `🧰 ${item.name}`,
    url: meta.items_url ?? meta.source_url,
    description: metaHeader(meta),
    color: COLORS.gold,
    fields: [
      {
        name: "📊 Estatísticas",
        value: `Win **${pct(item.win_rate)}** • Top 4 **${pct(item.top4_rate)}**\nMédia **${num(item.avg_place, 2)}** • Uso **${num(item.play_rate, 2)}/8**`,
        inline: false
      },
      { name: "👥 Principais usuários", value: (item.top_users ?? []).length ? item.top_users.join(" • ") : "Não disponível.", inline: false }
    ]
  });
}

function renderPlayer(riot, input) {
  const players = objectPlayers(riot);
  const player = findBest(players, input, row => `${row.riot_id?.game_name ?? row.id}#${row.riot_id?.tag_line ?? ""}`);
  if (!player) return errorResponse(`Não encontrei **${safe(input)}** entre os jogadores monitorados.`);
  return playerEmbed(player);
}

function renderPlayers(riot) {
  const players = objectPlayers(riot).filter(player => player.tft_rank || (player.tft_matches ?? []).length > 0);
  if (!players.length) return errorResponse("Ainda não há jogadores com dados de TFT.");

  players.sort((a, b) => rankScore(b.tft_rank) - rankScore(a.tft_rank));
  return embedResponse({
    title: "👅 TFT da galera",
    color: COLORS.green,
    description: players.map(player => {
      const recent = tftRecent(player.tft_matches ?? []);
      const lines = [`**${riotId(player)}** — ${rankLabel(player.tft_rank)}`);
      if (player.tft_rank) lines.push(`${player.tft_rank.wins ?? 0}W / ${player.tft_rank.losses ?? 0}L`);
      if (recent.games) lines.push(`Top4 ${pct(recent.top4Rate)} • 1º ${pct(recent.firstRate)} • média ${num(recent.avgPlacement, 2)} (${recent.games}j)`);
      return lines.join("\n");
    }).join("\n\n"),
    footer: "Rank = Riot API • Top4/1º/posição média = partidas Match-V1 coletadas"
  });
}

function playerEmbed(player) {
  const recent = tftRecent(player.tft_matches ?? []);
  const fields = [];

  if (player.tft_rank) {
    const wins = Number(player.tft_rank.wins ?? 0);
    const losses = Number(player.tft_rank.losses ?? 0);
    const total = wins + losses;
    fields.push({
      name: "🏆 Rank",
      value: `**${rankLabel(player.tft_rank)}**\nLeague W/L: **${wins}W / ${losses}L**${total ? ` • ${pct(wins / total)} W` : ""}`,
      inline: false
    });
  } else {
    fields.push({ name: "🏆 Rank", value: "Sem entrada ranqueada atual.", inline: false });
  }

  if (recent.games) {
    fields.push({
      name: "📊 Partidas coletadas",
      value: `Top 4 **${pct(recent.top4Rate)}** • 1º **${pct(recent.firstRate)}**\nPosição média **${num(recent.avgPlacement, 2)}** • amostra **${recent.games}j**`,
      inline: false
    });
    const comps = recentComps(player.tft_matches ?? []);
    if (comps.length) {
      fields.push({
        name: "🧩 Comps recentes",
        value: comps.slice(0, 5).map(row => `**${row.name}** — ${row.games}j • média ${num(row.avg, 2)}`).join("\n"),
        inline: false
      });
    }
  }

  return embedResponse({ title: `♟️ ${riotId(player)}`, color: COLORS.green, fields, footer: "Dados pessoais: Riot API / histórico salvo pelo Riot Analytics" });
}

function objectPlayers(riot) {
  return Object.entries(riot.players ?? {}).map(([id, player]) => ({ id, ...player }));
}

function tftRecent(matches) {
  const rows = Array.isArray(matches) ? matches.slice(0, 20) : [];
  const placements = rows.map(row => Number(row.placement)).filter(value => Number.isFinite(value) && value >= 1 && value <= 8);
  if (!placements.length) return { games: 0, top4Rate: 0, firstRate: 0, avgPlacement: 0 };
  return {
    games: placements.length,
    top4Rate: placements.filter(value => value <= 4).length / placements.length,
    firstRate: placements.filter(value => value === 1).length / placements.length,
    avgPlacement: placements.reduce((sum, value) => sum + value, 0) / placements.length
  };
}

function recentComps(matches) {
  const map = new Map();
  for (const match of (matches ?? []).slice(0, 20)) {
    const name = String(match.comp ?? "").trim();
    const placement = Number(match.placement);
    if (!name || !Number.isFinite(placement)) continue;
    const row = map.get(name) ?? { name, games: 0, sum: 0 };
    row.games++;
    row.sum += placement;
    map.set(name, row);
  }
  return [...map.values()].map(row => ({ ...row, avg: row.sum / row.games })).sort((a, b) => b.games - a.games || a.avg - b.avg);
}

function rankLabel(rank) {
  if (!rank) return "Unranked";
  return `${titleCase(String(rank.tier ?? ""))} ${String(rank.rank ?? "")} • ${Number(rank.lp ?? 0)} LP`.trim();
}

function rankScore(rank) {
  if (!rank) return -1;
  const tiers = { IRON: 0, BRONZE: 1, SILVER: 2, GOLD: 3, PLATINUM: 4, EMERALD: 5, DIAMOND: 6, MASTER: 7, GRANDMASTER: 8, CHALLENGER: 9 };
  const divisions = { IV: 0, III: 1, II: 2, I: 3 };
  return (tiers[String(rank.tier ?? "").toUpperCase()] ?? -1) * 10000 + (divisions[String(rank.rank ?? "").toUpperCase()] ?? 0) * 1000 + Number(rank.lp ?? 0);
}

function riotId(player) {
  const game = player.riot_id?.game_name ?? player.id ?? "Jogador";
  const tag = player.riot_id?.tag_line ?? "";
  return tag ? `${game}#${tag}` : game;
}

function topItemizedUnits(comp) {
  const lines = Object.entries(comp.unit_items ?? {}).filter(([, items]) => Array.isArray(items) && items.length).slice(0, 2).map(([unit, items]) => `**${unit}** → ${items.slice(0, 3).join(" / ")}`);
  return lines.length ? lines.join("\n") : "Sem itens destacados.";
}

function metaHeader(meta) {
  const patch = meta.patch ? `Patch **${meta.patch}**` : "Patch atual";
  const rank = meta.rank_filter ? ` • **${meta.rank_filter}**` : "";
  const fetched = meta.fetched_at ? ` • snapshot ${formatDate(meta.fetched_at)}` : "";
  return patch + rank + fetched;
}

function optionValue(subcommand, name) {
  return subcommand?.options?.find(option => option.name === name)?.value?.toString() ?? "";
}

function findBest(rows, input, label) {
  const query = normalize(input);
  if (!query) return null;
  return rows.map(row => {
    const value = normalize(label(row));
    let score = 0;
    if (value === query) score = 100;
    else if (value.startsWith(query)) score = 80;
    else if (value.includes(query)) score = 60;
    else if (query.includes(value) && value.length >= 4) score = 40;
    return { row, score };
  }).filter(result => result.score > 0).sort((a, b) => b.score - a.score)[0]?.row ?? null;
}

function normalize(value) {
  return String(value ?? "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");
}

function unique(values) { return [...new Set(values)]; }
function titleCase(value) { return value.toLowerCase().replace(/\b\w/g, char => char.toUpperCase()); }
function pct(value) { return `${(Number(value ?? 0) * 100).toFixed(1).replace(".", ",")}%`; }
function num(value, decimals = 2) { return Number(value ?? 0).toFixed(decimals).replace(".", ","); }
function safe(value) { return String(value ?? "").replace(/[*_~|`]/g, ""); }

function formatDate(value) {
  try {
    return new Intl.DateTimeFormat("pt-BR", { timeZone: "America/Sao_Paulo", day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }).format(new Date(value));
  } catch {
    return String(value);
  }
}

function embedResponse({ title, url, description, color, fields = [], footer }) {
  const embed = {
    title: trim(title, 256),
    color: color ?? COLORS.purple,
    fields: fields.slice(0, 25).map(field => ({ ...field, name: trim(field.name, 256), value: trim(field.value, 1024) })),
    timestamp: new Date().toISOString()
  };
  if (url) embed.url = url;
  if (description) embed.description = trim(description, 4096);
  if (footer) embed.footer = { text: trim(footer, 2048) };
  return { embeds: [embed], allowed_mentions: { parse: [] } };
}

function errorResponse(message) {
  return { embeds: [{ title: "🔎 Não encontrei", description: trim(message, 4096), color: COLORS.red }], allowed_mentions: { parse: [] } };
}

function interactionMessage(content, ephemeral = false) {
  return json({ type: 4, data: { content, flags: ephemeral ? 64 : 0, allowed_mentions: { parse: [] } } });
}

async function editOriginal(interaction, data) {
  const url = `https://discord.com/api/v10/webhooks/${interaction.application_id}/${interaction.token}/messages/@original`;
  const response = await fetch(url, { method: "PATCH", headers: JSON_HEADERS, body: JSON.stringify(data) });
  if (!response.ok) throw new Error(`Falha ao responder interação: ${response.status} ${await response.text()}`);
}

async function fetchJson(url) {
  if (!url) throw new Error("URL de dados não configurada.");
  const response = await fetch(url, { cf: { cacheEverything: true, cacheTtl: 60 } });
  if (!response.ok) throw new Error(`Fonte retornou HTTP ${response.status}`);
  return response.json();
}

async function verifyDiscordRequest(publicKeyHex, signatureHex, timestamp, body) {
  try {
    const publicKey = await crypto.subtle.importKey("raw", hexToBytes(publicKeyHex), { name: "Ed25519" }, false, ["verify"]);
    return crypto.subtle.verify({ name: "Ed25519" }, publicKey, hexToBytes(signatureHex), new TextEncoder().encode(timestamp + body));
  } catch (error) {
    console.error("Falha na validação Ed25519", error);
    return false;
  }
}

function hexToBytes(hex) {
  const clean = String(hex ?? "").trim();
  if (!/^[0-9a-f]+$/i.test(clean) || clean.length % 2 !== 0) throw new Error("Hex inválido.");
  const result = new Uint8Array(clean.length / 2);
  for (let index = 0; index < clean.length; index += 2) result[index / 2] = Number.parseInt(clean.slice(index, index + 2), 16);
  return result;
}

function trim(value, max) {
  const string = String(value ?? "");
  return string.length <= max ? string : string.slice(0, max - 1) + "…";
}

function json(payload, status = 200) {
  return new Response(JSON.stringify(payload), { status, headers: JSON_HEADERS });
}
